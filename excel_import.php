<?php
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);

$message = '';
$error = '';
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_dynamic_columns') {
    try {
        $registerAll = ($_POST['register_all'] ?? '') === '1';
        $selected = $_POST['headers'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_unique(array_filter(array_map('strval', $selected), fn($v) => trim($v) !== '')));
        if ($registerAll) {
            $stmt = $pdo->prepare("UPDATE excel_dynamic_columns SET status='active' WHERE status='pending'");
            $stmt->execute();
            $message = 'เพิ่มหัวข้อ Excel ใหม่ที่รอตรวจสอบทั้งหมดเข้าระบบแล้ว';
        } elseif ($selected) {
            $stmt = $pdo->prepare("UPDATE excel_dynamic_columns SET status='active' WHERE status='pending' AND header_name=?");
            $n = 0;
            foreach ($selected as $headerName) { $stmt->execute([$headerName]); $n += $stmt->rowCount(); }
            $message = 'เพิ่มหัวข้อที่เลือกเข้าระบบแล้ว ' . $n . ' หัวข้อ';
        } else {
            $error = 'กรุณาเลือกหัวข้อที่ต้องการเพิ่ม';
        }
    } catch (Throwable $e) {
        $error = 'ไม่สามารถเพิ่มหัวข้อได้: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_excel') {
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'กรุณาเลือกไฟล์ Excel (.xlsx)';
    } else {
        $tmp = $_FILES['excel_file']['tmp_name'];
        $name = $_FILES['excel_file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $error = 'รองรับเฉพาะไฟล์ .xlsx';
        } else {
            try {
                $hash = hash_file('sha256', $tmp);
                $dup = $pdo->prepare('SELECT id, imported_at FROM excel_import_batches WHERE file_hash=? ORDER BY id DESC LIMIT 1');
                $dup->execute([$hash]);
                $old = $dup->fetch();
                if ($old) {
                    throw new RuntimeException('ไฟล์นี้เคยนำเข้าแล้วเมื่อ ' . $old['imported_at'] . ' (Batch #' . $old['id'] . ')');
                }

                $sheetName = xlsx_first_sheet_name($tmp);
                $rows = xlsx_read_rows($tmp);
                if (!$rows) throw new RuntimeException('ไม่พบข้อมูลใน Sheet แรก');
                $header = $rows[0];
                $map = [];
                foreach ($header as $col => $value) {
                    $headerName = trim((string)$value);
                    if ($headerName !== '' && !array_key_exists($headerName, $map)) $map[$headerName] = $col;
                }
                $unknownHeaders = array_values(array_filter(array_keys($map), fn($h) => !in_array($h, $excelColumns, true)));
                $mappingRows = $pdo->query("SELECT header_name, mapped_to_header, status FROM excel_dynamic_columns WHERE status='active' AND mapped_to_header IS NOT NULL AND mapped_to_header <> ''")->fetchAll(PDO::FETCH_ASSOC);
                $headerTargets = [];
                foreach ($mappingRows as $mr) $headerTargets[(string)$mr['header_name']] = (string)$mr['mapped_to_header'];
                // Only use an alias when its standard destination is not already supplied by the file.
                $effectiveAliases = [];
                foreach ($headerTargets as $sourceHeader => $targetHeader) {
                    if (isset($map[$sourceHeader]) && in_array($targetHeader, $excelColumns, true) && !isset($map[$targetHeader])) $effectiveAliases[$sourceHeader] = $targetHeader;
                }
                $extraHeaders = array_values(array_filter($unknownHeaders, fn($h) => !isset($effectiveAliases[$h])));
                $knownCoreHeaders = ['วันที่สมัคร','วันหมดอายุ','หมายเลขสมาชิก','รายชื่อลูกค้า','POS','สถานที่_กิจกรรม','ช่องทางจำหน่าย','ประเภทการสมัคร','ยอดสุทธิ'];
                $availableStandardHeaders = array_values(array_unique(array_merge(array_keys($map), array_values($effectiveAliases))));
                if (!array_intersect($knownCoreHeaders, $availableStandardHeaders)) {
                    throw new RuntimeException('ไม่พบหัวข้อหลักที่ระบบรู้จักในไฟล์นี้ กรุณาตรวจสอบแถวหัวตารางก่อนนำเข้า');
                }

                $pdo->beginTransaction();
                $stmtBatch = $pdo->prepare('INSERT INTO excel_import_batches(source_filename,source_sheet,row_count,file_hash) VALUES(?,?,0,?)');
                $stmtBatch->execute([$name, $sheetName, $hash]);
                $batchId = (int)$pdo->lastInsertId();

                foreach ($unknownHeaders as $unknownHeader) {
                    $register = $pdo->prepare("INSERT IGNORE INTO excel_dynamic_columns(header_name,first_seen_batch_id,status) VALUES(?,?,'pending')");
                    $register->execute([$unknownHeader, $batchId]);
                }

                $cols = implode(',', array_map(fn($c) => '`' . $c . '`', $excelColumns));
                $ph = implode(',', array_fill(0, count($excelColumns), '?'));
                $insert = $pdo->prepare("INSERT INTO excel_member_records(import_batch_id,source_row,source_section,fiscal_year,$cols,extra_data) VALUES(?,?,?,?,$ph,?)");

                $count = 0;
                $counts = ['ap' => 0, 'online' => 0, 'booth' => 0, 'unknown' => 0];
                // Fiscal-year fallback statistics make incomplete source dates visible after import.
                $fyFallbackCounts = ['วันที่ชำระครบถ้วน' => 0, 'วันที่สมัคร2' => 0];
                $fyUnresolvedCount = 0;
                for ($i = 1; $i < count($rows); $i++) {
                    $raw = [];
                    foreach ($excelColumns as $h) {
                        $c = $map[$h] ?? null;
                        $raw[$h] = $c !== null ? (string)($rows[$i][$c] ?? '') : '';
                    }
                    $extra = [];
                    foreach ($extraHeaders as $h) {
                        $c = $map[$h] ?? null;
                        $extra[$h] = $c !== null ? (string)($rows[$i][$c] ?? '') : '';
                    }
                    foreach ($effectiveAliases as $sourceHeader => $targetHeader) {
                        $sourceIndex = $map[$sourceHeader] ?? null;
                        if ($sourceIndex !== null) $raw[$targetHeader] = (string)($rows[$i][$sourceIndex] ?? '');
                    }
                    $hasData = false;
                    foreach (array_merge(array_values($raw), array_values($extra)) as $v) {
                        if (trim((string)$v) !== '') { $hasData = true; break; }
                    }
                    if (!$hasData) continue;

                    $section = classify_excel_row($raw);
                    $counts[$section]++;

                    // Prefer the official application date. If it is blank or invalid,
                    // use the completed-payment date, then the secondary application date.
                    // Keep the original Excel fields untouched; only fiscal-year assignment falls back.
                    $fy = fy_from_date_value((string)($raw['วันที่สมัคร'] ?? ''));
                    if ($fy === null) {
                        foreach (['วันที่ชำระครบถ้วน', 'วันที่สมัคร2'] as $fallbackHeader) {
                            $candidate = fy_from_date_value((string)($raw[$fallbackHeader] ?? ''));
                            if ($candidate !== null) {
                                $fy = $candidate;
                                $fyFallbackCounts[$fallbackHeader]++;
                                break;
                            }
                        }
                    }
                    if ($fy === null) $fyUnresolvedCount++;
                    $values = [$batchId, $i + 1, $section, $fy];
                    foreach ($excelColumns as $h) $values[] = $raw[$h];
                    $values[] = $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                    $insert->execute($values);
                    $count++;
                }

                $pdo->prepare('UPDATE excel_import_batches SET row_count=? WHERE id=?')->execute([$count, $batchId]);
                $pdo->commit();

                $importResult = ['batch' => $batchId, 'count' => $count, 'counts' => $counts, 'filename' => $name, 'unknown_headers' => $unknownHeaders, 'mapped_headers' => array_keys($effectiveAliases), 'fy_fallback_counts' => $fyFallbackCounts, 'fy_unresolved_count' => $fyUnresolvedCount];
                $message = 'นำเข้าเรียบร้อย — ระบบแยกข้อมูลให้แล้วด้านล่าง';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

$latestBatch = (int)($importResult['batch'] ?? 0);
if ($latestBatch > 0) {
    $sectionStats = [];
    $st = $pdo->prepare("SELECT source_section,
        COUNT(*) AS total,
        SUM(CASE WHEN `ประเภทการสมัคร`='สมัครใหม่' THEN 1 ELSE 0 END) AS new_count,
        SUM(CASE WHEN `ประเภทการสมัคร`='ต่ออายุ' THEN 1 ELSE 0 END) AS renew_count,
        COALESCE(SUM(CAST(NULLIF(REPLACE(`ยอดสุทธิ`,',',''),'') AS DECIMAL(14,2))),0) AS revenue
        FROM excel_member_records WHERE import_batch_id=? GROUP BY source_section");
    $st->execute([$latestBatch]);
    foreach ($st->fetchAll() as $r) $sectionStats[$r['source_section']] = $r;

    $latestRows = [];
    foreach (['ap','online','booth','unknown'] as $section) {
        $st = $pdo->prepare("SELECT `วันที่สมัคร`,`หมายเลขสมาชิก`,`รายชื่อลูกค้า`,`กิจกรรม/ Package`,`ประเภทการสมัคร`,`ช่องทางจำหน่าย`,`สถานที่_กิจกรรม`,`ยอดสุทธิ`,`หมายเหตุ`
            FROM excel_member_records WHERE import_batch_id=? AND source_section=? ORDER BY id ASC LIMIT 8");
        $st->execute([$latestBatch, $section]);
        $latestRows[$section] = $st->fetchAll();
    }
}

$recent = $pdo->query('SELECT * FROM excel_import_batches ORDER BY id DESC LIMIT 8')->fetchAll();
$pendingDynamicColumns = $pdo->query("SELECT header_name, first_seen_batch_id, created_at FROM excel_dynamic_columns WHERE status='pending' ORDER BY id ASC")->fetchAll();
$labels = ['ap'=>'สมัครที่ อพ.','online'=>'สมัครออนไลน์','booth'=>'ออกบูธ','unknown'=>'ยังไม่จัดหมวด'];
$icons = ['ap'=>'🏛️','online'=>'🌐','booth'=>'📢','unknown'=>'⚠️'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>นำเข้า Excel | NSM Member Club</title>
<link rel="stylesheet" href="assets/css/style.css?v=10">
<style>
.excel-hero{background:#fff;border:1px solid #e7edf3;border-radius:14px;padding:24px;margin-bottom:18px}
.excel-hero h2{margin:0 0 8px;font-size:22px}.excel-hero p{margin:0;color:#687789;line-height:1.7}
.flow{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.flow-step{background:#f6f8fb;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600}.flow-arrow{padding:10px 0;color:#94a3b8}
.upload-row{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.upload-row .form-group{min-width:320px;flex:1}
.section-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}.section-box{background:#fff;border:1px solid #e7edf3;border-radius:14px;padding:18px}.section-box.unknown{border-style:dashed}.section-title{font-weight:700;font-size:16px;display:flex;align-items:center;gap:8px}.section-total{font-size:30px;font-weight:800;margin:12px 0 4px}.section-sub{color:#748295;font-size:12px}.mini-row{display:flex;justify-content:space-between;padding:8px 0;border-top:1px solid #edf1f5;font-size:13px}.mini-row:first-of-type{margin-top:12px}
.result-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.result-head .batch{color:#748295;font-size:13px}.table-wrap{overflow:auto}.section-table{width:100%;border-collapse:collapse;min-width:900px}.section-table th,.section-table td{padding:9px 10px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px;vertical-align:top}.section-table th{background:#f8fafc;white-space:nowrap}.section-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.section-tabs a{padding:9px 12px;border:1px solid #dfe7ef;border-radius:9px;text-decoration:none;color:#34495e;background:#fff;font-size:13px}.section-tabs a.active{background:#eef5ff;border-color:#b9d4f5}.empty-note{padding:16px;color:#7a8797;background:#fafbfc;border-radius:10px}
@media(max-width:900px){.section-grid{grid-template-columns:1fr}.upload-row .form-group{min-width:100%}}
</style>
</head>
<body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'excel_import.php'); require __DIR__ . '/includes/navigation.php'; ?>

<main class="main"><div class="topbar"><div><h1>นำเข้าและดูข้อมูลจาก Excel</h1><p>นำ Excel เข้ามาครั้งเดียว แล้วดูข้อมูลที่ระบบแยกให้ครบทั้ง 3 ส่วน</p></div></div>
<div class="content">
<?php if($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?>
<?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>

<?php if(!empty($pendingDynamicColumns)): ?>
<div class="card" style="border-left:4px solid #d97706;margin-bottom:18px">
  <div class="card-title">⚠️ พบหัวข้อใหม่จาก Excel <?=count($pendingDynamicColumns)?> หัวข้อ</div>
  <div class="card-subtitle">ระบบเก็บค่าของหัวข้อใหม่ไว้กับแต่ละรายการแล้ว คุณสามารถเพิ่มหัวข้อเข้าสู่การตั้งค่าระบบได้ โดยไม่ทำให้ข้อมูลเดิมหาย</div>
  <form method="post" style="margin-top:14px"><input type="hidden" name="action" value="register_dynamic_columns">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-bottom:14px">
    <?php foreach($pendingDynamicColumns as $field): ?><label style="display:flex;align-items:center;gap:8px;border:1px solid #e7edf3;border-radius:8px;padding:10px"><input type="checkbox" name="headers[]" value="<?=e($field['header_name'])?>"><span><?=e($field['header_name'])?></span></label><?php endforeach; ?>
    </div>
    <button class="btn btn-primary" type="submit">เพิ่มหัวข้อที่เลือกเข้าระบบ</button>
    <button class="btn" type="submit" name="register_all" value="1" formnovalidate>เพิ่มทั้งหมด</button>
    <a class="btn" href="excel_fields.php">จัดการหัวข้อและการแสดงผล</a>
  </form>
</div>
<?php endif; ?>

<div class="excel-hero">
<h2>📥 1. นำไฟล์ Excel เข้ามา</h2>
<p>ไม่ต้องกรอกข้อมูลสมาชิกซ้ำ ระบบใช้หัวข้อจาก Excel เป็นข้อมูลหลัก แล้วจัดรายการแต่ละแถวไปยังส่วนที่เกี่ยวข้องให้อัตโนมัติ</p>
<div class="flow"><div class="flow-step">Excel บริษัท</div><div class="flow-arrow">→</div><div class="flow-step">นำเข้า</div><div class="flow-arrow">→</div><div class="flow-step">ระบบแยกประเภท</div><div class="flow-arrow">→</div><div class="flow-step">อพ. / ออนไลน์ / ออกบูธ</div></div>
<form method="post" enctype="multipart/form-data" style="margin-top:20px"><input type="hidden" name="action" value="import_excel"><div class="upload-row"><div class="form-group"><label class="form-label">ไฟล์ Excel (.xlsx)</label><input class="form-control" type="file" name="excel_file" accept=".xlsx" required></div><button class="btn btn-primary" type="submit">📥 นำเข้า Excel</button></div></form>
</div>

<?php if($latestBatch > 0): ?>
<div class="card"><div class="result-head"><div><div class="card-title">2. ผลจากไฟล์ที่เพิ่งนำเข้า</div><div class="card-subtitle"><?=e($importResult['filename'])?> · Batch #<?=e($latestBatch)?> · <?=number_format($importResult['count'])?> รายการ</div><?php if(!empty($importResult['unknown_headers'])): ?><div class="card-subtitle" style="margin-top:8px;color:#b45309">พบหัวข้อที่ไม่ใช่หัวข้อมาตรฐาน <?=count($importResult['unknown_headers'])?> หัวข้อ และเก็บค่าต้นทางไว้แล้ว<?php if(!empty($importResult['mapped_headers'])): ?> · จับคู่สำเร็จ <?=count($importResult['mapped_headers'])?> หัวข้อ<?php endif; ?></div><?php endif; ?><?php $fallbackTotal = array_sum($importResult['fy_fallback_counts'] ?? []); if($fallbackTotal > 0): ?><div class="card-subtitle" style="margin-top:8px;color:#166534">ระบบกำหนดปีงบประมาณจากวันที่สำรองให้ <?=number_format($fallbackTotal)?> รายการ<?php foreach(($importResult['fy_fallback_counts'] ?? []) as $dateHeader=>$dateCount): if($dateCount > 0): ?> · <?=e($dateHeader)?> <?=number_format($dateCount)?> รายการ<?php endif; endforeach; ?></div><?php endif; ?><?php if(!empty($importResult['fy_unresolved_count'])): ?><div class="card-subtitle" style="margin-top:8px;color:#b45309">⚠️ ยังหาปีงบประมาณไม่ได้ <?=number_format($importResult['fy_unresolved_count'])?> รายการ เนื่องจากวันที่สมัครและวันที่สำรองว่างหรือไม่ถูกต้อง รายการเหล่านี้จะไม่ถูกรวมในสรุปปีจนกว่าจะตรวจสอบข้อมูล</div><?php endif; ?></div></div></div>

<div class="section-grid">
<?php foreach(['ap','online','booth'] as $section): $s=$sectionStats[$section]??['total'=>0,'new_count'=>0,'renew_count'=>0,'revenue'=>0]; ?>
<div class="section-box"><div class="section-title"><?=$icons[$section]?> <?=e($labels[$section])?></div><div class="section-total"><?=number_format((int)$s['total'])?></div><div class="section-sub">รายการจาก Excel</div><div class="mini-row"><span>สมัครใหม่</span><strong><?=number_format((int)$s['new_count'])?></strong></div><div class="mini-row"><span>ต่ออายุ</span><strong><?=number_format((int)$s['renew_count'])?></strong></div><div class="mini-row"><span>ยอดสุทธิ</span><strong>฿<?=number_format((float)$s['revenue'],2)?></strong></div></div>
<?php endforeach; ?>
</div>

<?php if(!empty($sectionStats['unknown'])): ?><div class="section-box unknown" style="margin-bottom:18px"><div class="section-title">⚠️ ยังไม่จัดหมวด</div><div class="section-total"><?=number_format((int)$sectionStats['unknown']['total'])?></div><div class="section-sub">ระบบจะไม่เดาให้ ถ้าข้อมูลยังไม่ชัดเจน</div></div><?php endif; ?>

<div class="card"><div class="result-head"><div><div class="card-title">3. ข้อมูลที่แยกออกมา</div><div class="card-subtitle">ดูตัวอย่างรายการของแต่ละส่วนได้ทันทีหลังนำเข้า</div></div></div>
<div class="section-tabs"><?php foreach(['ap','online','booth','unknown'] as $section): ?><a href="#section-<?=$section?>"><?=$icons[$section]?> <?=e($labels[$section])?></a><?php endforeach; ?></div>
<?php foreach(['ap','online','booth','unknown'] as $section): ?>
<div id="section-<?=$section?>" style="margin-top:22px"><div class="section-title" style="margin-bottom:10px"><?=$icons[$section]?> <?=e($labels[$section])?></div>
<?php if(empty($latestRows[$section])): ?><div class="empty-note">ยังไม่มีรายการในส่วนนี้จากไฟล์ล่าสุด</div><?php else: ?><div class="table-wrap"><table class="section-table"><thead><tr><th>วันที่สมัคร</th><th>หมายเลขสมาชิก / ชื่อ</th><th>กิจกรรม / Package</th><th>ประเภท</th><th>ช่องทาง</th><th>สถานที่</th><th>ยอดสุทธิ</th><th>หมายเหตุ</th></tr></thead><tbody><?php foreach($latestRows[$section] as $r): ?><tr><td><?=e($r['วันที่สมัคร'])?></td><td><?=e($r['หมายเลขสมาชิก'])?><br><?=e($r['รายชื่อลูกค้า'])?></td><td><?=e($r['กิจกรรม/ Package'])?></td><td><?=e($r['ประเภทการสมัคร'])?></td><td><?=e($r['ช่องทางจำหน่าย'])?></td><td><?=e($r['สถานที่_กิจกรรม'])?></td><td>฿<?=number_format((float)str_replace(',','',$r['ยอดสุทธิ']),2)?></td><td><?=e($r['หมายเหตุ'])?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-title">ประวัติไฟล์ที่นำเข้า</div><div class="card-subtitle">เก็บไฟล์เป็น Batch เพื่อป้องกันการนำเข้าไฟล์เดิมซ้ำ</div><div class="table-wrap"><table class="section-table"><thead><tr><th>Batch</th><th>ไฟล์</th><th>จำนวน</th><th>วันที่นำเข้า</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td>#<?=e($r['id'])?></td><td><?=e($r['source_filename'])?></td><td><?=number_format((int)$r['row_count'])?></td><td><?=e($r['imported_at'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div></main></body></html>

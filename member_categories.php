<?php
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);

$message = isset($_GET['saved']) ? 'บันทึกการจัดหมวดใหม่แล้ว' : '';
if (isset($_GET['bulk_saved'])) {
    $bulkSaved = filter_input(INPUT_GET, 'bulk_saved', FILTER_VALIDATE_INT);
    if ($bulkSaved !== false && $bulkSaved !== null && $bulkSaved >= 0) {
        $message = 'จัดหมวดหมู่รายการที่เลือกสำเร็จ ' . number_format($bulkSaved) . ' รายการ';
    }
}
$error = '';
$validSections = ['ap','online','booth','unknown'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reclassify_row') {
    $rowId = filter_input(INPUT_POST, 'row_id', FILTER_VALIDATE_INT);
    $newSection = (string)($_POST['new_section'] ?? 'unknown');
    if (!$rowId || !in_array($newSection, $validSections, true)) {
        $error = 'กรุณาเลือกรายการและหมวดที่ถูกต้อง';
    } else {
        $postedFy = (int)($_POST['fy'] ?? 2569);
        if ($postedFy < 2500 || $postedFy > 2700) $postedFy = 2569;
        $stmt = $pdo->prepare("UPDATE excel_member_records SET source_section=? WHERE id=? AND fiscal_year=? AND source_section='unknown'");
        $stmt->execute([$newSection, $rowId, $postedFy]);
        header('Location: member_categories.php?fy=' . $postedFy . '&saved=1', true, 303);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reclassify_bulk') {
    $postedFy = (int)($_POST['fy'] ?? 0);
    $newSection = (string)($_POST['bulk_section'] ?? '');
    $ids = $_POST['row_ids'] ?? [];
    if ($postedFy < 2500 || $postedFy > 2700) {
        $error = 'ปีงบประมาณไม่ถูกต้อง';
    } elseif (!in_array($newSection, ['ap','online','booth'], true)) {
        $error = 'กรุณาเลือกหนึ่งในสามหมวดหลัก';
    } elseif (!is_array($ids) || !$ids) {
        $error = 'กรุณาติ๊กเลือกรายการอย่างน้อย 1 รายการ';
    } else {
        $ids = array_values(array_unique(array_filter(array_map(static function ($value) {
            if (is_array($value) || !ctype_digit((string)$value)) return null;
            $id = (int)$value;
            return $id > 0 ? $id : null;
        }, $ids))));
        if (!$ids) {
            $error = 'ไม่พบรหัสรายการที่เลือก';
        } elseif (count($ids) > 1000) {
            $error = 'เลือกได้ไม่เกิน 1,000 รายการต่อครั้ง กรุณาแบ่งทำเป็นชุด';
        } else {
            try {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE excel_member_records SET source_section=? WHERE fiscal_year=? AND source_section='unknown' AND id IN ($placeholders)");
                $stmt->execute(array_merge([$newSection, $postedFy], $ids));
                $changed = $stmt->rowCount();
                $pdo->commit();
                header('Location: member_categories.php?fy=' . $postedFy . '&bulk_saved=' . $changed, true, 303);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'จัดหมวดหมู่ไม่สำเร็จ กรุณาตรวจสอบฐานข้อมูลแล้วลองอีกครั้ง';
            }
        }
    }
}

$defaultFY = latest_excel_fiscal_year($pdo);
$fy = (int)($_GET['fy'] ?? $defaultFY);
if ($fy < 2500 || $fy > 2700) $fy = $defaultFY;

$labels = [
    'ap' => 'สมัครที่ อพ.',
    'online' => 'สมัครออนไลน์',
    'booth' => 'ออกบูธ',
    'unknown' => 'ยังไม่จัดหมวด'
];
$icons = ['ap'=>'🏛️','online'=>'🌐','booth'=>'📢','unknown'=>'⚠️'];
$descriptions = [
    'ap' => 'รายการสมัครและต่ออายุที่ระบบจัดเป็นช่องทาง อพ.',
    'online' => 'รายการที่มาจากช่องทางออนไลน์ / Online / Email',
    'booth' => 'รายการที่เกี่ยวข้องกับกิจกรรมหรือการออกบูธ',
    'unknown' => 'รายการที่ระบบยังไม่พบเงื่อนไขเพียงพอในการจัดประเภท'
];

$sql = "SELECT source_section,
    COUNT(*) AS total,
    SUM(`ประเภทการสมัคร`='สมัครใหม่') AS new_count,
    SUM(`ประเภทการสมัคร`='ต่ออายุ') AS renew_count,
    COALESCE(SUM(CAST(NULLIF(REPLACE(`ยอดสุทธิ`,',',''),'') AS DECIMAL(14,2))),0) AS revenue
    FROM excel_member_records
    WHERE fiscal_year=?
    GROUP BY source_section";
$st = $pdo->prepare($sql);
$st->execute([$fy]);
$stats = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[$r['source_section']] = $r;

$total = $new = $renew = $revenue = 0;
foreach (['ap','online','booth'] as $s) {
    $r = $stats[$s] ?? ['total'=>0,'new_count'=>0,'renew_count'=>0,'revenue'=>0];
    $total += (int)$r['total'];
    $new += (int)$r['new_count'];
    $renew += (int)$r['renew_count'];
    $revenue += (float)$r['revenue'];
}

$chartSectionKeys = ['ap','online','booth'];
$chartSectionLabels = array_map(fn($key) => $labels[$key], $chartSectionKeys);
$chartSectionCounts = []; $chartSectionRevenue = [];
foreach ($chartSectionKeys as $key) {
    $chartRow = $stats[$key] ?? ['total'=>0,'revenue'=>0];
    $chartSectionCounts[] = (int)$chartRow['total'];
    $chartSectionRevenue[] = (float)$chartRow['revenue'];
}

$batch = $pdo->query("SELECT id, source_filename, row_count, imported_at FROM excel_import_batches ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$unknownStmt = $pdo->prepare("SELECT id,`วันที่สมัคร`,`หมายเลขสมาชิก`,`รายชื่อลูกค้า`,`ช่องทางจำหน่าย`,`POS`,`สถานที่_กิจกรรม`,`กิจกรรม/ Package`,`หมายเหตุ`
    FROM excel_member_records
    WHERE fiscal_year=? AND source_section='unknown'
    ORDER BY id DESC
    LIMIT 5000");
$unknownStmt->execute([$fy]);
$unknownRows = $unknownStmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Group similar unresolved rows so the user classifies a recognizable group,
 * rather than ticking individual members. Member/date fields are deliberately
 * excluded from the grouping key; source/channel, activity/package and note
 * define the group. Original Excel values are never modified.
 */
$normalizeGroupValue = static function ($value): string {
    $value = str_replace("\xC2\xA0", ' ', (string)$value);
    $value = preg_replace('/\\s+/u', ' ', trim($value));
    return strtolower($value);
};
$unknownGroups = [];
foreach ($unknownRows as $row) {
    $groupFields = [
        $row['ช่องทางจำหน่าย'] ?? '',
        $row['POS'] ?? '',
        $row['สถานที่_กิจกรรม'] ?? '',
        $row['กิจกรรม/ Package'] ?? '',
        $row['หมายเหตุ'] ?? '',
    ];
    $keyParts = array_map($normalizeGroupValue, $groupFields);
    $groupKey = hash('sha256', json_encode($keyParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!isset($unknownGroups[$groupKey])) {
        $unknownGroups[$groupKey] = [
            'key' => $groupKey,
            'ids' => [],
            'count' => 0,
            'channel' => (string)($row['ช่องทางจำหน่าย'] ?? ''),
            'pos' => (string)($row['POS'] ?? ''),
            'place' => (string)($row['สถานที่_กิจกรรม'] ?? ''),
            'package' => (string)($row['กิจกรรม/ Package'] ?? ''),
            'note' => (string)($row['หมายเหตุ'] ?? ''),
            'examples' => [],
        ];
    }
    $unknownGroups[$groupKey]['ids'][] = (int)$row['id'];
    $unknownGroups[$groupKey]['count']++;
    if (count($unknownGroups[$groupKey]['examples']) < 2) {
        $unknownGroups[$groupKey]['examples'][] = [
            'date' => (string)($row['วันที่สมัคร'] ?? ''),
            'member' => (string)($row['หมายเลขสมาชิก'] ?? ''),
            'name' => (string)($row['รายชื่อลูกค้า'] ?? ''),
        ];
    }
}
$unknownGroups = array_values($unknownGroups);
usort($unknownGroups, static function ($a, $b) {
    return ($b['count'] <=> $a['count']) ?: strcmp($a['place'] . $a['package'], $b['place'] . $b['package']);
});
$unknownRowsScanned = count($unknownRows);
$unknownRowsMayBeTruncated = $unknownRowsScanned >= 5000;
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>แยกประเภทข้อมูล | NSM Member Club</title>
<link rel="stylesheet" href="assets/css/style.css?v=10">
<style>
.category-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:20px}
.category-card{background:#fff;border:1px solid #e7edf3;border-radius:14px;padding:20px;transition:.15s;text-decoration:none;color:inherit;display:block}
.category-card:hover{border-color:#c8d6e5;transform:translateY(-1px)}
.category-icon{font-size:28px}.category-title{font-size:18px;font-weight:700;margin-top:10px}.category-desc{font-size:13px;color:#748295;line-height:1.6;margin-top:5px;min-height:42px}.category-total{font-size:32px;font-weight:800;margin-top:14px}.category-meta{display:flex;gap:18px;margin-top:10px;padding-top:10px;border-top:1px solid #edf1f5;font-size:12px;color:#687789}.category-meta strong{color:#26384a}.category-link{display:inline-block;margin-top:15px;font-size:13px;font-weight:700;color:#2563eb}.summary-note{background:#f8fafc;border:1px solid #e7edf3;border-radius:12px;padding:14px 16px;color:#5f7083;font-size:13px;line-height:1.7;margin-bottom:18px}.unknown-card{grid-column:1/-1}.top-actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.top-actions .form-control{width:130px}@media(max-width:900px){.category-grid{grid-template-columns:1fr}.unknown-card{grid-column:auto}}
</style>
</head>
<body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'member_categories.php'); require __DIR__ . '/includes/navigation.php'; ?>

<main class="main"><div class="topbar"><div><h1>แยกประเภทข้อมูล</h1><p>ข้อมูลจาก Excel ชุดเดียวกัน แต่แสดงแยกตามประเภทโดยไม่ต้องกรอกซ้ำ</p></div></div>
<div class="content">
<?php if($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?><?php if($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<div class="card">
<form method="get" class="top-actions">
<div><div class="filter-label">ปีงบประมาณ</div><input class="form-control" type="number" name="fy" value="<?=e($fy)?>"></div>
<button class="btn btn-primary" type="submit">แสดงผล</button>
<a class="btn" href="excel_import.php">📥 นำเข้า Excel</a>
</form>
</div>

<div class="summary-note">
<strong>หลักการของหน้านี้:</strong> ระบบไม่ได้สร้างข้อมูลใหม่และไม่ได้ให้กรอกสมาชิกซ้ำ แต่ใช้ข้อมูลจาก <code>excel_member_records</code> แล้วอ่านค่า <code>source_section</code> ที่ระบบจัดประเภทตอนนำเข้า Excel เพื่อแสดงเป็น 3 ส่วนด้านล่าง หากพบรายการไม่ชัดเจน สามารถเปลี่ยนหมวดในตารางตรวจสอบได้โดยไม่แก้ไขค่าต้นฉบับในคอลัมน์ Excel
<?php if($batch): ?> · ไฟล์ล่าสุด: <strong><?=e($batch['source_filename'])?></strong> (<?=number_format((int)$batch['row_count'])?> รายการ)
<?php endif; ?>
</div>

<div class="stats">
<div class="stat-card"><div><div class="stat-label">รายการทั้งหมด 3 ส่วน</div><div class="stat-number"><?=number_format($total)?></div></div><div class="stat-icon blue">📄</div></div>
<div class="stat-card"><div><div class="stat-label">สมัครใหม่</div><div class="stat-number"><?=number_format($new)?></div></div><div class="stat-icon green">🆕</div></div>
<div class="stat-card"><div><div class="stat-label">ต่ออายุ</div><div class="stat-number"><?=number_format($renew)?></div></div><div class="stat-icon orange">🔄</div></div>
<div class="stat-card"><div><div class="stat-label">ยอดสุทธิ</div><div class="stat-number">฿<?=number_format($revenue,2)?></div></div><div class="stat-icon purple">💰</div></div>
</div>

<div class="grid" style="margin-bottom:20px">
<section class="card"><div class="card-header"><div><div class="card-title">📊 เปรียบเทียบจำนวนรายการตามช่องทาง</div><div class="card-subtitle">ปีงบประมาณ <?=e($fy)?> · แยก อพ. ออนไลน์ และออกบูธ</div></div></div><div class="chart-container"><canvas id="categoryCountChart"></canvas></div></section>
<section class="card"><div class="card-header"><div><div class="card-title">💰 เปรียบเทียบยอดสุทธิตามช่องทาง</div><div class="card-subtitle">ยอดสุทธิจาก Excel ใช้เปรียบเทียบเบื้องต้น ไม่ใช่การยืนยันสูตรรายได้ทางบัญชี</div></div></div><div class="chart-container"><canvas id="categoryRevenueChart"></canvas></div></section>
</div>

<div class="category-grid">
<?php foreach(['ap','online','booth'] as $s): $r=$stats[$s]??['total'=>0,'new_count'=>0,'renew_count'=>0,'revenue'=>0]; ?>
<a class="category-card" href="<?=e(['ap'=>'member_ap.php','online'=>'member_online_data.php','booth'=>'member_booth.php'][$s])?>?fy=<?=e($fy)?>">
<div class="category-icon"><?=e($icons[$s])?></div>
<div class="category-title"><?=e($labels[$s])?></div>
<div class="category-desc"><?=e($descriptions[$s])?></div>
<div class="category-total"><?=number_format((int)$r['total'])?> <span style="font-size:13px;font-weight:600;color:#748295">รายการ</span></div>
<div class="category-meta"><span>สมัครใหม่ <strong><?=number_format((int)$r['new_count'])?></strong></span><span>ต่ออายุ <strong><?=number_format((int)$r['renew_count'])?></strong></span></div>
<div class="category-link">ดูรายละเอียด →</div>
</a>
<?php endforeach; ?>

<?php $u=$stats['unknown']??['total'=>0,'new_count'=>0,'renew_count'=>0,'revenue'=>0]; if((int)$u['total']>0): ?>
<div class="category-card unknown-card"><div class="category-icon">⚠️</div><div class="category-title">ยังไม่จัดหมวด <?=number_format((int)$u['total'])?> รายการ</div><div class="category-desc">รายการเหล่านี้ยังไม่ถูกนำไปรวมใน 3 หมวดหลัก เพื่อป้องกันการเดาประเภทผิด สามารถจัดหมวดรายการที่ยังไม่ชัดเจนได้จากตารางตรวจสอบด้านล่าง</div></div>
<?php endif; ?>
</div>
<section class="card"><div class="card-header"><div><div class="card-title">⚠️ ตรวจสอบรายการที่ยังไม่จัดหมวด</div><div class="card-subtitle">ระบบรวมรายการที่มีช่องทาง / POS และข้อมูลกิจกรรมเหมือนกันเป็นกลุ่ม เพื่อให้จัดหมวดครั้งเดียวได้หลายรายการ</div></div></div>
<?php if(!$unknownGroups): ?><div class="empty-note">ไม่มีรายการที่รอตรวจสอบในปีงบประมาณนี้</div><?php else: ?>
<div class="summary-note" style="margin-top:12px">
<strong><?=number_format($unknownRowsScanned)?> รายการ</strong> ถูกรวมเป็น <strong><?=number_format(count($unknownGroups))?> กลุ่ม</strong> ตามค่าช่องทางจำหน่าย, POS, สถานที่/กิจกรรม, Package และหมายเหตุ
<?php if($unknownRowsMayBeTruncated): ?><br><strong>หมายเหตุ:</strong> ระบบแสดงรายการรอตรวจสอบสูงสุด 5,000 แถวในครั้งนี้ กรุณาจัดการกลุ่มที่แสดงก่อน แล้วค่อยตรวจสอบรายการที่เหลือ<?php endif; ?>
<br>ชื่อสมาชิกและวันที่สมัครใช้แสดงตัวอย่างเท่านั้น จึงไม่ทำให้รายการของคนละคนถูกแยกเป็นคนละกลุ่ม
</div>
<form method="post" id="bulkCategoryForm" onsubmit="return confirmBulkCategory()">
<input type="hidden" name="action" value="reclassify_bulk"><input type="hidden" name="fy" value="<?= (int)$fy ?>">
<div class="bulk-toolbar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:14px 0">
<label style="display:inline-flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" id="selectAllUnknownGroups"> เลือกทุกกลุ่มที่แสดง</label>
<span id="selectedUnknownCount" aria-live="polite" style="color:#64748b;font-size:13px">เลือกแล้ว 0 กลุ่ม / 0 รายการ</span>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto"><label for="bulkSection" style="font-size:13px">กำหนดหมวดให้กลุ่มที่เลือก</label><select id="bulkSection" name="bulk_section" class="form-control" required style="min-width:160px"><option value="">เลือกหมวด...</option><option value="ap">สมัครที่ อพ.</option><option value="online">สมัครออนไลน์</option><option value="booth">ออกบูธ</option></select><button class="btn btn-primary" type="submit" id="bulkApplyButton" disabled>ยืนยันจัดหมวด</button></div>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,390px),1fr));gap:12px">
<?php foreach($unknownGroups as $group): $tooLarge = count($group['ids']) > 1000; ?>
<article class="unknown-group-card" style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;min-width:0;<?= $tooLarge ? 'background:#fff7ed;border-color:#fed7aa;' : '' ?>">
<div style="display:flex;gap:10px;align-items:flex-start">
<label style="display:flex;gap:8px;align-items:flex-start;flex:1;min-width:0">
<input class="unknown-group-checkbox" type="checkbox" data-ids="<?=e(json_encode($group['ids']))?>" data-count="<?= (int)$group['count'] ?>" <?= $tooLarge ? 'disabled' : '' ?> style="margin-top:4px">
<span style="min-width:0"><strong style="display:block;overflow-wrap:anywhere"><?=e($group['place'] !== '' ? $group['place'] : 'ไม่ระบุสถานที่ / กิจกรรม')?></strong><span style="display:block;color:#64748b;font-size:13px;margin-top:3px"><?=e($group['package'] !== '' ? $group['package'] : 'ไม่ระบุ Package')?></span></span>
</label>
<span style="white-space:nowrap;border-radius:999px;background:#eff6ff;color:#1d4ed8;padding:4px 9px;font-size:12px;font-weight:700"><?=number_format((int)$group['count'])?> รายการ</span>
</div>
<div style="display:grid;grid-template-columns:1fr;gap:5px;margin:12px 0 0 26px;font-size:13px">
<div><span style="color:#64748b">ช่องทางจำหน่าย:</span> <?=e($group['channel'] !== '' ? $group['channel'] : '—')?></div>
<div><span style="color:#64748b">POS:</span> <?=e($group['pos'] !== '' ? $group['pos'] : '—')?></div>
<div><span style="color:#64748b">หมายเหตุ:</span> <?=e($group['note'] !== '' ? $group['note'] : '—')?></div>
<?php if($group['examples']): ?><div style="margin-top:5px;padding-top:8px;border-top:1px solid #edf2f7;color:#64748b">ตัวอย่างสมาชิก:
<?php foreach($group['examples'] as $example): ?><div><?=e($example['date'])?> · <?=e($example['member'])?> <?=e($example['name'])?></div><?php endforeach; ?>
</div><?php endif; ?>
<?php if($tooLarge): ?><div style="color:#9a3412;font-weight:600;margin-top:5px">กลุ่มนี้มีมากกว่า 1,000 รายการ จึงต้องแบ่งจัดการอย่างปลอดภัย</div><?php endif; ?>
</div>
</article>
<?php endforeach; ?>
</div>
<div style="display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap;padding-top:14px"><span id="selectedUnknownCountBottom" style="color:#64748b;font-size:13px">เลือกแล้ว 0 กลุ่ม / 0 รายการ</span><button class="btn btn-primary" type="submit" id="bulkApplyButtonBottom" disabled>ยืนยันจัดหมวด</button></div>
<p class="field-muted" style="font-size:12px;margin-top:10px">เมื่อเลือกกลุ่ม ระบบจะจัดหมวดให้สมาชิกทั้งหมดในกลุ่มนั้น โดยต้องตรงกับเงื่อนไขที่แสดงและยังเป็นรายการ “ยังไม่จัดหมวด” เท่านั้น ระบบไม่แก้ไขค่าต้นฉบับใน Excel และยืนยันอีกครั้งก่อนบันทึก</p>
<?php endif; ?></section>
</div></main>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const categoryLabels = <?=json_encode($chartSectionLabels, JSON_UNESCAPED_UNICODE)?>;
const categoryCounts = <?=json_encode($chartSectionCounts)?>;
const categoryRevenue = <?=json_encode($chartSectionRevenue)?>;
const categoryCountCanvas = document.getElementById('categoryCountChart');
if (categoryCountCanvas && typeof Chart !== 'undefined') new Chart(categoryCountCanvas, {type:'bar',data:{labels:categoryLabels,datasets:[{label:'จำนวนรายการ',data:categoryCounts,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
const categoryRevenueCanvas = document.getElementById('categoryRevenueChart');
if (categoryRevenueCanvas && typeof Chart !== 'undefined') new Chart(categoryRevenueCanvas, {type:'bar',data:{labels:categoryLabels,datasets:[{label:'ยอดสุทธิ',data:categoryRevenue,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'฿'+Number(c.raw||0).toLocaleString('th-TH')}}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'฿'+Number(v).toLocaleString('th-TH')}}}}});
const unknownGroupChecks = Array.from(document.querySelectorAll('.unknown-group-checkbox'));
const selectAllUnknownGroups = document.getElementById('selectAllUnknownGroups');
const selectedUnknownCount = document.getElementById('selectedUnknownCount');
const selectedUnknownCountBottom = document.getElementById('selectedUnknownCountBottom');
const bulkApplyButton = document.getElementById('bulkApplyButton');
const bulkApplyButtonBottom = document.getElementById('bulkApplyButtonBottom');
const bulkCategoryForm = document.getElementById('bulkCategoryForm');
function getSelectedGroups() {
  return unknownGroupChecks.filter(cb => cb.checked);
}
function getSelectedIds() {
  const ids = new Set();
  getSelectedGroups().forEach(cb => {
    try {
      const groupIds = JSON.parse(cb.dataset.ids || '[]');
      if (Array.isArray(groupIds)) groupIds.forEach(id => {
        const parsed = Number(id);
        if (Number.isInteger(parsed) && parsed > 0) ids.add(parsed);
      });
    } catch (err) {
      // Invalid group data is ignored; server validates every submitted ID.
    }
  });
  return Array.from(ids);
}
function updateBulkSelection() {
  const groups = getSelectedGroups();
  const ids = getSelectedIds();
  const label = 'เลือกแล้ว ' + groups.length + ' กลุ่ม / ' + ids.length + ' รายการ';
  if (selectedUnknownCount) selectedUnknownCount.textContent = label;
  if (selectedUnknownCountBottom) selectedUnknownCountBottom.textContent = label;
  const overLimit = ids.length > 1000;
  if (bulkApplyButton) bulkApplyButton.disabled = ids.length === 0 || overLimit;
  if (bulkApplyButtonBottom) bulkApplyButtonBottom.disabled = ids.length === 0 || overLimit;
  if (selectAllUnknownGroups) {
    const selectable = unknownGroupChecks.filter(cb => !cb.disabled);
    const selected = selectable.filter(cb => cb.checked).length;
    selectAllUnknownGroups.checked = selectable.length > 0 && selected === selectable.length;
    selectAllUnknownGroups.indeterminate = selected > 0 && selected < selectable.length;
  }
  if (bulkCategoryForm) {
    bulkCategoryForm.querySelectorAll('input.generated-row-id').forEach(el => el.remove());
    ids.forEach(id => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'row_ids[]';
      input.value = String(id);
      input.className = 'generated-row-id';
      bulkCategoryForm.appendChild(input);
    });
  }
}
if (selectAllUnknownGroups) selectAllUnknownGroups.addEventListener('change', () => {
  unknownGroupChecks.forEach(cb => { if (!cb.disabled) cb.checked = selectAllUnknownGroups.checked; });
  updateBulkSelection();
});
unknownGroupChecks.forEach(cb => cb.addEventListener('change', updateBulkSelection));
function confirmBulkCategory() {
  const groups = getSelectedGroups();
  const ids = getSelectedIds();
  const sectionSelect = document.getElementById('bulkSection');
  if (!groups.length || !ids.length) { alert('กรุณาเลือกอย่างน้อย 1 กลุ่ม'); return false; }
  if (ids.length > 1000) { alert('เลือกได้ไม่เกิน 1,000 รายการต่อครั้ง กรุณาแบ่งเป็นชุด'); return false; }
  if (!sectionSelect || !sectionSelect.value) { alert('กรุณาเลือกหมวดปลายทาง'); return false; }
  const labels = {ap:'สมัครที่ อพ.', online:'สมัครออนไลน์', booth:'ออกบูธ'};
  return confirm('ยืนยันจัดหมวด ' + groups.length + ' กลุ่ม รวม ' + ids.length + ' รายการ ไปยังหมวด “' + (labels[sectionSelect.value] || sectionSelect.value) + '” ใช่หรือไม่?');
}
updateBulkSelection();
</script>
</body></html>

<?php
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);
$message = '';
$error = '';

/** Fill an empty standard field from previously imported extra_data without deleting source values. */
function backfillMappedExcelHeader(PDO $pdo, string $sourceHeader, string $targetHeader): int {
    global $excelColumns;
    if (!in_array($targetHeader, $excelColumns, true)) return 0;
    $targetSql = '`' . str_replace('`', '``', $targetHeader) . '`';
    $rows = $pdo->query("SELECT id, extra_data, $targetSql AS target_value FROM excel_member_records WHERE extra_data IS NOT NULL AND extra_data <> ''")->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare("UPDATE excel_member_records SET $targetSql=? WHERE id=? AND ($targetSql IS NULL OR TRIM($targetSql)='')");
    $changed = 0;
    foreach ($rows as $row) {
        if (trim((string)($row['target_value'] ?? '')) !== '') continue;
        $data = json_decode((string)$row['extra_data'], true);
        if (!is_array($data) || !array_key_exists($sourceHeader, $data)) continue;
        $value = (string)$data[$sourceHeader];
        if (trim($value) === '') continue;
        $update->execute([$value, (int)$row['id']]);
        $changed += $update->rowCount();
    }
    return $changed;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_field') {
        try {
            $header = trim((string)($_POST['header_name'] ?? ''));
            $target = trim((string)($_POST['mapped_to_header'] ?? ''));
            if ($header === '') throw new RuntimeException('กรุณากรอกชื่อหัวข้อ Excel');
            if (function_exists('mb_strlen') ? mb_strlen($header, 'UTF-8') > 255 : strlen($header) > 255) throw new RuntimeException('ชื่อหัวข้อต้องไม่เกิน 255 ตัวอักษร');
            if (in_array($header, $excelColumns, true)) throw new RuntimeException('หัวข้อนี้เป็นหัวข้อมาตรฐานอยู่แล้ว ไม่ต้องเพิ่มซ้ำ');
            if ($target !== '' && !in_array($target, $excelColumns, true)) throw new RuntimeException('ช่องปลายทางที่เลือกไม่ถูกต้อง');
            $stmt = $pdo->prepare("INSERT INTO excel_dynamic_columns(header_name,status,mapped_to_header) VALUES(?, 'active', ?) ON DUPLICATE KEY UPDATE mapped_to_header=VALUES(mapped_to_header), status=IF(status IN ('ignored','pending'),'active',status)");
            $stmt->execute([$header, $target !== '' ? $target : null]);
            $backfilled = $target !== '' ? backfillMappedExcelHeader($pdo, $header, $target) : 0;
            $message = 'ลงทะเบียนหัวข้อ Excel ล่วงหน้าแล้ว' . ($target !== '' ? ' และจับคู่กับ “' . $target . '”' . ($backfilled ? ' พร้อมเติมข้อมูลเดิมที่ช่องปลายทางว่าง ' . number_format($backfilled) . ' รายการ' : '') : ' สามารถเก็บค่าเป็นข้อมูลเพิ่มเติมได้');
        } catch (Throwable $e) { $error = 'เพิ่มหัวข้อไม่สำเร็จ: ' . $e->getMessage(); }
    } elseif ($action === 'save_fields') {
        try {
            $configs = $_POST['fields'] ?? [];
            if (!is_array($configs)) $configs = [];
            $allowedScopes = ['all','ap','online','booth','unknown'];
            $dynamicHeaderNames = [];
            foreach (array_keys($configs) as $postedId) {
                if (!ctype_digit((string)$postedId)) continue;
                $lookup = $pdo->prepare('SELECT header_name FROM excel_dynamic_columns WHERE id=? AND status=\'active\'');
                $lookup->execute([(int)$postedId]);
                $foundHeader = $lookup->fetchColumn();
                if ($foundHeader !== false) $dynamicHeaderNames[(int)$postedId] = (string)$foundHeader;
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE excel_dynamic_columns SET section_scope=?, display_in_table=?, use_in_filter=?, use_in_report=?, use_in_chart=?, mapped_to_header=? WHERE id=? AND status='active'");
            foreach ($configs as $id => $cfg) {
                if (!is_array($cfg) || !ctype_digit((string)$id)) continue;
                $scope = (string)($cfg['section_scope'] ?? 'all');
                if (!in_array($scope, $allowedScopes, true)) $scope = 'all';
                $target = trim((string)($cfg['mapped_to_header'] ?? ''));
                if ($target !== '' && !in_array($target, $excelColumns, true)) $target = '';
                $stmt->execute([$scope, isset($cfg['display_in_table']) ? 1 : 0, isset($cfg['use_in_filter']) ? 1 : 0, isset($cfg['use_in_report']) ? 1 : 0, isset($cfg['use_in_chart']) ? 1 : 0, $target !== '' ? $target : null, (int)$id]);
                if ($target !== '') backfillMappedExcelHeader($pdo, (string)($dynamicHeaderNames[$id] ?? ''), $target);
            }
            $pdo->commit();
            $message = 'บันทึกการตั้งค่าหัวข้อ Excel แล้ว';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'บันทึกการตั้งค่าไม่สำเร็จ: ' . $e->getMessage();
        }
    } elseif ($action === 'ignore_field') {
        try {
            $id = filter_input(INPUT_POST, 'field_id', FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('ไม่พบรหัสหัวข้อ');
            $stmt = $pdo->prepare("UPDATE excel_dynamic_columns SET status='ignored' WHERE id=? AND status='pending'");
            $stmt->execute([$id]);
            $message = $stmt->rowCount() ? 'ย้ายหัวข้อไปยังรายการที่ไม่ใช้งานแล้ว' : 'ไม่พบหัวข้อที่รอจัดการ';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

$dynamicFields = $pdo->query("SELECT * FROM excel_dynamic_columns ORDER BY FIELD(status,'pending','active','ignored'), header_name")->fetchAll(PDO::FETCH_ASSOC);
$dynamicByName = [];
foreach ($dynamicFields as $field) $dynamicByName[(string)$field['header_name']] = $field;
$coreHeaders = array_values(array_unique(array_filter(array_map('strval', $excelColumns), static fn($v) => trim($v) !== '')));
$allCount = count(array_unique(array_merge($coreHeaders, array_keys($dynamicByName))));
$pendingCount = 0; $activeCount = count($coreHeaders); $ignoredCount = 0;
foreach ($dynamicFields as $field) {
    if ($field['status'] === 'pending') $pendingCount++;
    elseif ($field['status'] === 'active') $activeCount++;
    elseif ($field['status'] === 'ignored') $ignoredCount++;
}
$statusLabels = ['pending'=>'รอตรวจสอบ','active'=>'เพิ่มเข้าระบบแล้ว','ignored'=>'ไม่ใช้งาน'];
$scopeLabels = ['all'=>'ทุกหมวด','ap'=>'สมัครที่ อพ.','online'=>'สมัครออนไลน์','booth'=>'ออกบูธ','unknown'=>'ยังไม่จัดหมวด'];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ตั้งค่าหัวข้อ Excel | NSM Member Club</title>
<link rel="stylesheet" href="assets/css/style.css?v=10">
<style>
.pre-register-card{margin-bottom:16px}.pre-register-form{display:grid;grid-template-columns:minmax(220px,1fr) minmax(240px,1fr) auto;gap:12px;align-items:end;margin-top:14px}.pre-register-form .form-group{margin:0}.field-note{background:#f8fafc;border:1px solid #e7edf3;border-radius:10px;padding:14px;color:#526174;line-height:1.65;margin-bottom:16px}
.field-stats{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:12px;margin-bottom:16px}.field-stat{border:1px solid #e7edf3;border-radius:10px;padding:14px;background:#fff}.field-stat strong{display:block;font-size:23px;line-height:1.35;color:#24364b}.field-stat span{font-size:12px;color:#718096}
.field-toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.field-toolbar input,.field-toolbar select{min-width:180px;max-width:100%;flex:1}.field-table-wrap{overflow:auto}.field-table{width:100%;border-collapse:collapse;min-width:900px}.field-table th,.field-table td{padding:10px;border-bottom:1px solid #edf1f5;text-align:left;font-size:13px;vertical-align:middle}.field-table th{background:#f8fafc;white-space:nowrap}.field-table select{max-width:170px}.field-checks{display:flex;gap:10px;flex-wrap:wrap}.field-checks label{white-space:nowrap;display:inline-flex;gap:5px;align-items:center}.field-status{display:inline-block;padding:4px 8px;border-radius:6px;background:#f1f5f9;font-size:12px;white-space:nowrap}.field-status.active{background:#dcfce7;color:#166534}.field-status.pending{background:#fef3c7;color:#92400e}.field-status.ignored{background:#f1f5f9;color:#64748b}.field-kind{font-size:11px;color:#718096;margin-top:4px}.field-muted{color:#7a8797}.field-row-hidden{display:none}
@media(max-width:700px){.field-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.pre-register-form{grid-template-columns:1fr}.pre-register-form .btn{width:100%}}
</style>
</head>
<body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'excel_fields.php'); require __DIR__ . '/includes/navigation.php'; ?>
<main class="main"><div class="topbar"><div><h1>ตั้งค่าหัวข้อ Excel</h1><p>ลงทะเบียนหัวข้อล่วงหน้า จับคู่ชื่อคอลัมน์กับช่องมาตรฐาน และจัดการหัวข้อใหม่ที่พบตอนนำเข้า</p></div></div><div class="content">
<?php if ($message): ?><div class="alert success"><?=e($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<div class="field-note"><strong>วิธีใช้งาน:</strong> เพิ่มชื่อคอลัมน์ล่วงหน้าได้ที่นี่ หรือรอให้ระบบพบหัวข้อใหม่ระหว่างนำเข้า จากนั้นเลือกช่องมาตรฐานที่ต้องการจับคู่ หากไม่ต้องจับคู่กับช่องมาตรฐาน ระบบจะเก็บค่าไว้ใน <code>extra_data</code> เพื่อไม่ให้ข้อมูลหาย การเปลี่ยนการจับคู่มีผลกับการนำเข้าในครั้งถัดไป ไม่ย้อนแก้ข้อมูลที่นำเข้าไปแล้ว</div>
<div class="card pre-register-card"><div class="card-title">＋ ลงทะเบียนหัวข้อ Excel ล่วงหน้า</div><div class="card-subtitle">ใช้เมื่อทราบว่าบริษัทจะเพิ่มคอลัมน์ใหม่ก่อนส่งไฟล์มา</div><form method="post" class="pre-register-form"><input type="hidden" name="action" value="add_field"><div class="form-group"><label class="form-label" for="newHeader">ชื่อหัวข้อใน Excel</label><input id="newHeader" class="form-control" name="header_name" maxlength="255" placeholder="เช่น วันที่รับสิทธิ์" required></div><div class="form-group"><label class="form-label" for="newTarget">จับคู่กับช่องข้อมูลมาตรฐาน (ไม่บังคับ)</label><select id="newTarget" class="form-control" name="mapped_to_header"><option value="">เก็บเป็นข้อมูลเพิ่มเติม ไม่จับคู่</option><?php foreach ($coreHeaders as $core): ?><option value="<?=e($core)?>"><?=e($core)?></option><?php endforeach; ?></select></div><button class="btn btn-primary" type="submit">บันทึกหัวข้อล่วงหน้า</button></form></div>
<div class="field-stats"><div class="field-stat"><strong><?=number_format($allCount)?></strong><span>หัวข้อทั้งหมดไม่ซ้ำ</span></div><div class="field-stat"><strong><?=number_format(count($coreHeaders))?></strong><span>หัวข้อหลักของระบบ</span></div><div class="field-stat"><strong><?=number_format($pendingCount)?></strong><span>หัวข้อใหม่รอตรวจสอบ</span></div><div class="field-stat"><strong><?=number_format($ignoredCount)?></strong><span>หัวข้อที่ตั้งเป็นไม่ใช้งาน</span></div></div>
<div class="card"><div class="card-header"><div><div class="card-title">รายการหัวข้อทั้งหมด</div><div class="card-subtitle">หัวข้อหลักจะแสดงเป็นรายการมาตรฐาน ส่วนหัวข้อใหม่จะมีสถานะและตัวเลือกจัดการ</div></div></div>
<div class="field-toolbar"><input id="headerSearch" class="form-control" type="search" placeholder="ค้นหาชื่อหัวข้อ..." aria-label="ค้นหาชื่อหัวข้อ"><select id="headerStatus" class="form-control" aria-label="กรองตามสถานะ"><option value="all">ทุกสถานะ</option><option value="core">หัวข้อหลักของระบบ</option><option value="pending">รอตรวจสอบ</option><option value="active">เพิ่มเข้าระบบแล้ว</option><option value="ignored">ไม่ใช้งาน</option></select><select id="headerScope" class="form-control" aria-label="กรองตามหมวด"><option value="all">ทุกหมวด</option><option value="ap">สมัครที่ อพ.</option><option value="online">สมัครออนไลน์</option><option value="booth">ออกบูธ</option><option value="unknown">ยังไม่จัดหมวด</option></select></div>
<form method="post" id="fieldSettingsForm"><input type="hidden" name="action" value="save_fields"><div class="field-table-wrap"><table class="field-table"><thead><tr><th>ชื่อหัวข้อ</th><th>แหล่งที่มา</th><th>สถานะ</th><th>จับคู่ช่องมาตรฐาน</th><th>หมวดข้อมูล</th><th>การแสดงผล</th><th>จัดการ</th></tr></thead><tbody>
<?php foreach ($coreHeaders as $header): $f = $dynamicByName[$header] ?? null; if ($f && $f['status'] === 'ignored') { $f = null; } ?>
<tr class="field-row" data-name="<?=e(function_exists('mb_strtolower') ? mb_strtolower($header, 'UTF-8') : strtolower($header))?>" data-status="core" data-scope="all"><td><strong><?=e($header)?></strong><div class="field-kind">หัวข้อข้อมูลมาตรฐานของระบบ</div></td><td>โครงสร้างหลัก</td><td><span class="field-status active">หัวข้อหลัก</span></td><td>ใช้กับข้อมูลสมาชิกตามโครงสร้างเดิม</td><td><span class="field-muted">ระบบใช้งานอยู่</span></td><td><span class="field-muted">ไม่ต้องเพิ่มซ้ำ</span></td></tr>
<?php endforeach; ?>
<?php foreach ($dynamicFields as $f): if (in_array($f['header_name'], $coreHeaders, true)) continue; $status = (string)$f['status']; ?>
<tr class="field-row" data-name="<?=e(function_exists('mb_strtolower') ? mb_strtolower($f['header_name'], 'UTF-8') : strtolower($f['header_name']))?>" data-status="<?=e($status)?>" data-scope="<?=e($f['section_scope'])?>"><td><strong><?=e($f['header_name'])?></strong><div class="field-kind">พบครั้งแรก Batch #<?=e($f['first_seen_batch_id'] ?? '-')?></div></td><td>หัวข้อเพิ่มเติมจาก Excel</td><td><span class="field-status <?=e($status)?>"><?=e($statusLabels[$status] ?? $status)?></span></td>
<td><?php if ($status === 'active'): ?><select class="form-control" name="fields[<?= (int)$f['id']?>][mapped_to_header]"><option value="" <?=empty($f['mapped_to_header'])?'selected':''?>>ไม่จับคู่ / เก็บข้อมูลเพิ่ม</option><?php foreach($coreHeaders as $core): ?><option value="<?=e($core)?>" <?=($f['mapped_to_header'] ?? '')===$core?'selected':''?>><?=e($core)?></option><?php endforeach; ?></select><?php else: ?><span class="field-muted">ตั้งค่าได้หลังเพิ่มเข้าระบบ</span><?php endif; ?></td>
<td><?php if ($status === 'active'): ?><select class="form-control" name="fields[<?= (int)$f['id']?>][section_scope]"><?php foreach($scopeLabels as $key=>$label): ?><option value="<?=e($key)?>" <?=$f['section_scope']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select><?php else: ?><span class="field-muted">เพิ่มหัวข้อก่อน</span><?php endif; ?></td>
<td><?php if ($status === 'active'): ?><div class="field-checks"><label><input type="checkbox" name="fields[<?= (int)$f['id']?>][display_in_table]" value="1" <?=$f['display_in_table']?'checked':''?>> ตาราง</label><label><input type="checkbox" name="fields[<?= (int)$f['id']?>][use_in_filter]" value="1" <?=$f['use_in_filter']?'checked':''?>> ตัวกรอง</label><label><input type="checkbox" name="fields[<?= (int)$f['id']?>][use_in_report]" value="1" <?=$f['use_in_report']?'checked':''?>> รายงาน</label><label><input type="checkbox" name="fields[<?= (int)$f['id']?>][use_in_chart]" value="1" <?=$f['use_in_chart']?'checked':''?>> กราฟ</label></div><?php else: ?><span class="field-muted">ตั้งค่าได้หลังเพิ่มเข้าระบบ</span><?php endif; ?></td>
<td><?php if ($status === 'pending'): ?><div style="display:flex;gap:6px;flex-wrap:wrap"><button class="btn btn-primary" type="submit" form="registerHeader<?= (int)$f['id']?>">เพิ่มเข้าระบบ</button><button class="btn" type="submit" form="ignoreHeader<?= (int)$f['id']?>">ไม่ใช้งาน</button></div><?php else: ?><span class="field-muted">—</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="field-muted" id="filterResult" aria-live="polite" style="margin-top:10px"></div>
<div style="margin-top:14px"><button class="btn btn-primary" type="submit">บันทึกการตั้งค่าหัวข้อ</button></div>
</form>
<?php foreach ($dynamicFields as $f): if ($f['status'] !== 'pending' || in_array($f['header_name'], $coreHeaders, true)) continue; ?>
<form id="registerHeader<?= (int)$f['id']?>" method="post" action="excel_import.php"><input type="hidden" name="action" value="register_dynamic_columns"><input type="hidden" name="headers[]" value="<?=e($f['header_name'])?>"></form>
<form id="ignoreHeader<?= (int)$f['id']?>" method="post" action="excel_fields.php"><input type="hidden" name="action" value="ignore_field"><input type="hidden" name="field_id" value="<?= (int)$f['id']?>"></form>
<?php endforeach; ?>
</div>
<div class="card"><div class="card-title">หมายเหตุการทำงาน</div><div class="card-subtitle">การจับคู่หัวข้อเพิ่มเติมกับช่องมาตรฐานจะใช้กับการนำเข้าในครั้งถัดไปเท่านั้น ข้อมูลเดิมที่เก็บใน extra_data จะไม่ถูกย้ายหรือแก้ไขอัตโนมัติ เพื่อป้องกันข้อมูลสูญหาย ส่วนตัวเลือกตาราง/ตัวกรอง/รายงาน/กราฟเป็นการตั้งค่าหัวข้อเพิ่มเติม ไม่ได้เปลี่ยนสูตรคำนวณหลักโดยอัตโนมัติ</div></div>
</div></main>
<script>
(function(){
 const search=document.getElementById('headerSearch'), status=document.getElementById('headerStatus'), scope=document.getElementById('headerScope'), rows=Array.from(document.querySelectorAll('.field-row')), result=document.getElementById('filterResult');
 function filterRows(){const q=(search.value||'').trim().toLocaleLowerCase('th');const st=status.value, sc=scope.value;let shown=0;rows.forEach(row=>{const okName=!q||(row.dataset.name||'').includes(q);const okStatus=st==='all'||row.dataset.status===st;const okScope=sc==='all'||row.dataset.scope===sc;const visible=okName&&okStatus&&okScope;row.classList.toggle('field-row-hidden',!visible);if(visible)shown++;});result.textContent='แสดง '+shown+' จาก '+rows.length+' หัวข้อ';}
 [search,status,scope].forEach(el=>el.addEventListener('input',filterRows));filterRows();
})();
</script>
</body></html>

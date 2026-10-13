<?php
/**
 * Activity / off-site booth summary based on existing Excel-imported booth records.
 * No database schema or imported data is modified by this report page.
 */
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);

$defaultFY = latest_excel_fiscal_year($pdo);
$fy = (int)($_GET['fy'] ?? $defaultFY);
if ($fy < 2500 || $fy > 2700) $fy = $defaultFY;
$month = (int)($_GET['month'] ?? 0);
if ($month < 0 || $month > 12) $month = 0;
$q = trim((string)($_GET['q'] ?? ''));

$months = [10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.',1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.'];
$categoryKeys = ['mag1'=>'นิตยสาร 1 ปี','mag2'=>'นิตยสาร 2 ปี','adult'=>'ผู้ใหญ่ 1 ปี','family'=>'ครอบครัว 1 ปี','institution'=>'สถาบัน 1 ปี'];

function booth_date_info($value): array {
    $raw = trim((string)$value);
    if ($raw === '') return [null, null, null];
    $parsed = null;
    if (function_exists('parse_import_date')) {
        try { $parsed = parse_import_date($raw); } catch (Throwable $e) { $parsed = null; }
    }
    if (!$parsed) {
        $formats = ['!d/m/Y H:i','!d/m/Y','!Y-m-d H:i:s','!Y-m-d','!d-m-Y','!j/n/Y'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $raw);
            if ($dt instanceof DateTime && DateTime::getLastErrors() === false) { $parsed = $dt->format('Y-m-d'); break; }
        }
    }
    if (!$parsed) return [null, null, null];
    try { $dt = new DateTime(substr((string)$parsed, 0, 10)); } catch (Throwable $e) { return [null, null, null]; }
    $year = (int)$dt->format('Y');
    // Accept Buddhist Era dates that were not normalized by the importer.
    if ($year >= 2400 && $year <= 2700) { $dt->modify('-543 years'); $year -= 543; }
    $monthNum = (int)$dt->format('n');
    $fyValue = $monthNum >= 10 ? $year + 544 : $year + 543;
    return [$dt->format('Y-m-d'), $monthNum, $fyValue];
}

function booth_member_category($value): ?string {
    $s = mb_strtolower(trim((string)$value), 'UTF-8');
    if ($s === '') return null;
    if (mb_strpos($s, 'นิตยสาร 2 ปี') !== false || mb_strpos($s, 'วารสาร 2 ปี') !== false) return 'mag2';
    if (mb_strpos($s, 'นิตยสาร 1 ปี') !== false || mb_strpos($s, 'วารสาร 1 ปี') !== false) return 'mag1';
    if (mb_strpos($s, 'ครอบครัว') !== false) return 'family';
    if (mb_strpos($s, 'สถาบัน') !== false || mb_strpos($s, 'สถานศึกษา') !== false) return 'institution';
    if (mb_strpos($s, 'ผู้ใหญ่') !== false || mb_strpos($s, 'บุคคลทั่วไป') !== false) return 'adult';
    return null;
}
function booth_money($value): float {
    $s = trim(str_replace([',', '฿', ' '], '', (string)$value));
    return is_numeric($s) ? (float)$s : 0.0;
}

// Only read the booth section. We intentionally do not infer event usage from AP/online records.
$st = $pdo->prepare("SELECT id, source_row, fiscal_year, `วันที่สมัคร`, `หมายเลขสมาชิก`, `รายชื่อลูกค้า`, `กลุ่มลูกค้า`, `กิจกรรม/ Package`, `สถานที่_กิจกรรม`, `ประเภทการสมัคร`, `ยอดสุทธิ`, `สถานะรายการ`, `หมายเหตุ` FROM excel_member_records WHERE source_section='booth' ORDER BY id ASC");
$st->execute();
$allRows = $st->fetchAll(PDO::FETCH_ASSOC);
$groups = [];
$unmatchedDates = 0;
$excludedStatus = 0;
$filteredRows = 0;
$overall = ['events'=>0,'records'=>0,'members'=>0,'revenue'=>0.0,'unclassified'=>0];
$memberSet = [];
$monthlyEvents = array_fill_keys(array_keys($months), 0);

foreach ($allRows as $r) {
    $status = trim((string)($r['สถานะรายการ'] ?? ''));
    if ($status !== '' && !in_array(mb_strtolower($status, 'UTF-8'), ['สำเร็จ','success','successful','completed','complete'], true)) { $excludedStatus++; continue; }
    [$dateISO, $monthNum, $computedFY] = booth_date_info($r['วันที่สมัคร'] ?? '');
    $rowFY = (int)($r['fiscal_year'] ?? 0);
    if ($rowFY < 2500 || $rowFY > 2700) $rowFY = (int)($computedFY ?? 0);
    if ($rowFY !== $fy) continue;
    if ($month && $monthNum !== $month) continue;
    $activity = trim((string)($r['กิจกรรม/ Package'] ?? '')) ?: 'ไม่ระบุชื่องาน';
    $place = trim((string)($r['สถานที่_กิจกรรม'] ?? '')) ?: 'ไม่ระบุสถานที่';
    if ($q !== '' && mb_stripos($activity.' '.$place.' '.(string)($r['หมายเหตุ'] ?? ''), $q, 0, 'UTF-8') === false) continue;
    $filteredRows++;
    if (!$dateISO) $unmatchedDates++;
    // Date + event + venue approximates an event grouping; there is no event ID in source data.
    $key = ($dateISO ?: 'date-unknown').'|'.$activity.'|'.$place;
    if (!isset($groups[$key])) {
        $groups[$key] = ['date'=>$dateISO,'date_raw'=>(string)($r['วันที่สมัคร'] ?? ''),'activity'=>$activity,'place'=>$place,'categories'=>array_fill_keys(array_keys($categoryKeys),0),'records'=>0,'members'=>0,'member_ids'=>[],'revenue'=>0.0,'unclassified'=>0,'first_id'=>(int)$r['id']];
    }
    $g =& $groups[$key];
    $g['records']++;
    $g['revenue'] += booth_money($r['ยอดสุทธิ'] ?? '');
    $cat = booth_member_category($r['ประเภทการสมัคร'] ?? '');
    if (!$cat) $cat = booth_member_category($r['กลุ่มลูกค้า'] ?? '');
    if ($cat) $g['categories'][$cat]++;
    else $g['unclassified']++;
    $memberNo = trim((string)($r['หมายเลขสมาชิก'] ?? ''));
    $memberKey = $memberNo !== '' ? $memberNo : ('record-'.$r['id']);
    $g['member_ids'][$memberKey] = true;
    $memberSet[$memberKey] = true;
    unset($g);
}

$activities = array_values($groups);
foreach ($activities as &$g) {
    $g['members'] = count($g['member_ids']);
    unset($g['member_ids']);
}
unset($g);
usort($activities, function($a,$b) { return strcmp((string)$a['date'], (string)$b['date']) ?: strcmp($a['activity'], $b['activity']) ?: strcmp($a['place'], $b['place']); });
$overall['events'] = count($activities);
$overall['records'] = $filteredRows;
$overall['members'] = count($memberSet);
foreach ($activities as $g) { $overall['revenue'] += $g['revenue']; $overall['unclassified'] += $g['unclassified']; if ($g['date']) { [, $m] = [null, (int)substr($g['date'],5,2)]; if (isset($monthlyEvents[$m])) $monthlyEvents[$m]++; } }
$categoryTotals = array_fill_keys(array_keys($categoryKeys),0);
foreach ($activities as $g) foreach ($categoryTotals as $k=>$_) $categoryTotals[$k] += $g['categories'][$k];
$visibleActivities = array_slice($activities, 0, 500);
$fyOptions = [];
foreach ($allRows as $r) { [, , $calcFY] = booth_date_info($r['วันที่สมัคร'] ?? ''); $optionFY=(int)($r['fiscal_year']??0); if ($optionFY<2500||$optionFY>2700) $optionFY=(int)($calcFY??0); if ($optionFY>=2500&&$optionFY<=2700) $fyOptions[$optionFY]=true; }
$fyOptions[$fy]=true; krsort($fyOptions);
$chartLabels = array_values($months);
$chartValues = array_map(fn($m)=>(int)$monthlyEvents[$m], array_keys($months));
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>กิจกรรม/ออกบูธนอกสถานที่ | NSM Member Club</title><link rel="stylesheet" href="assets/css/style.css?v=10"><style>
.report-title{font-size:20px;font-weight:700;color:#173b73;margin:0 0 6px}.report-subtitle{color:#53647b;margin:0 0 18px}.report-table th,.report-table td{white-space:normal;vertical-align:middle}.report-table .num{text-align:right;white-space:nowrap}.report-table tfoot td{font-weight:700;background:#f1f3f5}.report-table .event-name{min-width:220px}.report-table .place{min-width:150px}.report-table .date{min-width:135px}.report-hint{padding:12px 14px;background:#fff8e8;border:1px solid #f1dfad;border-radius:10px;color:#6d5a2b;margin-bottom:18px}.mini-chart{height:280px;position:relative}.category-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin:16px 0}.category-chip{border:1px solid #e0e7ef;border-radius:10px;padding:12px;background:#fff}.category-chip span{display:block;color:#64748b;font-size:12px}.category-chip strong{display:block;font-size:20px;margin-top:5px;color:#243b59}@media print{.sidebar,.topbar form,.filters-card,.no-print{display:none!important}.main{margin:0!important}.content{padding:0!important}.card{box-shadow:none!important;break-inside:avoid}}
</style></head><body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'outdoor_booth.php'); require __DIR__ . '/includes/navigation.php'; ?>
<main class="main"><div class="topbar"><div><h1>กิจกรรม/ออกบูธนอกสถานที่</h1><p>รายงานสรุปยอดสมาชิกแยกตามงาน วันที่ สถานที่ และประเภทสมาชิก</p></div></div><div class="content">
<div class="report-hint"><strong>หมายเหตุการคำนวณ:</strong> รายงานนี้อ่านเฉพาะรายการที่นำเข้าในหมวด <code>booth</code> และใช้วันที่สมัครเป็นวันที่อ้างอิงชั่วคราวตามฟิลด์ที่มีอยู่ กลุ่มงานคำนวณจากวันที่ + ชื่องาน + สถานที่ เพราะฐานข้อมูลยังไม่มีรหัสกิจกรรมเฉพาะ ตัวเลขนี้จึงอาจไม่ตรงกับรายงานต้นฉบับจนกว่าจะตรวจ mapping กับไฟล์ Excel จริง</div>
<div class="card filters-card"><form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap"><div><div class="filter-label">ปีงบประมาณ</div><select class="form-control" name="fy" style="min-width:140px"><?php foreach(array_keys($fyOptions) as $y):?><option value="<?=e($y)?>" <?=$fy===$y?'selected':''?>><?=e($y)?></option><?php endforeach;?></select></div><div><div class="filter-label">เดือน</div><select class="form-control" name="month"><option value="0">ทุกเดือน</option><?php foreach($months as $m=>$label):?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div><div style="flex:1;min-width:190px"><div class="filter-label">ค้นหางาน/สถานที่</div><input class="form-control" name="q" value="<?=e($q)?>" placeholder="ชื่องานหรือสถานที่"></div><button class="btn btn-primary" type="submit">แสดงรายงาน</button><button class="btn" type="button" onclick="window.print()">พิมพ์รายงาน</button></form></div>
<div class="stats"><div class="stat-card"><div><div class="stat-label">กลุ่มกิจกรรมโดยประมาณ</div><div class="stat-number"><?=number_format($overall['events'])?></div></div><div class="stat-icon blue">🗓️</div></div><div class="stat-card"><div><div class="stat-label">รายการสมาชิกในรายงาน</div><div class="stat-number"><?=number_format($overall['records'])?></div></div><div class="stat-icon green">📄</div></div><div class="stat-card"><div><div class="stat-label">หมายเลขสมาชิกไม่ซ้ำ*</div><div class="stat-number"><?=number_format($overall['members'])?></div></div><div class="stat-icon orange">👥</div></div><div class="stat-card"><div><div class="stat-label">ยอดสุทธิรวมจากรายการ</div><div class="stat-number">฿<?=number_format($overall['revenue'],2)?></div></div><div class="stat-icon purple">💰</div></div></div>
<div class="category-summary"><?php foreach($categoryKeys as $key=>$label):?><div class="category-chip"><span><?=e($label)?></span><strong><?=number_format($categoryTotals[$key])?></strong></div><?php endforeach;?><div class="category-chip"><span>ยังจัดประเภทไม่ได้</span><strong><?=number_format($overall['unclassified'])?></strong></div></div>
<div class="grid" style="margin-bottom:20px"><div class="card"><div class="card-header"><div><div class="card-title">จำนวนกลุ่มกิจกรรมรายเดือน</div><div class="card-subtitle">ปีงบประมาณ <?=e($fy)?> · ใช้วันที่สมัครเป็นวันที่อ้างอิง</div></div></div><div class="mini-chart"><canvas id="activityChart"></canvas></div></div><div class="card"><div class="card-header"><div><div class="card-title">ยอดสมาชิกแยกตามประเภท</div><div class="card-subtitle">นับจำนวนรายการที่จับคู่ประเภทได้ ไม่ใช่จำนวนบุคคลไม่ซ้ำ</div></div></div><div class="mini-chart"><canvas id="categoryChart"></canvas></div></div></div>
<div class="card"><div class="card-header"><div><div class="card-title">รายงานสรุปยอดสมาชิก กิจกรรม/ออกบูธนอกสถานที่</div><div class="card-subtitle">ประจำปีงบประมาณ <?=e($fy)?> · แสดงสูงสุด 500 กลุ่มตามตัวกรอง</div></div><div class="no-print"><button class="btn" type="button" onclick="window.print()">พิมพ์ / บันทึก PDF</button></div></div><div style="overflow:auto"><table class="building-table report-table"><thead><tr><th>ลำดับ</th><th>วันที่</th><th class="event-name">งาน/กิจกรรม</th><th class="place">สถานที่</th><?php foreach($categoryKeys as $label):?><th class="num"><?=e($label)?></th><?php endforeach;?><th class="num">จำนวนรายการ</th><th class="num">จำนวนสมาชิกไม่ซ้ำ</th><th class="num">จำนวนเงิน (ยอดสุทธิ)</th></tr></thead><tbody><?php if(!$visibleActivities):?><tr><td colspan="11" style="text-align:center;padding:24px">ไม่พบข้อมูลกิจกรรมในหมวด booth สำหรับตัวกรองนี้</td></tr><?php else: foreach($visibleActivities as $i=>$r):?><tr><td><?=number_format($i+1)?></td><td class="date"><?=e($r['date'] ? date('d/m/',strtotime($r['date'])).((int)date('Y',strtotime($r['date']))+543) : ($r['date_raw']?:'ไม่ระบุวันที่'))?></td><td><?=e($r['activity'])?></td><td><?=e($r['place'])?></td><?php foreach($categoryKeys as $key=>$label):?><td class="num"><?=number_format($r['categories'][$key])?></td><?php endforeach;?><td class="num"><?=number_format($r['records'])?></td><td class="num"><?=number_format($r['members'])?></td><td class="num"><?=number_format($r['revenue'],2)?></td></tr><?php endforeach; endif;?></tbody><tfoot><tr><td colspan="4">รวมจากกลุ่มที่แสดง</td><?php foreach($categoryKeys as $key=>$label):?><td class="num"><?=number_format(array_sum(array_map(fn($r)=>(int)$r['categories'][$key],$visibleActivities)))?></td><?php endforeach;?><td class="num"><?=number_format(array_sum(array_column($visibleActivities,'records')))?></td><td class="num">—</td><td class="num"><?=number_format(array_sum(array_column($visibleActivities,'revenue')),2)?></td></tr></tfoot></table></div><p class="card-subtitle" style="margin-top:12px">* สมาชิกไม่ซ้ำนับจากหมายเลขสมาชิก หากไม่มีหมายเลขสมาชิกจะนับรายการนั้นแยกเป็นหนึ่งรายการ · ยอดประเภทที่ไม่รู้จักแสดงใน “ยังจัดประเภทไม่ได้”</p></div>
<div class="card"><div class="card-title">รายการที่ใช้คำนวณ</div><p class="card-subtitle">ข้อมูลแหล่งที่มา: Excel ที่นำเข้าเป็นหมวด booth เท่านั้น · รายการที่มีสถานะไม่ว่างและไม่ใช่ “สำเร็จ” จะไม่รวมในยอด · ไม่ได้แก้ไขหรือลบข้อมูลต้นฉบับ</p><p class="card-subtitle">รายการที่ไม่มีวันที่หรือแปลวันที่ไม่ได้ในผลลัพธ์นี้: <?=number_format($unmatchedDates)?> · รายการที่ตัดออกเพราะสถานะไม่ใช่สำเร็จ: <?=number_format($excludedStatus)?></p></div>
</div></main><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>
const monthLabels=<?=json_encode($chartLabels,JSON_UNESCAPED_UNICODE)?>;const monthValues=<?=json_encode($chartValues)?>;const categoryLabels=<?=json_encode(array_values($categoryKeys),JSON_UNESCAPED_UNICODE)?>;const categoryValues=<?=json_encode(array_values($categoryTotals))?>;
if(typeof Chart!=='undefined'){const a=document.getElementById('activityChart');if(a)new Chart(a,{type:'bar',data:{labels:monthLabels,datasets:[{label:'กลุ่มกิจกรรมโดยประมาณ',data:monthValues,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});const c=document.getElementById('categoryChart');if(c)new Chart(c,{type:'bar',data:{labels:categoryLabels,datasets:[{label:'จำนวนรายการ',data:categoryValues,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}});}
</script></body></html>

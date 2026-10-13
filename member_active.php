<?php
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);

function active_parse_date_value($raw): ?DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $patterns = [
        '/^(\d{1,2})\/(\d{1,2})\/(\d{4,5})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/',
        '/^(\d{4,5})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$/',
        '/^(\d{1,2})-(\d{1,2})-(\d{4,5})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/',
    ];
    foreach ($patterns as $idx => $pattern) {
        if (!preg_match($pattern, $raw, $m)) continue;
        if ($idx === 1) { $year=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3]; }
        else { $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3]; }
        $hour=isset($m[4]) && $m[4]!=='' ? (int)$m[4] : 0;
        $minute=isset($m[5]) && $m[5]!=='' ? (int)$m[5] : 0;
        $second=isset($m[6]) && $m[6]!=='' ? (int)$m[6] : 0;
        if ($year > 2400 && $year < 10000) $year -= 543;
        if ($year < 1900 || $year > 2200 || !checkdate($month,$day,$year) || $hour>23 || $minute>59 || $second>59) return null;
        // Active is a calendar-date rule, so time-of-day must not exclude a member on either boundary date.
        return (new DateTimeImmutable())->setDate($year,$month,$day)->setTime(0,0,0);
    }
    return null;
}
function active_display_date($raw): string {
    $date = active_parse_date_value($raw);
    return $date ? $date->format('d/m/Y') : trim((string)$raw);
}

$defaultFY = latest_excel_fiscal_year($pdo);
$fy = (int)($_GET['fy'] ?? $defaultFY);
if ($fy < 2500 || $fy > 2700) $fy = $defaultFY;
$today = new DateTimeImmutable(date('Y-m-d'));
$st = $pdo->prepare("SELECT id,`หมายเลขสมาชิก`,`รายชื่อลูกค้า`,`กลุ่มลูกค้า`,`กิจกรรม/ Package`,`วันที่สมัคร`,`วันหมดอายุ`,`สถานที่_กิจกรรม`,`ประเภทการสมัคร`,`สถานะรายการ` FROM excel_member_records WHERE fiscal_year=? AND `สถานะรายการ`=? ORDER BY id DESC");
$st->execute([$fy,'สำเร็จ']);
$sourceRows = $st->fetchAll(PDO::FETCH_ASSOC);
$activeRows=[]; $expiredRows=[]; $futureRows=[]; $reviewRows=[]; $groupCounts=[]; $typeCounts=['สมัครใหม่'=>0,'ต่ออายุ'=>0]; $uniqueMembers=[];
foreach ($sourceRows as $row) {
    $start=active_parse_date_value($row['วันที่สมัคร'] ?? '');
    $end=active_parse_date_value($row['วันหมดอายุ'] ?? '');
    if (!$start || !$end || $end < $start) {
        $row['_review_reason']=(!$start && !$end) ? 'วันที่สมัครและวันหมดอายุว่าง/ไม่ถูกต้อง' : (!$start ? 'วันที่สมัครว่าง/ไม่ถูกต้อง' : (!$end ? 'วันหมดอายุว่าง/ไม่ถูกต้อง' : 'วันหมดอายุก่อนวันที่สมัคร'));
        $reviewRows[]=$row; continue;
    }
    if ($today < $start) { $futureRows[]=$row; continue; }
    if ($today > $end) { $expiredRows[]=$row; continue; }
    $activeRows[]=$row;
    $memberNo=trim((string)($row['หมายเลขสมาชิก'] ?? ''));
    if ($memberNo!=='') $uniqueMembers[$memberNo]=true;
    $group=trim((string)($row['กลุ่มลูกค้า'] ?? '')) ?: 'ไม่ระบุ';
    $groupCounts[$group]=($groupCounts[$group]??0)+1;
    $type=trim((string)($row['ประเภทการสมัคร'] ?? ''));
    if (isset($typeCounts[$type])) $typeCounts[$type]++;
}
arsort($groupCounts);
$groupLabels=array_keys($groupCounts); $groupValues=array_values($groupCounts);
$activeCount=count($activeRows); $activeUnique=count($uniqueMembers);
$displayActive=array_slice($activeRows,0,100); $displayReview=array_slice($reviewRows,0,100);
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>สมาชิก Active | NSM Member Club</title><link rel="stylesheet" href="assets/css/style.css?v=11"><style>
.active-page .content{display:flex;flex-direction:column;gap:20px}.active-page .card{padding:22px}.active-page .filter-form{display:flex;gap:14px;align-items:end;flex-wrap:wrap}.active-page .filter-label{display:block;margin-bottom:7px}.active-page .status-note{padding:15px 17px;border-radius:12px;line-height:1.75;background:#eff8ff;border:1px solid #cfe6fb;color:#244d70}.active-page .status-note strong{display:block;margin-bottom:3px}.active-page .stats{margin-bottom:0;grid-template-columns:repeat(5,minmax(0,1fr))}.active-page .table-wrap{overflow:auto}.active-page .building-table th{white-space:nowrap}.active-page .building-table td{vertical-align:top}.active-page .subnote{color:#738195;font-size:12px;line-height:1.7;margin-top:5px}.active-page .review-note{padding:12px 14px;background:#fff8e8;border:1px solid #f1dfad;border-radius:10px;color:#755b24;margin-bottom:15px;line-height:1.7}@media(max-width:1100px){.active-page .stats{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:750px){.active-page .card{padding:16px}.active-page .stats{grid-template-columns:1fr 1fr}}
</style></head><body><?php $activePage='member_active.php'; require __DIR__.'/includes/navigation.php'; ?><main class="main active-page"><div class="topbar"><div><h1>สมาชิก Active</h1><p>ตรวจสถานะจากวันที่สมัครและวันหมดอายุ โดยรองรับปี พ.ศ. และ ค.ศ.</p></div></div><div class="content">
<div class="card"><form method="get" class="filter-form"><label><span class="filter-label">ปีงบประมาณ</span><input class="form-control" type="number" name="fy" min="2500" max="2700" value="<?=e($fy)?>" style="width:145px"></label><button class="btn btn-primary" type="submit">แสดงผล</button><span class="subnote">วันที่อ้างอิง: <?=e($today->format('d/m/Y'))?></span></form></div>
<div class="status-note"><strong>กติกา Active ที่ใช้</strong>นับเฉพาะรายการสถานะ “สำเร็จ” ในปีงบประมาณที่เลือก และถือว่า Active เมื่อวันที่สมัคร ≤ วันนี้ ≤ วันหมดอายุ (รวมทั้งสองวัน) · วันที่ว่าง/ผิดรูปแบบหรือช่วงวันที่ไม่สมเหตุผลจะถูกแยกไว้ตรวจสอบ ไม่ถูกนับเป็น Active</div>
<div class="stats"><div class="stat-card"><div><div class="stat-label">รายการ Active</div><div class="stat-number"><?=number_format($activeCount)?></div></div><div class="stat-icon green">🟢</div></div><div class="stat-card"><div><div class="stat-label">สมาชิกไม่ซ้ำ</div><div class="stat-number"><?=number_format($activeUnique)?></div></div><div class="stat-icon blue">👥</div></div><div class="stat-card"><div><div class="stat-label">ยังไม่ถึงวันสมัคร</div><div class="stat-number"><?=number_format(count($futureRows))?></div></div><div class="stat-icon orange">🕒</div></div><div class="stat-card"><div><div class="stat-label">หมดอายุ</div><div class="stat-number"><?=number_format(count($expiredRows))?></div></div><div class="stat-icon orange">⌛</div></div><div class="stat-card"><div><div class="stat-label">ต้องตรวจสอบวันที่</div><div class="stat-number"><?=number_format(count($reviewRows))?></div></div><div class="stat-icon purple">🔎</div></div></div>
<div class="grid"><section class="card"><div class="card-header"><div><div class="card-title">📊 Active แยกตามกลุ่มลูกค้า</div><div class="card-subtitle">นับเฉพาะรายการที่ผ่านกติกา Active</div></div></div><div class="chart-container"><canvas id="activeGroupChart"></canvas></div></section><section class="card"><div class="card-header"><div><div class="card-title">🔄 สมัครใหม่เทียบกับต่ออายุ</div><div class="card-subtitle">รายการ Active ที่ระบุประเภทการสมัคร</div></div></div><div class="chart-container"><canvas id="activeTypeChart"></canvas></div></section></div>
<section class="card"><div class="card-header"><div><div class="card-title">สมาชิก Active (สูงสุด 100 รายการ)</div><div class="card-subtitle">เรียงจากรายการนำเข้าล่าสุด · ยอดด้านบนคำนวณจากข้อมูลทั้งหมดของปีที่เลือก</div></div></div><div class="table-wrap"><table class="building-table"><thead><tr><th>รหัสสมาชิก / ชื่อ</th><th>กลุ่มลูกค้า</th><th>Package</th><th>วันที่สมัคร</th><th>วันหมดอายุ</th><th>ประเภท</th></tr></thead><tbody><?php foreach($displayActive as $r):?><tr><td><?=e($r['หมายเลขสมาชิก'])?><br><?=e($r['รายชื่อลูกค้า'])?></td><td><?=e($r['กลุ่มลูกค้า'])?></td><td><?=e($r['กิจกรรม/ Package'])?></td><td><?=e(active_display_date($r['วันที่สมัคร']))?></td><td><?=e(active_display_date($r['วันหมดอายุ']))?></td><td><?=e($r['ประเภทการสมัคร'])?></td></tr><?php endforeach;?><?php if(!$displayActive):?><tr><td colspan="6" style="text-align:center;padding:24px">ไม่พบรายการ Active ตามเงื่อนไขในปีงบประมาณนี้</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="card-header"><div><div class="card-title">รายการที่ต้องตรวจสอบวันที่</div><div class="card-subtitle">ข้อมูลที่ไม่สามารถยืนยันสถานะได้จะไม่ถูกนับเป็น Active</div></div></div><div class="review-note">มี <?=number_format(count($reviewRows))?> รายการที่ต้องตรวจสอบ<?php if(count($reviewRows)>100):?> · แสดง 100 รายการแรก<?php endif;?> กรุณาแก้ไขข้อมูลต้นทาง Excel แล้วนำเข้าใหม่ตามขั้นตอนของระบบ</div><div class="table-wrap"><table class="building-table"><thead><tr><th>รหัสสมาชิก / ชื่อ</th><th>วันที่สมัคร</th><th>วันหมดอายุ</th><th>เหตุผล</th></tr></thead><tbody><?php foreach($displayReview as $r):?><tr><td><?=e($r['หมายเลขสมาชิก'])?><br><?=e($r['รายชื่อลูกค้า'])?></td><td><?=e($r['วันที่สมัคร'])?></td><td><?=e($r['วันหมดอายุ'])?></td><td><?=e($r['_review_reason'])?></td></tr><?php endforeach;?><?php if(!$displayReview):?><tr><td colspan="4" style="text-align:center;padding:24px">ไม่มีรายการที่ต้องตรวจสอบวันที่</td></tr><?php endif;?></tbody></table></div></section>
</div></main><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>
const groupLabels=<?=json_encode($groupLabels,JSON_UNESCAPED_UNICODE)?>,groupCounts=<?=json_encode($groupValues)?>;const g=document.getElementById('activeGroupChart');if(g&&typeof Chart!=='undefined')new Chart(g,{type:'bar',data:{labels:groupLabels,datasets:[{label:'รายการ Active',data:groupCounts,borderWidth:1}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}});
const t=document.getElementById('activeTypeChart');if(t&&typeof Chart!=='undefined')new Chart(t,{type:'doughnut',data:{labels:['สมัครใหม่','ต่ออายุ'],datasets:[{data:[<?= (int)$typeCounts['สมัครใหม่']?>,<?= (int)$typeCounts['ต่ออายุ']?>],borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
</script></body></html>

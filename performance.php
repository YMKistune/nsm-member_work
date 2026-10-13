<?php
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/config/database.php';

function e($value){ return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($value){ return number_format((float)$value, 2); }
function fyFromDate($date){
    $ts = strtotime($date);
    $year = (int)date('Y', $ts) + 543;
    $month = (int)date('n', $ts);
    return $month >= 10 ? $year + 1 : $year;
}
function fiscalMonthFromDate($date){
    $month = (int)date('n', strtotime($date));
    return $month >= 10 ? $month - 9 : $month + 3;
}

$defaultFY = (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);
$fy = isset($_GET['fy']) ? (int)$_GET['fy'] : $defaultFY;
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$buildingId = isset($_GET['building_id']) ? (int)$_GET['building_id'] : 0;
$positionId = isset($_GET['position_id']) ? (int)$_GET['position_id'] : 0;

$monthNames = [1=>'ต.ค.',2=>'พ.ย.',3=>'ธ.ค.',4=>'ม.ค.',5=>'ก.พ.',6=>'มี.ค.',7=>'เม.ย.',8=>'พ.ค.',9=>'มิ.ย.',10=>'ก.ค.',11=>'ส.ค.',12=>'ก.ย.'];

$buildings = $pdo->query("SELECT id,name FROM buildings WHERE is_active=1 ORDER BY name")->fetchAll();
$positions = $pdo->query("SELECT id,name FROM positions WHERE is_active=1 ORDER BY name")->fetchAll();

$years = $pdo->query("SELECT DISTINCT YEAR(work_date) AS calendar_year FROM employee_work_records ORDER BY calendar_year DESC")->fetchAll();
$fyOptions = [];
foreach($years as $r){
    $calendar = (int)$r['calendar_year'];
    $fyOptions[$calendar + 543 + 1] = true;
    $fyOptions[$calendar + 543] = true;
}
$fyOptions[$defaultFY] = true;
$fyOptions[$defaultFY-1] = true;
$fyOptions[$defaultFY-2] = true;
krsort($fyOptions);

$where = [];
$params = [];
$startCalendar = $fy - 544;
$endCalendar = $startCalendar + 1;
$where[] = 'work_date >= ? AND work_date < ?';
$params[] = sprintf('%04d-10-01', $startCalendar);
$params[] = sprintf('%04d-10-01', $endCalendar);
if($month >= 1 && $month <= 12){
    $calendarMonth = $month <= 3 ? $month + 9 : $month - 3;
    $calendarYear = $month <= 3 ? $endCalendar : $startCalendar;
    $where = ['work_date >= ? AND work_date < ?'];
    $params = [sprintf('%04d-%02d-01',$calendarYear,$calendarMonth), date('Y-m-d', strtotime(sprintf('%04d-%02d-01',$calendarYear,$calendarMonth).' +1 month'))];
}
if($buildingId > 0){ $where[] = 'building_id = ?'; $params[] = $buildingId; }
if($positionId > 0){ $where[] = 'position_id = ?'; $params[] = $positionId; }

$sql = "SELECT employee_id, employee_name_snapshot AS employee_name,
               GROUP_CONCAT(DISTINCT position_snapshot ORDER BY position_snapshot SEPARATOR ', ') AS positions,
               GROUP_CONCAT(DISTINCT building_snapshot ORDER BY building_snapshot SEPARATOR ', ') AS buildings,
               COUNT(*) AS work_count,
               COALESCE(SUM(member_notifications),0) AS members,
               COALESCE(SUM(daily_sales),0) AS sales
        FROM employee_work_records
        WHERE ".implode(' AND ',$where)."
        GROUP BY employee_id, employee_name_snapshot
        ORDER BY sales DESC, work_count DESC, members DESC, employee_name ASC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();

$totalEmployees = count($rows);
$totalWork = 0; $totalMembers = 0; $totalSales = 0;
foreach($rows as $r){ $totalWork += (int)$r['work_count']; $totalMembers += (int)$r['members']; $totalSales += (float)$r['sales']; }

$byWork = $rows; usort($byWork, fn($a,$b)=>((int)$b['work_count']<=> (int)$a['work_count']) ?: ((float)$b['sales']<=> (float)$a['sales']));
$byMembers = $rows; usort($byMembers, fn($a,$b)=>((int)$b['members']<=> (int)$a['members']) ?: ((float)$b['sales']<=> (float)$a['sales']));

$monthly = [];
for($m=1;$m<=12;$m++) $monthly[$m] = ['label'=>$monthNames[$m], 'sales'=>0, 'work'=>0, 'members'=>0];
$monthWhere = 'work_date >= ? AND work_date < ?';
$monthParams = [sprintf('%04d-10-01',$startCalendar), sprintf('%04d-10-01',$endCalendar)];
if($buildingId > 0){ $monthWhere .= ' AND building_id = ?'; $monthParams[] = $buildingId; }
if($positionId > 0){ $monthWhere .= ' AND position_id = ?'; $monthParams[] = $positionId; }
$stmt = $pdo->prepare("SELECT work_date, daily_sales, member_notifications FROM employee_work_records WHERE $monthWhere");
$stmt->execute($monthParams);
foreach($stmt->fetchAll() as $r){
    $m = fiscalMonthFromDate($r['work_date']);
    $monthly[$m]['sales'] += (float)$r['daily_sales'];
    $monthly[$m]['members'] += (int)$r['member_notifications'];
    $monthly[$m]['work']++;
}
$chartLabels = json_encode(array_column($monthly,'label'), JSON_UNESCAPED_UNICODE);
$chartSales = json_encode(array_column($monthly,'sales'));
$chartWork = json_encode(array_column($monthly,'work'));
$chartMembers = json_encode(array_column($monthly,'members'));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ผลงาน / KPI - NSM Member Club</title>
<link rel="stylesheet" href="assets/css/style.css?v=10">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'performance.php'); require __DIR__ . '/includes/navigation.php'; ?>
<main class="main">
<header class="topbar"><div><h1>ผลงาน / KPI</h1><p>สรุปและจัดอันดับผลงานพนักงานขายตามช่วงเวลาที่เลือก</p></div><div class="top-actions"></div></header>
<div class="content">
  <div class="filter-bar">
    <span class="filter-label">ปีงบประมาณ</span>
    <select id="fy" name="fy" form="performanceFilter">
      <?php foreach(array_keys($fyOptions) as $y): ?><option value="<?=e($y)?>" <?=$fy===$y?'selected':''?>><?=e($y)?></option><?php endforeach; ?>
    </select>
    <span class="filter-label">เดือน</span>
    <select id="month" name="month" form="performanceFilter"><option value="0">ทั้งปี</option><?php foreach($monthNames as $m=>$name): ?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=$name?></option><?php endforeach; ?></select>
    <span class="filter-label">ตำแหน่งงาน</span>
    <select id="position_id" name="position_id" form="performanceFilter"><option value="0">ทุกตำแหน่ง</option><?php foreach($positions as $p): ?><option value="<?=$p['id']?>" <?=$positionId===(int)$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach; ?></select>
    <span class="filter-label">อาคาร</span>
    <select id="building_id" name="building_id" form="performanceFilter"><option value="0">ทุกอาคาร</option><?php foreach($buildings as $b): ?><option value="<?=$b['id']?>" <?=$buildingId===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach; ?></select>
    <form id="performanceFilter" method="get"><button class="icon-button" style="border-radius:8px;width:auto;padding:0 16px" type="submit">ดูผลงาน</button></form>
  </div>

  <div class="stats">
    <div class="stat-card"><div><div class="stat-label">พนักงานที่มีผลงาน</div><div class="stat-number"><?=number_format($totalEmployees)?></div></div><div class="stat-icon blue">👥</div></div>
    <div class="stat-card"><div><div class="stat-label">จำนวนครั้งปฏิบัติงาน</div><div class="stat-number"><?=number_format($totalWork)?></div></div><div class="stat-icon green">📅</div></div>
    <div class="stat-card"><div><div class="stat-label">สมาชิก / ข่าวสาร</div><div class="stat-number"><?=number_format($totalMembers)?></div></div><div class="stat-icon orange">📣</div></div>
    <div class="stat-card"><div><div class="stat-label">ยอดขายรวม</div><div class="stat-number">฿<?=money($totalSales)?></div></div><div class="stat-icon purple">💰</div></div>
  </div>

  <div class="grid">
    <div class="card"><div class="card-header"><div><div class="card-title">📈 แนวโน้มผลงาน</div><div class="card-subtitle">สรุปยอดขาย จำนวนครั้ง และจำนวนสมาชิกตามเดือน</div></div><span class="badge">KPI</span></div><div class="chart-container"><canvas id="performanceChart"></canvas></div></div>
    <div class="card"><div class="card-header"><div><div class="card-title">🏆 อันดับยอดขาย</div><div class="card-subtitle">เรียงจากยอดขายสูงสุด</div></div></div>
      <?php foreach(array_slice($rows,0,5) as $i=>$r): ?><div class="employee"><div class="employee-info"><div class="employee-avatar"><?=($i+1)?></div><div><div class="employee-name"><?=e($r['employee_name'])?></div><div class="employee-position"><?=e($r['positions'] ?: '-')?> · ครั้ง <?=number_format($r['work_count'])?> · สมาชิก <?=number_format($r['members'])?></div></div></div><div class="employee-value"><strong>฿<?=money($r['sales'])?></strong><span>ยอดขาย</span></div></div><?php endforeach; ?>
      <?php if(!$rows): ?><div class="footer">ยังไม่มีข้อมูลในช่วงที่เลือก</div><?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-bottom:20px"><div class="card-header"><div><div class="card-title">📊 เปรียบเทียบยอดขายพนักงาน 10 อันดับแรก</div><div class="card-subtitle">ใช้ตัวกรองปีงบประมาณ เดือน อาคาร และตำแหน่งเดียวกับรายงานนี้</div></div></div><div class="chart-container"><canvas id="employeeSalesRankChart"></canvas></div></div>

  <div class="grid">
    <div class="card"><div class="card-header"><div><div class="card-title">🥇 อันดับผลงานพนักงาน</div><div class="card-subtitle">เรียงตามยอดขาย → จำนวนครั้ง → จำนวนสมาชิก</div></div></div>
      <div style="overflow:auto"><table class="building-table"><thead><tr><th>อันดับ</th><th>พนักงาน</th><th>ตำแหน่งงาน</th><th>อาคาร</th><th>จำนวนครั้ง</th><th>สมาชิก</th><th class="right">ยอดขาย</th></tr></thead><tbody>
      <?php foreach($rows as $i=>$r): ?><tr><td><strong><?=($i+1)?></strong></td><td><?=e($r['employee_name'])?></td><td><?=e($r['positions'] ?: '-')?></td><td><?=e($r['buildings'] ?: '-')?></td><td><?=number_format($r['work_count'])?></td><td><?=number_format($r['members'])?></td><td class="right">฿<?=money($r['sales'])?></td></tr><?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="7" style="text-align:center">ยังไม่มีข้อมูลในช่วงที่เลือก</td></tr><?php endif; ?></tbody></table></div>
    </div>
    <div class="card"><div class="card-header"><div><div class="card-title">🏃 จำนวนครั้ง</div><div class="card-subtitle">พนักงานที่มีจำนวนครั้งปฏิบัติงานสูงสุด</div></div></div>
      <?php foreach(array_slice($byWork,0,5) as $i=>$r): ?><div class="employee"><div class="employee-info"><div class="employee-avatar"><?=($i+1)?></div><div><div class="employee-name"><?=e($r['employee_name'])?></div><div class="employee-position">ยอดขาย ฿<?=money($r['sales'])?></div></div></div><div class="employee-value"><strong><?=number_format($r['work_count'])?></strong><span>ครั้ง</span></div></div><?php endforeach; ?>
    </div>
  </div>

  <div class="card"><div class="card-header"><div><div class="card-title">👥 อันดับจำนวนสมาชิก</div><div class="card-subtitle">พนักงานที่มีจำนวนสมาชิก / ข่าวสารสูงสุด</div></div></div>
    <div style="overflow:auto"><table class="building-table"><thead><tr><th>อันดับ</th><th>พนักงาน</th><th>ตำแหน่งงาน</th><th>อาคาร</th><th>สมาชิก / ข่าวสาร</th><th>จำนวนครั้ง</th><th class="right">ยอดขาย</th></tr></thead><tbody>
    <?php foreach(array_slice($byMembers,0,10) as $i=>$r): ?><tr><td><strong><?=($i+1)?></strong></td><td><?=e($r['employee_name'])?></td><td><?=e($r['positions'] ?: '-')?></td><td><?=e($r['buildings'] ?: '-')?></td><td><?=number_format($r['members'])?></td><td><?=number_format($r['work_count'])?></td><td class="right">฿<?=money($r['sales'])?></td></tr><?php endforeach; ?>
    <?php if(!$rows): ?><tr><td colspan="6" style="text-align:center">ยังไม่มีข้อมูลในช่วงที่เลือก</td></tr><?php endif; ?></tbody></table></div>
  </div>
</div>
</main>
<script>
const labels=<?=$chartLabels?>, sales=<?=$chartSales?>, work=<?=$chartWork?>, members=<?=$chartMembers?>;
const ctx=document.getElementById('performanceChart');
if(ctx && typeof Chart!=='undefined') new Chart(ctx.getContext('2d'),{type:'line',data:{labels,datasets:[{label:'ยอดขาย',data:sales,yAxisID:'y',borderWidth:3,tension:.35},{label:'จำนวนครั้ง',data:work,yAxisID:'y1',borderWidth:2,tension:.35},{label:'จำนวนสมาชิก',data:members,yAxisID:'y1',borderWidth:2,tension:.35}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},scales:{y:{beginAtZero:true,position:'left',ticks:{callback:v=>'฿'+Number(v).toLocaleString('th-TH')}},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}},plugins:{legend:{position:'bottom'}}}});
const rankCanvas=document.getElementById('employeeSalesRankChart');
const rankRows=<?=json_encode(array_slice($rows,0,10), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?>;
if(rankCanvas && typeof Chart!=='undefined') new Chart(rankCanvas,{type:'bar',data:{labels:rankRows.map(r=>r.employee_name||'ไม่ระบุชื่อ'),datasets:[{label:'ยอดขาย (บาท)',data:rankRows.map(r=>Number(r.sales)||0),borderWidth:1}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'฿'+Number(c.raw||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})}}},scales:{x:{beginAtZero:true,ticks:{callback:v=>'฿'+Number(v).toLocaleString('th-TH')}}}}});
</script>
</body></html>
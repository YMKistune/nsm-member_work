<?php
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);

$labels=['ap'=>'สมัครที่ อพ.','online'=>'สมัครออนไลน์','booth'=>'ออกบูธ','unknown'=>'ยังไม่จัดหมวด'];
$section = $section ?? 'unknown';
$title = $title ?? ($labels[$section] ?? 'ข้อมูลจาก Excel');
$currentFY = ((int)date('n') >= 10) ? (int)date('Y') + 544 : (int)date('Y') + 543;
$latestFY = (int)$pdo->query('SELECT COALESCE(MAX(fiscal_year),0) FROM excel_member_records WHERE fiscal_year IS NOT NULL')->fetchColumn();
$defaultFY = $latestFY >= 2500 ? $latestFY : $currentFY;
$fy=(int)($_GET['fy']??$defaultFY); if($fy<2500||$fy>2700)$fy=$defaultFY; $month=(int)($_GET['month']??0); $type=trim($_GET['type']??'');
$where=['source_section=?','fiscal_year=?'];$params=[$section,$fy];
$dynamicFields = $pdo->query("SELECT id, header_name, section_scope, display_in_table, use_in_filter FROM excel_dynamic_columns WHERE status='active' ORDER BY header_name")->fetchAll(PDO::FETCH_ASSOC);
$visibleDynamicFields = array_values(array_filter($dynamicFields, fn($f) => (int)$f['display_in_table'] === 1 && in_array($f['section_scope'], ['all',$section], true)));
$filterDynamicFields = array_values(array_filter($dynamicFields, fn($f) => (int)$f['use_in_filter'] === 1 && in_array($f['section_scope'], ['all',$section], true)));
$dynamicFilterValues = [];

if($month>=1&&$month<=12){$where[]="COALESCE(MONTH(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`วันที่สมัคร`),' ',1),'%d/%m/%Y')),MONTH(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`วันที่สมัคร`),' ',1),'%Y-%m-%d')))=?";$params[]=$month;}
if($type!==''&&in_array($type,['สมัครใหม่','ต่ออายุ'],true)){$where[]='`ประเภทการสมัคร`=?';$params[]=$type;}
foreach($filterDynamicFields as $field){
  $key='df_'.(int)$field['id'];
  $value=trim((string)($_GET[$key]??''));
  if($value==='') continue;
  $path='$.' . json_encode((string)$field['header_name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $where[]='JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(extra_data) THEN extra_data ELSE JSON_OBJECT() END, ?)) LIKE ?';
  $params[]=$path; $params[]='%'.$value.'%';
  $dynamicFilterValues[$key]=$value;
}
$sql='SELECT COUNT(*) c, COALESCE(SUM(CAST(NULLIF(REPLACE(`ยอดสุทธิ`,",","") ,"") AS DECIMAL(14,2))),0) revenue, SUM(`ประเภทการสมัคร`="สมัครใหม่") new_count, SUM(`ประเภทการสมัคร`="ต่ออายุ") renew_count FROM excel_member_records WHERE '.implode(' AND ',$where);
$st=$pdo->prepare($sql);$st->execute($params);$sum=$st->fetch();
$listSql='SELECT `วันที่สมัคร`,`วันหมดอายุ`,`หมายเลขสมาชิก`,`รายชื่อลูกค้า`,`กิจกรรม/ Package`,`ประเภทการสมัคร`,`ช่องทางจำหน่าย`,`สถานที่_กิจกรรม`,`ยอดสุทธิ`,`หมายเหตุ`,extra_data FROM excel_member_records WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 100';$st=$pdo->prepare($listSql);$st->execute($params);$list=$st->fetchAll();
$packageSql="SELECT COALESCE(NULLIF(`กิจกรรม/ Package`,''),'ไม่ระบุ Package') package_name, COUNT(*) total FROM excel_member_records WHERE ".implode(' AND ',$where)." GROUP BY package_name ORDER BY total DESC LIMIT 8";$st=$pdo->prepare($packageSql);$st->execute($params);$packageRows=$st->fetchAll(PDO::FETCH_ASSOC);
$packageLabels=array_column($packageRows,'package_name');$packageCounts=array_map(fn($r)=>(int)$r['total'],$packageRows);

// กราฟประจำหมวด: จำนวนสมัครใหม่/ต่ออายุ และยอดสุทธิรายเดือน ตามปีงบประมาณ
$monthOptions=[10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม',1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน'];
$orderedMonths=[10,11,12,1,2,3,4,5,6,7,8,9];
$monthLabels=['ตุลาคม','พฤศจิกายน','ธันวาคม','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน'];
$monthlyNew=array_fill(0,12,0); $monthlyRenew=array_fill(0,12,0); $monthlyRevenue=array_fill(0,12,0);
if($fy>=2500&&$fy<=2700){
  $chartSql="SELECT COALESCE(MONTH(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`วันที่สมัคร`),' ',1),'%d/%m/%Y')),MONTH(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`วันที่สมัคร`),' ',1),'%Y-%m-%d'))) m,
    SUM(`ประเภทการสมัคร`='สมัครใหม่') new_count,
    SUM(`ประเภทการสมัคร`='ต่ออายุ') renew_count,
    COALESCE(SUM(CAST(NULLIF(REPLACE(`ยอดสุทธิ`,',',''),'') AS DECIMAL(14,2))),0) revenue
    FROM excel_member_records WHERE source_section=? AND fiscal_year=? GROUP BY m";
  $cs=$pdo->prepare($chartSql); $cs->execute([$section,$fy]);
  $cm=[]; foreach($cs->fetchAll(PDO::FETCH_ASSOC) as $r) $cm[(int)$r['m']]=$r;
  foreach($orderedMonths as $i=>$m){ $r=$cm[$m]??[]; $monthlyNew[$i]=(int)($r['new_count']??0); $monthlyRenew[$i]=(int)($r['renew_count']??0); $monthlyRevenue[$i]=(float)($r['revenue']??0); }
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?></title><link rel="stylesheet" href="assets/css/style.css?v=10"></head><body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'section_data_page.php'); require __DIR__ . '/includes/navigation.php'; ?>
<main class="main"><div class="topbar"><div><h1><?=e($title)?></h1><p>ข้อมูลที่ระบบแยกจาก Excel อัตโนมัติ · ไม่ต้องกรอกข้อมูลซ้ำ</p></div></div><div class="content">
<div class="card"><form method="get"><div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end"><div><div class="filter-label">ปีงบประมาณ</div><input class="form-control" type="number" name="fy" value="<?=e($fy)?>" style="width:130px"></div><div><div class="filter-label">เดือน</div><select class="form-control" name="month"><option value="0">ทั้งหมด</option><?php foreach($monthOptions as $m=>$monthLabel):?><option value="<?=$m?>" <?=$month===$m?'selected':''?>><?=e($monthLabel)?></option><?php endforeach;?></select></div><div><div class="filter-label">ประเภทการสมัคร</div><select class="form-control" name="type"><option value="">ทั้งหมด</option><option <?=$type==='สมัครใหม่'?'selected':''?>>สมัครใหม่</option><option <?=$type==='ต่ออายุ'?'selected':''?>>ต่ออายุ</option></select></div><?php foreach($filterDynamicFields as $field): $filterKey='df_'.(int)$field['id']; ?><div><div class="filter-label"><?=e($field['header_name'])?></div><input class="form-control" type="text" name="<?=e($filterKey)?>" value="<?=e($dynamicFilterValues[$filterKey]??'')?>" placeholder="ค้นหา..." style="min-width:150px"></div><?php endforeach; ?><button class="btn btn-primary" type="submit">กรองข้อมูล</button></div></form></div>
<div class="grid" style="margin-bottom:20px"><div class="card"><div class="card-header"><div><div class="card-title">📈 สมัครใหม่ / ต่ออายุรายเดือน</div><div class="card-subtitle">เฉพาะข้อมูลหมวด <?=e($title)?> ปีงบประมาณ <?=e($fy)?></div></div></div><div class="chart-container"><canvas id="memberTypeChart"></canvas></div></div><div class="card"><div class="card-header"><div><div class="card-title">💰 ยอดสุทธิรายเดือน</div><div class="card-subtitle">เฉพาะข้อมูลหมวด <?=e($title)?></div></div></div><div class="chart-container"><canvas id="monthlyRevenueChart"></canvas></div></div></div>
<div class="card" style="margin-bottom:20px"><div class="card-header"><div><div class="card-title">📦 Package / กิจกรรมที่พบมากที่สุด</div><div class="card-subtitle">สูงสุด 8 รายการ · ใช้ตัวกรองเดียวกับตารางข้อมูลด้านล่าง</div></div></div><div class="chart-container"><canvas id="sectionPackageChart"></canvas></div></div>
<div class="stats"><div class="stat-card"><div><div class="stat-label">รายการทั้งหมด</div><div class="stat-number"><?=number_format((int)$sum['c'])?></div></div><div class="stat-icon blue">📄</div></div><div class="stat-card"><div><div class="stat-label">สมัครใหม่</div><div class="stat-number"><?=number_format((int)$sum['new_count'])?></div></div><div class="stat-icon green">🆕</div></div><div class="stat-card"><div><div class="stat-label">ต่ออายุ</div><div class="stat-number"><?=number_format((int)$sum['renew_count'])?></div></div><div class="stat-icon orange">🔄</div></div><div class="stat-card"><div><div class="stat-label">ยอดสุทธิ</div><div class="stat-number">฿<?=number_format((float)$sum['revenue'],2)?></div></div><div class="stat-icon purple">💰</div></div></div>
<div class="card"><div class="card-header"><div><div class="card-title"><?=e($title)?> — ข้อมูลจาก Excel</div><div class="card-subtitle">ข้อมูลถูกแยกจากไฟล์ต้นทางแล้ว ไม่ใช่แบบฟอร์มกรอกข้อมูล</div></div></div><div style="overflow:auto"><table class="building-table"><thead><tr><th>วันที่สมัคร</th><th>สมาชิก</th><th>Package</th><th>ประเภท</th><th>ช่องทาง</th><th>สถานที่</th><th class="right">ยอดสุทธิ</th><th>หมายเหตุ</th><?php foreach($visibleDynamicFields as $field): ?><th><?=e($field['header_name'])?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($list as $r): $extraData=json_decode((string)($r['extra_data']??''),true); if(!is_array($extraData)) $extraData=[]; ?><tr><td><?=e($r['วันที่สมัคร'])?></td><td><?=e($r['หมายเลขสมาชิก'])?><br><?=e($r['รายชื่อลูกค้า'])?></td><td><?=e($r['กิจกรรม/ Package'])?></td><td><?=e($r['ประเภทการสมัคร'])?></td><td><?=e($r['ช่องทางจำหน่าย'])?></td><td><?=e($r['สถานที่_กิจกรรม'])?></td><td class="right">฿<?=number_format((float)str_replace(',','',$r['ยอดสุทธิ']),2)?></td><td><?=e($r['หมายเหตุ'])?></td><?php foreach($visibleDynamicFields as $field): ?><td><?=e($extraData[$field['header_name']]??'')?></td><?php endforeach; ?></tr><?php endforeach;?></tbody></table></div></div>
</div></main>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const monthLabels=<?=json_encode($monthLabels,JSON_UNESCAPED_UNICODE)?>;
const newData=<?=json_encode($monthlyNew)?>, renewData=<?=json_encode($monthlyRenew)?>, revenueData=<?=json_encode($monthlyRevenue)?>;
const typeCanvas=document.getElementById('memberTypeChart');
if(typeCanvas&&typeof Chart!=='undefined') new Chart(typeCanvas,{type:'bar',data:{labels:monthLabels,datasets:[{label:'สมัครใหม่',data:newData,borderWidth:1},{label:'ต่ออายุ',data:renewData,borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
const revCanvas=document.getElementById('monthlyRevenueChart');
if(revCanvas&&typeof Chart!=='undefined') new Chart(revCanvas,{type:'line',data:{labels:monthLabels,datasets:[{label:'ยอดสุทธิ',data:revenueData,borderWidth:2,tension:.3,fill:false}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>'฿'+Number(c.raw||0).toLocaleString('th-TH')}}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'฿'+Number(v).toLocaleString('th-TH')}}}}});
const packageCanvas=document.getElementById('sectionPackageChart');const packageLabels=<?=json_encode($packageLabels,JSON_UNESCAPED_UNICODE)?>, packageCounts=<?=json_encode($packageCounts)?>;if(packageCanvas&&typeof Chart!=='undefined')new Chart(packageCanvas,{type:'bar',data:{labels:packageLabels,datasets:[{label:'จำนวนรายการ',data:packageCounts,borderWidth:1}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}}}}});
</script></body></html>

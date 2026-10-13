<?php
require_once __DIR__ . '/includes_excel_data.php';
ensureExcelTables($pdo);

// Main dashboard uses imported Excel records for membership figures.
// Employee performance remains a separate block and is never added to member totals.
$currentFY = ((int)date('n') >= 10) ? (int)date('Y') + 544 : (int)date('Y') + 543;
$latestDataFY = (int)$pdo->query('SELECT COALESCE(MAX(fiscal_year),0) FROM excel_member_records WHERE fiscal_year IS NOT NULL')->fetchColumn();
$defaultFY = $latestDataFY >= 2500 ? $latestDataFY : $currentFY;
$yearRangeStart = min(2700, max(2502, $defaultFY + 2));
$yearRangeEnd = max(2500, $defaultFY - 10);
$yearOptions = array_values(array_unique(array_merge(range(2575, 2565), range($yearRangeStart, $yearRangeEnd, -1), array_map('intval', $pdo->query('SELECT DISTINCT fiscal_year FROM excel_member_records WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC')->fetchAll(PDO::FETCH_COLUMN)))));
rsort($yearOptions, SORT_NUMERIC);
$fy1 = (int)($_GET['fy1'] ?? $_GET['fy'] ?? $defaultFY);
$fy2 = (int)($_GET['fy2'] ?? ($fy1 - 1));
$fy3 = (int)($_GET['fy3'] ?? ($fy1 - 2));
foreach (['fy1','fy2','fy3'] as $yearKey) {
    if (!in_array($$yearKey, $yearOptions, true)) $$yearKey = $defaultFY;
}
$fy = $fy1;
$startCalendar = $fy - 544;
$endCalendar = $startCalendar + 1;
$fyStart = sprintf('%04d-10-01', $startCalendar);
$fyEnd = sprintf('%04d-10-01', $endCalendar);
$years = [$fy1, $fy2, $fy3];
$monthOrder = [10,11,12,1,2,3,4,5,6,7,8,9];
$monthLabels = ['ต.ค.','พ.ย.','ธ.ค.','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.'];
$monthNameByNumber = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
$dateMonthExpr = "COALESCE(MONTH(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`วันที่สมัคร`),' ',1),'%d/%m/%Y')),MONTH(STR_TO_DATE(SUBSTRING_INDEX(TRIM(`วันที่สมัคร`),' ',1),'%Y-%m-%d')))";
$moneyExpr = "CAST(NULLIF(REPLACE(REPLACE(TRIM(COALESCE(`ยอดสุทธิ`,'')),',',''),'฿',''),'') AS DECIMAL(14,2))";
$sumStmt = $pdo->prepare("SELECT COUNT(*) total_records,
  SUM(`ประเภทการสมัคร`='สมัครใหม่') new_count,
  SUM(`ประเภทการสมัคร`='ต่ออายุ') renew_count,
  COALESCE(SUM($moneyExpr),0) net_revenue
  FROM excel_member_records WHERE fiscal_year=?");
$sumStmt->execute([$fy]);
$currentSummary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$members = (int)($currentSummary['total_records'] ?? 0);
$newCount = (int)($currentSummary['new_count'] ?? 0);
$renewCount = (int)($currentSummary['renew_count'] ?? 0);
$revenue = (float)($currentSummary['net_revenue'] ?? 0);
$sectionCounts = ['ap'=>0,'online'=>0,'booth'=>0,'unknown'=>0];
$sectionRevenue = ['ap'=>0.0,'online'=>0.0,'booth'=>0.0,'unknown'=>0.0];
$sectionStmt = $pdo->prepare("SELECT source_section, COUNT(*) record_count, COALESCE(SUM($moneyExpr),0) net_revenue FROM excel_member_records WHERE fiscal_year=? GROUP BY source_section");
$sectionStmt->execute([$fy]);
foreach ($sectionStmt->fetchAll(PDO::FETCH_ASSOC) as $sectionRow) {
    $key = (string)$sectionRow['source_section'];
    if (array_key_exists($key, $sectionCounts)) {
        $sectionCounts[$key] = (int)$sectionRow['record_count'];
        $sectionRevenue[$key] = (float)$sectionRow['net_revenue'];
    }
}
$members = array_sum($sectionCounts);
$revenue = array_sum($sectionRevenue);
$opMembers = $sectionCounts['ap']; $onlineMembers = $sectionCounts['online'];
$boothMembers = $sectionCounts['booth']; $unknownMembers = $sectionCounts['unknown'];
$opRevenue = $sectionRevenue['ap']; $onlineRevenue = $sectionRevenue['online'];
$boothRevenue = $sectionRevenue['booth']; $unknownRevenue = $sectionRevenue['unknown'];
$targetStmt = $pdo->prepare("SELECT COALESCE(MAX(rt.annual_target),0) FROM revenue_targets rt INNER JOIN projects p ON p.id=rt.project_id WHERE p.name='NSM Member Club' AND rt.fiscal_year=?");
$targetStmt->execute([$fy]);
$target = (float)$targetStmt->fetchColumn();
$monthly_target = $target / 12;
$achievement = $target > 0 ? ($revenue / $target) * 100 : 0;
$remaining = max(0, $target - $revenue);

$actual = array_fill(0,12,0.0); $onlineActual = array_fill(0,12,0.0); $boothActual = array_fill(0,12,0.0); $unknownActual = array_fill(0,12,0.0);
$monthlySql = "SELECT $dateMonthExpr month_no, source_section, COALESCE(SUM($moneyExpr),0) revenue FROM excel_member_records WHERE fiscal_year=? GROUP BY month_no, source_section";
$st = $pdo->prepare($monthlySql); $st->execute([$fy]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $monthNo = (int)($row['month_no'] ?? 0); $idx = array_search($monthNo, $monthOrder, true);
    if ($idx === false) continue;
    if ($row['source_section']==='ap') $actual[$idx]=(float)$row['revenue'];
    elseif ($row['source_section']==='online') $onlineActual[$idx]=(float)$row['revenue'];
    elseif ($row['source_section']==='booth') $boothActual[$idx]=(float)$row['revenue'];
    else $unknownActual[$idx]=(float)$row['revenue'];
}
$targetChart = array_fill(0,12,$monthly_target);
$compareRevenue = [];
$comparisonMap = [];
$uniqueYears = array_values(array_unique($years));
$in = implode(',', array_fill(0,count($uniqueYears),'?'));
$comparisonSql = "SELECT fiscal_year, $dateMonthExpr month_no, COUNT(*) record_count, COALESCE(SUM($moneyExpr),0) revenue FROM excel_member_records WHERE fiscal_year IN ($in) GROUP BY fiscal_year, month_no";
$cmp = $pdo->prepare($comparisonSql); $cmp->execute($uniqueYears);
foreach ($cmp->fetchAll(PDO::FETCH_ASSOC) as $row) $comparisonMap[(int)$row['fiscal_year']][(int)($row['month_no']??0)] = ['count'=>(int)$row['record_count'],'revenue'=>(float)$row['revenue']];
$comparisonTable = [];
foreach ($monthOrder as $i=>$monthNo) {
    $row = ['month'=>$monthNameByNumber[$monthNo]];
    foreach ($years as $j=>$year) {
        $item = $comparisonMap[$year][$monthNo] ?? ['count'=>0,'revenue'=>0];
        $row['c'.$j] = $item['count']; $row['r'.$j] = $item['revenue'];
        $compareRevenue[$j][$i] = $item['revenue'];
    }
    $comparisonTable[] = $row;
}

// Employee-only indicators, deliberately not combined with membership counts or revenue.
$employees = [];
$stmt = $pdo->prepare("SELECT e.id, e.name,
  COALESCE((SELECT p.name FROM employee_work_records wr LEFT JOIN positions p ON p.id=wr.position_id WHERE wr.employee_id=e.id ORDER BY wr.work_date DESC, wr.id DESC LIMIT 1),'-') AS position_name,
  COALESCE(SUM(w.daily_sales),0) AS revenue, COUNT(w.id) AS work_count, COALESCE(SUM(w.member_notifications),0) AS notifications
  FROM employees e LEFT JOIN employee_work_records w ON w.employee_id=e.id AND w.work_date>=? AND w.work_date<?
  WHERE e.is_active=1 GROUP BY e.id,e.name HAVING work_count>0 ORDER BY revenue DESC LIMIT 4");
$stmt->execute([$fyStart,$fyEnd]);
while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) $employees[] = [$row['name'],$row['position_name'],(float)$row['revenue'],(int)$row['work_count'],(int)$row['notifications']];
$buildings=[];
$stmt=$pdo->prepare("SELECT b.id,b.name,COUNT(w.id) AS work_count,COALESCE(SUM(w.daily_sales),0) AS revenue FROM buildings b LEFT JOIN employee_work_records w ON w.building_id=b.id AND w.work_date>=? AND w.work_date<? WHERE b.is_active=1 GROUP BY b.id,b.name ORDER BY revenue DESC");
$stmt->execute([$fyStart,$fyEnd]); $buildingRows=$stmt->fetchAll(PDO::FETCH_ASSOC);
$maxBuildingRevenue=0.0; foreach($buildingRows as $row) $maxBuildingRevenue=max($maxBuildingRevenue,(float)$row['revenue']);
foreach($buildingRows as $row) $buildings[]=[$row['name'],(int)$row['work_count'],(float)$row['revenue'],$maxBuildingRevenue>0&&(float)$row['revenue']===$maxBuildingRevenue];
$d = ['members'=>$members,'revenue'=>$revenue,'op_members'=>$opMembers,'op_revenue'=>$opRevenue,'online_members'=>$onlineMembers,'online_revenue'=>$onlineRevenue,'booth_members'=>$boothMembers,'booth_revenue'=>$boothRevenue,'unknown_members'=>$unknownMembers,'unknown_revenue'=>$unknownRevenue,'new_count'=>$newCount,'renew_count'=>$renewCount,'target'=>$target,'achievement'=>$achievement,'remaining'=>$remaining];
function money($v) { return '฿' . number_format((float)$v); }
?>

<!doctype html>

<html lang="th">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>NSM Member Club - Dashboard</title>

<link
    rel="stylesheet"
    href="assets/css/style.css?v=10"
>

<script
    src="https://cdn.jsdelivr.net/npm/chart.js"
></script>

<style>
.fy-filter-label{display:flex;flex-direction:column;gap:5px;color:#667085;font-size:12px;font-weight:600}
.fy-filter-label select{min-width:135px;padding:9px 10px;border:1px solid #d7e0ea;border-radius:8px;background:#fff;color:#25364a;font-size:13px}
.member-summary-breakdown{display:flex;flex-direction:column;gap:6px}
.comparison-table{min-width:850px}.comparison-table th,.comparison-table td{padding:10px 12px;border-bottom:1px solid #edf1f5}.comparison-table th{white-space:nowrap;background:#f8fafc}
@media(max-width:760px){.filter-bar{align-items:stretch;flex-wrap:wrap}.fy-filter-label{flex:1;min-width:125px}}
</style></head>

<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<?php $activePage = 'dashboard.php'; require __DIR__ . '/includes/navigation.php'; ?>



<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">


<!-- =========================================================
     TOPBAR
========================================================= -->

<header class="topbar">

    <div>

        <h1>
            Dashboard
        </h1>

        <p>
            ภาพรวมการดำเนินงาน NSM Member Club
        </p>

    </div>


    

    </div>

</header>



<section class="content">


<!-- =========================================================
     FILTER
========================================================= -->

<form class="filter-bar" method="get">
    <span class="filter-label">เปรียบเทียบปีงบประมาณ</span>
    <?php foreach (['fy1'=>$fy1,'fy2'=>$fy2,'fy3'=>$fy3] as $key=>$selectedYear): ?>
      <label class="fy-filter-label"><span><?= $key==='fy1'?'ปีหลัก':($key==='fy2'?'ปีเปรียบเทียบ 2':'ปีเปรียบเทียบ 3') ?></span>
        <select name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
          <?php foreach ($yearOptions as $yearOption): ?>
            <option value="<?= (int)$yearOption ?>" <?= (int)$selectedYear===(int)$yearOption?'selected':'' ?>>ปีงบประมาณ <?= (int)$yearOption ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary">เปรียบเทียบ</button>
</form>



<div class="card member-summary-card" style="margin-top:16px">
    <div class="card-header">
        <div>
            <div class="card-title">📌 สรุปสมาชิกและรายได้</div>
            <div class="card-subtitle">ภาพรวมจากข้อมูล Excel ที่นำเข้า · แยกหมวดตามช่องทาง</div>
        </div>
        <span class="badge">FY <?= htmlspecialchars($fy) ?></span>
    </div>

    <div class="member-summary-boxes">
        <div class="member-summary-box member-box">
            <div class="member-summary-box-head">
                <div class="member-summary-box-title">
                    <div class="member-summary-box-icon blue">👥</div>
                    <div class="member-summary-box-label">รายการสมาชิกทั้งหมด</div>
                </div>
                <span class="member-summary-box-badge blue">รวมทั้งหมด</span>
            </div>
            <div class="member-summary-big"><?=number_format($d['members'])?> <span class="member-summary-unit">รายการ</span></div>
            <div class="member-summary-breakdown">
                <div class="member-summary-source op">สมัคร อพ.<strong><?=number_format($d['op_members'])?> รายการ</strong></div>
                <div class="member-summary-source online">ออนไลน์<strong><?=number_format($d['online_members'])?> รายการ</strong></div>
                <div class="member-summary-source">ออกบูธ<strong><?=number_format($d['booth_members'])?> รายการ</strong></div>
                <div class="member-summary-source">ยังไม่จัดหมวด<strong><?=number_format($d['unknown_members'])?> รายการ</strong></div>
            </div>
        </div>

        <div class="member-summary-box revenue-box">
            <div class="member-summary-box-head">
                <div class="member-summary-box-title">
                    <div class="member-summary-box-icon green">💰</div>
                    <div class="member-summary-box-label">ยอดสุทธิรวม (ชั่วคราว)</div>
                </div>
                <span class="member-summary-box-badge green">รวมทั้งหมด</span>
            </div>
            <div class="member-summary-big"><?=money($d['revenue'])?> <span class="member-summary-unit">บาท</span></div>
            <div class="member-summary-breakdown">
                <div class="member-summary-source op">สมัคร อพ.<strong><?=money($d['op_revenue'])?></strong></div>
                <div class="member-summary-source online">ออนไลน์<strong><?=money($d['online_revenue'])?></strong></div>
                <div class="member-summary-source">ออกบูธ<strong><?=money($d['booth_revenue'])?></strong></div>
                <div class="member-summary-source">ยังไม่จัดหมวด<strong><?=money($d['unknown_revenue'])?></strong></div>
            </div>
        </div>
    </div>
</div>



<!-- =========================================================
     STAT CARDS
========================================================= -->

<div class="stats stats-secondary">


<?php

$cards = [
    ['สมัครใหม่', number_format($d['new_count']), 'จากข้อมูล Excel · ปีหลัก', '🆕', 'green'],
    ['ต่ออายุ', number_format($d['renew_count']), 'จากข้อมูล Excel · ปีหลัก', '🔄', 'orange'],
    ['รายการออกบูธ', number_format($d['booth_members']), 'แยกจาก อพ. และออนไลน์', '📢', 'purple'],
    ['รอตรวจสอบหมวด', number_format($d['unknown_members']), 'ควรจัดหมวดก่อนรายงานทางการ', '⚠️', 'orange']
];


foreach ($cards as $c):

?>

    <div class="stat-card">

        <div>

            <div class="stat-label">
                <?= htmlspecialchars($c[0]) ?>
            </div>

            <div class="stat-number">
                <?= $c[1] ?>
            </div>

            <div class="stat-change positive">
                <?= htmlspecialchars($c[2]) ?>
            </div>

        </div>


        <div class="stat-icon <?= htmlspecialchars($c[4]) ?>">
            <?= $c[3] ?>
        </div>

    </div>

<?php endforeach; ?>

</div>

<!-- =========================================================
     REVENUE + TARGET
========================================================= -->

<div class="grid">


<section class="card">


    <div class="card-header">

        <div>

            <div class="card-title">
                รายได้รายเดือน
            </div>

            <div class="card-subtitle">
                ยอดสุทธิจาก Excel แยกตามส่วน · ใช้เป็นตัวเปรียบเทียบชั่วคราว
            </div>

        </div>


        <span class="badge">
            FY <?= htmlspecialchars($fy) ?>
        </span>

    </div>


    <div class="chart-container">

        <canvas id="revenueChart"></canvas>

    </div>


</section>



<section class="card">


    <div class="card-header">

        <div>

            <div class="card-title">
                🎯 เป้าหมายรายได้ (เทียบกับยอดสุทธิ)
            </div>

            <div class="card-subtitle">
                NSM Member Club
            </div>

        </div>


        <span class="badge">
            FY <?= htmlspecialchars($fy) ?>
        </span>

    </div>


    <div class="target-number">

        <?= money($d['revenue']) ?>

    </div>


    <div class="target-text">

        จากเป้าหมาย
        <?= money($d['target']) ?>

    </div>


    <div class="progress">

        <div
            class="progress-bar"
            style="width:<?= min(100, $d['achievement']) ?>%"
        ></div>

    </div>


    <div class="target-row">

        <span>
            ทำได้แล้ว
        </span>

        <strong>
            <?= number_format($d['achievement'], 1) ?>%
        </strong>

    </div>


    <div class="target-row">

        <span>
            เหลืออีก
        </span>

        <strong>
            <?= money($d['remaining']) ?>
        </strong>

    </div>


    <div class="target-row">

        <span>
            เป้าหมาย/เดือน
        </span>

        <strong>
            <?= money($monthly_target) ?>
        </strong>

    </div>


</section>

</div>



<section class="card" style="margin-top:20px">
  <div class="card-header"><div><div class="card-title">📈 เปรียบเทียบยอดสุทธิ 3 ปีงบประมาณ</div><div class="card-subtitle">รายเดือน ต.ค. – ก.ย. จากข้อมูล Excel · ยอดสุทธิยังเป็นตัวชี้วัดชั่วคราว</div></div></div>
  <div class="chart-container"><canvas id="fiscalCompareChart"></canvas></div>
  <div class="card-header" style="margin-top:16px"><div><div class="card-title">📋 ตารางเปรียบเทียบรายเดือน</div><div class="card-subtitle">จำนวนรายการและยอดสุทธิของแต่ละปี แยกเป็นคอลัมน์ชัดเจน</div></div></div>
  <div style="overflow:auto"><table class="building-table comparison-table"><thead><tr><th>เดือน</th><?php foreach($years as $year): ?><th colspan="2" class="right">ปี <?= (int)$year ?></th><?php endforeach; ?></tr><tr><th></th><?php foreach($years as $year): ?><th class="right">รายการ</th><th class="right">ยอดสุทธิ</th><?php endforeach; ?></tr></thead><tbody><?php foreach($comparisonTable as $row): ?><tr><td><?= htmlspecialchars($row['month'], ENT_QUOTES, 'UTF-8') ?></td><?php foreach($years as $i=>$year): ?><td class="right"><?= number_format($row['c'.$i]) ?></td><td class="right">฿<?= number_format($row['r'.$i],2) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
</section>

<!-- =========================================================
     EMPLOYEE + BUILDING
========================================================= -->

<div class="grid">


<section class="card">


    <div class="card-header">

        <div>

            <div class="card-title">
                👨‍💼 ผลงานพนักงาน
            </div>

            <div class="card-subtitle">

                สรุปการทำงานในปีงบประมาณ
                <?= htmlspecialchars($fy) ?>

            </div>

        </div>


        <a
            class="link"
            href="employees.php"
        >
            ดูทั้งหมด →
        </a>

    </div>



    <div class="employee-list">


        <?php if (empty($employees)): ?>


            <div class="employee">

                <div>

                    ยังไม่มีข้อมูลการปฏิบัติงาน
                    ในปีงบประมาณนี้

                </div>

            </div>


        <?php else: ?>


            <?php foreach ($employees as $e): ?>


                <div class="employee">


                    <div class="employee-info">


                        <div class="employee-avatar">

                            <?= htmlspecialchars(
                                (preg_match('/^./us', (string)$e[0], $avatarMatch) ? $avatarMatch[0] : substr((string)$e[0], 0, 1))
                            ) ?>

                        </div>


                        <div>

                            <div class="employee-name">

                                <?= htmlspecialchars($e[0]) ?>

                            </div>


                            <div class="employee-position">

                                <?= htmlspecialchars($e[1]) ?>

                            </div>

                        </div>


                    </div>


                    <div class="employee-value">


                        <strong>

                            <?= money($e[2]) ?>

                        </strong>


                        <span>

                            <?= $e[3] ?>
                            ครั้ง · แจ้งข่าว
                            <?= number_format($e[4]) ?>

                        </span>


                    </div>


                </div>


            <?php endforeach; ?>


        <?php endif; ?>


    </div>


</section>



<section class="card">


    <div class="card-header">

        <div>

            <div class="card-title">
                🏢 ผลงานตามอาคาร
            </div>

            <div class="card-subtitle">
                ภาพรวมการปฏิบัติงาน
            </div>

        </div>

    </div>



    <table class="building-table">


        <thead>

            <tr>

                <th>
                    อาคาร
                </th>

                <th>
                    ครั้ง
                </th>

                <th>
                    รายได้
                </th>

            </tr>

        </thead>


        <tbody>


        <?php if (empty($buildings)): ?>


            <tr>

                <td colspan="3">
                    ยังไม่มีข้อมูล
                </td>

            </tr>


        <?php else: ?>


            <?php foreach ($buildings as $b): ?>


                <tr>


                    <td>

                        <b>
                            <?= htmlspecialchars($b[0]) ?>
                        </b>


                        <?php if ($b[3]): ?>

                            <span class="badge green">
                                สูงสุด
                            </span>

                        <?php endif; ?>


                    </td>


                    <td class="right">

                        <?= number_format($b[1]) ?>

                    </td>


                    <td class="right">

                        <?= money($b[2]) ?>

                    </td>


                </tr>


            <?php endforeach; ?>


        <?php endif; ?>


        </tbody>


    </table>


</section>


</div>



<!-- =========================================================
     FOOTER
========================================================= -->

<div class="footer">

    NSM Member Club Management System
    · Dashboard

</div>


</section>

</main>



<!-- =========================================================
     DATA FOR CHART.JS
========================================================= -->

<script>
window.dashboardData = {
  labels: <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE) ?>,
  actual: <?= json_encode($actual, JSON_UNESCAPED_UNICODE) ?>,
  onlineActual: <?= json_encode($onlineActual, JSON_UNESCAPED_UNICODE) ?>,
  boothActual: <?= json_encode($boothActual, JSON_UNESCAPED_UNICODE) ?>,
  unknownActual: <?= json_encode($unknownActual, JSON_UNESCAPED_UNICODE) ?>,
  target: <?= json_encode($targetChart, JSON_UNESCAPED_UNICODE) ?>,
  compareYears: <?= json_encode($years) ?>,
  compareRevenue: <?= json_encode($compareRevenue, JSON_UNESCAPED_UNICODE) ?>,
  currentFY: <?= (int)$fy ?>,
  previousFY: <?= (int)$fy2 ?>
};
</script>



<script src="assets/js/dashboard.js"></script>


</body>

</html>
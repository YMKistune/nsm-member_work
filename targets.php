<?php
// NSM Member Club: manage annual revenue target by fiscal year.
// This page uses the existing revenue_targets / revenue_target_history tables.
date_default_timezone_set('Asia/Bangkok');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/config/database.php';

function target_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function target_money($value): string { return number_format((float)$value, 2); }

$currentFY = ((int)date('n') >= 10) ? (int)date('Y') + 544 : (int)date('Y') + 543;
$fy = (int)($_GET['fy'] ?? $_POST['fiscal_year'] ?? $currentFY);
if ($fy < 2500 || $fy > 2700) $fy = $currentFY;
$notice = '';
$error = '';
if (empty($_SESSION['nsm_target_csrf'])) $_SESSION['nsm_target_csrf'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['nsm_target_csrf'];
$projectId = 0;
$targetId = 0;
$annualTarget = 0.0;
$monthlyTarget = 0.0;
$actualRevenue = 0.0;
$historyRows = [];

try {
    $projectStmt = $pdo->prepare("SELECT id FROM projects WHERE name = 'NSM Member Club' ORDER BY id ASC LIMIT 1");
    $projectStmt->execute();
    $projectId = (int)$projectStmt->fetchColumn();
    if (!$projectId) throw new RuntimeException('ไม่พบโครงการ NSM Member Club ในตาราง projects กรุณาตรวจสอบฐานข้อมูลก่อน');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_target') {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $postedToken)) throw new RuntimeException('เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        $fy = (int)($_POST['fiscal_year'] ?? $currentFY);
        if ($fy < 2500 || $fy > 2700) throw new RuntimeException('ปีงบประมาณไม่ถูกต้อง');
        $rawAnnual = trim((string)($_POST['annual_target'] ?? ''));
        if ($rawAnnual === '' || !is_numeric($rawAnnual)) throw new RuntimeException('กรุณากรอกเป้าหมายเป็นตัวเลข');
        $annualTarget = (float)$rawAnnual;
        if (!is_finite($annualTarget) || $annualTarget < 0 || $annualTarget > 999999999999.99) throw new RuntimeException('จำนวนเป้าหมายต้องอยู่ระหว่าง 0 ถึง 999,999,999,999.99 บาท');
        $monthlyTarget = round($annualTarget / 12, 2);

        $pdo->beginTransaction();
        $oldStmt = $pdo->prepare('SELECT id, annual_target, monthly_target FROM revenue_targets WHERE fiscal_year = ? AND project_id = ? LIMIT 1 FOR UPDATE');
        $oldStmt->execute([$fy, $projectId]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if ($old) {
            $targetId = (int)$old['id'];
            $oldAnnual = (float)$old['annual_target'];
            $oldMonthly = (float)$old['monthly_target'];
            $updateStmt = $pdo->prepare('UPDATE revenue_targets SET annual_target = ?, monthly_target = ? WHERE id = ?');
            $updateStmt->execute([$annualTarget, $monthlyTarget, $targetId]);
        } else {
            $oldAnnual = null;
            $oldMonthly = null;
            $insertStmt = $pdo->prepare('INSERT INTO revenue_targets (fiscal_year, project_id, annual_target, monthly_target) VALUES (?, ?, ?, ?)');
            $insertStmt->execute([$fy, $projectId, $annualTarget, $monthlyTarget]);
            $targetId = (int)$pdo->lastInsertId();
        }

        // Keep the legacy audit trail so each adjustment can be reviewed later.
        $historyStmt = $pdo->prepare('INSERT INTO revenue_target_history (revenue_target_id, fiscal_year, project_id, old_annual_target, new_annual_target, old_monthly_target, new_monthly_target) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $historyStmt->execute([$targetId, $fy, $projectId, $oldAnnual, $annualTarget, $oldMonthly, $monthlyTarget]);
        $pdo->commit();
        $notice = 'บันทึกเป้าหมายปีงบประมาณ ' . $fy . ' เรียบร้อยแล้ว';
    }

    $targetStmt = $pdo->prepare('SELECT id, annual_target, monthly_target FROM revenue_targets WHERE fiscal_year = ? AND project_id = ? LIMIT 1');
    $targetStmt->execute([$fy, $projectId]);
    $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($targetRow) {
        $targetId = (int)$targetRow['id'];
        $annualTarget = (float)$targetRow['annual_target'];
        $monthlyTarget = (float)$targetRow['monthly_target'];
    } else {
        $annualTarget = 0.0;
        $monthlyTarget = 0.0;
    }

    // Match Dashboard's temporary revenue measure: sum imported Excel "ยอดสุทธิ".
    $moneyExpr = "CAST(NULLIF(REPLACE(REPLACE(TRIM(COALESCE(`ยอดสุทธิ`,'')),',',''),'฿',''),'') AS DECIMAL(14,2))";
    $actualStmt = $pdo->prepare("SELECT COALESCE(SUM($moneyExpr), 0) FROM excel_member_records WHERE fiscal_year = ?");
    $actualStmt->execute([$fy]);
    $actualRevenue = (float)$actualStmt->fetchColumn();

    $historyStmt = $pdo->prepare('SELECT old_annual_target, new_annual_target, changed_at FROM revenue_target_history WHERE fiscal_year = ? AND project_id = ? ORDER BY changed_at DESC, id DESC LIMIT 10');
    $historyStmt->execute([$fy, $projectId]);
    $historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = 'ไม่สามารถโหลดหรือบันทึกเป้าหมายได้: ' . $ex->getMessage();
}

$achievement = $annualTarget > 0 ? ($actualRevenue / $annualTarget) * 100 : 0;
$remaining = max(0, $annualTarget - $actualRevenue);
$calendarStart = $fy - 544;
$fyLabel = 'ตุลาคม ' . ($calendarStart + 543) . ' – กันยายน ' . ($calendarStart + 544);
$yearOptions = range(max(2500, $currentFY - 10), min(2700, $currentFY + 2));
if (!in_array($fy, $yearOptions, true)) $yearOptions[] = $fy;
rsort($yearOptions, SORT_NUMERIC);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>จัดการเป้าหมายงบประมาณ | NSM Member Club</title>
<link rel="stylesheet" href="assets/css/style.css?v=10">
<style>
.target-page-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:18px;align-items:start}
.target-field{display:flex;flex-direction:column;gap:7px;color:#475569;font-size:13px;font-weight:600}
.target-field input,.target-field select{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #d7e0ea;border-radius:8px;background:#fff;color:#25364a;font-size:14px}
.target-field input:focus,.target-field select:focus{outline:2px solid #bfdbfe;border-color:#2f6fbd}
.target-help{font-size:12px;color:#64748b;font-weight:400;line-height:1.5}
.target-metric{padding:15px;border:1px solid #e5ebf2;border-radius:10px;background:#fbfcfe}
.target-metric-label{font-size:12px;color:#64748b;margin-bottom:6px}
.target-metric-value{font-size:22px;font-weight:750;color:#26384d;overflow-wrap:anywhere}
.target-progress{height:9px;border-radius:999px;background:#e8edf3;overflow:hidden;margin:12px 0 7px}
.target-progress-fill{height:100%;background:#2f8b69;border-radius:999px}
.target-notice{padding:12px 14px;border-radius:8px;margin-bottom:14px;background:#ecfdf3;border:1px solid #abefc6;color:#067647}
.target-error{padding:12px 14px;border-radius:8px;margin-bottom:14px;background:#fef3f2;border:1px solid #fecdca;color:#b42318;overflow-wrap:anywhere}
.target-history-table{width:100%;border-collapse:collapse;min-width:560px}.target-history-table th,.target-history-table td{padding:10px 12px;border-bottom:1px solid #edf1f5;text-align:left;font-size:13px}.target-history-table th{background:#f8fafc;color:#475569}.target-history-table .right{text-align:right}
.target-muted{color:#64748b;font-size:12px;line-height:1.6}
@media(max-width:850px){.target-page-grid{grid-template-columns:1fr}.target-metric-value{font-size:20px}}
</style>
</head>
<body>
<?php $activePage = 'targets.php'; require __DIR__ . '/includes/navigation.php'; ?>
<main class="main">
<header class="topbar"><div><h1>จัดการเป้าหมายงบประมาณ</h1><p>กำหนดและแก้ไขเป้าหมายรายได้ NSM Member Club แยกตามปีงบประมาณ</p></div><div class="top-actions"></div></header>
<section class="content">
<?php if ($notice !== ''): ?><div class="target-notice" role="status"><?=target_h($notice)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="target-error" role="alert"><?=target_h($error)?><div class="target-muted" style="margin-top:6px">หากเกี่ยวกับตารางฐานข้อมูล ให้ตรวจสอบตาราง revenue_targets และ revenue_target_history ก่อน อย่านำเข้า SQL ที่ลบหรือสร้างฐานข้อมูลใหม่ทับของเดิม</div></div><?php endif; ?>
<section class="card" style="margin-bottom:18px">
  <div class="card-header"><div><div class="card-title">ปีงบประมาณที่ต้องการจัดการ</div><div class="card-subtitle">เลือกปีเพื่อดูหรือแก้ไขเป้าหมายของปีนั้น โดยไม่เปลี่ยนเป้าหมายปีอื่น</div></div></div>
  <form method="get" class="filter-bar" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <label class="target-field" style="min-width:210px">ปีงบประมาณ
      <select name="fy" onchange="this.form.submit()">
      <?php foreach ($yearOptions as $optionFY): ?><option value="<?= (int)$optionFY ?>" <?=$optionFY===$fy?'selected':''?>>ปีงบประมาณ <?= (int)$optionFY ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="target-help" style="padding-bottom:10px"><?=target_h($fyLabel)?></div>
    <noscript><button class="btn btn-light" type="submit">แสดงปีที่เลือก</button></noscript>
  </form>
</section>
<div class="target-page-grid">
  <section class="card">
    <div class="card-header"><div><div class="card-title">🎯 เป้าหมายรายได้ประจำปี</div><div class="card-subtitle">แก้ไขจำนวนงบประมาณได้ตลอด และบันทึกประวัติการเปลี่ยนแปลง</div></div><span class="badge">FY <?= (int)$fy ?></span></div>
    <form method="post" action="targets.php?fy=<?= (int)$fy ?>" style="display:grid;gap:16px">
      <input type="hidden" name="action" value="save_target"><input type="hidden" name="fiscal_year" value="<?= (int)$fy ?>"><input type="hidden" name="csrf_token" value="<?=target_h($csrfToken)?>">
      <label class="target-field">เป้าหมายรายได้ทั้งปี (บาท)
        <input type="number" name="annual_target" min="0" max="999999999999.99" step="0.01" inputmode="decimal" required value="<?=target_h(number_format($annualTarget,2,'.',''))?>">
        <span class="target-help">กรอกจำนวนเงินเป้าหมายรายปี ตัวเลขนี้แก้ไขได้โดยตรง</span>
      </label>
      <div class="target-metric"><div class="target-metric-label">เป้าหมายเฉลี่ยต่อเดือน (คำนวณอัตโนมัติ)</div><div class="target-metric-value" id="monthlyTargetPreview">฿<?=target_money($monthlyTarget)?></div><div class="target-help">เป้าหมายรายปี ÷ 12 เดือน</div></div>
      <div style="display:flex;gap:9px;flex-wrap:wrap"><button type="submit" class="btn btn-primary">💾 บันทึกเป้าหมาย</button><a class="btn btn-light" href="targets.php?fy=<?= (int)$fy ?>">ยกเลิกการแก้ไข</a></div>
    </form>
  </section>
  <section class="card">
    <div class="card-header"><div><div class="card-title">📊 ผลเทียบกับเป้าหมาย</div><div class="card-subtitle">ใช้ยอดสุทธิจาก Excel ตามวิธีที่ Dashboard ใช้อยู่ในปัจจุบัน</div></div></div>
    <div class="target-metric" style="margin-bottom:10px"><div class="target-metric-label">เป้าหมายทั้งปี</div><div class="target-metric-value">฿<?=target_money($annualTarget)?></div></div>
    <div class="target-metric" style="margin-bottom:10px"><div class="target-metric-label">รายได้จริงจาก Excel</div><div class="target-metric-value">฿<?=target_money($actualRevenue)?></div></div>
    <div class="target-metric"><div class="target-metric-label">ทำได้แล้ว</div><div class="target-metric-value"><?=number_format($achievement,1)?>%</div><div class="target-progress"><div class="target-progress-fill" style="width:<?=max(0,min(100,$achievement))?>%"></div></div><div class="target-help">ยอดที่ยังขาด: <strong>฿<?=target_money($remaining)?></strong><?php if ($actualRevenue > $annualTarget && $annualTarget > 0): ?> · เกินเป้าหมาย ฿<?=target_money($actualRevenue-$annualTarget)?><?php endif; ?></div></div>
  </section>
</div>
<section class="card" style="margin-top:18px">
  <div class="card-header"><div><div class="card-title">🕘 ประวัติการเปลี่ยนเป้าหมาย</div><div class="card-subtitle">แสดงรายการล่าสุดของปีงบประมาณ <?= (int)$fy ?> เพื่อให้ตรวจสอบการปรับจำนวนย้อนหลังได้</div></div></div>
  <div style="overflow-x:auto"><table class="target-history-table"><thead><tr><th>วันที่เปลี่ยน</th><th class="right">เป้าหมายเดิม</th><th class="right">เป้าหมายใหม่</th><th class="right">เปลี่ยนแปลง</th></tr></thead><tbody>
  <?php if (!$historyRows): ?><tr><td colspan="4" class="target-muted">ยังไม่มีประวัติการเปลี่ยนเป้าหมายสำหรับปีนี้</td></tr><?php else: foreach ($historyRows as $history): $old = $history['old_annual_target'] === null ? null : (float)$history['old_annual_target']; $new = (float)$history['new_annual_target']; $diff = $old === null ? null : $new - $old; ?><tr><td><?=target_h(date('d/m/Y H:i', strtotime((string)$history['changed_at'])))?></td><td class="right"><?=$old===null?'-':'฿'.target_money($old)?></td><td class="right">฿<?=target_money($new)?></td><td class="right"><?=$diff===null?'รายการแรก':(($diff>=0?'+':'').'฿'.target_money($diff))?></td></tr><?php endforeach; endif; ?>
  </tbody></table></div>
</section>
<p class="target-muted" style="margin-top:12px">หมายเหตุ: หน้านี้จัดการเฉพาะเป้าหมายงบประมาณ ไม่แก้ไขข้อมูลสมาชิกหรือยอดที่นำเข้าจาก Excel</p>
</section></main>
<script>
(function(){const input=document.querySelector('input[name="annual_target"]');const preview=document.getElementById('monthlyTargetPreview');if(!input||!preview)return;const update=()=>{const v=Number(input.value);const monthly=Number.isFinite(v)&&v>=0?v/12:0;preview.textContent='฿'+monthly.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});};input.addEventListener('input',update);})();
</script>
</body></html>

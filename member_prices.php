<?php
require_once __DIR__ . '/config/database.php';
$message=''; $error='';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function money($v){return '฿'.number_format((float)$v,2);}
$currentMonth=(int)date('n'); $currentYear=(int)date('Y'); $defaultFY=($currentMonth>=10)?$currentYear+544:$currentYear+543;
$fy=(int)($_GET['fy']??$defaultFY); if($fy<2500||$fy>2700)$fy=$defaultFY;
$availableYears=range($defaultFY,$defaultFY-10,-1);
foreach([$fy] as $y) if(!in_array($y,$availableYears,true))$availableYears[]=$y;
sort($availableYears,SORT_NUMERIC);$availableYears=array_reverse($availableYears);

// บันทึกราคา/ประเภทการคำนวณ โดยราคาที่แก้จะมีผลกับการบันทึกข้อมูลใหม่เท่านั้น
if($_SERVER['REQUEST_METHOD']==='POST'){
  $action=$_POST['action']??'';
  try{
    if($action==='save_prices'){
      $postFY=(int)$_POST['fiscal_year']; $rates=$_POST['rate']??[]; $types=$_POST['calculation_type']??[];
      $stmt=$pdo->prepare('UPDATE member_input_items SET rate=?, calculation_type=? WHERE id=? AND fiscal_year=?');
      foreach($rates as $id=>$rate){
        $id=(int)$id; $rate=max(0,(float)$rate); $type=$types[$id]??'fixed_rate';
        if(!in_array($type,['fixed_rate','manual_revenue','zero_revenue'],true))$type='fixed_rate';
        $stmt->execute([$rate,$type,$id,$postFY]);
      }
      $fy=$postFY; $message='บันทึกราคาสมาชิกเรียบร้อยแล้ว ราคาที่เปลี่ยนจะใช้กับการบันทึกข้อมูลใหม่ โดยข้อมูลเดิมยังคงใช้ราคาที่บันทึกไว้';
    }
    if($action==='clone_year'){
      $from=(int)$_POST['from_year']; $to=(int)$_POST['to_year'];
      if($from===$to)throw new RuntimeException('ปีต้นทางและปีปลายทางต้องต่างกัน');
      if($to<2500||$to>2700)throw new RuntimeException('ปีงบประมาณไม่ถูกต้อง');
      $check=$pdo->prepare('SELECT COUNT(*) FROM member_input_items WHERE fiscal_year=?');$check->execute([$to]);
      if((int)$check->fetchColumn()>0)throw new RuntimeException('ปีปลายทางมีรายการอยู่แล้ว ไม่ควรคัดลอกทับ');
      $stmt=$pdo->prepare('INSERT INTO member_input_items(fiscal_year,item_code,category_code,category_name,label,rate,calculation_type,sort_order,is_active) SELECT ?,item_code,category_code,category_name,label,rate,calculation_type,sort_order,is_active FROM member_input_items WHERE fiscal_year=?');
      $stmt->execute([$to,$from]); $fy=$to; $message="สร้างรายการสมาชิกปี {$to} จากปี {$from} แล้ว สามารถแก้ราคา/สูตรของปี {$to} ได้";
    }
  }catch(Throwable $ex){$error='ไม่สามารถบันทึกได้: '.$ex->getMessage();}
}
$stmt=$pdo->prepare('SELECT * FROM member_input_items WHERE fiscal_year=? ORDER BY category_code,sort_order,id');$stmt->execute([$fy]);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);
$categories=[];foreach($items as $it)$categories[$it['category_code']][]=$it;
$labels=[];foreach($items as $it)$labels[$it['category_code']]=$it['category_name'];
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ราคาสมาชิก - NSM Member Club</title><link rel="stylesheet" href="assets/css/style.css?v=10"><style>
.page-card{background:#fff;border:1px solid #e7ebef;border-radius:14px;padding:20px;margin-bottom:20px}.page-title{font-size:16px;font-weight:700}.page-subtitle{font-size:11px;color:#8a96a3;margin-top:3px;margin-bottom:18px}.filter-bar{margin-bottom:20px}.btn{border:0;border-radius:8px;padding:9px 14px;cursor:pointer;font-size:13px;font-weight:600}.btn-primary{background:#2f80ed;color:#fff}.btn-secondary{background:#edf1f5;color:#34495e}.message{padding:12px 15px;border-radius:10px;background:#e9f8ef;color:#198754;margin-bottom:18px;font-size:13px}.error{padding:12px 15px;border-radius:10px;background:#fff0f0;color:#b42318;margin-bottom:18px;font-size:13px}.price-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}.price-box{border:1px solid #e7ebef;border-radius:12px;padding:16px}.price-box h3{font-size:14px;margin-bottom:12px}.price-row{display:grid;grid-template-columns:1fr 130px 160px;gap:10px;align-items:center;padding:9px 0;border-bottom:1px solid #f1f3f5}.price-row:last-child{border-bottom:0}.label{font-size:12px}.hint{font-size:11px;color:#8a96a3}.price-row input,.price-row select,.clone-form select{width:100%;border:1px solid #d9e0e7;border-radius:7px;padding:8px;font-size:12px}.actions{display:flex;gap:10px;margin-top:18px;flex-wrap:wrap}.clone-form{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end}.clone-form label{font-size:12px;font-weight:600;display:block;margin-bottom:6px}.notice{background:#f8fafc;border:1px solid #e7ebef;border-radius:10px;padding:12px;font-size:12px;color:#667085;margin-bottom:18px}@media(max-width:900px){.price-grid{grid-template-columns:1fr}}@media(max-width:600px){.price-row{grid-template-columns:1fr 100px}.price-row select{grid-column:1/-1}.clone-form{grid-template-columns:1fr}}
</style></head><body>
<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'member_prices.php'); require __DIR__ . '/includes/navigation.php'; ?>
<main class="main"><header class="topbar"><div><h1>ราคาสมาชิก</h1><p>จัดการราคาและวิธีคำนวณแยกตามปีงบประมาณ</p></div><div class="top-actions"></div></header><section class="content">
<?php if($message):?><div class="message"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="error"><?=e($error)?></div><?php endif;?>
<div class="filter-bar"><span class="filter-label">ปีงบประมาณ</span><form method="get" style="display:inline-flex;gap:10px"><select name="fy" onchange="this.form.submit()"><?php foreach($availableYears as $y):?><option value="<?=$y?>" <?=$fy===$y?'selected':''?>><?=$y?></option><?php endforeach;?></select></form></div>
<div class="page-card"><div class="page-title">💰 ราคาสมาชิก ปีงบประมาณ <?=$fy?></div><div class="page-subtitle">หน้านี้ใช้จัดการเฉพาะราคาและวิธีคำนวณ ส่วนการเพิ่ม/แก้ชื่อประเภทและรายการ ให้จัดการที่ตั้งค่าระบบ</div><div class="notice">เมื่อมีการเปลี่ยนราคา ระบบจะใช้ราคาใหม่กับข้อมูลที่บันทึกหลังจากนั้น ส่วนรายการเดิมจะเก็บ <strong>ราคาที่ใช้จริงตอนบันทึก</strong> ไว้ เพื่อให้รายงานย้อนหลังไม่เปลี่ยนตามราคาปัจจุบัน</div>
<?php if(!$items):?><div class="notice">ยังไม่มีรายการสำหรับปี <?=$fy?> หากต้องการเริ่มปีใหม่ ให้คัดลอกจากปีที่มีอยู่ด้านล่าง</div><?php else:?><form method="post"><input type="hidden" name="action" value="save_prices"><input type="hidden" name="fiscal_year" value="<?=$fy?>"><div class="price-grid"><?php foreach($categories as $cat=>$rows):?><div class="price-box"><h3><?=e($labels[$cat])?></h3><?php foreach($rows as $it):$id=(int)$it['id'];?><div class="price-row"><div><div class="label"><?=e($it['label'])?></div><div class="hint"><?=e($it['item_code'])?></div></div><input type="number" name="rate[<?=$id?>]" value="<?=e($it['rate'])?>" min="0" step="0.01"><select name="calculation_type[<?=$id?>]"><option value="fixed_rate" <?=$it['calculation_type']==='fixed_rate'?'selected':''?>>จำนวน × ราคา</option><option value="manual_revenue" <?=$it['calculation_type']==='manual_revenue'?'selected':''?>>กรอกรายได้เอง</option><option value="zero_revenue" <?=$it['calculation_type']==='zero_revenue'?'selected':''?>>ไม่มีรายได้</option></select></div><?php endforeach;?></div><?php endforeach;?></div><div class="actions"><button class="btn btn-primary" type="submit">💾 บันทึกราคาและวิธีคำนวณ</button></div></form><?php endif;?></div>
<div class="page-card"><div class="page-title">📋 สร้างปีงบประมาณใหม่</div><div class="page-subtitle">คัดลอกรายการจากปีเดิมก่อน แล้วปรับราคา/วิธีคำนวณของปีใหม่ได้</div><form method="post" class="clone-form"><input type="hidden" name="action" value="clone_year"><div><label>คัดลอกจากปี</label><select name="from_year"><?php foreach($availableYears as $y):?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach;?></select></div><div><label>สร้างเป็นปี</label><select name="to_year"><?php foreach($availableYears as $y):?><option value="<?=$y+1?>"><?=$y+1?></option><?php endforeach;?></select></div><button class="btn btn-primary" type="submit">➕ สร้างปีใหม่</button></form></div>
<div class="footer">NSM Member Club Management System · Member Prices</div></section></main></body></html>

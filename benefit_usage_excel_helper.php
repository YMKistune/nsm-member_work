<?php
/** Excel usage-import helper. Keeps Museum and Lounge records separate. */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/config/excel_import.php';

function benefit_usage_header_key(string $value): string {
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    return preg_replace('/[\s_\-\/\\().:]+/u', '', $value) ?? $value;
}
function benefit_usage_column(array $headerMap, array $aliases): ?string {
    $normalized = [];
    foreach ($headerMap as $col => $name) $normalized[benefit_usage_header_key((string)$name)] = $col;
    foreach ($aliases as $alias) {
        $key = benefit_usage_header_key($alias);
        if (isset($normalized[$key])) return $normalized[$key];
    }
    return null;
}
function benefit_usage_parse_datetime(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $formats = ['!d/m/Y H:i:s','!d/m/Y H:i','!d/m/Y','!Y-m-d H:i:s','!Y-m-d H:i','!Y-m-d','!d-m-Y H:i:s','!d-m-Y H:i','!d-m-Y'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $year = (int)$dt->format('Y');
            if ($year > 2400) $dt = $dt->modify('-543 years');
            return $dt->format('Y-m-d H:i:s');
        }
    }
    // Last safe fallback for ISO timestamps only.
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)) {
        try { $dt = new DateTimeImmutable(str_replace('T',' ',$value)); $year=(int)$dt->format('Y'); if($year>2400)$dt=$dt->modify('-543 years'); return $dt->format('Y-m-d H:i:s'); } catch(Throwable $e) { return null; }
    }
    return null;
}
function benefit_usage_fiscal_year(string $mysqlDate): int {
    $dt = new DateTimeImmutable($mysqlDate);
    return (int)$dt->format('Y') + 543 + ((int)$dt->format('n') >= 10 ? 1 : 0);
}
function benefit_usage_process_post(PDO $pdo, string $area): array {
    $result = ['message'=>'','error'=>'','preview'=>null,'imported'=>0,'skipped'=>0];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return $result;
    $action = (string)($_POST['benefit_excel_action'] ?? '');
    if (!in_array($action, ['preview','confirm'], true)) return $result;
    if (!isset($_SESSION['benefit_excel_csrf'])) $_SESSION['benefit_excel_csrf'] = bin2hex(random_bytes(24));
    if (!hash_equals((string)$_SESSION['benefit_excel_csrf'], (string)($_POST['csrf_token'] ?? ''))) { $result['error']='คำขอหมดอายุหรือไม่ถูกต้อง กรุณาลองใหม่'; return $result; }
    $sessionKey = 'benefit_excel_preview_' . $area;
    try {
        if ($action === 'preview') {
            if (!isset($_FILES['benefit_excel_file']) || $_FILES['benefit_excel_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('กรุณาเลือกไฟล์ Excel .xlsx');
            $file = $_FILES['benefit_excel_file'];
            if ((int)$file['size'] <= 0 || (int)$file['size'] > 15 * 1024 * 1024) throw new RuntimeException('ไฟล์ต้องมีขนาดมากกว่า 0 และไม่เกิน 15 MB');
            if (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('รองรับเฉพาะไฟล์ .xlsx ในขั้นตอนนี้');
            $rows = xlsx_read_rows((string)$file['tmp_name']);
            if (count($rows) < 2) throw new RuntimeException('ไม่พบแถวข้อมูลใต้หัวตารางใน Sheet แรก');
            $headerRow = array_shift($rows); $headerMap = [];
            foreach ($headerRow as $col=>$heading) { $heading=trim((string)$heading); if($heading!=='') $headerMap[(string)$col]=$heading; }
            $columns = [
                'member_number'=>benefit_usage_column($headerMap,['หมายเลขสมาชิก','รหัสสมาชิก','member number','member no','member id','membership id']),
                'member_name'=>benefit_usage_column($headerMap,['รายชื่อลูกค้า','ชื่อสมาชิก','ชื่อ-นามสกุล','ชื่อลูกค้า','member name','customer name','name']),
                'used_at'=>benefit_usage_column($headerMap,['วันที่ใช้สิทธิ์','วันที่ใช้บริการ','วันเวลาที่ใช้สิทธิ์','วันเวลาใช้สิทธิ์','วันที่เข้าใช้','วันที่เข้าชม','วันที่เข้า lounge','วันที่เข้า Lounge','used at','usage date','visit date','service date','date/time','datetime']),
                'benefit_type'=>benefit_usage_column($headerMap,['ประเภทสิทธิ์','ประเภทการใช้สิทธิ์','ประเภทบริการ','สิทธิ์ที่ใช้','กิจกรรม/ Package','package','benefit type','service type','service','benefit']),
                'location'=>benefit_usage_column($headerMap,['พิพิธภัณฑ์ / อาคาร','พิพิธภัณฑ์/อาคาร','อาคาร','สถานที่ใช้สิทธิ์','สถานที่ใช้บริการ','สถานที่_กิจกรรม','สถานที่','จุดบริการ','museum','location','building','venue']),
                'member_type'=>benefit_usage_column($headerMap,['ประเภทสมาชิก','ประเภทการสมัคร','member type','membership type']),
                'member_level'=>benefit_usage_column($headerMap,['ระดับสมาชิก','ระดับ','member level','membership level']),
                'notes'=>benefit_usage_column($headerMap,['หมายเหตุ','รายละเอียดเพิ่มเติม','notes','remark','remarks'])
            ];
            if (!$columns['member_number']) throw new RuntimeException('ไม่พบคอลัมน์รหัสสมาชิก เช่น หมายเลขสมาชิก');
            if (!$columns['used_at']) throw new RuntimeException('ไม่พบคอลัมน์วันที่ใช้สิทธิ์/วันที่ใช้บริการ จึงยังนำเข้าไม่ได้');
            $recognizedCols = array_values(array_unique(array_filter(array_values($columns), static fn($v) => $v !== null)));
            $unknownHeaders = [];
            foreach ($headerMap as $col => $heading) {
                if (!in_array($col, $recognizedCols, true)) $unknownHeaders[] = $heading;
            }
            if ($unknownHeaders) {
                $pending = $pdo->prepare("INSERT IGNORE INTO excel_dynamic_columns(header_name,status,section_scope) VALUES(?,'pending','unknown')");
                foreach ($unknownHeaders as $unknownHeader) $pending->execute([$unknownHeader]);
            }
            $previewRows=[]; $valid=0; $invalid=0; $nonempty=0;
            foreach ($rows as $row) {
                $get=static function($key) use($row,$columns){$col=$columns[$key]??null; return $col!==null?trim((string)($row[$col]??'')):'';};
                $member= $get('member_number'); $date=benefit_usage_parse_datetime($get('used_at'));
                if ($member==='' && trim($get('used_at'))==='') continue;
                $nonempty++;
                $record=['member_number'=>$member,'member_name'=>$get('member_name'),'used_at'=>$date,'benefit_type'=>$get('benefit_type'),'location'=>$get('location'),'member_type'=>$get('member_type'),'member_level'=>$get('member_level'),'notes'=>$get('notes')];
                $record['valid']=($member!=='' && $date!==null && ($area!=='museum' || preg_match('/พิพิธภัณฑ์|museum|ฟิวเจอเรียม|futurium|อาคาร/u', $record['location'].' '.$record['benefit_type'])) && ($area!=='lounge' || preg_match('/lounge/u', $record['location'].' '.$record['benefit_type']) || $record['location']!=='' || $record['benefit_type']!==''));
                if($record['valid']) $valid++; else $invalid++;
                if(count($previewRows)<12) $previewRows[]=$record;
            }
            if($nonempty===0) throw new RuntimeException('ไม่พบแถวข้อมูลที่มีค่าพอสำหรับตรวจสอบ');
            $_SESSION[$sessionKey]=['area'=>$area,'file_name'=>basename((string)$file['name']),'file_hash'=>hash_file('sha256',(string)$file['tmp_name']),'headers'=>$headerMap,'columns'=>$columns,'unknown_headers'=>$unknownHeaders,'rows'=>$rows,'valid'=>$valid,'invalid'=>$invalid,'nonempty'=>$nonempty,'preview'=>$previewRows,'created'=>time()];
            $result['preview']=$_SESSION[$sessionKey];
            $result['message']='ตรวจสอบไฟล์แล้ว กรุณาตรวจตัวอย่างก่อนยืนยันนำเข้า';
        } else {
            $payload=$_SESSION[$sessionKey]??null;
            if(!is_array($payload)||($payload['area']??'')!==$area||time()-(int)($payload['created']??0)>1800) throw new RuntimeException('ไม่พบตัวอย่างไฟล์หรือหมดเวลา กรุณาเลือกไฟล์เพื่อดูตัวอย่างใหม่');
            if((int)$payload['valid']<1) throw new RuntimeException('ไม่มีแถวที่ผ่านการตรวจสอบ จึงไม่ได้นำเข้า');
            $pdo->exec("CREATE TABLE IF NOT EXISTS benefit_usage_import_batches (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, benefit_area ENUM('museum','lounge') NOT NULL, source_filename VARCHAR(255) NOT NULL, file_hash CHAR(64) NOT NULL, imported_rows INT UNSIGNED NOT NULL DEFAULT 0, skipped_rows INT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_benefit_area_file (benefit_area,file_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS benefit_usage_import_rows (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, benefit_area ENUM('museum','lounge') NOT NULL, row_hash CHAR(64) NOT NULL, usage_record_id BIGINT UNSIGNED DEFAULT NULL, batch_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_benefit_area_row (benefit_area,row_hash), KEY idx_benefit_import_batch(batch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $pdo->beginTransaction();
            $batchCheck=$pdo->prepare('SELECT id FROM benefit_usage_import_batches WHERE benefit_area=? AND file_hash=?'); $batchCheck->execute([$area,$payload['file_hash']]);
            if($batchCheck->fetchColumn()) throw new RuntimeException('ไฟล์นี้เคยนำเข้าส่วนนี้แล้ว ระบบป้องกันการนำเข้าซ้ำ');
            $batchInsert=$pdo->prepare('INSERT INTO benefit_usage_import_batches(benefit_area,source_filename,file_hash) VALUES(?,?,?)');
            $batchInsert->execute([$area,$payload['file_name'],$payload['file_hash']]); $batchId=(int)$pdo->lastInsertId();
            $rowMark=$pdo->prepare('INSERT IGNORE INTO benefit_usage_import_rows(benefit_area,row_hash,usage_record_id,batch_id) VALUES(?,?,NULL,?)');
            $usageInsert=$pdo->prepare('INSERT INTO benefit_usage_records(member_number,member_name,benefit_area,used_at,fiscal_year,benefit_type,location,member_type,member_level,notes) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $rowLink=$pdo->prepare('UPDATE benefit_usage_import_rows SET usage_record_id=? WHERE benefit_area=? AND row_hash=?');
            $imported=0; $skipped=0; $seen=[];
            foreach($payload['rows'] as $row){
                $cols=$payload['columns']; $get=static function($key) use($row,$cols){$col=$cols[$key]??null;return $col!==null?trim((string)($row[$col]??'')):'';};
                $member=$get('member_number'); $rawDate=$get('used_at'); $date=benefit_usage_parse_datetime($rawDate);
                if($member==='' && $rawDate==='') continue;
                $type=$get('benefit_type'); $location=$get('location');
                $areaValid=($area==='museum' ? (bool)preg_match('/พิพิธภัณฑ์|museum|ฟิวเจอเรียม|futurium|อาคาร/u',$location.' '.$type) : ($location!==''||$type!==''));
                if($member===''||!$date||!$areaValid){$skipped++;continue;}
                $name=$get('member_name'); $memberType=$get('member_type'); $level=$get('member_level'); $notes=$get('notes');
                $sig=hash('sha256',json_encode([$member,$date,$area,$type,$location,$memberType,$level,$notes],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                if(isset($seen[$sig])){$skipped++;continue;} $seen[$sig]=true;
                $rowMark->execute([$area,$sig,$batchId]);
                if($rowMark->rowCount()===0){$skipped++;continue;}
                if($name==='') $name=$member;
                $usageInsert->execute([$member,$name,$area,$date,benefit_usage_fiscal_year($date),$type,$location,$memberType,$level,$notes]);
                $rowLink->execute([(int)$pdo->lastInsertId(),$area,$sig]); $imported++;
            }
            $updateBatch=$pdo->prepare('UPDATE benefit_usage_import_batches SET imported_rows=?, skipped_rows=? WHERE id=?'); $updateBatch->execute([$imported,$skipped,$batchId]);
            $pdo->commit(); unset($_SESSION[$sessionKey]);
            $result['imported']=$imported; $result['skipped']=$skipped; $result['message']='นำเข้าข้อมูลเรียบร้อย: '.$imported.' รายการ'.($skipped?' (ข้ามรายการซ้ำหรือข้อมูลไม่ครบ '.$skipped.' รายการ)':'');
        }
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); $result['error']=$e->getMessage(); }
    if(!$result['preview'] && isset($_SESSION[$sessionKey]) && $action==='preview') $result['preview']=$_SESSION[$sessionKey];
    return $result;
}
function benefit_usage_render_excel_panel(array $result, string $area): void {
    if (!isset($_SESSION['benefit_excel_csrf'])) $_SESSION['benefit_excel_csrf']=bin2hex(random_bytes(24));
    $csrf=htmlspecialchars((string)$_SESSION['benefit_excel_csrf'],ENT_QUOTES,'UTF-8');
    $label=$area==='museum'?'สิทธิ์เข้าชมพิพิธภัณฑ์':'Member Lounge';
    echo '<section class="card benefit-excel-panel"><div class="card-header"><div><div class="card-title">📥 นำเข้าข้อมูลการใช้สิทธิ์จาก Excel</div><div class="card-subtitle">แยกจากข้อมูลสมัครสมาชิก และคงหน้าสรุปแบบเดิมไว้ด้านล่าง</div></div></div>';
    if(!empty($result['error'])) echo '<div class="benefit-import-alert error">'.htmlspecialchars($result['error'],ENT_QUOTES,'UTF-8').'</div>';
    if(!empty($result['message'])) echo '<div class="benefit-import-alert success">'.htmlspecialchars($result['message'],ENT_QUOTES,'UTF-8').'</div>';
    echo '<form method="post" enctype="multipart/form-data" class="benefit-excel-form"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="benefit_excel_action" value="preview"><label class="benefit-drop"><strong>เลือกไฟล์ Excel (.xlsx)</strong><span>ข้อมูลของ '.$label.' จะถูกนำเข้าเฉพาะส่วนนี้</span><input type="file" name="benefit_excel_file" accept=".xlsx" required></label><button class="btn btn-primary" type="submit">ตรวจสอบและดูตัวอย่าง</button></form>';
    $preview=$result['preview']??null;
    if(is_array($preview)){
        echo '<div class="benefit-preview-summary"><strong>ไฟล์:</strong> '.htmlspecialchars($preview['file_name'],ENT_QUOTES,'UTF-8').' · <strong>แถวที่ตรวจพบ:</strong> '.(int)$preview['nonempty'].' · <strong>ผ่านเบื้องต้น:</strong> '.(int)$preview['valid'].' · <strong>ต้องตรวจสอบ/ข้าม:</strong> '.(int)$preview['invalid'].'</div>';
        if (!empty($preview['unknown_headers'])) { echo '<div class="benefit-import-alert error"><strong>พบหัวข้อ Excel ใหม่/ยังไม่ได้จับคู่:</strong> '.htmlspecialchars(implode(', ', $preview['unknown_headers']),ENT_QUOTES,'UTF-8').'<br>ระบบบันทึกหัวข้อเหล่านี้ไว้ในรายการรอตรวจสอบแล้ว แต่จะไม่นำไปใช้คำนวณจนกว่าจะตั้งค่าการจับคู่ให้ถูกต้อง</div>'; }
        echo '<div class="benefit-preview-wrap"><table class="building-table"><thead><tr><th>รหัสสมาชิก</th><th>ชื่อ</th><th>วันเวลาใช้</th><th>ประเภทสิทธิ์/บริการ</th><th>สถานที่</th><th>ผลตรวจเบื้องต้น</th></tr></thead><tbody>';
        foreach($preview['preview'] as $r){echo '<tr><td>'.htmlspecialchars($r['member_number'],ENT_QUOTES,'UTF-8').'</td><td>'.htmlspecialchars($r['member_name'],ENT_QUOTES,'UTF-8').'</td><td>'.htmlspecialchars($r['used_at']??'อ่านวันที่ไม่ได้',ENT_QUOTES,'UTF-8').'</td><td>'.htmlspecialchars($r['benefit_type'],ENT_QUOTES,'UTF-8').'</td><td>'.htmlspecialchars($r['location'],ENT_QUOTES,'UTF-8').'</td><td>'.(!empty($r['valid'])?'<span class="benefit-valid">ผ่านเบื้องต้น</span>':'<span class="benefit-invalid">ตรวจสอบ/ข้าม</span>').'</td></tr>';}
        echo '</tbody></table></div><p class="benefit-import-footnote">ระบบจะข้ามรายการซ้ำและรายการที่ข้อมูลสำคัญไม่ครบ ไม่ใช้ยอดสมัครสมาชิกแทนจำนวนครั้งใช้สิทธิ์จริง</p>';
        if((int)$preview['valid']>0){echo '<form method="post" class="benefit-confirm-form"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="benefit_excel_action" value="confirm"><button class="btn btn-primary" type="submit" onclick="return confirm(\'ยืนยันนำเข้าข้อมูลการใช้สิทธิ์ในส่วนนี้ใช่หรือไม่?\')">ยืนยันนำเข้า '.(int)$preview['valid'].' รายการที่ผ่านเบื้องต้น</button><button class="btn btn-secondary" type="button" onclick="window.location.href=window.location.pathname+window.location.search">ยกเลิกตัวอย่าง</button></form>';}
    }
    echo '<p class="benefit-import-footnote">รองรับหัวข้อที่มีชื่อแตกต่างกันตามรายการที่ระบบรู้จัก หากเป็นหัวข้อใหม่หรือไฟล์มีรูปแบบวันที่ไม่รองรับ ให้ตรวจสอบก่อนนำเข้า · จำกัดไฟล์ 15 MB · อ่าน Sheet แรก</p></section>';
}
?>
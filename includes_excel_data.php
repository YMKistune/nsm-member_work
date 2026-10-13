<?php

if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/excel_import.php';

$excelColumns = [
'วันที่สมัคร','วันหมดอายุ','รับวารสารหน้างาน','เริ่มส่งวารสารเดือน','วารสารครบเดือน','Tracking','วันเกิด','เดือนเกิด','ปีเกิด','ปีเกิด คศ','วันเดือนปีเกิด','รหัสสั่งซื้อ','กลุ่มลูกค้า','คำนำหน้า','รายชื่อลูกค้า','หมายเลขสมาชิก','ชื่อผู้รับวารสาร','โรงเรียน/หน่วยงาน','ที่อยู่','ตำบล/แขวง','อำเภอ/เขต','จังหวัด','รหัสไปรษณีย์','ที่อยู่ออกใบกำกับ','เบอร์โทรศัพท์','อีเมล','เลขที่ประจำตัวผู้เสียภาษี','POS','สถานที่_กิจกรรม','กิจกรรม/ Package','ประเภทการสมัคร','ช่องทางจำหน่าย','ประเภทการชำระ','จำนวนเงินทั้งหมด','ส่วนลด','บัตรกำนัล','ยอดจ่าย','ยอดสุทธิ','วันที่ชำระครบถ้วน','วันที่สมัคร2','สถานะรายการ','หมายเหตุ'
];

function ensureExcelTables(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `excel_import_batches` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `source_filename` varchar(255) NOT NULL,
          `source_sheet` varchar(100) NOT NULL DEFAULT 'Sheet1',
          `row_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
          `file_hash` char(64) DEFAULT NULL,
          `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`), KEY `idx_imported_at` (`imported_at`), KEY `idx_file_hash` (`file_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $col = $pdo->query("SHOW COLUMNS FROM excel_import_batches LIKE 'file_hash'")->fetch();
        if (!$col) $pdo->exec("ALTER TABLE excel_import_batches ADD COLUMN file_hash CHAR(64) NULL AFTER row_count, ADD KEY idx_file_hash (file_hash)");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `excel_member_records` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `import_batch_id` bigint(20) UNSIGNED NOT NULL,
          `source_row` int(10) UNSIGNED NOT NULL,
          `source_section` enum('ap','online','booth','unknown') NOT NULL DEFAULT 'unknown',
          `fiscal_year` smallint(5) UNSIGNED DEFAULT NULL,
          `วันที่สมัคร` text DEFAULT NULL, `วันหมดอายุ` text DEFAULT NULL, `รับวารสารหน้างาน` text DEFAULT NULL,
          `เริ่มส่งวารสารเดือน` text DEFAULT NULL, `วารสารครบเดือน` text DEFAULT NULL, `Tracking` text DEFAULT NULL,
          `วันเกิด` text DEFAULT NULL, `เดือนเกิด` text DEFAULT NULL, `ปีเกิด` text DEFAULT NULL, `ปีเกิด คศ` text DEFAULT NULL,
          `วันเดือนปีเกิด` text DEFAULT NULL, `รหัสสั่งซื้อ` text DEFAULT NULL, `กลุ่มลูกค้า` text DEFAULT NULL, `คำนำหน้า` text DEFAULT NULL,
          `รายชื่อลูกค้า` text DEFAULT NULL, `หมายเลขสมาชิก` text DEFAULT NULL, `ชื่อผู้รับวารสาร` text DEFAULT NULL,
          `โรงเรียน/หน่วยงาน` text DEFAULT NULL, `ที่อยู่` text DEFAULT NULL, `ตำบล/แขวง` text DEFAULT NULL, `อำเภอ/เขต` text DEFAULT NULL,
          `จังหวัด` text DEFAULT NULL, `รหัสไปรษณีย์` text DEFAULT NULL, `ที่อยู่ออกใบกำกับ` text DEFAULT NULL, `เบอร์โทรศัพท์` text DEFAULT NULL,
          `อีเมล` text DEFAULT NULL, `เลขที่ประจำตัวผู้เสียภาษี` text DEFAULT NULL, `POS` text DEFAULT NULL, `สถานที่_กิจกรรม` text DEFAULT NULL,
          `กิจกรรม/ Package` text DEFAULT NULL, `ประเภทการสมัคร` text DEFAULT NULL, `ช่องทางจำหน่าย` text DEFAULT NULL, `ประเภทการชำระ` text DEFAULT NULL,
          `จำนวนเงินทั้งหมด` text DEFAULT NULL, `ส่วนลด` text DEFAULT NULL, `บัตรกำนัล` text DEFAULT NULL, `ยอดจ่าย` text DEFAULT NULL,
          `ยอดสุทธิ` text DEFAULT NULL, `วันที่ชำระครบถ้วน` text DEFAULT NULL, `วันที่สมัคร2` text DEFAULT NULL, `สถานะรายการ` text DEFAULT NULL,
          `หมายเหตุ` text DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`), UNIQUE KEY `uq_batch_row` (`import_batch_id`,`source_row`), KEY `idx_section_fy` (`source_section`,`fiscal_year`),
          KEY `idx_apply_date` (`วันที่สมัคร`(40)), KEY `idx_member_no` (`หมายเลขสมาชิก`(60)), KEY `idx_package` (`กิจกรรม/ Package`(120)), KEY `idx_channel` (`ช่องทางจำหน่าย`(120)),
          CONSTRAINT `fk_excel_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `excel_import_batches` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Additive upgrade: preserve all existing rows while retaining values from new Excel headings.
        $extraDataColumn = $pdo->query("SHOW COLUMNS FROM excel_member_records LIKE 'extra_data'")->fetch();
        if (!$extraDataColumn) {
            $pdo->exec("ALTER TABLE excel_member_records ADD COLUMN extra_data LONGTEXT NULL AFTER `หมายเหตุ`");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `excel_dynamic_columns` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `header_name` varchar(255) NOT NULL,
          `first_seen_batch_id` bigint(20) UNSIGNED DEFAULT NULL,
          `status` enum('pending','active','ignored') NOT NULL DEFAULT 'pending',
          `section_scope` enum('all','ap','online','booth','unknown') NOT NULL DEFAULT 'all',
          `display_in_table` tinyint(1) NOT NULL DEFAULT 0,
          `use_in_filter` tinyint(1) NOT NULL DEFAULT 0,
          `use_in_report` tinyint(1) NOT NULL DEFAULT 0,
          `use_in_chart` tinyint(1) NOT NULL DEFAULT 0,
          `mapped_to_header` varchar(255) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`), UNIQUE KEY `uq_excel_dynamic_header` (`header_name`),
          KEY `idx_excel_dynamic_status` (`status`), KEY `idx_excel_dynamic_section` (`section_scope`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Upgrade older dynamic-column tables additively. Never drop or rewrite user data.
        $dynamicColumnMigrations = [
            'first_seen_batch_id' => "ALTER TABLE excel_dynamic_columns ADD COLUMN first_seen_batch_id BIGINT UNSIGNED NULL",
            'section_scope' => "ALTER TABLE excel_dynamic_columns ADD COLUMN section_scope ENUM('all','ap','online','booth','unknown') NOT NULL DEFAULT 'all'",
            'display_in_table' => "ALTER TABLE excel_dynamic_columns ADD COLUMN display_in_table TINYINT(1) NOT NULL DEFAULT 0",
            'use_in_filter' => "ALTER TABLE excel_dynamic_columns ADD COLUMN use_in_filter TINYINT(1) NOT NULL DEFAULT 0",
            'use_in_report' => "ALTER TABLE excel_dynamic_columns ADD COLUMN use_in_report TINYINT(1) NOT NULL DEFAULT 0",
            'use_in_chart' => "ALTER TABLE excel_dynamic_columns ADD COLUMN use_in_chart TINYINT(1) NOT NULL DEFAULT 0",
            'mapped_to_header' => "ALTER TABLE excel_dynamic_columns ADD COLUMN mapped_to_header VARCHAR(255) DEFAULT NULL",
            'updated_at' => "ALTER TABLE excel_dynamic_columns ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($dynamicColumnMigrations as $columnName => $ddl) {
            // MariaDB does not accept a bound parameter in SHOW COLUMNS ... LIKE ?.
            // Query information_schema instead, which supports prepared parameters.
            $columnCheck = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1'
            );
            $columnCheck->execute(['excel_dynamic_columns', $columnName]);
            if (!$columnCheck->fetchColumn()) $pdo->exec($ddl);
        }
        // Additive table for actual museum/Lounge usage events. It is intentionally
        // separate from imported membership-sale/application records.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `benefit_usage_records` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `member_number` VARCHAR(100) NOT NULL,
          `member_name` VARCHAR(255) NOT NULL,
          `benefit_area` ENUM('museum','lounge') NOT NULL,
          `used_at` DATETIME NOT NULL,
          `fiscal_year` SMALLINT UNSIGNED NOT NULL,
          `benefit_type` VARCHAR(255) DEFAULT NULL,
          `location` VARCHAR(255) DEFAULT NULL,
          `member_type` VARCHAR(255) DEFAULT NULL,
          `member_level` VARCHAR(255) DEFAULT NULL,
          `notes` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_benefit_usage_area_fy` (`benefit_area`,`fiscal_year`),
          KEY `idx_benefit_usage_member` (`member_number`),
          KEY `idx_benefit_usage_date` (`used_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    } catch (PDOException $e) {
        // MariaDB error 1932 means table metadata may exist while its storage-engine
        // table is unavailable. CREATE TABLE IF NOT EXISTS cannot safely repair it.
        $mysqlError = (int)($e->errorInfo[1] ?? 0);
        $errorText = $e->getMessage();
        if ($mysqlError === 1932 && preg_match('/excel_import_batches|excel_member_records|excel_dynamic_columns/i', $errorText)) {
            http_response_code(500);
            if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ตรวจพบปัญหาตาราง Excel</title>';
            echo '<style>body{font-family:Tahoma,Arial,sans-serif;background:#f4f7fb;color:#243247;margin:0;padding:24px;line-height:1.7}.panel{max-width:820px;margin:4vh auto;background:#fff;border:1px solid #dbe4ef;border-radius:14px;padding:24px;box-shadow:0 8px 28px rgba(20,40,70,.06)}h1{font-size:22px;margin-top:0;color:#8a341c}.note{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:14px}.steps{padding-left:22px}.muted{color:#64748b;font-size:14px}code{background:#f1f5f9;padding:2px 5px;border-radius:4px;overflow-wrap:anywhere}</style></head><body><main class="panel">';
            echo '<h1>พบปัญหาตารางฐานข้อมูลสำหรับนำเข้า Excel</h1>';
            echo '<p>MariaDB แจ้งรหัสข้อผิดพลาด <strong>1932</strong> ว่าตารางมีข้อมูลโครงสร้างอยู่ แต่ Storage Engine ไม่สามารถเปิดตารางได้ โค้ด <code>CREATE TABLE IF NOT EXISTS</code> จึงไม่สามารถซ่อมสถานะนี้ได้โดยอัตโนมัติ</p>';
            echo '<div class="note"><strong>เพื่อป้องกันข้อมูลสูญหาย กรุณาอย่าลบตาราง อย่าสร้างทับ และอย่านำเข้าไฟล์ setup ใหม่ทับฐานข้อมูลเดิม</strong></div>';
            echo '<h2>ขั้นตอนถัดไปที่ปลอดภัย</h2><ol class="steps"><li>หยุดการนำเข้า Excel ชั่วคราว</li><li>สำรองฐานข้อมูล <code>nsm_member</code> จาก phpMyAdmin ก่อนดำเนินการใด ๆ</li><li>เปิดไฟล์ <code>sql/diagnose_excel_import_tables.sql</code> ใน ZIP แล้วรันคำสั่งตรวจสอบทีละคำสั่งในฐานข้อมูล <code>nsm_member</code></li><li>เก็บผลลัพธ์ของ <code>information_schema</code>, <code>SHOW TABLE STATUS</code> และ <code>CHECK TABLE</code> เพื่อเลือกแนวทางซ่อมโดยไม่เดาว่าตารางว่างหรือเสียหาย</li></ol>';
            echo '<p class="muted">ระบบยังไม่ได้แก้ไขหรือลบข้อมูลใด ๆ เพื่อซ่อมตารางนี้ ข้อความทางเทคนิค: <code>' . htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8') . '</code></p>';
            echo '</main></body></html>';
            exit;
        }
        throw $e;
    }
}

function latest_excel_fiscal_year(PDO $pdo): int {
    $latest = (int)$pdo->query("SELECT COALESCE(MAX(fiscal_year),0) FROM excel_member_records WHERE fiscal_year IS NOT NULL")->fetchColumn();
    if ($latest >= 2500 && $latest <= 2700) return $latest;
    return ((int)date('n') >= 10) ? (int)date('Y') + 544 : (int)date('Y') + 543;
}

function fy_from_date_value(string $value): ?int {
    $date = parse_import_date($value);
    if (!$date) return null;
    $ts = strtotime($date);
    if (!$ts) return null;
    $y=(int)date('Y',$ts); $m=(int)date('n',$ts);
    return $m >= 10 ? $y + 544 : $y + 543;
}


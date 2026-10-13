```php
<?php

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/config/database.php';

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| เพิ่มข้อมูล
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        /*
        |--------------------------------------------------------------------------
        | เพิ่มอาคาร
        |--------------------------------------------------------------------------
        */

        if ($action === 'add_building') {

            $name = trim($_POST['name'] ?? '');

            if ($name === '') {
                throw new Exception('กรุณากรอกชื่ออาคาร');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM buildings
                WHERE name = ?
                LIMIT 1
            ");

            $stmt->execute([$name]);

            if ($stmt->fetch()) {
                throw new Exception('มีอาคารชื่อนี้อยู่ในระบบแล้ว');
            }

            $stmt = $pdo->prepare("
                INSERT INTO buildings (name, is_active)
                VALUES (?, 1)
            ");

            $stmt->execute([$name]);

            $message = 'เพิ่มอาคารเรียบร้อยแล้ว';
        }


        /*
        |--------------------------------------------------------------------------
        | แก้ไขอาคาร
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'edit_building') {

            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');

            if ($id <= 0) {
                throw new Exception('ไม่พบรหัสอาคาร');
            }

            if ($name === '') {
                throw new Exception('กรุณากรอกชื่ออาคาร');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM buildings
                WHERE name = ?
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->execute([$name, $id]);

            if ($stmt->fetch()) {
                throw new Exception('มีอาคารชื่อนี้อยู่ในระบบแล้ว');
            }

            $stmt = $pdo->prepare("
                UPDATE buildings
                SET name = ?
                WHERE id = ?
            ");

            $stmt->execute([$name, $id]);

            $message = 'แก้ไขชื่ออาคารเรียบร้อยแล้ว';
        }


        /*
        |--------------------------------------------------------------------------
        | เปิด / ปิด อาคาร
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'toggle_building') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT is_active
                FROM buildings
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $building = $stmt->fetch();

            if (!$building) {
                throw new Exception('ไม่พบข้อมูลอาคาร');
            }

            $newStatus = $building['is_active'] ? 0 : 1;

            $stmt = $pdo->prepare("
                UPDATE buildings
                SET is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([$newStatus, $id]);

            $message = $newStatus
                ? 'เปิดใช้งานอาคารเรียบร้อยแล้ว'
                : 'ปิดใช้งานอาคารเรียบร้อยแล้ว';
        }


        /*
        |--------------------------------------------------------------------------
        | เพิ่มตำแหน่ง
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'add_position') {

            $name = trim($_POST['name'] ?? '');

            if ($name === '') {
                throw new Exception('กรุณากรอกชื่อตำแหน่งงาน');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM positions
                WHERE name = ?
                LIMIT 1
            ");

            $stmt->execute([$name]);

            if ($stmt->fetch()) {
                throw new Exception('มีตำแหน่งนี้อยู่ในระบบแล้ว');
            }

            $stmt = $pdo->prepare("
                INSERT INTO positions (name, is_active)
                VALUES (?, 1)
            ");

            $stmt->execute([$name]);

            $message = 'เพิ่มตำแหน่งงานเรียบร้อยแล้ว';
        }


        /*
        |--------------------------------------------------------------------------
        | แก้ไขตำแหน่ง
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'edit_position') {

            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');

            if ($id <= 0) {
                throw new Exception('ไม่พบรหัสตำแหน่ง');
            }

            if ($name === '') {
                throw new Exception('กรุณากรอกชื่อตำแหน่งงาน');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM positions
                WHERE name = ?
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->execute([$name, $id]);

            if ($stmt->fetch()) {
                throw new Exception('มีตำแหน่งนี้อยู่ในระบบแล้ว');
            }

            $stmt = $pdo->prepare("
                UPDATE positions
                SET name = ?
                WHERE id = ?
            ");

            $stmt->execute([$name, $id]);

            $message = 'แก้ไขตำแหน่งงานเรียบร้อยแล้ว';
        }


        /*
        |--------------------------------------------------------------------------
        | เปิด / ปิดตำแหน่ง
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'toggle_position') {

            $id = (int)($_POST['id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT is_active
                FROM positions
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $position = $stmt->fetch();

            if (!$position) {
                throw new Exception('ไม่พบข้อมูลตำแหน่ง');
            }

            $newStatus = $position['is_active'] ? 0 : 1;

            $stmt = $pdo->prepare("
                UPDATE positions
                SET is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([$newStatus, $id]);

            $message = $newStatus
                ? 'เปิดใช้งานตำแหน่งเรียบร้อยแล้ว'
                : 'ปิดใช้งานตำแหน่งเรียบร้อยแล้ว';
        }

        /*
        |--------------------------------------------------------------------------
        | เพิ่มประเภท/รายการสมาชิกตามปี
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'add_member_item') {

            $memberFY = (int)($_POST['fiscal_year'] ?? 0);
            $categoryName = trim($_POST['category_name'] ?? '');
            $label = trim($_POST['label'] ?? '');

            if ($memberFY < 2500 || $memberFY > 2700) {
                throw new Exception('ปีงบประมาณไม่ถูกต้อง');
            }
            if ($categoryName === '') {
                throw new Exception('กรุณากรอกประเภทสมาชิก');
            }
            if ($label === '') {
                throw new Exception('กรุณากรอกรายการสมาชิก');
            }

            // ใช้หัวข้อใหญ่เดิมได้ เพื่อเพิ่มรายการย่อยหลายรายการในหัวข้อเดียว
            $catStmt = $pdo->prepare("
                SELECT category_code
                FROM member_input_items
                WHERE fiscal_year = ?
                  AND category_name = ?
                LIMIT 1
            ");
            $catStmt->execute([$memberFY, $categoryName]);
            $categoryCode = $catStmt->fetchColumn();

            if (!$categoryCode) {
                $categoryCode = 'cat_' . substr(sha1($memberFY . '|' . $categoryName), 0, 12);
            }

            $base = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($categoryName . '_' . $label));
            $base = trim($base, '_');
            if ($base === '') $base = 'member_item';
            $base = substr($base, 0, 55);

            $itemCode = $base;
            $suffix = 1;
            $check = $pdo->prepare('SELECT COUNT(*) FROM member_input_items WHERE fiscal_year=? AND item_code=?');
            while (true) {
                $check->execute([$memberFY, $itemCode]);
                if ((int)$check->fetchColumn() === 0) break;
                $itemCode = substr($base, 0, 55) . '_' . $suffix++;
            }

            $orderStmt = $pdo->prepare("
                SELECT COALESCE(MAX(sort_order),0)+10
                FROM member_input_items
                WHERE fiscal_year=? AND category_code=?
            ");
            $orderStmt->execute([$memberFY, $categoryCode]);
            $sortOrder = (int)$orderStmt->fetchColumn();

            // settings จัดการเฉพาะโครงสร้าง ส่วนราคา/วิธีคำนวณให้จัดการที่ member_prices.php
            $stmt = $pdo->prepare("
                INSERT INTO member_input_items
                (fiscal_year,item_code,category_code,category_name,label,rate,calculation_type,sort_order,is_active)
                VALUES (?,?,?,?,?,0,'fixed_rate',?,?,1)
            ");
            $stmt->execute([
                $memberFY,
                $itemCode,
                $categoryCode,
                $categoryName,
                $label,
                $sortOrder
            ]);

            $message = 'เพิ่มรายการสมาชิกเรียบร้อยแล้ว สามารถตั้งราคาและวิธีคำนวณได้ที่เมนูราคาสมาชิก';
        }

        elseif ($action === 'edit_member_item') {

            $id = (int)($_POST['id'] ?? 0);
            $memberFY = (int)($_POST['fiscal_year'] ?? 0);
            $categoryName = trim($_POST['category_name'] ?? '');
            $label = trim($_POST['label'] ?? '');

            if ($id <= 0 || $memberFY < 2500 || $memberFY > 2700) {
                throw new Exception('ข้อมูลสมาชิกไม่ถูกต้อง');
            }
            if ($categoryName === '' || $label === '') {
                throw new Exception('กรุณากรอกประเภทและรายการสมาชิกให้ครบ');
            }

            // ถ้าเปลี่ยนชื่อหัวข้อใหญ่ ให้ใช้ category_code เดิมของหัวข้อนั้น
            // หรือสร้าง code ใหม่หากเป็นหัวข้อใหม่ โดยไม่แตะ item_code เพื่อรักษาประวัติข้อมูล
            $catStmt = $pdo->prepare("
                SELECT category_code
                FROM member_input_items
                WHERE fiscal_year = ?
                  AND category_name = ?
                LIMIT 1
            ");
            $catStmt->execute([$memberFY, $categoryName]);
            $existingCategoryCode = $catStmt->fetchColumn();

            if ($existingCategoryCode) {
                $categoryCode = $existingCategoryCode;
            } else {
                $categoryCode = 'cat_' . substr(sha1($memberFY . '|' . $categoryName), 0, 12);
            }

            $stmt = $pdo->prepare("
                UPDATE member_input_items
                SET category_code = ?, category_name = ?, label = ?
                WHERE id = ? AND fiscal_year = ?
            ");
            $stmt->execute([
                $categoryCode,
                $categoryName,
                $label,
                $id,
                $memberFY
            ]);

            $message = 'แก้ไขโครงสร้างรายการสมาชิกเรียบร้อยแล้ว';
        }

        elseif ($action === 'toggle_member_item') {

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT is_active FROM member_input_items WHERE id=? LIMIT 1');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if (!$item) throw new Exception('ไม่พบรายการสมาชิก');

            $newStatus = ((int)$item['is_active'] === 1) ? 0 : 1;
            $stmt = $pdo->prepare('UPDATE member_input_items SET is_active=? WHERE id=?');
            $stmt->execute([$newStatus,$id]);
            $message = $newStatus ? 'เปิดใช้งานรายการสมาชิกแล้ว' : 'ปิดใช้งานรายการสมาชิกแล้ว';
        }

    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| อาคาร
|--------------------------------------------------------------------------
*/

$buildings = $pdo->query("
    SELECT
        b.id,
        b.name,
        b.is_active,
        b.created_at,
        COUNT(r.id) AS work_count,
        COALESCE(SUM(r.daily_sales), 0) AS total_sales
    FROM buildings b

    LEFT JOIN employee_work_records r
        ON r.building_id = b.id

    GROUP BY
        b.id,
        b.name,
        b.is_active,
        b.created_at

    ORDER BY
        b.is_active DESC,
        b.name ASC
")->fetchAll();


/*
|--------------------------------------------------------------------------
| ตำแหน่ง
|--------------------------------------------------------------------------
*/

$positions = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.is_active,
        p.created_at,
        COUNT(r.id) AS work_count
    FROM positions p

    LEFT JOIN employee_work_records r
        ON r.position_id = p.id

    GROUP BY
        p.id,
        p.name,
        p.is_active,
        p.created_at

    ORDER BY
        p.is_active DESC,
        p.name ASC
")->fetchAll();


/*
|--------------------------------------------------------------------------
| ประเภท/รายการสมาชิก
|--------------------------------------------------------------------------
*/

$memberFY = isset($_GET['member_fy']) ? (int)$_GET['member_fy'] : 2569;
if ($memberFY < 2500 || $memberFY > 2700) $memberFY = 2569;

$memberYears = range(max($memberFY, 2569), 2569 - 10, -1);
if (!in_array($memberFY, $memberYears, true)) $memberYears[] = $memberFY;
$memberYears = array_values(array_unique($memberYears));
rsort($memberYears, SORT_NUMERIC);

$memberStmt = $pdo->prepare('SELECT * FROM member_input_items WHERE fiscal_year=? ORDER BY category_code,sort_order,id');
$memberStmt->execute([$memberFY]);
$memberItems = $memberStmt->fetchAll();

$memberCategories = [];
foreach ($memberItems as $memberItem) {
    $memberCategories[$memberItem['category_code']][] = $memberItem;
}

/*
|--------------------------------------------------------------------------
| สรุป
|--------------------------------------------------------------------------
*/

$activeBuildings = 0;
$inactiveBuildings = 0;

foreach ($buildings as $building) {

    if ((int)$building['is_active'] === 1) {
        $activeBuildings++;
    } else {
        $inactiveBuildings++;
    }
}


$activePositions = 0;
$inactivePositions = 0;

foreach ($positions as $position) {

    if ((int)$position['is_active'] === 1) {
        $activePositions++;
    } else {
        $inactivePositions++;
    }
}


function money($value)
{
    return number_format((float)$value, 2);
}

?>

<!doctype html>

<html lang="th">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        ตั้งค่าระบบ - NSM Member Club
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css?v=10"
    >

    <style>

        .page-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .section-subtitle {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .summary-box {
            background: #ffffff;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.05);
        }

        .summary-label {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .summary-number {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
        }

        .blue {
            border-left: 4px solid #2563eb;
        }

        .green {
            border-left: 4px solid #16a34a;
        }

        .red {
            border-left: 4px solid #dc2626;
        }

        .orange {
            border-left: 4px solid #f59e0b;
        }

        .add-form {
            display: flex;
            gap: 10px;
            align-items: end;
            margin-bottom: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
            flex: 1;
        }

        .form-label {
            font-weight: 600;
            color: #334155;
        }

        .form-control {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 13px;
            border: 1px solid #dbe1ea;
            border-radius: 10px;
            font-size: 15px;
            background: #fff;
            outline: none;
        }

        .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.10);
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 11px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-success {
            background: #16a34a;
            color: white;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-light {
            background: #eef2f7;
            color: #334155;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .data-table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        .data-table th {
            text-align: left;
            padding: 13px 12px;
            background: #f8fafc;
            color: #475569;
            font-size: 14px;
            border-bottom: 1px solid #e2e8f0;
        }

        .data-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #eef2f7;
            color: #334155;
            font-size: 14px;
        }

        .data-table tr:hover {
            background: #fafcff;
        }

        .text-right {
            text-align: right !important;
        }

        .text-center {
            text-align: center !important;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #dcfce7;
            color: #15803d;
        }

        .status-inactive {
            background: #fee2e2;
            color: #b91c1c;
        }

        .action-buttons {
            display: flex;
            gap: 7px;
        }

        .action-buttons form {
            margin: 0;
        }

        .empty-state {
            text-align: center;
            padding: 35px;
            color: #94a3b8;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(15,23,42,0.45);
            align-items: center;
            justify-content: center;
        }

        .modal-box {
            width: 90%;
            max-width: 450px;
            background: #ffffff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #1e293b;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        @media (max-width: 1000px) {

            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media (max-width: 700px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .add-form {
                flex-direction: column;
                align-items: stretch;
            }

        }


        .member-settings-toolbar { display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:20px; }
        .member-settings-toolbar .form-group { min-width:170px; flex:0 0 auto; }
        .member-settings-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:18px; }
        .member-settings-box { border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; background:#fff; }
        .member-settings-box-header { padding:15px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:10px; align-items:center; }
        .member-settings-box-header strong { color:#1e293b; }
        .member-item-row { display:grid; grid-template-columns:1fr 105px 145px auto; gap:10px; align-items:center; padding:12px 14px; border-bottom:1px solid #eef2f7; }
        .member-item-row:last-child { border-bottom:0; }
        .member-item-label { font-size:13px; font-weight:600; color:#334155; }
        .member-item-meta { font-size:11px; color:#94a3b8; margin-top:3px; }
        .member-inline-input,.member-inline-select { width:100%; box-sizing:border-box; padding:8px 9px; border:1px solid #dbe1ea; border-radius:8px; font-size:12px; background:#fff; }
        .member-add-grid { display:grid; grid-template-columns:1fr 1fr 120px 170px auto; gap:10px; align-items:end; }
        .member-action-form { display:flex; gap:6px; align-items:center; }
        .member-structure-meta{font-size:12px;color:#475569;background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:8px 10px;}
        .btn-small { padding:8px 10px; font-size:12px; }
        @media(max-width:1100px){ .member-settings-grid{grid-template-columns:1fr}.member-item-row{grid-template-columns:1fr 100px}.member-item-row .member-action-form{grid-column:1/-1}.member-add-grid{grid-template-columns:1fr 1fr}.member-add-grid button{grid-column:1/-1} }
        @media(max-width:650px){ .member-add-grid{grid-template-columns:1fr}.member-settings-toolbar .form-group{width:100%} }

    </style>

</head>


<body>


<!-- SIDEBAR -->

<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'settings.php'); require __DIR__ . '/includes/navigation.php'; ?>


<!-- MAIN -->

<main class="main">


    <header class="topbar">

        <div>

            <h1>
                ตั้งค่าระบบ
            </h1>

            <p>
                จัดการข้อมูลพื้นฐานที่ใช้ในระบบ
            </p>

        </div>


        
        </div>

    </header>


    <section class="content">


        <!-- ALERT -->

        <?php if ($message): ?>

            <div class="alert alert-success">
                ✅ <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>


        <?php if ($error): ?>

            <div class="alert alert-error">
                ❌ <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <!-- EXCEL HEADER SETTINGS SHORTCUT -->
        <div class="page-card excel-settings-shortcut" style="display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:18px;">
            <div style="display:flex;align-items:flex-start;gap:14px;min-width:240px;flex:1;">
                <div style="width:46px;height:46px;border-radius:12px;background:#eef5ff;color:#2458a6;display:flex;align-items:center;justify-content:center;font-size:23px;flex-shrink:0;">🧾</div>
                <div>
                    <div class="section-title" style="margin:0 0 5px;">จัดการหัวข้อ Excel</div>
                    <div class="section-subtitle">ลงทะเบียนหัวข้อใหม่ล่วงหน้า จับคู่ชื่อคอลัมน์ และตรวจหัวข้อที่พบจากไฟล์นำเข้า</div>
                </div>
            </div>
            <a class="btn btn-primary" href="excel_fields.php">เปิดตั้งค่าหัวข้อ Excel →</a>
        </div>

        <!-- SUMMARY -->

        <div class="summary-grid">


            <div class="summary-box blue">

                <div class="summary-label">
                    อาคารที่ใช้งาน
                </div>

                <div class="summary-number">
                    <?= number_format($activeBuildings) ?>
                </div>

            </div>


            <div class="summary-box red">

                <div class="summary-label">
                    อาคารปิดใช้งาน
                </div>

                <div class="summary-number">
                    <?= number_format($inactiveBuildings) ?>
                </div>

            </div>


            <div class="summary-box green">

                <div class="summary-label">
                    ตำแหน่งที่ใช้งาน
                </div>

                <div class="summary-number">
                    <?= number_format($activePositions) ?>
                </div>

            </div>


            <div class="summary-box orange">

                <div class="summary-label">
                    ตำแหน่งปิดใช้งาน
                </div>

                <div class="summary-number">
                    <?= number_format($inactivePositions) ?>
                </div>

            </div>


        </div>


        <!-- BUILDINGS -->

        <section class="page-card">

            <div class="section-title">
                🏢 จัดการอาคาร
            </div>

            <div class="section-subtitle">
                เพิ่ม แก้ไข และจัดการอาคารที่ใช้สำหรับบันทึกการปฏิบัติงาน
            </div>


            <form
                method="post"
                class="add-form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="add_building"
                >


                <div class="form-group">

                    <label class="form-label">
                        ชื่ออาคาร
                    </label>

                    <input
                        type="text"
                        name="name"
                        class="form-control"
                        placeholder="เช่น อาคารพิพิธภัณฑ์วิทยาศาสตร์"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    ➕ เพิ่มอาคาร
                </button>

            </form>


            <div class="data-table-wrapper">

                <?php if (count($buildings) > 0): ?>

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th>
                                    อาคาร
                                </th>

                                <th class="text-center">
                                    สถานะ
                                </th>

                                <th class="text-right">
                                    ปฏิบัติงาน
                                </th>

                                <th class="text-right">
                                    ยอดขายรวม
                                </th>

                                <th>
                                    จัดการ
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($buildings as $building): ?>

                                <tr>

                                    <td>

                                        <strong>
                                            <?= htmlspecialchars($building['name']) ?>
                                        </strong>

                                        <div style="font-size:12px;color:#94a3b8;">
                                            ID #<?= (int)$building['id'] ?>
                                        </div>

                                    </td>


                                    <td class="text-center">

                                        <?php if ((int)$building['is_active'] === 1): ?>

                                            <span class="status status-active">
                                                ● ใช้งาน
                                            </span>

                                        <?php else: ?>

                                            <span class="status status-inactive">
                                                ● ปิดใช้งาน
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-right">

                                        <?= number_format((int)$building['work_count']) ?>
                                        ครั้ง

                                    </td>


                                    <td class="text-right">

                                        ฿<?= money($building['total_sales']) ?>

                                    </td>


                                    <td>

                                        <div class="action-buttons">


                                            <button
                                                type="button"
                                                class="btn btn-warning"
                                                onclick="openBuildingModal(
                                                    <?= (int)$building['id'] ?>,
                                                    <?= htmlspecialchars(json_encode($building['name'], JSON_UNESCAPED_UNICODE)) ?>
                                                )"
                                            >
                                                ✏️ แก้ไข
                                            </button>


                                            <form
                                                method="post"
                                                onsubmit="return confirmToggle(
                                                    <?= (int)$building['is_active'] ?>
                                                )"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle_building"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$building['id'] ?>"
                                                >


                                                <?php if ((int)$building['is_active'] === 1): ?>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-danger"
                                                    >
                                                        ปิดใช้งาน
                                                    </button>

                                                <?php else: ?>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-success"
                                                    >
                                                        เปิดใช้งาน
                                                    </button>

                                                <?php endif; ?>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty-state">
                        ยังไม่มีข้อมูลอาคาร
                    </div>

                <?php endif; ?>

            </div>

        </section>



        <!-- รายการประเภทสมาชิกเดิมแยกออกจากหน้าตั้งค่าหลักแล้ว; เก็บ backend/data เดิมไว้เพื่อความปลอดภัย -->

        <!-- POSITIONS -->

        <section class="page-card">

            <div class="section-title">
                💼 จัดการตำแหน่งงาน
            </div>

            <div class="section-subtitle">
                ตำแหน่งงานจะถูกเลือกจากรายการนี้ในหน้าบันทึกการปฏิบัติงาน
            </div>


            <form
                method="post"
                class="add-form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="add_position"
                >


                <div class="form-group">

                    <label class="form-label">
                        ชื่อตำแหน่งงาน
                    </label>

                    <input
                        type="text"
                        name="name"
                        class="form-control"
                        placeholder="เช่น เจ้าหน้าที่สมาชิก"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    ➕ เพิ่มตำแหน่ง
                </button>

            </form>


            <div class="data-table-wrapper">

                <?php if (count($positions) > 0): ?>

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th>
                                    ตำแหน่งงาน
                                </th>

                                <th class="text-center">
                                    สถานะ
                                </th>

                                <th class="text-right">
                                    ปฏิบัติงาน
                                </th>

                                <th>
                                    จัดการ
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($positions as $position): ?>

                                <tr>

                                    <td>

                                        <strong>
                                            <?= htmlspecialchars($position['name']) ?>
                                        </strong>

                                        <div style="font-size:12px;color:#94a3b8;">
                                            ID #<?= (int)$position['id'] ?>
                                        </div>

                                    </td>


                                    <td class="text-center">

                                        <?php if ((int)$position['is_active'] === 1): ?>

                                            <span class="status status-active">
                                                ● ใช้งาน
                                            </span>

                                        <?php else: ?>

                                            <span class="status status-inactive">
                                                ● ปิดใช้งาน
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-right">

                                        <?= number_format((int)$position['work_count']) ?>
                                        ครั้ง

                                    </td>


                                    <td>

                                        <div class="action-buttons">


                                            <button
                                                type="button"
                                                class="btn btn-warning"
                                                onclick="openPositionModal(
                                                    <?= (int)$position['id'] ?>,
                                                    <?= htmlspecialchars(json_encode($position['name'], JSON_UNESCAPED_UNICODE)) ?>
                                                )"
                                            >
                                                ✏️ แก้ไข
                                            </button>


                                            <form
                                                method="post"
                                                onsubmit="return confirmToggle(
                                                    <?= (int)$position['is_active'] ?>
                                                )"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="toggle_position"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= (int)$position['id'] ?>"
                                                >


                                                <?php if ((int)$position['is_active'] === 1): ?>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-danger"
                                                    >
                                                        ปิดใช้งาน
                                                    </button>

                                                <?php else: ?>

                                                    <button
                                                        type="submit"
                                                        class="btn btn-success"
                                                    >
                                                        เปิดใช้งาน
                                                    </button>

                                                <?php endif; ?>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty-state">
                        ยังไม่มีข้อมูลตำแหน่งงาน
                    </div>

                <?php endif; ?>

            </div>

        </section>


        <div class="footer">
            NSM Member Club Management System · System Settings
        </div>


    </section>

</main>


<!-- BUILDING MODAL -->

<div
    id="buildingModal"
    class="modal"
>

    <div class="modal-box">

        <div class="modal-title">
            ✏️ แก้ไขอาคาร
        </div>


        <form method="post">

            <input
                type="hidden"
                name="action"
                value="edit_building"
            >

            <input
                type="hidden"
                name="id"
                id="building_id"
            >


            <div class="form-group">

                <label class="form-label">
                    ชื่ออาคาร
                </label>

                <input
                    type="text"
                    name="name"
                    id="building_name"
                    class="form-control"
                    required
                >

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-light"
                    onclick="closeBuildingModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    💾 บันทึก
                </button>

            </div>

        </form>

    </div>

</div>


<!-- POSITION MODAL -->

<div
    id="positionModal"
    class="modal"
>

    <div class="modal-box">

        <div class="modal-title">
            ✏️ แก้ไขตำแหน่งงาน
        </div>


        <form method="post">

            <input
                type="hidden"
                name="action"
                value="edit_position"
            >

            <input
                type="hidden"
                name="id"
                id="position_id"
            >


            <div class="form-group">

                <label class="form-label">
                    ชื่อตำแหน่งงาน
                </label>

                <input
                    type="text"
                    name="name"
                    id="position_name"
                    class="form-control"
                    required
                >

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-light"
                    onclick="closePositionModal()"
                >
                    ยกเลิก
                </button>


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    💾 บันทึก
                </button>

            </div>

        </form>

    </div>

</div>


<script>

function openBuildingModal(id, name)
{
    document.getElementById('building_id').value = id;
    document.getElementById('building_name').value = name;

    document.getElementById('buildingModal').style.display = 'flex';
}


function closeBuildingModal()
{
    document.getElementById('buildingModal').style.display = 'none';
}


function openPositionModal(id, name)
{
    document.getElementById('position_id').value = id;
    document.getElementById('position_name').value = name;

    document.getElementById('positionModal').style.display = 'flex';
}


function closePositionModal()
{
    document.getElementById('positionModal').style.display = 'none';
}


function confirmToggle(isActive)
{
    if (isActive) {

        return confirm(
            'ต้องการปิดใช้งานรายการนี้หรือไม่?'
        );

    }

    return confirm(
        'ต้องการเปิดใช้งานรายการนี้หรือไม่?'
    );
}


window.addEventListener('click', function(event)
{
    const buildingModal =
        document.getElementById('buildingModal');

    const positionModal =
        document.getElementById('positionModal');


    if (event.target === buildingModal) {
        closeBuildingModal();
    }


    if (event.target === positionModal) {
        closePositionModal();
    }
});

</script>


</body>

</html>
```

<?php

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/config/database.php';

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| บันทึกข้อมูล
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $work_date = $_POST['work_date'] ?? '';
    $employee_name_input = trim($_POST['employee_name'] ?? '');
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $position_id = (int)($_POST['position_id'] ?? 0);
    $building_id = (int)($_POST['building_id'] ?? 0);
    $member_notifications = (int)($_POST['member_notifications'] ?? 0);
    $daily_sales = (float)($_POST['daily_sales'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {

        if (!$work_date) {
            throw new Exception('กรุณาเลือกวันที่ปฏิบัติงาน');
        }

        if ($employee_name_input === '') {
            throw new Exception('กรุณากรอกชื่อพนักงาน');
        }

        if ($position_id <= 0) {
            throw new Exception('กรุณาเลือกตำแหน่งงาน');
        }

        if ($building_id <= 0) {
            throw new Exception('กรุณาเลือกอาคาร');
        }

        /*
        |--------------------------------------------------------------------------
        | ดึงข้อมูล Snapshot
        |--------------------------------------------------------------------------
        */

        // พนักงานเป็นผู้กรอกชื่อเอง: ถ้าชื่อตรงกับทะเบียนพนักงานให้ผูก employee_id
        // แต่ถ้าไม่ตรง ยังบันทึกชื่อที่กรอกได้ เพื่อไม่ให้ข้อมูลการทำงานหาย
        $stmt = $pdo->prepare("
            SELECT id, name
            FROM employees
            WHERE name = ?
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$employee_name_input]);
        $employee = $stmt->fetch();

        if ($employee) {
            $employee_id = (int)$employee['id'];
            $employee_name_snapshot = $employee['name'];
        } else {
            $employee_id = 0;
            $employee_name_snapshot = $employee_name_input;
        }


        $stmt = $pdo->prepare("
            SELECT id, name
            FROM positions
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([$position_id]);
        $position = $stmt->fetch();

        if (!$position) {
            throw new Exception('ไม่พบข้อมูลตำแหน่งงาน');
        }


        $stmt = $pdo->prepare("
            SELECT id, name
            FROM buildings
            WHERE id = ?
              AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([$building_id]);
        $building = $stmt->fetch();

        if (!$building) {
            throw new Exception('ไม่พบข้อมูลอาคาร');
        }


        /*
        |--------------------------------------------------------------------------
        | บันทึกข้อมูล
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO employee_work_records (
                work_date,
                employee_id,
                employee_name_snapshot,
                position_id,
                position_snapshot,
                building_id,
                building_snapshot,
                member_notifications,
                daily_sales,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $work_date,
            $employee_id,
            $employee_name_snapshot,
            $position['id'],
            $position['name'],
            $building['id'],
            $building['name'],
            $member_notifications,
            $daily_sales,
            $notes !== '' ? $notes : null
        ]);

        $message = 'บันทึกข้อมูลการปฏิบัติงานเรียบร้อยแล้ว';

    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| ดึงข้อมูลสำหรับ Dropdown
|--------------------------------------------------------------------------
*/

$employees = $pdo->query("
    SELECT id, name
    FROM employees
    WHERE is_active = 1
    ORDER BY name
")->fetchAll();


$positions = $pdo->query("
    SELECT id, name
    FROM positions
    WHERE is_active = 1
    ORDER BY name
")->fetchAll();


$buildings = $pdo->query("
    SELECT id, name
    FROM buildings
    WHERE is_active = 1
    ORDER BY name
")->fetchAll();


/*
|--------------------------------------------------------------------------
| ดึงรายการล่าสุด
|--------------------------------------------------------------------------
*/

$recentRecords = $pdo->query("
    SELECT
        id,
        work_date,
        employee_name_snapshot,
        position_snapshot,
        building_snapshot,
        member_notifications,
        daily_sales,
        notes
    FROM employee_work_records
    ORDER BY work_date DESC, id DESC
    LIMIT 20
")->fetchAll();


/*
|--------------------------------------------------------------------------
| ฟังก์ชัน
|--------------------------------------------------------------------------
*/

function money($value)
{
    return number_format((float)$value, 2);
}

function thaiDate($date)
{
    if (!$date) {
        return '';
    }

    $timestamp = strtotime($date);

    $months = [
        1 => 'ม.ค.',
        2 => 'ก.พ.',
        3 => 'มี.ค.',
        4 => 'เม.ย.',
        5 => 'พ.ค.',
        6 => 'มิ.ย.',
        7 => 'ก.ค.',
        8 => 'ส.ค.',
        9 => 'ก.ย.',
        10 => 'ต.ค.',
        11 => 'พ.ย.',
        12 => 'ธ.ค.'
    ];

    $day = date('j', $timestamp);
    $month = $months[(int)date('n', $timestamp)];
    $year = (int)date('Y', $timestamp) + 543;

    return $day . ' ' . $month . ' ' . $year;
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

    <title>บันทึกการปฏิบัติงาน - NSM Member Club</title>

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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            font-weight: 600;
            color: #334155;
        }

        .form-control {
            width: 100%;
            box-sizing: border-box;
            padding: 12px 14px;
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

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-secondary {
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

        .data-table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
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
            text-align: right;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }

        .required {
            color: #ef4444;
        }

        @media (max-width: 900px) {

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

        }

    </style>

</head>

<body class="work-entry-page">


<!-- SIDEBAR -->

<?php $activePage = basename($_SERVER['SCRIPT_NAME'] ?? 'work_record.php'); require __DIR__ . '/includes/navigation.php'; ?>


<!-- MAIN -->

<main class="main">


    <!-- TOPBAR -->

    <header class="topbar">

        <div>

            <h1>
                บันทึกการปฏิบัติงาน
            </h1>

            <p>
                บันทึกข้อมูลการทำงานของพนักงานในแต่ละวัน
            </p>

        </div>



        </div>

    </header>


    <section class="content">


        <!-- MESSAGE -->

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


        <!-- FORM -->

        <section class="page-card entry-card">

            <div class="section-title">
                👨‍💼 บันทึกข้อมูลการปฏิบัติงาน
            </div>

            <div class="section-subtitle">
                พนักงานกรอกข้อมูลของตนเอง พร้อมระบุวันที่ ตำแหน่ง อาคาร และผลงาน
            </div>

            <div class="mobile-only-note" style="background:#eef6ff;color:#315b8a;border-radius:10px;padding:10px 12px;font-size:12px;line-height:1.55;margin-bottom:16px;">📱 แบบฟอร์มนี้ออกแบบสำหรับมือถือ สามารถเปิดจากลิงก์ที่ส่งใน LINE แล้วกรอกได้ทันที</div>


            <form method="post">


                <div class="form-grid">


                    <!-- DATE -->

                    <div class="form-group">

                        <label class="form-label">
                            วันที่ปฏิบัติงาน
                            <span class="required">*</span>
                        </label>

                        <input
                            type="date"
                            name="work_date"
                            class="form-control"
                            value="<?= htmlspecialchars($_POST['work_date'] ?? date('Y-m-d')) ?>"
                            required
                        >

                    </div>


                    <!-- EMPLOYEE -->

                    <div class="form-group">

                        <label class="form-label">
                            ชื่อพนักงาน
                            <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            name="employee_name"
                            class="form-control"
                            list="employeeNames"
                            value="<?= htmlspecialchars($_POST['employee_name'] ?? '') ?>"
                            placeholder="กรอกชื่อ-นามสกุลของตนเอง"
                            autocomplete="name"
                            required
                        >

                        <datalist id="employeeNames">
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= htmlspecialchars($employee['name']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <small class="helper-text" style="color:#64748b;">กรอกชื่อของตนเองได้เลย ระบบจะจับคู่กับทะเบียนพนักงานให้อัตโนมัติเมื่อชื่อตรงกัน</small>

                    </div>


                    <!-- POSITION -->

                    <div class="form-group">

                        <label class="form-label">
                            ตำแหน่งงาน
                            <span class="required">*</span>
                        </label>

                        <select
                            name="position_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                -- เลือกตำแหน่ง --
                            </option>

                            <?php foreach ($positions as $position): ?>

                                <option
                                    value="<?= $position['id'] ?>"
                                    <?= ((int)($_POST['position_id'] ?? 0) === (int)$position['id']) ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($position['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- BUILDING -->

                    <div class="form-group">

                        <label class="form-label">
                            อาคารที่ปฏิบัติงาน
                            <span class="required">*</span>
                        </label>

                        <select
                            name="building_id"
                            class="form-control"
                            required
                        >

                            <option value="">
                                -- เลือกอาคาร --
                            </option>

                            <?php foreach ($buildings as $building): ?>

                                <option
                                    value="<?= $building['id'] ?>"
                                    <?= ((int)($_POST['building_id'] ?? 0) === (int)$building['id']) ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($building['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- NOTIFICATIONS -->

                    <div class="form-group">

                        <label class="form-label">
                            จำนวนสมาชิกที่แจ้งข่าวสาร
                        </label>

                        <input
                            type="number"
                            name="member_notifications"
                            class="form-control"
                            min="0"
                            value="<?= htmlspecialchars($_POST['member_notifications'] ?? '0') ?>"
                        >

                    </div>


                    <!-- SALES -->

                    <div class="form-group">

                        <label class="form-label">
                            ยอดขายประจำวัน (บาท)
                        </label>

                        <input
                            type="number"
                            name="daily_sales"
                            class="form-control"
                            min="0"
                            step="0.01"
                            value="<?= htmlspecialchars($_POST['daily_sales'] ?? '0') ?>"
                        >

                    </div>


                    <!-- NOTES -->

                    <div class="form-group full">

                        <label class="form-label">
                            หมายเหตุ
                        </label>

                        <textarea
                            name="notes"
                            class="form-control"
                            placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"
                        ><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>

                    </div>


                </div>


                <div class="form-actions">

                    <button
                        type="reset"
                        class="btn btn-secondary"
                    >
                        ล้างข้อมูล
                    </button>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        💾 บันทึกข้อมูล
                    </button>

                </div>

            </form>

        </section>


        <!-- RECENT RECORDS -->

        <section class="page-card recent-card">

            <div class="section-title">
                📋 รายการปฏิบัติงานล่าสุด
            </div>

            <div class="section-subtitle">
                แสดงข้อมูลล่าสุด 20 รายการ
            </div>


            <div class="data-table-wrapper">

                <?php if (count($recentRecords) > 0): ?>

                    <table class="data-table">

                        <thead>

                            <tr>

                                <th>
                                    วันที่
                                </th>

                                <th>
                                    พนักงาน
                                </th>

                                <th>
                                    ตำแหน่ง
                                </th>

                                <th>
                                    อาคาร
                                </th>

                                <th class="text-right">
                                    แจ้งข่าว
                                </th>

                                <th class="text-right">
                                    ยอดขาย
                                </th>

                                <th>
                                    หมายเหตุ
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($recentRecords as $record): ?>

                                <tr>

                                    <td>
                                        <?= thaiDate($record['work_date']) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= htmlspecialchars($record['employee_name_snapshot']) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($record['position_snapshot'] ?? '-') ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($record['building_snapshot'] ?? '-') ?>
                                    </td>

                                    <td class="text-right">
                                        <?= number_format((int)$record['member_notifications']) ?>
                                    </td>

                                    <td class="text-right">
                                        ฿<?= money($record['daily_sales']) ?>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars($record['notes'] ?? '-') ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div class="empty-state">

                        ยังไม่มีข้อมูลการปฏิบัติงาน

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <div class="footer">

            NSM Member Club Management System · Employee Work Record

        </div>


    </section>

</main>


</body>

</html>

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$companyId = attendly_company_id();
$user = attendly_user();

if (!$companyId) {
    exit('Company tidak ditemukan. Silakan login kembali.');
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectPage($params = []) {
    $url = 'karyawan.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

$success = '';
$error = '';

/* =========================================================
   PROSES SIMPAN KARYAWAN
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'save_employee') {

            $id = (int)($_POST['employee_id'] ?? 0);
            $nik = trim($_POST['nik'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $joinDate = trim($_POST['join_date'] ?? '');
            $departmentId = (int)($_POST['department_id'] ?? 0);
            $positionId = (int)($_POST['position_id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $cardUid = strtoupper(trim($_POST['card_uid'] ?? ''));

            $photoName = null;
            $uploadedPhoto = null;

            if ($nik === '' || $name === '') {
                $error = 'NIK dan nama karyawan wajib diisi.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Format email tidak valid.';
            } elseif (!in_array($gender, ['Laki-laki', 'Perempuan'], true)) {
                $error = 'Jenis kelamin tidak valid.';
            } elseif (!in_array($status, ['active', 'inactive'], true)) {
                $error = 'Status karyawan tidak valid.';
            } elseif ($joinDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $joinDate)) {
                $error = 'Format tanggal masuk tidak valid.';
            }

            /* Validasi departemen */
            if (!$error && $departmentId > 0) {
                $check = $pdo->prepare("
                    SELECT id FROM departments
                    WHERE id = ? AND company_id = ?
                    LIMIT 1
                ");
                $check->execute([$departmentId, $companyId]);

                if (!$check->fetch()) {
                    $error = 'Departemen tidak ditemukan.';
                }
            }

            /* Validasi jabatan */
            if (!$error && $positionId > 0) {
                $check = $pdo->prepare("
                    SELECT id FROM positions
                    WHERE id = ? AND company_id = ?
                    LIMIT 1
                ");
                $check->execute([$positionId, $companyId]);

                if (!$check->fetch()) {
                    $error = 'Jabatan tidak ditemukan.';
                }
            }

            /* Validasi foto */
            if (!$error && isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {

                if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Upload foto gagal.';
                } elseif ($_FILES['photo']['size'] > 1024 * 1024) {
                    $error = 'Ukuran foto maksimal 1 MB.';
                } else {
                    $allowedMime = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp'
                    ];

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($_FILES['photo']['tmp_name']);

                    if (!isset($allowedMime[$mime])) {
                        $error = 'Format foto harus JPG, PNG, atau WebP.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads/employees/';

                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        $photoName = bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];
                        $uploadedPhoto = $uploadDir . $photoName;

                        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadedPhoto)) {
                            $error = 'Tidak dapat menyimpan foto.';
                        }
                    }
                }
            }

            if (!$error) {
                try {
                    $pdo->beginTransaction();

                    /* Periksa NIK duplikat */
                    $duplicate = $pdo->prepare("
                        SELECT id FROM employees
                        WHERE company_id = ?
                        AND nik = ?
                        AND id != ?
                        LIMIT 1
                    ");
                    $duplicate->execute([$companyId, $nik, $id]);

                    if ($duplicate->fetch()) {
                        throw new Exception('NIK sudah digunakan oleh karyawan lain.');
                    }

                    /* Periksa RFID duplikat */
                    if ($cardUid !== '') {
                        $duplicateCard = $pdo->prepare("
                            SELECT employee_id
                            FROM employee_cards
                            WHERE company_id = ?
                            AND card_uid = ?
                            AND employee_id != ?
                            LIMIT 1
                        ");
                        $duplicateCard->execute([$companyId, $cardUid, $id]);

                        if ($duplicateCard->fetch()) {
                            throw new Exception('UID RFID sudah digunakan karyawan lain.');
                        }
                    }

                    if ($id > 0) {

                        $checkEmployee = $pdo->prepare("
                            SELECT id, photo
                            FROM employees
                            WHERE id = ? AND company_id = ?
                            LIMIT 1
                        ");
                        $checkEmployee->execute([$id, $companyId]);
                        $oldEmployee = $checkEmployee->fetch(PDO::FETCH_ASSOC);

                        if (!$oldEmployee) {
                            throw new Exception('Data karyawan tidak ditemukan.');
                        }

                        $finalPhoto = $photoName ?: $oldEmployee['photo'];

                        $update = $pdo->prepare("
                            UPDATE employees SET
                                nik = ?,
                                name = ?,
                                email = ?,
                                phone = ?,
                                gender = ?,
                                join_date = ?,
                                department_id = ?,
                                position_id = ?,
                                status = ?,
                                photo = ?,
                                updated_at = NOW()
                            WHERE id = ? AND company_id = ?
                        ");

                        $update->execute([
                            $nik,
                            $name,
                            $email ?: null,
                            $phone ?: null,
                            $gender,
                            $joinDate ?: null,
                            $departmentId ?: null,
                            $positionId ?: null,
                            $status,
                            $finalPhoto,
                            $id,
                            $companyId
                        ]);

                        $employeeId = $id;

                        /* Hapus foto lama setelah transaksi berhasil */
                        $oldPhoto = $oldEmployee['photo'];

                    } else {

                        $insert = $pdo->prepare("
                            INSERT INTO employees (
                                company_id,
                                nik,
                                name,
                                email,
                                phone,
                                gender,
                                join_date,
                                department_id,
                                position_id,
                                status,
                                photo,
                                created_at,
                                updated_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");

                        $insert->execute([
                            $companyId,
                            $nik,
                            $name,
                            $email ?: null,
                            $phone ?: null,
                            $gender,
                            $joinDate ?: null,
                            $departmentId ?: null,
                            $positionId ?: null,
                            $status,
                            $photoName
                        ]);

                        $employeeId = (int)$pdo->lastInsertId();
                        $oldPhoto = null;
                    }

                    /* Simpan atau nonaktifkan kartu RFID */
                    if ($cardUid !== '') {

                        $cardCheck = $pdo->prepare("
                            SELECT id FROM employee_cards
                            WHERE company_id = ?
                            AND employee_id = ?
                            ORDER BY id DESC
                            LIMIT 1
                        ");
                        $cardCheck->execute([$companyId, $employeeId]);
                        $existingCard = $cardCheck->fetch(PDO::FETCH_ASSOC);

                        if ($existingCard) {
                            $cardUpdate = $pdo->prepare("
                                UPDATE employee_cards
                                SET card_uid = ?, status = 'active'
                                WHERE id = ? AND company_id = ?
                            ");
                            $cardUpdate->execute([
                                $cardUid,
                                $existingCard['id'],
                                $companyId
                            ]);
                        } else {
                            $cardInsert = $pdo->prepare("
                                INSERT INTO employee_cards (
                                    company_id,
                                    employee_id,
                                    card_uid,
                                    status,
                                    created_at
                                ) VALUES (?, ?, ?, 'active', NOW())
                            ");
                            $cardInsert->execute([
                                $companyId,
                                $employeeId,
                                $cardUid
                            ]);
                        }

                    } else {
                        $deactivateCard = $pdo->prepare("
                            UPDATE employee_cards
                            SET status = 'inactive'
                            WHERE company_id = ? AND employee_id = ?
                        ");
                        $deactivateCard->execute([$companyId, $employeeId]);
                    }

                    $pdo->commit();

                    if (!empty($oldPhoto) && $photoName) {
                        $oldPath = __DIR__ . '/uploads/employees/' . basename($oldPhoto);
                        if (is_file($oldPath)) {
                            unlink($oldPath);
                        }
                    }

                    redirectPage(['success' => $id > 0 ? 'updated' : 'created']);

                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($uploadedPhoto && is_file($uploadedPhoto)) {
                        unlink($uploadedPhoto);
                    }

                    $error = $ex->getMessage();
                }
            }
        }

        /* =================================================
           NONAKTIFKAN KARYAWAN
        ================================================= */

        if ($action === 'delete_employee') {

            $id = (int)($_POST['employee_id'] ?? 0);

            if ($id > 0) {
                try {
                    $pdo->beginTransaction();

                    $update = $pdo->prepare("
                        UPDATE employees
                        SET status = 'inactive', updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $update->execute([$id, $companyId]);

                    $card = $pdo->prepare("
                        UPDATE employee_cards
                        SET status = 'inactive'
                        WHERE employee_id = ? AND company_id = ?
                    ");
                    $card->execute([$id, $companyId]);

                    $pdo->commit();

                    redirectPage(['success' => 'deactivated']);

                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $ex->getMessage();
                }
            }
        }
    }
}

/* =========================================================
   NOTIFIKASI
========================================================= */

if (isset($_GET['success'])) {
    $messages = [
        'created' => 'Karyawan berhasil ditambahkan.',
        'updated' => 'Data karyawan berhasil diperbarui.',
        'deactivated' => 'Karyawan berhasil dinonaktifkan.'
    ];

    $success = $messages[$_GET['success']] ?? '';
}

/* =========================================================
   FILTER DAN PAGINATION
========================================================= */

$search = trim($_GET['search'] ?? '');
$departmentFilter = (int)($_GET['department'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = ['e.company_id = ?'];
$params = [$companyId];

if ($search !== '') {
    $where[] = '(e.name LIKE ? OR e.nik LIKE ? OR e.email LIKE ?)';
    $keyword = '%' . $search . '%';
    array_push($params, $keyword, $keyword, $keyword);
}

if ($departmentFilter > 0) {
    $where[] = 'e.department_id = ?';
    $params[] = $departmentFilter;
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'e.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

/* =========================================================
   STATISTIK KARYAWAN
========================================================= */

$statsQuery = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active,
        SUM(status = 'inactive') AS inactive,
        SUM(
            join_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            AND join_date <= CURDATE()
        ) AS new_employees
    FROM employees
    WHERE company_id = ?
");
$statsQuery->execute([$companyId]);
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

/* =========================================================
   DATA DEPARTEMEN DAN JABATAN
========================================================= */

$departmentQuery = $pdo->prepare("
    SELECT id, name
    FROM departments
    WHERE company_id = ?
    ORDER BY name ASC
");
$departmentQuery->execute([$companyId]);
$departments = $departmentQuery->fetchAll(PDO::FETCH_ASSOC);

$positionQuery = $pdo->prepare("
    SELECT id, department_id, name
    FROM positions
    WHERE company_id = ?
    ORDER BY name ASC
");
$positionQuery->execute([$companyId]);
$positions = $positionQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   HITUNG TOTAL DATA
========================================================= */

$countQuery = $pdo->prepare("
    SELECT COUNT(*)
    FROM employees e
    WHERE $whereSql
");
$countQuery->execute($params);

$totalRows = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

/* =========================================================
   AMBIL DATA KARYAWAN
========================================================= */

$listQuery = $pdo->prepare("
    SELECT
        e.*,
        d.name AS department_name,
        p.name AS position_name,
        (
            SELECT ec.card_uid
            FROM employee_cards ec
            WHERE ec.employee_id = e.id
            AND ec.company_id = e.company_id
            AND ec.status = 'active'
            ORDER BY ec.id DESC
            LIMIT 1
        ) AS card_uid
    FROM employees e
    LEFT JOIN departments d
        ON d.id = e.department_id
        AND d.company_id = e.company_id
    LEFT JOIN positions p
        ON p.id = e.position_id
        AND p.company_id = e.company_id
    WHERE $whereSql
    ORDER BY e.id DESC
    LIMIT $limit OFFSET $offset
");

$listQuery->execute($params);
$employees = $listQuery->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = attendly_csrf_token();

$currentName = $user['name'] ?? 'Administrator';
$currentRole = 'Administrator';

if (!empty($user['roles'])) {
    $currentRole = is_array($user['roles'])
        ? implode(', ', $user['roles'])
        : $user['roles'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Data Karyawan | Attendly</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
    --text: #263449;
    --muted: #8290a5;
    --border: #e8edf4;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    font-family: 'Inter', sans-serif;
    color: var(--text);
    font-size: 14px;
}

.sidebar {
    width: 255px;
    height: 100vh;
    background: var(--sidebar);
    position: fixed;
    top: 0;
    left: 0;
    padding: 24px 16px;
    color: #fff;
    z-index: 1000;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 21px;
    font-weight: 800;
    padding: 0 10px;
    margin-bottom: 35px;
}

.brand-icon {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.menu-label {
    color: #8493aa;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin: 18px 10px 9px;
}

.sidebar a {
    color: #aebbd0;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 13px;
    border-radius: 9px;
    margin-bottom: 4px;
    font-size: 13px;
    font-weight: 500;
    transition: .2s;
}

.sidebar a i {
    width: 18px;
    text-align: center;
}

.sidebar a:hover,
.sidebar a.active {
    background: var(--primary);
    color: #fff;
}

.sidebar-footer {
    margin-top: auto;
    padding: 18px 10px 0;
    border-top: 1px solid #344157;
    color: #91a0b6;
    font-size: 11px;
}

.main-content {
    margin-left: 255px;
    padding: 25px 30px;
    min-height: 100vh;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
}

.page-title {
    font-size: 23px;
    font-weight: 800;
    margin: 0 0 5px;
}

.page-subtitle {
    color: var(--muted);
    margin: 0;
}

.profile {
    display: flex;
    align-items: center;
    gap: 11px;
}

.profile-avatar {
    width: 40px;
    height: 40px;
    background: #e5edff;
    color: var(--primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.profile-name {
    font-weight: 700;
    font-size: 13px;
}

.profile-role {
    color: var(--muted);
    font-size: 11px;
}

.stat-card,
.content-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 15px rgba(30, 50, 80, .025);
}

.stat-card {
    padding: 20px;
    height: 100%;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    font-size: 27px;
    font-weight: 800;
    margin-top: 9px;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.content-card {
    padding: 22px;
    margin-top: 22px;
}

.card-heading {
    font-size: 16px;
    font-weight: 800;
}

.form-control,
.form-select {
    min-height: 40px;
    border-color: #e3e9f1;
    border-radius: 8px;
    font-size: 13px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(57,121,246,.1);
}

.btn {
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    padding: 9px 14px;
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
}

.table {
    vertical-align: middle;
    font-size: 12px;
    margin-bottom: 0;
}

.table thead th {
    background: #f8fafc;
    color: #7e8ba0;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.table tbody td {
    padding: 13px 12px;
    border-color: #edf1f6;
}

.employee-avatar {
    width: 37px;
    height: 37px;
    border-radius: 50%;
    object-fit: cover;
    background: #eaf0ff;
}

.employee-name {
    font-weight: 700;
    color: #263449;
}

.employee-email {
    color: var(--muted);
    font-size: 11px;
    margin-top: 3px;
}

.status-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}

.status-active {
    color: #16834c;
    background: #e5f8ee;
}

.status-inactive {
    color: #b45353;
    background: #fff0f0;
}

.rfid-badge {
    display: inline-block;
    background: #eef3ff;
    color: #3567ca;
    border-radius: 6px;
    padding: 5px 7px;
    font-size: 10px;
    font-weight: 700;
}

.action-btn {
    width: 30px;
    height: 30px;
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 7px;
    color: #64748b;
}

.action-btn:hover {
    background: #f1f5f9;
}

.modal-content {
    border: 0;
    border-radius: 13px;
}

.modal-header,
.modal-footer {
    border-color: var(--border);
    padding: 18px 22px;
}

.modal-body {
    padding: 22px;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
}

.required {
    color: #dc3545;
}

.empty-state {
    text-align: center;
    padding: 45px 15px;
    color: var(--muted);
}

.empty-state i {
    font-size: 35px;
    margin-bottom: 12px;
    color: #b7c4d5;
}

@media(max-width: 991px) {
    .sidebar {
        transform: translateX(-100%);
        transition: .25s;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
        padding: 20px 15px;
    }

    .mobile-toggle {
        display: inline-flex !important;
    }
}

.mobile-toggle {
    display: none;
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="fa-solid fa-fingerprint"></i>
        </div>
        Attendly
    </div>

    <div class="menu-label">Menu Utama</div>

    <a href="dashboard.php">
        <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>

    <a href="karyawan.php" class="active">
        <i class="fa-solid fa-users"></i> Data Karyawan
    </a>

    <a href="departemen.php">
        <i class="fa-solid fa-building"></i> Departemen
    </a>

    <a href="jabatan.php">
        <i class="fa-solid fa-briefcase"></i> Jabatan
    </a>

    <a href="jadwal.php">
        <i class="fa-solid fa-calendar-days"></i> Jadwal Kerja
    </a>

    <a href="lokasi.php">
        <i class="fa-solid fa-location-dot"></i> Lokasi Kantor
    </a>

    <div class="menu-label">Absensi</div>

    <a href="absensi.php">
        <i class="fa-solid fa-fingerprint"></i> Data Absensi
    </a>

    <a href="izin.php">
        <i class="fa-solid fa-envelope-open-text"></i> Pengajuan Izin
    </a>

    <a href="laporan.php">
        <i class="fa-solid fa-chart-column"></i> Laporan
    </a>

    <div class="menu-label">Sistem</div>

    <a href="pengaturan.php">
        <i class="fa-solid fa-gear"></i> Pengaturan
    </a>

    <a href="logout.php"
       onclick="return confirm('Apakah Anda yakin ingin logout?')">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly System</div>
        <div>Versi 1.0.0</div>
    </div>

</aside>

<!-- MAIN -->
<main class="main-content">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light mobile-toggle" id="sidebarToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <h4 class="page-title">Data Karyawan</h4>
                <p class="page-subtitle">Kelola data karyawan dan kartu RFID perusahaan.</p>
            </div>
        </div>

        <div class="profile">
            <div class="profile-avatar">
                <?= e(strtoupper(substr($currentName, 0, 2))) ?>
            </div>
            <div>
                <div class="profile-name"><?= e($currentName) ?></div>
                <div class="profile-role"><?= e($currentRole) ?></div>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i>
            <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATISTIK -->
    <div class="row g-3">

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Karyawan</div>
                        <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
                    </div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Karyawan Aktif</div>
                        <div class="stat-value"><?= number_format((int)$stats['active']) ?></div>
                    </div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Karyawan Nonaktif</div>
                        <div class="stat-value"><?= number_format((int)$stats['inactive']) ?></div>
                    </div>
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="fa-solid fa-user-xmark"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Karyawan Baru Bulan Ini</div>
                        <div class="stat-value"><?= number_format((int)$stats['new_employees']) ?></div>
                    </div>
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- TABEL KARYAWAN -->
    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <div class="card-heading">Daftar Karyawan</div>
                <div class="text-muted small mt-1">Data karyawan yang terdaftar di perusahaan.</div>
            </div>

            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i>
                Tambah Karyawan
            </button>
        </div>

        <!-- FILTER -->
        <form method="GET" class="row g-2 mb-4">

            <div class="col-lg-5">
                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="fa-solid fa-search text-muted"></i>
                    </span>
                    <input type="text"
                           name="search"
                           class="form-control"
                           placeholder="Cari nama, NIK, email..."
                           value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-lg-3">
                <select name="department" class="form-select">
                    <option value="">Semua Departemen</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int)$department['id'] ?>"
                            <?= $departmentFilter === (int)$department['id'] ? 'selected' : '' ?>>
                            <?= e($department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>

            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <a href="karyawan.php" class="btn btn-light border">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>

        </form>

        <!-- DATA TABLE -->
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIK</th>
                        <th>Karyawan</th>
                        <th>Departemen</th>
                        <th>Jabatan</th>
                        <th>RFID</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$employees): ?>

                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fa-solid fa-users d-block"></i>
                                <div class="fw-bold">Belum ada data karyawan</div>
                                <div class="small">Tambahkan karyawan untuk mulai menggunakan Attendly.</div>
                            </div>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($employees as $employee): ?>

                        <?php
                        $employeeJson = htmlspecialchars(
                            json_encode($employee, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
                            ENT_QUOTES,
                            'UTF-8'
                        );

                        $photoPath = !empty($employee['photo'])
                            ? 'uploads/employees/' . rawurlencode(basename($employee['photo']))
                            : '';
                        ?>

                        <tr>
                            <td class="fw-bold"><?= e($employee['nik']) ?></td>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($photoPath): ?>
                                        <img src="<?= e($photoPath) ?>" class="employee-avatar" alt="Foto">
                                    <?php else: ?>
                                        <div class="employee-avatar d-flex align-items-center justify-content-center">
                                            <i class="fa-solid fa-user text-primary"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <div class="employee-name"><?= e($employee['name']) ?></div>
                                        <div class="employee-email"><?= e($employee['email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <td><?= e($employee['department_name'] ?: '-') ?></td>

                            <td><?= e($employee['position_name'] ?: '-') ?></td>

                            <td>
                                <?php if ($employee['card_uid']): ?>
                                    <span class="rfid-badge">
                                        <i class="fa-solid fa-wifi me-1"></i>
                                        <?= e($employee['card_uid']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">Belum terdaftar</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($employee['status'] === 'active'): ?>
                                    <span class="status-badge status-active">Aktif</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">

                                    <button type="button"
                                            class="action-btn"
                                            title="Edit"
                                            onclick='editEmployee(<?= $employeeJson ?>)'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <?php if ($employee['status'] === 'active'): ?>
                                        <form method="POST"
                                              onsubmit="return confirm('Nonaktifkan karyawan ini?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_employee">
                                            <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">

                                            <button type="submit" class="action-btn text-danger" title="Nonaktifkan">
                                                <i class="fa-solid fa-user-slash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 gap-3">

            <div class="text-muted small">
                Menampilkan <?= $totalRows ? $offset + 1 : 0 ?>
                sampai <?= min($offset + $limit, $totalRows) ?>
                dari <?= $totalRows ?> karyawan
            </div>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <?php
                    $queryParams = $_GET;
                    $queryParams['page'] = max(1, $page - 1);
                    ?>

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= e(http_build_query($queryParams)) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <li class="page-item active">
                        <span class="page-link"><?= $page ?></span>
                    </li>

                    <?php
                    $queryParams['page'] = min($totalPages, $page + 1);
                    ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= e(http_build_query($queryParams)) ?>">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </li>

                </ul>
            </nav>

        </div>

    </div>

    <div class="text-center text-muted small mt-4">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<!-- MODAL TAMBAH / EDIT KARYAWAN -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <form method="POST" enctype="multipart/form-data" id="employeeForm">

                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save_employee">
                <input type="hidden" name="employee_id" id="employeeId">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle">Tambah Karyawan</h5>
                        <div class="text-muted small">Lengkapi informasi karyawan di bawah ini.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">NIK <span class="required">*</span></label>
                            <input type="text" name="nik" id="nik" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nama Lengkap <span class="required">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nomor Telepon</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jenis Kelamin</label>
                            <select name="gender" id="gender" class="form-select" required>
                                <option value="">Pilih Jenis Kelamin</option>
                                <option value="Laki-laki">Laki-laki</option>
                                <option value="Perempuan">Perempuan</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tanggal Masuk</label>
                            <input type="date" name="join_date" id="joinDate" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Departemen</label>
                            <select name="department_id" id="department" class="form-select">
                                <option value="">Pilih Departemen</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= (int)$department['id'] ?>">
                                        <?= e($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jabatan</label>
                            <select name="position_id" id="position" class="form-select">
                                <option value="">Pilih Jabatan</option>
                                <?php foreach ($positions as $position): ?>
                                    <option
                                        value="<?= (int)$position['id'] ?>"
                                        data-department="<?= (int)$position['department_id'] ?>">
                                        <?= e($position['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status Karyawan</label>
                            <select name="status" id="status" class="form-select">
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Foto Karyawan</label>
                            <input type="file"
                                   name="photo"
                                   id="photo"
                                   class="form-control"
                                   accept=".jpg,.jpeg,.png,.webp">
                            <small class="text-muted">JPG, PNG, WebP. Maksimal 1 MB.</small>
                        </div>

                        <div class="col-12">
                            <hr>
                            <div class="fw-bold mb-2">
                                <i class="fa-solid fa-id-card text-primary me-2"></i>
                                Kartu RFID
                            </div>

                            <label class="form-label">UID Kartu RFID</label>
                            <div class="input-group">
                                <input type="text"
                                       name="card_uid"
                                       id="cardUid"
                                       class="form-control"
                                       placeholder="Contoh: A1B2C3D4"
                                       autocomplete="off">
                                <button type="button" class="btn btn-outline-primary" id="clearRfid">
                                    <i class="fa-solid fa-eraser"></i>
                                </button>
                            </div>
                            <small class="text-muted">
                                Masukkan UID kartu RFID yang akan digunakan karyawan.
                                Kosongkan jika karyawan tidak menggunakan kartu.
                            </small>
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i>
                        Simpan Karyawan
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const employeeModalElement = document.getElementById('employeeModal');
const employeeModal = new bootstrap.Modal(employeeModalElement);

const employeeForm = document.getElementById('employeeForm');

function openAddModal() {
    employeeForm.reset();

    document.getElementById('employeeId').value = '';
    document.getElementById('modalTitle').textContent = 'Tambah Karyawan';
    document.getElementById('status').value = 'active';

    filterPositions();

    employeeModal.show();
}

function editEmployee(employee) {
    employeeForm.reset();

    document.getElementById('employeeId').value = employee.id;
    document.getElementById('nik').value = employee.nik || '';
    document.getElementById('name').value = employee.name || '';
    document.getElementById('email').value = employee.email || '';
    document.getElementById('phone').value = employee.phone || '';
    document.getElementById('gender').value = employee.gender || '';
    document.getElementById('joinDate').value = employee.join_date || '';
    document.getElementById('department').value = employee.department_id || '';
    document.getElementById('status').value = employee.status || 'active';
    document.getElementById('cardUid').value = employee.card_uid || '';

    document.getElementById('modalTitle').textContent = 'Edit Data Karyawan';

    filterPositions();

    document.getElementById('position').value = employee.position_id || '';

    employeeModal.show();
}

function filterPositions() {
    const departmentId = document.getElementById('department').value;
    const positionSelect = document.getElementById('position');

    Array.from(positionSelect.options).forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }

        const optionDepartment = option.dataset.department;
        option.hidden = departmentId !== '' && optionDepartment !== departmentId;
    });

    if (positionSelect.selectedOptions[0]?.hidden) {
        positionSelect.value = '';
    }
}

document.getElementById('department').addEventListener('change', filterPositions);

document.getElementById('clearRfid').addEventListener('click', function() {
    document.getElementById('cardUid').value = '';
    document.getElementById('cardUid').focus();
});

document.getElementById('cardUid').addEventListener('input', function() {
    this.value = this.value.toUpperCase().replace(/\s/g, '');
});

document.getElementById('sidebarToggle').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('show');
});

document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');

    if (
        window.innerWidth <= 991 &&
        sidebar.classList.contains('show') &&
        !sidebar.contains(event.target) &&
        !toggle.contains(event.target)
    ) {
        sidebar.classList.remove('show');
    }
});

employeeForm.addEventListener('submit', function(event) {
    const photo = document.getElementById('photo');

    if (photo.files.length > 0 && photo.files[0].size > 1024 * 1024) {
        event.preventDefault();
        alert('Ukuran foto maksimal 1 MB.');
    }
});
</script>

</body>
</html>

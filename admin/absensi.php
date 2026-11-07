
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$user = attendly_user();

$errors = [];
$success = '';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function attendance_redirect($params = []) {
    $query = http_build_query($params);
    header('Location: absensi.php' . ($query ? '?' . $query : ''));
    exit;
}

function attendance_log($pdo, $companyId, $userId, $action, $description) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs
                (company_id, user_id, action, description, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, $action, $description]);
    } catch (Throwable $e) {
        // Log aktivitas tidak boleh menggagalkan proses utama.
    }
}

function attendance_status_label($status) {
    $labels = [
        'present' => 'Hadir',
        'late' => 'Terlambat',
        'permission' => 'Izin',
        'sick' => 'Sakit',
        'leave' => 'Cuti',
        'absent' => 'Alpha',
        'holiday' => 'Libur',
        'off_day' => 'Hari Libur Kerja'
    ];

    return $labels[$status] ?? ucfirst((string)$status);
}

function attendance_status_class($status) {
    $classes = [
        'present' => 'success',
        'late' => 'warning',
        'permission' => 'info',
        'sick' => 'primary',
        'leave' => 'info',
        'absent' => 'danger',
        'holiday' => 'secondary',
        'off_day' => 'secondary'
    ];

    return $classes[$status] ?? 'secondary';
}

function valid_attendance_date($date) {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

$allowedStatuses = [
    'present',
    'late',
    'permission',
    'sick',
    'leave',
    'absent',
    'holiday',
    'off_day'
];

$allowedMethods = ['rfid', 'web', 'mobile', 'admin', 'api'];

/*
|--------------------------------------------------------------------------
| PROSES FORM
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
            throw new Exception('Sesi keamanan tidak valid. Silakan muat ulang halaman.');
        }

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | TAMBAH ABSENSI MANUAL
        |--------------------------------------------------------------------------
        */

        if ($action === 'manual_attendance') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $attendanceDate = trim($_POST['attendance_date'] ?? '');
            $checkIn = trim($_POST['check_in'] ?? '');
            $checkOut = trim($_POST['check_out'] ?? '');
            $status = $_POST['status'] ?? 'present';
            $notes = trim($_POST['notes'] ?? '');

            if ($employeeId <= 0) {
                throw new Exception('Silakan pilih karyawan.');
            }

            if (!valid_attendance_date($attendanceDate)) {
                throw new Exception('Tanggal absensi tidak valid.');
            }

            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Status absensi tidak valid.');
            }

            $clockInAt = null;
            $clockOutAt = null;

            if ($checkIn !== '') {
                $time = DateTime::createFromFormat('!H:i', $checkIn);

                if (!$time || $time->format('H:i') !== $checkIn) {
                    throw new Exception('Jam masuk tidak valid.');
                }

                $clockInAt = $attendanceDate . ' ' . $checkIn . ':00';
            }

            if ($checkOut !== '') {
                $time = DateTime::createFromFormat('!H:i', $checkOut);

                if (!$time || $time->format('H:i') !== $checkOut) {
                    throw new Exception('Jam pulang tidak valid.');
                }

                $clockOutAt = $attendanceDate . ' ' . $checkOut . ':00';
            }

            if ($clockInAt && $clockOutAt && $clockOutAt < $clockInAt) {
                throw new Exception('Jam pulang tidak boleh lebih awal dari jam masuk.');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM employees
                WHERE id = ?
                  AND company_id = ?
                  AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$employeeId, $companyId]);

            if (!$stmt->fetchColumn()) {
                throw new Exception('Karyawan tidak ditemukan atau tidak aktif.');
            }

            $stmt = $pdo->prepare("
                SELECT id
                FROM attendances
                WHERE employee_id = ?
                  AND company_id = ?
                  AND attendance_date = ?
                LIMIT 1
            ");
            $stmt->execute([$employeeId, $companyId, $attendanceDate]);

            if ($stmt->fetchColumn()) {
                throw new Exception('Absensi karyawan pada tanggal tersebut sudah tersedia.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO attendances (
                    company_id,
                    employee_id,
                    attendance_date,
                    clock_in_at,
                    clock_out_at,
                    clock_in_method,
                    clock_out_method,
                    status,
                    notes
                ) VALUES (?, ?, ?, ?, ?, 'admin', 'admin', ?, ?)
            ");

            $stmt->execute([
                $companyId,
                $employeeId,
                $attendanceDate,
                $clockInAt,
                $clockOutAt,
                $status,
                $notes !== '' ? $notes : null
            ]);

            attendance_log(
                $pdo,
                $companyId,
                $user['id'] ?? null,
                'create',
                'Menambahkan absensi manual untuk karyawan #' . $employeeId
            );

            attendance_redirect([
                'success' => 'Absensi manual berhasil ditambahkan.'
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | UBAH STATUS ABSENSI
        |--------------------------------------------------------------------------
        */

        if ($action === 'update_status') {
            $attendanceId = (int)($_POST['attendance_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            if ($attendanceId <= 0) {
                throw new Exception('Data absensi tidak valid.');
            }

            if (!in_array($status, $allowedStatuses, true)) {
                throw new Exception('Status absensi tidak valid.');
            }

            $stmt = $pdo->prepare("
                UPDATE attendances
                SET status = ?
                WHERE id = ?
                  AND company_id = ?
            ");
            $stmt->execute([$status, $attendanceId, $companyId]);

            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM attendances
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$attendanceId, $companyId]);

                if (!$stmt->fetchColumn()) {
                    throw new Exception('Data absensi tidak ditemukan.');
                }
            }

            attendance_log(
                $pdo,
                $companyId,
                $user['id'] ?? null,
                'update',
                'Mengubah status absensi #' . $attendanceId
            );

            attendance_redirect([
                'success' => 'Status absensi berhasil diperbarui.'
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | HAPUS ABSENSI
        |--------------------------------------------------------------------------
        */

        if ($action === 'delete') {
            $attendanceId = (int)($_POST['attendance_id'] ?? 0);

            if ($attendanceId <= 0) {
                throw new Exception('Data absensi tidak valid.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM attendances
                WHERE id = ?
                  AND company_id = ?
            ");
            $stmt->execute([$attendanceId, $companyId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Data absensi tidak ditemukan.');
            }

            attendance_log(
                $pdo,
                $companyId,
                $user['id'] ?? null,
                'delete',
                'Menghapus data absensi #' . $attendanceId
            );

            attendance_redirect([
                'success' => 'Data absensi berhasil dihapus.'
            ]);
        }

        throw new Exception('Aksi tidak dikenali.');

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/*
|--------------------------------------------------------------------------
| NOTIFIKASI
|--------------------------------------------------------------------------
*/

if (!empty($_GET['success'])) {
    $success = $_GET['success'];
}

/*
|--------------------------------------------------------------------------
| FILTER DATA
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-d'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$sourceFilter = trim($_GET['source'] ?? '');

if (!valid_attendance_date($dateFrom)) {
    $dateFrom = date('Y-m-d');
}

if (!valid_attendance_date($dateTo)) {
    $dateTo = date('Y-m-d');
}

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if ($sourceFilter !== '' && !in_array($sourceFilter, $allowedMethods, true)) {
    $sourceFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

$where = ['a.company_id = ?'];
$params = [$companyId];

if ($search !== '') {
    $where[] = '(e.name LIKE ? OR e.nik LIKE ? OR e.email LIKE ?)';
    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}

if ($dateFrom !== '') {
    $where[] = 'a.attendance_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'a.attendance_date <= ?';
    $params[] = $dateTo;
}

if ($sourceFilter !== '') {
    $where[] = 'a.clock_in_method = ?';
    $params[] = $sourceFilter;
}

$whereSql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| STATISTIK HARI INI
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'present') AS present_count,
        SUM(status = 'late') AS late_count,
        SUM(status IN ('permission', 'sick', 'leave')) AS permission_count,
        SUM(status = 'absent') AS absent_count
    FROM attendances
    WHERE company_id = ?
      AND attendance_date = CURDATE()
");
$stmt->execute([$companyId]);

$todayStats = $stmt->fetch(PDO::FETCH_ASSOC);

$stats = [
    'total' => (int)($todayStats['total'] ?? 0),
    'present' => (int)($todayStats['present_count'] ?? 0),
    'late' => (int)($todayStats['late_count'] ?? 0),
    'permission' => (int)($todayStats['permission_count'] ?? 0),
    'absent' => (int)($todayStats['absent_count'] ?? 0)
];

/*
|--------------------------------------------------------------------------
| TOTAL DATA DAN PAGINATION
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM attendances a
    INNER JOIN employees e
        ON e.id = a.employee_id
       AND e.company_id = a.company_id
    WHERE $whereSql
");
$stmt->execute($params);

$totalRows = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| DAFTAR ABSENSI
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.employee_id,
        a.location_id,
        a.schedule_id,
        a.attendance_date,
        a.clock_in_at,
        a.clock_out_at,
        a.clock_in_method,
        a.clock_out_method,
        a.clock_in_latitude,
        a.clock_in_longitude,
        a.clock_out_latitude,
        a.clock_out_longitude,
        a.late_minutes,
        a.work_minutes,
        a.status,
        a.notes,
        e.nik,
        e.name AS employee_name,
        d.name AS department_name,
        p.name AS position_name,
        l.name AS location_name
    FROM attendances a
    INNER JOIN employees e
        ON e.id = a.employee_id
       AND e.company_id = a.company_id
    LEFT JOIN departments d
        ON d.id = e.department_id
       AND d.company_id = a.company_id
    LEFT JOIN positions p
        ON p.id = e.position_id
       AND p.company_id = a.company_id
    LEFT JOIN locations l
        ON l.id = a.location_id
       AND l.company_id = a.company_id
    WHERE $whereSql
    ORDER BY a.attendance_date DESC, a.clock_in_at DESC, a.id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);

$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| DAFTAR KARYAWAN
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT id, nik, name
    FROM employees
    WHERE company_id = ?
      AND status = 'active'
    ORDER BY name ASC
");
$stmt->execute([$companyId]);

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$queryParams = $_GET;
unset($queryParams['page']);

$paginationUrl = function($number) use ($queryParams) {
    return '?' . http_build_query(
        array_merge($queryParams, ['page' => $number])
    );
};

?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Data Absensi | Attendly</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
    --text: #263449;
    --muted: #8994a5;
    --border: #edf0f5;
}

* {
    font-family: 'Inter', sans-serif;
}

body {
    background: var(--background);
    color: var(--text);
    font-size: 14px;
}

.sidebar {
    width: 255px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--sidebar);
    color: #fff;
    padding: 24px 16px;
    z-index: 1000;
    overflow-y: auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 21px;
    font-weight: 800;
    margin-bottom: 32px;
    padding: 0 10px;
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
    color: #8492a8;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 22px 10px 10px;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #aebbd0;
    text-decoration: none;
    padding: 12px 13px;
    border-radius: 9px;
    margin-bottom: 4px;
    font-weight: 500;
    transition: .2s;
}

.sidebar a:hover,
.sidebar a.active {
    color: #fff;
    background: var(--primary);
}

.sidebar a i {
    width: 18px;
}

.sidebar-footer {
    border-top: 1px solid #344158;
    margin-top: 30px;
    padding: 18px 10px 0;
    color: #8997ad;
    font-size: 12px;
}

.main-content {
    margin-left: 255px;
    padding: 28px;
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
    margin-bottom: 5px;
}

.page-subtitle {
    color: var(--muted);
}

.profile {
    display: flex;
    align-items: center;
    gap: 11px;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e2ebff;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.stat-card,
.content-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    height: 100%;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    font-size: 25px;
    font-weight: 800;
    margin-top: 7px;
}

.card-heading {
    font-weight: 700;
    font-size: 16px;
}

.form-control,
.form-select {
    border-color: #e5e9f0;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 .2rem rgba(57,121,246,.1);
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
}

.table thead th {
    background: #f8f9fc;
    color: #78869a;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 14px;
    border-bottom: 1px solid var(--border);
}

.table tbody td {
    padding: 14px;
    border-bottom: 1px solid var(--border);
}

.employee-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e8efff;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}

.badge {
    font-weight: 600;
    padding: 7px 10px;
}

.pagination .page-link {
    color: var(--primary);
    border-color: var(--border);
    font-size: 12px;
}

.pagination .active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

@media (max-width: 991px) {
    .sidebar {
        transform: translateX(-100%);
        transition: .25s;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
        padding: 18px;
    }
}

@media (max-width: 575px) {
    .topbar {
        align-items: flex-start;
    }

    .profile {
        display: none;
    }
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

    <a href="karyawan.php">
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

    <a href="absensi.php" class="active">
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

    <a href="logout.php" onclick="return confirm('Apakah Anda yakin ingin logout?')">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly System</div>
        <div>Versi 1.0.0</div>
    </div>

</aside>

<main class="main-content">

    <!-- TOPBAR -->
    <div class="topbar">
        <div>
            <button class="btn btn-light d-lg-none mb-2" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <h1 class="page-title">Data Absensi</h1>
            <div class="page-subtitle">
                Pantau dan kelola aktivitas kehadiran karyawan.
            </div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($user['name'] ?? 'AD', 0, 2))) ?>
            </div>
            <div>
                <div class="fw-bold"><?= e($user['name'] ?? 'Administrator') ?></div>
                <small class="text-muted">Administrator</small>
            </div>
        </div>
    </div>

    <!-- ALERT -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i>
            <?= e($success) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            <?= e($error) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <!-- STATISTIK -->
    <div class="row g-3 mb-4">

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>
                    <span class="stat-label">Hari Ini</span>
                </div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
                <div class="stat-label">Total catatan absensi</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <span class="stat-label">Hadir</span>
                </div>
                <div class="stat-value"><?= number_format($stats['present']) ?></div>
                <div class="stat-label">Karyawan hadir</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <span class="stat-label">Terlambat</span>
                </div>
                <div class="stat-value"><?= number_format($stats['late']) ?></div>
                <div class="stat-label">Datang terlambat</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fa-solid fa-envelope"></i>
                    </div>
                    <span class="stat-label">Izin / Sakit / Cuti</span>
                </div>
                <div class="stat-value"><?= number_format($stats['permission']) ?></div>
                <div class="stat-label">Tidak masuk dengan keterangan</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="fa-solid fa-user-xmark"></i>
                    </div>
                    <span class="stat-label">Alpha</span>
                </div>
                <div class="stat-value"><?= number_format($stats['absent']) ?></div>
                <div class="stat-label">Tanpa keterangan</div>
            </div>
        </div>

    </div>

    <!-- TABEL ABSENSI -->
    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <div class="card-heading">Daftar Kehadiran</div>
                <small class="text-muted">
                    Data absensi karyawan berdasarkan tanggal.
                </small>
            </div>

            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#manualModal">
                <i class="fa-solid fa-plus me-1"></i>
                Tambah Absensi
            </button>
        </div>

        <!-- FILTER -->
        <form method="GET" class="row g-2 mb-3">

            <div class="col-lg-3 col-md-6">
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Cari nama, NIK, email..."
                    value="<?= e($search) ?>"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>

                    <?php foreach ($allowedStatuses as $item): ?>
                        <option value="<?= e($item) ?>" <?= $statusFilter === $item ? 'selected' : '' ?>>
                            <?= e(attendance_status_label($item)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <input
                    type="date"
                    name="date_from"
                    class="form-control"
                    value="<?= e($dateFrom) ?>"
                    aria-label="Dari tanggal"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <input
                    type="date"
                    name="date_to"
                    class="form-control"
                    value="<?= e($dateTo) ?>"
                    aria-label="Sampai tanggal"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="source" class="form-select">
                    <option value="">Semua Sumber</option>
                    <option value="rfid" <?= $sourceFilter === 'rfid' ? 'selected' : '' ?>>RFID</option>
                    <option value="web" <?= $sourceFilter === 'web' ? 'selected' : '' ?>>Web</option>
                    <option value="mobile" <?= $sourceFilter === 'mobile' ? 'selected' : '' ?>>Mobile</option>
                    <option value="admin" <?= $sourceFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="api" <?= $sourceFilter === 'api' ? 'selected' : '' ?>>API</option>
                </select>
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <button class="btn btn-primary" type="submit">
                    <i class="fa-solid fa-filter"></i>
                </button>
            </div>

        </form>

        <!-- DATA TABLE -->
        <div class="table-responsive">
            <table class="table mb-0">

                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Tanggal</th>
                        <th>Jam Masuk</th>
                        <th>Jam Pulang</th>
                        <th>Terlambat</th>
                        <th>Status</th>
                        <th>Sumber</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$attendances): ?>

                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fa-solid fa-calendar-xmark fs-2 text-muted mb-3"></i>
                            <div class="fw-semibold">Belum ada data absensi</div>
                            <small class="text-muted">
                                Data absensi akan muncul di sini.
                            </small>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($attendances as $row): ?>

                        <tr>

                            <td>
                                <div class="d-flex align-items-center gap-2">

                                    <div class="employee-avatar">
                                        <?= e(strtoupper(substr($row['employee_name'], 0, 1))) ?>
                                    </div>

                                    <div>
                                        <div class="fw-bold">
                                            <?= e($row['employee_name']) ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= e($row['nik']) ?>
                                        </small>
                                        <?php if (!empty($row['department_name'])): ?>
                                            <div class="text-muted" style="font-size:11px">
                                                <?= e($row['department_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </td>

                            <td>
                                <?= e(date('d/m/Y', strtotime($row['attendance_date']))) ?>
                            </td>

                            <td>
                                <?= $row['clock_in_at']
                                    ? e(date('H:i', strtotime($row['clock_in_at'])))
                                    : '-' ?>
                            </td>

                            <td>
                                <?= $row['clock_out_at']
                                    ? e(date('H:i', strtotime($row['clock_out_at'])))
                                    : '-' ?>
                            </td>

                            <td>
                                <?php if ((int)$row['late_minutes'] > 0): ?>
                                    <span class="text-warning fw-semibold">
                                        <?= (int)$row['late_minutes'] ?> menit
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e(attendance_status_class($row['status'])) ?>">
                                    <?= e(attendance_status_label($row['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <?php
                                $sourceLabels = [
                                    'rfid' => 'RFID',
                                    'web' => 'Web',
                                    'mobile' => 'Mobile',
                                    'admin' => 'Admin',
                                    'api' => 'API'
                                ];

                                $source = $row['clock_in_method'] ?: $row['clock_out_method'];
                                ?>
                                <span class="text-muted">
                                    <?= e($sourceLabels[$source] ?? '-') ?>
                                </span>
                            </td>

                            <td class="text-end">

                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm" data-bs-toggle="dropdown">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>
                                            <button
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#statusModal"
                                                data-id="<?= (int)$row['id'] ?>"
                                                data-status="<?= e($row['status']) ?>"
                                            >
                                                <i class="fa-solid fa-pen me-2"></i>
                                                Ubah Status
                                            </button>
                                        </li>

                                        <li>
                                            <button
                                                class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#detailModal"
                                                data-name="<?= e($row['employee_name']) ?>"
                                                data-nik="<?= e($row['nik']) ?>"
                                                data-date="<?= e($row['attendance_date']) ?>"
                                                data-checkin="<?= e($row['clock_in_at']) ?>"
                                                data-checkout="<?= e($row['clock_out_at']) ?>"
                                                data-status="<?= e(attendance_status_label($row['status'])) ?>"
                                                data-location="<?= e($row['location_name'] ?? '-') ?>"
                                                data-notes="<?= e($row['notes'] ?? '-') ?>"
                                                data-late="<?= (int)$row['late_minutes'] ?>"
                                                data-work="<?= (int)$row['work_minutes'] ?>"
                                            >
                                                <i class="fa-solid fa-eye me-2"></i>
                                                Detail
                                            </button>
                                        </li>

                                        <li>
                                            <hr class="dropdown-divider">
                                        </li>

                                        <li>
                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Yakin ingin menghapus data absensi ini?')"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= e(attendly_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="attendance_id" value="<?= (int)$row['id'] ?>">

                                                <button class="dropdown-item text-danger" type="submit">
                                                    <i class="fa-solid fa-trash me-2"></i>
                                                    Hapus
                                                </button>
                                            </form>
                                        </li>

                                    </ul>
                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">

            <small class="text-muted">
                Menampilkan <?= count($attendances) ?> dari <?= number_format($totalRows) ?> data
            </small>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($paginationUrl(max(1, $page - 1))) ?>">
                            Sebelumnya
                        </a>
                    </li>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($paginationUrl($i)) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($paginationUrl(min($totalPages, $page + 1))) ?>">
                            Berikutnya
                        </a>
                    </li>

                </ul>
            </nav>

        </div>

    </div>

</main>

<!-- MODAL TAMBAH ABSENSI -->
<div class="modal fade" id="manualModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">

            <form method="POST">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Tambah Absensi Manual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf_token" value="<?= e(attendly_csrf_token()) ?>">
                    <input type="hidden" name="action" value="manual_attendance">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Karyawan</label>

                        <select name="employee_id" class="form-select" required>
                            <option value="">Pilih Karyawan</option>

                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= (int)$employee['id'] ?>">
                                    <?= e($employee['nik'] . ' - ' . $employee['name']) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tanggal Absensi</label>
                        <input
                            type="date"
                            name="attendance_date"
                            class="form-control"
                            value="<?= e(date('Y-m-d')) ?>"
                            required
                        >
                    </div>

                    <div class="row g-3">

                        <div class="col-6">
                            <label class="form-label fw-semibold">Jam Masuk</label>
                            <input type="time" name="check_in" class="form-control">
                        </div>

                        <div class="col-6">
                            <label class="form-label fw-semibold">Jam Pulang</label>
                            <input type="time" name="check_out" class="form-control">
                        </div>

                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Status</label>

                        <select name="status" class="form-select" required>
                            <option value="present">Hadir</option>
                            <option value="late">Terlambat</option>
                            <option value="permission">Izin</option>
                            <option value="sick">Sakit</option>
                            <option value="leave">Cuti</option>
                            <option value="absent">Alpha</option>
                            <option value="holiday">Libur</option>
                            <option value="off_day">Hari Libur Kerja</option>
                        </select>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea
                            name="notes"
                            class="form-control"
                            rows="3"
                            placeholder="Catatan tambahan (opsional)"
                        ></textarea>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Batal
                    </button>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i>
                        Simpan Absensi
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- MODAL UBAH STATUS -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">

            <form method="POST">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Ubah Status Absensi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden" name="csrf_token" value="<?= e(attendly_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="attendance_id" id="statusAttendanceId">

                    <label class="form-label fw-semibold">Status Absensi</label>

                    <select name="status" id="attendanceStatus" class="form-select" required>
                        <option value="present">Hadir</option>
                        <option value="late">Terlambat</option>
                        <option value="permission">Izin</option>
                        <option value="sick">Sakit</option>
                        <option value="leave">Cuti</option>
                        <option value="absent">Alpha</option>
                        <option value="holiday">Libur</option>
                        <option value="off_day">Hari Libur Kerja</option>
                    </select>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Batal
                    </button>

                    <button type="submit" class="btn btn-primary">
                        Simpan Perubahan
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<!-- MODAL DETAIL ABSENSI -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">

            <div class="modal-header">
                <h5 class="modal-title fw-bold">Detail Absensi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="mb-3">
                    <small class="text-muted">Nama Karyawan</small>
                    <div class="fw-bold" id="detailName">-</div>
                    <small class="text-muted" id="detailNik">-</small>
                </div>

                <hr>

                <div class="row g-3">

                    <div class="col-6">
                        <small class="text-muted">Tanggal</small>
                        <div class="fw-semibold" id="detailDate">-</div>
                    </div>

                    <div class="col-6">
                        <small class="text-muted">Status</small>
                        <div class="fw-semibold" id="detailStatus">-</div>
                    </div>

                    <div class="col-6">
                        <small class="text-muted">Jam Masuk</small>
                        <div class="fw-semibold" id="detailCheckIn">-</div>
                    </div>

                    <div class="col-6">
                        <small class="text-muted">Jam Pulang</small>
                        <div class="fw-semibold" id="detailCheckOut">-</div>
                    </div>

                    <div class="col-6">
                        <small class="text-muted">Terlambat</small>
                        <div class="fw-semibold" id="detailLate">-</div>
                    </div>

                    <div class="col-6">
                        <small class="text-muted">Durasi Kerja</small>
                        <div class="fw-semibold" id="detailWork">-</div>
                    </div>

                    <div class="col-12">
                        <small class="text-muted">Lokasi</small>
                        <div class="fw-semibold" id="detailLocation">-</div>
                    </div>

                    <div class="col-12">
                        <small class="text-muted">Catatan</small>
                        <div class="fw-semibold" id="detailNotes">-</div>
                    </div>

                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                    Tutup
                </button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});

/* UBAH STATUS */
document.getElementById('statusModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;

    document.getElementById('statusAttendanceId').value = button.dataset.id;
    document.getElementById('attendanceStatus').value = button.dataset.status;
});

/* DETAIL ABSENSI */
document.getElementById('detailModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;

    const setText = (id, value) => {
        document.getElementById(id).textContent = value || '-';
    };

    const formatDate = value => {
        if (!value) return '-';

        const date = new Date(value + 'T00:00:00');

        return date.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        });
    };

    const formatDateTime = value => {
        if (!value) return '-';

        const date = new Date(value.replace(' ', 'T'));

        return date.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit'
        });
    };

    const formatMinutes = value => {
        const minutes = parseInt(value || 0);

        if (!minutes) return '-';

        const hours = Math.floor(minutes / 60);
        const remaining = minutes % 60;

        if (hours > 0) {
            return hours + ' jam ' + remaining + ' menit';
        }

        return remaining + ' menit';
    };

    setText('detailName', button.dataset.name);
    setText('detailNik', button.dataset.nik);
    setText('detailDate', formatDate(button.dataset.date));
    setText('detailStatus', button.dataset.status);
    setText('detailCheckIn', formatDateTime(button.dataset.checkin));
    setText('detailCheckOut', formatDateTime(button.dataset.checkout));
    setText('detailLate', formatMinutes(button.dataset.late));
    setText('detailWork', formatMinutes(button.dataset.work));
    setText('detailLocation', button.dataset.location);
    setText('detailNotes', button.dataset.notes);
});
</script>

</body>
</html>

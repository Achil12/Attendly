
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = (int) attendly_company_id();
$userEmail = trim($user['email'] ?? '');

date_default_timezone_set('Asia/Jakarta');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$employee = null;
$attendances = [];
$error = '';

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}

$allowedStatuses = ['hadir', 'terlambat', 'izin', 'sakit', 'alpha'];

if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$totalRecords = 0;
$totalPages = 1;

$statusLabels = [
    'hadir' => 'Hadir',
    'terlambat' => 'Terlambat',
    'izin' => 'Izin',
    'sakit' => 'Sakit',
    'alpha' => 'Tidak Hadir'
];

$statusClasses = [
    'hadir' => 'success',
    'terlambat' => 'warning',
    'izin' => 'info',
    'sakit' => 'primary',
    'alpha' => 'danger'
];

$summary = [
    'hadir' => 0,
    'terlambat' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT id, name, nik
        FROM employees
        WHERE company_id = ? AND email = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $userEmail]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        throw new RuntimeException('Data karyawan tidak ditemukan.');
    }

    $employeeId = (int)$employee['id'];

    // Statistik periode terpilih.
    $stmt = $pdo->prepare("
        SELECT LOWER(status) AS attendance_status, COUNT(*) AS total
        FROM attendances
        WHERE company_id = ?
          AND employee_id = ?
          AND attendance_date BETWEEN ? AND ?
        GROUP BY LOWER(status)
    ");

    $stmt->execute([$companyId, $employeeId, $startDate, $endDate]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['attendance_status'];

        if (isset($summary[$key])) {
            $summary[$key] = (int)$row['total'];
        }
    }

    $where = [
        'company_id = ?',
        'employee_id = ?',
        'attendance_date BETWEEN ? AND ?'
    ];

    $params = [$companyId, $employeeId, $startDate, $endDate];

    if ($statusFilter !== '') {
        $where[] = 'LOWER(status) = ?';
        $params[] = $statusFilter;
    }

    if ($search !== '') {
        $where[] = '(
            CAST(attendance_date AS CHAR) LIKE ?
            OR COALESCE(source, "") LIKE ?
            OR COALESCE(notes, "") LIKE ?
        )';

        $keyword = '%' . $search . '%';
        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM attendances
        WHERE $whereSql
    ");
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT attendance_date, check_in, check_out, status, source, notes
        FROM attendances
        WHERE $whereSql
        ORDER BY attendance_date DESC
        LIMIT $perPage OFFSET $offset
    ");

    $stmt->execute($params);
    $attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    $error = $ex instanceof RuntimeException
        ? $ex->getMessage()
        : 'Gagal mengambil data riwayat absensi.';

    error_log('Riwayat Absensi Karyawan: ' . $ex->getMessage());
}

$totalAttendance = array_sum($summary);

function pageUrl($page) {
    $params = $_GET;
    $params['page'] = $page;

    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Riwayat Absensi | Attendly</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
    --border: #e9edf3;
    --muted: #8792a5;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--background);
    color: #263449;
    font-family: Inter, sans-serif;
    font-size: 14px;
}

.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: 255px;
    padding: 25px 16px;
    background: var(--sidebar);
    color: white;
    overflow-y: auto;
    z-index: 1000;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 12px 30px;
    font-size: 21px;
    font-weight: 800;
}

.brand-icon {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: var(--primary);
}

.menu-label {
    margin: 18px 12px 9px;
    color: #8997ad;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 13px;
    margin-bottom: 5px;
    border-radius: 9px;
    color: #b9c4d4;
    text-decoration: none;
    font-size: 13px;
}

.sidebar a:hover,
.sidebar a.active {
    color: white;
    background: #2b405f;
}

.sidebar a.active {
    box-shadow: inset 3px 0 var(--primary);
}

.sidebar a i {
    width: 18px;
    text-align: center;
}

.sidebar-footer {
    margin: 35px 10px 0;
    padding: 17px 10px;
    border-top: 1px solid #34435a;
    color: #8997ad;
    font-size: 11px;
}

.main-content {
    margin-left: 255px;
    padding: 30px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
}

.page-title {
    font-size: 24px;
    font-weight: 800;
    margin: 0;
}

.page-subtitle {
    color: var(--muted);
    margin-top: 6px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.avatar {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    font-weight: 800;
}

.profile-name {
    font-size: 13px;
    font-weight: 700;
}

.profile-role {
    font-size: 11px;
    color: var(--muted);
}

.stat-card,
.content-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.stat-card {
    padding: 20px;
    height: 100%;
}

.stat-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: #edf3ff;
    color: var(--primary);
    margin-bottom: 15px;
}

.stat-value {
    font-size: 25px;
    font-weight: 800;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.content-card {
    overflow: hidden;
    margin-bottom: 22px;
}

.card-heading {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
}

.card-heading h5 {
    font-size: 15px;
    font-weight: 800;
    margin: 0;
}

.card-heading p {
    color: var(--muted);
    font-size: 12px;
    margin: 5px 0 0;
}

.card-body {
    padding: 22px;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    color: #56647a;
}

.form-control,
.form-select {
    padding: 10px 12px;
    border-radius: 8px;
    border-color: #e2e7ef;
    font-size: 12px;
}

.table {
    font-size: 12px;
    vertical-align: middle;
}

.table thead th {
    background: #f8f9fc;
    color: #69768a;
    font-size: 11px;
    font-weight: 700;
    padding: 14px;
    white-space: nowrap;
}

.table tbody td {
    padding: 14px;
}

.btn {
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 15px;
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
}

.pagination .page-link {
    color: var(--primary);
    font-size: 12px;
}

.pagination .active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

@media(max-width:991px) {
    .sidebar {
        transform: translateX(-100%);
        transition: .25s;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
        padding: 20px;
    }
}

@media(max-width:576px) {
    .main-content { padding: 15px; }
    .page-title { font-size: 20px; }
    .profile-info { display: none; }
}
</style>
</head>

<body>

<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="fa-solid fa-fingerprint"></i>
        </div>
        Attendly
    </div>

    <div class="menu-label">Menu Karyawan</div>

    <a href="dashboard_karyawan.php">
        <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>

    <a href="profil.php">
        <i class="fa-solid fa-user"></i> Profil Saya
    </a>

    <a href="absensi_saya.php" class="active">
        <i class="fa-solid fa-fingerprint"></i> Riwayat Absensi
    </a>

    <a href="pengajuan_izin.php">
        <i class="fa-solid fa-envelope-open-text"></i> Pengajuan Izin
    </a>

    <a href="jadwal_saya.php">
        <i class="fa-solid fa-calendar-days"></i> Jadwal Kerja
    </a>

    <div class="menu-label">Akun</div>

    <a href="logout.php" onclick="return confirm('Apakah Anda yakin ingin logout?')">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly Employee</div>
        <div>Versi 1.0.0</div>
    </div>

</aside>

<main class="main-content">

    <div class="topbar">
        <div>
            <button class="btn btn-light d-lg-none mb-3" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <h1 class="page-title">Riwayat Absensi</h1>
            <div class="page-subtitle">Lihat dan pantau catatan kehadiran Anda.</div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($employee['name'] ?? ($user['name'] ?? 'K'), 0, 1))) ?>
            </div>

            <div class="profile-info">
                <div class="profile-name"><?= e($employee['name'] ?? ($user['name'] ?? 'Karyawan')) ?></div>
                <div class="profile-role">Karyawan</div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <div class="stat-value"><?= $summary['hadir'] ?></div>
                <div class="stat-label">Hadir</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                <div class="stat-value"><?= $summary['terlambat'] ?></div>
                <div class="stat-label">Terlambat</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-envelope"></i></div>
                <div class="stat-value"><?= $summary['izin'] ?></div>
                <div class="stat-label">Izin</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-notes-medical"></i></div>
                <div class="stat-value"><?= $summary['sakit'] ?></div>
                <div class="stat-label">Sakit</div>
            </div>
        </div>

        <div class="col-6 col-xl">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-user-xmark"></i></div>
                <div class="stat-value"><?= $summary['alpha'] ?></div>
                <div class="stat-label">Tidak Hadir</div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="card-heading">
            <h5><i class="fa-solid fa-filter text-primary me-2"></i>Filter Riwayat Absensi</h5>
            <p>Gunakan filter untuk mencari catatan kehadiran berdasarkan periode.</p>
        </div>

        <div class="card-body">

            <form method="GET">
                <div class="row g-3 align-items-end">

                    <div class="col-md-3">
                        <label class="form-label">Tanggal Mulai</label>
                        <input type="date" name="start_date" class="form-control"
                               value="<?= e($startDate) ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Tanggal Akhir</label>
                        <input type="date" name="end_date" class="form-control"
                               value="<?= e($endDate) ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">Semua Status</option>
                            <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?= e($key) ?>"
                                    <?= $statusFilter === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Pencarian</label>
                        <input type="text" name="search" class="form-control"
                               placeholder="Tanggal, sumber..."
                               value="<?= e($search) ?>">
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fa-solid fa-search me-1"></i> Filter
                        </button>

                        <a href="absensi_saya.php" class="btn btn-light">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                    </div>

                </div>
            </form>

        </div>
    </div>

    <div class="content-card">

        <div class="card-heading d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5><i class="fa-solid fa-list text-primary me-2"></i>Daftar Absensi</h5>
                <p>Total <?= $totalRecords ?> catatan ditemukan.</p>
            </div>

            <button class="btn btn-outline-primary" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i> Cetak
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>TANGGAL</th>
                        <th>JAM MASUK</th>
                        <th>JAM PULANG</th>
                        <th>STATUS</th>
                        <th>SUMBER</th>
                        <th>CATATAN</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$attendances): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fa-solid fa-calendar-xmark fs-3 d-block mb-2"></i>
                            Tidak ada data absensi.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($attendances as $index => $row): ?>
                        <?php
                        $status = strtolower($row['status'] ?? '');
                        $label = $statusLabels[$status] ?? $row['status'];
                        $class = $statusClasses[$status] ?? 'secondary';
                        ?>

                        <tr>
                            <td><?= $offset + $index + 1 ?></td>

                            <td>
                                <strong>
                                    <?= e(date('d/m/Y', strtotime($row['attendance_date']))) ?>
                                </strong>
                            </td>

                            <td>
                                <?= $row['check_in']
                                    ? e(date('H:i', strtotime($row['check_in'])))
                                    : '-' ?>
                            </td>

                            <td>
                                <?= $row['check_out']
                                    ? e(date('H:i', strtotime($row['check_out'])))
                                    : '-' ?>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e($class) ?>">
                                    <?= e($label) ?>
                                </span>
                            </td>

                            <td><?= e(strtoupper($row['source'] ?? '-')) ?></td>

                            <td><?= e($row['notes'] ?? '-') ?></td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">

                <small class="text-muted">
                    Halaman <?= $page ?> dari <?= $totalPages ?>
                </small>

                <nav>
                    <ul class="pagination pagination-sm mb-0">

                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(pageUrl(max(1, $page - 1))) ?>">
                                Sebelumnya
                            </a>
                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(pageUrl($i)) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(pageUrl(min($totalPages, $page + 1))) ?>">
                                Berikutnya
                            </a>
                        </li>

                    </ul>
                </nav>

            </div>
        <?php endif; ?>

    </div>

    <div class="text-center text-muted small py-3">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});
</script>

</body>
</html>

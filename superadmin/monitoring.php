<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();

$roleStmt = $pdo->prepare("
    SELECT LOWER(r.name)
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
");
$roleStmt->execute([$user['id'] ?? 0]);
$roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('super admin', $roles, true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getCount(PDO $pdo, string $sql): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function getRows(PDO $pdo, string $sql): array
{
    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = ?
            AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$companyTotal = getCount($pdo, "SELECT COUNT(*) FROM companies");
$companyActive = getCount($pdo, "SELECT COUNT(*) FROM companies WHERE status = 'active'");
$userTotal = getCount($pdo, "SELECT COUNT(*) FROM users");
$employeeTotal = getCount($pdo, "SELECT COUNT(*) FROM employees");
$subscriptionActive = getCount($pdo, "SELECT COUNT(*) FROM subscriptions WHERE status = 'active'");

$attendanceToday = 0;
if (tableExists($pdo, 'attendances') && columnExists($pdo, 'attendances', 'attendance_date')) {
    $attendanceToday = getCount(
        $pdo,
        "SELECT COUNT(*) FROM attendances WHERE attendance_date = CURDATE()"
    );
}

$databaseName = 'Tidak diketahui';
$databaseVersion = 'Tidak diketahui';
$databaseSize = 0;

try {
    $databaseName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $databaseVersion = $pdo->query("SELECT VERSION()")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(data_length + index_length), 0)
        FROM information_schema.tables
        WHERE table_schema = ?
    ");
    $stmt->execute([$databaseName]);
    $databaseSize = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
}

$databaseSizeMB = round($databaseSize / 1024 / 1024, 2);

$phpVersion = PHP_VERSION;
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Tidak diketahui';
$serverTime = date('d-m-Y H:i:s');
$timezone = date_default_timezone_get();

$diskTotal = @disk_total_space(__DIR__);
$diskFree = @disk_free_space(__DIR__);
$diskUsed = ($diskTotal && $diskFree !== false)
    ? $diskTotal - $diskFree
    : 0;

$diskPercent = $diskTotal > 0
    ? round(($diskUsed / $diskTotal) * 100, 1)
    : 0;

$diskTotalGB = $diskTotal ? round($diskTotal / 1024 / 1024 / 1024, 2) : 0;
$diskUsedGB = round($diskUsed / 1024 / 1024 / 1024, 2);
$diskFreeGB = $diskFree !== false
    ? round($diskFree / 1024 / 1024 / 1024, 2)
    : 0;

$companyGrowth = getRows($pdo, "
    SELECT DATE(created_at) AS tanggal, COUNT(*) AS total
    FROM companies
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY tanggal ASC
");

$activityLogs = [];

if (tableExists($pdo, 'activity_logs')) {
    $hasCompany = columnExists($pdo, 'activity_logs', 'company_id');
    $hasUser = columnExists($pdo, 'activity_logs', 'user_id');
    $hasAction = columnExists($pdo, 'activity_logs', 'action');
    $hasDescription = columnExists($pdo, 'activity_logs', 'description');
    $hasCreated = columnExists($pdo, 'activity_logs', 'created_at');

    $select = ['al.id'];

    if ($hasAction) {
        $select[] = 'al.action';
    }

    if ($hasDescription) {
        $select[] = 'al.description';
    }

    if ($hasCreated) {
        $select[] = 'al.created_at';
    }

    if ($hasCompany) {
        $select[] = 'c.name AS company_name';
    }

    if ($hasUser) {
        $select[] = 'u.name AS user_name';
    }

    $joins = '';

    if ($hasCompany) {
        $joins .= ' LEFT JOIN companies c ON c.id = al.company_id';
    }

    if ($hasUser) {
        $joins .= ' LEFT JOIN users u ON u.id = al.user_id';
    }

    try {
        $activityLogs = $pdo->query("
            SELECT " . implode(', ', $select) . "
            FROM activity_logs al
            {$joins}
            ORDER BY al.id DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $activityLogs = [];
    }
}

$companyStatus = getRows($pdo, "
    SELECT status, COUNT(*) AS total
    FROM companies
    GROUP BY status
");

$subscriptionStatus = getRows($pdo, "
    SELECT status, COUNT(*) AS total
    FROM subscriptions
    GROUP BY status
");

$systemChecks = [
    [
        'name' => 'PHP',
        'value' => PHP_VERSION,
        'status' => version_compare(PHP_VERSION, '8.0.0', '>=')
    ],
    [
        'name' => 'PDO MySQL',
        'value' => extension_loaded('pdo_mysql') ? 'Aktif' : 'Tidak aktif',
        'status' => extension_loaded('pdo_mysql')
    ],
    [
        'name' => 'MySQL Connection',
        'value' => 'Terhubung',
        'status' => true
    ],
    [
        'name' => 'OpenSSL',
        'value' => extension_loaded('openssl') ? 'Aktif' : 'Tidak aktif',
        'status' => extension_loaded('openssl')
    ],
    [
        'name' => 'JSON',
        'value' => extension_loaded('json') ? 'Aktif' : 'Tidak aktif',
        'status' => extension_loaded('json')
    ],
    [
        'name' => 'File Upload',
        'value' => ini_get('file_uploads') ? 'Aktif' : 'Tidak aktif',
        'status' => (bool)ini_get('file_uploads')
    ],
    [
        'name' => 'Session',
        'value' => session_status() === PHP_SESSION_ACTIVE ? 'Aktif' : 'Tidak aktif',
        'status' => session_status() === PHP_SESSION_ACTIVE
    ]
];

$healthyChecks = count(array_filter($systemChecks, fn($item) => $item['status']));
$totalChecks = count($systemChecks);

$healthPercentage = $totalChecks > 0
    ? round(($healthyChecks / $totalChecks) * 100)
    : 0;

$chartLabels = [];
$chartValues = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('d M', strtotime($date));
    $chartValues[$date] = 0;
}

foreach ($companyGrowth as $item) {
    $date = $item['tanggal'];
    if (isset($chartValues[$date])) {
        $chartValues[$date] = (int)$item['total'];
    }
}

$chartValues = array_values($chartValues);

$memoryLimit = ini_get('memory_limit');
$maxExecution = ini_get('max_execution_time');
$uploadLimit = ini_get('upload_max_filesize');
$postLimit = ini_get('post_max_size');

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitoring Sistem | Attendly Super Admin</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
    --text: #253047;
    --muted: #8290a5;
}

* {
    font-family: Inter, sans-serif;
}

body {
    background: var(--background);
    color: var(--text);
}

.sidebar {
    width: 255px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--sidebar);
    color: white;
    padding: 24px 16px;
    overflow-y: auto;
    z-index: 1000;
}

.brand {
    font-size: 22px;
    font-weight: 800;
    padding: 0 12px 28px;
}

.brand span {
    color: #67a0ff;
}

.menu-label {
    color: #8795aa;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    padding: 14px 12px 8px;
}

.sidebar .nav-link {
    color: #bdc8d9;
    padding: 11px 13px;
    border-radius: 9px;
    margin-bottom: 4px;
    font-size: 13px;
}

.sidebar .nav-link i {
    width: 23px;
}

.sidebar .nav-link:hover,
.sidebar .nav-link.active {
    background: #263c5e;
    color: white;
}

.sidebar-footer {
    border-top: 1px solid #34435a;
    margin-top: 25px;
    padding: 18px 12px 0;
    color: #aab7ca;
    font-size: 12px;
}

.main {
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
    font-size: 22px;
    font-weight: 800;
}

.page-description {
    color: var(--muted);
    font-size: 13px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #e5edff;
    color: var(--primary);
    font-weight: 800;
}

.stat-card,
.content-card {
    background: white;
    border: 1px solid #edf0f5;
    border-radius: 14px;
    padding: 20px;
    height: 100%;
}

.stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    background: #edf3ff;
    color: var(--primary);
    margin-bottom: 14px;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.stat-value {
    font-size: 23px;
    font-weight: 800;
    margin-top: 5px;
}

.section-title {
    font-size: 14px;
    font-weight: 700;
}

.table th {
    color: #8290a5;
    font-size: 11px;
    text-transform: uppercase;
    background: #f9fafc;
    white-space: nowrap;
}

.table td {
    font-size: 12px;
    vertical-align: middle;
}

.status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
}

.progress {
    height: 8px;
    background: #edf0f5;
}

.progress-bar {
    border-radius: 10px;
}

.system-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 13px 0;
    border-bottom: 1px solid #edf0f5;
    font-size: 12px;
}

.system-item:last-child {
    border-bottom: 0;
}

.health-score {
    font-size: 38px;
    font-weight: 800;
}

.btn {
    font-size: 12px;
    font-weight: 600;
    border-radius: 8px;
    padding: 9px 13px;
}

@media(max-width: 991px) {
    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main {
        margin-left: 0;
        padding: 18px;
    }
}
</style>
</head>

<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <i class="fa-solid fa-fingerprint me-2"></i>Attend<span>ly</span>
    </div>

    <div class="menu-label">MENU UTAMA</div>
    <nav class="nav flex-column">
        <a href="dashboard.php" class="nav-link">
            <i class="fa-solid fa-gauge-high"></i> Dashboard
        </a>
        <a href="perusahaan.php" class="nav-link">
            <i class="fa-solid fa-building"></i> Perusahaan
        </a>
        <a href="pengguna.php" class="nav-link">
            <i class="fa-solid fa-users"></i> Pengguna
        </a>
    </nav>

    <div class="menu-label">SAAS MANAGEMENT</div>
    <nav class="nav flex-column">
        <a href="paket.php" class="nav-link">
            <i class="fa-solid fa-box"></i> Paket Langganan
        </a>
        <a href="subscription.php" class="nav-link">
            <i class="fa-solid fa-repeat"></i> Subscription
        </a>
        <a href="transaksi.php" class="nav-link">
            <i class="fa-solid fa-money-bill-transfer"></i> Transaksi
        </a>
        <a href="invoice.php" class="nav-link">
            <i class="fa-solid fa-file-invoice"></i> Invoice
        </a>
        <a href="payment_gateway.php" class="nav-link">
            <i class="fa-solid fa-credit-card"></i> Payment Gateway
        </a>
    </nav>

    <div class="menu-label">SISTEM</div>
    <nav class="nav flex-column">
        <a href="monitoring.php" class="nav-link active">
            <i class="fa-solid fa-chart-line"></i> Monitoring
        </a>
        <a href="../ubah_password.php" class="nav-link">
            <i class="fa-solid fa-key"></i> Ubah Password
        </a>
        <a href="../logout.php" class="nav-link">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </nav>

    <div class="sidebar-footer">
        <strong>Attendly System</strong><br>
        Super Admin Panel · Versi 1.0.0
    </div>
</aside>

<main class="main">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light d-lg-none" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <div class="page-title">Monitoring Sistem</div>
                <div class="page-description">
                    Pantau kondisi server, database, dan aktivitas platform Attendly.
                </div>
            </div>
        </div>

        <div class="profile">
            <div class="avatar">AD</div>
            <div>
                <div class="fw-bold small"><?= e($user['name'] ?? 'Administrator') ?></div>
                <div class="text-muted" style="font-size:11px">Super Admin</div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div class="text-muted small">
            <i class="fa-solid fa-clock me-1"></i>
            Waktu server: <?= e($serverTime) ?>
        </div>

        <button class="btn btn-outline-primary" onclick="location.reload()">
            <i class="fa-solid fa-rotate me-1"></i> Refresh Monitoring
        </button>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-building"></i></div>
                <div class="stat-label">Total Perusahaan</div>
                <div class="stat-value"><?= number_format($companyTotal) ?></div>
                <small class="text-success">
                    <?= number_format($companyActive) ?> perusahaan aktif
                </small>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                <div class="stat-label">Total Pengguna</div>
                <div class="stat-value"><?= number_format($userTotal) ?></div>
                <small class="text-muted">Seluruh akun platform</small>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-id-card"></i></div>
                <div class="stat-label">Total Karyawan</div>
                <div class="stat-value"><?= number_format($employeeTotal) ?></div>
                <small class="text-muted">Seluruh perusahaan</small>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                <div class="stat-label">Absensi Hari Ini</div>
                <div class="stat-value"><?= number_format($attendanceToday) ?></div>
                <small class="text-muted">Seluruh perusahaan</small>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-lg-8">
            <div class="content-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <div class="section-title">Pertumbuhan Perusahaan</div>
                        <small class="text-muted">Registrasi perusahaan selama 7 hari terakhir</small>
                    </div>
                    <span class="badge text-bg-light">7 Hari</span>
                </div>

                <canvas id="companyChart" height="105"></canvas>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card">
                <div class="section-title mb-1">Kesehatan Sistem</div>
                <small class="text-muted">Pemeriksaan komponen aplikasi</small>

                <div class="text-center py-4">
                    <div class="health-score"><?= $healthPercentage ?>%</div>
                    <div class="text-muted small">Komponen terdeteksi normal</div>

                    <div class="progress mt-3">
                        <div class="progress-bar <?= $healthPercentage === 100 ? 'bg-success' : 'bg-warning' ?>"
                             style="width:<?= $healthPercentage ?>%"></div>
                    </div>

                    <div class="small text-muted mt-2">
                        <?= $healthyChecks ?> dari <?= $totalChecks ?> pemeriksaan normal
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-lg-6">
            <div class="content-card">
                <div class="section-title mb-3">Informasi Server</div>

                <div class="system-item">
                    <span><i class="fa-brands fa-php me-2 text-primary"></i>PHP Version</span>
                    <strong><?= e($phpVersion) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-server me-2 text-primary"></i>Web Server</span>
                    <strong class="text-end"><?= e($serverSoftware) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-clock me-2 text-primary"></i>Timezone</span>
                    <strong><?= e($timezone) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-memory me-2 text-primary"></i>Memory Limit</span>
                    <strong><?= e($memoryLimit) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-stopwatch me-2 text-primary"></i>Max Execution</span>
                    <strong><?= e($maxExecution) ?> detik</strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-upload me-2 text-primary"></i>Upload Limit</span>
                    <strong><?= e($uploadLimit) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-file-arrow-up me-2 text-primary"></i>POST Limit</span>
                    <strong><?= e($postLimit) ?></strong>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="content-card">
                <div class="section-title mb-3">Informasi Database</div>

                <div class="system-item">
                    <span><i class="fa-solid fa-database me-2 text-primary"></i>Database</span>
                    <strong><?= e($databaseName) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-code-branch me-2 text-primary"></i>Versi MySQL</span>
                    <strong><?= e($databaseVersion) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-hard-drive me-2 text-primary"></i>Ukuran Database</span>
                    <strong><?= number_format($databaseSizeMB, 2) ?> MB</strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-building me-2 text-primary"></i>Subscription Aktif</span>
                    <strong><?= number_format($subscriptionActive) ?></strong>
                </div>

                <div class="system-item">
                    <span><i class="fa-solid fa-link me-2 text-primary"></i>Koneksi Database</span>
                    <span class="badge text-bg-success">Terhubung</span>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-lg-6">
            <div class="content-card">
                <div class="section-title mb-3">Penggunaan Disk Server</div>

                <?php if ($diskTotal > 0): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Terpakai</span>
                        <strong class="small"><?= $diskPercent ?>%</strong>
                    </div>

                    <div class="progress mb-3">
                        <div class="progress-bar <?= $diskPercent >= 90 ? 'bg-danger' : ($diskPercent >= 75 ? 'bg-warning' : 'bg-primary') ?>"
                             style="width:<?= min(100, $diskPercent) ?>%"></div>
                    </div>

                    <div class="row text-center">
                        <div class="col-4">
                            <div class="fw-bold"><?= $diskTotalGB ?> GB</div>
                            <small class="text-muted">Total</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold"><?= $diskUsedGB ?> GB</div>
                            <small class="text-muted">Terpakai</small>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold"><?= $diskFreeGB ?> GB</div>
                            <small class="text-muted">Tersedia</small>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary small mb-0">
                        Informasi disk tidak tersedia pada server ini.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="content-card">
                <div class="section-title mb-3">Pemeriksaan Komponen</div>

                <?php foreach ($systemChecks as $check): ?>
                    <div class="system-item">
                        <span><?= e($check['name']) ?></span>

                        <?php if ($check['status']): ?>
                            <span class="text-success">
                                <i class="fa-solid fa-circle-check me-1"></i>
                                <?= e($check['value']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-danger">
                                <i class="fa-solid fa-circle-xmark me-1"></i>
                                <?= e($check['value']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

    <div class="content-card mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="section-title">Aktivitas Sistem Terbaru</div>
                <small class="text-muted">10 aktivitas terakhir yang tercatat</small>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Perusahaan</th>
                        <th>Aktivitas</th>
                        <th>Deskripsi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$activityLogs): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            Belum ada aktivitas yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activityLogs as $log): ?>
                        <tr>
                            <td>
                                <?= !empty($log['created_at'])
                                    ? date('d/m/Y H:i', strtotime($log['created_at']))
                                    : '-' ?>
                            </td>
                            <td><?= e($log['user_name'] ?? '-') ?></td>
                            <td><?= e($log['company_name'] ?? '-') ?></td>
                            <td>
                                <span class="badge text-bg-light border">
                                    <?= e($log['action'] ?? '-') ?>
                                </span>
                            </td>
                            <td><?= e($log['description'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-center text-muted mt-4" style="font-size:11px">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});

const chartLabels = <?= json_encode($chartLabels) ?>;
const chartValues = <?= json_encode($chartValues) ?>;

new Chart(document.getElementById('companyChart'), {
    type: 'line',
    data: {
        labels: chartLabels,
        datasets: [{
            label: 'Perusahaan Baru',
            data: chartValues,
            borderColor: '#3979f6',
            backgroundColor: 'rgba(57, 121, 246, 0.10)',
            fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 4,
            pointBackgroundColor: '#3979f6'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        }
    }
});
</script>

</body>
</html>

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = (int) attendly_company_id();
$userId = (int)($user['id'] ?? 0);
$userEmail = trim($user['email'] ?? '');

date_default_timezone_set('Asia/Jakarta');

$today = date('Y-m-d');
$currentMonth = date('m');
$currentYear = date('Y');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$employee = null;
$todayAttendance = null;
$recentAttendance = [];
$attendanceStats = [
    'hadir' => 0,
    'terlambat' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

$success = '';
$error = '';

try {
    // Menghubungkan akun login dengan data karyawan melalui email.
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            d.name AS department_name,
            p.name AS position_name
        FROM employees e
        LEFT JOIN departments d
            ON d.id = e.department_id
           AND d.company_id = e.company_id
        LEFT JOIN positions p
            ON p.id = e.position_id
           AND p.company_id = e.company_id
        WHERE e.company_id = ?
          AND e.email = ?
        LIMIT 1
    ");

    $stmt->execute([$companyId, $userEmail]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        throw new RuntimeException(
            'Data karyawan belum terhubung dengan akun login. Hubungi administrator.'
        );
    }

    $employeeId = (int)$employee['id'];

    // Absensi hari ini.
    $stmt = $pdo->prepare("
        SELECT *
        FROM attendances
        WHERE company_id = ?
          AND employee_id = ?
          AND attendance_date = ?
        LIMIT 1
    ");

    $stmt->execute([$companyId, $employeeId, $today]);
    $todayAttendance = $stmt->fetch(PDO::FETCH_ASSOC);

    // Statistik absensi bulan ini.
    $stmt = $pdo->prepare("
        SELECT
            LOWER(status) AS attendance_status,
            COUNT(*) AS total
        FROM attendances
        WHERE company_id = ?
          AND employee_id = ?
          AND MONTH(attendance_date) = ?
          AND YEAR(attendance_date) = ?
        GROUP BY LOWER(status)
    ");

    $stmt->execute([
        $companyId,
        $employeeId,
        $currentMonth,
        $currentYear
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = $row['attendance_status'];

        if (array_key_exists($status, $attendanceStats)) {
            $attendanceStats[$status] = (int)$row['total'];
        }
    }

    // Riwayat absensi terakhir.
    $stmt = $pdo->prepare("
        SELECT attendance_date, check_in, check_out, status, source
        FROM attendances
        WHERE company_id = ?
          AND employee_id = ?
        ORDER BY attendance_date DESC
        LIMIT 7
    ");

    $stmt->execute([$companyId, $employeeId]);
    $recentAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (RuntimeException $ex) {
    $error = $ex->getMessage();
} catch (PDOException $ex) {
    error_log('Dashboard Karyawan: ' . $ex->getMessage());
    $error = 'Terjadi kesalahan saat mengambil data dashboard.';
}

$employeeName = $employee['name'] ?? ($user['name'] ?? 'Karyawan');
$initials = strtoupper(substr($employeeName, 0, 1));
$photo = $employee['photo'] ?? '';
$monthName = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
    4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September',
    10 => 'Oktober', 11 => 'November', 12 => 'Desember'
][(int)$currentMonth];

$attendancePercentage = 0;
$totalRecorded = array_sum($attendanceStats);

if ($totalRecorded > 0) {
    $attendancePercentage = round(
        (($attendanceStats['hadir'] + $attendanceStats['terlambat']) / $totalRecorded) * 100
    );
}

$todayStatus = $todayAttendance['status'] ?? 'Belum Absen';

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

$todayLabel = $statusLabels[strtolower($todayStatus)] ?? $todayStatus;
$todayClass = $statusClasses[strtolower($todayStatus)] ?? 'secondary';
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Karyawan | Attendly</title>

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
    gap: 15px;
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
    overflow: hidden;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-name {
    font-size: 13px;
    font-weight: 700;
}

.profile-role {
    font-size: 11px;
    color: var(--muted);
}

.welcome-card {
    position: relative;
    padding: 28px;
    border-radius: 15px;
    background: linear-gradient(120deg, #2563eb, #5b91ff);
    color: white;
    overflow: hidden;
    margin-bottom: 22px;
}

.welcome-card h2 {
    font-size: 23px;
    font-weight: 800;
}

.welcome-card p {
    margin: 8px 0 0;
    opacity: .85;
}

.welcome-icon {
    position: absolute;
    right: 35px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 95px;
    opacity: .12;
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

.today-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 18px;
    border-radius: 11px;
    background: #f8faff;
    border: 1px solid var(--border);
}

.today-time {
    font-size: 22px;
    font-weight: 800;
}

.today-label {
    color: var(--muted);
    font-size: 11px;
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

.quick-link {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 15px;
    color: #263449;
    text-decoration: none;
    border: 1px solid var(--border);
    border-radius: 10px;
    transition: .2s;
}

.quick-link:hover {
    border-color: var(--primary);
    background: #f8faff;
    color: var(--primary);
}

.quick-icon {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    background: #edf3ff;
    color: var(--primary);
    border-radius: 10px;
}

.quick-link strong {
    display: block;
    font-size: 12px;
}

.quick-link small {
    color: var(--muted);
    font-size: 10px;
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
    .welcome-card { padding: 22px; }
    .welcome-card h2 { font-size: 19px; }
    .welcome-icon { font-size: 65px; right: 15px; }
    .today-box { align-items: flex-start; flex-direction: column; }
}
</style>
</head>

<body>

<!--navbar-->
<?php include "add/sidebar.php"; ?>

<main class="main-content">

    <div class="topbar">
        <div>
            <button class="btn btn-light d-lg-none mb-3" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <h1 class="page-title">Dashboard Karyawan</h1>
            <div class="page-subtitle">
                Pantau aktivitas dan kehadiran kerja Anda.
            </div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?php if ($photo): ?>
                    <img src="../uploads/employees/<?= e(basename($photo)) ?>" alt="Foto profil">
                <?php else: ?>
                    <?= e($initials) ?>
                <?php endif; ?>
            </div>

            <div class="profile-info">
                <div class="profile-name"><?= e($employeeName) ?></div>
                <div class="profile-role">Karyawan</div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="welcome-card">
        <div class="welcome-icon">
            <i class="fa-solid fa-fingerprint"></i>
        </div>

        <h2>Selamat datang, <?= e($employeeName) ?>!</h2>

        <p>
            <?= e(date('l, d F Y')) ?>.
            Semoga aktivitas kerja Anda hari ini berjalan lancar.
        </p>

        <?php if ($employee): ?>
            <div class="mt-3 small">
                <span class="me-3">
                    <i class="fa-solid fa-id-card me-1"></i>
                    NIK: <?= e($employee['nik'] ?? '-') ?>
                </span>

                <span class="me-3">
                    <i class="fa-solid fa-building me-1"></i>
                    <?= e($employee['department_name'] ?? '-') ?>
                </span>

                <span>
                    <i class="fa-solid fa-briefcase me-1"></i>
                    <?= e($employee['position_name'] ?? '-') ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?= $attendanceStats['hadir'] ?></div>
                <div class="stat-label">Hadir Bulan Ini</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-value"><?= $attendanceStats['terlambat'] ?></div>
                <div class="stat-label">Terlambat</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div class="stat-value"><?= $attendanceStats['izin'] + $attendanceStats['sakit'] ?></div>
                <div class="stat-label">Izin dan Sakit</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div class="stat-value"><?= $attendancePercentage ?>%</div>
                <div class="stat-label">Persentase Kehadiran</div>
            </div>
        </div>

    </div>

    <div class="row g-3">

        <div class="col-lg-7">

            <div class="content-card">
                <div class="card-heading">
                    <h5><i class="fa-solid fa-fingerprint text-primary me-2"></i>Absensi Hari Ini</h5>
                    <p>Status kehadiran Anda pada <?= e(date('d F Y')) ?>.</p>
                </div>

                <div class="card-body">

                    <div class="today-box mb-3">
                        <div>
                            <div class="today-label">Status Kehadiran</div>
                            <div class="mt-2">
                                <span class="badge text-bg-<?= e($todayClass) ?> px-3 py-2">
                                    <?= e($todayLabel) ?>
                                </span>
                            </div>
                        </div>

                        <div>
                            <div class="today-label">Jam Masuk</div>
                            <div class="today-time">
                                <?= !empty($todayAttendance['check_in'])
                                    ? e(date('H:i', strtotime($todayAttendance['check_in'])))
                                    : '--:--' ?>
                            </div>
                        </div>

                        <div>
                            <div class="today-label">Jam Pulang</div>
                            <div class="today-time">
                                <?= !empty($todayAttendance['check_out'])
                                    ? e(date('H:i', strtotime($todayAttendance['check_out'])))
                                    : '--:--' ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            Sumber: <?= e($todayAttendance['source'] ?? '-') ?>
                        </small>

                        <a href="absensi_saya.php" class="btn btn-primary btn-sm">
                            Lihat Riwayat
                            <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>

                </div>
            </div>

            <div class="content-card">
                <div class="card-heading">
                    <h5><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Riwayat Absensi Terakhir</h5>
                    <p>Catatan kehadiran terbaru Anda.</p>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>TANGGAL</th>
                                <th>MASUK</th>
                                <th>PULANG</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if (!$recentAttendance): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    Belum ada riwayat absensi.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentAttendance as $row): ?>
                                <?php
                                $rowStatus = strtolower($row['status'] ?? '');
                                $rowLabel = $statusLabels[$rowStatus] ?? $row['status'];
                                $rowClass = $statusClasses[$rowStatus] ?? 'secondary';
                                ?>
                                <tr>
                                    <td><?= e(date('d/m/Y', strtotime($row['attendance_date']))) ?></td>
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
                                        <span class="badge text-bg-<?= e($rowClass) ?>">
                                            <?= e($rowLabel) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <div class="col-lg-5">

            <div class="content-card">
                <div class="card-heading">
                    <h5><i class="fa-solid fa-bolt text-primary me-2"></i>Akses Cepat</h5>
                    <p>Akses fitur yang sering digunakan.</p>
                </div>

                <div class="card-body">
                    <div class="d-grid gap-3">

                        <a href="absensi_saya.php" class="quick-link">
                            <div class="quick-icon">
                                <i class="fa-solid fa-fingerprint"></i>
                            </div>
                            <div>
                                <strong>Riwayat Absensi</strong>
                                <small>Lihat seluruh catatan kehadiran</small>
                            </div>
                        </a>

                        <a href="pengajuan_izin.php" class="quick-link">
                            <div class="quick-icon">
                                <i class="fa-solid fa-envelope-open-text"></i>
                            </div>
                            <div>
                                <strong>Ajukan Izin</strong>
                                <small>Ajukan izin atau cuti kerja</small>
                            </div>
                        </a>

                        <a href="jadwal_saya.php" class="quick-link">
                            <div class="quick-icon">
                                <i class="fa-solid fa-calendar-days"></i>
                            </div>
                            <div>
                                <strong>Jadwal Kerja</strong>
                                <small>Lihat jadwal kerja Anda</small>
                            </div>
                        </a>

                        <a href="profil.php" class="quick-link">
                            <div class="quick-icon">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <div>
                                <strong>Profil Saya</strong>
                                <small>Periksa informasi akun dan karyawan</small>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <div class="content-card">
                <div class="card-heading">
                    <h5><i class="fa-solid fa-chart-line text-primary me-2"></i>Ringkasan Bulan Ini</h5>
                    <p>Rekap kehadiran <?= e($monthName) ?> <?= e($currentYear) ?>.</p>
                </div>

                <div class="card-body">

                    <?php
                    $summaryItems = [
                        ['Hadir', $attendanceStats['hadir'], 'success'],
                        ['Terlambat', $attendanceStats['terlambat'], 'warning'],
                        ['Izin', $attendanceStats['izin'], 'info'],
                        ['Sakit', $attendanceStats['sakit'], 'primary'],
                        ['Tidak Hadir', $attendanceStats['alpha'], 'danger']
                    ];
                    ?>

                    <?php foreach ($summaryItems as $item): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted"><?= e($item[0]) ?></span>
                            <span class="badge text-bg-<?= e($item[2]) ?>">
                                <?= (int)$item[1] ?> hari
                            </span>
                        </div>
                    <?php endforeach; ?>

                    <hr>

                    <div class="d-flex justify-content-between">
                        <strong>Total Catatan</strong>
                        <strong><?= $totalRecorded ?> hari</strong>
                    </div>

                </div>
            </div>

        </div>

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

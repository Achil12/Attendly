
<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = attendly_company_id();

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');
$todayLabel = date('d F Y');

$stats = [
    'total_employees' => 0,
    'active_employees' => 0,
    'inactive_employees' => 0,
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'permission' => 0,
    'sick' => 0,
    'leave' => 0,
];

$activities = [];
$chartLabels = [];
$chartPresent = [];
$chartLate = [];
$chartAbsent = [];
$companyName = 'Perusahaan';

try {
    // Identitas perusahaan
    $stmt = $pdo->prepare("
        SELECT name
        FROM companies
        WHERE id = :company_id
        LIMIT 1
    ");
    $stmt->execute(['company_id' => $companyId]);

    $company = $stmt->fetch();

    if ($company) {
        $companyName = $company['name'];
    }

    // Statistik karyawan
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_employees,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_employees,
            SUM(CASE WHEN status IN ('inactive', 'resigned') THEN 1 ELSE 0 END) AS inactive_employees
        FROM employees
        WHERE company_id = :company_id
    ");

    $stmt->execute(['company_id' => $companyId]);
    $employeeStats = $stmt->fetch();

    if ($employeeStats) {
        $stats['total_employees'] = (int)$employeeStats['total_employees'];
        $stats['active_employees'] = (int)$employeeStats['active_employees'];
        $stats['inactive_employees'] = (int)$employeeStats['inactive_employees'];
    }

    // Statistik absensi hari ini
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent,
            SUM(CASE WHEN status = 'permission' THEN 1 ELSE 0 END) AS permission,
            SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) AS sick,
            SUM(CASE WHEN status = 'leave' THEN 1 ELSE 0 END) AS leave_count
        FROM attendances
        WHERE company_id = :company_id
          AND attendance_date = :today
    ");

    $stmt->execute([
        'company_id' => $companyId,
        'today' => $today
    ]);

    $attendanceStats = $stmt->fetch();

    if ($attendanceStats) {
        $stats['present'] = (int)$attendanceStats['present'];
        $stats['late'] = (int)$attendanceStats['late'];
        $stats['absent'] = (int)$attendanceStats['absent'];
        $stats['permission'] = (int)$attendanceStats['permission'];
        $stats['sick'] = (int)$attendanceStats['sick'];
        $stats['leave'] = (int)$attendanceStats['leave_count'];
    }

    // Grafik absensi 7 hari terakhir
    $stmt = $pdo->prepare("
        SELECT
            attendance_date,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) AS late,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent
        FROM attendances
        WHERE company_id = :company_id
          AND attendance_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                                  AND CURDATE()
        GROUP BY attendance_date
        ORDER BY attendance_date ASC
    ");

    $stmt->execute(['company_id' => $companyId]);

    $chartData = [];

    foreach ($stmt->fetchAll() as $row) {
        $chartData[$row['attendance_date']] = $row;
    }

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));

        $chartLabels[] = date('d M', strtotime($date));

        $chartPresent[] = (int)($chartData[$date]['present'] ?? 0);
        $chartLate[] = (int)($chartData[$date]['late'] ?? 0);
        $chartAbsent[] = (int)($chartData[$date]['absent'] ?? 0);
    }

    // Aktivitas terbaru
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.action,
            a.module,
            a.description,
            a.created_at,
            u.name AS user_name
        FROM activity_logs a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.company_id = :company_id
        ORDER BY a.created_at DESC
        LIMIT 8
    ");

    $stmt->execute(['company_id' => $companyId]);
    $activities = $stmt->fetchAll();

} catch (PDOException $ex) {
    error_log('[ATTENDLY DASHBOARD] ' . $ex->getMessage());

    http_response_code(500);
    exit('Gagal mengambil data dashboard. Periksa konfigurasi dan struktur database.');
}

// Total tercatat hadir
$totalPresent = $stats['present'] + $stats['late'];

// Data grafik untuk JavaScript
$chartLabelsJson = json_encode(
    $chartLabels,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$chartPresentJson = json_encode($chartPresent);
$chartLateJson = json_encode($chartLate);
$chartAbsentJson = json_encode($chartAbsent);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Dashboard | Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3979f6;
            --sidebar: #17243a;
            --background: #f5f7fb;
            --text: #1e293b;
            --muted: #64748b;
            --border: #edf0f5;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
        }

        .sidebar {
            width: 255px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: var(--sidebar);
            color: #fff;
            padding: 24px 16px;
            overflow-y: auto;
            z-index: 1000;
            transition: .3s;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 800;
            margin: 0 8px 35px;
            color: #fff;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: var(--primary);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .menu-label {
            color: #8190a8;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
            margin: 20px 10px 10px;
            text-transform: uppercase;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 5px;
            border-radius: 8px;
            color: #aab6c9;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: .2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #263d60;
            color: #fff;
        }

        .sidebar a.active {
            box-shadow: inset 3px 0 var(--primary);
        }

        .sidebar a i {
            width: 18px;
            text-align: center;
        }

        .sidebar-footer {
            border-top: 1px solid #34445c;
            padding: 18px 10px 0;
            margin-top: 30px;
            color: #9eabc0;
            font-size: 11px;
        }

        .main-content {
            margin-left: 255px;
            min-height: 100vh;
        }

        .topbar {
            height: 75px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
        }

        .page-content {
            padding: 28px 30px;
        }

        .page-title {
            font-size: 23px;
            font-weight: 800;
            margin-bottom: 6px;
        }

        .page-subtitle {
            color: var(--muted);
            font-size: 13px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-avatar {
            width: 39px;
            height: 39px;
            background: #e8f0ff;
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
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
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 20px;
            height: 100%;
            box-shadow: 0 3px 12px rgba(20, 35, 60, .025);
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 800;
            margin-top: 12px;
            line-height: 1.2;
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

        .icon-blue { background: #eaf1ff; color: #3979f6; }
        .icon-green { background: #e8f8ef; color: #16a36a; }
        .icon-orange { background: #fff2e4; color: #f29b38; }
        .icon-red { background: #ffeded; color: #e65353; }
        .icon-purple { background: #f1ebff; color: #8b5cf6; }
        .icon-cyan { background: #e5f8fc; color: #0891b2; }

        .card-heading {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .card-description {
            font-size: 11px;
            color: var(--muted);
        }

        .chart-wrapper {
            height: 285px;
            position: relative;
        }

        .table {
            font-size: 12px;
            vertical-align: middle;
        }

        .table th {
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            background: #f8fafc;
            padding: 13px;
            white-space: nowrap;
        }

        .table td {
            padding: 14px 13px;
        }

        .badge-soft {
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .activity-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-item {
            display: flex;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid var(--border);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-title {
            font-size: 12px;
            font-weight: 600;
        }

        .activity-meta {
            font-size: 10px;
            color: var(--muted);
            margin-top: 4px;
        }

        .mobile-menu {
            display: none;
            border: none;
            background: transparent;
            font-size: 20px;
        }

        .page-footer {
            text-align: center;
            color: #94a3b8;
            font-size: 11px;
            padding: 25px 0 5px;
        }

        @media(max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu {
                display: inline-block;
            }

            .page-content {
                padding: 22px 16px;
            }

            .topbar {
                padding: 0 17px;
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

    <a href="dashboard.php" class="active">
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

    <a href="../logout.php"
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

    <!-- TOPBAR -->
    <header class="topbar">

        <div class="d-flex align-items-center gap-3">
            <button class="mobile-menu" id="mobileMenu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <div class="fw-bold" style="font-size:14px">
                    Dashboard Admin
                </div>
                <div class="text-secondary" style="font-size:11px">
                    <?= e($companyName) ?>
                </div>
            </div>
        </div>

        <div class="profile">
            <div class="profile-avatar">
                <?= e(strtoupper(substr($user['name'] ?? 'A', 0, 2))) ?>
            </div>

            <div>
                <div class="profile-name">
                    <?= e($user['name'] ?? 'Administrator') ?>
                </div>
                <div class="profile-role">Administrator</div>
            </div>
        </div>

    </header>

    <!-- CONTENT -->
    <section class="page-content">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h1 class="page-title">Dashboard</h1>
                <div class="page-subtitle">
                    Ringkasan aktivitas dan kehadiran karyawan.
                </div>
            </div>

            <div class="text-secondary small">
                <i class="fa-regular fa-calendar me-1"></i>
                <?= e($todayLabel) ?>
            </div>
        </div>

        <!-- STATISTIK KARYAWAN -->
        <div class="row g-3 mb-3">

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="stat-label">Total Karyawan</div>
                        <div class="stat-icon icon-blue">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-number">
                        <?= number_format($stats['total_employees']) ?>
                    </div>
                    <div class="text-secondary mt-2" style="font-size:10px">
                        Seluruh data karyawan
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="stat-label">Karyawan Aktif</div>
                        <div class="stat-icon icon-green">
                            <i class="fa-solid fa-user-check"></i>
                        </div>
                    </div>
                    <div class="stat-number">
                        <?= number_format($stats['active_employees']) ?>
                    </div>
                    <div class="text-secondary mt-2" style="font-size:10px">
                        Status aktif
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="stat-label">Hadir Hari Ini</div>
                        <div class="stat-icon icon-cyan">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                    </div>
                    <div class="stat-number">
                        <?= number_format($totalPresent) ?>
                    </div>
                    <div class="text-secondary mt-2" style="font-size:10px">
                        Hadir dan terlambat
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="stat-label">Terlambat</div>
                        <div class="stat-icon icon-orange">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-number">
                        <?= number_format($stats['late']) ?>
                    </div>
                    <div class="text-secondary mt-2" style="font-size:10px">
                        Terlambat hari ini
                    </div>
                </div>
            </div>

        </div>

        <!-- STATISTIK STATUS -->
        <div class="row g-3 mb-4">

            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-label">Tidak Hadir</div>
                    <div class="stat-number text-danger">
                        <?= number_format($stats['absent']) ?>
                    </div>
                    <div class="text-secondary" style="font-size:10px">
                        Status absent tercatat
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-label">Izin</div>
                    <div class="stat-number text-primary">
                        <?= number_format($stats['permission']) ?>
                    </div>
                    <div class="text-secondary" style="font-size:10px">
                        Status izin hari ini
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-label">Sakit</div>
                    <div class="stat-number" style="color:#8b5cf6">
                        <?= number_format($stats['sick']) ?>
                    </div>
                    <div class="text-secondary" style="font-size:10px">
                        Status sakit hari ini
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-label">Cuti</div>
                    <div class="stat-number" style="color:#0891b2">
                        <?= number_format($stats['leave']) ?>
                    </div>
                    <div class="text-secondary" style="font-size:10px">
                        Status cuti hari ini
                    </div>
                </div>
            </div>

        </div>

        <!-- GRAFIK -->
        <div class="row g-3 mb-4">

            <div class="col-lg-8">
                <div class="content-card">

                    <div class="mb-4">
                        <div class="card-heading">
                            Statistik Kehadiran
                        </div>
                        <div class="card-description">
                            Rekap absensi tujuh hari terakhir
                        </div>
                    </div>

                    <div class="chart-wrapper">
                        <canvas id="attendanceChart"></canvas>
                    </div>

                </div>
            </div>

            <div class="col-lg-4">
                <div class="content-card">

                    <div class="mb-4">
                        <div class="card-heading">
                            Ringkasan Hari Ini
                        </div>
                        <div class="card-description">
                            Distribusi status absensi
                        </div>
                    </div>

                    <div class="chart-wrapper">
                        <canvas id="todayChart"></canvas>
                    </div>

                    <div class="text-center text-secondary mt-2"
                         style="font-size:11px">
                        Total catatan absensi:
                        <?= number_format(
                            $stats['present'] +
                            $stats['late'] +
                            $stats['absent'] +
                            $stats['permission'] +
                            $stats['sick'] +
                            $stats['leave']
                        ) ?>
                    </div>

                </div>
            </div>

        </div>

        <!-- AKTIVITAS -->
        <div class="row g-3">

            <div class="col-12">
                <div class="content-card">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="card-heading">
                                Aktivitas Terbaru
                            </div>
                            <div class="card-description">
                                Riwayat aktivitas administrator dan sistem
                            </div>
                        </div>

                        <a href="aktivitas.php"
                           class="btn btn-sm btn-light border">
                            Lihat Semua
                            <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>

                    <?php if (empty($activities)): ?>

                        <div class="text-center text-secondary py-5">
                            <i class="fa-regular fa-folder-open fs-2 mb-3 d-block"></i>
                            Belum ada aktivitas yang tercatat.
                        </div>

                    <?php else: ?>

                        <?php foreach ($activities as $activity): ?>

                            <div class="activity-item">

                                <div class="activity-icon icon-blue">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </div>

                                <div class="flex-grow-1">

                                    <div class="activity-title">
                                        <?= e($activity['description'] ?: $activity['action']) ?>
                                    </div>

                                    <div class="activity-meta">
                                        <?= e($activity['user_name'] ?? 'Sistem') ?>
                                        &bull;
                                        <?= e($activity['module']) ?>
                                        &bull;
                                        <?= e(date(
                                            'd/m/Y H:i',
                                            strtotime($activity['created_at'])
                                        )) ?>
                                    </div>

                                </div>

                                <span class="badge bg-light text-secondary align-self-start">
                                    <?= e($activity['action']) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </div>

        </div>

        <div class="page-footer">
            © <?= date('Y') ?> Attendly. All Rights Reserved.
        </div>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Mobile sidebar
document.getElementById('mobileMenu').addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});

// Grafik absensi 7 hari
const chartLabels = <?= $chartLabelsJson ?>;
const chartPresent = <?= $chartPresentJson ?>;
const chartLate = <?= $chartLateJson ?>;
const chartAbsent = <?= $chartAbsentJson ?>;

new Chart(document.getElementById('attendanceChart'), {
    type: 'line',
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: 'Hadir',
                data: chartPresent,
                borderColor: '#16a36a',
                backgroundColor: 'rgba(22,163,106,.08)',
                fill: true,
                tension: .35
            },
            {
                label: 'Terlambat',
                data: chartLate,
                borderColor: '#f29b38',
                backgroundColor: 'rgba(242,155,56,.05)',
                fill: false,
                tension: .35
            },
            {
                label: 'Tidak Hadir',
                data: chartAbsent,
                borderColor: '#e65353',
                backgroundColor: 'rgba(230,83,83,.05)',
                fill: false,
                tension: .35
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            intersect: false,
            mode: 'index'
        },
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 7,
                    font: { size: 11 }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 },
                grid: { color: '#f0f2f6' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Grafik ringkasan hari ini
new Chart(document.getElementById('todayChart'), {
    type: 'doughnut',
    data: {
        labels: [
            'Hadir',
            'Terlambat',
            'Tidak Hadir',
            'Izin',
            'Sakit',
            'Cuti'
        ],
        datasets: [{
            data: [
                <?= $stats['present'] ?>,
                <?= $stats['late'] ?>,
                <?= $stats['absent'] ?>,
                <?= $stats['permission'] ?>,
                <?= $stats['sick'] ?>,
                <?= $stats['leave'] ?>
            ],
            backgroundColor: [
                '#16a36a',
                '#f29b38',
                '#e65353',
                '#3979f6',
                '#8b5cf6',
                '#0891b2'
            ],
            borderWidth: 0,
            hoverOffset: 5
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 7,
                    padding: 13,
                    font: { size: 10 }
                }
            }
        }
    }
});
</script>

</body>
</html>

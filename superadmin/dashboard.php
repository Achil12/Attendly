<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| VALIDASI SUPER ADMIN
|--------------------------------------------------------------------------
*/

$roleStmt = $pdo->prepare("
    SELECT r.name
    FROM user_roles ur
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
");

$roleStmt->execute([$user['id']]);
$userRoles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

$isSuperAdmin = false;

foreach ($userRoles as $role) {
    if (strtolower(trim($role)) === 'super admin') {
        $isSuperAdmin = true;
        break;
    }
}

if (!$isSuperAdmin) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke halaman Super Admin.');
}

/*
|--------------------------------------------------------------------------
| HELPER STATISTIK
|--------------------------------------------------------------------------
*/

function getCount(PDO $pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function getRows(PDO $pdo, $sql, $params = [])
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| STATISTIK GLOBAL
|--------------------------------------------------------------------------
*/

$totalCompanies = getCount($pdo, "
    SELECT COUNT(*) FROM companies
");

$activeCompanies = getCount($pdo, "
    SELECT COUNT(*) FROM companies
    WHERE status = 'active'
");

$inactiveCompanies = getCount($pdo, "
    SELECT COUNT(*) FROM companies
    WHERE status IN ('inactive', 'suspended')
");

$totalUsers = getCount($pdo, "
    SELECT COUNT(*) FROM users
");

$totalEmployees = getCount($pdo, "
    SELECT COUNT(*) FROM employees
");

$totalSubscriptions = getCount($pdo, "
    SELECT COUNT(*) FROM subscriptions
");

$activeSubscriptions = getCount($pdo, "
    SELECT COUNT(*) FROM subscriptions
    WHERE status = 'active'
");

$totalPlans = getCount($pdo, "
    SELECT COUNT(*) FROM plans
");

/*
|--------------------------------------------------------------------------
| PERUSAHAAN TERBARU
|--------------------------------------------------------------------------
*/

$latestCompanies = getRows($pdo, "
    SELECT
        c.id,
        c.name,
        c.email,
        c.status,
        c.created_at
    FROM companies c
    ORDER BY c.created_at DESC
    LIMIT 8
");

/*
|--------------------------------------------------------------------------
| LANGGANAN TERBARU
|--------------------------------------------------------------------------
*/

$latestSubscriptions = getRows($pdo, "
    SELECT
        s.id,
        s.status,
        s.start_date,
        s.end_date,
        c.name AS company_name,
        p.name AS plan_name
    FROM subscriptions s
    LEFT JOIN companies c ON c.id = s.company_id
    LEFT JOIN plans p ON p.id = s.plan_id
    ORDER BY s.id DESC
    LIMIT 8
");

/*
|--------------------------------------------------------------------------
| DATA PAKET
|--------------------------------------------------------------------------
*/

$planStats = getRows($pdo, "
    SELECT
        p.id,
        p.name,
        COUNT(s.id) AS total_subscribers
    FROM plans p
    LEFT JOIN subscriptions s
        ON s.plan_id = p.id
       AND s.status = 'active'
    GROUP BY p.id, p.name
    ORDER BY total_subscribers DESC
");

/*
|--------------------------------------------------------------------------
| PERUSAHAAN BARU 7 HARI TERAKHIR
|--------------------------------------------------------------------------
*/

$companyGrowth = getRows($pdo, "
    SELECT
        DATE(created_at) AS tanggal,
        COUNT(*) AS total
    FROM companies
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY tanggal ASC
");

$growthLabels = [];
$growthValues = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $growthLabels[$date] = date('d/m', strtotime($date));
    $growthValues[$date] = 0;
}

foreach ($companyGrowth as $item) {
    $date = $item['tanggal'];

    if (isset($growthValues[$date])) {
        $growthValues[$date] = (int)$item['total'];
    }
}

$displayName = $user['name'] ?? $user['email'] ?? 'Super Admin';
$initial = strtoupper(substr($displayName, 0, 1));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Super Admin Dashboard | Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #3979f6;
            --sidebar: #17243a;
            --background: #f5f7fb;
            --muted: #8993a5;
            --border: #edf0f5;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: var(--background);
            color: #253047;
            font-size: 14px;
        }

        .sidebar {
            width: 255px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: var(--sidebar);
            padding: 24px 16px;
            color: #fff;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 10px 25px;
            color: #fff;
            text-decoration: none;
            font-size: 21px;
            font-weight: 800;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            background: var(--primary);
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-label {
            color: #8290a5;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 12px;
            text-transform: uppercase;
        }

        .sidebar .nav-link {
            color: #aab5c5;
            padding: 12px 14px;
            border-radius: 9px;
            margin-bottom: 5px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #fff;
            background: #293d5c;
        }

        .sidebar .nav-link.active {
            border-left: 3px solid #5790ff;
        }

        .sidebar .nav-link i {
            width: 18px;
            text-align: center;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid #344158;
            padding: 18px 10px 0;
            color: #8290a5;
            font-size: 11px;
        }

        .main-content {
            margin-left: 255px;
            padding: 25px 30px;
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
            font-size: 12px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5edff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .profile-name {
            font-weight: 700;
            font-size: 12px;
        }

        .profile-role {
            color: var(--muted);
            font-size: 11px;
        }

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 19px;
            height: 100%;
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
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

        .icon-blue {
            background: #eaf1ff;
            color: #3979f6;
        }

        .icon-green {
            background: #e8f8ef;
            color: #16a36a;
        }

        .icon-orange {
            background: #fff3e5;
            color: #f59e0b;
        }

        .icon-purple {
            background: #f2eaff;
            color: #8856d9;
        }

        .stat-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .stat-number {
            font-size: 26px;
            font-weight: 800;
            margin-top: 5px;
        }

        .stat-footer {
            font-size: 10px;
            color: var(--muted);
            margin-top: 8px;
        }

        .content-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 21px;
            height: 100%;
        }

        .section-title {
            font-size: 14px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .section-subtitle {
            font-size: 11px;
            color: var(--muted);
        }

        .table {
            font-size: 12px;
            vertical-align: middle;
        }

        .table thead th {
            background: #f8f9fc;
            color: #7e899b;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .4px;
            font-weight: 700;
            padding: 13px 10px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 13px 10px;
            border-bottom: 1px solid #f1f3f7;
        }

        .status {
            padding: 6px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            display: inline-block;
        }

        .status-active {
            background: #dcfce7;
            color: #15803d;
        }

        .status-inactive {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .plan-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 13px 0;
            border-bottom: 1px solid var(--border);
            font-size: 12px;
        }

        .plan-item:last-child {
            border-bottom: 0;
        }

        .plan-name {
            font-weight: 700;
        }

        .plan-count {
            background: #eff5ff;
            color: var(--primary);
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 10px;
        }

        .mobile-toggle {
            display: none;
            border: 0;
            background: #fff;
            width: 40px;
            height: 40px;
            border-radius: 9px;
        }

        .quick-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #253047;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            transition: .2s;
        }

        .quick-link:hover {
            border-color: var(--primary);
            background: #f8faff;
            color: var(--primary);
        }

        .quick-icon {
            width: 35px;
            height: 35px;
            border-radius: 9px;
            background: #eff5ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @media(max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                transition: .3s;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .mobile-toggle {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-right: 10px;
            }

            .profile-info {
                display: none;
            }
        }

        @media(max-width: 576px) {
            .page-title {
                font-size: 19px;
            }

            .stat-number {
                font-size: 22px;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar" id="sidebar">

    <a href="dashboard.php" class="brand">
        <span class="brand-icon">
            <i class="fa-solid fa-calendar-check"></i>
        </span>
        Attendly
    </a>

    <div class="menu-label">Super Admin</div>

    <nav>
        <a href="dashboard.php" class="nav-link active">
            <i class="fa-solid fa-gauge-high"></i>
            Dashboard
        </a>

        <a href="perusahaan.php" class="nav-link">
            <i class="fa-solid fa-building"></i>
            Manajemen Perusahaan
        </a>

        <a href="paket.php" class="nav-link">
            <i class="fa-solid fa-cubes"></i>
            Manajemen Paket
        </a>

        <a href="subscription.php" class="nav-link">
            <i class="fa-solid fa-repeat"></i>
            Subscription
        </a>

        <a href="transaksi.php" class="nav-link">
            <i class="fa-solid fa-credit-card"></i>
            Transaksi
        </a>

        <a href="invoice.php" class="nav-link">
            <i class="fa-solid fa-file-invoice"></i>
            Invoice
        </a>

        <a href="payment_gateway.php" class="nav-link">
            <i class="fa-solid fa-wallet"></i>
            Payment Gateway
        </a>

        <a href="pengguna.php" class="nav-link">
            <i class="fa-solid fa-users"></i>
            Pengguna
        </a>

        <a href="monitoring.php" class="nav-link">
            <i class="fa-solid fa-chart-line"></i>
            Monitoring Sistem
        </a>

        <a href="../ubah_password.php" class="nav-link">
            <i class="fa-solid fa-lock"></i>
            Ubah Password
        </a>

        <a href="../logout.php" class="nav-link">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="fw-bold text-white mb-1">Attendly SaaS</div>
        <div>Super Admin Panel · Versi 1.0.0</div>
    </div>

</aside>

<main class="main-content">

    <div class="topbar">

        <div class="d-flex align-items-start">
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <div class="page-title">Super Admin Dashboard</div>
                <div class="page-subtitle">
                    Ringkasan dan monitoring seluruh ekosistem Attendly.
                </div>
            </div>
        </div>

        <div class="profile">
            <div class="avatar"><?= e($initial) ?></div>

            <div class="profile-info">
                <div class="profile-name"><?= e($displayName) ?></div>
                <div class="profile-role">Super Administrator</div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Total Perusahaan</div>
                    <div class="stat-icon icon-blue">
                        <i class="fa-solid fa-building"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($totalCompanies) ?></div>
                <div class="stat-footer">
                    Seluruh perusahaan terdaftar
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Perusahaan Aktif</div>
                    <div class="stat-icon icon-green">
                        <i class="fa-solid fa-building-circle-check"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($activeCompanies) ?></div>
                <div class="stat-footer">
                    Perusahaan dengan status aktif
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Total Pengguna</div>
                    <div class="stat-icon icon-purple">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($totalUsers) ?></div>
                <div class="stat-footer">
                    Seluruh akun terdaftar
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Total Karyawan</div>
                    <div class="stat-icon icon-orange">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($totalEmployees) ?></div>
                <div class="stat-footer">
                    Karyawan seluruh perusahaan
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Subscription Aktif</div>
                    <div class="stat-icon icon-green">
                        <i class="fa-solid fa-repeat"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($activeSubscriptions) ?></div>
                <div class="stat-footer">
                    Dari <?= number_format($totalSubscriptions) ?> subscription
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Paket Tersedia</div>
                    <div class="stat-icon icon-blue">
                        <i class="fa-solid fa-cubes"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($totalPlans) ?></div>
                <div class="stat-footer">
                    Paket layanan Attendly
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Perusahaan Nonaktif</div>
                    <div class="stat-icon icon-orange">
                        <i class="fa-solid fa-building-circle-xmark"></i>
                    </div>
                </div>

                <div class="stat-number"><?= number_format($inactiveCompanies) ?></div>
                <div class="stat-footer">
                    Nonaktif atau ditangguhkan
                </div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-top">
                    <div class="stat-label">Status Sistem</div>
                    <div class="stat-icon icon-green">
                        <i class="fa-solid fa-server"></i>
                    </div>
                </div>

                <div class="stat-number fs-5 text-success">Online</div>
                <div class="stat-footer">
                    Dashboard berhasil dimuat
                </div>
            </div>
        </div>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-lg-8">
            <div class="content-card">
                <div class="mb-4">
                    <div class="section-title">Pertumbuhan Perusahaan</div>
                    <div class="section-subtitle">
                        Registrasi perusahaan dalam 7 hari terakhir.
                    </div>
                </div>

                <div style="height:280px">
                    <canvas id="companyGrowthChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="content-card">
                <div class="mb-3">
                    <div class="section-title">Paket Berlangganan</div>
                    <div class="section-subtitle">
                        Jumlah subscription aktif berdasarkan paket.
                    </div>
                </div>

                <?php if ($planStats): ?>
                    <?php foreach ($planStats as $plan): ?>
                        <div class="plan-item">
                            <span class="plan-name">
                                <?= e($plan['name']) ?>
                            </span>

                            <span class="plan-count">
                                <?= number_format((int)$plan['total_subscribers']) ?> pelanggan
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted small py-4 text-center">
                        Belum ada data paket.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div class="content-card mb-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="section-title">Perusahaan Terbaru</div>
                <div class="section-subtitle">
                    Daftar perusahaan yang baru terdaftar.
                </div>
            </div>

            <a href="perusahaan.php" class="btn btn-sm btn-outline-primary">
                Lihat Semua
            </a>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Perusahaan</th>
                        <th>Email</th>
                        <th>Tanggal Registrasi</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($latestCompanies): ?>
                        <?php foreach ($latestCompanies as $company): ?>
                            <?php
                            $status = strtolower($company['status'] ?? '');
                            $statusClass = $status === 'active'
                                ? 'status-active'
                                : 'status-inactive';
                            ?>

                            <tr>
                                <td>
                                    <div class="fw-bold">
                                        <?= e($company['name']) ?>
                                    </div>
                                    <small class="text-muted">
                                        ID: <?= (int)$company['id'] ?>
                                    </small>
                                </td>

                                <td><?= e($company['email'] ?? '-') ?></td>

                                <td>
                                    <?= !empty($company['created_at'])
                                        ? date('d M Y', strtotime($company['created_at']))
                                        : '-' ?>
                                </td>

                                <td>
                                    <span class="status <?= $statusClass ?>">
                                        <?= e(ucfirst($status ?: 'unknown')) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                Belum ada perusahaan terdaftar.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <div class="content-card mb-4">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="section-title">Subscription Terbaru</div>
                <div class="section-subtitle">
                    Status langganan perusahaan.
                </div>
            </div>

            <a href="subscription.php" class="btn btn-sm btn-outline-primary">
                Lihat Semua
            </a>
        </div>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Perusahaan</th>
                        <th>Paket</th>
                        <th>Mulai</th>
                        <th>Berakhir</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($latestSubscriptions): ?>
                        <?php foreach ($latestSubscriptions as $subscription): ?>
                            <?php
                            $status = strtolower($subscription['status'] ?? '');

                            $statusClass = $status === 'active'
                                ? 'status-active'
                                : ($status === 'pending'
                                    ? 'status-pending'
                                    : 'status-inactive');
                            ?>

                            <tr>
                                <td class="fw-bold">
                                    <?= e($subscription['company_name'] ?? '-') ?>
                                </td>

                                <td><?= e($subscription['plan_name'] ?? '-') ?></td>

                                <td>
                                    <?= !empty($subscription['start_date'])
                                        ? date('d M Y', strtotime($subscription['start_date']))
                                        : '-' ?>
                                </td>

                                <td>
                                    <?= !empty($subscription['end_date'])
                                        ? date('d M Y', strtotime($subscription['end_date']))
                                        : '-' ?>
                                </td>

                                <td>
                                    <span class="status <?= $statusClass ?>">
                                        <?= e(ucfirst($status ?: 'unknown')) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Belum ada data subscription.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <div class="content-card mb-4">
        <div class="mb-3">
            <div class="section-title">Akses Cepat</div>
            <div class="section-subtitle">
                Pintasan pengelolaan platform Attendly.
            </div>
        </div>

        <div class="row g-3">

            <div class="col-md-6 col-xl-3">
                <a href="perusahaan.php" class="quick-link">
                    <span class="quick-icon">
                        <i class="fa-solid fa-building"></i>
                    </span>
                    Manajemen Perusahaan
                </a>
            </div>

            <div class="col-md-6 col-xl-3">
                <a href="paket.php" class="quick-link">
                    <span class="quick-icon">
                        <i class="fa-solid fa-cubes"></i>
                    </span>
                    Kelola Paket
                </a>
            </div>

            <div class="col-md-6 col-xl-3">
                <a href="subscription.php" class="quick-link">
                    <span class="quick-icon">
                        <i class="fa-solid fa-repeat"></i>
                    </span>
                    Subscription
                </a>
            </div>

            <div class="col-md-6 col-xl-3">
                <a href="monitoring.php" class="quick-link">
                    <span class="quick-icon">
                        <i class="fa-solid fa-chart-line"></i>
                    </span>
                    Monitoring Sistem
                </a>
            </div>

        </div>
    </div>

    <div class="text-center text-muted small mt-4">
        © <?= date('Y') ?> Attendly SaaS. All Rights Reserved.
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const sidebar = document.getElementById('sidebar');
const mobileToggle = document.getElementById('mobileToggle');

mobileToggle?.addEventListener('click', function() {
    sidebar.classList.toggle('show');
});

document.addEventListener('click', function(event) {
    if (
        window.innerWidth <= 991 &&
        sidebar.classList.contains('show') &&
        !sidebar.contains(event.target) &&
        !mobileToggle.contains(event.target)
    ) {
        sidebar.classList.remove('show');
    }
});

const growthLabels = <?= json_encode(array_values($growthLabels)) ?>;
const growthValues = <?= json_encode(array_values($growthValues)) ?>;

new Chart(document.getElementById('companyGrowthChart'), {
    type: 'line',
    data: {
        labels: growthLabels,
        datasets: [{
            label: 'Perusahaan Baru',
            data: growthValues,
            borderColor: '#3979f6',
            backgroundColor: 'rgba(57, 121, 246, .10)',
            borderWidth: 3,
            fill: true,
            tension: .4,
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
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
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>

</body>
</html>
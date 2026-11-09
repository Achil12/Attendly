<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = attendly_user();
$userId = (int)($user['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| SUPER ADMIN AUTHORIZATION
|--------------------------------------------------------------------------
*/
$roleStmt = $pdo->prepare("
    SELECT LOWER(r.name)
    FROM user_roles ur
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
");
$roleStmt->execute([$userId]);
$userRoles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('super admin', $userRoles, true)) {
    http_response_code(403);
    exit('Akses ditolak.');
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function subscriptionRedirect($message, $type = 'success')
{
    $_SESSION['subscription_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: subscription.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['subscription_csrf'])) {
    $_SESSION['subscription_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['subscription_csrf'];

function verifySubscriptionCsrf()
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['subscription_csrf']) ||
        !hash_equals($_SESSION['subscription_csrf'], $token)
    ) {
        http_response_code(419);
        exit('Token keamanan tidak valid.');
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE ACTION
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifySubscriptionCsrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'change_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';

            $allowedStatus = [
                'active',
                'pending',
                'expired',
                'cancelled',
                'suspended'
            ];

            if ($id <= 0 || !in_array($status, $allowedStatus, true)) {
                subscriptionRedirect('Data subscription tidak valid.', 'danger');
            }

            $stmt = $pdo->prepare("
                UPDATE subscriptions
                SET status = ?
                WHERE id = ?
            ");

            $stmt->execute([$status, $id]);

            subscriptionRedirect('Status subscription berhasil diperbarui.');
        }

        if ($action === 'extend') {
            $id = (int)($_POST['id'] ?? 0);
            $days = (int)($_POST['days'] ?? 0);

            if ($id <= 0 || $days < 1 || $days > 3650) {
                subscriptionRedirect('Jumlah hari perpanjangan tidak valid.', 'danger');
            }

            $stmt = $pdo->prepare("
                SELECT id, ends_at, status
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$id]);
            $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$subscription) {
                subscriptionRedirect('Subscription tidak ditemukan.', 'danger');
            }

            $baseDate = $subscription['ends_at'] &&
            strtotime($subscription['ends_at']) > time()
            ? $subscription['ends_at']
            : date('Y-m-d H:i:s');

            $newEndDate = date(
                'Y-m-d',
                strtotime($baseDate . ' +' . $days . ' days')
            );

            $update = $pdo->prepare("
                UPDATE subscriptions
                SET ends_at = ?, status = 'active'
                WHERE id = ?
            ");

            $update->execute([$newEndDate, $id]);

            subscriptionRedirect('Masa subscription berhasil diperpanjang.');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                subscriptionRedirect('ID subscription tidak valid.', 'danger');
            }

            $stmt = $pdo->prepare("
                DELETE FROM subscriptions
                WHERE id = ?
            ");

            $stmt->execute([$id]);

            subscriptionRedirect('Data subscription berhasil dihapus.');
        }

    } catch (PDOException $e) {
        subscriptionRedirect(
            'Terjadi kesalahan database: ' . $e->getMessage(),
            'danger'
        );
    }
}

/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/
$flash = $_SESSION['subscription_flash'] ?? null;
unset($_SESSION['subscription_flash']);

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$planFilter = (int)($_GET['plan'] ?? 0);

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(c.name LIKE ? OR p.name LIKE ? OR c.email LIKE ?)";
    $keyword = "%{$search}%";
    array_push($params, $keyword, $keyword, $keyword);
}

$allowedStatus = [
    'trialing',
    'active',
    'past_due',
    'cancelled',
    'expired'
];

if (in_array($statusFilter, $allowedStatus, true)) {
    $where[] = "s.status = ?";
    $params[] = $statusFilter;
}

if ($planFilter > 0) {
    $where[] = "s.plan_id = ?";
    $params[] = $planFilter;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/
$stats = [
    'total' => 0,
    'active' => 0,
    'pending' => 0,
    'expired' => 0
];

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status IN ('trialing', 'past_due') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired
    FROM subscriptions
");

$stats = array_merge(
    $stats,
    $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []
);

/*
|--------------------------------------------------------------------------
| PLAN OPTIONS
|--------------------------------------------------------------------------
*/
$plans = $pdo->query("
    SELECT id, name
    FROM plans
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM subscriptions s
    INNER JOIN companies c ON c.id = s.company_id
    INNER JOIN plans p ON p.id = s.plan_id
    {$whereSql}
");

$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

/*
|--------------------------------------------------------------------------
| SUBSCRIPTION LIST
|--------------------------------------------------------------------------
*/
$listStmt = $pdo->prepare("
    SELECT
        s.id,
        s.company_id,
        s.plan_id,
        s.status,
        s.billing_cycle,
        s.starts_at AS start_date,
        s.ends_at AS end_date,
        s.created_at,
        c.name AS company_name,
        c.email AS company_email,
        p.name AS plan_name,
        p.price_monthly,
        p.price_yearly
    FROM subscriptions s
    INNER JOIN companies c ON c.id = s.company_id
    INNER JOIN plans p ON p.id = s.plan_id
    {$whereSql}
    ORDER BY s.id DESC
    LIMIT {$limit} OFFSET {$offset}
");

$listStmt->execute($params);
$subscriptions = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function subscriptionPageUrl($page)
{
    $query = $_GET;
    $query['page'] = $page;

    return 'subscription.php?' . http_build_query($query);
}

function subscriptionStatusLabel($status)
{
    $labels = [
        'trialing' => 'Masa Percobaan',
        'active' => 'Aktif',
        'past_due' => 'Menunggak',
        'cancelled' => 'Dibatalkan',
        'expired' => 'Kedaluwarsa'
    ];

    return $labels[$status] ?? ucfirst($status);
}

function subscriptionStatusClass($status)
{
    $classes = [
        'trialing' => 'status-pending',
        'active' => 'status-active',
        'past_due' => 'status-pending',
        'cancelled' => 'status-cancelled',
        'expired' => 'status-expired'
    ];

    return $classes[$status] ?? 'status-expired';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription | Attendly Super Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3979f6;
            --sidebar: #17243a;
            --background: #f5f7fb;
            --text: #263449;
            --muted: #8793a5;
            --border: #e9edf4;
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
            position: fixed;
            top: 0;
            left: 0;
            width: 255px;
            height: 100vh;
            background: var(--sidebar);
            color: white;
            padding: 25px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .brand {
            font-size: 21px;
            font-weight: 800;
            padding: 0 12px 30px;
        }

        .brand span {
            color: #70a1ff;
        }

        .sidebar-label {
            color: #8290a6;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
            padding: 14px 12px 8px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #b9c4d5;
            text-decoration: none;
            padding: 12px;
            border-radius: 9px;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #263b5c;
            color: white;
        }

        .sidebar a.active {
            border-left: 3px solid #70a1ff;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 15px 12px 0;
            border-top: 1px solid #34445c;
            color: #aab6c8;
            font-size: 12px;
        }

        .main {
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
            font-size: 23px;
            font-weight: 800;
            margin-bottom: 5px;
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

        .avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #e6efff;
            color: var(--primary);
            display: grid;
            place-items: center;
            font-weight: 800;
        }

        .stat-card,
        .content-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 13px;
            box-shadow: 0 3px 15px rgba(20, 40, 80, .025);
        }

        .stat-card {
            padding: 20px;
            height: 100%;
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-size: 17px;
            margin-bottom: 15px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 7px;
        }

        .stat-value {
            font-size: 25px;
            font-weight: 800;
        }

        .content-card {
            margin-top: 24px;
            overflow: hidden;
        }

        .card-header-custom {
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: 15px;
            font-weight: 800;
            margin: 0;
        }

        .table {
            margin: 0;
            vertical-align: middle;
        }

        .table thead th {
            background: #f9fafc;
            color: #7b8798;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 15px 18px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 15px 18px;
            border-color: #f0f2f6;
            font-size: 12px;
        }

        .company-name {
            font-weight: 700;
            color: #263449;
            font-size: 13px;
        }

        .company-meta {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
        }

        .plan-badge {
            background: #edf3ff;
            color: #3979f6;
            padding: 6px 9px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-active {
            color: #16834d;
            background: #e8f8ef;
        }

        .status-pending {
            color: #a56a00;
            background: #fff5df;
        }

        .status-expired {
            color: #bd4141;
            background: #fff0f0;
        }

        .status-cancelled {
            color: #667085;
            background: #eef0f3;
        }

        .status-suspended {
            color: #8b4bb5;
            background: #f5eaff;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .form-control,
        .form-select {
            font-size: 13px;
            padding: 10px 12px;
            border-color: #e3e8f0;
        }

        .btn {
            font-size: 12px;
            font-weight: 600;
            border-radius: 8px;
            padding: 9px 13px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            display: inline-grid;
            place-items: center;
            padding: 0;
            border: 1px solid var(--border);
            background: white;
            color: #66758a;
            border-radius: 7px;
        }

        .action-btn:hover {
            background: #f1f5fb;
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

        .mobile-menu {
            display: none;
        }

        @media(max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                transition: .25s;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .main {
                margin-left: 0;
                padding: 20px;
            }

            .mobile-menu {
                display: inline-block;
            }
        }
    </style>
</head>

<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <i class="fa-solid fa-calendar-check"></i>
        Attend<span>ly</span>
    </div>

    <div class="sidebar-label">SUPER ADMIN</div>

    <a href="dashboard.php">
        <i class="fa-solid fa-chart-pie"></i>
        Dashboard
    </a>

    <a href="perusahaan.php">
        <i class="fa-solid fa-building"></i>
        Perusahaan
    </a>

    <a href="paket.php">
        <i class="fa-solid fa-cubes"></i>
        Paket Langganan
    </a>

    <a href="subscription.php" class="active">
        <i class="fa-solid fa-repeat"></i>
        Subscription
    </a>

    <div class="sidebar-label">KEUANGAN</div>

    <a href="transaksi.php">
        <i class="fa-solid fa-money-bill-transfer"></i>
        Transaksi
    </a>

    <a href="invoice.php">
        <i class="fa-solid fa-file-invoice"></i>
        Invoice
    </a>

    <a href="payment_gateway.php">
        <i class="fa-solid fa-credit-card"></i>
        Payment Gateway
    </a>

    <div class="sidebar-label">SISTEM</div>

    <a href="pengguna.php">
        <i class="fa-solid fa-users"></i>
        Pengguna
    </a>

    <a href="monitoring.php">
        <i class="fa-solid fa-server"></i>
        Monitoring
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly SaaS</div>
        <div>Super Admin Panel · v1.0.0</div>

        <div class="mt-3">
            <a href="../ubah_password.php">
                <i class="fa-solid fa-key"></i>
                Ubah Password
            </a>
            <a href="../logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                Keluar
            </a>
        </div>
    </div>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <button class="btn btn-light mobile-menu me-2" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <h1 class="page-title">Manajemen Subscription</h1>
            <div class="page-subtitle">
                Pantau masa aktif dan status langganan seluruh perusahaan.
            </div>
        </div>

        <div class="profile">
            <div class="avatar">SA</div>
            <div>
                <div class="fw-bold" style="font-size:13px;">Super Admin</div>
                <div class="text-secondary" style="font-size:11px;">Administrator SaaS</div>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3">

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#eaf1ff;color:#3979f6;">
                    <i class="fa-solid fa-repeat"></i>
                </div>
                <div class="stat-label">Total Subscription</div>
                <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e8f8ef;color:#16a05d;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-label">Subscription Aktif</div>
                <div class="stat-value"><?= number_format((int)$stats['active']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff5e6;color:#e99a27;">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-label">Menunggu Pembayaran</div>
                <div class="stat-value"><?= number_format((int)$stats['pending']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff0f0;color:#e05252;">
                    <i class="fa-solid fa-calendar-xmark"></i>
                </div>
                <div class="stat-label">Kedaluwarsa</div>
                <div class="stat-value"><?= number_format((int)$stats['expired']) ?></div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="card-header-custom">
            <h5 class="card-title">Daftar Subscription</h5>
            <div class="text-secondary mt-1" style="font-size:11px;">
                Total <?= number_format($totalRows) ?> data langganan
            </div>
        </div>

        <div class="p-3 border-bottom">
            <form method="GET" class="row g-2">

                <div class="col-lg-4">
                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Cari perusahaan atau paket..."
                        value="<?= e($search) ?>"
                    >
                </div>

                <div class="col-lg-3">
                    <select name="plan" class="form-select">
                        <option value="0">Semua Paket</option>
                        <?php foreach ($plans as $plan): ?>
                            <option
                                value="<?= (int)$plan['id'] ?>"
                                <?= $planFilter === (int)$plan['id'] ? 'selected' : '' ?>
                            >
                                <?= e($plan['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <?php foreach ($allowedStatus as $status): ?>
                            <option
                                value="<?= e($status) ?>"
                                <?= $statusFilter === $status ? 'selected' : '' ?>
                            >
                                <?= e(subscriptionStatusLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary">
                        <i class="fa-solid fa-filter"></i>
                    </button>

                    <a href="subscription.php" class="btn btn-light border">
                        Reset
                    </a>
                </div>

            </form>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Perusahaan</th>
                        <th>Paket</th>
                        <th>Mulai</th>
                        <th>Berakhir</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$subscriptions): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <i class="fa-solid fa-repeat fa-2x text-secondary mb-3"></i>
                            <div class="fw-bold">Belum ada subscription</div>
                            <div class="text-secondary mt-1">
                                Data langganan perusahaan akan muncul di sini.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subscriptions as $subscription): ?>
                        <tr>
                            <td>
                                <div class="company-name">
                                    <?= e($subscription['company_name']) ?>
                                </div>
                                <div class="company-meta">
                                    <?= e($subscription['company_email'] ?: '-') ?>
                                </div>
                            </td>

                            <td>
                                <span class="plan-badge">
                                    <?= e($subscription['plan_name']) ?>
                                </span>
                            </td>

                            <td>
                                <?= $subscription['start_date']
                                    ? date('d/m/Y', strtotime($subscription['start_date']))
                                    : '-' ?>
                            </td>

                            <td>
                                <?= $subscription['end_date']
                                    ? date('d/m/Y', strtotime($subscription['end_date']))
                                    : '-' ?>
                            </td>

                            <td>
                                <span class="status <?= e(subscriptionStatusClass($subscription['status'])) ?>">
                                    <?= e(subscriptionStatusLabel($subscription['status'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= $subscription['created_at']
                                    ? date('d/m/Y', strtotime($subscription['created_at']))
                                    : '-' ?>
                            </td>

                            <td>
                                <div class="d-flex gap-1">

                                    <button
                                        type="button"
                                        class="action-btn"
                                        title="Perpanjang"
                                        onclick="openExtendModal(<?= (int)$subscription['id'] ?>, '<?= e($subscription['company_name']) ?>')"
                                    >
                                        <i class="fa-solid fa-calendar-plus"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="action-btn"
                                        title="Ubah status"
                                        onclick="openStatusModal(<?= (int)$subscription['id'] ?>, '<?= e($subscription['status']) ?>')"
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$subscription['id'] ?>">

                                        <button
                                            type="submit"
                                            class="action-btn text-danger"
                                            title="Hapus"
                                            onclick="return confirm('Hapus data subscription ini?')"
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="text-secondary" style="font-size:12px;">
                Menampilkan
                <?= $totalRows > 0 ? $offset + 1 : 0 ?>
                -
                <?= min($offset + $limit, $totalRows) ?>
                dari <?= number_format($totalRows) ?> data
            </div>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(subscriptionPageUrl(max(1, $page - 1))) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(subscriptionPageUrl($i)) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(subscriptionPageUrl(min($totalPages, $page + 1))) ?>">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </li>

                </ul>
            </nav>
        </div>

    </div>

    <div class="text-center text-secondary mt-4" style="font-size:11px;">
        © <?= date('Y') ?> Attendly SaaS. All Rights Reserved.
    </div>

</main>

<!-- MODAL PERPANJANG -->
<div class="modal fade" id="extendModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="extend">
                <input type="hidden" name="id" id="extendId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Perpanjang Subscription</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        Perusahaan:
                        <div class="fw-bold" id="extendCompany"></div>
                    </div>

                    <label class="form-label fw-semibold">Tambahkan Masa Aktif</label>
                    <select name="days" class="form-select" required>
                        <option value="7">7 Hari</option>
                        <option value="30" selected>30 Hari</option>
                        <option value="90">90 Hari</option>
                        <option value="180">180 Hari</option>
                        <option value="365">365 Hari</option>
                    </select>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Perpanjang
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<!-- MODAL STATUS -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="change_status">
                <input type="hidden" name="id" id="statusId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Ubah Status Subscription</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" id="subscriptionStatus" class="form-select" required>
                        <?php foreach ($allowedStatus as $status): ?>
                            <option value="<?= e($status) ?>">
                                <?= e(subscriptionStatusLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        Batal
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Simpan Status
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const extendModal = new bootstrap.Modal(
        document.getElementById('extendModal')
    );

    const statusModal = new bootstrap.Modal(
        document.getElementById('statusModal')
    );

    function openExtendModal(id, company) {
        document.getElementById('extendId').value = id;
        document.getElementById('extendCompany').textContent = company;
        extendModal.show();
    }

    function openStatusModal(id, status) {
        document.getElementById('statusId').value = id;
        document.getElementById('subscriptionStatus').value = status;
        statusModal.show();
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
    }
</script>

</body>
</html>
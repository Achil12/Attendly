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

function paketRedirect($message, $type = 'success')
{
    $_SESSION['paket_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: paket.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['paket_csrf'])) {
    $_SESSION['paket_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['paket_csrf'];

function verifyPaketCsrf()
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['paket_csrf']) ||
        !hash_equals($_SESSION['paket_csrf'], $token)
    ) {
        http_response_code(419);
        exit('Token keamanan tidak valid.');
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE FORM
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyPaketCsrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $priceMonthly = (float)($_POST['price_monthly'] ?? 0);
            $priceYearly = (float)($_POST['price_yearly'] ?? 0);
            $maxEmployees = (int)($_POST['max_employees'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $isPopular = isset($_POST['is_popular']) ? 1 : 0;

            if ($name === '') {
                paketRedirect('Nama paket wajib diisi.', 'danger');
            }

            if ($priceMonthly < 0 || $priceYearly < 0 || $maxEmployees < 0) {
                paketRedirect('Nilai harga dan batas karyawan tidak valid.', 'danger');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE plans
                    SET
                        name = ?,
                        description = ?,
                        price_monthly = ?,
                        price_yearly = ?,
                        max_employees = ?,
                        status = ?,
                        is_popular = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $description ?: null,
                    $priceMonthly,
                    $priceYearly,
                    $maxEmployees,
                    $status,
                    $isPopular,
                    $id
                ]);

                paketRedirect('Paket berhasil diperbarui.');
            }

            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));

            $check = $pdo->prepare("SELECT COUNT(*) FROM plans WHERE slug = ?");
            $check->execute([$slug]);

            if ((int)$check->fetchColumn() > 0) {
                $slug .= '-' . strtolower(bin2hex(random_bytes(3)));
            }

            $stmt = $pdo->prepare("
                INSERT INTO plans (
                    name,
                    slug,
                    description,
                    price_monthly,
                    price_yearly,
                    max_employees,
                    status,
                    is_popular,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $name,
                $slug,
                $description ?: null,
                $priceMonthly,
                $priceYearly,
                $maxEmployees,
                $status,
                $isPopular
            ]);

            paketRedirect('Paket baru berhasil ditambahkan.');
        }

        if ($action === 'change_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';

            if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
                paketRedirect('Data status tidak valid.', 'danger');
            }

            $stmt = $pdo->prepare("
                UPDATE plans
                SET status = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $id]);

            paketRedirect('Status paket berhasil diperbarui.');
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                paketRedirect('ID paket tidak valid.', 'danger');
            }

            $check = $pdo->prepare("
                SELECT COUNT(*)
                FROM subscriptions
                WHERE plan_id = ?
            ");
            $check->execute([$id]);

            if ((int)$check->fetchColumn() > 0) {
                paketRedirect(
                    'Paket tidak dapat dihapus karena sudah digunakan dalam data subscription. Nonaktifkan paket sebagai gantinya.',
                    'warning'
                );
            }

            $stmt = $pdo->prepare("DELETE FROM plans WHERE id = ?");
            $stmt->execute([$id]);

            paketRedirect('Paket berhasil dihapus.');
        }

    } catch (PDOException $e) {
        paketRedirect('Terjadi kesalahan database: ' . $e->getMessage(), 'danger');
    }
}

/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/
$flash = $_SESSION['paket_flash'] ?? null;
unset($_SESSION['paket_flash']);

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(p.name LIKE ? OR p.slug LIKE ? OR p.description LIKE ?)";
    $keyword = "%{$search}%";
    array_push($params, $keyword, $keyword, $keyword);
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = "p.status = ?";
    $params[] = $statusFilter;
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
    'inactive' => 0,
    'subscribers' => 0
];

$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
    FROM plans
");

$planStats = $statsStmt->fetch(PDO::FETCH_ASSOC);

if ($planStats) {
    $stats = array_merge($stats, $planStats);
}

try {
    $subscriberStmt = $pdo->query("
        SELECT COUNT(DISTINCT company_id)
        FROM subscriptions
        WHERE status = 'active'
    ");
    $stats['subscribers'] = (int)$subscriberStmt->fetchColumn();
} catch (PDOException $e) {
    $stats['subscribers'] = 0;
}

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
    FROM plans p
    {$whereSql}
");
$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

/*
|--------------------------------------------------------------------------
| PLAN LIST
|--------------------------------------------------------------------------
*/
$listStmt = $pdo->prepare("
    SELECT
        p.id,
        p.name,
        p.slug,
        p.description,
        p.price_monthly,
        p.price_yearly,
        p.max_employees,
        p.status,
        p.is_popular,
        p.created_at,
        (
            SELECT COUNT(*)
            FROM subscriptions s
            WHERE s.plan_id = p.id
              AND s.status = 'active'
        ) AS active_subscriptions
    FROM plans p
    {$whereSql}
    ORDER BY p.id ASC
    LIMIT {$limit} OFFSET {$offset}
");

$listStmt->execute($params);
$plans = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function paketPageUrl($page)
{
    $query = $_GET;
    $query['page'] = $page;

    return 'paket.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paket Langganan | Attendly Super Admin</title>

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
            transition: .2s;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
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

        .plan-name {
            font-weight: 800;
            color: #263449;
            font-size: 13px;
        }

        .plan-description {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
            max-width: 260px;
        }

        .price {
            font-weight: 800;
            font-size: 13px;
        }

        .price-sub {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
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

        .status-inactive {
            color: #bd4141;
            background: #fff0f0;
        }

        .popular {
            display: inline-block;
            padding: 4px 7px;
            border-radius: 5px;
            background: #fff4df;
            color: #b8790b;
            font-size: 9px;
            font-weight: 800;
            margin-left: 5px;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: #2865dc;
            border-color: #2865dc;
        }

        .form-control,
        .form-select {
            font-size: 13px;
            padding: 10px 12px;
            border-color: #e3e8f0;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(57, 121, 246, .1);
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

    <a href="paket.php" class="active">
        <i class="fa-solid fa-cubes"></i>
        Paket Langganan
    </a>

    <a href="subscription.php">
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

            <h1 class="page-title">Paket Langganan</h1>
            <div class="page-subtitle">
                Kelola paket dan harga layanan Attendly SaaS.
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
                    <i class="fa-solid fa-cubes"></i>
                </div>
                <div class="stat-label">Total Paket</div>
                <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e8f8ef;color:#16a05d;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-label">Paket Aktif</div>
                <div class="stat-value"><?= number_format((int)$stats['active']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff0f0;color:#e05252;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div class="stat-label">Paket Nonaktif</div>
                <div class="stat-value"><?= number_format((int)$stats['inactive']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff5e6;color:#e99a27;">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <div class="stat-label">Perusahaan Berlangganan</div>
                <div class="stat-value"><?= number_format((int)$stats['subscribers']) ?></div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="card-header-custom">
            <div>
                <h5 class="card-title">Daftar Paket</h5>
                <div class="text-secondary mt-1" style="font-size:11px;">
                    Total <?= number_format($totalRows) ?> paket ditemukan
                </div>
            </div>

            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i>
                Tambah Paket
            </button>
        </div>

        <div class="p-3 border-bottom">
            <form method="GET" class="row g-2">

                <div class="col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="fa-solid fa-magnifying-glass text-secondary"></i>
                        </span>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            placeholder="Cari nama atau deskripsi paket..."
                            value="<?= e($search) ?>"
                        >
                    </div>
                </div>

                <div class="col-lg-3">
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                            Aktif
                        </option>
                        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                            Nonaktif
                        </option>
                    </select>
                </div>

                <div class="col-lg-4 d-flex gap-2">
                    <button class="btn btn-primary">
                        <i class="fa-solid fa-filter me-1"></i>
                        Filter
                    </button>

                    <a href="paket.php" class="btn btn-light border">
                        Reset
                    </a>
                </div>

            </form>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Paket</th>
                        <th>Harga Bulanan</th>
                        <th>Harga Tahunan</th>
                        <th>Maks. Karyawan</th>
                        <th>Subscription Aktif</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$plans): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <i class="fa-solid fa-cubes fa-2x text-secondary mb-3"></i>
                            <div class="fw-bold">Belum ada paket</div>
                            <div class="text-secondary mt-1">
                                Tambahkan paket langganan pertama.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($plans as $plan): ?>
                        <tr>
                            <td>
                                <div class="plan-name">
                                    <?= e($plan['name']) ?>

                                    <?php if (!empty($plan['is_popular'])): ?>
                                        <span class="popular">POPULER</span>
                                    <?php endif; ?>
                                </div>

                                <div class="plan-description">
                                    <?= e($plan['description'] ?: 'Tidak ada deskripsi') ?>
                                </div>

                                <div class="company-meta">
                                    <?= e($plan['slug']) ?>
                                </div>
                            </td>

                            <td>
                                <div class="price">
                                    Rp <?= number_format((float)$plan['price_monthly'], 0, ',', '.') ?>
                                </div>
                                <div class="price-sub">Per bulan</div>
                            </td>

                            <td>
                                <div class="price">
                                    Rp <?= number_format((float)$plan['price_yearly'], 0, ',', '.') ?>
                                </div>
                                <div class="price-sub">Per tahun</div>
                            </td>

                            <td>
                                <?php if ((int)$plan['max_employees'] === 0): ?>
                                    <span class="text-success fw-bold">Unlimited</span>
                                <?php else: ?>
                                    <span class="fw-bold">
                                        <?= number_format((int)$plan['max_employees']) ?>
                                    </span>
                                    <span class="text-secondary">karyawan</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge bg-primary-subtle text-primary">
                                    <?= number_format((int)$plan['active_subscriptions']) ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($plan['status'] === 'active'): ?>
                                    <span class="status status-active">Aktif</span>
                                <?php else: ?>
                                    <span class="status status-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex gap-1">

                                    <button
                                        type="button"
                                        class="action-btn"
                                        title="Edit paket"
                                        onclick='editPlan(<?= json_encode([
                                            "id" => $plan["id"],
                                            "name" => $plan["name"],
                                            "description" => $plan["description"],
                                            "price_monthly" => $plan["price_monthly"],
                                            "price_yearly" => $plan["price_yearly"],
                                            "max_employees" => $plan["max_employees"],
                                            "status" => $plan["status"],
                                            "is_popular" => $plan["is_popular"]
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">
                                        <input
                                            type="hidden"
                                            name="status"
                                            value="<?= $plan['status'] === 'active' ? 'inactive' : 'active' ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn"
                                            title="<?= $plan['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                            onclick="return confirm('Ubah status paket ini?')"
                                        >
                                            <i class="fa-solid <?= $plan['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">

                                        <button
                                            type="submit"
                                            class="action-btn text-danger"
                                            title="Hapus paket"
                                            onclick="return confirm('Hapus paket ini? Paket yang sudah digunakan tidak dapat dihapus.')"
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
                        <a class="page-link" href="<?= e(paketPageUrl(max(1, $page - 1))) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(paketPageUrl($i)) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(paketPageUrl(min($totalPages, $page + 1))) ?>">
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

<!-- MODAL TAMBAH / EDIT PAKET -->
<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="planId">

                <div class="modal-header border-bottom">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle">
                            Tambah Paket
                        </h5>
                        <div class="text-secondary" style="font-size:11px;">
                            Atur harga dan batas penggunaan paket.
                        </div>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Nama Paket <span class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                name="name"
                                id="planName"
                                class="form-control"
                                placeholder="Contoh: Professional"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status Paket</label>
                            <select name="status" id="planStatus" class="form-select">
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Harga Bulanan (Rp)
                            </label>
                            <input
                                type="number"
                                name="price_monthly"
                                id="priceMonthly"
                                class="form-control"
                                min="0"
                                step="1"
                                value="0"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Harga Tahunan (Rp)
                            </label>
                            <input
                                type="number"
                                name="price_yearly"
                                id="priceYearly"
                                class="form-control"
                                min="0"
                                step="1"
                                value="0"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Maksimal Karyawan
                            </label>
                            <input
                                type="number"
                                name="max_employees"
                                id="maxEmployees"
                                class="form-control"
                                min="0"
                                value="10"
                                required
                            >
                            <div class="form-text">
                                Isi 0 jika tidak ada batas karyawan.
                            </div>
                        </div>

                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check mt-3">
                                <input
                                    type="checkbox"
                                    name="is_popular"
                                    id="isPopular"
                                    class="form-check-input"
                                    value="1"
                                >
                                <label class="form-check-label fw-semibold" for="isPopular">
                                    Tandai sebagai paket populer
                                </label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Deskripsi Paket</label>
                            <textarea
                                name="description"
                                id="planDescription"
                                class="form-control"
                                rows="3"
                                placeholder="Jelaskan fitur dan manfaat paket..."
                            ></textarea>
                        </div>

                    </div>
                </div>

                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">
                        Batal
                    </button>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i>
                        Simpan Paket
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const planModal = new bootstrap.Modal(
        document.getElementById('planModal')
    );

    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Tambah Paket';
        document.getElementById('planId').value = '';
        document.getElementById('planName').value = '';
        document.getElementById('planDescription').value = '';
        document.getElementById('priceMonthly').value = 0;
        document.getElementById('priceYearly').value = 0;
        document.getElementById('maxEmployees').value = 10;
        document.getElementById('planStatus').value = 'active';
        document.getElementById('isPopular').checked = false;

        planModal.show();
    }

    function editPlan(plan) {
        document.getElementById('modalTitle').textContent = 'Edit Paket';
        document.getElementById('planId').value = plan.id;
        document.getElementById('planName').value = plan.name || '';
        document.getElementById('planDescription').value = plan.description || '';
        document.getElementById('priceMonthly').value = plan.price_monthly || 0;
        document.getElementById('priceYearly').value = plan.price_yearly || 0;
        document.getElementById('maxEmployees').value = plan.max_employees ?? 0;
        document.getElementById('planStatus').value = plan.status || 'active';
        document.getElementById('isPopular').checked = Number(plan.is_popular) === 1;

        planModal.show();
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
    }
</script>

</body>
</html>
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
    exit('Akses ditolak. Halaman ini hanya untuk Super Admin.');
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

function redirectPage($message, $type = 'success')
{
    $_SESSION['company_flash'] = [
        'message' => $message,
        'type' => $type
    ];

    header('Location: perusahaan.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['company_csrf'])) {
    $_SESSION['company_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['company_csrf'];

function verifyCompanyCsrf()
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['company_csrf']) ||
        !hash_equals($_SESSION['company_csrf'], $token)
    ) {
        http_response_code(419);
        exit('Token keamanan tidak valid. Silakan refresh halaman.');
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCompanyCsrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($name === '') {
                redirectPage('Nama perusahaan wajib diisi.', 'danger');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirectPage('Format email tidak valid.', 'danger');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                $status = 'active';
            }

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE companies
                    SET
                        name = ?,
                        email = ?,
                        phone = ?,
                        address = ?,
                        status = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $email ?: null,
                    $phone ?: null,
                    $address ?: null,
                    $status,
                    $id
                ]);

                redirectPage('Data perusahaan berhasil diperbarui.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO companies
                    (name, email, phone, address, status, created_at)
                VALUES
                    (?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $name,
                $email ?: null,
                $phone ?: null,
                $address ?: null,
                $status
            ]);

            redirectPage('Perusahaan berhasil ditambahkan.');
        }

        if ($action === 'change_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';

            if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
                redirectPage('Data status tidak valid.', 'danger');
            }

            $stmt = $pdo->prepare("
                UPDATE companies
                SET status = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $id]);

            redirectPage(
                $status === 'active'
                    ? 'Perusahaan berhasil diaktifkan.'
                    : 'Perusahaan berhasil dinonaktifkan.'
            );
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                redirectPage('ID perusahaan tidak valid.', 'danger');
            }

            $check = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM users WHERE company_id = ?) AS total_users,
                    (SELECT COUNT(*) FROM subscriptions WHERE company_id = ?) AS total_subscriptions
            ");
            $check->execute([$id, $id]);
            $relations = $check->fetch(PDO::FETCH_ASSOC);

            if (
                (int)$relations['total_users'] > 0 ||
                (int)$relations['total_subscriptions'] > 0
            ) {
                redirectPage(
                    'Perusahaan tidak dapat dihapus karena masih memiliki pengguna atau data langganan. Nonaktifkan perusahaan sebagai gantinya.',
                    'warning'
                );
            }

            $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
            $stmt->execute([$id]);

            redirectPage('Perusahaan berhasil dihapus.');
        }

    } catch (PDOException $e) {
        redirectPage('Terjadi kesalahan database: ' . $e->getMessage(), 'danger');
    }
}

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/
$flash = $_SESSION['company_flash'] ?? null;
unset($_SESSION['company_flash']);

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
    $keyword = "%{$search}%";
    array_push($params, $keyword, $keyword, $keyword);
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = "c.status = ?";
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
    'new' => 0
];

try {
    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE
                WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                THEN 1 ELSE 0
            END) AS new
        FROM companies
    ");

    $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (PDOException $e) {
    // Statistik tidak mengganggu halaman utama.
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
    FROM companies c
    {$whereSql}
");
$countStmt->execute($params);

$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

/*
|--------------------------------------------------------------------------
| COMPANY LIST
|--------------------------------------------------------------------------
*/
$listStmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        c.email,
        c.phone,
        c.address,
        c.status,
        c.created_at,
        (
            SELECT COUNT(*)
            FROM users u
            WHERE u.company_id = c.id
        ) AS total_users,
        (
            SELECT COUNT(*)
            FROM employees emp
            WHERE emp.company_id = c.id
        ) AS total_employees,
        (
            SELECT COUNT(*)
            FROM subscriptions s
            WHERE s.company_id = c.id
              AND s.status = 'active'
        ) AS active_subscriptions
    FROM companies c
    {$whereSql}
    ORDER BY c.id DESC
    LIMIT {$limit} OFFSET {$offset}
");

$listStmt->execute($params);
$companies = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function pageUrl($page)
{
    $query = $_GET;
    $query['page'] = $page;

    return 'perusahaan.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Perusahaan | Attendly Super Admin</title>

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
                display: inline-block !important;
            }
        }

        .mobile-menu {
            display: none;
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

    <a href="perusahaan.php" class="active">
        <i class="fa-solid fa-building"></i>
        Perusahaan
    </a>

    <a href="paket.php">
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

            <h1 class="page-title">Manajemen Perusahaan</h1>
            <div class="page-subtitle">
                Kelola seluruh perusahaan yang menggunakan platform Attendly.
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
                    <i class="fa-solid fa-building"></i>
                </div>
                <div class="stat-label">Total Perusahaan</div>
                <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e8f8ef;color:#16a05d;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-label">Perusahaan Aktif</div>
                <div class="stat-value"><?= number_format((int)$stats['active']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff0f0;color:#e05252;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div class="stat-label">Perusahaan Nonaktif</div>
                <div class="stat-value"><?= number_format((int)$stats['inactive']) ?></div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff5e6;color:#e99a27;">
                    <i class="fa-solid fa-building-circle-check"></i>
                </div>
                <div class="stat-label">Perusahaan Baru (30 Hari)</div>
                <div class="stat-value"><?= number_format((int)$stats['new']) ?></div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="card-header-custom">
            <div>
                <h5 class="card-title">Daftar Perusahaan</h5>
                <div class="text-secondary mt-1" style="font-size:11px;">
                    Total <?= number_format($totalRows) ?> perusahaan ditemukan
                </div>
            </div>

            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i>
                Tambah Perusahaan
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
                            placeholder="Cari nama, email, atau telepon..."
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
                    <a href="perusahaan.php" class="btn btn-light border">
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
                        <th>Kontak</th>
                        <th>Pengguna</th>
                        <th>Karyawan</th>
                        <th>Subscription Aktif</th>
                        <th>Status</th>
                        <th>Terdaftar</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$companies): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fa-solid fa-building fa-2x text-secondary mb-3"></i>
                            <div class="fw-bold">Belum ada perusahaan</div>
                            <div class="text-secondary mt-1">
                                Data perusahaan akan muncul di sini.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($companies as $company): ?>
                        <tr>
                            <td>
                                <div class="company-name"><?= e($company['name']) ?></div>
                                <div class="company-meta">
                                    ID: #<?= (int)$company['id'] ?>
                                </div>
                            </td>

                            <td>
                                <div><?= e($company['email'] ?: '-') ?></div>
                                <div class="company-meta">
                                    <?= e($company['phone'] ?: '-') ?>
                                </div>
                            </td>

                            <td class="fw-bold">
                                <?= number_format((int)$company['total_users']) ?>
                            </td>

                            <td class="fw-bold">
                                <?= number_format((int)$company['total_employees']) ?>
                            </td>

                            <td>
                                <span class="badge bg-primary-subtle text-primary">
                                    <?= number_format((int)$company['active_subscriptions']) ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($company['status'] === 'active'): ?>
                                    <span class="status status-active">Aktif</span>
                                <?php else: ?>
                                    <span class="status status-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= $company['created_at']
                                    ? date('d/m/Y', strtotime($company['created_at']))
                                    : '-' ?>
                            </td>

                            <td>
                                <div class="d-flex gap-1">

                                    <button
                                        type="button"
                                        class="action-btn"
                                        title="Edit"
                                        onclick='editCompany(<?= json_encode([
                                            "id" => $company["id"],
                                            "name" => $company["name"],
                                            "email" => $company["email"],
                                            "phone" => $company["phone"],
                                            "address" => $company["address"],
                                            "status" => $company["status"]
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="id" value="<?= (int)$company['id'] ?>">
                                        <input
                                            type="hidden"
                                            name="status"
                                            value="<?= $company['status'] === 'active' ? 'inactive' : 'active' ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="action-btn"
                                            title="<?= $company['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>"
                                            onclick="return confirm('Yakin ingin mengubah status perusahaan ini?')"
                                        >
                                            <i class="fa-solid <?= $company['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$company['id'] ?>">

                                        <button
                                            type="submit"
                                            class="action-btn text-danger"
                                            title="Hapus"
                                            onclick="return confirm('Hapus perusahaan ini? Data yang masih memiliki pengguna atau subscription tidak dapat dihapus.')"
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
                        <a class="page-link" href="<?= e(pageUrl(max(1, $page - 1))) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
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

<!-- MODAL PERUSAHAAN -->
<div class="modal fade" id="companyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="companyId">

                <div class="modal-header border-bottom">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle">
                            Tambah Perusahaan
                        </h5>
                        <div class="text-secondary" style="font-size:11px;">
                            Masukkan informasi perusahaan.
                        </div>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Nama Perusahaan <span class="text-danger">*</span>
                            </label>
                            <input
                                type="text"
                                name="name"
                                id="companyName"
                                class="form-control"
                                placeholder="Contoh: PT Attendly Indonesia"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Perusahaan</label>
                            <input
                                type="email"
                                name="email"
                                id="companyEmail"
                                class="form-control"
                                placeholder="admin@perusahaan.com"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nomor Telepon</label>
                            <input
                                type="text"
                                name="phone"
                                id="companyPhone"
                                class="form-control"
                                placeholder="08xxxxxxxxxx"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status Perusahaan</label>
                            <select name="status" id="companyStatus" class="form-select">
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Alamat Perusahaan</label>
                            <textarea
                                name="address"
                                id="companyAddress"
                                class="form-control"
                                rows="3"
                                placeholder="Masukkan alamat lengkap perusahaan..."
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
                        Simpan Perusahaan
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const companyModal = new bootstrap.Modal(
        document.getElementById('companyModal')
    );

    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Tambah Perusahaan';
        document.getElementById('companyId').value = '';
        document.getElementById('companyName').value = '';
        document.getElementById('companyEmail').value = '';
        document.getElementById('companyPhone').value = '';
        document.getElementById('companyAddress').value = '';
        document.getElementById('companyStatus').value = 'active';

        companyModal.show();
    }

    function editCompany(company) {
        document.getElementById('modalTitle').textContent = 'Edit Perusahaan';
        document.getElementById('companyId').value = company.id;
        document.getElementById('companyName').value = company.name || '';
        document.getElementById('companyEmail').value = company.email || '';
        document.getElementById('companyPhone').value = company.phone || '';
        document.getElementById('companyAddress').value = company.address || '';
        document.getElementById('companyStatus').value = company.status || 'active';

        companyModal.show();
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('show');
    }
</script>

</body>
</html>
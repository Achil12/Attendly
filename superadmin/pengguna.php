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

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_pengguna'])) {
    $_SESSION['csrf_pengguna'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_pengguna'];

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function userStatusLabel($status): array
{
    $map = [
        'active' => ['Aktif', 'success'],
        'inactive' => ['Nonaktif', 'secondary'],
        'suspended' => ['Ditangguhkan', 'danger'],
        'pending' => ['Menunggu', 'warning']
    ];

    return $map[strtolower((string)$status)] ?? [
        ucfirst((string)$status),
        'secondary'
    ];
}

$success = '';
$error = '';

$search = trim($_GET['search'] ?? '');
$companyFilter = (int)($_GET['company_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$allowedStatuses = ['active', 'inactive', 'suspended', 'pending'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf, $token)) {
        $error = 'Token keamanan tidak valid. Muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_status') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';

            if (!in_array($newStatus, $allowedStatuses, true)) {
                $error = 'Status pengguna tidak valid.';
            } elseif ($userId === (int)($user['id'] ?? 0)) {
                $error = 'Anda tidak dapat mengubah status akun sendiri.';
            } else {
                try {
                    $check = $pdo->prepare("
                        SELECT id
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $check->execute([$userId]);

                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Pengguna tidak ditemukan.');
                    }

                    $update = $pdo->prepare("
                        UPDATE users
                        SET status = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $update->execute([$newStatus, $userId]);

                    $success = 'Status pengguna berhasil diperbarui.';
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        if ($action === 'delete') {
            $userId = (int)($_POST['user_id'] ?? 0);

            if ($userId === (int)($user['id'] ?? 0)) {
                $error = 'Anda tidak dapat menghapus akun sendiri.';
            } else {
                try {
                    $check = $pdo->prepare("
                        SELECT id
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $check->execute([$userId]);

                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Pengguna tidak ditemukan.');
                    }

                    $pdo->beginTransaction();

                    $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")
                        ->execute([$userId]);

                    $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")
                        ->execute([$userId]);

                    $pdo->prepare("DELETE FROM users WHERE id = ?")
                        ->execute([$userId]);

                    $pdo->commit();

                    $success = 'Pengguna berhasil dihapus.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = 'Pengguna tidak dapat dihapus. Akun mungkin masih memiliki data yang terhubung.';
                }
            }
        }
    }
}

$companies = [];
$allRoles = [];
$users = [];
$totalRows = 0;
$totalPages = 1;

$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'superadmin' => 0
];

$dbError = '';

try {
    $companies = $pdo->query("
        SELECT id, name
        FROM companies
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $allRoles = $pdo->query("
        SELECT DISTINCT name
        FROM roles
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
            SUM(
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = users.id
                    AND LOWER(r.name) = 'super admin'
                ) THEN 1 ELSE 0 END
            ) AS superadmin
        FROM users
    ");

    $stats = array_merge(
        $stats,
        $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []
    );

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(
            u.name LIKE ?
            OR u.email LIKE ?
            OR c.name LIKE ?
        )";

        $keyword = "%{$search}%";

        $params[] = $keyword;
        $params[] = $keyword;
        $params[] = $keyword;
    }

    if ($companyFilter > 0) {
        $where[] = "u.company_id = ?";
        $params[] = $companyFilter;
    }

    if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
        $where[] = "u.status = ?";
        $params[] = $statusFilter;
    }

    if ($roleFilter !== '') {
        $where[] = "EXISTS (
            SELECT 1
            FROM user_roles urf
            JOIN roles rf ON rf.id = urf.role_id
            WHERE urf.user_id = u.id
            AND rf.name = ?
        )";

        $params[] = $roleFilter;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM users u
        LEFT JOIN companies c ON c.id = u.company_id
        {$whereSQL}
    ");

    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.company_id,
            u.name,
            u.email,
            u.status,
            u.created_at,
            c.name AS company_name,
            (
                SELECT GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ')
                FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = u.id
            ) AS role_names
        FROM users u
        LEFT JOIN companies c ON c.id = u.company_id
        {$whereSQL}
        ORDER BY u.created_at DESC, u.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function userPageUrl($page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return '?' . http_build_query($query);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pengguna | Attendly Super Admin</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
}

* {
    font-family: 'Inter', sans-serif;
}

body {
    background: var(--background);
    color: #263247;
}

.sidebar {
    width: 255px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--sidebar);
    color: #fff;
    padding: 22px 15px;
    overflow-y: auto;
    z-index: 1000;
}

.brand {
    font-size: 21px;
    font-weight: 800;
    padding: 0 12px 28px;
}

.brand span {
    color: #67a0ff;
}

.sidebar-label {
    color: #8492a8;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.2px;
    padding: 15px 12px 8px;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 13px;
    margin-bottom: 4px;
    color: #b9c5d7;
    text-decoration: none;
    border-radius: 8px;
    font-size: 13px;
}

.sidebar a:hover,
.sidebar a.active {
    color: #fff;
    background: #2d4162;
}

.sidebar a.active {
    border-left: 3px solid #65a0ff;
}

.main {
    margin-left: 255px;
    padding: 25px;
}

.topbar {
    background: #fff;
    border-radius: 12px;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    box-shadow: 0 2px 10px rgba(0,0,0,.025);
}

.avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #e8efff;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.stat-card,
.content-card {
    background: #fff;
    border: 0;
    border-radius: 13px;
    box-shadow: 0 2px 12px rgba(0,0,0,.035);
}

.stat-card {
    padding: 20px;
    height: 100%;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.stat-value {
    font-size: 25px;
    font-weight: 800;
    margin-top: 14px;
}

.stat-label {
    color: #8490a2;
    font-size: 12px;
}

.content-card {
    padding: 22px;
}

.form-control,
.form-select {
    border-color: #e5eaf1;
    border-radius: 8px;
    font-size: 13px;
    padding: 10px 12px;
}

.btn {
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    padding: 9px 14px;
}

.table {
    font-size: 12px;
    vertical-align: middle;
}

.table thead th {
    background: #f8faff;
    color: #718096;
    font-size: 11px;
    font-weight: 700;
    padding: 13px;
    white-space: nowrap;
}

.table tbody td {
    padding: 13px;
}

.muted {
    color: #8994a5;
    font-size: 11px;
}

.user-avatar {
    width: 37px;
    height: 37px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #eaf1ff;
    color: #3979f6;
    font-weight: 800;
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
        padding: 15px;
    }
}
</style>
</head>

<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <i class="fa-solid fa-calendar-check me-2"></i>Attend<span>ly</span>
    </div>

    <div class="sidebar-label">SUPER ADMIN</div>

    <a href="dashboard.php">
        <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>

    <a href="perusahaan.php">
        <i class="fa-solid fa-building"></i> Perusahaan
    </a>

    <a href="paket.php">
        <i class="fa-solid fa-box"></i> Paket Langganan
    </a>

    <a href="subscription.php">
        <i class="fa-solid fa-repeat"></i> Subscription
    </a>

    <a href="transaksi.php">
        <i class="fa-solid fa-money-bill-transfer"></i> Transaksi
    </a>

    <a href="invoice.php">
        <i class="fa-solid fa-file-invoice"></i> Invoice
    </a>

    <a href="payment_gateway.php">
        <i class="fa-solid fa-credit-card"></i> Payment Gateway
    </a>

    <a href="pengguna.php" class="active">
        <i class="fa-solid fa-users"></i> Pengguna
    </a>

    <a href="monitoring.php">
        <i class="fa-solid fa-chart-line"></i> Monitoring
    </a>

    <div class="sidebar-label">AKUN</div>

    <a href="../ubah_password.php">
        <i class="fa-solid fa-key"></i> Ubah Password
    </a>

    <a href="../logout.php">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="mt-5 px-3 text-white-50 small">
        Attendly SaaS<br>Versi 1.0.0
    </div>
</aside>

<main class="main">

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light d-lg-none" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <h6 class="fw-bold mb-1">Manajemen Pengguna</h6>
                <div class="muted">Pantau akun pengguna seluruh perusahaan.</div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <div class="avatar">SA</div>
            <div>
                <div class="fw-bold small">Super Admin</div>
                <div class="muted">Administrator</div>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i><?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
        <div class="alert alert-danger">
            <strong>Kesalahan database:</strong> <?= e($dbError) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Total Pengguna</div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
                <div class="muted">Seluruh akun terdaftar</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Pengguna Aktif</div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['active']) ?></div>
                <div class="muted">Akun aktif</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Pengguna Nonaktif</div>
                    <div class="stat-icon bg-secondary-subtle text-secondary">
                        <i class="fa-solid fa-user-slash"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['inactive']) ?></div>
                <div class="muted">Akun nonaktif</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Super Admin</div>
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['superadmin']) ?></div>
                <div class="muted">Akun administrator utama</div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="mb-4">
            <h6 class="fw-bold mb-1">Daftar Pengguna</h6>
            <div class="muted">Cari dan kelola status akun pengguna.</div>
        </div>

        <form method="GET" class="row g-2 mb-4">

            <div class="col-lg-3 col-md-6">
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Cari nama, email, perusahaan..."
                    value="<?= e($search) ?>"
                >
            </div>

            <div class="col-lg-3 col-md-6">
                <select name="company_id" class="form-select">
                    <option value="0">Semua Perusahaan</option>
                    <?php foreach ($companies as $company): ?>
                        <option
                            value="<?= e($company['id']) ?>"
                            <?= $companyFilter === (int)$company['id'] ? 'selected' : '' ?>
                        >
                            <?= e($company['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="role" class="form-select">
                    <option value="">Semua Role</option>
                    <?php foreach ($allRoles as $role): ?>
                        <option value="<?= e($role) ?>" <?= $roleFilter === $role ? 'selected' : '' ?>>
                            <?= e($role) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= e(userStatusLabel($status)[0]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <button class="btn btn-primary">
                    <i class="fa-solid fa-search"></i>
                </button>
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <a href="pengguna.php" class="btn btn-light border">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>PENGGUNA</th>
                        <th>PERUSAHAAN</th>
                        <th>ROLE</th>
                        <th>TANGGAL DAFTAR</th>
                        <th>STATUS</th>
                        <th>AKSI</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$users): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-users fs-2 d-block mb-3"></i>
                            Tidak ada pengguna ditemukan.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($users as $item): ?>
                        <?php
                            [$statusLabel, $statusColor] = userStatusLabel($item['status']);
                            $initial = strtoupper(substr($item['name'] ?: 'U', 0, 1));
                            $isCurrentUser = (int)$item['id'] === (int)($user['id'] ?? 0);
                        ?>

                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar">
                                        <?= e($initial) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold">
                                            <?= e($item['name']) ?>
                                            <?php if ($isCurrentUser): ?>
                                                <span class="badge text-bg-primary">Anda</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="muted"><?= e($item['email']) ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <?= e($item['company_name'] ?? 'Platform Attendly') ?>
                            </td>

                            <td>
                                <?= e($item['role_names'] ?: 'Belum ada role') ?>
                            </td>

                            <td class="muted">
                                <?= e($item['created_at'] ?? '-') ?>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e($statusColor) ?>">
                                    <?= e($statusLabel) ?>
                                </span>
                            </td>

                            <td>
                                <div class="d-flex gap-1">

                                    <?php if (!$isCurrentUser): ?>

                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= e($item['id']) ?>">

                                            <select
                                                name="status"
                                                class="form-select form-select-sm"
                                                style="width:125px"
                                                onchange="if(confirm('Ubah status pengguna ini?')) this.form.submit(); else this.value=''"
                                            >
                                                <option value="">Status</option>
                                                <?php foreach ($allowedStatuses as $status): ?>
                                                    <option value="<?= e($status) ?>">
                                                        <?= e(userStatusLabel($status)[0]) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>

                                        <form method="POST" class="d-inline"
                                            onsubmit="return confirm('Hapus akun pengguna ini secara permanen?')">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= e($item['id']) ?>">

                                            <button class="btn btn-sm btn-light border text-danger" title="Hapus pengguna">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>

                                    <?php else: ?>
                                        <span class="muted">Akun saat ini</span>
                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-3">

            <div class="muted">
                Menampilkan <?= $users ? $offset + 1 : 0 ?>
                sampai <?= min($offset + $limit, $totalRows) ?>
                dari <?= number_format($totalRows) ?> pengguna
            </div>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(userPageUrl(max(1, $page - 1))) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(userPageUrl($i)) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(userPageUrl(min($totalPages, $page + 1))) ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
}
</script>

</body>
</html>
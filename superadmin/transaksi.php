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

if (empty($_SESSION['csrf_transaksi'])) {
    $_SESSION['csrf_transaksi'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_transaksi'];

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function transactionStatus($status): array
{
    $status = strtolower((string)$status);

    $map = [
        'paid' => ['Lunas', 'success'],
        'settlement' => ['Lunas', 'success'],
        'success' => ['Berhasil', 'success'],
        'pending' => ['Menunggu', 'warning'],
        'unpaid' => ['Belum Dibayar', 'warning'],
        'failed' => ['Gagal', 'danger'],
        'cancelled' => ['Dibatalkan', 'secondary'],
        'expired' => ['Kedaluwarsa', 'secondary'],
        'refunded' => ['Dikembalikan', 'info'],
        'partial_refund' => ['Pengembalian Sebagian', 'info'],
    ];

    return $map[$status] ?? [ucfirst($status ?: 'Unknown'), 'secondary'];
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$gatewayFilter = trim($_GET['gateway'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$allowedStatuses = [
    'paid', 'settlement', 'success', 'pending',
    'unpaid', 'failed', 'cancelled', 'expired',
    'refunded', 'partial_refund'
];

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        pt.transaction_id LIKE ?
        OR pt.order_id LIKE ?
        OR c.name LIKE ?
        OR pt.gateway LIKE ?
    )";

    $keyword = "%{$search}%";

    array_push($params, $keyword, $keyword, $keyword, $keyword);
}

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "pt.status = ?";
    $params[] = $statusFilter;
}

if ($gatewayFilter !== '') {
    $where[] = "pt.gateway = ?";
    $params[] = $gatewayFilter;
}

if ($dateFrom !== '') {
    $where[] = "DATE(pt.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "DATE(pt.created_at) <= ?";
    $params[] = $dateTo;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stats = [
    'total' => 0,
    'paid' => 0,
    'pending' => 0,
    'failed' => 0,
    'revenue' => 0,
];

try {
    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('paid','settlement','success') THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN status IN ('pending','unpaid') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status IN ('failed','cancelled','expired') THEN 1 ELSE 0 END) AS failed,
            COALESCE(SUM(
                CASE WHEN status IN ('paid','settlement','success')
                THEN amount ELSE 0 END
            ), 0) AS revenue
        FROM payment_transactions
    ");

    $stats = array_merge($stats, $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$gateways = [];

try {
    $gatewayStmt = $pdo->query("
        SELECT DISTINCT gateway
        FROM payment_transactions
        WHERE gateway IS NOT NULL AND gateway <> ''
        ORDER BY gateway ASC
    ");

    $gateways = $gatewayStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $gateways = [];
}

$totalRows = 0;
$totalPages = 1;
$transactions = [];

try {
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM payment_transactions pt
        LEFT JOIN companies c ON c.id = pt.company_id
        {$whereSQL}
    ");

    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $stmt = $pdo->prepare("
        SELECT
            pt.*,
            c.name AS company_name
        FROM payment_transactions pt
        LEFT JOIN companies c ON c.id = pt.company_id
        {$whereSQL}
        ORDER BY pt.created_at DESC, pt.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function pageUrl($page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return '?' . http_build_query($query);
}

function dateFormat($date): string
{
    if (!$date) {
        return '-';
    }

    return date('d/m/Y H:i', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transaksi | Attendly Super Admin</title>

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
    transition: .2s;
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

.transaction-id {
    font-weight: 700;
    color: #34435b;
}

.muted {
    color: #8994a5;
    font-size: 11px;
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
    <div class="brand"><i class="fa-solid fa-calendar-check me-2"></i>Attend<span>ly</span></div>

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

    <a href="transaksi.php" class="active">
        <i class="fa-solid fa-money-bill-transfer"></i> Transaksi
    </a>

    <a href="invoice.php">
        <i class="fa-solid fa-file-invoice"></i> Invoice
    </a>

    <a href="payment_gateway.php">
        <i class="fa-solid fa-credit-card"></i> Payment Gateway
    </a>

    <a href="pengguna.php">
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
                <h6 class="fw-bold mb-1">Manajemen Transaksi</h6>
                <div class="muted">Pantau pembayaran dan transaksi seluruh perusahaan.</div>
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

    <?php if (!empty($dbError)): ?>
        <div class="alert alert-danger">
            <strong>Terjadi kesalahan database:</strong>
            <?= e($dbError) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Total Transaksi</div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
                <div class="muted">Seluruh transaksi tercatat</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Transaksi Berhasil</div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['paid']) ?></div>
                <div class="muted">Pembayaran berhasil</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Menunggu Pembayaran</div>
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['pending']) ?></div>
                <div class="muted">Belum diselesaikan</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Total Pendapatan</div>
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                </div>
                <div class="stat-value" style="font-size:21px">
                    <?= rupiah($stats['revenue']) ?>
                </div>
                <div class="muted">Dari transaksi berhasil</div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h6 class="fw-bold mb-1">Daftar Transaksi</h6>
                <div class="muted">Riwayat pembayaran seluruh perusahaan.</div>
            </div>

            <button class="btn btn-outline-success" onclick="exportCSV()">
                <i class="fa-solid fa-file-csv me-1"></i> Export CSV
            </button>
        </div>

        <form method="GET" class="row g-2 mb-4">

            <div class="col-lg-3 col-md-6">
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Cari transaksi, order ID, perusahaan..."
                    value="<?= e($search) ?>"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= e(transactionStatus($status)[0]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="gateway" class="form-select">
                    <option value="">Semua Gateway</option>
                    <?php foreach ($gateways as $gateway): ?>
                        <option value="<?= e($gateway) ?>" <?= $gatewayFilter === $gateway ? 'selected' : '' ?>>
                            <?= e(ucfirst($gateway)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <button class="btn btn-primary" type="submit">
                    <i class="fa-solid fa-search"></i>
                </button>
            </div>

            <div class="col-12">
                <a href="transaksi.php" class="text-decoration-none small">
                    <i class="fa-solid fa-rotate-left me-1"></i> Reset Filter
                </a>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>TRANSAKSI</th>
                        <th>PERUSAHAAN</th>
                        <th>GATEWAY</th>
                        <th>METODE</th>
                        <th>TANGGAL</th>
                        <th>JUMLAH</th>
                        <th>STATUS</th>
                        <th>AKSI</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$transactions): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-receipt fs-2 d-block mb-3"></i>
                            Belum ada transaksi.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                            [$statusLabel, $statusColor] = transactionStatus($transaction['status'] ?? '');
                        ?>

                        <tr>
                            <td>
                                <div class="transaction-id">
                                    <?= e($transaction['transaction_id'] ?? '-') ?>
                                </div>
                                <div class="muted">
                                    Order: <?= e($transaction['order_id'] ?? '-') ?>
                                </div>
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    <?= e($transaction['company_name'] ?? 'Perusahaan tidak ditemukan') ?>
                                </div>
                                <div class="muted">
                                    ID: <?= e($transaction['company_id'] ?? '-') ?>
                                </div>
                            </td>

                            <td><?= e(ucfirst($transaction['gateway'] ?? '-')) ?></td>

                            <td><?= e($transaction['payment_method'] ?? '-') ?></td>

                            <td><?= e(dateFormat($transaction['created_at'] ?? null)) ?></td>

                            <td class="fw-bold">
                                <?= rupiah($transaction['amount'] ?? 0) ?>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e($statusColor) ?>">
                                    <?= e($statusLabel) ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    class="btn btn-sm btn-light border"
                                    onclick='showDetail(<?= json_encode(
                                        $transaction,
                                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                    ) ?>)'
                                    title="Detail transaksi"
                                >
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-3">
            <div class="muted">
                Menampilkan <?= $transactions ? $offset + 1 : 0 ?>
                sampai <?= min($offset + $limit, $totalRows) ?>
                dari <?= number_format($totalRows) ?> transaksi
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
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
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

    <div class="text-center text-muted small mt-4">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <div class="modal-header">
                <h6 class="modal-title fw-bold">
                    <i class="fa-solid fa-receipt text-primary me-2"></i>
                    Detail Transaksi
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3" id="detailContent"></div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Tutup</button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
}

function escapeHTML(value) {
    return String(value ?? '-').replace(/[&<>"']/g, function(char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];
    });
}

function showDetail(data) {
    const fields = [
        ['ID Transaksi', data.transaction_id],
        ['Order ID', data.order_id],
        ['Perusahaan', data.company_name],
        ['ID Perusahaan', data.company_id],
        ['Gateway', data.gateway],
        ['Metode Pembayaran', data.payment_method],
        ['Jumlah', data.amount],
        ['Mata Uang', data.currency],
        ['Status', data.status],
        ['Waktu Transaksi', data.created_at],
        ['Waktu Pembayaran', data.paid_at],
        ['Subscription ID', data.subscription_id]
    ];

    document.getElementById('detailContent').innerHTML = fields.map(([label, value]) => `
        <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1">${escapeHTML(label)}</div>
                <div class="fw-semibold text-break">${escapeHTML(value)}</div>
            </div>
        </div>
    `).join('');

    bootstrap.Modal.getOrCreateInstance(
        document.getElementById('detailModal')
    ).show();
}

function exportCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');

    const url = 'transaksi_export.php?' + params.toString();
    window.location.href = url;
}
</script>

</body>
</html>
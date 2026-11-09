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

if (empty($_SESSION['csrf_invoice'])) {
    $_SESSION['csrf_invoice'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_invoice'];

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string
{
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function invoiceStatus($status): array
{
    $map = [
        'paid' => ['Lunas', 'success'],
        'pending' => ['Menunggu', 'warning'],
        'unpaid' => ['Belum Dibayar', 'warning'],
        'overdue' => ['Jatuh Tempo', 'danger'],
        'cancelled' => ['Dibatalkan', 'secondary'],
        'expired' => ['Kedaluwarsa', 'secondary'],
        'refunded' => ['Dikembalikan', 'info'],
    ];

    return $map[strtolower((string)$status)] ?? [
        ucfirst((string)$status),
        'secondary'
    ];
}

/*
|--------------------------------------------------------------------------
| Pastikan tabel invoice tersedia
|--------------------------------------------------------------------------
| Struktur yang digunakan:
| id, company_id, subscription_id, invoice_number,
| amount, tax_amount, total_amount, status,
| issued_at, due_at, paid_at, created_at, updated_at
*/

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$allowedStatuses = [
    'paid',
    'pending',
    'unpaid',
    'overdue',
    'cancelled',
    'expired',
    'refunded'
];

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        i.invoice_number LIKE ?
        OR c.name LIKE ?
    )";

    $keyword = "%{$search}%";
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = "i.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom !== '') {
    $where[] = "DATE(i.issued_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = "DATE(i.issued_at) <= ?";
    $params[] = $dateTo;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stats = [
    'total' => 0,
    'paid' => 0,
    'pending' => 0,
    'overdue' => 0,
    'revenue' => 0
];

$invoices = [];
$totalRows = 0;
$totalPages = 1;
$dbError = '';

try {
    $statsStmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN status IN ('pending','unpaid') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue,
            COALESCE(SUM(
                CASE WHEN status = 'paid'
                THEN total_amount ELSE 0 END
            ), 0) AS revenue
        FROM invoices
    ");

    $stats = array_merge(
        $stats,
        $statsStmt->fetch(PDO::FETCH_ASSOC) ?: []
    );

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM invoices i
        LEFT JOIN companies c ON c.id = i.company_id
        {$whereSQL}
    ");

    $countStmt->execute($params);

    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $limit));

    $stmt = $pdo->prepare("
        SELECT
            i.*,
            c.name AS company_name
        FROM invoices i
        LEFT JOIN companies c ON c.id = i.company_id
        {$whereSQL}
        ORDER BY i.created_at DESC, i.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");

    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function invoicePageUrl($page): string
{
    $query = $_GET;
    $query['page'] = $page;

    return '?' . http_build_query($query);
}

function invoiceDate($date): string
{
    return $date ? date('d/m/Y H:i', strtotime($date)) : '-';
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice | Attendly Super Admin</title>

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

    <a href="invoice.php" class="active">
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
                <h6 class="fw-bold mb-1">Manajemen Invoice</h6>
                <div class="muted">Kelola dan pantau tagihan perusahaan Attendly.</div>
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

    <?php if ($dbError): ?>
        <div class="alert alert-danger">
            <strong>Kesalahan database:</strong>
            <?= e($dbError) ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Total Invoice</div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
                <div class="muted">Seluruh invoice</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Invoice Lunas</div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['paid']) ?></div>
                <div class="muted">Sudah dibayar</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Belum Dibayar</div>
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format((int)$stats['pending']) ?></div>
                <div class="muted">Menunggu pembayaran</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Pendapatan Invoice</div>
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                </div>
                <div class="stat-value" style="font-size:21px">
                    <?= rupiah($stats['revenue']) ?>
                </div>
                <div class="muted">Dari invoice lunas</div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h6 class="fw-bold mb-1">Daftar Invoice</h6>
                <div class="muted">Riwayat tagihan seluruh perusahaan.</div>
            </div>

            <button class="btn btn-outline-success" onclick="exportCSV()">
                <i class="fa-solid fa-file-csv me-1"></i> Export CSV
            </button>
        </div>

        <form method="GET" class="row g-2 mb-4">

            <div class="col-lg-4 col-md-6">
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Cari nomor invoice atau perusahaan..."
                    value="<?= e($search) ?>"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                            <?= e(invoiceStatus($status)[0]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <input
                    type="date"
                    name="date_from"
                    class="form-control"
                    value="<?= e($dateFrom) ?>"
                >
            </div>

            <div class="col-lg-2 col-md-6">
                <input
                    type="date"
                    name="date_to"
                    class="form-control"
                    value="<?= e($dateTo) ?>"
                >
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <button class="btn btn-primary">
                    <i class="fa-solid fa-search"></i>
                </button>
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <a href="invoice.php" class="btn btn-light border">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>NOMOR INVOICE</th>
                        <th>PERUSAHAAN</th>
                        <th>DITERBITKAN</th>
                        <th>JATUH TEMPO</th>
                        <th>TOTAL TAGIHAN</th>
                        <th>STATUS</th>
                        <th>AKSI</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-file-invoice fs-2 d-block mb-3"></i>
                            Belum ada invoice.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                            [$statusLabel, $statusColor] = invoiceStatus($invoice['status'] ?? '');
                        ?>

                        <tr>
                            <td>
                                <div class="fw-bold">
                                    <?= e($invoice['invoice_number'] ?? '-') ?>
                                </div>
                                <div class="muted">
                                    ID: <?= e($invoice['id']) ?>
                                </div>
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    <?= e($invoice['company_name'] ?? 'Perusahaan tidak ditemukan') ?>
                                </div>
                                <div class="muted">
                                    Perusahaan #<?= e($invoice['company_id'] ?? '-') ?>
                                </div>
                            </td>

                            <td><?= e(invoiceDate($invoice['issued_at'] ?? null)) ?></td>

                            <td><?= e(invoiceDate($invoice['due_at'] ?? null)) ?></td>

                            <td class="fw-bold">
                                <?= rupiah($invoice['total_amount'] ?? 0) ?>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e($statusColor) ?>">
                                    <?= e($statusLabel) ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-light border"
                                    onclick='showInvoice(<?= json_encode(
                                        $invoice,
                                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                    ) ?>)'
                                    title="Detail invoice"
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
                Menampilkan <?= $invoices ? $offset + 1 : 0 ?>
                sampai <?= min($offset + $limit, $totalRows) ?>
                dari <?= number_format($totalRows) ?> invoice
            </div>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(invoicePageUrl(max(1, $page - 1))) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(invoicePageUrl($i)) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(invoicePageUrl(min($totalPages, $page + 1))) ?>">
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

<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <div class="modal-header">
                <h6 class="modal-title fw-bold">
                    <i class="fa-solid fa-file-invoice text-primary me-2"></i>
                    Detail Invoice
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3" id="invoiceDetail"></div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">
                    Tutup
                </button>
                <button class="btn btn-primary" onclick="printInvoice()">
                    <i class="fa-solid fa-print me-1"></i> Cetak
                </button>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentInvoice = null;

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

function showInvoice(data) {
    currentInvoice = data;

    const fields = [
        ['Nomor Invoice', data.invoice_number],
        ['Perusahaan', data.company_name],
        ['Subscription ID', data.subscription_id],
        ['Jumlah', data.amount],
        ['Pajak', data.tax_amount],
        ['Total Tagihan', data.total_amount],
        ['Status', data.status],
        ['Tanggal Terbit', data.issued_at],
        ['Jatuh Tempo', data.due_at],
        ['Tanggal Pembayaran', data.paid_at],
        ['Dibuat', data.created_at]
    ];

    document.getElementById('invoiceDetail').innerHTML = fields.map(([label, value]) => `
        <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100">
                <div class="text-muted small mb-1">${escapeHTML(label)}</div>
                <div class="fw-semibold text-break">${escapeHTML(value)}</div>
            </div>
        </div>
    `).join('');

    bootstrap.Modal.getOrCreateInstance(
        document.getElementById('invoiceModal')
    ).show();
}

function printInvoice() {
    if (!currentInvoice) return;

    const data = currentInvoice;

    const fields = [
        ['Nomor Invoice', data.invoice_number],
        ['Perusahaan', data.company_name],
        ['Jumlah', data.amount],
        ['Pajak', data.tax_amount],
        ['Total Tagihan', data.total_amount],
        ['Status', data.status],
        ['Tanggal Terbit', data.issued_at],
        ['Jatuh Tempo', data.due_at],
        ['Tanggal Pembayaran', data.paid_at]
    ];

    const rows = fields.map(([label, value]) => `
        <tr>
            <th style="text-align:left;padding:10px;border:1px solid #ddd">${escapeHTML(label)}</th>
            <td style="padding:10px;border:1px solid #ddd">${escapeHTML(value)}</td>
        </tr>
    `).join('');

    const printWindow = window.open('', '_blank');

    if (!printWindow) {
        alert('Izinkan pop-up browser untuk mencetak invoice.');
        return;
    }

    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Invoice ${escapeHTML(data.invoice_number)}</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    padding: 35px;
                    color: #222;
                }
                h2 { margin-bottom: 5px; }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 25px;
                }
                .footer {
                    margin-top: 35px;
                    color: #777;
                    font-size: 12px;
                }
            </style>
        </head>
        <body>
            <h2>ATTENDLY</h2>
            <p>Invoice Langganan Attendly</p>
            <hr>
            <table>${rows}</table>
            <div class="footer">© 2026 Attendly. All Rights Reserved.</div>
            <script>
                window.onload = function() {
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);

    printWindow.document.close();
}

function exportCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');

    window.location.href = 'invoice_export.php?' + params.toString();
}
</script>

</body>
</html>
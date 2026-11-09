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

if (empty($_SESSION['csrf_payment_gateway'])) {
    $_SESSION['csrf_payment_gateway'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_payment_gateway'];

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function gatewayLabel($gateway): string
{
    $labels = [
        'midtrans' => 'Midtrans',
        'xendit' => 'Xendit',
        'tripay' => 'Tripay',
        'duitku' => 'Duitku',
        'ipaymu' => 'iPaymu',
        'doku' => 'DOKU',
        'paypal' => 'PayPal',
        'stripe' => 'Stripe',
        'manual' => 'Manual Transfer'
    ];

    return $labels[strtolower((string)$gateway)] ?? ucfirst((string)$gateway);
}

$success = '';
$error = '';

$availableGateways = [
    'midtrans' => 'Midtrans',
    'xendit' => 'Xendit',
    'tripay' => 'Tripay',
    'duitku' => 'Duitku',
    'ipaymu' => 'iPaymu',
    'doku' => 'DOKU',
    'paypal' => 'PayPal',
    'stripe' => 'Stripe',
    'manual' => 'Manual Transfer'
];

/*
|--------------------------------------------------------------------------
| Struktur payment_gateway_configs yang digunakan
|--------------------------------------------------------------------------
| id, company_id, gateway, merchant_id, api_key,
| secret_key, client_key, environment, is_active,
| created_at, updated_at
|
| company_id NULL = konfigurasi global SaaS.
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf, $token)) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $gateway = strtolower(trim($_POST['gateway'] ?? ''));
            $merchantId = trim($_POST['merchant_id'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            $secretKey = trim($_POST['secret_key'] ?? '');
            $clientKey = trim($_POST['client_key'] ?? '');
            $environment = $_POST['environment'] ?? 'sandbox';
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (!isset($availableGateways[$gateway])) {
                $error = 'Payment gateway tidak valid.';
            } elseif (!in_array($environment, ['sandbox', 'production'], true)) {
                $error = 'Environment tidak valid.';
            } else {
                try {
                    if ($id > 0) {
                        $check = $pdo->prepare("
                            SELECT id, gateway
                            FROM payment_gateway_configs
                            WHERE id = ? AND company_id IS NULL
                            LIMIT 1
                        ");
                        $check->execute([$id]);
                        $existing = $check->fetch(PDO::FETCH_ASSOC);

                        if (!$existing) {
                            throw new RuntimeException('Konfigurasi tidak ditemukan.');
                        }

                        if ($existing['gateway'] !== $gateway) {
                            throw new RuntimeException('Jenis gateway tidak dapat diubah pada konfigurasi yang sudah dibuat.');
                        }

                        $fields = [
                            'merchant_id' => $merchantId,
                            'environment' => $environment,
                            'is_active' => $isActive
                        ];

                        if ($apiKey !== '') {
                            $fields['api_key'] = $apiKey;
                        }

                        if ($secretKey !== '') {
                            $fields['secret_key'] = $secretKey;
                        }

                        if ($clientKey !== '') {
                            $fields['client_key'] = $clientKey;
                        }

                        $setParts = [];
                        $values = [];

                        foreach ($fields as $column => $value) {
                            $setParts[] = "{$column} = ?";
                            $values[] = $value;
                        }

                        $values[] = $id;

                        $update = $pdo->prepare("
                            UPDATE payment_gateway_configs
                            SET " . implode(', ', $setParts) . ",
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ? AND company_id IS NULL
                        ");

                        $update->execute($values);

                        $success = 'Konfigurasi payment gateway berhasil diperbarui.';
                    } else {
                        $check = $pdo->prepare("
                            SELECT id
                            FROM payment_gateway_configs
                            WHERE company_id IS NULL AND gateway = ?
                            LIMIT 1
                        ");
                        $check->execute([$gateway]);

                        if ($check->fetchColumn()) {
                            throw new RuntimeException('Gateway tersebut sudah memiliki konfigurasi global.');
                        }

                        $insert = $pdo->prepare("
                            INSERT INTO payment_gateway_configs
                            (
                                company_id,
                                gateway,
                                merchant_id,
                                api_key,
                                secret_key,
                                client_key,
                                environment,
                                is_active,
                                created_at,
                                updated_at
                            )
                            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");

                        $insert->execute([
                            $gateway,
                            $merchantId ?: null,
                            $apiKey ?: null,
                            $secretKey ?: null,
                            $clientKey ?: null,
                            $environment,
                            $isActive
                        ]);

                        $success = 'Payment gateway berhasil ditambahkan.';
                    }
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);

            try {
                $stmt = $pdo->prepare("
                    UPDATE payment_gateway_configs
                    SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND company_id IS NULL
                ");
                $stmt->execute([$id]);

                $success = $stmt->rowCount()
                    ? 'Status payment gateway berhasil diubah.'
                    : 'Tidak ada perubahan status.';
            } catch (PDOException $e) {
                $error = 'Gagal mengubah status payment gateway.';
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            try {
                $stmt = $pdo->prepare("
                    DELETE FROM payment_gateway_configs
                    WHERE id = ? AND company_id IS NULL
                ");
                $stmt->execute([$id]);

                $success = $stmt->rowCount()
                    ? 'Konfigurasi payment gateway berhasil dihapus.'
                    : 'Konfigurasi tidak ditemukan.';
            } catch (PDOException $e) {
                $error = 'Konfigurasi tidak dapat dihapus. Periksa apakah masih digunakan.';
            }
        }
    }
}

$gateways = [];
$gatewayStats = [
    'total' => 0,
    'active' => 0,
    'sandbox' => 0,
    'production' => 0
];

try {
    $stmt = $pdo->query("
        SELECT *
        FROM payment_gateway_configs
        WHERE company_id IS NULL
        ORDER BY created_at DESC, id DESC
    ");

    $gateways = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $gatewayStats['total'] = count($gateways);

    foreach ($gateways as $gateway) {
        if ((int)$gateway['is_active'] === 1) {
            $gatewayStats['active']++;
        }

        if (($gateway['environment'] ?? '') === 'sandbox') {
            $gatewayStats['sandbox']++;
        }

        if (($gateway['environment'] ?? '') === 'production') {
            $gatewayStats['production']++;
        }
    }
} catch (PDOException $e) {
    $error = $error ?: $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment Gateway | Attendly Super Admin</title>

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

.form-label {
    font-size: 12px;
    font-weight: 700;
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

.gateway-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #edf3ff;
    color: #3979f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.secret-field {
    position: relative;
}

.secret-field input {
    padding-right: 42px;
}

.secret-field button {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: #8490a2;
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

    <a href="payment_gateway.php" class="active">
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
                <h6 class="fw-bold mb-1">Payment Gateway</h6>
                <div class="muted">Konfigurasi metode pembayaran Attendly SaaS.</div>
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

    <div class="alert alert-info border-0">
        <i class="fa-solid fa-shield-halved me-2"></i>
        <strong>Keamanan:</strong>
        API Key dan Secret Key disimpan sebagai kredensial server. Jangan tampilkan
        secret key di halaman publik atau mengirimkannya ke browser pelanggan.
        Gunakan environment Sandbox untuk pengujian sebelum Production.
    </div>

    <div class="row g-3 mb-4">

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Total Gateway</div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-credit-card"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($gatewayStats['total']) ?></div>
                <div class="muted">Konfigurasi global</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Gateway Aktif</div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($gatewayStats['active']) ?></div>
                <div class="muted">Siap digunakan sesuai integrasi</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Sandbox</div>
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fa-solid fa-flask"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($gatewayStats['sandbox']) ?></div>
                <div class="muted">Mode pengujian</div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between">
                    <div class="stat-label">Production</div>
                    <div class="stat-icon bg-info-subtle text-info">
                        <i class="fa-solid fa-globe"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($gatewayStats['production']) ?></div>
                <div class="muted">Mode transaksi nyata</div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h6 class="fw-bold mb-1">Konfigurasi Gateway</h6>
                <div class="muted">Kelola kredensial pembayaran global Attendly.</div>
            </div>

            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i> Tambah Gateway
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>PAYMENT GATEWAY</th>
                        <th>MERCHANT ID</th>
                        <th>ENVIRONMENT</th>
                        <th>STATUS</th>
                        <th>DIBUAT</th>
                        <th>AKSI</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$gateways): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-credit-card fs-2 d-block mb-3"></i>
                            Belum ada konfigurasi payment gateway.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($gateways as $gateway): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="gateway-icon">
                                        <i class="fa-solid fa-credit-card"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold">
                                            <?= e(gatewayLabel($gateway['gateway'])) ?>
                                        </div>
                                        <div class="muted">
                                            ID: <?= e($gateway['id']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td><?= e($gateway['merchant_id'] ?: '-') ?></td>

                            <td>
                                <?php if (($gateway['environment'] ?? '') === 'production'): ?>
                                    <span class="badge text-bg-success">Production</span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">Sandbox</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ((int)$gateway['is_active'] === 1): ?>
                                    <span class="badge text-bg-success">Aktif</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <td class="muted">
                                <?= e($gateway['created_at'] ?? '-') ?>
                            </td>

                            <td>
                                <div class="d-flex gap-1">

                                    <button
                                        class="btn btn-sm btn-light border"
                                        onclick='editGateway(<?= json_encode(
                                            [
                                                'id' => $gateway['id'],
                                                'gateway' => $gateway['gateway'],
                                                'merchant_id' => $gateway['merchant_id'] ?? '',
                                                'environment' => $gateway['environment'] ?? 'sandbox',
                                                'is_active' => $gateway['is_active']
                                            ],
                                            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                        ) ?>)'
                                        title="Edit"
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= e($gateway['id']) ?>">

                                        <button
                                            class="btn btn-sm btn-light border"
                                            title="Aktif/nonaktif"
                                            onclick="return confirm('Ubah status gateway ini?')"
                                        >
                                            <i class="fa-solid fa-power-off"></i>
                                        </button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e($gateway['id']) ?>">

                                        <button
                                            class="btn btn-sm btn-light border text-danger"
                                            title="Hapus"
                                            onclick="return confirm('Hapus konfigurasi gateway ini?')"
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

    </div>

    <div class="text-center text-muted small mt-4">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<div class="modal fade" id="gatewayModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="gatewayId">

                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="gatewayModalTitle">
                        Tambah Payment Gateway
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label">Payment Gateway *</label>
                            <select name="gateway" id="gatewayName" class="form-select" required>
                                <option value="">Pilih Gateway</option>
                                <?php foreach ($availableGateways as $key => $label): ?>
                                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Jenis gateway tidak dapat diganti setelah konfigurasi dibuat.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Environment *</label>
                            <select name="environment" id="environment" class="form-select" required>
                                <option value="sandbox">Sandbox (Testing)</option>
                                <option value="production">Production (Live)</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Merchant ID</label>
                            <input
                                type="text"
                                name="merchant_id"
                                id="merchantId"
                                class="form-control"
                                placeholder="Masukkan Merchant ID"
                            >
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Client Key</label>
                            <div class="secret-field">
                                <input
                                    type="password"
                                    name="client_key"
                                    id="clientKey"
                                    class="form-control"
                                    placeholder="Masukkan Client Key"
                                    autocomplete="new-password"
                                >
                                <button type="button" onclick="toggleSecret('clientKey', this)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">API Key</label>
                            <div class="secret-field">
                                <input
                                    type="password"
                                    name="api_key"
                                    id="apiKey"
                                    class="form-control"
                                    placeholder="Masukkan API Key"
                                    autocomplete="new-password"
                                >
                                <button type="button" onclick="toggleSecret('apiKey', this)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Secret Key</label>
                            <div class="secret-field">
                                <input
                                    type="password"
                                    name="secret_key"
                                    id="secretKey"
                                    class="form-control"
                                    placeholder="Masukkan Secret Key"
                                    autocomplete="new-password"
                                >
                                <button type="button" onclick="toggleSecret('secretKey', this)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="is_active"
                                    id="isActive"
                                    checked
                                >
                                <label class="form-check-label fw-semibold small" for="isActive">
                                    Aktifkan payment gateway
                                </label>
                            </div>
                        </div>

                    </div>

                    <div class="alert alert-warning small mt-3 mb-0">
                        Saat mengedit, kosongkan kolom API Key, Client Key, atau Secret Key
                        jika ingin mempertahankan kredensial lama.
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Batal
                    </button>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i> Simpan Konfigurasi
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const gatewayModal = bootstrap.Modal.getOrCreateInstance(
    document.getElementById('gatewayModal')
);

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
}

function openAddModal() {
    document.getElementById('gatewayModalTitle').textContent = 'Tambah Payment Gateway';
    document.getElementById('gatewayId').value = '';
    document.getElementById('gatewayName').disabled = false;
    document.getElementById('gatewayName').value = '';
    document.getElementById('merchantId').value = '';
    document.getElementById('environment').value = 'sandbox';
    document.getElementById('apiKey').value = '';
    document.getElementById('secretKey').value = '';
    document.getElementById('clientKey').value = '';
    document.getElementById('isActive').checked = true;

    gatewayModal.show();
}

function editGateway(data) {
    document.getElementById('gatewayModalTitle').textContent = 'Edit Payment Gateway';
    document.getElementById('gatewayId').value = data.id;
    document.getElementById('gatewayName').value = data.gateway;
    document.getElementById('gatewayName').disabled = true;
    document.getElementById('merchantId').value = data.merchant_id || '';
    document.getElementById('environment').value = data.environment || 'sandbox';
    document.getElementById('apiKey').value = '';
    document.getElementById('secretKey').value = '';
    document.getElementById('clientKey').value = '';
    document.getElementById('isActive').checked = Number(data.is_active) === 1;

    gatewayModal.show();
}

function toggleSecret(id, button) {
    const input = document.getElementById(id);
    const icon = button.querySelector('i');

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>

</body>
</html>
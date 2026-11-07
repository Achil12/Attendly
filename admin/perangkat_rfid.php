
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = (int) attendly_company_id();
$csrfToken = attendly_csrf_token();

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$success = '';
$error = '';
$newApiKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $deviceName = trim($_POST['device_name'] ?? '');

            if ($deviceName === '') {
                $error = 'Nama perangkat wajib diisi.';
            } else {
                try {
                    // API Key hanya ditampilkan sekali setelah dibuat.
                    $newApiKey = 'att_' . bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $newApiKey);

                    $stmt = $pdo->prepare("
                        INSERT INTO rfid_api_tokens
                            (company_id, device_name, token_hash, status, created_at, updated_at)
                        VALUES (?, ?, ?, 'active', NOW(), NOW())
                    ");

                    $stmt->execute([
                        $companyId,
                        $deviceName,
                        $tokenHash
                    ]);

                    $_SESSION['rfid_new_api_key'] = $newApiKey;
                    header('Location: perangkat_rfid.php?created=1');
                    exit;

                } catch (PDOException $ex) {
                    error_log($ex->getMessage());
                    $error = 'Gagal membuat perangkat RFID.';
                }
            }
        }

        if ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);

            try {
                $stmt = $pdo->prepare("
                    UPDATE rfid_api_tokens
                    SET status = CASE
                        WHEN status = 'active' THEN 'inactive'
                        ELSE 'active'
                    END,
                    updated_at = NOW()
                    WHERE id = ?
                      AND company_id = ?
                ");

                $stmt->execute([$id, $companyId]);
                header('Location: perangkat_rfid.php?updated=1');
                exit;

            } catch (PDOException $ex) {
                $error = 'Gagal mengubah status perangkat.';
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            try {
                $stmt = $pdo->prepare("
                    DELETE FROM rfid_api_tokens
                    WHERE id = ?
                      AND company_id = ?
                ");

                $stmt->execute([$id, $companyId]);
                header('Location: perangkat_rfid.php?deleted=1');
                exit;

            } catch (PDOException $ex) {
                $error = 'Gagal menghapus perangkat.';
            }
        }
    }
}

if (isset($_GET['created']) && isset($_SESSION['rfid_new_api_key'])) {
    $newApiKey = $_SESSION['rfid_new_api_key'];
    unset($_SESSION['rfid_new_api_key']);
    $success = 'Perangkat RFID berhasil dibuat. Simpan API Key berikut karena hanya ditampilkan sekali.';
}

if (isset($_GET['updated'])) {
    $success = 'Status perangkat berhasil diperbarui.';
}

if (isset($_GET['deleted'])) {
    $success = 'Perangkat berhasil dihapus.';
}

$stmt = $pdo->prepare("
    SELECT
        id,
        device_name,
        status,
        last_used_at,
        created_at
    FROM rfid_api_tokens
    WHERE company_id = ?
    ORDER BY id DESC
");

$stmt->execute([$companyId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalDevices = count($devices);
$activeDevices = 0;
$inactiveDevices = 0;

foreach ($devices as $device) {
    if ($device['status'] === 'active') {
        $activeDevices++;
    } else {
        $inactiveDevices++;
    }
}

$user = attendly_user();
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Perangkat RFID | Attendly</title>

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
.sidebar a:hover, .sidebar a.active {
    color: white;
    background: #2b405f;
}
.sidebar a.active { box-shadow: inset 3px 0 var(--primary); }
.sidebar a i { width: 18px; text-align: center; }
.sidebar-footer {
    margin: 35px 10px 0;
    padding: 17px 10px;
    border-top: 1px solid #34435a;
    color: #8997ad;
    font-size: 11px;
}
.main-content { margin-left: 255px; padding: 30px; }
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
}
.page-title { font-size: 24px; font-weight: 800; margin: 0; }
.page-subtitle { color: var(--muted); margin-top: 6px; }
.profile { display: flex; align-items: center; gap: 10px; }
.avatar {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    color: white;
    background: var(--primary);
    font-weight: 700;
}
.profile-name { font-size: 13px; font-weight: 700; }
.profile-role { color: var(--muted); font-size: 11px; }
.stat-card, .content-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}
.stat-card { padding: 20px; height: 100%; }
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
.stat-value { font-size: 25px; font-weight: 800; }
.stat-label { color: var(--muted); font-size: 12px; }
.content-card { overflow: hidden; margin-bottom: 22px; }
.card-heading {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
}
.card-heading h5 { font-size: 15px; font-weight: 800; margin: 0; }
.card-heading p { color: var(--muted); font-size: 12px; margin: 5px 0 0; }
.card-body { padding: 22px; }
.form-label { font-size: 12px; font-weight: 700; }
.form-control {
    min-height: 42px;
    border-radius: 8px;
    border-color: #e2e7ef;
    font-size: 13px;
}
.btn {
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 15px;
}
.btn-primary { background: var(--primary); border-color: var(--primary); }
.table { font-size: 12px; vertical-align: middle; }
.table thead th {
    background: #f8f9fc;
    color: #69768a;
    font-size: 11px;
    font-weight: 700;
    padding: 14px;
    white-space: nowrap;
}
.table tbody td { padding: 15px 14px; }
.badge-active { background: #e5f8ed; color: #159653; }
.badge-inactive { background: #f0f1f3; color: #697386; }
.token-box {
    background: #f5f7fb;
    border: 1px dashed #b7c5dc;
    border-radius: 10px;
    padding: 15px;
    word-break: break-all;
    font-family: monospace;
    font-size: 12px;
}
@media(max-width:991px) {
    .sidebar { transform: translateX(-100%); transition: .25s; }
    .sidebar.show { transform: translateX(0); }
    .main-content { margin-left: 0; padding: 20px; }
}
@media(max-width:576px) {
    .main-content { padding: 15px; }
    .profile-info { display: none; }
    .page-title { font-size: 20px; }
}
</style>
</head>

<body>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-fingerprint"></i></div>
        Attendly
    </div>

    <div class="menu-label">Menu Utama</div>
    <a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
    <a href="karyawan.php"><i class="fa-solid fa-users"></i> Data Karyawan</a>
    <a href="departemen.php"><i class="fa-solid fa-building"></i> Departemen</a>
    <a href="jabatan.php"><i class="fa-solid fa-briefcase"></i> Jabatan</a>
    <a href="jadwal.php"><i class="fa-solid fa-calendar-days"></i> Jadwal Kerja</a>
    <a href="lokasi.php"><i class="fa-solid fa-location-dot"></i> Lokasi Kantor</a>

    <div class="menu-label">Absensi</div>
    <a href="absensi.php"><i class="fa-solid fa-fingerprint"></i> Data Absensi</a>
    <a href="izin.php"><i class="fa-solid fa-envelope-open-text"></i> Pengajuan Izin</a>
    <a href="laporan.php"><i class="fa-solid fa-chart-column"></i> Laporan</a>

    <div class="menu-label">Sistem</div>
    <a href="pengaturan.php"><i class="fa-solid fa-gear"></i> Pengaturan</a>
    <a href="perangkat_rfid.php" class="active"><i class="fa-solid fa-microchip"></i> Perangkat RFID</a>
    <a href="logout.php" onclick="return confirm('Apakah Anda yakin ingin logout?')">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly System</div>
        <div>Versi 1.0.0</div>
    </div>
</aside>

<main class="main-content">

    <div class="topbar">
        <div>
            <button class="btn btn-light d-lg-none mb-3" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="page-title">Perangkat RFID</h1>
            <div class="page-subtitle">Kelola perangkat dan simulasi absensi RFID.</div>
        </div>

        <div class="profile">
            <div class="avatar">AD</div>
            <div class="profile-info">
                <div class="profile-name"><?= e($user['name'] ?? 'Administrator') ?></div>
                <div class="profile-role">Administrator</div>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= e($success) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($newApiKey): ?>
        <div class="content-card">
            <div class="card-heading">
                <h5><i class="fa-solid fa-key text-primary me-2"></i>API Key Perangkat Baru</h5>
                <p>Salin dan simpan API Key ini. Sistem tidak menyimpan token aslinya.</p>
            </div>
            <div class="card-body">
                <div class="token-box mb-3" id="newApiKey"><?= e($newApiKey) ?></div>
                <button class="btn btn-primary" type="button" onclick="copyNewKey()">
                    <i class="fa-solid fa-copy me-2"></i>Salin API Key
                </button>
                <div class="small text-danger mt-3">
                    Jangan bagikan API Key kepada pihak yang tidak berwenang.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-microchip"></i></div>
                <div class="stat-value"><?= $totalDevices ?></div>
                <div class="stat-label">Total Perangkat</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-value"><?= $activeDevices ?></div>
                <div class="stat-label">Perangkat Aktif</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-circle-pause"></i></div>
                <div class="stat-value"><?= $inactiveDevices ?></div>
                <div class="stat-label">Perangkat Nonaktif</div>
            </div>
        </div>
    </div>

    <div class="content-card">
        <div class="card-heading">
            <h5><i class="fa-solid fa-plus-circle text-primary me-2"></i>Daftarkan Perangkat</h5>
            <p>Buat identitas perangkat dan API Key untuk ESP32 atau simulator.</p>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="create">

                <div class="col-md-8">
                    <label class="form-label">Nama Perangkat</label>
                    <input type="text" name="device_name" class="form-control"
                           placeholder="Contoh: RFID Pintu Utama" maxlength="150" required>
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-plus me-2"></i>Buat Perangkat
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="content-card">
        <div class="card-heading">
            <h5><i class="fa-solid fa-list me-2 text-primary"></i>Daftar Perangkat RFID</h5>
            <p>Daftar API Key yang terdaftar pada perusahaan ini.</p>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>PERANGKAT</th>
                        <th>STATUS</th>
                        <th>TERAKHIR DIGUNAKAN</th>
                        <th>TANGGAL DIBUAT</th>
                        <th class="text-end">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$devices): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            Belum ada perangkat RFID.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= e($device['device_name']) ?></div>
                                <small class="text-muted">ID #<?= (int)$device['id'] ?></small>
                            </td>
                            <td>
                                <?php if ($device['status'] === 'active'): ?>
                                    <span class="badge badge-active">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $device['last_used_at'] ? e($device['last_used_at']) : '-' ?></td>
                            <td><?= e($device['created_at']) ?></td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-2">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">
                                            <?= $device['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Hapus perangkat ini? API Key tidak dapat digunakan lagi.')">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">
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

    <div class="content-card">
        <div class="card-heading">
            <h5><i class="fa-solid fa-vial text-primary me-2"></i>Simulasi Scan RFID</h5>
            <p>Uji endpoint RFID menggunakan UID kartu yang sudah terdaftar.</p>
        </div>

        <div class="card-body">
            <form id="simulationForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">API Key Perangkat</label>
                        <input type="password" id="simulationApiKey" class="form-control"
                               placeholder="Masukkan API Key perangkat" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">UID Kartu RFID</label>
                        <input type="text" id="simulationUid" class="form-control"
                               placeholder="Contoh: A1B2C3D4" maxlength="32" required>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" id="scanButton">
                            <i class="fa-solid fa-fingerprint me-2"></i>Simulasikan Scan
                        </button>
                    </div>
                </div>
            </form>

            <div id="simulationResult" class="mt-3"></div>
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

function copyNewKey() {
    const key = document.getElementById('newApiKey').textContent.trim();

    navigator.clipboard.writeText(key).then(() => {
        alert('API Key berhasil disalin.');
    }).catch(() => {
        alert('Gagal menyalin API Key. Silakan salin secara manual.');
    });
}

document.getElementById('simulationForm').addEventListener('submit', async function (event) {
    event.preventDefault();

    const apiKey = document.getElementById('simulationApiKey').value.trim();
    const uid = document.getElementById('simulationUid').value.trim().toUpperCase();
    const result = document.getElementById('simulationResult');
    const button = document.getElementById('scanButton');

    button.disabled = true;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Memproses...';

    result.innerHTML = '';

    try {
        const response = await fetch('../api/rfid/scan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': apiKey
            },
            body: JSON.stringify({ uid: uid })
        });

        const data = await response.json();

        const alertClass = data.success ? 'success' : 'danger';

        result.innerHTML = `
            <div class="alert alert-${alertClass}">
                <strong>${data.success ? 'Berhasil' : 'Gagal'}</strong>
                <div class="mt-1">${escapeHtml(data.message || 'Tidak ada pesan.')}</div>
                <pre class="mt-3 mb-0 small text-wrap">${escapeHtml(JSON.stringify(data.data || {}, null, 2))}</pre>
            </div>
        `;

    } catch (error) {
        result.innerHTML = `
            <div class="alert alert-danger">
                Tidak dapat menghubungi RFID API. Periksa koneksi server.
            </div>
        `;
    } finally {
        button.disabled = false;
        button.innerHTML = '<i class="fa-solid fa-fingerprint me-2"></i>Simulasikan Scan';
    }
});

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char];
    });
}
</script>

</body>
</html>

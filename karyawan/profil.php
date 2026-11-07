
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = (int) attendly_company_id();
$userEmail = trim($user['email'] ?? '');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$success = '';
$error = '';
$employee = null;

try {
    $stmt = $pdo->prepare("
        SELECT
            e.*,
            d.name AS department_name,
            p.name AS position_name
        FROM employees e
        LEFT JOIN departments d
            ON d.id = e.department_id
           AND d.company_id = e.company_id
        LEFT JOIN positions p
            ON p.id = e.position_id
           AND p.company_id = e.company_id
        WHERE e.company_id = ?
          AND e.email = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $userEmail]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        throw new RuntimeException('Data karyawan tidak ditemukan.');
    }
} catch (Throwable $ex) {
    $error = $ex instanceof RuntimeException
        ? $ex->getMessage()
        : 'Gagal mengambil data profil.';
    error_log('Profil Karyawan: ' . $ex->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee) {
    try {
        if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Sesi keamanan tidak valid. Silakan muat ulang halaman.');
        }

        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (strlen($phone) > 30) {
            throw new RuntimeException('Nomor telepon maksimal 30 karakter.');
        }

        if (strlen($address) > 1000) {
            throw new RuntimeException('Alamat maksimal 1000 karakter.');
        }

        $stmt = $pdo->prepare("
            UPDATE employees
            SET phone = ?, address = ?, updated_at = NOW()
            WHERE id = ? AND company_id = ?
        ");

        $stmt->execute([
            $phone,
            $address,
            $employee['id'],
            $companyId
        ]);

        $success = 'Profil berhasil diperbarui.';

        $stmt = $pdo->prepare("
            SELECT
                e.*,
                d.name AS department_name,
                p.name AS position_name
            FROM employees e
            LEFT JOIN departments d
                ON d.id = e.department_id
               AND d.company_id = e.company_id
            LEFT JOIN positions p
                ON p.id = e.position_id
               AND p.company_id = e.company_id
            WHERE e.id = ? AND e.company_id = ?
            LIMIT 1
        ");
        $stmt->execute([$employee['id'], $companyId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $ex) {
        $error = $ex instanceof RuntimeException
            ? $ex->getMessage()
            : 'Gagal memperbarui profil.';
        error_log('Update Profil Karyawan: ' . $ex->getMessage());
    }
}

$name = $employee['name'] ?? ($user['name'] ?? 'Karyawan');
$photo = $employee['photo'] ?? '';
$initial = strtoupper(substr($name, 0, 1));
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profil Saya | Attendly</title>

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

.sidebar a:hover,
.sidebar a.active {
    color: white;
    background: #2b405f;
}

.sidebar a.active {
    box-shadow: inset 3px 0 var(--primary);
}

.sidebar a i {
    width: 18px;
    text-align: center;
}

.sidebar-footer {
    margin: 35px 10px 0;
    padding: 17px 10px;
    border-top: 1px solid #34435a;
    color: #8997ad;
    font-size: 11px;
}

.main-content {
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
    font-size: 24px;
    font-weight: 800;
    margin: 0;
}

.page-subtitle {
    color: var(--muted);
    margin-top: 6px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.avatar {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    font-weight: 800;
    overflow: hidden;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-name {
    font-size: 13px;
    font-weight: 700;
}

.profile-role {
    font-size: 11px;
    color: var(--muted);
}

.content-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    overflow: hidden;
    margin-bottom: 22px;
}

.card-heading {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
}

.card-heading h5 {
    font-size: 15px;
    font-weight: 800;
    margin: 0;
}

.card-body {
    padding: 24px;
}

.profile-cover {
    padding: 28px;
    background: linear-gradient(120deg, #2563eb, #5b91ff);
    color: white;
}

.profile-photo {
    width: 95px;
    height: 95px;
    border: 4px solid rgba(255,255,255,.8);
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: white;
    color: var(--primary);
    font-size: 32px;
    font-weight: 800;
    overflow: hidden;
}

.profile-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-cover h4 {
    font-size: 20px;
    font-weight: 800;
    margin: 15px 0 5px;
}

.profile-cover p {
    margin: 0;
    opacity: .85;
}

.section-title {
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 18px;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    color: #56647a;
}

.form-control {
    padding: 11px 13px;
    border-radius: 8px;
    border-color: #e2e7ef;
    font-size: 13px;
}

.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 .2rem rgba(57,121,246,.1);
}

.form-control:disabled,
.form-control[readonly] {
    background: #f5f7fb;
    color: #718096;
}

.info-item {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 13px 0;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}

.info-item:last-child {
    border-bottom: 0;
}

.info-label {
    color: var(--muted);
}

.info-value {
    font-weight: 700;
    text-align: right;
}

.btn {
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 16px;
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
}

@media(max-width:991px) {
    .sidebar {
        transform: translateX(-100%);
        transition: .25s;
    }

    .sidebar.show {
        transform: translateX(0);
    }

    .main-content {
        margin-left: 0;
        padding: 20px;
    }
}

@media(max-width:576px) {
    .main-content { padding: 15px; }
    .page-title { font-size: 20px; }
    .profile-info { display: none; }
    .card-body { padding: 17px; }
}
</style>
</head>

<body>

<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="fa-solid fa-fingerprint"></i>
        </div>
        Attendly
    </div>

    <div class="menu-label">Menu Karyawan</div>

    <a href="dashboard_karyawan.php">
        <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>

    <a href="profil.php" class="active">
        <i class="fa-solid fa-user"></i> Profil Saya
    </a>

    <a href="absensi_saya.php">
        <i class="fa-solid fa-fingerprint"></i> Riwayat Absensi
    </a>

    <a href="pengajuan_izin.php">
        <i class="fa-solid fa-envelope-open-text"></i> Pengajuan Izin
    </a>

    <a href="jadwal_saya.php">
        <i class="fa-solid fa-calendar-days"></i> Jadwal Kerja
    </a>

    <div class="menu-label">Akun</div>

    <a href="logout.php" onclick="return confirm('Apakah Anda yakin ingin logout?')">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly Employee</div>
        <div>Versi 1.0.0</div>
    </div>

</aside>

<main class="main-content">

    <div class="topbar">
        <div>
            <button class="btn btn-light d-lg-none mb-3" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <h1 class="page-title">Profil Saya</h1>
            <div class="page-subtitle">Informasi akun dan data pribadi karyawan.</div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?php if ($photo): ?>
                    <img src="../uploads/employees/<?= e(basename($photo)) ?>" alt="Foto profil">
                <?php else: ?>
                    <?= e($initial) ?>
                <?php endif; ?>
            </div>

            <div class="profile-info">
                <div class="profile-name"><?= e($name) ?></div>
                <div class="profile-role">Karyawan</div>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check me-2"></i><?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fa-solid fa-circle-exclamation me-2"></i><?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($employee): ?>

    <div class="row g-3">

        <div class="col-lg-4">

            <div class="content-card">
                <div class="profile-cover text-center">
                    <div class="profile-photo mx-auto">
                        <?php if ($photo): ?>
                            <img src="../uploads/employees/<?= e(basename($photo)) ?>" alt="Foto karyawan">
                        <?php else: ?>
                            <?= e($initial) ?>
                        <?php endif; ?>
                    </div>

                    <h4><?= e($employee['name']) ?></h4>
                    <p><?= e($employee['position_name'] ?? 'Karyawan') ?></p>
                </div>

                <div class="card-body">

                    <div class="info-item">
                        <span class="info-label">NIK</span>
                        <span class="info-value"><?= e($employee['nik'] ?? '-') ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Departemen</span>
                        <span class="info-value"><?= e($employee['department_name'] ?? '-') ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Jabatan</span>
                        <span class="info-value"><?= e($employee['position_name'] ?? '-') ?></span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <span class="badge text-bg-<?= ($employee['status'] ?? '') === 'aktif' ? 'success' : 'secondary' ?>">
                                <?= e(ucfirst($employee['status'] ?? '-')) ?>
                            </span>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Tanggal Bergabung</span>
                        <span class="info-value">
                            <?= !empty($employee['join_date'])
                                ? e(date('d/m/Y', strtotime($employee['join_date'])))
                                : '-' ?>
                        </span>
                    </div>

                </div>
            </div>

        </div>

        <div class="col-lg-8">

            <div class="content-card">

                <div class="card-heading">
                    <h5><i class="fa-solid fa-user-pen text-primary me-2"></i>Informasi Pribadi</h5>
                </div>

                <div class="card-body">

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(attendly_csrf_token()) ?>">

                        <div class="section-title">Informasi Dasar</div>

                        <div class="row g-3">

                            <div class="col-md-6">
                                <label class="form-label">Nama Lengkap</label>
                                <input type="text" class="form-control"
                                       value="<?= e($employee['name']) ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">NIK Karyawan</label>
                                <input type="text" class="form-control"
                                       value="<?= e($employee['nik'] ?? '') ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control"
                                       value="<?= e($employee['email'] ?? '') ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Jenis Kelamin</label>
                                <input type="text" class="form-control"
                                       value="<?= e($employee['gender'] ?? '-') ?>" readonly>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Nomor Telepon</label>
                                <input type="text" name="phone" class="form-control"
                                       maxlength="30"
                                       value="<?= e($employee['phone'] ?? '') ?>"
                                       placeholder="Masukkan nomor telepon">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Tanggal Bergabung</label>
                                <input type="text" class="form-control"
                                       value="<?= !empty($employee['join_date'])
                                           ? e(date('d/m/Y', strtotime($employee['join_date'])))
                                           : '-' ?>" readonly>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Alamat</label>
                                <textarea name="address" class="form-control"
                                          rows="4" maxlength="1000"
                                          placeholder="Masukkan alamat lengkap"><?= e($employee['address'] ?? '') ?></textarea>
                            </div>

                        </div>

                        <hr class="my-4">

                        <div class="d-flex justify-content-end gap-2">
                            <a href="dashboard_karyawan.php" class="btn btn-light">
                                Batal
                            </a>

                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-floppy-disk me-1"></i>
                                Simpan Perubahan
                            </button>
                        </div>

                    </form>

                </div>
            </div>

            <div class="content-card">
                <div class="card-heading">
                    <h5><i class="fa-solid fa-shield-halved text-primary me-2"></i>Informasi Keamanan</h5>
                </div>

                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Untuk menjaga keamanan akun, hubungi administrator jika ingin mengganti email login atau informasi akun lainnya.
                    </p>

                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong class="d-block small">Password Akun</strong>
                            <small class="text-muted">Ubah password login Anda.</small>
                        </div>

                        <a href="ubah_password.php" class="btn btn-outline-primary">
                            Ubah Password
                        </a>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <?php endif; ?>

    <div class="text-center text-muted small py-3">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});
</script>

</body>
</html>

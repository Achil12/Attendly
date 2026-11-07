
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = (int) attendly_company_id();
$userEmail = trim($user['email'] ?? '');

date_default_timezone_set('Asia/Jakarta');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$employee = null;
$leaveTypes = [];
$requests = [];
$error = '';
$success = '';

$statusLabels = [
    'pending' => 'Menunggu',
    'approved' => 'Disetujui',
    'rejected' => 'Ditolak'
];

$statusClasses = [
    'pending' => 'warning',
    'approved' => 'success',
    'rejected' => 'danger'
];

try {
    $stmt = $pdo->prepare("
        SELECT id, name, nik
        FROM employees
        WHERE company_id = ? AND email = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $userEmail]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        throw new RuntimeException('Data karyawan tidak ditemukan.');
    }

    $stmt = $pdo->prepare("
        SELECT id, name
        FROM leave_types
        WHERE company_id = ? AND status = 'active'
        ORDER BY name ASC
    ");
    $stmt->execute([$companyId]);
    $leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    $error = $ex instanceof RuntimeException
        ? $ex->getMessage()
        : 'Gagal memuat data pengajuan izin.';
    error_log('Pengajuan Izin Karyawan: ' . $ex->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employee) {
    try {
        if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Sesi keamanan tidak valid. Silakan muat ulang halaman.');
        }

        $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$leaveTypeId || !$startDate || !$endDate || !$reason) {
            throw new RuntimeException('Semua kolom wajib diisi.');
        }

        $start = DateTime::createFromFormat('!Y-m-d', $startDate);
        $end = DateTime::createFromFormat('!Y-m-d', $endDate);

        if (!$start || $start->format('Y-m-d') !== $startDate ||
            !$end || $end->format('Y-m-d') !== $endDate) {
            throw new RuntimeException('Format tanggal tidak valid.');
        }

        if ($end < $start) {
            throw new RuntimeException('Tanggal selesai tidak boleh sebelum tanggal mulai.');
        }

        if ($startDate < date('Y-m-d')) {
            throw new RuntimeException('Tanggal mulai tidak boleh di masa lalu.');
        }

        if (mb_strlen($reason) > 2000) {
            throw new RuntimeException('Alasan maksimal 2000 karakter.');
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM leave_types
            WHERE id = ? AND company_id = ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$leaveTypeId, $companyId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Jenis izin tidak valid.');
        }

        // Hitung hari kalender, termasuk tanggal awal dan akhir.
        $totalDays = (int)$start->diff($end)->days + 1;

        // Cegah pengajuan yang tanggalnya bertabrakan dengan pengajuan aktif.
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM leave_requests
            WHERE company_id = ?
              AND employee_id = ?
              AND status IN ('pending', 'approved')
              AND start_date <= ?
              AND end_date >= ?
        ");
        $stmt->execute([
            $companyId,
            $employee['id'],
            $endDate,
            $startDate
        ]);

        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Sudah ada pengajuan izin pada rentang tanggal tersebut.');
        }

        $attachment = null;

        if (
            isset($_FILES['attachment']) &&
            $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload lampiran gagal.');
            }

            if ($_FILES['attachment']['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Ukuran lampiran maksimal 2 MB.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['attachment']['tmp_name']);

            $allowed = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png'
            ];

            if (!isset($allowed[$mime])) {
                throw new RuntimeException('Lampiran harus berupa PDF, JPG, atau PNG.');
            }

            $uploadDirectory = __DIR__ . '/../uploads/leave';

            if (!is_dir($uploadDirectory)) {
                if (!mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Folder upload tidak dapat dibuat.');
                }
            }

            $fileName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
            $destination = $uploadDirectory . '/' . $fileName;

            if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $destination)) {
                throw new RuntimeException('Gagal menyimpan lampiran.');
            }

            $attachment = $fileName;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO leave_requests (
                    company_id,
                    employee_id,
                    leave_type_id,
                    start_date,
                    end_date,
                    total_days,
                    reason,
                    attachment,
                    status,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
            ");

            $stmt->execute([
                $companyId,
                $employee['id'],
                $leaveTypeId,
                $startDate,
                $endDate,
                $totalDays,
                $reason,
                $attachment
            ]);

        } catch (Throwable $insertError) {
            if ($attachment && is_file(__DIR__ . '/../uploads/leave/' . $attachment)) {
                unlink(__DIR__ . '/../uploads/leave/' . $attachment);
            }
            throw $insertError;
        }

        header('Location: pengajuan_izin.php?success=1');
        exit;

    } catch (Throwable $ex) {
        $error = $ex instanceof RuntimeException
            ? $ex->getMessage()
            : 'Gagal menyimpan pengajuan izin.';
        error_log('Simpan Pengajuan Izin: ' . $ex->getMessage());
    }
}

if (isset($_GET['success'])) {
    $success = 'Pengajuan izin berhasil dikirim dan menunggu persetujuan administrator.';
}

if ($employee) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                lr.id,
                lr.start_date,
                lr.end_date,
                lr.total_days,
                lr.reason,
                lr.attachment,
                lr.status,
                lr.created_at,
                lt.name AS leave_type_name
            FROM leave_requests lr
            LEFT JOIN leave_types lt
                ON lt.id = lr.leave_type_id
               AND lt.company_id = lr.company_id
            WHERE lr.company_id = ?
              AND lr.employee_id = ?
            ORDER BY lr.created_at DESC
        ");
        $stmt->execute([$companyId, $employee['id']]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $ex) {
        $error = 'Gagal mengambil riwayat pengajuan izin.';
        error_log('Riwayat Izin Karyawan: ' . $ex->getMessage());
    }
}

$totalRequests = count($requests);
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

foreach ($requests as $request) {
    if ($request['status'] === 'pending') $pendingCount++;
    if ($request['status'] === 'approved') $approvedCount++;
    if ($request['status'] === 'rejected') $rejectedCount++;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pengajuan Izin | Attendly</title>

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
}

.profile-name {
    font-size: 13px;
    font-weight: 700;
}

.profile-role {
    font-size: 11px;
    color: var(--muted);
}

.stat-card,
.content-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
}

.stat-card {
    padding: 20px;
    height: 100%;
}

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

.stat-value {
    font-size: 25px;
    font-weight: 800;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
}

.content-card {
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

.card-heading p {
    color: var(--muted);
    font-size: 12px;
    margin: 5px 0 0;
}

.card-body {
    padding: 22px;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    color: #56647a;
}

.form-control,
.form-select {
    padding: 11px 13px;
    border-radius: 8px;
    border-color: #e2e7ef;
    font-size: 12px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 .2rem rgba(57,121,246,.1);
}

.table {
    font-size: 12px;
    vertical-align: middle;
}

.table thead th {
    background: #f8f9fc;
    color: #69768a;
    font-size: 11px;
    font-weight: 700;
    padding: 14px;
    white-space: nowrap;
}

.table tbody td {
    padding: 14px;
}

.btn {
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 15px;
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
    .card-body { padding: 16px; }
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

    <a href="profil.php">
        <i class="fa-solid fa-user"></i> Profil Saya
    </a>

    <a href="absensi_saya.php">
        <i class="fa-solid fa-fingerprint"></i> Riwayat Absensi
    </a>

    <a href="pengajuan_izin.php" class="active">
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

            <h1 class="page-title">Pengajuan Izin</h1>
            <div class="page-subtitle">Ajukan izin, sakit, atau cuti dan pantau statusnya.</div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($employee['name'] ?? ($user['name'] ?? 'K'), 0, 1))) ?>
            </div>

            <div class="profile-info">
                <div class="profile-name"><?= e($employee['name'] ?? ($user['name'] ?? 'Karyawan')) ?></div>
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

    <div class="row g-3 mb-4">

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-file-lines"></i></div>
                <div class="stat-value"><?= $totalRequests ?></div>
                <div class="stat-label">Total Pengajuan</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                <div class="stat-value"><?= $pendingCount ?></div>
                <div class="stat-label">Menunggu</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-value"><?= $approvedCount ?></div>
                <div class="stat-label">Disetujui</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                <div class="stat-value"><?= $rejectedCount ?></div>
                <div class="stat-label">Ditolak</div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="card-heading">
            <h5><i class="fa-solid fa-plus-circle text-primary me-2"></i>Buat Pengajuan Baru</h5>
            <p>Lengkapi formulir berikut untuk mengajukan izin atau cuti.</p>
        </div>

        <div class="card-body">

            <form method="POST" enctype="multipart/form-data">

                <input type="hidden" name="csrf_token" value="<?= e(attendly_csrf_token()) ?>">

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label">Jenis Izin <span class="text-danger">*</span></label>
                        <select name="leave_type_id" class="form-select" required>
                            <option value="">Pilih jenis izin</option>

                            <?php foreach ($leaveTypes as $type): ?>
                                <option value="<?= (int)$type['id'] ?>">
                                    <?= e($type['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Lampiran</label>
                        <input type="file" name="attachment" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">PDF, JPG, PNG. Maksimal 2 MB.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Tanggal Mulai <span class="text-danger">*</span></label>
                        <input type="date" name="start_date" id="startDate"
                               class="form-control" min="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Tanggal Selesai <span class="text-danger">*</span></label>
                        <input type="date" name="end_date" id="endDate"
                               class="form-control" min="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Alasan Pengajuan <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="4"
                                  maxlength="2000"
                                  placeholder="Jelaskan alasan pengajuan izin..." required></textarea>
                    </div>

                </div>

                <div class="d-flex justify-content-end mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-paper-plane me-1"></i>
                        Kirim Pengajuan
                    </button>
                </div>

            </form>

        </div>
    </div>

    <div class="content-card">

        <div class="card-heading">
            <h5><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Riwayat Pengajuan</h5>
            <p>Daftar seluruh pengajuan izin Anda.</p>
        </div>

        <div class="table-responsive">

            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>NO</th>
                        <th>JENIS IZIN</th>
                        <th>TANGGAL MULAI</th>
                        <th>TANGGAL SELESAI</th>
                        <th>DURASI</th>
                        <th>STATUS</th>
                        <th>LAMPIRAN</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$requests): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="fa-solid fa-folder-open fs-3 d-block mb-2"></i>
                            Belum ada pengajuan izin.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($requests as $index => $request): ?>
                        <?php
                        $status = $request['status'] ?? 'pending';
                        $label = $statusLabels[$status] ?? ucfirst($status);
                        $class = $statusClasses[$status] ?? 'secondary';
                        ?>

                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong><?= e($request['leave_type_name'] ?? '-') ?></strong>
                                <div class="text-muted small">
                                    <?= e($request['reason']) ?>
                                </div>
                            </td>

                            <td><?= e(date('d/m/Y', strtotime($request['start_date']))) ?></td>

                            <td><?= e(date('d/m/Y', strtotime($request['end_date']))) ?></td>

                            <td><?= (int)$request['total_days'] ?> hari</td>

                            <td>
                                <span class="badge text-bg-<?= e($class) ?>">
                                    <?= e($label) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (!empty($request['attachment'])): ?>
                                    <a href="../uploads/leave/<?= rawurlencode(basename($request['attachment'])) ?>"
                                       target="_blank" rel="noopener"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-paperclip"></i> Lihat
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>

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

const startDate = document.getElementById('startDate');
const endDate = document.getElementById('endDate');

startDate?.addEventListener('change', function () {
    endDate.min = this.value;

    if (endDate.value && endDate.value < this.value) {
        endDate.value = this.value;
    }
});
</script>

</body>
</html>

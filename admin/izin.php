<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$user = attendly_user();

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function leave_redirect($params = []) {
    header('Location: izin.php' . (!empty($params) ? '?' . http_build_query($params) : ''));
    exit;
}

function leave_log($pdo, $companyId, $userId, $action, $description) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs
                (company_id, user_id, action, description, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$companyId, $userId, $action, $description]);
    } catch (Throwable $e) {
        // Log tidak menggagalkan proses utama.
    }
}

function leave_status_label($status) {
    $labels = [
        'pending' => 'Menunggu',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan'
    ];

    return $labels[$status] ?? ucfirst($status);
}

function leave_status_class($status) {
    $classes = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'secondary'
    ];

    return $classes[$status] ?? 'secondary';
}

$errors = [];
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sesi keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'update_status') {
                $requestId = (int)($_POST['request_id'] ?? 0);
                $status = $_POST['status'] ?? '';

                if ($requestId <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
                    throw new Exception('Data pengajuan tidak valid.');
                }

                $stmt = $pdo->prepare("
                    UPDATE leave_requests
                    SET status = ?,
                        approved_by = ?,
                        updated_at = NOW()
                    WHERE id = ?
                      AND company_id = ?
                      AND status = 'pending'
                ");

                $stmt->execute([
                    $status,
                    $user['id'] ?? null,
                    $requestId,
                    $companyId
                ]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Pengajuan tidak ditemukan atau sudah diproses.');
                }

                leave_log(
                    $pdo,
                    $companyId,
                    $user['id'] ?? null,
                    'update',
                    'Mengubah status pengajuan izin #' . $requestId . ' menjadi ' . $status
                );

                leave_redirect([
                    'success' => $status === 'approved'
                        ? 'Pengajuan berhasil disetujui.'
                        : 'Pengajuan berhasil ditolak.'
                ]);
            }

            if ($action === 'delete') {
                $requestId = (int)($_POST['request_id'] ?? 0);

                if ($requestId <= 0) {
                    throw new Exception('Data pengajuan tidak valid.');
                }

                $stmt = $pdo->prepare("
                    DELETE FROM leave_requests
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$requestId, $companyId]);

                leave_log(
                    $pdo,
                    $companyId,
                    $user['id'] ?? null,
                    'delete',
                    'Menghapus pengajuan izin #' . $requestId
                );

                leave_redirect(['success' => 'Pengajuan berhasil dihapus.']);
            }

            throw new Exception('Aksi tidak dikenali.');

        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = (int)($_GET['leave_type'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = ['lr.company_id = ?'];
$params = [$companyId];

if ($search !== '') {
    $where[] = '(e.name LIKE ? OR e.nik LIKE ?)';
    $keyword = '%' . $search . '%';
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($statusFilter !== '') {
    $where[] = 'lr.status = ?';
    $params[] = $statusFilter;
}

if ($typeFilter > 0) {
    $where[] = 'lr.leave_type_id = ?';
    $params[] = $typeFilter;
}

if ($dateFrom !== '') {
    $where[] = 'lr.start_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'lr.end_date <= ?';
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM leave_requests lr
    INNER JOIN employees e
        ON e.id = lr.employee_id
       AND e.company_id = lr.company_id
    WHERE $whereSql
");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $limit));

$stmt = $pdo->prepare("
    SELECT
        lr.id,
        lr.employee_id,
        lr.leave_type_id,
        lr.start_date,
        lr.end_date,
        lr.total_days,
        lr.reason,
        NULL AS attachment,
        lr.status,
        NULL AS admin_notes,
        lr.created_at,
        e.name AS employee_name,
        e.nik,
        d.name AS department_name,
        lt.name AS leave_type_name
    FROM leave_requests lr
    INNER JOIN employees e
        ON e.id = lr.employee_id
       AND e.company_id = lr.company_id
    LEFT JOIN departments d
        ON d.id = e.department_id
       AND d.company_id = lr.company_id
    LEFT JOIN leave_types lt
        ON lt.id = lr.leave_type_id
       AND lt.company_id = lr.company_id
    WHERE $whereSql
    ORDER BY
        CASE lr.status
            WHEN 'pending' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'rejected' THEN 3
            ELSE 4
        END,
        lr.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT id, name
    FROM leave_types
    WHERE company_id = ? AND status = 'active'
    ORDER BY name
");
$stmt->execute([$companyId]);
$leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'approved') AS approved_count,
        SUM(status = 'rejected') AS rejected_count
    FROM leave_requests
    WHERE company_id = ?
      AND MONTH(created_at) = MONTH(CURDATE())
      AND YEAR(created_at) = YEAR(CURDATE())
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalThisMonth = (int)($stats['total'] ?? 0);
$pendingCount = (int)($stats['pending_count'] ?? 0);
$approvedCount = (int)($stats['approved_count'] ?? 0);
$rejectedCount = (int)($stats['rejected_count'] ?? 0);

$queryParams = $_GET;
unset($queryParams['page']);

$paginationUrl = function($number) use ($queryParams) {
    return '?' . http_build_query(array_merge($queryParams, ['page' => $number]));
};
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
    --text: #263449;
    --muted: #8994a5;
    --border: #edf0f5;
}

* { font-family: 'Inter', sans-serif; }

body {
    background: var(--background);
    color: var(--text);
    font-size: 14px;
}

.sidebar {
    width: 255px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: var(--sidebar);
    color: #fff;
    padding: 24px 16px;
    z-index: 1000;
    overflow-y: auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 21px;
    font-weight: 800;
    margin-bottom: 32px;
    padding: 0 10px;
}

.brand-icon {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.menu-label {
    color: #8492a8;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 22px 10px 10px;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #aebbd0;
    text-decoration: none;
    padding: 12px 13px;
    border-radius: 9px;
    margin-bottom: 4px;
    font-weight: 500;
}

.sidebar a:hover,
.sidebar a.active {
    color: #fff;
    background: var(--primary);
}

.sidebar a i { width: 18px; }

.sidebar-footer {
    border-top: 1px solid #344158;
    margin-top: 30px;
    padding: 18px 10px 0;
    color: #8997ad;
    font-size: 12px;
}

.main-content {
    margin-left: 255px;
    padding: 28px;
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

.page-subtitle { color: var(--muted); }

.profile {
    display: flex;
    align-items: center;
    gap: 11px;
}

.avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e2ebff;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.stat-card,
.content-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    height: 100%;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    font-size: 25px;
    font-weight: 800;
    margin-top: 7px;
}

.card-heading {
    font-weight: 700;
    font-size: 16px;
}

.form-control,
.form-select {
    border-color: #e5e9f0;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 13px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 .2rem rgba(57,121,246,.1);
}

.btn {
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    padding: 9px 14px;
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
}

.table { vertical-align: middle; }

.table thead th {
    background: #f8f9fc;
    color: #78869a;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 14px;
    border-bottom: 1px solid var(--border);
}

.table tbody td {
    padding: 14px;
    border-bottom: 1px solid var(--border);
}

.badge {
    font-weight: 600;
    padding: 7px 10px;
}

.pagination .page-link {
    color: var(--primary);
    border-color: var(--border);
    font-size: 12px;
}

.pagination .active .page-link {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

@media (max-width: 991px) {
    .sidebar {
        transform: translateX(-100%);
        transition: .25s;
    }

    .sidebar.show { transform: translateX(0); }

    .main-content {
        margin-left: 0;
        padding: 18px;
    }
}

@media (max-width: 575px) {
    .topbar { align-items: flex-start; }
    .profile { display: none; }
}
</style>
</head>

<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">

    <div class="brand">
        <div class="brand-icon">
            <i class="fa-solid fa-fingerprint"></i>
        </div>
        Attendly
    </div>

    <div class="menu-label">Menu Utama</div>

    <a href="dashboard.php">
        <i class="fa-solid fa-gauge-high"></i> Dashboard
    </a>

    <a href="karyawan.php">
        <i class="fa-solid fa-users"></i> Data Karyawan
    </a>

    <a href="departemen.php">
        <i class="fa-solid fa-building"></i> Departemen
    </a>

    <a href="jabatan.php">
        <i class="fa-solid fa-briefcase"></i> Jabatan
    </a>

    <a href="jadwal.php">
        <i class="fa-solid fa-calendar-days"></i> Jadwal Kerja
    </a>

    <a href="lokasi.php">
        <i class="fa-solid fa-location-dot"></i> Lokasi Kantor
    </a>

    <div class="menu-label">Absensi</div>

    <a href="absensi.php">
        <i class="fa-solid fa-fingerprint"></i> Data Absensi
    </a>

    <a href="izin.php" class="active">
        <i class="fa-solid fa-envelope-open-text"></i> Pengajuan Izin
    </a>

    <a href="laporan.php">
        <i class="fa-solid fa-chart-column"></i> Laporan
    </a>

    <div class="menu-label">Sistem</div>

    <a href="pengaturan.php">
        <i class="fa-solid fa-gear"></i> Pengaturan
    </a>

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
            <button class="btn btn-light d-lg-none mb-2" id="menuToggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="page-title">Pengajuan Izin</h1>
            <div class="page-subtitle">Kelola pengajuan cuti, izin, dan sakit karyawan.</div>
        </div>

        <div class="profile">
            <div class="avatar">AD</div>
            <div>
                <div class="fw-bold"><?= e($user['name'] ?? 'Administrator') ?></div>
                <small class="text-muted">Administrator</small>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i><?= e($success) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>
            <button class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-file-lines"></i>
                    </div>
                    <span class="stat-label">Bulan Ini</span>
                </div>
                <div class="stat-value"><?= number_format($totalThisMonth) ?></div>
                <div class="stat-label">Total pengajuan</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-warning-subtle text-warning">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <span class="stat-label">Menunggu</span>
                </div>
                <div class="stat-value"><?= number_format($pendingCount) ?></div>
                <div class="stat-label">Perlu diproses</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <span class="stat-label">Disetujui</span>
                </div>
                <div class="stat-value"><?= number_format($approvedCount) ?></div>
                <div class="stat-label">Pengajuan disetujui</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>
                    <span class="stat-label">Ditolak</span>
                </div>
                <div class="stat-value"><?= number_format($rejectedCount) ?></div>
                <div class="stat-label">Pengajuan ditolak</div>
            </div>
        </div>
    </div>

    <div class="content-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <div class="card-heading">Daftar Pengajuan</div>
                <small class="text-muted">Periksa dan proses pengajuan karyawan.</small>
            </div>
        </div>

        <form method="GET" class="row g-2 mb-3">
            <div class="col-lg-3 col-md-6">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama atau NIK..."
                       value="<?= e($search) ?>">
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Menunggu</option>
                    <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
                    <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <select name="leave_type" class="form-select">
                    <option value="">Semua Jenis</option>
                    <?php foreach ($leaveTypes as $type): ?>
                        <option value="<?= (int)$type['id'] ?>"
                            <?= $typeFilter === (int)$type['id'] ? 'selected' : '' ?>>
                            <?= e($type['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_from" class="form-control"
                       value="<?= e($dateFrom) ?>" aria-label="Dari tanggal">
            </div>

            <div class="col-lg-2 col-md-6">
                <input type="date" name="date_to" class="form-control"
                       value="<?= e($dateTo) ?>" aria-label="Sampai tanggal">
            </div>

            <div class="col-lg-1 col-md-6 d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter"></i>
                </button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Karyawan</th>
                        <th>Jenis Izin</th>
                        <th>Tanggal</th>
                        <th>Durasi</th>
                        <th>Alasan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$requests): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <i class="fa-solid fa-folder-open fs-2 text-muted mb-3"></i>
                            <div class="fw-semibold">Belum ada pengajuan izin</div>
                            <small class="text-muted">Pengajuan karyawan akan tampil di sini.</small>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= e($request['employee_name']) ?></div>
                                <small class="text-muted">
                                    <?= e($request['nik']) ?>
                                    <?= $request['department_name'] ? ' · ' . e($request['department_name']) : '' ?>
                                </small>
                            </td>

                            <td><?= e($request['leave_type_name'] ?? 'Tidak diketahui') ?></td>

                            <td>
                                <div><?= e(date('d/m/Y', strtotime($request['start_date']))) ?></div>
                                <small class="text-muted">
                                    s/d <?= e(date('d/m/Y', strtotime($request['end_date']))) ?>
                                </small>
                            </td>

                            <td><?= (int)$request['total_days'] ?> hari</td>

                            <td style="min-width:180px;max-width:260px">
                                <span title="<?= e($request['reason']) ?>">
                                    <?= e(mb_strimwidth($request['reason'] ?? '-', 0, 65, '...')) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge text-bg-<?= e(leave_status_class($request['status'])) ?>">
                                    <?= e(leave_status_label($request['status'])) ?>
                                </span>
                            </td>

                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm" data-bs-toggle="dropdown">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button class="dropdown-item"
                                                data-bs-toggle="modal"
                                                data-bs-target="#detailModal"
                                                data-name="<?= e($request['employee_name']) ?>"
                                                data-nik="<?= e($request['nik']) ?>"
                                                data-type="<?= e($request['leave_type_name'] ?? '-') ?>"
                                                data-start="<?= e($request['start_date']) ?>"
                                                data-end="<?= e($request['end_date']) ?>"
                                                data-days="<?= (int)$request['total_days'] ?>"
                                                data-reason="<?= e($request['reason']) ?>"
                                                data-status="<?= e(leave_status_label($request['status'])) ?>"
                                                data-notes="<?= e($request['admin_notes']) ?>"
                                                data-attachment="<?= e($request['attachment']) ?>">
                                                <i class="fa-solid fa-eye me-2"></i>Detail
                                            </button>
                                        </li>

                                        <?php if ($request['status'] === 'pending'): ?>
                                            <li>
                                                <button class="dropdown-item text-success"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#processModal"
                                                    data-id="<?= (int)$request['id'] ?>"
                                                    data-status="approved">
                                                    <i class="fa-solid fa-check me-2"></i>Setujui
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item text-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#processModal"
                                                    data-id="<?= (int)$request['id'] ?>"
                                                    data-status="rejected">
                                                    <i class="fa-solid fa-xmark me-2"></i>Tolak
                                                </button>
                                            </li>
                                        <?php endif; ?>

                                        <li><hr class="dropdown-divider"></li>

                                        <li>
                                            <form method="POST"
                                                onsubmit="return confirm('Hapus pengajuan ini?')">
                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e(attendly_csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="request_id"
                                                       value="<?= (int)$request['id'] ?>">
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="fa-solid fa-trash me-2"></i>Hapus
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
            <small class="text-muted">
                Menampilkan <?= count($requests) ?> dari <?= number_format($totalRows) ?> pengajuan
            </small>

            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($paginationUrl(max(1, $page - 1))) ?>">
                            Sebelumnya
                        </a>
                    </li>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($paginationUrl($i)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($paginationUrl(min($totalPages, $page + 1))) ?>">
                            Berikutnya
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>

</main>

<!-- MODAL DETAIL -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Detail Pengajuan Izin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <small class="text-muted">Karyawan</small>
                    <div class="fw-bold" id="detailName"></div>
                    <small class="text-muted" id="detailNik"></small>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <small class="text-muted">Jenis Izin</small>
                        <div class="fw-semibold" id="detailType"></div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Durasi</small>
                        <div class="fw-semibold" id="detailDays"></div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Mulai</small>
                        <div class="fw-semibold" id="detailStart"></div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Selesai</small>
                        <div class="fw-semibold" id="detailEnd"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <small class="text-muted">Alasan</small>
                    <div id="detailReason" class="p-3 bg-light rounded"></div>
                </div>

                <div class="mb-3">
                    <small class="text-muted">Status</small>
                    <div class="fw-semibold" id="detailStatus"></div>
                </div>

                <div class="mb-3">
                    <small class="text-muted">Catatan Admin</small>
                    <div id="detailNotes" class="text-muted">-</div>
                </div>

                <div id="attachmentWrapper" class="d-none">
                    <small class="text-muted">Lampiran</small>
                    <div>
                        <a href="#" id="detailAttachment" target="_blank" rel="noopener">
                            <i class="fa-solid fa-paperclip me-1"></i>Lihat Lampiran
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL PROSES -->
<div class="modal fade" id="processModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="processTitle">Proses Pengajuan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(attendly_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" id="processRequestId">
                    <input type="hidden" name="status" id="processStatus">

                    <p id="processMessage"></p>

                    <label class="form-label fw-semibold">Catatan Admin</label>
                    <textarea name="admin_notes" class="form-control" rows="3"
                              placeholder="Tambahkan catatan (opsional)"></textarea>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="processSubmit">
                        Konfirmasi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});

document.getElementById('processModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const status = button.dataset.status;

    document.getElementById('processRequestId').value = button.dataset.id;
    document.getElementById('processStatus').value = status;

    if (status === 'approved') {
        document.getElementById('processTitle').textContent = 'Setujui Pengajuan';
        document.getElementById('processMessage').textContent =
            'Apakah Anda yakin ingin menyetujui pengajuan ini?';
        document.getElementById('processSubmit').textContent = 'Setujui Pengajuan';
        document.getElementById('processSubmit').className = 'btn btn-success';
    } else {
        document.getElementById('processTitle').textContent = 'Tolak Pengajuan';
        document.getElementById('processMessage').textContent =
            'Apakah Anda yakin ingin menolak pengajuan ini?';
        document.getElementById('processSubmit').textContent = 'Tolak Pengajuan';
        document.getElementById('processSubmit').className = 'btn btn-danger';
    }
});

document.getElementById('detailModal')?.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;

    document.getElementById('detailName').textContent = button.dataset.name || '-';
    document.getElementById('detailNik').textContent = button.dataset.nik || '-';
    document.getElementById('detailType').textContent = button.dataset.type || '-';
    document.getElementById('detailDays').textContent = (button.dataset.days || '0') + ' hari';
    document.getElementById('detailStart').textContent = button.dataset.start || '-';
    document.getElementById('detailEnd').textContent = button.dataset.end || '-';
    document.getElementById('detailReason').textContent = button.dataset.reason || '-';
    document.getElementById('detailStatus').textContent = button.dataset.status || '-';
    document.getElementById('detailNotes').textContent = button.dataset.notes || '-';

    const attachment = button.dataset.attachment;
    const wrapper = document.getElementById('attachmentWrapper');
    const link = document.getElementById('detailAttachment');

    if (attachment) {
        link.href = attachment;
        wrapper.classList.remove('d-none');
    } else {
        link.removeAttribute('href');
        wrapper.classList.add('d-none');
    }
});
</script>

</body>
</html>
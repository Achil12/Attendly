
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$user = attendly_user();

if (!$companyId) {
    exit('Company tidak ditemukan. Silakan login kembali.');
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectDepartment($params = []) {
    $url = 'departemen.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

$success = '';
$error = '';

/* =====================================================
   PROSES FORM
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {

        $action = $_POST['action'] ?? '';

        /* TAMBAH / EDIT DEPARTEMEN */
        if ($action === 'save') {

            $id = (int)($_POST['department_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($name === '') {
                $error = 'Nama departemen wajib diisi.';
            } elseif (!in_array($status, ['active', 'inactive'], true)) {
                $error = 'Status departemen tidak valid.';
            } else {

                try {

                    $duplicate = $pdo->prepare("
                        SELECT id
                        FROM departments
                        WHERE company_id = ?
                        AND LOWER(name) = LOWER(?)
                        AND id != ?
                        LIMIT 1
                    ");
                    $duplicate->execute([$companyId, $name, $id]);

                    if ($duplicate->fetch()) {
                        throw new Exception('Nama departemen sudah digunakan.');
                    }

                    if ($id > 0) {

                        $check = $pdo->prepare("
                            SELECT id
                            FROM departments
                            WHERE id = ? AND company_id = ?
                            LIMIT 1
                        ");
                        $check->execute([$id, $companyId]);

                        if (!$check->fetch()) {
                            throw new Exception('Departemen tidak ditemukan.');
                        }

                        $stmt = $pdo->prepare("
                            UPDATE departments
                            SET name = ?,
                                description = ?,
                                status = ?,
                                updated_at = NOW()
                            WHERE id = ? AND company_id = ?
                        ");

                        $stmt->execute([
                            $name,
                            $description ?: null,
                            $status,
                            $id,
                            $companyId
                        ]);

                        redirectDepartment(['success' => 'updated']);

                    } else {

                        $stmt = $pdo->prepare("
                            INSERT INTO departments (
                                company_id,
                                name,
                                description,
                                status,
                                created_at,
                                updated_at
                            ) VALUES (?, ?, ?, ?, NOW(), NOW())
                        ");

                        $stmt->execute([
                            $companyId,
                            $name,
                            $description ?: null,
                            $status
                        ]);

                        redirectDepartment(['success' => 'created']);
                    }

                } catch (Throwable $ex) {
                    $error = $ex->getMessage();
                }
            }
        }

        /* NONAKTIFKAN DEPARTEMEN */
        if ($action === 'deactivate') {

            $id = (int)($_POST['department_id'] ?? 0);

            if ($id > 0) {
                try {

                    $check = $pdo->prepare("
                        SELECT id
                        FROM departments
                        WHERE id = ? AND company_id = ?
                        LIMIT 1
                    ");
                    $check->execute([$id, $companyId]);

                    if (!$check->fetch()) {
                        throw new Exception('Departemen tidak ditemukan.');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE departments
                        SET status = 'inactive', updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$id, $companyId]);

                    redirectDepartment(['success' => 'deactivated']);

                } catch (Throwable $ex) {
                    $error = $ex->getMessage();
                }
            }
        }

        /* AKTIFKAN KEMBALI */
        if ($action === 'activate') {

            $id = (int)($_POST['department_id'] ?? 0);

            if ($id > 0) {
                try {

                    $stmt = $pdo->prepare("
                        UPDATE departments
                        SET status = 'active', updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");
                    $stmt->execute([$id, $companyId]);

                    redirectDepartment(['success' => 'activated']);

                } catch (Throwable $ex) {
                    $error = $ex->getMessage();
                }
            }
        }
    }
}

/* =====================================================
   NOTIFIKASI
===================================================== */

if (isset($_GET['success'])) {
    $messages = [
        'created' => 'Departemen berhasil ditambahkan.',
        'updated' => 'Departemen berhasil diperbarui.',
        'deactivated' => 'Departemen berhasil dinonaktifkan.',
        'activated' => 'Departemen berhasil diaktifkan.'
    ];

    $success = $messages[$_GET['success']] ?? '';
}

/* =====================================================
   FILTER
===================================================== */

$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = ['d.company_id = ?'];
$params = [$companyId];

if ($search !== '') {
    $where[] = 'd.name LIKE ?';
    $params[] = '%' . $search . '%';
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'd.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

/* =====================================================
   STATISTIK
===================================================== */

$statsQuery = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active,
        SUM(status = 'inactive') AS inactive
    FROM departments
    WHERE company_id = ?
");

$statsQuery->execute([$companyId]);
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

/* =====================================================
   JUMLAH KARYAWAN PER DEPARTEMEN
===================================================== */

$countQuery = $pdo->prepare("
    SELECT COUNT(*)
    FROM departments d
    WHERE $whereSql
");

$countQuery->execute($params);

$totalRows = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

/* =====================================================
   DATA DEPARTEMEN
===================================================== */

$listQuery = $pdo->prepare("
    SELECT
        d.*,
        (
            SELECT COUNT(*)
            FROM employees e
            WHERE e.department_id = d.id
            AND e.company_id = d.company_id
        ) AS employee_count
    FROM departments d
    WHERE $whereSql
    ORDER BY d.id DESC
    LIMIT $limit OFFSET $offset
");

$listQuery->execute($params);
$departments = $listQuery->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = attendly_csrf_token();

$currentName = $user['name'] ?? 'Administrator';
$currentRole = 'Administrator';

if (!empty($user['roles'])) {
    $currentRole = is_array($user['roles'])
        ? implode(', ', $user['roles'])
        : $user['roles'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Departemen | Attendly</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
    --text: #263449;
    --muted: #8290a5;
    --border: #e8edf4;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    font-family: 'Inter', sans-serif;
    color: var(--text);
    font-size: 14px;
}

/* SIDEBAR */
.sidebar {
    width: 255px;
    height: 100vh;
    background: var(--sidebar);
    position: fixed;
    top: 0;
    left: 0;
    padding: 24px 16px;
    color: #fff;
    z-index: 1000;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 21px;
    font-weight: 800;
    padding: 0 10px;
    margin-bottom: 35px;
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
    color: #8493aa;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    margin: 18px 10px 9px;
}

.sidebar a {
    color: #aebbd0;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 13px;
    border-radius: 9px;
    margin-bottom: 4px;
    font-size: 13px;
    font-weight: 500;
    transition: .2s;
}

.sidebar a i {
    width: 18px;
    text-align: center;
}

.sidebar a:hover,
.sidebar a.active {
    background: var(--primary);
    color: #fff;
}

.sidebar-footer {
    margin-top: auto;
    padding: 18px 10px 0;
    border-top: 1px solid #344157;
    color: #91a0b6;
    font-size: 11px;
}

/* MAIN */
.main-content {
    margin-left: 255px;
    padding: 25px 30px;
    min-height: 100vh;
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
    margin: 0 0 5px;
}

.page-subtitle {
    color: var(--muted);
    margin: 0;
}

.profile {
    display: flex;
    align-items: center;
    gap: 11px;
}

.profile-avatar {
    width: 40px;
    height: 40px;
    background: #e5edff;
    color: var(--primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.profile-name {
    font-weight: 700;
    font-size: 13px;
}

.profile-role {
    color: var(--muted);
    font-size: 11px;
}

/* CARD */
.stat-card,
.content-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 15px rgba(30, 50, 80, .025);
}

.stat-card {
    padding: 20px;
    height: 100%;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 600;
}

.stat-value {
    font-size: 27px;
    font-weight: 800;
    margin-top: 9px;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
}

.content-card {
    padding: 22px;
    margin-top: 22px;
}

.card-heading {
    font-size: 16px;
    font-weight: 800;
}

.form-control,
.form-select {
    min-height: 40px;
    border-color: #e3e9f1;
    border-radius: 8px;
    font-size: 13px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(57,121,246,.1);
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

.table {
    vertical-align: middle;
    font-size: 12px;
    margin-bottom: 0;
}

.table thead th {
    background: #f8fafc;
    color: #7e8ba0;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 14px 12px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}

.table tbody td {
    padding: 14px 12px;
    border-color: #edf1f6;
}

.department-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: #edf3ff;
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.department-name {
    font-weight: 700;
    color: #263449;
}

.department-description {
    color: var(--muted);
    font-size: 11px;
    margin-top: 3px;
}

.status-badge {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}

.status-active {
    color: #16834c;
    background: #e5f8ee;
}

.status-inactive {
    color: #b45353;
    background: #fff0f0;
}

.action-btn {
    width: 31px;
    height: 31px;
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 7px;
    color: #64748b;
}

.action-btn:hover {
    background: #f1f5f9;
}

.empty-state {
    text-align: center;
    padding: 45px 15px;
    color: var(--muted);
}

.empty-state i {
    font-size: 35px;
    margin-bottom: 12px;
    color: #b7c4d5;
}

.modal-content {
    border: 0;
    border-radius: 13px;
}

.modal-header,
.modal-footer {
    border-color: var(--border);
    padding: 18px 22px;
}

.modal-body {
    padding: 22px;
}

.form-label {
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
}

.required {
    color: #dc3545;
}

.mobile-toggle {
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

    .main-content {
        margin-left: 0;
        padding: 20px 15px;
    }

    .mobile-toggle {
        display: inline-flex;
    }
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

    <a href="departemen.php" class="active">
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

    <a href="izin.php">
        <i class="fa-solid fa-envelope-open-text"></i> Pengajuan Izin
    </a>

    <a href="laporan.php">
        <i class="fa-solid fa-chart-column"></i> Laporan
    </a>

    <div class="menu-label">Sistem</div>

    <a href="pengaturan.php">
        <i class="fa-solid fa-gear"></i> Pengaturan
    </a>

    <a href="logout.php"
       onclick="return confirm('Apakah Anda yakin ingin logout?')">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>

    <div class="sidebar-footer">
        <div class="fw-bold text-white">Attendly System</div>
        <div>Versi 1.0.0</div>
    </div>

</aside>

<!-- MAIN -->
<main class="main-content">

    <div class="topbar">

        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light mobile-toggle" id="sidebarToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <h4 class="page-title">Departemen</h4>
                <p class="page-subtitle">Kelola struktur departemen perusahaan.</p>
            </div>
        </div>

        <div class="profile">
            <div class="profile-avatar">
                <?= e(strtoupper(substr($currentName, 0, 2))) ?>
            </div>

            <div>
                <div class="profile-name"><?= e($currentName) ?></div>
                <div class="profile-role"><?= e($currentRole) ?></div>
            </div>
        </div>

    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fa-solid fa-circle-check me-2"></i>
            <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATISTIK -->
    <div class="row g-3">

        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Departemen</div>
                        <div class="stat-value"><?= number_format((int)$stats['total']) ?></div>
                    </div>
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-building"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Departemen Aktif</div>
                        <div class="stat-value"><?= number_format((int)$stats['active']) ?></div>
                    </div>
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Departemen Nonaktif</div>
                        <div class="stat-value"><?= number_format((int)$stats['inactive']) ?></div>
                    </div>
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- DAFTAR DEPARTEMEN -->
    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

            <div>
                <div class="card-heading">Daftar Departemen</div>
                <div class="text-muted small mt-1">
                    Daftar departemen yang terdaftar di perusahaan.
                </div>
            </div>

            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fa-solid fa-plus me-1"></i>
                Tambah Departemen
            </button>

        </div>

        <!-- FILTER -->
        <form method="GET" class="row g-2 mb-4">

            <div class="col-lg-7">
                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="fa-solid fa-search text-muted"></i>
                    </span>

                    <input type="text"
                           name="search"
                           class="form-control"
                           placeholder="Cari nama departemen..."
                           value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-lg-3">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>

            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>

                <a href="departemen.php" class="btn btn-light border">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>

        </form>

        <!-- TABEL -->
        <div class="table-responsive">

            <table class="table">

                <thead>
                    <tr>
                        <th>Departemen</th>
                        <th>Deskripsi</th>
                        <th>Jumlah Karyawan</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$departments): ?>

                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-building d-block"></i>
                                <div class="fw-bold">Belum ada departemen</div>
                                <div class="small">Silakan tambahkan departemen perusahaan.</div>
                            </div>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($departments as $department): ?>

                        <?php
                        $departmentJson = htmlspecialchars(
                            json_encode($department, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>

                        <tr>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="department-icon">
                                        <i class="fa-solid fa-building"></i>
                                    </div>

                                    <div>
                                        <div class="department-name">
                                            <?= e($department['name']) ?>
                                        </div>
                                        <div class="text-muted small">
                                            ID: <?= (int)$department['id'] ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <?= e($department['description'] ?: '-') ?>
                            </td>

                            <td>
                                <span class="fw-bold">
                                    <?= number_format((int)$department['employee_count']) ?>
                                </span>
                                <span class="text-muted">Karyawan</span>
                            </td>

                            <td>
                                <?php if ($department['status'] === 'active'): ?>
                                    <span class="status-badge status-active">Aktif</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= !empty($department['created_at'])
                                    ? e(date('d/m/Y', strtotime($department['created_at'])))
                                    : '-' ?>
                            </td>

                            <td>
                                <div class="d-flex justify-content-center gap-1">

                                    <button type="button"
                                            class="action-btn"
                                            title="Edit"
                                            onclick='editDepartment(<?= $departmentJson ?>)'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <?php if ($department['status'] === 'active'): ?>

                                        <form method="POST"
                                              onsubmit="return confirm('Nonaktifkan departemen ini?')">

                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="department_id" value="<?= (int)$department['id'] ?>">

                                            <button type="submit"
                                                    class="action-btn text-danger"
                                                    title="Nonaktifkan">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>

                                    <?php else: ?>

                                        <form method="POST"
                                              onsubmit="return confirm('Aktifkan kembali departemen ini?')">

                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="department_id" value="<?= (int)$department['id'] ?>">

                                            <button type="submit"
                                                    class="action-btn text-success"
                                                    title="Aktifkan">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- PAGINATION -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 gap-3">

            <div class="text-muted small">
                Menampilkan <?= $totalRows ? $offset + 1 : 0 ?>
                sampai <?= min($offset + $limit, $totalRows) ?>
                dari <?= $totalRows ?> departemen
            </div>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <?php
                    $queryParams = $_GET;
                    $queryParams['page'] = max(1, $page - 1);
                    ?>

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= e(http_build_query($queryParams)) ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                    </li>

                    <li class="page-item active">
                        <span class="page-link"><?= $page ?></span>
                    </li>

                    <?php
                    $queryParams['page'] = min($totalPages, $page + 1);
                    ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= e(http_build_query($queryParams)) ?>">
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

<!-- MODAL TAMBAH / EDIT -->
<div class="modal fade" id="departmentModal" tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST" id="departmentForm">

                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="department_id" id="departmentId">

                <div class="modal-header">

                    <div>
                        <h5 class="modal-title fw-bold" id="modalTitle">
                            Tambah Departemen
                        </h5>
                        <div class="text-muted small">
                            Masukkan informasi departemen perusahaan.
                        </div>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">
                        <label class="form-label">
                            Nama Departemen <span class="required">*</span>
                        </label>

                        <input type="text"
                               name="name"
                               id="departmentName"
                               class="form-control"
                               placeholder="Contoh: IT Support"
                               maxlength="100"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Deskripsi</label>

                        <textarea name="description"
                                  id="departmentDescription"
                                  class="form-control"
                                  rows="3"
                                  placeholder="Deskripsi departemen..."></textarea>
                    </div>

                    <div>
                        <label class="form-label">Status</label>

                        <select name="status" id="departmentStatus" class="form-select">
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-light border"
                            data-bs-dismiss="modal">
                        Batal
                    </button>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-1"></i>
                        Simpan Departemen
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const departmentModal = new bootstrap.Modal(
    document.getElementById('departmentModal')
);

const departmentForm = document.getElementById('departmentForm');

function openAddModal() {
    departmentForm.reset();

    document.getElementById('departmentId').value = '';
    document.getElementById('modalTitle').textContent = 'Tambah Departemen';
    document.getElementById('departmentStatus').value = 'active';

    departmentModal.show();
}

function editDepartment(department) {
    document.getElementById('departmentId').value = department.id;
    document.getElementById('departmentName').value = department.name || '';
    document.getElementById('departmentDescription').value = department.description || '';
    document.getElementById('departmentStatus').value = department.status || 'active';

    document.getElementById('modalTitle').textContent = 'Edit Departemen';

    departmentModal.show();
}

document.getElementById('sidebarToggle').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('show');
});

document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');

    if (
        window.innerWidth <= 991 &&
        sidebar.classList.contains('show') &&
        !sidebar.contains(event.target) &&
        !toggle.contains(event.target)
    ) {
        sidebar.classList.remove('show');
    }
});
</script>

</body>
</html>

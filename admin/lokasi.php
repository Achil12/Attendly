
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$csrfToken = attendly_csrf_token();

$error = '';
$success = '';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectLocation($params = []) {
    $query = http_build_query($params);
    header('Location: lokasi.php' . ($query ? '?' . $query : ''));
    exit;
}

/* PROSES FORM */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $latitude = trim($_POST['latitude'] ?? '');
                $longitude = trim($_POST['longitude'] ?? '');
                $radius = max(1, (int) ($_POST['radius'] ?? 100));
                $status = $_POST['status'] ?? 'active';

                if ($name === '' || $address === '') {
                    throw new Exception('Nama lokasi dan alamat wajib diisi.');
                }

                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                if (
                    $latitude !== '' &&
                    (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90)
                ) {
                    throw new Exception('Latitude tidak valid.');
                }

                if (
                    $longitude !== '' &&
                    (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180)
                ) {
                    throw new Exception('Longitude tidak valid.');
                }

                $latitude = $latitude === '' ? null : (float) $latitude;
                $longitude = $longitude === '' ? null : (float) $longitude;

                if ($id > 0) {
                    $check = $pdo->prepare("
                        SELECT id
                        FROM locations
                        WHERE id = ? AND company_id = ?
                    ");
                    $check->execute([$id, $companyId]);

                    if (!$check->fetch()) {
                        throw new Exception('Lokasi tidak ditemukan.');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE locations
                        SET name = ?,
                            address = ?,
                            latitude = ?,
                            longitude = ?,
                            radius = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");

                    $stmt->execute([
                        $name,
                        $address,
                        $latitude,
                        $longitude,
                        $radius,
                        $status,
                        $id,
                        $companyId
                    ]);

                    $message = 'Lokasi berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO locations
                        (
                            company_id,
                            name,
                            address,
                            latitude,
                            longitude,
                            radius,
                            status,
                            created_at,
                            updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");

                    $stmt->execute([
                        $companyId,
                        $name,
                        $address,
                        $latitude,
                        $longitude,
                        $radius,
                        $status
                    ]);

                    $message = 'Lokasi berhasil ditambahkan.';
                }

                redirectLocation(['success' => $message]);
            }

            if ($action === 'toggle') {
                $id = (int) ($_POST['id'] ?? 0);

                $stmt = $pdo->prepare("
                    UPDATE locations
                    SET status = CASE
                        WHEN status = 'active' THEN 'inactive'
                        ELSE 'active'
                    END,
                    updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$id, $companyId]);

                redirectLocation([
                    'success' => 'Status lokasi berhasil diperbarui.'
                ]);
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);

                $stmt = $pdo->prepare("
                    DELETE FROM locations
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$id, $companyId]);

                redirectLocation([
                    'success' => 'Lokasi berhasil dihapus.'
                ]);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

/* STATISTIK */
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active,
        SUM(status = 'inactive') AS inactive
    FROM locations
    WHERE company_id = ?
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* FILTER */
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = ['company_id = ?'];
$params = [$companyId];

if ($search !== '') {
    $where[] = '(name LIKE ? OR address LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

/* TOTAL DATA */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM locations
    WHERE $whereSql
");
$stmt->execute($params);

$totalRows = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $limit));

/* DATA LOKASI */
$stmt = $pdo->prepare("
    SELECT *
    FROM locations
    WHERE $whereSql
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* DATA EDIT */
$editLocation = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM locations
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$editId, $companyId]);

    $editLocation = $stmt->fetch(PDO::FETCH_ASSOC);
}

$user = attendly_user();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lokasi Kantor | Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3979f6;
            --sidebar: #17243a;
            --background: #f5f7fb;
            --text: #263449;
            --muted: #8a96a8;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-size: 14px;
        }

        .sidebar {
            width: 255px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: var(--sidebar);
            padding: 24px 16px;
            color: #fff;
            z-index: 1000;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 21px;
            font-weight: 800;
            margin-bottom: 35px;
            padding: 0 10px;
        }

        .brand-icon {
            width: 39px;
            height: 39px;
            border-radius: 11px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-label {
            color: #8290a5;
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
            color: #aeb9c9;
            text-decoration: none;
            padding: 12px;
            border-radius: 9px;
            margin-bottom: 4px;
            font-weight: 500;
            transition: .2s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            color: #fff;
            background: #293d5b;
        }

        .sidebar a.active {
            background: var(--primary);
        }

        .sidebar a i {
            width: 18px;
            text-align: center;
        }

        .sidebar-footer {
            border-top: 1px solid #34445d;
            margin-top: 35px;
            padding: 18px 10px;
            color: #8290a5;
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e4edff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .stat-card,
        .content-card {
            background: #fff;
            border: 1px solid #edf0f5;
            border-radius: 14px;
            box-shadow: 0 3px 15px rgba(30, 50, 80, .03);
        }

        .stat-card {
            padding: 20px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-number {
            font-size: 27px;
            font-weight: 800;
            margin-top: 8px;
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #edf3ff;
            color: var(--primary);
            font-size: 18px;
        }

        .content-card {
            padding: 22px;
        }

        .form-control,
        .form-select {
            border-color: #e5eaf1;
            padding: 10px 12px;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(57, 121, 246, .1);
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: #2865dc;
            border-color: #2865dc;
        }

        .table th {
            color: #8490a1;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            font-weight: 700;
            background: #fafbfd;
            white-space: nowrap;
            padding: 14px;
        }

        .table td {
            padding: 15px 14px;
            vertical-align: middle;
            border-color: #f0f2f6;
        }

        .badge-active {
            background: #e6f8ee;
            color: #16834b;
        }

        .badge-inactive {
            background: #f1f2f4;
            color: #697386;
        }

        .location-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #edf3ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mobile-toggle {
            display: none;
        }

        @media (max-width: 991px) {
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

            .mobile-toggle {
                display: inline-block;
            }
        }

        @media (max-width: 576px) {
            .main-content {
                padding: 15px;
            }

            .page-title {
                font-size: 20px;
            }

            .profile-info {
                display: none;
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

    <a href="departemen.php">
        <i class="fa-solid fa-building"></i> Departemen
    </a>

    <a href="jabatan.php">
        <i class="fa-solid fa-briefcase"></i> Jabatan
    </a>

    <a href="jadwal.php">
        <i class="fa-solid fa-calendar-days"></i> Jadwal Kerja
    </a>

    <a href="lokasi.php" class="active">
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
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light mobile-toggle" id="mobileToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <h1 class="page-title">Lokasi Kantor</h1>
                <div class="page-subtitle">Kelola titik lokasi dan radius absensi kantor.</div>
            </div>
        </div>

        <div class="profile">
            <div class="avatar">AD</div>
            <div class="profile-info">
                <div class="fw-bold"><?= e($user['name'] ?? 'Administrator') ?></div>
                <small class="text-secondary">Administrator</small>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">

        <div class="col-md-4 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Lokasi</div>
                        <div class="stat-number"><?= (int) ($stats['total'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Lokasi Aktif</div>
                        <div class="stat-number"><?= (int) ($stats['active'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Lokasi Nonaktif</div>
                        <div class="stat-number"><?= (int) ($stats['inactive'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fa-solid fa-circle-pause"></i>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-1">Daftar Lokasi Kantor</h5>
                <small class="text-secondary">Atur lokasi yang diizinkan untuk absensi.</small>
            </div>

            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#locationModal">
                <i class="fa-solid fa-plus me-2"></i>Tambah Lokasi
            </button>
        </div>

        <form method="GET" class="row g-2 mb-4">

            <div class="col-md-5">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama atau alamat lokasi..."
                       value="<?= e($search) ?>">
            </div>

            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>

            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter me-1"></i>Filter
                </button>

                <a href="lokasi.php" class="btn btn-light">Reset</a>
            </div>

        </form>

        <div class="table-responsive">
            <table class="table align-middle">

                <thead>
                    <tr>
                        <th>Lokasi</th>
                        <th>Alamat</th>
                        <th>Koordinat</th>
                        <th>Radius Absensi</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$locations): ?>

                    <tr>
                        <td colspan="6" class="text-center py-5 text-secondary">
                            <i class="fa-solid fa-map-location-dot fs-2 d-block mb-2"></i>
                            Belum ada lokasi kantor.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($locations as $location): ?>

                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="location-icon">
                                        <i class="fa-solid fa-building"></i>
                                    </div>

                                    <div>
                                        <div class="fw-bold"><?= e($location['name']) ?></div>
                                        <small class="text-secondary">ID: #<?= (int) $location['id'] ?></small>
                                    </div>
                                </div>
                            </td>

                            <td style="min-width:220px">
                                <?= e($location['address']) ?>
                            </td>

                            <td>
                                <?php if ($location['latitude'] !== null && $location['longitude'] !== null): ?>
                                    <small>
                                        <?= e($location['latitude']) ?><br>
                                        <?= e($location['longitude']) ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-secondary">Belum diatur</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="fw-semibold"><?= (int) $location['radius'] ?> meter</span>
                            </td>

                            <td>
                                <?php if ($location['status'] === 'active'): ?>
                                    <span class="badge badge-active rounded-pill px-3 py-2">Aktif</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive rounded-pill px-3 py-2">Nonaktif</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm" data-bs-toggle="dropdown">
                                        <i class="fa-solid fa-ellipsis"></i>
                                    </button>

                                    <ul class="dropdown-menu dropdown-menu-end">

                                        <li>
                                            <a class="dropdown-item"
                                               href="lokasi.php?edit=<?= (int) $location['id'] ?>">
                                                <i class="fa-solid fa-pen me-2"></i>Edit
                                            </a>
                                        </li>

                                        <li>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int) $location['id'] ?>">

                                                <button type="submit" class="dropdown-item">
                                                    <i class="fa-solid fa-power-off me-2"></i>
                                                    <?= $location['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                        </li>

                                        <li><hr class="dropdown-divider"></li>

                                        <li>
                                            <form method="POST"
                                                  onsubmit="return confirm('Yakin ingin menghapus lokasi ini?')">

                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $location['id'] ?>">

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

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">

            <small class="text-secondary">
                Menampilkan <?= count($locations) ?> dari <?= $totalRows ?> lokasi
            </small>

            <nav>
                <ul class="pagination pagination-sm mb-0">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="?<?= e(http_build_query([
                               'search' => $search,
                               'status' => $statusFilter,
                               'page' => max(1, $page - 1)
                           ])) ?>">
                            Sebelumnya
                        </a>
                    </li>

                    <li class="page-item active">
                        <span class="page-link"><?= $page ?> / <?= $totalPages ?></span>
                    </li>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="?<?= e(http_build_query([
                               'search' => $search,
                               'status' => $statusFilter,
                               'page' => min($totalPages, $page + 1)
                           ])) ?>">
                            Selanjutnya
                        </a>
                    </li>

                </ul>
            </nav>

        </div>

    </div>

    <div class="text-center text-secondary mt-4 small">
        © 2026 Attendly. All Rights Reserved.
    </div>

</main>

<!-- MODAL TAMBAH / EDIT LOKASI -->
<div class="modal fade" id="locationModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">

            <form method="POST">

                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($editLocation['id'] ?? 0) ?>">

                <div class="modal-header border-0 px-4 pt-4">
                    <div>
                        <h5 class="modal-title fw-bold">
                            <?= $editLocation ? 'Edit Lokasi Kantor' : 'Tambah Lokasi Kantor' ?>
                        </h5>
                        <small class="text-secondary">
                            Masukkan informasi lokasi kantor.
                        </small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Lokasi *</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="Contoh: Kantor Pusat"
                               value="<?= e($editLocation['name'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Alamat Lengkap *</label>
                        <textarea name="address" class="form-control" rows="3"
                                  placeholder="Masukkan alamat lengkap kantor..."
                                  required><?= e($editLocation['address'] ?? '') ?></textarea>
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Latitude</label>
                            <input type="number" step="any" name="latitude"
                                   id="latitude" class="form-control"
                                   placeholder="-6.200000"
                                   value="<?= e($editLocation['latitude'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Longitude</label>
                            <input type="number" step="any" name="longitude"
                                   id="longitude" class="form-control"
                                   placeholder="106.816666"
                                   value="<?= e($editLocation['longitude'] ?? '') ?>">
                        </div>

                    </div>

                    <div class="mt-3 mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="getLocation">
                            <i class="fa-solid fa-location-crosshairs me-2"></i>
                            Gunakan Lokasi Saya
                        </button>
                        <small class="text-secondary d-block mt-2" id="locationMessage">
                            Gunakan GPS perangkat untuk mengisi koordinat secara otomatis.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Radius Absensi (Meter) *</label>
                        <input type="number" name="radius" class="form-control"
                               min="1" max="10000"
                               value="<?= e($editLocation['radius'] ?? 100) ?>" required>
                        <small class="text-secondary">
                            Karyawan harus berada dalam radius ini untuk melakukan absensi berbasis lokasi.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($editLocation['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                                Aktif
                            </option>
                            <option value="inactive" <?= ($editLocation['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                                Nonaktif
                            </option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer border-0 px-4 pb-4">
                    <a href="lokasi.php" class="btn btn-light">Batal</a>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Simpan Lokasi
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('mobileToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});

document.getElementById('getLocation')?.addEventListener('click', function () {
    const message = document.getElementById('locationMessage');

    if (!navigator.geolocation) {
        message.textContent = 'Browser tidak mendukung fitur GPS.';
        return;
    }

    message.textContent = 'Sedang mengambil koordinat lokasi...';

    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('latitude').value =
                position.coords.latitude.toFixed(8);

            document.getElementById('longitude').value =
                position.coords.longitude.toFixed(8);

            message.textContent = 'Koordinat berhasil diambil.';
        },
        function (error) {
            const messages = {
                1: 'Izin lokasi ditolak. Aktifkan izin lokasi pada browser.',
                2: 'Lokasi tidak tersedia.',
                3: 'Waktu pengambilan lokasi habis.'
            };

            message.textContent = messages[error.code] || 'Gagal mengambil lokasi.';
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
});

<?php if ($editLocation): ?>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('locationModal')).show();
});
<?php endif; ?>
</script>

</body>
</html>


<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$csrfToken = attendly_csrf_token();

$error = '';
$success = '';

$days = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu'
];

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectSchedule($params = []) {
    $query = http_build_query($params);
    header('Location: jadwal.php' . ($query ? '?' . $query : ''));
    exit;
}

/* SIMPAN JADWAL */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save') {
                $id = (int) ($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $startTime = $_POST['start_time'] ?? '';
                $endTime = $_POST['end_time'] ?? '';
                $breakStart = !empty($_POST['break_start']) ? $_POST['break_start'] : null;
                $breakEnd = !empty($_POST['break_end']) ? $_POST['break_end'] : null;
                $gracePeriod = max(0, (int) ($_POST['grace_period'] ?? 0));
                $status = $_POST['status'] ?? 'active';
                $selectedDays = $_POST['days'] ?? [];

                if ($name === '' || $startTime === '' || $endTime === '') {
                    throw new Exception('Nama jadwal, jam masuk, dan jam pulang wajib diisi.');
                }

                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }

                $selectedDays = array_values(array_unique(array_filter(
                    array_map('intval', $selectedDays),
                    fn($day) => $day >= 1 && $day <= 7
                )));

                if (!$selectedDays) {
                    throw new Exception('Pilih minimal satu hari kerja.');
                }

                $pdo->beginTransaction();

                if ($id > 0) {
                    $check = $pdo->prepare("
                        SELECT id
                        FROM work_schedules
                        WHERE id = ? AND company_id = ?
                    ");
                    $check->execute([$id, $companyId]);

                    if (!$check->fetch()) {
                        throw new Exception('Jadwal tidak ditemukan.');
                    }

                    $stmt = $pdo->prepare("
                        UPDATE work_schedules
                        SET name = ?,
                            start_time = ?,
                            end_time = ?,
                            break_start = ?,
                            break_end = ?,
                            grace_period = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ? AND company_id = ?
                    ");

                    $stmt->execute([
                        $name,
                        $startTime,
                        $endTime,
                        $breakStart,
                        $breakEnd,
                        $gracePeriod,
                        $status,
                        $id,
                        $companyId
                    ]);

                    $scheduleId = $id;

                    // schedule_days tidak memiliki company_id
                    $deleteDays = $pdo->prepare("
                        DELETE FROM schedule_days
                        WHERE schedule_id = ?
                    ");
                    $deleteDays->execute([$scheduleId]);

                    $successMessage = 'Jadwal kerja berhasil diperbarui.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO work_schedules
                        (
                            company_id,
                            name,
                            start_time,
                            end_time,
                            break_start,
                            break_end,
                            grace_period,
                            status,
                            created_at,
                            updated_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");

                    $stmt->execute([
                        $companyId,
                        $name,
                        $startTime,
                        $endTime,
                        $breakStart,
                        $breakEnd,
                        $gracePeriod,
                        $status
                    ]);

                    $scheduleId = (int) $pdo->lastInsertId();
                    $successMessage = 'Jadwal kerja berhasil ditambahkan.';
                }

                // schedule_days hanya menggunakan schedule_id dan day_of_week
                $insertDay = $pdo->prepare("
                    INSERT INTO schedule_days (schedule_id, day_of_week)
                    VALUES (?, ?)
                ");

                foreach ($selectedDays as $day) {
                    $insertDay->execute([$scheduleId, $day]);
                }

                $pdo->commit();

                redirectSchedule(['success' => $successMessage]);
            }

            if ($action === 'toggle') {
                $id = (int) ($_POST['id'] ?? 0);

                $stmt = $pdo->prepare("
                    UPDATE work_schedules
                    SET status = CASE
                        WHEN status = 'active' THEN 'inactive'
                        ELSE 'active'
                    END,
                    updated_at = NOW()
                    WHERE id = ? AND company_id = ?
                ");
                $stmt->execute([$id, $companyId]);

                redirectSchedule([
                    'success' => 'Status jadwal berhasil diperbarui.'
                ]);
            }

            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);

                $check = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM employee_schedules
                    WHERE schedule_id = ? AND company_id = ?
                ");
                $check->execute([$id, $companyId]);

                if ((int) $check->fetchColumn() > 0) {
                    throw new Exception(
                        'Jadwal masih digunakan oleh karyawan. Nonaktifkan jadwal jika tidak ingin digunakan lagi.'
                    );
                }

                $pdo->beginTransaction();

                $deleteDays = $pdo->prepare("
                    DELETE FROM schedule_days
                    WHERE schedule_id = ?
                ");
                $deleteDays->execute([$id]);

                $deleteSchedule = $pdo->prepare("
                    DELETE FROM work_schedules
                    WHERE id = ? AND company_id = ?
                ");
                $deleteSchedule->execute([$id, $companyId]);

                $pdo->commit();

                redirectSchedule([
                    'success' => 'Jadwal berhasil dihapus.'
                ]);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

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
    FROM work_schedules
    WHERE company_id = ?
");
$stmt->execute([$companyId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT employee_id)
    FROM employee_schedules
    WHERE company_id = ?
");
$stmt->execute([$companyId]);
$assignedEmployees = (int) $stmt->fetchColumn();

/* FILTER DAN PAGINATION */
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

$where = ['ws.company_id = ?'];
$params = [$companyId];

if ($search !== '') {
    $where[] = 'ws.name LIKE ?';
    $params[] = '%' . $search . '%';
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $where[] = 'ws.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM work_schedules ws
    WHERE $whereSql
");
$stmt->execute($params);

$totalRows = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $limit));

/* DAFTAR JADWAL */
$stmt = $pdo->prepare("
    SELECT
        ws.*,
        (
            SELECT GROUP_CONCAT(sd.day_of_week ORDER BY sd.day_of_week)
            FROM schedule_days sd
            WHERE sd.schedule_id = ws.id
        ) AS work_days,
        (
            SELECT COUNT(DISTINCT es.employee_id)
            FROM employee_schedules es
            WHERE es.schedule_id = ws.id
              AND es.company_id = ws.company_id
        ) AS employee_count
    FROM work_schedules ws
    WHERE $whereSql
    ORDER BY ws.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* DATA EDIT */
$editSchedule = null;
$editDays = [];

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    $stmt = $pdo->prepare("
        SELECT *
        FROM work_schedules
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$editId, $companyId]);
    $editSchedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($editSchedule) {
        $stmt = $pdo->prepare("
            SELECT day_of_week
            FROM schedule_days
            WHERE schedule_id = ?
        ");
        $stmt->execute([$editId]);

        $editDays = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }
}

$user = attendly_user();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Kerja | Attendly</title>

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

        .day-badge {
            display: inline-block;
            padding: 5px 8px;
            background: #edf3ff;
            color: #3979f6;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            margin: 2px;
        }

        .day-option {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 9px 11px;
            border: 1px solid #e7ebf1;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
        }

        .day-option:has(input:checked) {
            border-color: var(--primary);
            background: #f0f5ff;
            color: var(--primary);
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

    <a href="jadwal.php" class="active">
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
                <h1 class="page-title">Jadwal Kerja</h1>
                <div class="page-subtitle">Atur jam kerja dan hari operasional karyawan.</div>
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

        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Total Jadwal</div>
                        <div class="stat-number"><?= (int) ($stats['total'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-calendar-days"></i></div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Jadwal Aktif</div>
                        <div class="stat-number"><?= (int) ($stats['active'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Jadwal Nonaktif</div>
                        <div class="stat-number"><?= (int) ($stats['inactive'] ?? 0) ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-circle-pause"></i></div>
                </div>
            </div>
        </div>

        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-label">Karyawan Terjadwal</div>
                        <div class="stat-number"><?= $assignedEmployees ?></div>
                    </div>
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                </div>
            </div>
        </div>

    </div>

    <div class="content-card">

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h5 class="fw-bold mb-1">Daftar Jadwal Kerja</h5>
                <small class="text-secondary">Kelola jadwal kerja perusahaan.</small>
            </div>

            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal">
                <i class="fa-solid fa-plus me-2"></i>Tambah Jadwal
            </button>
        </div>

        <form method="GET" class="row g-2 mb-4">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control"
                       placeholder="Cari nama jadwal..."
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
                <button class="btn btn-primary">
                    <i class="fa-solid fa-filter me-1"></i>Filter
                </button>
                <a href="jadwal.php" class="btn btn-light">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Nama Jadwal</th>
                        <th>Jam Kerja</th>
                        <th>Istirahat</th>
                        <th>Hari Kerja</th>
                        <th>Toleransi</th>
                        <th>Karyawan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$schedules): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-secondary">
                            <i class="fa-regular fa-calendar-xmark fs-2 d-block mb-2"></i>
                            Belum ada jadwal kerja.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= e($schedule['name']) ?></div>
                                <small class="text-secondary">ID: #<?= (int) $schedule['id'] ?></small>
                            </td>

                            <td>
                                <div class="fw-semibold">
                                    <?= e(substr($schedule['start_time'], 0, 5)) ?>
                                    -
                                    <?= e(substr($schedule['end_time'], 0, 5)) ?>
                                </div>
                            </td>

                            <td>
                                <?php if ($schedule['break_start'] && $schedule['break_end']): ?>
                                    <?= e(substr($schedule['break_start'], 0, 5)) ?>
                                    -
                                    <?= e(substr($schedule['break_end'], 0, 5)) ?>
                                <?php else: ?>
                                    <span class="text-secondary">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php
                                $scheduleDays = $schedule['work_days']
                                    ? array_map('intval', explode(',', $schedule['work_days']))
                                    : [];

                                foreach ($scheduleDays as $day):
                                    if (isset($days[$day])):
                                ?>
                                    <span class="day-badge"><?= e(substr($days[$day], 0, 3)) ?></span>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </td>

                            <td><?= (int) $schedule['grace_period'] ?> menit</td>

                            <td>
                                <span class="fw-semibold"><?= (int) $schedule['employee_count'] ?></span>
                                <small class="text-secondary">orang</small>
                            </td>

                            <td>
                                <?php if ($schedule['status'] === 'active'): ?>
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
                                               href="jadwal.php?edit=<?= (int) $schedule['id'] ?>">
                                                <i class="fa-solid fa-pen me-2"></i>Edit
                                            </a>
                                        </li>

                                        <li>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">

                                                <button class="dropdown-item" type="submit">
                                                    <i class="fa-solid fa-power-off me-2"></i>
                                                    <?= $schedule['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                        </li>

                                        <li><hr class="dropdown-divider"></li>

                                        <li>
                                            <form method="POST"
                                                  onsubmit="return confirm('Yakin ingin menghapus jadwal ini?')">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $schedule['id'] ?>">

                                                <button class="dropdown-item text-danger" type="submit">
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
                Menampilkan <?= count($schedules) ?> dari <?= $totalRows ?> jadwal
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

<!-- MODAL TAMBAH / EDIT JADWAL -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($editSchedule['id'] ?? 0) ?>">

                <div class="modal-header border-0 px-4 pt-4">
                    <div>
                        <h5 class="modal-title fw-bold">
                            <?= $editSchedule ? 'Edit Jadwal Kerja' : 'Tambah Jadwal Kerja' ?>
                        </h5>
                        <small class="text-secondary">Lengkapi informasi jadwal kerja.</small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body px-4">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Jadwal *</label>
                        <input type="text" name="name" class="form-control"
                               placeholder="Contoh: Shift Pagi"
                               value="<?= e($editSchedule['name'] ?? '') ?>" required>
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Jam Masuk *</label>
                            <input type="time" name="start_time" class="form-control"
                                   value="<?= e(isset($editSchedule['start_time']) ? substr($editSchedule['start_time'], 0, 5) : '08:00') ?>"
                                   required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Jam Pulang *</label>
                            <input type="time" name="end_time" class="form-control"
                                   value="<?= e(isset($editSchedule['end_time']) ? substr($editSchedule['end_time'], 0, 5) : '17:00') ?>"
                                   required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mulai Istirahat</label>
                            <input type="time" name="break_start" class="form-control"
                                   value="<?= e(isset($editSchedule['break_start']) ? substr($editSchedule['break_start'], 0, 5) : '12:00') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Selesai Istirahat</label>
                            <input type="time" name="break_end" class="form-control"
                                   value="<?= e(isset($editSchedule['break_end']) ? substr($editSchedule['break_end'], 0, 5) : '13:00') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Toleransi Keterlambatan (Menit)</label>
                            <input type="number" name="grace_period" class="form-control"
                                   min="0" max="180"
                                   value="<?= e($editSchedule['grace_period'] ?? 15) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= ($editSchedule['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option>
                                <option value="inactive" <?= ($editSchedule['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                            </select>
                        </div>

                    </div>

                    <div class="mt-4">
                        <label class="form-label fw-semibold">Hari Kerja *</label>

                        <div class="row g-2">
                            <?php foreach ($days as $number => $day): ?>
                                <div class="col-6 col-md-3">
                                    <label class="day-option">
                                        <input type="checkbox"
                                               name="days[]"
                                               value="<?= $number ?>"
                                               <?= in_array($number, $editDays, true) ? 'checked' : (!$editSchedule && $number <= 5 ? 'checked' : '') ?>>
                                        <?= e($day) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>

                <div class="modal-footer border-0 px-4 pb-4">
                    <a href="jadwal.php" class="btn btn-light">Batal</a>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Simpan Jadwal
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

<?php if ($editSchedule): ?>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('scheduleModal')).show();
});
<?php endif; ?>
</script>

</body>
</html>


<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$user = attendly_user();

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function report_date($date) {
    return date('d/m/Y', strtotime($date));
}

$today = date('Y-m-d');
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? $today;
$departmentId = (int)($_GET['department_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$errors = [];
$report = [];
$summary = [
    'total' => 0,
    'hadir' => 0,
    'terlambat' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

$start = DateTime::createFromFormat('Y-m-d', $dateFrom);
$end = DateTime::createFromFormat('Y-m-d', $dateTo);

if (!$start || $start->format('Y-m-d') !== $dateFrom ||
    !$end || $end->format('Y-m-d') !== $dateTo) {
    $errors[] = 'Format tanggal tidak valid.';
} elseif ($dateFrom > $dateTo) {
    $errors[] = 'Tanggal mulai tidak boleh melebihi tanggal akhir.';
}

$stmt = $pdo->prepare("
    SELECT id, name
    FROM departments
    WHERE company_id = ?
    ORDER BY name
");
$stmt->execute([$companyId]);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$where = [
    'a.company_id = ?',
    'a.attendance_date BETWEEN ? AND ?'
];
$params = [$companyId, $dateFrom, $dateTo];

if ($departmentId > 0) {
    $where[] = 'e.department_id = ?';
    $params[] = $departmentId;
}

if (in_array($statusFilter, ['hadir', 'terlambat', 'izin', 'sakit', 'alpha'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

if (!$errors) {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(a.status = 'hadir') AS hadir,
            SUM(a.status = 'terlambat') AS terlambat,
            SUM(a.status = 'izin') AS izin,
            SUM(a.status = 'sakit') AS sakit,
            SUM(a.status = 'alpha') AS alpha
        FROM attendances a
        INNER JOIN employees e
            ON e.id = a.employee_id
           AND e.company_id = a.company_id
        WHERE $whereSql
    ");
    $stmt->execute($params);
    $summary = array_merge($summary, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);

    $stmt = $pdo->prepare("
    SELECT
        e.id AS employee_id,
        e.nik,
        e.name AS employee_name,
        d.name AS department_name,

        COUNT(a.id) AS total_attendance,

        SUM(a.status = 'hadir') AS hadir,
        SUM(a.status = 'terlambat') AS terlambat,
        SUM(a.status = 'izin') AS izin,
        SUM(a.status = 'sakit') AS sakit,
        SUM(a.status = 'alpha') AS alpha,

        SUM(a.clock_in_at IS NOT NULL) AS total_check_in,

        AVG(
            CASE
                WHEN a.clock_in_at IS NOT NULL
                THEN TIME_TO_SEC(a.clock_in_at)
                ELSE NULL
            END
        ) AS average_check_in

    FROM attendances a

    INNER JOIN employees e
        ON e.id = a.employee_id
       AND e.company_id = a.company_id

    LEFT JOIN departments d
        ON d.id = e.department_id
       AND d.company_id = a.company_id

    WHERE $whereSql

    GROUP BY
        e.id,
        e.nik,
        e.name,
        d.name

    ORDER BY e.name ASC
");

$stmt->execute($params);
$report = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function report_percent($value, $total) {
    if ((int)$total <= 0) {
        return 0;
    }

    return round(((int)$value / (int)$total) * 100, 1);
}

function report_time($seconds) {
    if ($seconds === null || $seconds === false) {
        return '-';
    }

    return gmdate('H:i', (int)$seconds);
}

$totalEmployees = count($report);
$totalRecords = (int)($summary['total'] ?? 0);
$presentTotal = (int)($summary['hadir'] ?? 0) + (int)($summary['terlambat'] ?? 0);
$attendanceRate = report_percent($presentTotal, $totalRecords);

?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Absensi | Attendly</title>

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

.progress {
    height: 7px;
    border-radius: 20px;
    background: #edf0f5;
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

@media print {
    .sidebar,
    .topbar button,
    .profile,
    .filter-section,
    .no-print {
        display: none !important;
    }

    .main-content {
        margin: 0;
        padding: 0;
    }

    body { background: #fff; }

    .stat-card,
    .content-card {
        border: 1px solid #ddd;
        break-inside: avoid;
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

    <a href="laporan.php" class="active">
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
            <h1 class="page-title">Laporan Absensi</h1>
            <div class="page-subtitle">Ringkasan dan rekapitulasi kehadiran karyawan.</div>
        </div>

        <div class="profile">
            <div class="avatar">AD</div>
            <div>
                <div class="fw-bold"><?= e($user['name'] ?? 'Administrator') ?></div>
                <small class="text-muted">Administrator</small>
            </div>
        </div>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="content-card filter-section mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label class="form-label fw-semibold">Tanggal Mulai</label>
                <input type="date" name="date_from" class="form-control"
                       value="<?= e($dateFrom) ?>" required>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label fw-semibold">Tanggal Akhir</label>
                <input type="date" name="date_to" class="form-control"
                       value="<?= e($dateTo) ?>" required>
            </div>

            <div class="col-lg-3 col-md-6">
                <label class="form-label fw-semibold">Departemen</label>
                <select name="department_id" class="form-select">
                    <option value="0">Semua Departemen</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int)$department['id'] ?>"
                            <?= $departmentId === (int)$department['id'] ? 'selected' : '' ?>>
                            <?= e($department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2 col-md-6">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="hadir" <?= $statusFilter === 'hadir' ? 'selected' : '' ?>>Hadir</option>
                    <option value="terlambat" <?= $statusFilter === 'terlambat' ? 'selected' : '' ?>>Terlambat</option>
                    <option value="izin" <?= $statusFilter === 'izin' ? 'selected' : '' ?>>Izin</option>
                    <option value="sakit" <?= $statusFilter === 'sakit' ? 'selected' : '' ?>>Sakit</option>
                    <option value="alpha" <?= $statusFilter === 'alpha' ? 'selected' : '' ?>>Alpha</option>
                </select>
            </div>

            <div class="col-lg-1 col-md-12 d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-filter"></i>
                </button>
            </div>
        </form>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h5 class="fw-bold mb-1">Periode Laporan</h5>
            <div class="text-muted">
                <?= e(report_date($dateFrom)) ?> - <?= e(report_date($dateTo)) ?>
            </div>
        </div>

        <div class="d-flex gap-2 no-print">
            <button class="btn btn-outline-success" id="exportCsv">
                <i class="fa-solid fa-file-csv me-1"></i> Export CSV
            </button>
            <button class="btn btn-outline-primary" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i> Cetak
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary-subtle text-primary">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?= number_format($totalRecords) ?></div>
                <div class="stat-label">Total Catatan Absensi</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon bg-success-subtle text-success">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <div class="stat-value"><?= number_format($presentTotal) ?></div>
                <div class="stat-label">Hadir + Terlambat</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning-subtle text-warning">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-value"><?= number_format((int)$summary['terlambat']) ?></div>
                <div class="stat-label">Terlambat</div>
            </div>
        </div>

        <div class="col-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon bg-danger-subtle text-danger">
                    <i class="fa-solid fa-user-xmark"></i>
                </div>
                <div class="stat-value"><?= number_format((int)$summary['alpha']) ?></div>
                <div class="stat-label">Alpha</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="content-card">
                <div class="card-heading mb-3">Distribusi Kehadiran</div>

                <?php
                $distribution = [
                    ['Hadir', (int)$summary['hadir'], 'success'],
                    ['Terlambat', (int)$summary['terlambat'], 'warning'],
                    ['Izin', (int)$summary['izin'], 'info'],
                    ['Sakit', (int)$summary['sakit'], 'primary'],
                    ['Alpha', (int)$summary['alpha'], 'danger']
                ];
                ?>

                <?php foreach ($distribution as [$label, $value, $color]): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="fw-semibold"><?= e($label) ?></span>
                            <span class="text-muted">
                                <?= number_format($value) ?> (<?= report_percent($value, $totalRecords) ?>%)
                            </span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-<?= e($color) ?>"
                                 style="width:<?= report_percent($value, $totalRecords) ?>%">
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="content-card">
                <div class="card-heading mb-3">Ringkasan Periode</div>

                <div class="d-flex justify-content-between py-3 border-bottom">
                    <span class="text-muted">Jumlah karyawan tercatat</span>
                    <strong><?= number_format($totalEmployees) ?></strong>
                </div>

                <div class="d-flex justify-content-between py-3 border-bottom">
                    <span class="text-muted">Total kehadiran</span>
                    <strong><?= number_format($presentTotal) ?></strong>
                </div>

                <div class="d-flex justify-content-between py-3 border-bottom">
                    <span class="text-muted">Izin dan sakit</span>
                    <strong><?= number_format((int)$summary['izin'] + (int)$summary['sakit']) ?></strong>
                </div>

                <div class="d-flex justify-content-between py-3">
                    <span class="text-muted">Persentase hadir dari catatan</span>
                    <strong class="text-primary"><?= $attendanceRate ?>%</strong>
                </div>

                <small class="text-muted">
                    Persentase dihitung dari jumlah catatan hadir dan terlambat dibandingkan seluruh catatan absensi pada periode terpilih.
                </small>
            </div>
        </div>
    </div>

    <div class="content-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="card-heading">Rekapitulasi Per Karyawan</div>
                <small class="text-muted">Ringkasan absensi setiap karyawan pada periode terpilih.</small>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table mb-0" id="reportTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Karyawan</th>
                        <th>Departemen</th>
                        <th>Total</th>
                        <th>Hadir</th>
                        <th>Terlambat</th>
                        <th>Izin</th>
                        <th>Sakit</th>
                        <th>Alpha</th>
                        <th>Rata-rata Masuk</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$report): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5">
                            <i class="fa-solid fa-chart-simple fs-2 text-muted mb-3"></i>
                            <div class="fw-semibold">Tidak ada data laporan</div>
                            <small class="text-muted">Ubah periode atau filter laporan.</small>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report as $index => $row): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td>
                                <div class="fw-bold"><?= e($row['employee_name']) ?></div>
                                <small class="text-muted"><?= e($row['nik']) ?></small>
                            </td>
                            <td><?= e($row['department_name'] ?? '-') ?></td>
                            <td><?= number_format((int)$row['total_attendance']) ?></td>
                            <td><span class="text-success fw-semibold"><?= number_format((int)$row['hadir']) ?></span></td>
                            <td><span class="text-warning fw-semibold"><?= number_format((int)$row['terlambat']) ?></span></td>
                            <td><?= number_format((int)$row['izin']) ?></td>
                            <td><?= number_format((int)$row['sakit']) ?></td>
                            <td><span class="text-danger fw-semibold"><?= number_format((int)$row['alpha']) ?></span></td>
                            <td><?= e(report_time($row['average_check_in'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('menuToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('show');
});

document.getElementById('exportCsv')?.addEventListener('click', function () {
    const table = document.getElementById('reportTable');
    const rows = Array.from(table.querySelectorAll('tr'));

    const csv = rows.map(row => {
        return Array.from(row.querySelectorAll('th, td')).map(cell => {
            let value = cell.innerText.replace(/\s+/g, ' ').trim();
            return '"' + value.replace(/"/g, '""') + '"';
        }).join(',');
    }).join('\r\n');

    const blob = new Blob(['\uFEFF' + csv], {
        type: 'text/csv;charset=utf-8;'
    });

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = 'laporan-absensi-<?= e($dateFrom) ?>-<?= e($dateTo) ?>.csv';

    document.body.appendChild(link);
    link.click();
    link.remove();

    URL.revokeObjectURL(url);
});
</script>

</body>
</html>

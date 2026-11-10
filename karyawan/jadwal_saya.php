<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = attendly_company_id();

if (!$companyId || empty($user['email'])) {
    http_response_code(403);
    exit('Akses ditolak.');
}

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$days = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu'
];

$employeeStmt = $pdo->prepare("
    SELECT id, name, nik, email
    FROM employees
    WHERE company_id = ?
      AND email = ?
    LIMIT 1
");
$employeeStmt->execute([$companyId, $user['email']]);
$employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    http_response_code(404);
    exit('Data karyawan tidak ditemukan.');
}

$employeeId = (int)$employee['id'];

$scheduleStmt = $pdo->prepare("
    SELECT
        ws.id,
        ws.name,
        ws.start_time,
        ws.end_time,
        ws.break_start,
        ws.break_end,
        ws.grace_period,
        ws.status
    FROM employee_schedules es
    INNER JOIN work_schedules ws
        ON ws.id = es.schedule_id
       AND ws.company_id = ?
    WHERE es.employee_id = ?
    ORDER BY ws.id DESC
    LIMIT 1
");
$scheduleStmt->execute([$companyId, $employeeId]);
$schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

$weeklySchedule = [];

if ($schedule) {
    $dayStmt = $pdo->prepare("
        SELECT day_of_week
        FROM schedule_days
        WHERE schedule_id = ?
        ORDER BY day_of_week ASC
    ");
    $dayStmt->execute([$schedule['id']]);
    $weeklySchedule = $dayStmt->fetchAll(PDO::FETCH_COLUMN);
}

$todayNumber = (int)date('N');
$todayName = $days[$todayNumber];

$workingToday = false;

foreach ($weeklySchedule as $day) {
    if ((int)$day === $todayNumber || strtolower((string)$day) === strtolower($todayName)) {
        $workingToday = true;
        break;
    }
}

$formatTime = function ($time) {
    if (!$time) {
        return '-';
    }

    return date('H:i', strtotime($time));
};

$workingDaysText = [];

foreach ($weeklySchedule as $day) {
    $dayNumber = (int)$day;

    if (isset($days[$dayNumber])) {
        $workingDaysText[] = $days[$dayNumber];
    } else {
        $workingDaysText[] = ucfirst((string)$day);
    }
}

$workingDaysText = array_unique($workingDaysText);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jadwal Saya | Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3979f6;
            --sidebar: #17243a;
            --background: #f5f7fb;
            --text: #253047;
            --muted: #8993a5;
            --border: #edf0f5;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
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
            color: #fff;
            padding: 24px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 0 10px 30px;
            font-size: 21px;
            font-weight: 800;
            color: #fff;
            text-decoration: none;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            background: var(--primary);
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .menu-label {
            color: #8290a5;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            padding: 10px 12px;
            text-transform: uppercase;
        }

        .sidebar .nav-link {
            color: #aab5c5;
            padding: 12px 14px;
            border-radius: 9px;
            margin-bottom: 5px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: .2s;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #fff;
            background: #293d5c;
        }

        .sidebar .nav-link.active {
            border-left: 3px solid #5790ff;
        }

        .sidebar .nav-link i {
            width: 18px;
            text-align: center;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid #344158;
            padding: 18px 10px 0;
            color: #8290a5;
            font-size: 11px;
        }

        .main-content {
            margin-left: 255px;
            padding: 25px 30px;
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

        .page-subtitle {
            color: var(--muted);
            font-size: 12px;
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
            background: #e5edff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .profile-name {
            font-weight: 700;
            font-size: 12px;
        }

        .profile-role {
            color: var(--muted);
            font-size: 11px;
        }

        .card-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .section-subtitle {
            color: var(--muted);
            font-size: 12px;
        }

        .schedule-card {
            background: linear-gradient(120deg, #3979f6, #245bd0);
            color: #fff;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 22px;
        }

        .schedule-card .schedule-name {
            font-size: 20px;
            font-weight: 800;
            margin: 12px 0;
        }

        .schedule-time {
            font-size: 29px;
            font-weight: 800;
            letter-spacing: -.8px;
        }

        .schedule-time small {
            font-size: 13px;
            font-weight: 500;
            opacity: .8;
        }

        .schedule-info {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            margin-top: 22px;
        }

        .schedule-info small {
            display: block;
            opacity: .75;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .schedule-info strong {
            font-size: 13px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-working {
            background: #dcfce7;
            color: #15803d;
        }

        .status-off {
            background: #f1f5f9;
            color: #64748b;
        }

        .day-card {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 17px 10px;
            text-align: center;
            height: 100%;
            transition: .2s;
        }

        .day-card.active {
            border-color: var(--primary);
            background: #eff5ff;
        }

        .day-name {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .day-icon {
            font-size: 20px;
            color: #c1cad8;
            margin-bottom: 8px;
        }

        .day-card.active .day-icon {
            color: var(--primary);
        }

        .day-status {
            font-size: 10px;
            font-weight: 700;
            color: #8993a5;
        }

        .day-card.active .day-status {
            color: var(--primary);
        }

        .empty-state {
            text-align: center;
            padding: 55px 20px;
        }

        .empty-icon {
            width: 65px;
            height: 65px;
            border-radius: 18px;
            background: #eff5ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            margin: 0 auto 17px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 13px 0;
            border-bottom: 1px solid var(--border);
            font-size: 12px;
        }

        .info-row:last-child {
            border-bottom: 0;
        }

        .info-label {
            color: var(--muted);
        }

        .info-value {
            font-weight: 700;
            text-align: right;
        }

        .mobile-toggle {
            display: none;
            border: 0;
            background: #fff;
            width: 40px;
            height: 40px;
            border-radius: 9px;
        }

        @media(max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
                transition: .3s;
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
                align-items: center;
                justify-content: center;
                margin-right: 10px;
            }

            .topbar {
                align-items: flex-start;
            }

            .profile-info {
                display: none;
            }
        }

        @media(max-width: 576px) {
            .page-title {
                font-size: 19px;
            }

            .schedule-time {
                font-size: 23px;
            }

            .schedule-info {
                gap: 17px;
            }
        }
    </style>
</head>

<body>

<!--navbar-->
<?php include "add/sidebar.php"; ?>

<main class="main-content">

    <div class="topbar">
        <div class="d-flex align-items-start">
            <button class="mobile-toggle" id="mobileToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div>
                <div class="page-title">Jadwal Kerja Saya</div>
                <div class="page-subtitle">
                    Informasi jadwal dan jam kerja Anda.
                </div>
            </div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($employee['name'], 0, 1))) ?>
            </div>

            <div class="profile-info">
                <div class="profile-name"><?= e($employee['name']) ?></div>
                <div class="profile-role">Karyawan</div>
            </div>
        </div>
    </div>

    <?php if (!$schedule): ?>

        <div class="card-box">
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa-solid fa-calendar-xmark"></i>
                </div>

                <h5 class="fw-bold">Belum Ada Jadwal Kerja</h5>

                <p class="text-muted small mb-0">
                    Saat ini belum ada jadwal kerja yang ditetapkan untuk akun Anda.
                    Silakan hubungi administrator perusahaan.
                </p>
            </div>
        </div>

    <?php else: ?>

        <div class="schedule-card">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="small opacity-75">
                        <i class="fa-solid fa-calendar-check me-1"></i>
                        Jadwal Kerja Aktif
                    </div>

                    <div class="schedule-name"><?= e($schedule['name']) ?></div>

                    <div class="schedule-time">
                        <?= e($formatTime($schedule['start_time'])) ?>
                        <small>sampai</small>
                        <?= e($formatTime($schedule['end_time'])) ?>
                    </div>
                </div>

                <span class="badge bg-light text-primary rounded-pill px-3 py-2">
                    <?= e(ucfirst($schedule['status'])) ?>
                </span>
            </div>

            <div class="schedule-info">
                <div>
                    <small>Jam Masuk</small>
                    <strong>
                        <i class="fa-solid fa-right-to-bracket me-1"></i>
                        <?= e($formatTime($schedule['start_time'])) ?>
                    </strong>
                </div>

                <div>
                    <small>Jam Pulang</small>
                    <strong>
                        <i class="fa-solid fa-right-from-bracket me-1"></i>
                        <?= e($formatTime($schedule['end_time'])) ?>
                    </strong>
                </div>

                <div>
                    <small>Toleransi Keterlambatan</small>
                    <strong>
                        <?= (int)($schedule['grace_period'] ?? 0) ?> menit
                    </strong>
                </div>

                <div>
                    <small>Istirahat</small>
                    <strong>
                        <?php if ($schedule['break_start'] && $schedule['break_end']): ?>
                            <?= e($formatTime($schedule['break_start'])) ?>
                            - <?= e($formatTime($schedule['break_end'])) ?>
                        <?php else: ?>
                            Tidak ditentukan
                        <?php endif; ?>
                    </strong>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card-box h-100 mb-0">
                    <div class="section-title mb-2">Status Hari Ini</div>
                    <div class="section-subtitle mb-3">
                        <?= e($todayName) ?>, <?= date('d F Y') ?>
                    </div>

                    <?php if ($workingToday): ?>
                        <span class="status-pill status-working">
                            <i class="fa-solid fa-check-circle me-2"></i>
                            Hari Kerja
                        </span>
                    <?php else: ?>
                        <span class="status-pill status-off">
                            <i class="fa-solid fa-mug-hot me-2"></i>
                            Hari Libur
                        </span>
                    <?php endif; ?>

                    <p class="text-muted small mt-3 mb-0">
                        <?= $workingToday
                            ? 'Anda memiliki jadwal kerja hari ini. Jangan lupa melakukan absensi.'
                            : 'Tidak ada jadwal kerja untuk hari ini.' ?>
                    </p>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card-box h-100 mb-0">
                    <div class="section-title mb-2">Informasi Jadwal</div>

                    <div class="info-row">
                        <span class="info-label">Nama Jadwal</span>
                        <span class="info-value"><?= e($schedule['name']) ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Hari Kerja</span>
                        <span class="info-value">
                            <?= e(implode(', ', $workingDaysText) ?: '-') ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Toleransi</span>
                        <span class="info-value">
                            <?= (int)($schedule['grace_period'] ?? 0) ?> menit
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-box">
            <div class="mb-4">
                <div class="section-title">Jadwal Mingguan</div>
                <div class="section-subtitle">
                    Hari kerja berdasarkan jadwal yang diberikan perusahaan.
                </div>
            </div>

            <div class="row g-2">
                <?php foreach ($days as $number => $dayName): ?>
                    <?php
                    $isWorkingDay = false;

                    foreach ($weeklySchedule as $day) {
                        if (
                            (int)$day === $number ||
                            strtolower((string)$day) === strtolower($dayName)
                        ) {
                            $isWorkingDay = true;
                            break;
                        }
                    }

                    $isToday = $number === $todayNumber;
                    ?>

                    <div class="col-6 col-md">
                        <div class="day-card <?= $isWorkingDay ? 'active' : '' ?>">
                            <div class="day-name"><?= e($dayName) ?></div>

                            <div class="day-icon">
                                <?php if ($isWorkingDay): ?>
                                    <i class="fa-solid fa-briefcase"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-mug-hot"></i>
                                <?php endif; ?>
                            </div>

                            <div class="day-status">
                                <?= $isWorkingDay ? 'Hari Kerja' : 'Libur' ?>
                            </div>

                            <?php if ($isToday): ?>
                                <div class="mt-2">
                                    <span class="badge bg-primary rounded-pill">Hari Ini</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php endif; ?>

    <div class="text-center text-muted small mt-4">
        © <?= date('Y') ?> Attendly. All Rights Reserved.
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const sidebar = document.getElementById('sidebar');
    const mobileToggle = document.getElementById('mobileToggle');

    mobileToggle?.addEventListener('click', function () {
        sidebar.classList.toggle('show');
    });

    document.addEventListener('click', function (event) {
        if (
            window.innerWidth <= 991 &&
            sidebar.classList.contains('show') &&
            !sidebar.contains(event.target) &&
            !mobileToggle.contains(event.target)
        ) {
            sidebar.classList.remove('show');
        }
    });
</script>

</body>
</html>
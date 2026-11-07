<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

attendly_require_auth();

$companyId = attendly_company_id();
$user = attendly_user();

/**
 * Escape output HTML
 */
function e($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Default settings
 */
$defaultSettings = [
    'attendance_grace_period' => '15',
    'attendance_auto_checkout' => '0',
    'attendance_require_location' => '1',
    'attendance_default_radius' => '100',
    'attendance_allow_manual' => '1',
    'notification_email' => '1',
    'notification_late' => '1',
    'notification_absence' => '1',
    'notification_leave' => '1',
    'rfid_enabled' => '1',
    'company_timezone' => 'Asia/Jakarta',
];

$settings = $defaultSettings;
$company = [];

$success = '';
$error = '';

/**
 * Decode JSON setting value.
 *
 * Database stores JSON strings, for example:
 * "15", "1", "Asia/Jakarta".
 */
function decodeSettingValue($value, $default = '') {

    if ($value === null || $value === '') {
        return $default;
    }

    $decoded = json_decode($value, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_scalar($decoded)) {
            return (string)$decoded;
        }
    }

    return (string)$value;
}

/**
 * Load company settings
 */
try {

    $stmt = $pdo->prepare("
        SELECT setting_key, setting_value
        FROM company_settings
        WHERE company_id = ?
    ");

    $stmt->execute([$companyId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $key = $row['setting_key'];

        if (array_key_exists($key, $settings)) {

            $settings[$key] = decodeSettingValue(
                $row['setting_value'],
                $settings[$key]
            );

        }
    }

} catch (PDOException $ex) {

    error_log('Attendly Load Settings Error: ' . $ex->getMessage());

    $error = 'Gagal mengambil pengaturan perusahaan.';

}

/**
 * Load company profile
 */
try {

    $stmt = $pdo->prepare("
        SELECT name, email, phone, address
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$companyId]);

    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $ex) {

    error_log('Attendly Load Company Error: ' . $ex->getMessage());

    $error = $error ?: 'Gagal mengambil data perusahaan.';

}

/**
 * Handle POST
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!attendly_verify_csrf($_POST['csrf_token'] ?? '')) {

        $error = 'Sesi keamanan tidak valid. Silakan muat ulang halaman.';

    } else {

        $action = $_POST['action'] ?? '';

        /**
         * SAVE COMPANY PROFILE
         */
        if ($action === 'save_company') {

            $name = trim($_POST['company_name'] ?? '');
            $email = trim($_POST['company_email'] ?? '');
            $phone = trim($_POST['company_phone'] ?? '');
            $address = trim($_POST['company_address'] ?? '');

            if ($name === '') {

                $error = 'Nama perusahaan wajib diisi.';

            } elseif (
                $email !== '' &&
                !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {

                $error = 'Format email perusahaan tidak valid.';

            } else {

                try {

                    $stmt = $pdo->prepare("
                        UPDATE companies
                        SET
                            name = ?,
                            email = ?,
                            phone = ?,
                            address = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $name,
                        $email,
                        $phone,
                        $address,
                        $companyId
                    ]);

                    $success = 'Profil perusahaan berhasil diperbarui.';

                    $company = [
                        'name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'address' => $address
                    ];

                } catch (PDOException $ex) {

                    error_log('Attendly Save Company Error: ' . $ex->getMessage());

                    $error = 'Gagal menyimpan profil perusahaan.';

                }

            }

        }

        /**
         * SAVE SYSTEM SETTINGS
         */
        elseif ($action === 'save_settings') {

            $newSettings = [

                'attendance_grace_period' => (string)max(
                    0,
                    min(180, (int)($_POST['attendance_grace_period'] ?? 15))
                ),

                'attendance_auto_checkout' =>
                    isset($_POST['attendance_auto_checkout']) ? '1' : '0',

                'attendance_require_location' =>
                    isset($_POST['attendance_require_location']) ? '1' : '0',

                'attendance_default_radius' => (string)max(
                    10,
                    min(5000, (int)($_POST['attendance_default_radius'] ?? 100))
                ),

                'attendance_allow_manual' =>
                    isset($_POST['attendance_allow_manual']) ? '1' : '0',

                'notification_email' =>
                    isset($_POST['notification_email']) ? '1' : '0',

                'notification_late' =>
                    isset($_POST['notification_late']) ? '1' : '0',

                'notification_absence' =>
                    isset($_POST['notification_absence']) ? '1' : '0',

                'notification_leave' =>
                    isset($_POST['notification_leave']) ? '1' : '0',

                'rfid_enabled' =>
                    isset($_POST['rfid_enabled']) ? '1' : '0',

                'company_timezone' =>
                    trim($_POST['company_timezone'] ?? 'Asia/Jakarta'),

            ];

            /**
             * Validate timezone
             */
            if (
                !in_array(
                    $newSettings['company_timezone'],
                    timezone_identifiers_list(),
                    true
                )
            ) {

                $newSettings['company_timezone'] = 'Asia/Jakarta';

            }

            try {

                $pdo->beginTransaction();

                /**
                 * Query sesuai struktur tabel company_settings:
                 *
                 * id
                 * company_id
                 * setting_key
                 * setting_value JSON
                 * updated_by
                 * updated_at
                 *
                 * UNIQUE KEY (company_id, setting_key)
                 */

                $stmt = $pdo->prepare("
                    INSERT INTO company_settings
                    (
                        company_id,
                        setting_key,
                        setting_value,
                        updated_by
                    )
                    VALUES (?, ?, ?, ?)

                    ON DUPLICATE KEY UPDATE

                        setting_value = VALUES(setting_value),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ");

                foreach ($newSettings as $key => $value) {

                    $jsonValue = json_encode(
                        $value,
                        JSON_UNESCAPED_UNICODE
                    );

                    if ($jsonValue === false) {
                        throw new RuntimeException(
                            'Gagal mengubah pengaturan menjadi JSON.'
                        );
                    }

                    $stmt->execute([
                        $companyId,
                        $key,
                        $jsonValue,
                        $user['id'] ?? null
                    ]);

                }

                $pdo->commit();

                $settings = array_merge(
                    $settings,
                    $newSettings
                );

                $success = 'Pengaturan berhasil disimpan.';

            } catch (Throwable $ex) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Attendly Save Settings Error: ' . $ex->getMessage()
                );

                $error = 'Gagal menyimpan pengaturan. Periksa log PHP untuk detail kesalahan.';

            }

        }

    }

}

/**
 * CSRF token
 */
$csrfToken = attendly_csrf_token();

$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Pengaturan | Attendly</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>

:root {
    --primary: #3979f6;
    --sidebar: #17243a;
    --background: #f5f7fb;
    --text: #263449;
    --muted: #8792a5;
    --border: #e9edf3;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--background);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    font-size: 14px;
}

/* SIDEBAR */

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
    transition: .2s;
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

/* MAIN */

.main-content {
    margin-left: 255px;
    padding: 30px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
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
    display: grid;
    place-items: center;
    border-radius: 50%;
    color: white;
    background: var(--primary);
    font-weight: 700;
}

.profile-name {
    font-weight: 700;
    font-size: 13px;
}

.profile-role {
    color: var(--muted);
    font-size: 11px;
}

/* SETTINGS NAV */

.settings-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
}

.settings-nav a {
    padding: 10px 15px;
    border: 1px solid var(--border);
    border-radius: 9px;
    color: #68758a;
    background: white;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.settings-nav a.active {
    color: white;
    border-color: var(--primary);
    background: var(--primary);
}

/* CARDS */

.settings-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    margin-bottom: 20px;
    overflow: hidden;
}

.card-heading {
    padding: 20px 22px;
    border-bottom: 1px solid var(--border);
}

.card-heading h5 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
}

.card-heading p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 12px;
}

.card-body {
    padding: 22px;
}

/* FORM */

.form-label {
    font-size: 12px;
    font-weight: 700;
}

.form-control,
.form-select {
    min-height: 42px;
    border-color: #e2e7ef;
    border-radius: 8px;
    font-size: 13px;
}

.form-control:focus,
.form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px #3979f61c;
}

/* SETTINGS ROW */

.setting-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid var(--border);
}

.setting-row:last-child {
    border-bottom: 0;
}

.setting-title {
    font-weight: 700;
    font-size: 13px;
}

.setting-description {
    color: var(--muted);
    font-size: 11px;
    margin-top: 4px;
}

.form-check-input {
    cursor: pointer;
}

.form-check-input:checked {
    background-color: var(--primary);
    border-color: var(--primary);
}

.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
}

.btn {
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    padding: 10px 16px;
}

.section-anchor {
    scroll-margin-top: 20px;
}

/* RESPONSIVE */

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

    .main-content {
        padding: 15px;
    }

    .topbar {
        align-items: flex-start;
    }

    .page-title {
        font-size: 20px;
    }

    .profile-info {
        display: none;
    }

    .card-body {
        padding: 16px;
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
        <i class="fa-solid fa-gauge-high"></i>
        Dashboard
    </a>

    <a href="karyawan.php">
        <i class="fa-solid fa-users"></i>
        Data Karyawan
    </a>

    <a href="departemen.php">
        <i class="fa-solid fa-building"></i>
        Departemen
    </a>

    <a href="jabatan.php">
        <i class="fa-solid fa-briefcase"></i>
        Jabatan
    </a>

    <a href="jadwal.php">
        <i class="fa-solid fa-calendar-days"></i>
        Jadwal Kerja
    </a>

    <a href="lokasi.php">
        <i class="fa-solid fa-location-dot"></i>
        Lokasi Kantor
    </a>

    <div class="menu-label">Absensi</div>

    <a href="absensi.php">
        <i class="fa-solid fa-fingerprint"></i>
        Data Absensi
    </a>

    <a href="izin.php">
        <i class="fa-solid fa-envelope-open-text"></i>
        Pengajuan Izin
    </a>

    <a href="laporan.php">
        <i class="fa-solid fa-chart-column"></i>
        Laporan
    </a>

    <div class="menu-label">Sistem</div>

    <a href="pengaturan.php" class="active">
        <i class="fa-solid fa-gear"></i>
        Pengaturan
    </a>

    <a href="logout.php"
       onclick="return confirm('Apakah Anda yakin ingin logout?')">

        <i class="fa-solid fa-right-from-bracket"></i>
        Logout

    </a>

    <div class="sidebar-footer">

        <div class="fw-bold text-white">
            Attendly System
        </div>

        <div>Versi 1.0.0</div>

    </div>

</aside>

<!-- MAIN CONTENT -->

<main class="main-content">

    <div class="topbar">

        <div>

            <button
                class="btn btn-light d-lg-none mb-3"
                id="menuToggle"
                type="button"
            >
                <i class="fa-solid fa-bars"></i>
            </button>

            <h1 class="page-title">
                Pengaturan
            </h1>

            <div class="page-subtitle">
                Kelola profil perusahaan dan konfigurasi sistem Attendly.
            </div>

        </div>

        <div class="profile">

            <div class="avatar">
                AD
            </div>

            <div class="profile-info">

                <div class="profile-name">
                    <?= e($user['name'] ?? 'Administrator') ?>
                </div>

                <div class="profile-role">
                    Administrator
                </div>

            </div>

        </div>

    </div>

    <!-- ALERT SUCCESS -->

    <?php if ($success): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="fa-solid fa-circle-check me-2"></i>

            <?= e($success) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <!-- ALERT ERROR -->

    <?php if ($error): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="fa-solid fa-circle-exclamation me-2"></i>

            <?= e($error) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>

    <!-- SETTINGS NAVIGATION -->

    <div class="settings-nav">

        <a href="#perusahaan" class="active">
            <i class="fa-solid fa-building me-2"></i>
            Perusahaan
        </a>

        <a href="#absensi">
            <i class="fa-solid fa-fingerprint me-2"></i>
            Absensi
        </a>

        <a href="#notifikasi">
            <i class="fa-solid fa-bell me-2"></i>
            Notifikasi
        </a>

        <a href="#perangkat">
            <i class="fa-solid fa-microchip me-2"></i>
            Perangkat RFID
        </a>

    </div>

    <!-- COMPANY PROFILE -->

    <section
        class="settings-card section-anchor"
        id="perusahaan"
    >

        <div class="card-heading">

            <h5>
                <i class="fa-solid fa-building me-2 text-primary"></i>
                Profil Perusahaan
            </h5>

            <p>
                Informasi dasar perusahaan yang menggunakan Attendly.
            </p>

        </div>

        <div class="card-body">

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save_company"
                >

                <div class="row g-3">

                    <div class="col-md-6">

                        <label class="form-label">
                            Nama Perusahaan *
                        </label>

                        <input
                            type="text"
                            name="company_name"
                            class="form-control"
                            value="<?= e($company['name'] ?? '') ?>"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Email Perusahaan
                        </label>

                        <input
                            type="email"
                            name="company_email"
                            class="form-control"
                            value="<?= e($company['email'] ?? '') ?>"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Nomor Telepon
                        </label>

                        <input
                            type="text"
                            name="company_phone"
                            class="form-control"
                            value="<?= e($company['phone'] ?? '') ?>"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Zona Waktu
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= e($settings['company_timezone']) ?>"
                            disabled
                        >

                        <small class="text-muted">
                            Zona waktu dapat diatur pada bagian Absensi.
                        </small>

                    </div>

                    <div class="col-12">

                        <label class="form-label">
                            Alamat Perusahaan
                        </label>

                        <textarea
                            name="company_address"
                            class="form-control"
                            rows="3"
                        ><?= e($company['address'] ?? '') ?></textarea>

                    </div>

                    <div class="col-12 text-end">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >

                            <i class="fa-solid fa-save me-2"></i>
                            Simpan Profil

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </section>

    <!-- SETTINGS FORM -->

    <form method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="action"
            value="save_settings"
        >

        <!-- ATTENDANCE SETTINGS -->

        <section
            class="settings-card section-anchor"
            id="absensi"
        >

            <div class="card-heading">

                <h5>
                    <i class="fa-solid fa-fingerprint me-2 text-primary"></i>
                    Pengaturan Absensi
                </h5>

                <p>
                    Atur kebijakan dan aturan pencatatan kehadiran karyawan.
                </p>

            </div>

            <div class="card-body">

                <div class="row g-3 mb-3">

                    <div class="col-md-6">

                        <label class="form-label">
                            Toleransi Keterlambatan (Menit)
                        </label>

                        <input
                            type="number"
                            name="attendance_grace_period"
                            class="form-control"
                            min="0"
                            max="180"
                            value="<?= e($settings['attendance_grace_period']) ?>"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Radius Lokasi Default (Meter)
                        </label>

                        <input
                            type="number"
                            name="attendance_default_radius"
                            class="form-control"
                            min="10"
                            max="5000"
                            value="<?= e($settings['attendance_default_radius']) ?>"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Zona Waktu Perusahaan
                        </label>

                        <select
                            name="company_timezone"
                            class="form-select"
                        >

                            <?php foreach (
                                [
                                    'Asia/Jakarta',
                                    'Asia/Makassar',
                                    'Asia/Jayapura',
                                    'UTC'
                                ] as $timezone
                            ): ?>

                                <option
                                    value="<?= e($timezone) ?>"
                                    <?= $settings['company_timezone'] === $timezone ? 'selected' : '' ?>
                                >

                                    <?= e($timezone) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Wajib menggunakan lokasi GPS
                        </div>

                        <div class="setting-description">
                            Karyawan harus berada dalam radius lokasi kantor untuk melakukan absensi.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="attendance_require_location"
                            value="1"
                            <?= $settings['attendance_require_location'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Aktifkan auto checkout
                        </div>

                        <div class="setting-description">
                            Sistem dapat melakukan checkout otomatis sesuai kebijakan jadwal.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="attendance_auto_checkout"
                            value="1"
                            <?= $settings['attendance_auto_checkout'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Izinkan absensi manual oleh admin
                        </div>

                        <div class="setting-description">
                            Administrator dapat menambahkan atau memperbaiki catatan absensi secara manual.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="attendance_allow_manual"
                            value="1"
                            <?= $settings['attendance_allow_manual'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

            </div>

        </section>

        <!-- NOTIFICATION SETTINGS -->

        <section
            class="settings-card section-anchor"
            id="notifikasi"
        >

            <div class="card-heading">

                <h5>
                    <i class="fa-solid fa-bell me-2 text-primary"></i>
                    Pengaturan Notifikasi
                </h5>

                <p>
                    Atur jenis pemberitahuan yang ingin diaktifkan.
                </p>

            </div>

            <div class="card-body">

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Notifikasi Email
                        </div>

                        <div class="setting-description">
                            Aktifkan pengiriman pemberitahuan melalui email.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="notification_email"
                            value="1"
                            <?= $settings['notification_email'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Notifikasi Keterlambatan
                        </div>

                        <div class="setting-description">
                            Pemberitahuan ketika karyawan terlambat masuk.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="notification_late"
                            value="1"
                            <?= $settings['notification_late'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Notifikasi Ketidakhadiran
                        </div>

                        <div class="setting-description">
                            Pemberitahuan ketika karyawan tidak melakukan absensi.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="notification_absence"
                            value="1"
                            <?= $settings['notification_absence'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Notifikasi Pengajuan Izin
                        </div>

                        <div class="setting-description">
                            Pemberitahuan ketika karyawan mengajukan izin atau cuti.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="notification_leave"
                            value="1"
                            <?= $settings['notification_leave'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

            </div>

        </section>

        <!-- RFID SETTINGS -->

        <section
            class="settings-card section-anchor"
            id="perangkat"
        >

            <div class="card-heading">

                <h5>
                    <i class="fa-solid fa-microchip me-2 text-primary"></i>
                    Perangkat RFID
                </h5>

                <p>
                    Konfigurasi dasar penggunaan kartu RFID dan perangkat ESP32.
                </p>

            </div>

            <div class="card-body">

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Aktifkan sistem RFID
                        </div>

                        <div class="setting-description">
                            Izinkan karyawan melakukan absensi menggunakan kartu RFID.
                        </div>

                    </div>

                    <div class="form-check form-switch">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="rfid_enabled"
                            value="1"
                            <?= $settings['rfid_enabled'] === '1' ? 'checked' : '' ?>
                        >

                    </div>

                </div>

                <div class="alert alert-info mt-3 mb-0">

                    <i class="fa-solid fa-circle-info me-2"></i>

                    Pengaturan ini hanya mengaktifkan fitur RFID di tingkat perusahaan.
                    Pendaftaran UID kartu dan konfigurasi perangkat ESP32 dilakukan melalui menu Data Karyawan dan Perangkat.

                </div>

            </div>

        </section>

        <!-- SAVE SETTINGS -->

        <div class="d-flex justify-content-end mb-4">

            <button
                type="submit"
                class="btn btn-primary"
            >

                <i class="fa-solid fa-save me-2"></i>
                Simpan Semua Pengaturan

            </button>

        </div>

    </form>

    <!-- FOOTER -->

    <div class="text-center text-muted small py-3">

        © 2026 Attendly. All Rights Reserved.

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

/**
 * Mobile sidebar toggle
 */
document.getElementById('menuToggle')?.addEventListener('click', function () {

    document.getElementById('sidebar').classList.toggle('show');

});

/**
 * Settings navigation active state
 */
document.querySelectorAll('.settings-nav a').forEach(function (link) {

    link.addEventListener('click', function () {

        document.querySelectorAll('.settings-nav a').forEach(function (item) {

            item.classList.remove('active');

        });

        this.classList.add('active');

    });

});

</script>

</body>
</html>
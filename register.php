<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

/*
|--------------------------------------------------------------------------
| Redirect Jika Sudah Login
|--------------------------------------------------------------------------
*/

if (attendly_is_logged_in()) {
    if (attendly_is_super_admin()) {
        header('Location: superadmin/dashboard.php');
    } else {
        $roles = array_map(
            fn($role) => strtolower(trim((string) $role)),
            attendly_roles()
        );

        if (
            in_array('employee', $roles, true) ||
            in_array('karyawan', $roles, true)
        ) {
            header('Location: karyawan/dashboard_karyawan.php');
        } else {
            header('Location: dashboard.php');
        }
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function register_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function register_length($value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

/*
|--------------------------------------------------------------------------
| Ambil Struktur Kolom Database
|--------------------------------------------------------------------------
*/

function register_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $allowedTables = [
        'companies',
        'users',
        'plans',
        'roles',
        'subscriptions',
        'user_roles',
        'activity_logs'
    ];

    if (!in_array($table, $allowedTables, true)) {
        throw new RuntimeException('Tabel tidak diizinkan.');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

    $cache[$table] = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $cache[$table][$column['Field']] = $column;
    }

    return $cache[$table];
}

/*
|--------------------------------------------------------------------------
| Buat Slug Perusahaan
|--------------------------------------------------------------------------
*/

function register_create_slug(PDO $pdo, string $companyName): string
{
    $columns = register_columns($pdo, 'companies');

    if (!isset($columns['slug'])) {
        return '';
    }

    $slug = strtolower(trim($companyName));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'company';
    }

    $baseSlug = $slug;
    $counter = 1;

    $check = $pdo->prepare("
        SELECT id
        FROM companies
        WHERE slug = ?
        LIMIT 1
    ");

    while (true) {
        $check->execute([$slug]);

        if (!$check->fetchColumn()) {
            return $slug;
        }

        $counter++;
        $slug = $baseSlug . '-' . $counter;
    }
}

/*
|--------------------------------------------------------------------------
| Insert Dinamis Berdasarkan Kolom Tersedia
|--------------------------------------------------------------------------
*/

function register_insert(
    PDO $pdo,
    string $table,
    array $data
): int {
    $columns = register_columns($pdo, $table);

    $insertData = [];

    foreach ($data as $column => $value) {
        if (isset($columns[$column])) {
            $insertData[$column] = $value;
        }
    }

    if (empty($insertData)) {
        throw new RuntimeException(
            "Tidak ada kolom yang dapat digunakan pada tabel {$table}."
        );
    }

    $columnNames = array_keys($insertData);

    $quotedColumns = array_map(
        fn($column) => "`{$column}`",
        $columnNames
    );

    $placeholders = array_fill(
        0,
        count($columnNames),
        '?'
    );

    $sql = "
        INSERT INTO `{$table}` (
            " . implode(', ', $quotedColumns) . "
        ) VALUES (
            " . implode(', ', $placeholders) . "
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($insertData));

    return (int) $pdo->lastInsertId();
}

/*
|--------------------------------------------------------------------------
| Ambil Paket Free Trial
|--------------------------------------------------------------------------
*/

function register_get_trial_plan(PDO $pdo): ?array
{
    $columns = register_columns($pdo, 'plans');

    $stmt = $pdo->query("SELECT * FROM plans");

    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($plans as $plan) {

        $identifier = strtolower(trim(
            (string) (
                $plan['slug']
                ?? $plan['code']
                ?? ''
            )
        ));

        $name = strtolower(trim(
            (string) ($plan['name'] ?? '')
        ));

        $isTrial = in_array(
            $identifier,
            ['free_trial', 'free-trial', 'trial'],
            true
        ) || in_array(
            $name,
            ['free trial', 'free_trial', 'free trial 14 hari'],
            true
        );

        if (!$isTrial) {
            continue;
        }

        if (isset($columns['status'])) {
            if (strtolower((string) $plan['status']) !== 'active') {
                continue;
            }
        }

        return $plan;
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Ambil Role Owner
|--------------------------------------------------------------------------
*/

function register_get_owner_role(PDO $pdo): ?array
{
    $columns = register_columns($pdo, 'roles');

    $sql = "
        SELECT id
        FROM roles
        WHERE LOWER(name) = 'owner'
    ";

    if (isset($columns['company_id'])) {
        $sql .= " AND company_id IS NULL";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->query($sql);

    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    return $role ?: null;
}

/*
|--------------------------------------------------------------------------
| Variabel Form
|--------------------------------------------------------------------------
*/

$errors = [];
$success = false;

$old = [
    'fullName' => '',
    'company' => '',
    'email' => '',
    'phone' => ''
];

/*
|--------------------------------------------------------------------------
| Proses Registrasi
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old['fullName'] = trim($_POST['fullName'] ?? '');
    $old['company'] = trim($_POST['company'] ?? '');
    $old['email'] = strtolower(trim($_POST['email'] ?? ''));
    $old['phone'] = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    $terms = isset($_POST['terms']);
    $csrfToken = $_POST['csrf_token'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | Validasi CSRF
    |--------------------------------------------------------------------------
    */

    if (!attendly_verify_csrf($csrfToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Nama
    |--------------------------------------------------------------------------
    */

    if ($old['fullName'] === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    } elseif (register_length($old['fullName']) > 150) {
        $errors[] = 'Nama lengkap maksimal 150 karakter.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Perusahaan
    |--------------------------------------------------------------------------
    */

    if ($old['company'] === '') {
        $errors[] = 'Nama perusahaan wajib diisi.';
    } elseif (register_length($old['company']) > 180) {
        $errors[] = 'Nama perusahaan maksimal 180 karakter.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Email
    |--------------------------------------------------------------------------
    */

    if (
        $old['email'] === '' ||
        !filter_var($old['email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Format email tidak valid.';
    } elseif (strlen($old['email']) > 190) {
        $errors[] = 'Email maksimal 190 karakter.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Nomor HP
    |--------------------------------------------------------------------------
    */

    if ($old['phone'] === '') {
        $errors[] = 'Nomor HP wajib diisi.';
    } elseif (strlen($old['phone']) > 40) {
        $errors[] = 'Nomor HP maksimal 40 karakter.';
    } elseif (!preg_match('/^[0-9+\-\s()]+$/', $old['phone'])) {
        $errors[] = 'Format nomor HP tidak valid.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Password
    |--------------------------------------------------------------------------
    */

    if (strlen($password) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Konfirmasi password tidak sesuai.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Persetujuan
    |--------------------------------------------------------------------------
    */

    if (!$terms) {
        $errors[] = 'Anda harus menyetujui syarat dan ketentuan.';
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan ke Database
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        try {

            /*
            |--------------------------------------------------------------------------
            | Pastikan Email Belum Digunakan
            |--------------------------------------------------------------------------
            */

            $checkEmail = $pdo->prepare("
                SELECT id
                FROM users
                WHERE LOWER(email) = ?
                LIMIT 1
            ");

            $checkEmail->execute([
                $old['email']
            ]);

            if ($checkEmail->fetchColumn()) {
                throw new RuntimeException(
                    'Email sudah terdaftar. Silakan gunakan email lain atau masuk.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Ambil Paket Trial dan Role Owner
            |--------------------------------------------------------------------------
            */

            $plan = register_get_trial_plan($pdo);

            if (!$plan) {
                throw new RuntimeException(
                    'Paket Free Trial tidak ditemukan atau tidak aktif. Periksa tabel plans.'
                );
            }

            $ownerRole = register_get_owner_role($pdo);

            if (!$ownerRole) {
                throw new RuntimeException(
                    'Role Owner tidak ditemukan. Periksa tabel roles.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Mulai Transaksi
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();

            $trialStart = date('Y-m-d H:i:s');
            $trialEnd = date(
                'Y-m-d H:i:s',
                strtotime('+14 days')
            );

            /*
            |--------------------------------------------------------------------------
            | Buat Perusahaan
            |--------------------------------------------------------------------------
            */

            $companyData = [
                'name' => $old['company'],
                'slug' => register_create_slug(
                    $pdo,
                    $old['company']
                ),
                'email' => $old['email'],
                'phone' => $old['phone'],
                'status' => 'active',
                'trial_ends_at' => $trialEnd
            ];

            $companyId = register_insert(
                $pdo,
                'companies',
                $companyData
            );

            /*
            |--------------------------------------------------------------------------
            | Buat Akun Owner
            |--------------------------------------------------------------------------
            */

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'Gagal mengamankan password.'
                );
            }

            $userData = [
                'company_id' => $companyId,
                'name' => $old['fullName'],
                'email' => $old['email'],
                'password_hash' => $passwordHash,
                'phone' => $old['phone'],
                'status' => 'active'
            ];

            $userId = register_insert(
                $pdo,
                'users',
                $userData
            );

            /*
            |--------------------------------------------------------------------------
            | Hubungkan Owner dengan Role
            |--------------------------------------------------------------------------
            */

            register_insert(
                $pdo,
                'user_roles',
                [
                    'user_id' => $userId,
                    'role_id' => $ownerRole['id']
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Buat Langganan Free Trial
            |--------------------------------------------------------------------------
            | Menggunakan status active agar sesuai dengan
            | attendly_require_tenant() pada config/session.php.
            */

            $subscriptionData = [
                'company_id' => $companyId,
                'plan_id' => $plan['id'],
                'status' => 'active',
                'billing_cycle' => 'monthly',

                // Struktur database yang digunakan session.php.
                'start_date' => $trialStart,
                'end_date' => $trialEnd,

                // Kompatibilitas jika kolom memakai nama alternatif.
                'starts_at' => $trialStart,
                'ends_at' => $trialEnd,

                'trial_ends_at' => $trialEnd
            ];

            register_insert(
                $pdo,
                'subscriptions',
                $subscriptionData
            );

            /*
            |--------------------------------------------------------------------------
            | Catat Aktivitas
            |--------------------------------------------------------------------------
            | Pencatatan aktivitas bersifat opsional.
            | Kegagalan log tidak membatalkan registrasi.
            */

            try {

                register_insert(
                    $pdo,
                    'activity_logs',
                    [
                        'company_id' => $companyId,
                        'user_id' => $userId,
                        'action' => 'register',
                        'module' => 'account',
                        'description' => 'Perusahaan dan akun Owner berhasil didaftarkan.',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => substr(
                            $_SERVER['HTTP_USER_AGENT'] ?? '',
                            0,
                            500
                        )
                    ]
                );

            } catch (Throwable $logError) {

                error_log(
                    '[ATTENDLY REGISTER LOG] ' . $logError->getMessage()
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            $success = true;

            $old = [
                'fullName' => '',
                'company' => '',
                'email' => '',
                'phone' => ''
            ];

        } catch (Throwable $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                '[ATTENDLY REGISTER] ' . $ex->getMessage()
            );

            if ($ex instanceof RuntimeException) {
                $errors[] = $ex->getMessage();
            } elseif (
                $ex instanceof PDOException &&
                $ex->getCode() === '23000'
            ) {
                $errors[] = 'Email atau data perusahaan sudah digunakan.';
            } else {
                $errors[] = 'Terjadi kesalahan sistem. Silakan coba kembali.';
            }
        }
    }
}

$csrfToken = attendly_csrf_token();
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101c3a">

    <title>Daftar Akun - Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --navy: #101c3a;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            color: var(--navy);
            background: var(--bg);
            font-family: 'Inter', sans-serif;
        }

        a {
            text-decoration: none;
        }

        .register-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .register-visual {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            width: 43%;
            min-height: 100vh;
            padding: 50px 7%;
            overflow: hidden;
            color: #fff;
            background: linear-gradient(145deg, #101c3a, #173b78 55%, #2563eb);
        }

        .register-visual::before,
        .register-visual::after {
            position: absolute;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 50%;
            content: "";
            pointer-events: none;
        }

        .register-visual::before {
            top: -180px;
            right: -230px;
            width: 550px;
            height: 550px;
        }

        .register-visual::after {
            bottom: -180px;
            left: -220px;
            width: 420px;
            height: 420px;
        }

        .brand {
            position: relative;
            z-index: 1;
            color: #fff;
            font-size: 25px;
            font-weight: 800;
        }

        .brand i {
            margin-right: 9px;
            color: #60a5fa;
        }

        .visual-content {
            position: relative;
            z-index: 1;
            max-width: 490px;
        }

        .visual-content h1 {
            margin-bottom: 20px;
            font-size: clamp(32px, 4vw, 47px);
            font-weight: 800;
            line-height: 1.2;
        }

        .visual-content h1 span {
            color: #93c5fd;
        }

        .visual-content p {
            color: #cbd5e1;
            font-size: 14px;
            line-height: 1.8;
        }

        .visual-features {
            display: flex;
            flex-direction: column;
            gap: 17px;
            margin-top: 35px;
        }

        .visual-feature {
            display: flex;
            align-items: center;
            gap: 13px;
            color: #e2e8f0;
            font-size: 13px;
        }

        .visual-feature i {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 31px;
            height: 31px;
            border-radius: 9px;
            color: #93c5fd;
            background: rgba(255,255,255,.13);
        }

        .visual-footer {
            position: relative;
            z-index: 1;
            color: #cbd5e1;
            font-size: 12px;
        }

        .register-form-section {
            display: flex;
            flex: 1;
            align-items: center;
            justify-content: center;
            padding: 45px 30px;
        }

        .register-card {
            width: 100%;
            max-width: 470px;
        }

        .mobile-brand {
            display: none;
            margin-bottom: 30px;
            color: var(--navy);
            font-size: 24px;
            font-weight: 800;
        }

        .mobile-brand i {
            margin-right: 8px;
            color: var(--primary);
        }

        .register-heading {
            margin-bottom: 28px;
        }

        .register-heading h2 {
            margin-bottom: 9px;
            font-size: 29px;
            font-weight: 800;
        }

        .register-heading p {
            color: var(--muted);
            font-size: 13px;
        }

        .form-label {
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .form-control {
            height: 46px;
            padding: 11px 13px;
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 13px;
            box-shadow: none !important;
        }

        .form-control:focus {
            border-color: var(--primary);
        }

        .input-group .form-control {
            border-radius: 9px 0 0 9px;
        }

        .input-group-text {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 46px;
            border: 1px solid var(--border);
            border-left: 0;
            border-radius: 0 9px 9px 0;
            color: var(--muted);
            background: #fff;
            cursor: pointer;
        }

        .form-check-input {
            cursor: pointer;
        }

        .form-check-input:checked {
            border-color: var(--primary);
            background-color: var(--primary);
        }

        .form-check-label {
            color: var(--muted);
            font-size: 11px;
            line-height: 1.6;
            cursor: pointer;
        }

        .form-check-label a {
            color: var(--primary);
            font-weight: 600;
        }

        .password-strength {
            height: 4px;
            margin-top: 9px;
            overflow: hidden;
            border-radius: 10px;
            background: #e2e8f0;
        }

        .password-strength span {
            display: block;
            width: 0;
            height: 100%;
            transition: .3s;
        }

        .strength-text {
            margin-top: 5px;
            color: var(--muted);
            font-size: 10px;
        }

        .btn-register {
            width: 100%;
            height: 48px;
            margin-top: 23px;
            border: none;
            border-radius: 9px;
            color: #fff;
            background: var(--primary);
            font-size: 13px;
            font-weight: 700;
            transition: .2s;
        }

        .btn-register:hover {
            color: #fff;
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-register:disabled {
            opacity: .7;
            cursor: wait;
            transform: none;
        }

        .login-text {
            margin-top: 22px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        .login-text a {
            color: var(--primary);
            font-weight: 700;
        }

        .security-note {
            margin-top: 28px;
            color: #94a3b8;
            font-size: 10px;
            text-align: center;
        }

        .security-note i {
            margin-right: 5px;
            color: #16a34a;
        }

        .alert {
            border-radius: 9px;
            font-size: 12px;
        }

        @media (max-width: 991px) {
            .register-visual {
                width: 38%;
                padding: 40px 5%;
            }

            .visual-content h1 {
                font-size: 33px;
            }
        }

        @media (max-width: 767px) {
            .register-visual {
                display: none;
            }

            .register-form-section {
                min-height: 100vh;
                padding: 30px 20px;
            }

            .mobile-brand {
                display: block;
            }

            .register-heading h2 {
                font-size: 26px;
            }
        }
    </style>

</head>

<body>

<div class="register-wrapper">

    <!-- Panel Kiri -->
    <section class="register-visual">

        <a href="index.php" class="brand">
            <i class="fa-solid fa-fingerprint"></i>Attendly
        </a>

        <div class="visual-content">

            <h1>
                Mulai Kelola Tim dengan
                <span>Lebih Cerdas.</span>
            </h1>

            <p>
                Buat akun Attendly dan nikmati kemudahan mengelola
                absensi, karyawan, jadwal kerja, serta laporan kehadiran
                dalam satu platform.
            </p>

            <div class="visual-features">

                <div class="visual-feature">
                    <i class="fa-solid fa-users"></i>
                    Manajemen data karyawan
                </div>

                <div class="visual-feature">
                    <i class="fa-solid fa-calendar-check"></i>
                    Jadwal dan pengaturan shift
                </div>

                <div class="visual-feature">
                    <i class="fa-solid fa-chart-pie"></i>
                    Analisis laporan kehadiran
                </div>

                <div class="visual-feature">
                    <i class="fa-solid fa-shield-halved"></i>
                    Pengelolaan akun terpusat
                </div>

            </div>

        </div>

        <div class="visual-footer">
            © <?= date('Y') ?> Attendly. All rights reserved.
        </div>

    </section>

    <!-- Form Registrasi -->
    <section class="register-form-section">

        <div class="register-card">

            <a href="index.php" class="mobile-brand">
                <i class="fa-solid fa-fingerprint"></i>Attendly
            </a>

            <div class="register-heading">

                <h2>Buat Akun Baru</h2>

                <p>
                    Daftarkan perusahaan Anda untuk mulai menggunakan Attendly.
                </p>

            </div>

            <!-- Pesan Error -->
            <?php if (!empty($errors)): ?>

                <div class="alert alert-danger">

                    <div class="fw-bold mb-1">
                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                        Pendaftaran gagal
                    </div>

                    <ul class="mb-0 ps-3">

                        <?php foreach ($errors as $error): ?>

                            <li><?= register_e($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <!-- Pesan Berhasil -->
            <?php if ($success): ?>

                <div class="alert alert-success">

                    <div class="fw-bold mb-1">
                        <i class="fa-solid fa-circle-check me-1"></i>
                        Pendaftaran berhasil!
                    </div>

                    Perusahaan dan akun Owner Anda berhasil dibuat.

                    <br>

                    Masa uji coba gratis berlaku selama 14 hari.

                    <div class="mt-3">

                        <a href="login.php" class="btn btn-success btn-sm">
                            <i class="fa-solid fa-right-to-bracket me-1"></i>
                            Masuk ke Attendly
                        </a>

                    </div>

                </div>

            <?php else: ?>

                <form id="registerForm" method="POST" action="">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= register_e($csrfToken) ?>"
                    >

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label" for="fullName">
                                Nama Lengkap
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="fullName"
                                name="fullName"
                                placeholder="Nama lengkap"
                                autocomplete="name"
                                maxlength="150"
                                value="<?= register_e($old['fullName']) ?>"
                                required
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label" for="company">
                                Nama Perusahaan
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="company"
                                name="company"
                                placeholder="Nama perusahaan"
                                autocomplete="organization"
                                maxlength="180"
                                value="<?= register_e($old['company']) ?>"
                                required
                            >

                        </div>

                    </div>

                    <div class="row">

                        <div class="col-md-6 mb-3">

                            <label class="form-label" for="email">
                                Email
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                placeholder="nama@email.com"
                                autocomplete="email"
                                maxlength="190"
                                value="<?= register_e($old['email']) ?>"
                                required
                            >

                        </div>

                        <div class="col-md-6 mb-3">

                            <label class="form-label" for="phone">
                                Nomor HP
                            </label>

                            <input
                                type="tel"
                                class="form-control"
                                id="phone"
                                name="phone"
                                placeholder="08xxxxxxxxxx"
                                autocomplete="tel"
                                maxlength="40"
                                value="<?= register_e($old['phone']) ?>"
                                required
                            >

                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="form-label" for="password">
                            Password
                        </label>

                        <div class="input-group">

                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Minimal 8 karakter"
                                autocomplete="new-password"
                                minlength="8"
                                required
                            >

                            <button
                                type="button"
                                class="input-group-text"
                                id="togglePassword"
                                aria-label="Tampilkan password"
                            >
                                <i class="fa-regular fa-eye"></i>
                            </button>

                        </div>

                        <div class="password-strength">
                            <span id="strengthBar"></span>
                        </div>

                        <div class="strength-text" id="strengthText">
                            Kekuatan password
                        </div>

                    </div>

                    <div class="mb-3">

                        <label class="form-label" for="confirmPassword">
                            Konfirmasi Password
                        </label>

                        <div class="input-group">

                            <input
                                type="password"
                                class="form-control"
                                id="confirmPassword"
                                name="confirmPassword"
                                placeholder="Ulangi password"
                                autocomplete="new-password"
                                required
                            >

                            <button
                                type="button"
                                class="input-group-text"
                                id="toggleConfirmPassword"
                                aria-label="Tampilkan konfirmasi password"
                            >
                                <i class="fa-regular fa-eye"></i>
                            </button>

                        </div>

                    </div>

                    <div class="form-check mt-3">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="terms"
                            name="terms"
                            value="1"
                            required
                        >

                        <label class="form-check-label" for="terms">

                            Saya menyetujui
                            <a href="terms.php" target="_blank">Syarat dan Ketentuan</a>
                            serta
                            <a href="privacy.php" target="_blank">Kebijakan Privasi</a>
                            Attendly.

                        </label>

                    </div>

                    <button
                        type="submit"
                        class="btn-register"
                        id="registerButton"
                    >

                        <span id="registerButtonText">
                            Buat Akun Sekarang
                        </span>

                        <i class="fa-solid fa-arrow-right ms-2"></i>

                    </button>

                </form>

            <?php endif; ?>

            <div class="login-text">

                Sudah memiliki akun?

                <a href="login.php">
                    Masuk sekarang
                </a>

            </div>

            <div class="security-note">

                <i class="fa-solid fa-lock"></i>

                Informasi Anda diproses secara aman.

            </div>

        </div>

    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirmPassword');

    /*
    |--------------------------------------------------------------------------
    | Toggle Password
    |--------------------------------------------------------------------------
    */

    function setupPasswordToggle(input, button) {

        if (!input || !button) return;

        button.addEventListener('click', function () {

            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';

            this.innerHTML = isPassword
                ? '<i class="fa-regular fa-eye-slash"></i>'
                : '<i class="fa-regular fa-eye"></i>';

        });
    }

    setupPasswordToggle(
        password,
        document.getElementById('togglePassword')
    );

    setupPasswordToggle(
        confirmPassword,
        document.getElementById('toggleConfirmPassword')
    );

    /*
    |--------------------------------------------------------------------------
    | Password Strength
    |--------------------------------------------------------------------------
    */

    if (password) {

        password.addEventListener('input', function () {

            const value = this.value;

            const bar = document.getElementById('strengthBar');
            const text = document.getElementById('strengthText');

            let score = 0;

            if (value.length >= 8) score++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
            if (/[0-9]/.test(value)) score++;
            if (/[^a-zA-Z0-9]/.test(value)) score++;

            const levels = [
                {
                    width: '0%',
                    label: 'Kekuatan password',
                    color: '#e2e8f0'
                },
                {
                    width: '25%',
                    label: 'Lemah',
                    color: '#ef4444'
                },
                {
                    width: '50%',
                    label: 'Cukup',
                    color: '#f59e0b'
                },
                {
                    width: '75%',
                    label: 'Baik',
                    color: '#3b82f6'
                },
                {
                    width: '100%',
                    label: 'Sangat kuat',
                    color: '#16a34a'
                }
            ];

            bar.style.width = levels[score].width;
            bar.style.background = levels[score].color;

            text.textContent = levels[score].label;

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Validasi Form
    |--------------------------------------------------------------------------
    */

    const registerForm = document.getElementById('registerForm');

    if (registerForm) {

        registerForm.addEventListener('submit', function (event) {

            if (password.value.length < 8) {

                event.preventDefault();

                alert('Password minimal 8 karakter.');

                password.focus();

                return;
            }

            if (password.value !== confirmPassword.value) {

                event.preventDefault();

                alert('Konfirmasi password tidak sesuai.');

                confirmPassword.focus();

                return;
            }

            const button = document.getElementById('registerButton');
            const buttonText = document.getElementById('registerButtonText');

            button.disabled = true;

            buttonText.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin me-2"></i>Memproses...';

        });

    }

});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
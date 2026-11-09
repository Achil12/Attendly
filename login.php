<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

/*
|--------------------------------------------------------------------------
| Helper Redirect Berdasarkan Role
|--------------------------------------------------------------------------
*/

function attendly_login_redirect(): string
{
    if (attendly_is_super_admin()) {
        return 'superadmin/dashboard.php';
    }

    $roles = array_map(
        fn($role) => strtolower(trim((string) $role)),
        attendly_roles()
    );

    if (
        in_array('employee', $roles, true) ||
        in_array('karyawan', $roles, true)
    ) {
        return 'karyawan/dashboard_karyawan.php';
    }

    return 'dashboard.php';
}

/*
|--------------------------------------------------------------------------
| Jika Sudah Login
|--------------------------------------------------------------------------
*/

if (attendly_is_logged_in()) {
    header('Location: ' . attendly_login_redirect());
    exit;
}

/*
|--------------------------------------------------------------------------
| Variabel
|--------------------------------------------------------------------------
*/

$error = '';
$email = '';

/*
|--------------------------------------------------------------------------
| Proses Login
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!attendly_verify_csrf($csrfToken)) {

        $error = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';

    } elseif (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $password === ''
    ) {

        $error = 'Email dan password wajib diisi dengan benar.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Ambil Data Pengguna
            |--------------------------------------------------------------------------
            | LEFT JOIN digunakan agar Super Admin tetap bisa login
            | meskipun company_id bernilai NULL.
            */

            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.company_id,
                    u.name,
                    u.email,
                    u.password_hash,
                    u.status AS user_status,
                    c.status AS company_status
                FROM users u
                LEFT JOIN companies c
                    ON c.id = u.company_id
                WHERE LOWER(u.email) = :email
                LIMIT 1
            ");

            $stmt->execute([
                'email' => $email
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | Validasi Akun dan Password
            |--------------------------------------------------------------------------
            */

            if (
                !$user ||
                !password_verify($password, $user['password_hash'])
            ) {

                $error = 'Email atau password yang kamu masukkan salah.';

            } elseif ($user['user_status'] !== 'active') {

                $error = 'Akun kamu sedang tidak aktif. Hubungi administrator.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Ambil Role Pengguna
                |--------------------------------------------------------------------------
                */

                $roleStmt = $pdo->prepare("
                    SELECT r.name
                    FROM user_roles ur
                    INNER JOIN roles r
                        ON r.id = ur.role_id
                    WHERE ur.user_id = :user_id
                ");

                $roleStmt->execute([
                    'user_id' => $user['id']
                ]);

                $roles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($roles)) {

                    $error = 'Akun belum memiliki hak akses. Hubungi administrator.';

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Identifikasi Super Admin
                    |--------------------------------------------------------------------------
                    */

                    $normalizedRoles = array_map(
                        fn($role) => strtolower(trim((string) $role)),
                        $roles
                    );

                    $isSuperAdmin = in_array(
                        'super admin',
                        $normalizedRoles,
                        true
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Validasi Perusahaan
                    |--------------------------------------------------------------------------
                    | Super Admin tidak wajib memiliki perusahaan.
                    | Pengguna biasa wajib terhubung dengan perusahaan aktif.
                    */

                    if (!$isSuperAdmin) {

                        if (empty($user['company_id'])) {

                            $error = 'Akun belum terhubung dengan perusahaan. Hubungi administrator.';

                        } elseif ($user['company_status'] !== 'active') {

                            $error = 'Perusahaan belum aktif. Silakan hubungi administrator.';

                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Login Berhasil
                    |--------------------------------------------------------------------------
                    */

                    if ($error === '') {

                        // Mencegah session fixation.
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            session_regenerate_id(true);
                        }

                        // Buat session login.
                        attendly_login($user, $roles);

                        $_SESSION['authenticated_at'] = time();
                        $_SESSION['last_activity'] = time();

                        /*
                        |--------------------------------------------------------------------------
                        | Update Waktu Login
                        |--------------------------------------------------------------------------
                        */

                        try {

                            $update = $pdo->prepare("
                                UPDATE users
                                SET last_login_at = NOW()
                                WHERE id = :user_id
                            ");

                            $update->execute([
                                'user_id' => $user['id']
                            ]);

                        } catch (PDOException $ex) {

                            // Login tetap berhasil jika kolom belum tersedia.
                            error_log(
                                '[ATTENDLY LAST LOGIN] ' . $ex->getMessage()
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Redirect Berdasarkan Role
                        |--------------------------------------------------------------------------
                        */

                        header('Location: ' . attendly_login_redirect());
                        exit;
                    }
                }
            }

        } catch (PDOException $ex) {

            error_log('[ATTENDLY LOGIN] ' . $ex->getMessage());

            $error = 'Terjadi kesalahan sistem. Silakan coba beberapa saat lagi.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101c3a">

    <title>Login - Attendly</title>

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
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            color: var(--navy);
            background: var(--bg);
        }

        a {
            text-decoration: none;
        }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Visual Panel */

        .login-visual {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            width: 52%;
            min-height: 100vh;
            padding: 55px 8%;
            overflow: hidden;
            color: #fff;
            background: linear-gradient(
                145deg,
                #101c3a 0%,
                #173b78 55%,
                #2563eb 100%
            );
        }

        .login-visual::before,
        .login-visual::after {
            position: absolute;
            content: "";
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 50%;
            pointer-events: none;
        }

        .login-visual::before {
            top: -170px;
            right: -220px;
            width: 550px;
            height: 550px;
        }

        .login-visual::after {
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
            max-width: 540px;
        }

        .visual-content h1 {
            margin-bottom: 22px;
            font-size: clamp(32px, 4vw, 49px);
            font-weight: 800;
            line-height: 1.2;
        }

        .visual-content h1 span {
            color: #93c5fd;
        }

        .visual-content p {
            max-width: 460px;
            color: #cbd5e1;
            font-size: 15px;
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
            font-size: 14px;
        }

        .visual-feature i {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
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

        /* Form Panel */

        .login-form-section {
            display: flex;
            flex: 1;
            align-items: center;
            justify-content: center;
            padding: 40px 25px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
        }

        .mobile-brand {
            display: none;
            margin-bottom: 40px;
            color: var(--navy);
            font-size: 24px;
            font-weight: 800;
        }

        .mobile-brand i {
            margin-right: 8px;
            color: var(--primary);
        }

        .login-heading {
            margin-bottom: 32px;
        }

        .login-heading h2 {
            margin-bottom: 10px;
            font-size: 30px;
            font-weight: 800;
        }

        .login-heading p {
            color: var(--muted);
            font-size: 14px;
        }

        .form-label {
            margin-bottom: 9px;
            font-size: 13px;
            font-weight: 600;
        }

        .form-control {
            height: 50px;
            padding: 12px 15px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            box-shadow: none !important;
        }

        .form-control:focus {
            border-color: var(--primary);
        }

        .input-group .form-control {
            border-radius: 10px 0 0 10px;
        }

        .input-group-text {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 50px;
            border: 1px solid var(--border);
            border-left: 0;
            border-radius: 0 10px 10px 0;
            color: var(--muted);
            background: #fff;
            cursor: pointer;
        }

        .forgot-link {
            color: var(--primary);
            font-size: 12px;
            font-weight: 600;
        }

        .forgot-link:hover {
            color: var(--primary-dark);
        }

        .btn-login {
            width: 100%;
            height: 50px;
            margin-top: 25px;
            border: none;
            border-radius: 10px;
            color: #fff;
            background: var(--primary);
            font-size: 14px;
            font-weight: 700;
            transition: .2s;
        }

        .btn-login:hover {
            color: #fff;
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-login:disabled {
            opacity: .7;
            cursor: wait;
            transform: none;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 27px 0;
            color: #94a3b8;
            font-size: 12px;
        }

        .divider::before,
        .divider::after {
            flex: 1;
            height: 1px;
            background: var(--border);
            content: "";
        }

        .register-text {
            color: var(--muted);
            font-size: 13px;
            text-align: center;
        }

        .register-text a {
            color: var(--primary);
            font-weight: 700;
        }

        .security-note {
            margin-top: 35px;
            color: #94a3b8;
            font-size: 11px;
            text-align: center;
        }

        .security-note i {
            margin-right: 5px;
            color: #16a34a;
        }

        .alert {
            border-radius: 10px;
            font-size: 13px;
        }

        @media (max-width: 991px) {
            .login-visual {
                width: 43%;
                padding: 40px 6%;
            }

            .visual-content h1 {
                font-size: 34px;
            }
        }

        @media (max-width: 767px) {
            .login-visual {
                display: none;
            }

            .login-form-section {
                min-height: 100vh;
                padding: 35px 22px;
            }

            .mobile-brand {
                display: block;
            }

            .login-heading h2 {
                font-size: 27px;
            }
        }
    </style>
</head>

<body>

<div class="login-wrapper">

    <!-- Panel Kiri -->
    <section class="login-visual">

        <a href="index.php" class="brand">
            <i class="fa-solid fa-fingerprint"></i>Attendly
        </a>

        <div class="visual-content">

            <h1>
                Kelola Kehadiran Tim dengan
                <span>Lebih Mudah.</span>
            </h1>

            <p>
                Pantau kehadiran karyawan, kelola jadwal kerja,
                dan buat laporan secara praktis melalui satu platform.
            </p>

            <div class="visual-features">

                <div class="visual-feature">
                    <i class="fa-solid fa-fingerprint"></i>
                    Absensi karyawan lebih praktis
                </div>

                <div class="visual-feature">
                    <i class="fa-solid fa-calendar-check"></i>
                    Pengelolaan jadwal kerja
                </div>

                <div class="visual-feature">
                    <i class="fa-solid fa-chart-line"></i>
                    Laporan kehadiran real-time
                </div>

            </div>

        </div>

        <div class="visual-footer">
            © <?= date('Y') ?> Attendly. All rights reserved.
        </div>

    </section>

    <!-- Panel Login -->
    <section class="login-form-section">

        <div class="login-card">

            <a href="index.php" class="mobile-brand">
                <i class="fa-solid fa-fingerprint"></i>Attendly
            </a>

            <div class="login-heading">

                <h2>Selamat Datang!</h2>

                <p>
                    Masuk ke akun Attendly untuk melanjutkan.
                </p>

            </div>

            <!-- Pesan Error -->
            <div id="alertBox" aria-live="polite">

                <?php if ($error !== ''): ?>

                    <div class="alert alert-danger alert-dismissible fade show"
                         role="alert">

                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

                        <button type="button"
                                class="btn-close"
                                data-bs-dismiss="alert"
                                aria-label="Tutup">
                        </button>

                    </div>

                <?php endif; ?>

            </div>

            <!-- Form Login -->
            <form id="loginForm" method="POST" action="login.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(attendly_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="mb-4">

                    <label class="form-label" for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        placeholder="nama@perusahaan.com"
                        value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="username"
                        maxlength="190"
                        required
                    >

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
                            placeholder="Masukkan password"
                            autocomplete="current-password"
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

                </div>

                <div class="d-flex justify-content-end align-items-center mt-3">

                    <a href="#" class="forgot-link" id="forgotPassword">
                        Lupa password?
                    </a>

                </div>

                <button
                    type="submit"
                    class="btn-login"
                    id="loginButton"
                >

                    <span id="loginButtonText">
                        Masuk Sekarang
                    </span>

                    <i class="fa-solid fa-arrow-right ms-2"></i>

                </button>

            </form>

            <div class="divider">
                atau
            </div>

            <div class="register-text">

                Belum memiliki akun?

                <a href="register.php">
                    Daftar sekarang
                </a>

            </div>

            <div class="security-note">

                <i class="fa-solid fa-shield-halved"></i>

                Informasi akun Anda dilindungi oleh sistem keamanan Attendly.

            </div>

        </div>

    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Toggle Password
    |--------------------------------------------------------------------------
    */

    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    togglePassword.addEventListener('click', function () {

        const isPassword = passwordInput.type === 'password';

        passwordInput.type = isPassword ? 'text' : 'password';

        this.innerHTML = isPassword
            ? '<i class="fa-regular fa-eye-slash"></i>'
            : '<i class="fa-regular fa-eye"></i>';

        this.setAttribute(
            'aria-label',
            isPassword ? 'Sembunyikan password' : 'Tampilkan password'
        );

    });

    /*
    |--------------------------------------------------------------------------
    | Loading Login
    |--------------------------------------------------------------------------
    */

    const loginForm = document.getElementById('loginForm');
    const loginButton = document.getElementById('loginButton');
    const loginButtonText = document.getElementById('loginButtonText');

    loginForm.addEventListener('submit', function () {

        if (!loginForm.checkValidity()) {
            return;
        }

        loginButton.disabled = true;

        loginButtonText.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin me-2"></i>Memproses...';

    });

    /*
    |--------------------------------------------------------------------------
    | Informasi Lupa Password
    |--------------------------------------------------------------------------
    */

    document.getElementById('forgotPassword').addEventListener('click', function (e) {

        e.preventDefault();

        const alertBox = document.getElementById('alertBox');

        alertBox.innerHTML = `
            <div class="alert alert-info alert-dismissible fade show" role="alert">

                Fitur reset password belum tersedia.
                Silakan hubungi administrator.

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Tutup">
                </button>

            </div>
        `;

    });

});
</script>

</body>
</html>
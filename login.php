
<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

// Jika sudah login, arahkan ke dashboard
if (attendly_is_logged_in()) {
    header('Location: admin/dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
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
            // Cari akun berdasarkan email
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
                INNER JOIN companies c
                    ON c.id = u.company_id
                WHERE u.email = :email
                LIMIT 1
            ");

            $stmt->execute([
                'email' => $email
            ]);

            $user = $stmt->fetch();

            // Validasi akun dan password
            if (
                !$user ||
                !password_verify($password, $user['password_hash'])
            ) {
                $error = 'Email atau password yang kamu masukkan salah.';

            } elseif ($user['user_status'] !== 'active') {
                $error = 'Akun kamu sedang tidak aktif. Hubungi administrator.';

            } elseif ($user['company_status'] !== 'active') {
                $error = 'Perusahaan belum aktif. Silakan hubungi administrator.';

            } else {

                // Ambil role dari database
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

                    // Buat session login
                    attendly_login($user, $roles);

                    // Perbarui waktu login jika kolom tersedia
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
                        // Tidak menggagalkan login jika kolom belum tersedia
                        error_log('[ATTENDLY LAST LOGIN] ' . $ex->getMessage());
                    }

                    // Catat waktu login
                    $_SESSION['authenticated_at'] = time();

                    header('Location: dashboard.php');
                    exit;
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
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            color: var(--navy);
        }

        a {
            text-decoration: none;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
        }

        /* Left panel */
        .login-visual {
            width: 52%;
            min-height: 100vh;
            background: linear-gradient(145deg, #101c3a 0%, #173b78 55%, #2563eb 100%);
            color: white;
            padding: 55px 8%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .login-visual::before,
        .login-visual::after {
            content: "";
            position: absolute;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 50%;
        }

        .login-visual::before {
            width: 550px;
            height: 550px;
            right: -220px;
            top: -170px;
        }

        .login-visual::after {
            width: 420px;
            height: 420px;
            left: -220px;
            bottom: -180px;
        }

        .brand {
            color: white;
            font-size: 25px;
            font-weight: 800;
            position: relative;
            z-index: 1;
        }

        .brand i {
            color: #60a5fa;
            margin-right: 9px;
        }

        .visual-content {
            max-width: 540px;
            position: relative;
            z-index: 1;
        }

        .visual-content h1 {
            font-size: clamp(32px, 4vw, 49px);
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 22px;
        }

        .visual-content h1 span {
            color: #93c5fd;
        }

        .visual-content p {
            color: #cbd5e1;
            line-height: 1.8;
            font-size: 15px;
            max-width: 460px;
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
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: rgba(255,255,255,.13);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #93c5fd;
        }

        .visual-footer {
            color: #cbd5e1;
            font-size: 12px;
            position: relative;
            z-index: 1;
        }

        /* Right panel */
        .login-form-section {
            flex: 1;
            display: flex;
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
            font-size: 24px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 40px;
        }

        .mobile-brand i {
            color: var(--primary);
            margin-right: 8px;
        }

        .login-heading {
            margin-bottom: 32px;
        }

        .login-heading h2 {
            font-size: 30px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .login-heading p {
            color: var(--muted);
            font-size: 14px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 9px;
        }

        .form-control {
            height: 50px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 15px;
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
            background: white;
            border: 1px solid var(--border);
            border-left: 0;
            border-radius: 0 10px 10px 0;
            cursor: pointer;
            color: var(--muted);
            padding: 0 15px;
        }

        .form-check-input {
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-label {
            color: var(--muted);
            font-size: 12px;
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
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            margin-top: 25px;
            transition: .2s;
        }

        .btn-login:hover {
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
            content: "";
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .register-text {
            text-align: center;
            font-size: 13px;
            color: var(--muted);
        }

        .register-text a {
            color: var(--primary);
            font-weight: 700;
        }

        .security-note {
            margin-top: 35px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
        }

        .security-note i {
            color: #16a34a;
            margin-right: 5px;
        }

        .alert {
            font-size: 13px;
            border-radius: 10px;
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

    <!-- Left Visual -->
    <section class="login-visual">

        <a href="index.html" class="brand">
            <i class="fa-solid fa-fingerprint"></i>Attendly
        </a>

        <div class="visual-content">
            <h1>
                Kelola Kehadiran Tim dengan <span>Lebih Mudah.</span>
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

    <!-- Login Form -->
    <section class="login-form-section">

        <div class="login-card">

            <a href="index.html" class="mobile-brand">
                <i class="fa-solid fa-fingerprint"></i>Attendly
            </a>

            <div class="login-heading">
                <h2>Selamat Datang!</h2>
                <p>Masuk ke akun Attendly untuk melanjutkan.</p>
            </div>

            <div id="alertBox" aria-live="polite">

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-dismissible fade show"
                         role="alert">
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>

                        <button type="button"
                                class="btn-close"
                                data-bs-dismiss="alert"
                                aria-label="Tutup"></button>
                    </div>
                <?php endif; ?>

            </div>

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

                        <span class="input-group-text" id="togglePassword">
                            <i class="fa-regular fa-eye"></i>
                        </span>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">

                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="remember"
                            name="remember"
                            value="1"
                        >

                        <label class="form-check-label" for="remember">
                            Ingat saya
                        </label>
                    </div>

                    <a href="#" class="forgot-link" id="forgotPassword">
                        Lupa password?
                    </a>

                </div>

                <button type="submit" class="btn-login" id="loginButton">
                    <span id="loginButtonText">Masuk Sekarang</span>
                    <i class="fa-solid fa-arrow-right ms-2"></i>
                </button>

            </form>

            <div class="divider">atau</div>

            <div class="register-text">
                Belum memiliki akun?
                <a href="register.html">Daftar sekarang</a>
            </div>

            <div class="security-note">
                <i class="fa-solid fa-shield-halved"></i>
                Koneksi dan informasi akun Anda perlu dilindungi.
            </div>

        </div>

    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Tampilkan / sembunyikan password
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');

    togglePassword.addEventListener('click', function () {
        const isPassword = passwordInput.type === 'password';

        passwordInput.type = isPassword ? 'text' : 'password';

        this.innerHTML = isPassword
            ? '<i class="fa-regular fa-eye-slash"></i>'
            : '<i class="fa-regular fa-eye"></i>';
    });

    // Indikator proses login
    document.getElementById('loginForm').addEventListener('submit', function () {
        const button = document.getElementById('loginButton');
        const buttonText = document.getElementById('loginButtonText');

        button.disabled = true;
        buttonText.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin me-2"></i>Memproses...';
    });

    // Informasi lupa password
    document.getElementById('forgotPassword').addEventListener('click', function (e) {
        e.preventDefault();

        const alertBox = document.getElementById('alertBox');

        alertBox.innerHTML = `
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                Fitur reset password belum tersedia. Silakan hubungi administrator.

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="Tutup"></button>
            </div>
        `;
    });
</script>

</body>
</html>

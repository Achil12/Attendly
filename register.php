
<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

if (attendly_is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$success = false;
$old = [
    'fullName' => '',
    'company' => '',
    'email' => '',
    'phone' => ''
];

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function createCompanySlug($pdo, $companyName) {
    $slug = strtolower(trim($companyName));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'company';
    }

    $baseSlug = $slug;
    $counter = 1;

    while (true) {
        $check = $pdo->prepare("SELECT id FROM companies WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);

        if (!$check->fetch()) {
            return $slug;
        }

        $counter++;
        $slug = $baseSlug . '-' . $counter;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old['fullName'] = trim($_POST['fullName'] ?? '');
    $old['company'] = trim($_POST['company'] ?? '');
    $old['email'] = strtolower(trim($_POST['email'] ?? ''));
    $old['phone'] = trim($_POST['phone'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $terms = isset($_POST['terms']);

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!attendly_verify_csrf($csrfToken)) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    if ($old['fullName'] === '') {
        $errors[] = 'Nama lengkap wajib diisi.';
    } elseif (mb_strlen($old['fullName']) > 150) {
        $errors[] = 'Nama lengkap maksimal 150 karakter.';
    }

    if ($old['company'] === '') {
        $errors[] = 'Nama perusahaan wajib diisi.';
    } elseif (mb_strlen($old['company']) > 180) {
        $errors[] = 'Nama perusahaan maksimal 180 karakter.';
    }

    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }

    if ($old['phone'] === '') {
        $errors[] = 'Nomor HP wajib diisi.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password minimal 8 karakter.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Konfirmasi password tidak sesuai.';
    }

    if (!$terms) {
        $errors[] = 'Anda harus menyetujui syarat dan ketentuan.';
    }

    if (empty($errors)) {
        try {
            // Periksa email
            $checkEmail = $pdo->prepare("
                SELECT id FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $checkEmail->execute([$old['email']]);

            if ($checkEmail->fetch()) {
                $errors[] = 'Email sudah terdaftar. Silakan gunakan email lain atau masuk.';
            } else {

                // Ambil paket Free Trial
                $planQuery = $pdo->prepare("
                    SELECT id
                    FROM plans
                    WHERE code = 'free_trial'
                    AND is_active = 1
                    LIMIT 1
                ");
                $planQuery->execute();
                $plan = $planQuery->fetch(PDO::FETCH_ASSOC);

                if (!$plan) {
                    throw new Exception('Paket Free Trial tidak ditemukan. Periksa data plans.');
                }

                // Ambil role Owner global
                $roleQuery = $pdo->prepare("
                    SELECT id
                    FROM roles
                    WHERE code = 'owner'
                    AND company_id IS NULL
                    LIMIT 1
                ");
                $roleQuery->execute();
                $ownerRole = $roleQuery->fetch(PDO::FETCH_ASSOC);

                if (!$ownerRole) {
                    throw new Exception('Role Owner tidak ditemukan. Periksa data roles.');
                }

                $pdo->beginTransaction();

                // Buat perusahaan
                $slug = createCompanySlug($pdo, $old['company']);

                $trialStart = date('Y-m-d H:i:s');
                $trialEnd = date('Y-m-d H:i:s', strtotime('+14 days'));

                $companyInsert = $pdo->prepare("
                    INSERT INTO companies (
                        name,
                        slug,
                        email,
                        phone,
                        status,
                        trial_ends_at
                    ) VALUES (?, ?, ?, ?, 'active', ?)
                ");

                $companyInsert->execute([
                    $old['company'],
                    $slug,
                    $old['email'],
                    $old['phone'],
                    $trialEnd
                ]);

                $companyId = $pdo->lastInsertId();

                // Buat akun pengguna
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $userInsert = $pdo->prepare("
                    INSERT INTO users (
                        company_id,
                        name,
                        email,
                        password_hash,
                        phone,
                        status
                    ) VALUES (?, ?, ?, ?, ?, 'active')
                ");

                $userInsert->execute([
                    $companyId,
                    $old['fullName'],
                    $old['email'],
                    $passwordHash,
                    $old['phone']
                ]);

                $userId = $pdo->lastInsertId();

                // Hubungkan akun dengan role Owner
                $userRoleInsert = $pdo->prepare("
                    INSERT INTO user_roles (
                        user_id,
                        role_id
                    ) VALUES (?, ?)
                ");

                $userRoleInsert->execute([
                    $userId,
                    $ownerRole['id']
                ]);

                // Aktifkan langganan Free Trial
                $subscriptionInsert = $pdo->prepare("
                    INSERT INTO subscriptions (
                        company_id,
                        plan_id,
                        status,
                        billing_cycle,
                        starts_at,
                        ends_at,
                        trial_ends_at
                    ) VALUES (
                        ?, ?, 'trialing', 'monthly', ?, ?, ?
                    )
                ");

                $subscriptionInsert->execute([
                    $companyId,
                    $plan['id'],
                    $trialStart,
                    $trialEnd,
                    $trialEnd
                ]);

                // Catat aktivitas registrasi
                $activityInsert = $pdo->prepare("
                    INSERT INTO activity_logs (
                        company_id,
                        user_id,
                        action,
                        module,
                        description,
                        ip_address,
                        user_agent
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $activityInsert->execute([
                    $companyId,
                    $userId,
                    'register',
                    'account',
                    'Perusahaan dan akun Owner berhasil didaftarkan.',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
                ]);

                $pdo->commit();

                $success = true;

                // Bersihkan data formulir
                $old = [
                    'fullName' => '',
                    'company' => '',
                    'email' => '',
                    'phone' => ''
                ];

            }
        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Attendly Register PDO Error: ' . $e->getMessage());

            $errors[] = 'Terjadi kesalahan database. Silakan coba kembali.';

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Attendly Register Error: ' . $e->getMessage());

            $errors[] = $e->getMessage();
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
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            min-height: 100vh;
            color: var(--navy);
        }

        a {
            text-decoration: none;
        }

        .register-wrapper {
            min-height: 100vh;
            display: flex;
        }

        .register-visual {
            width: 43%;
            min-height: 100vh;
            background: linear-gradient(145deg, #101c3a, #173b78 55%, #2563eb);
            color: white;
            padding: 50px 7%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .register-visual::before,
        .register-visual::after {
            content: "";
            position: absolute;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 50%;
        }

        .register-visual::before {
            width: 550px;
            height: 550px;
            right: -230px;
            top: -180px;
        }

        .register-visual::after {
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
            max-width: 490px;
            position: relative;
            z-index: 1;
        }

        .visual-content h1 {
            font-size: clamp(32px, 4vw, 47px);
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 20px;
        }

        .visual-content h1 span {
            color: #93c5fd;
        }

        .visual-content p {
            color: #cbd5e1;
            line-height: 1.8;
            font-size: 14px;
        }

        .visual-features {
            margin-top: 35px;
            display: flex;
            flex-direction: column;
            gap: 17px;
        }

        .visual-feature {
            display: flex;
            align-items: center;
            gap: 13px;
            color: #e2e8f0;
            font-size: 13px;
        }

        .visual-feature i {
            width: 31px;
            height: 31px;
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

        .register-form-section {
            flex: 1;
            display: flex;
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
            font-size: 24px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 30px;
        }

        .mobile-brand i {
            color: var(--primary);
            margin-right: 8px;
        }

        .register-heading {
            margin-bottom: 28px;
        }

        .register-heading h2 {
            font-size: 29px;
            font-weight: 800;
            margin-bottom: 9px;
        }

        .register-heading p {
            color: var(--muted);
            font-size: 13px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .form-control {
            height: 46px;
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 11px 13px;
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
            background: white;
            border: 1px solid var(--border);
            border-left: 0;
            border-radius: 0 9px 9px 0;
            color: var(--muted);
            cursor: pointer;
        }

        .form-check-input {
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-label {
            font-size: 11px;
            color: var(--muted);
            cursor: pointer;
            line-height: 1.6;
        }

        .form-check-label a {
            color: var(--primary);
            font-weight: 600;
        }

        .password-strength {
            height: 4px;
            background: #e2e8f0;
            border-radius: 10px;
            margin-top: 9px;
            overflow: hidden;
        }

        .password-strength span {
            display: block;
            height: 100%;
            width: 0;
            transition: .3s;
        }

        .strength-text {
            font-size: 10px;
            color: var(--muted);
            margin-top: 5px;
        }

        .btn-register {
            width: 100%;
            height: 48px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            margin-top: 23px;
            transition: .2s;
        }

        .btn-register:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-register:disabled {
            opacity: .7;
            cursor: not-allowed;
            transform: none;
        }

        .login-text {
            text-align: center;
            font-size: 12px;
            color: var(--muted);
            margin-top: 22px;
        }

        .login-text a {
            color: var(--primary);
            font-weight: 700;
        }

        .security-note {
            margin-top: 28px;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
        }

        .security-note i {
            color: #16a34a;
            margin-right: 5px;
        }

        .alert {
            font-size: 12px;
            border-radius: 9px;
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

    <section class="register-visual">

        <a href="login.php" class="brand">
            <i class="fa-solid fa-fingerprint"></i>Attendly
        </a>

        <div class="visual-content">

            <h1>
                Mulai Kelola Tim dengan <span>Lebih Cerdas.</span>
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
            © 2026 Attendly. All rights reserved.
        </div>

    </section>

    <section class="register-form-section">

        <div class="register-card">

            <a href="login.php" class="mobile-brand">
                <i class="fa-solid fa-fingerprint"></i>Attendly
            </a>

            <div class="register-heading">
                <h2>Buat Akun Baru</h2>
                <p>Daftarkan perusahaan Anda untuk mulai menggunakan Attendly.</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <div class="fw-bold mb-1">
                        <i class="fa-solid fa-circle-exclamation me-1"></i>
                        Pendaftaran gagal
                    </div>
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check me-1"></i>
                    <strong>Pendaftaran berhasil!</strong>
                    Perusahaan dan akun Owner Anda sudah dibuat.
                    Silakan <a href="login.php" class="fw-bold">masuk ke Attendly</a>.
                </div>
            <?php endif; ?>

            <?php if (!$success): ?>

            <form id="registerForm" method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
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
                            value="<?= e($old['fullName']) ?>"
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
                            value="<?= e($old['company']) ?>"
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
                            value="<?= e($old['email']) ?>"
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
                            value="<?= e($old['phone']) ?>"
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

                        <span class="input-group-text" id="togglePassword">
                            <i class="fa-regular fa-eye"></i>
                        </span>
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

                        <span class="input-group-text" id="toggleConfirmPassword">
                            <i class="fa-regular fa-eye"></i>
                        </span>
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

                <button type="submit" class="btn-register" id="registerButton">
                    <span id="registerButtonText">Buat Akun Sekarang</span>
                    <i class="fa-solid fa-arrow-right ms-2"></i>
                </button>

            </form>

            <?php endif; ?>

            <div class="login-text">
                Sudah memiliki akun?
                <a href="login.php">Masuk sekarang</a>
            </div>

            <div class="security-note">
                <i class="fa-solid fa-lock"></i>
                Informasi Anda diproses secara aman.
            </div>

        </div>

    </section>

</div>

<script>
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirmPassword');

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
                { width: '0%', label: 'Kekuatan password', color: '#e2e8f0' },
                { width: '25%', label: 'Lemah', color: '#ef4444' },
                { width: '50%', label: 'Cukup', color: '#f59e0b' },
                { width: '75%', label: 'Baik', color: '#3b82f6' },
                { width: '100%', label: 'Sangat kuat', color: '#16a34a' }
            ];

            bar.style.width = levels[score].width;
            bar.style.background = levels[score].color;
            text.textContent = levels[score].label;
        });
    }

    const registerForm = document.getElementById('registerForm');

    if (registerForm) {
        registerForm.addEventListener('submit', function (e) {
            if (password.value.length < 8) {
                e.preventDefault();
                alert('Password minimal harus 8 karakter.');
                password.focus();
                return;
            }

            if (password.value !== confirmPassword.value) {
                e.preventDefault();
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
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

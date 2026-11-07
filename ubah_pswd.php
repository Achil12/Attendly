<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/session.php';

attendly_require_auth();

$user = attendly_user();
$companyId = attendly_company_id();

if (empty($user['id'])) {
    http_response_code(403);
    exit('Data akun tidak valid.');
}

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['password_change_csrf'])) {
    $_SESSION['password_change_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['password_change_csrf'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!hash_equals($csrfToken, $token)) {
        $error = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Semua kolom wajib diisi.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'Password baru minimal 8 karakter.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Konfirmasi password tidak cocok.';
    } elseif ($currentPassword === $newPassword) {
        $error = 'Password baru tidak boleh sama dengan password lama.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, password_hash
                FROM users
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$user['id']]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
                $error = 'Password saat ini tidak benar.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $pdo->prepare("
                    UPDATE users
                    SET password_hash = ?
                    WHERE id = ?
                ");

                $update->execute([
                    $newHash,
                    $user['id']
                ]);

                $_SESSION['password_change_csrf'] = bin2hex(random_bytes(32));
                $csrfToken = $_SESSION['password_change_csrf'];

                $success = 'Password berhasil diperbarui.';
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan saat memperbarui password.';
        }
    }
}

$displayName = $user['name'] ?? $user['full_name'] ?? $user['email'] ?? 'Pengguna';
$roleName = 'Pengguna';

if (!empty($user['roles']) && is_array($user['roles'])) {
    $roleName = implode(', ', $user['roles']);
} elseif (!empty($user['role'])) {
    $roleName = $user['role'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ubah Password | Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3979f6;
            --sidebar: #17243a;
            --background: #f5f7fb;
            --muted: #8993a5;
            --border: #edf0f5;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: var(--background);
            color: #253047;
            font-size: 14px;
        }

        .main-content {
            max-width: 1100px;
            margin: auto;
            padding: 30px 20px;
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
            width: 42px;
            height: 42px;
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
            padding: 25px;
            height: 100%;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
        }

        .form-control {
            min-height: 45px;
            border-color: #e5e9f0;
            border-radius: 9px;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(57, 121, 246, .1);
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper .form-control {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #8993a5;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            border-radius: 9px;
            padding: 11px 18px;
            font-size: 13px;
            font-weight: 700;
        }

        .btn-light {
            border-radius: 9px;
            padding: 11px 18px;
            font-size: 13px;
            font-weight: 600;
        }

        .security-info {
            background: #eff5ff;
            border-radius: 10px;
            padding: 16px;
            color: #315fae;
            font-size: 12px;
            line-height: 1.8;
        }

        .account-info {
            border-bottom: 1px solid var(--border);
            padding: 13px 0;
            font-size: 12px;
        }

        .account-info:last-child {
            border-bottom: 0;
        }

        .account-label {
            color: var(--muted);
            margin-bottom: 5px;
        }

        .account-value {
            font-weight: 700;
        }

        @media(max-width: 576px) {
            .main-content {
                padding: 20px 14px;
            }

            .page-title {
                font-size: 19px;
            }

            .profile-info {
                display: none;
            }

            .card-box {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<main class="main-content">

    <div class="topbar">
        <div>
            <div class="page-title">
                <i class="fa-solid fa-lock text-primary me-2"></i>
                Ubah Password
            </div>
            <div class="page-subtitle">
                Kelola keamanan password akun Attendly Anda.
            </div>
        </div>

        <div class="profile">
            <div class="avatar">
                <?= e(strtoupper(substr($displayName, 0, 1))) ?>
            </div>

            <div class="profile-info">
                <div class="profile-name"><?= e($displayName) ?></div>
                <div class="profile-role"><?= e($roleName) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-7">
            <div class="card-box">

                <h5 class="fw-bold mb-1">Perbarui Password</h5>
                <p class="text-muted small mb-4">
                    Masukkan password lama dan password baru Anda.
                </p>

                <?php if ($success): ?>
                    <div class="alert alert-success small">
                        <i class="fa-solid fa-circle-check me-2"></i>
                        <?= e($success) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger small">
                        <i class="fa-solid fa-circle-exclamation me-2"></i>
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label">Password Saat Ini</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                name="current_password"
                                id="currentPassword"
                                class="form-control"
                                placeholder="Masukkan password saat ini"
                                required
                            >
                            <button type="button" class="toggle-password" data-target="currentPassword">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password Baru</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                name="new_password"
                                id="newPassword"
                                class="form-control"
                                placeholder="Minimal 8 karakter"
                                minlength="8"
                                required
                            >
                            <button type="button" class="toggle-password" data-target="newPassword">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Konfirmasi Password Baru</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                name="confirm_password"
                                id="confirmPassword"
                                class="form-control"
                                placeholder="Ulangi password baru"
                                minlength="8"
                                required
                            >
                            <button type="button" class="toggle-password" data-target="confirmPassword">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk me-2"></i>
                            Simpan Password
                        </button>

                        <button type="reset" class="btn btn-light">
                            Reset
                        </button>

                        <a href="javascript:history.back()" class="btn btn-light">
                            Kembali
                        </a>
                    </div>
                </form>

            </div>
        </div>

        <div class="col-lg-5">
            <div class="card-box">

                <h6 class="fw-bold mb-3">
                    <i class="fa-solid fa-shield-halved text-primary me-2"></i>
                    Keamanan Akun
                </h6>

                <div class="security-info">
                    <div class="mb-2">
                        <i class="fa-solid fa-check-circle me-2"></i>
                        Minimal 8 karakter.
                    </div>

                    <div class="mb-2">
                        <i class="fa-solid fa-check-circle me-2"></i>
                        Kombinasikan huruf besar, kecil, angka, dan simbol.
                    </div>

                    <div class="mb-2">
                        <i class="fa-solid fa-check-circle me-2"></i>
                        Jangan gunakan password yang sama dengan akun lain.
                    </div>

                    <div>
                        <i class="fa-solid fa-check-circle me-2"></i>
                        Jangan bagikan password kepada siapa pun.
                    </div>
                </div>

                <hr class="my-4">

                <h6 class="fw-bold mb-2">Informasi Akun</h6>

                <div class="account-info">
                    <div class="account-label">Nama Pengguna</div>
                    <div class="account-value"><?= e($displayName) ?></div>
                </div>

                <div class="account-info">
                    <div class="account-label">Email</div>
                    <div class="account-value"><?= e($user['email'] ?? '-') ?></div>
                </div>

                <div class="account-info">
                    <div class="account-label">Hak Akses</div>
                    <div class="account-value"><?= e($roleName) ?></div>
                </div>

            </div>
        </div>

    </div>

    <div class="text-center text-muted small mt-4">
        © <?= date('Y') ?> Attendly. All Rights Reserved.
    </div>

</main>

<script>
document.querySelectorAll('.toggle-password').forEach(function(button) {
    button.addEventListener('click', function() {
        const input = document.getElementById(this.dataset.target);
        const icon = this.querySelector('i');

        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});
</script>

</body>
</html>
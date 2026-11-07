
<?php
/**
 * Attendly SaaS
 * Session & Authentication Configuration
 */

//declare(strict_types=1);

// Zona waktu
date_default_timezone_set('Asia/Jakarta');

// Konfigurasi session cookie
$isHttps = (
    isset($_SERVER['HTTPS']) &&
    $_SERVER['HTTPS'] !== 'off'
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_name('ATTENDLY_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/**
 * Mengambil data user yang sedang login.
 */
function attendly_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Mengambil ID user.
 */
function attendly_user_id(): ?int
{
    $user = attendly_user();

    return isset($user['id']) ? (int) $user['id'] : null;
}

/**
 * Mengambil company_id user.
 */
function attendly_company_id(): ?int
{
    $user = attendly_user();

    return isset($user['company_id'])
        ? (int) $user['company_id']
        : null;
}

/**
 * Memeriksa status login.
 */
function attendly_is_logged_in(): bool
{
    $user = attendly_user();

    return $user !== null
        && !empty($user['id'])
        && !empty($user['company_id']);
}

/**
 * Memeriksa role user.
 */
function attendly_has_role(string $role): bool
{
    $user = attendly_user();

    if (!$user) {
        return false;
    }

    $roles = $user['roles'] ?? [];

    return in_array($role, $roles, true);
}

/**
 * Memeriksa apakah user memiliki salah satu role.
 */
function attendly_has_any_role(array $allowedRoles): bool
{
    foreach ($allowedRoles as $role) {
        if (attendly_has_role($role)) {
            return true;
        }
    }

    return false;
}

/**
 * Memastikan user sudah login.
 */
function attendly_require_auth(): void
{
    if (!attendly_is_logged_in()) {
        header('Location: /../login.php');
        exit;
    }
}

/**
 * Memastikan user mempunyai role tertentu.
 */
function attendly_require_role(array $allowedRoles): void
{
    attendly_require_auth();

    if (!attendly_has_any_role($allowedRoles)) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki akses ke halaman ini.');
    }
}

/**
 * Login dan menyimpan session.
 *
 * Panggil setelah username/password berhasil diverifikasi
 * dari database.
 */
function attendly_login(array $user, array $roles = []): void
{
    if (empty($user['id']) || empty($user['company_id'])) {
        throw new InvalidArgumentException(
            'Data user atau company_id tidak valid.'
        );
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'         => (int) $user['id'],
        'company_id' => (int) $user['company_id'],
        'name'       => $user['name'] ?? '',
        'email'      => $user['email'] ?? '',
        'roles'      => array_values($roles)
    ];

    $_SESSION['login_time'] = time();
}

/**
 * Logout.
 */
function attendly_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]
        );
    }

    session_destroy();
}

/**
 * Membuat token CSRF.
 */
function attendly_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Memverifikasi token CSRF.
 */
function attendly_verify_csrf(?string $token): bool
{
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Memastikan request memiliki CSRF token valid.
 */
function attendly_require_csrf(): void
{
    $token = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? null;

    if (!attendly_verify_csrf($token)) {
        http_response_code(419);
        exit('CSRF token tidak valid atau sudah kedaluwarsa.');
    }
}

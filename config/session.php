<?php
/**
 * Attendly SaaS
 * Session, Authentication & Multi-Tenant Security
 */

date_default_timezone_set('Asia/Jakarta');

/*
|--------------------------------------------------------------------------
| SESSION CONFIGURATION
|--------------------------------------------------------------------------
*/

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('ATTENDLY_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| USER SESSION
|--------------------------------------------------------------------------
*/

function attendly_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user'])
        ? $_SESSION['user']
        : null;
}

function attendly_user_id(): ?int
{
    $user = attendly_user();

    return !empty($user['id'])
        ? (int) $user['id']
        : null;
}

function attendly_account_company_id(): ?int
{
    $user = attendly_user();

    return !empty($user['company_id'])
        ? (int) $user['company_id']
        : null;
}

/*
|--------------------------------------------------------------------------
| ROLE & ACCESS CONTROL
|--------------------------------------------------------------------------
*/

function attendly_roles(): array
{
    $user = attendly_user();

    if (!$user) {
        return [];
    }

    $roles = $user['roles'] ?? [];

    if (!is_array($roles)) {
        return [];
    }

    return array_values(array_unique(array_filter(
        array_map(
            static fn($role) => strtolower(trim((string) $role)),
            $roles
        )
    )));
}

function attendly_has_role(string $role): bool
{
    return in_array(
        strtolower(trim($role)),
        attendly_roles(),
        true
    );
}

function attendly_has_any_role(array $allowedRoles): bool
{
    foreach ($allowedRoles as $role) {
        if (attendly_has_role((string) $role)) {
            return true;
        }
    }

    return false;
}

function attendly_is_super_admin(): bool
{
    return attendly_has_role('super admin');
}

function attendly_is_logged_in(): bool
{
    $user = attendly_user();

    return $user !== null
        && !empty($user['id'])
        && (
            attendly_account_company_id() !== null
            || attendly_is_super_admin()
        );
}

function attendly_require_auth(): void
{
    if (!attendly_is_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function attendly_require_role(array $allowedRoles): void
{
    attendly_require_auth();

    if (attendly_is_super_admin()) {
        return;
    }

    if (!attendly_has_any_role($allowedRoles)) {
        http_response_code(403);
        exit('403 - Anda tidak memiliki akses ke halaman ini.');
    }
}

function attendly_require_super_admin(): void
{
    attendly_require_auth();

    if (!attendly_is_super_admin()) {
        http_response_code(403);
        exit('403 - Akses khusus Super Admin.');
    }
}

/*
|--------------------------------------------------------------------------
| COMPANY CONTEXT
|--------------------------------------------------------------------------
*/

function attendly_company_id(): ?int
{
    if (attendly_is_super_admin()) {
        $activeCompanyId = $_SESSION['active_company_id'] ?? null;

        return !empty($activeCompanyId)
            ? (int) $activeCompanyId
            : null;
    }

    return attendly_account_company_id();
}

function attendly_has_company_context(): bool
{
    return attendly_company_id() !== null;
}

function attendly_set_company_context(
    PDO $pdo,
    int $companyId
): bool {
    attendly_require_super_admin();

    if ($companyId < 1) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$companyId]);

    $exists = $stmt->fetchColumn();

    if (!$exists) {
        return false;
    }

    $_SESSION['active_company_id'] = (int) $exists;
    $_SESSION['company_context_time'] = time();

    return true;
}

function attendly_clear_company_context(): void
{
    attendly_require_super_admin();

    unset(
        $_SESSION['active_company_id'],
        $_SESSION['company_context_time']
    );
}

function attendly_require_company_id(): int
{
    attendly_require_auth();

    $companyId = attendly_company_id();

    if (!$companyId || $companyId < 1) {
        http_response_code(403);

        if (attendly_is_super_admin()) {
            exit('Pilih perusahaan terlebih dahulu melalui Switch Company.');
        }

        exit('Akun ini tidak terhubung dengan perusahaan.');
    }

    return $companyId;
}

/*
|--------------------------------------------------------------------------
| SUBSCRIPTION VALIDATION
|--------------------------------------------------------------------------
*/

function attendly_subscription_active(int $companyId): bool
{
    global $pdo;

    if (
        !isset($pdo)
        || !($pdo instanceof PDO)
        || $companyId < 1
    ) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM subscriptions
        WHERE company_id = ?
          AND starts_at <= NOW()
          AND (
                (
                    status = 'active'
                    AND (
                        ends_at IS NULL
                        OR ends_at >= NOW()
                    )
                )
                OR
                (
                    status = 'trialing'
                    AND trial_ends_at IS NOT NULL
                    AND trial_ends_at >= NOW()
                )
          )
        ORDER BY
            CASE
                WHEN status = 'active' THEN 1
                WHEN status = 'trialing' THEN 2
                ELSE 3
            END,
            ends_at DESC,
            trial_ends_at DESC,
            id DESC
        LIMIT 1
    ");

    $stmt->execute([$companyId]);

    return (bool) $stmt->fetchColumn();
}

function attendly_company_active(int $companyId): bool
{
    global $pdo;

    if (
        !isset($pdo)
        || !($pdo instanceof PDO)
        || $companyId < 1
    ) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM companies
        WHERE id = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$companyId]);

    return (bool) $stmt->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| TENANT ACCESS
|--------------------------------------------------------------------------
*/

function attendly_require_tenant(): void
{
    attendly_require_auth();

    global $pdo;

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        http_response_code(500);
        exit('Koneksi database tidak tersedia.');
    }

    $companyId = attendly_require_company_id();

    /*
     * Super Admin dapat mengakses perusahaan
     * melalui Switch Company tanpa batas subscription.
     */
    if (attendly_is_super_admin()) {
        if (!attendly_company_active($companyId)) {
            http_response_code(403);
            exit('Perusahaan sedang tidak aktif.');
        }

        return;
    }

    if (!attendly_company_active($companyId)) {
        http_response_code(403);
        exit('Perusahaan sedang tidak aktif. Silakan hubungi Super Admin.');
    }

    if (!attendly_subscription_active($companyId)) {
        http_response_code(403);
        exit('Masa langganan perusahaan Anda telah berakhir. Silakan hubungi Super Admin untuk perpanjangan.');
    }
}

/*
|--------------------------------------------------------------------------
| COMPANY RECORD VALIDATION
|--------------------------------------------------------------------------
*/

function attendly_verify_company(
    PDO $pdo,
    string $table,
    int $recordId,
    ?int $companyId = null
): bool {
    $allowedTables = [
        'employees',
        'departments',
        'positions',
        'locations',
        'work_schedules',
        'attendances',
        'leave_requests',
        'employee_cards',
        'subscriptions'
    ];

    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Nama tabel tidak diizinkan.');
    }

    if ($recordId < 1) {
        return false;
    }

    $companyId = $companyId ?? attendly_require_company_id();

    if ($companyId < 1) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM `$table`
        WHERE id = ?
          AND company_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $recordId,
        $companyId
    ]);

    return (bool) $stmt->fetchColumn();
}

function attendly_require_company_record(
    PDO $pdo,
    string $table,
    int $recordId
): void {
    if (!attendly_verify_company($pdo, $table, $recordId)) {
        http_response_code(404);
        exit('Data tidak ditemukan atau bukan milik perusahaan Anda.');
    }
}

/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

function attendly_login(array $user, array $roles = []): void
{
    if (empty($user['id'])) {
        throw new InvalidArgumentException('ID user tidak valid.');
    }

    $normalizedRoles = array_values(array_unique(array_filter(
        array_map(
            static fn($role) => strtolower(trim((string) $role)),
            $roles
        )
    )));

    $isSuperAdmin = in_array(
        'super admin',
        $normalizedRoles,
        true
    );

    if (empty($user['company_id']) && !$isSuperAdmin) {
        throw new InvalidArgumentException(
            'Akun bukan Super Admin harus terhubung dengan perusahaan.'
        );
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id' => (int) $user['id'],

        'company_id' => !empty($user['company_id'])
            ? (int) $user['company_id']
            : null,

        'name' => $user['name'] ?? '',
        'email' => $user['email'] ?? '',
        'roles' => $normalizedRoles
    ];

    unset(
        $_SESSION['active_company_id'],
        $_SESSION['company_context_time']
    );

    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

function attendly_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

function attendly_csrf_token(): string
{
    if (
        empty($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function attendly_verify_csrf(?string $token): bool
{
    if (
        !is_string($token)
        || $token === ''
        || empty($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
    ) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

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

/*
|--------------------------------------------------------------------------
| SESSION TIMEOUT
|--------------------------------------------------------------------------
*/

function attendly_check_session_timeout(
    int $timeout = 7200
): void {
    if (!attendly_is_logged_in()) {
        return;
    }

    $timeout = max(60, $timeout);

    $lastActivity = (int) (
        $_SESSION['last_activity']
        ?? $_SESSION['login_time']
        ?? time()
    );

    if ((time() - $lastActivity) > $timeout) {
        attendly_logout();

        header('Location: /login.php?expired=1');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

/*
|--------------------------------------------------------------------------
| INITIAL SESSION CHECK
|--------------------------------------------------------------------------
*/

attendly_check_session_timeout();
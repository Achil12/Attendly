
<?php
/**
 * Attendly - RFID Attendance Kiosk
 * Public kiosk, tanpa login.
 * USB RFID HID -> PHP -> MySQL langsung.
 */

date_default_timezone_set('Asia/Jakarta');

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| KONFIGURASI KIOSK
|--------------------------------------------------------------------------
| Ganti angka 1 sesuai companies.id perusahaan.
*/
const KIOSK_COMPANY_ID = 1;
const RFID_MAX_FAILED_ATTEMPTS = 3;
const RFID_LOCK_SECONDS = 60;

require_once dirname(__DIR__) . '/config/db.php';

/*
|--------------------------------------------------------------------------
| SESSION RFID
|--------------------------------------------------------------------------
| Session hanya digunakan untuk menghitung percobaan gagal.
| Bukan untuk autentikasi akun.
*/
if (!isset($_SESSION['rfid_failed_attempts'])) {
    $_SESSION['rfid_failed_attempts'] = 0;
}

if (!isset($_SESSION['rfid_locked_until'])) {
    $_SESSION['rfid_locked_until'] = 0;
}

if (empty($_SESSION['kiosk_csrf'])) {
    $_SESSION['kiosk_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['kiosk_csrf'];

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/
function kiosk_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function kiosk_check_limit(): array
{
    $now = time();
    $lockedUntil = (int) ($_SESSION['rfid_locked_until'] ?? 0);

    if ($lockedUntil > $now) {
        return [
            'allowed' => false,
            'remaining' => $lockedUntil - $now
        ];
    }

    if ($lockedUntil > 0 && $lockedUntil <= $now) {
        $_SESSION['rfid_locked_until'] = 0;
        $_SESSION['rfid_failed_attempts'] = 0;
    }

    return [
        'allowed' => true,
        'remaining' => 0
    ];
}

function kiosk_failed_attempt(): array
{
    $_SESSION['rfid_failed_attempts'] =
        (int) ($_SESSION['rfid_failed_attempts'] ?? 0) + 1;

    $failed = $_SESSION['rfid_failed_attempts'];

    if ($failed >= RFID_MAX_FAILED_ATTEMPTS) {
        $_SESSION['rfid_locked_until'] =
            time() + RFID_LOCK_SECONDS;

        return [
            'locked' => true,
            'attempts_remaining' => 0,
            'remaining' => RFID_LOCK_SECONDS
        ];
    }

    return [
        'locked' => false,
        'attempts_remaining' => RFID_MAX_FAILED_ATTEMPTS - $failed,
        'remaining' => 0
    ];
}

function kiosk_reset_attempts(): void
{
    $_SESSION['rfid_failed_attempts'] = 0;
    $_SESSION['rfid_locked_until'] = 0;
}

function kiosk_event(
    PDO $pdo,
    int $companyId,
    ?int $employeeId,
    string $uid,
    string $eventType,
    string $eventAt,
    string $processingStatus,
    string $message
): void {
    $stmt = $pdo->prepare("
        INSERT INTO attendance_events (
            company_id,
            employee_id,
            card_uid,
            event_type,
            event_at,
            payload,
            processing_status,
            processing_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $companyId,
        $employeeId,
        $uid,
        $eventType,
        $eventAt,
        json_encode([
            'source' => 'laptop_usb_rfid',
            'mode' => 'kiosk'
        ]),
        $processingStatus,
        $message
    ]);
}

function kiosk_photo(?string $photo): ?string
{
    if (!$photo) {
        return null;
    }

    $photo = trim($photo);

    if ($photo === '') {
        return null;
    }

    // Jangan mengubah URL absolut.
    if (
        preg_match('~^https?://~i', $photo) ||
        str_starts_with($photo, 'data:')
    ) {
        return $photo;
    }

    return '../' . ltrim($photo, '/');
}

/*
|--------------------------------------------------------------------------
| KONEKSI DATABASE DAN PERUSAHAAN
|--------------------------------------------------------------------------
*/
try {
    $companyStmt = $pdo->prepare("
        SELECT id, name, timezone, status
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    $companyStmt->execute([KIOSK_COMPANY_ID]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$company || $company['status'] !== 'active') {
        throw new RuntimeException('Perusahaan tidak aktif atau tidak ditemukan.');
    }

    $companyName = $company['name'];

    if (!empty($company['timezone'])) {
        date_default_timezone_set($company['timezone']);
    }

} catch (Throwable $e) {
    error_log('[Attendly Kiosk] ' . $e->getMessage());

    $companyName = 'Attendly';
    $company = null;
}

/*
|--------------------------------------------------------------------------
| HEALTH CHECK - MASIH DALAM FILE YANG SAMA
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    ($_GET['action'] ?? '') === 'health'
) {
    try {
        if (!$company) {
            throw new RuntimeException('Perusahaan tidak tersedia.');
        }

        $pdo->query('SELECT 1');

        kiosk_json([
            'success' => true,
            'message' => 'Database terhubung.',
            'company' => $companyName
        ]);

    } catch (Throwable $e) {
        error_log('[Attendly Kiosk Health] ' . $e->getMessage());

        kiosk_json([
            'success' => false,
            'message' => 'Database tidak dapat dihubungi.'
        ], 503);
    }
}

/*
|--------------------------------------------------------------------------
| PROSES SCAN RFID
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $limit = kiosk_check_limit();

    if (!$limit['allowed']) {
        kiosk_json([
            'success' => false,
            'locked' => true,
            'remaining' => $limit['remaining'],
            'message' => 'Terlalu banyak percobaan gagal. Silakan tunggu.'
        ], 429);
    }

    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true);

    if (!is_array($input)) {
        $attempt = kiosk_failed_attempt();

        kiosk_json([
            'success' => false,
            'locked' => $attempt['locked'],
            'attempts_remaining' => $attempt['attempts_remaining'],
            'remaining' => $attempt['remaining'],
            'message' => 'Data scan tidak valid.'
        ], 400);
    }

    $token = (string) ($input['csrf_token'] ?? '');

    if (
        !hash_equals(
            (string) $_SESSION['kiosk_csrf'],
            $token
        )
    ) {
        kiosk_json([
            'success' => false,
            'message' => 'Permintaan tidak valid. Muat ulang halaman.'
        ], 403);
    }

    $uid = strtoupper(trim((string) ($input['uid'] ?? '')));

    /*
     * UID umum RFID terdiri dari angka dan huruf.
     * Tanda titik dua dan strip juga diperbolehkan.
     */
    if (
        $uid === '' ||
        strlen($uid) < 4 ||
        strlen($uid) > 64 ||
        !preg_match('/^[A-Z0-9:-]+$/', $uid)
    ) {
        $attempt = kiosk_failed_attempt();

        kiosk_json([
            'success' => false,
            'locked' => $attempt['locked'],
            'attempts_remaining' => $attempt['attempts_remaining'],
            'remaining' => $attempt['remaining'],
            'message' => 'Format UID RFID tidak valid.'
        ], 400);
    }

    if (!$company) {
        kiosk_json([
            'success' => false,
            'message' => 'Perusahaan tidak tersedia.'
        ], 503);
    }

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    try {
        $pdo->beginTransaction();

        /*
         * Cari kartu berdasarkan UID dan perusahaan.
         * Kartu harus aktif dan belum dicabut.
         */
        $employeeStmt = $pdo->prepare("
            SELECT
                ec.id AS card_id,
                ec.card_uid,
                ec.status AS card_status,
                ec.revoked_at,
                e.id AS employee_id,
                e.company_id,
                e.nik,
                e.name,
                e.email,
                e.photo_path,
                e.photo,
                e.status AS employee_status,
                d.name AS department_name
            FROM employee_cards ec
            INNER JOIN employees e
                ON e.id = ec.employee_id
                AND e.company_id = ec.company_id
            LEFT JOIN departments d
                ON d.id = e.department_id
                AND d.company_id = e.company_id
            WHERE
                ec.company_id = ?
                AND ec.card_uid = ?
                AND ec.status = 'active'
                AND ec.revoked_at IS NULL
                AND e.status = 'active'
            LIMIT 1
            FOR UPDATE
        ");

        $employeeStmt->execute([
            KIOSK_COMPANY_ID,
            $uid
        ]);

        $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

        /*
         * UID tidak dikenal atau kartu tidak aktif.
         * Catat event dan hitung kegagalan.
         */
        if (!$employee) {
            kiosk_event(
                $pdo,
                KIOSK_COMPANY_ID,
                null,
                $uid,
                'unknown_card',
                $now,
                'rejected',
                'Kartu RFID tidak dikenal atau tidak aktif.'
            );

            $pdo->commit();

            $attempt = kiosk_failed_attempt();

            kiosk_json([
                'success' => false,
                'locked' => $attempt['locked'],
                'attempts_remaining' => $attempt['attempts_remaining'],
                'remaining' => $attempt['remaining'],
                'message' => $attempt['locked']
                    ? '3 kali percobaan gagal. Kiosk dikunci selama 60 detik.'
                    : 'Kartu tidak terdaftar atau sudah dinonaktifkan.'
            ], $attempt['locked'] ? 429 : 403);
        }

        $employeeId = (int) $employee['employee_id'];

        /*
         * Cari absensi karyawan hari ini.
         */
        $attendanceStmt = $pdo->prepare("
            SELECT
                id,
                attendance_date,
                clock_in_at,
                clock_out_at,
                status,
                work_minutes
            FROM attendances
            WHERE
                company_id = ?
                AND employee_id = ?
                AND attendance_date = ?
            LIMIT 1
            FOR UPDATE
        ");

        $attendanceStmt->execute([
            KIOSK_COMPANY_ID,
            $employeeId,
            $today
        ]);

        $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);

        $statusLabel = '';
        $message = '';
        $eventType = '';

        /*
         * SCAN PERTAMA = CLOCK IN
         */
        if (!$attendance) {
            $insertAttendance = $pdo->prepare("
                INSERT INTO attendances (
                    company_id,
                    employee_id,
                    attendance_date,
                    clock_in_at,
                    clock_in_method,
                    late_minutes,
                    work_minutes,
                    status,
                    notes
                ) VALUES (?, ?, ?, ?, 'rfid', 0, 0, 'present', ?)
            ");

            $insertAttendance->execute([
                KIOSK_COMPANY_ID,
                $employeeId,
                $today,
                $now,
                'Absensi masuk melalui RFID laptop.'
            ]);

            $eventType = 'clock_in';
            $statusLabel = 'Absen Masuk';
            $message = 'Selamat bekerja! Absensi masuk berhasil dicatat.';

        /*
         * SCAN KEDUA = CLOCK OUT
         */
        } elseif (
            !empty($attendance['clock_in_at']) &&
            empty($attendance['clock_out_at'])
        ) {
            $clockIn = new DateTime($attendance['clock_in_at']);
            $clockOut = new DateTime($now);

            $workMinutes = max(
                0,
                (int) floor(
                    ($clockOut->getTimestamp() - $clockIn->getTimestamp()) / 60
                )
            );

            $updateAttendance = $pdo->prepare("
                UPDATE attendances
                SET
                    clock_out_at = ?,
                    clock_out_method = 'rfid',
                    work_minutes = ?,
                    notes = CONCAT(
                        COALESCE(notes, ''),
                        CASE
                            WHEN notes IS NULL OR notes = '' THEN ''
                            ELSE '\n'
                        END,
                        'Absensi pulang melalui RFID laptop.'
                    )
                WHERE
                    id = ?
                    AND company_id = ?
                    AND employee_id = ?
            ");

            $updateAttendance->execute([
                $now,
                $workMinutes,
                $attendance['id'],
                KIOSK_COMPANY_ID,
                $employeeId
            ]);

            $eventType = 'clock_out';
            $statusLabel = 'Absen Pulang';
            $message = 'Absensi pulang berhasil dicatat. Terima kasih!';
        } else {
            /*
             * Sudah masuk dan pulang.
             * Bukan kegagalan UID, sehingga tidak menambah hitungan gagal.
             */
            kiosk_event(
                $pdo,
                KIOSK_COMPANY_ID,
                $employeeId,
                $uid,
                'manual',
                $now,
                'rejected',
                'Absensi hari ini sudah lengkap.'
            );

            $pdo->commit();

            kiosk_json([
                'success' => false,
                'message' => 'Absensi hari ini sudah lengkap. Anda sudah melakukan absen masuk dan pulang.',
                'employee' => [
                    'name' => $employee['name'],
                    'nik' => $employee['nik']
                ]
            ], 409);
        }

        /*
         * Catat event RFID berhasil.
         */
        kiosk_event(
            $pdo,
            KIOSK_COMPANY_ID,
            $employeeId,
            $uid,
            $eventType,
            $now,
            'processed',
            $message
        );

        $pdo->commit();

        // UID valid menghapus hitungan kegagalan.
        kiosk_reset_attempts();

        $photo = $employee['photo_path'] ?: $employee['photo'];

        kiosk_json([
            'success' => true,
            'message' => $message,
            'employee' => [
                'id' => $employeeId,
                'name' => $employee['name'],
                'nik' => $employee['nik'],
                'department' => $employee['department_name'] ?: '-',
                'photo' => kiosk_photo($photo)
            ],
            'attendance' => [
                'status_label' => $statusLabel,
                'time' => date('H:i:s', strtotime($now)),
                'date' => $today,
                'event' => $eventType
            ],
            'attempts_remaining' => RFID_MAX_FAILED_ATTEMPTS
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[Attendly RFID] ' . $e->getMessage());

        kiosk_json([
            'success' => false,
            'message' => 'Terjadi kesalahan saat menyimpan absensi. Silakan coba kembali.'
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| DATA HALAMAN
|--------------------------------------------------------------------------
*/
if (!$company) {
    http_response_code(503);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101b35">
    <meta name="description" content="Attendly RFID Attendance Kiosk">
    <title>RFID Attendance | Attendly</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3979f6;
            --navy: #101b35;
            --navy-light: #172746;
            --muted: #91a1bd;
            --green: #21c58e;
            --red: #f16b76;
            --yellow: #f4b942;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 50% 30%, rgba(57,121,246,.15), transparent 35%),
                linear-gradient(135deg, #0d172e, #142442 55%, #101b35);
            color: #fff;
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }

        .kiosk {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 25px 5%;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 45px;
            height: 45px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: var(--primary);
            font-size: 20px;
            box-shadow: 0 8px 25px rgba(57,121,246,.3);
        }

        .brand-name {
            font-size: 21px;
            font-weight: 900;
            letter-spacing: -.8px;
        }

        .brand-name span {
            color: #76a3ff;
        }

        .brand-subtitle {
            font-size: 10px;
            color: var(--muted);
            margin-top: 3px;
        }

        .system-status {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 10px 15px;
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 30px;
            background: rgba(255,255,255,.04);
            color: #c4d0e3;
            font-size: 10px;
            font-weight: 600;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--yellow);
            box-shadow: 0 0 10px currentColor;
        }

        .status-dot.online {
            background: var(--green);
        }

        .status-dot.offline {
            background: var(--red);
        }

        .main-content {
            width: 100%;
            max-width: 1100px;
            margin: auto;
            padding: 55px 0;
        }

        .welcome {
            text-align: center;
            margin-bottom: 35px;
        }

        .welcome-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 13px;
            border-radius: 30px;
            background: rgba(57,121,246,.12);
            border: 1px solid rgba(57,121,246,.2);
            color: #91b4ff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 17px;
        }

        .welcome h1 {
            font-size: clamp(27px, 4vw, 43px);
            font-weight: 900;
            letter-spacing: -1.5px;
            margin: 0;
        }

        .welcome p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 12px;
        }

        .clock {
            text-align: center;
            margin: 30px 0 35px;
        }

        .clock-time {
            font-size: clamp(42px, 7vw, 70px);
            line-height: 1;
            font-weight: 800;
            letter-spacing: -3px;
            font-variant-numeric: tabular-nums;
        }

        .clock-date {
            color: #9eafc9;
            font-size: 12px;
            margin-top: 13px;
        }

        .attendance-card {
            max-width: 680px;
            margin: auto;
            background: rgba(255,255,255,.055);
            border: 1px solid rgba(255,255,255,.11);
            border-radius: 23px;
            padding: 35px;
            text-align: center;
            box-shadow: 0 25px 80px rgba(0,0,0,.18);
            backdrop-filter: blur(15px);
        }

        .scan-animation {
            width: 112px;
            height: 112px;
            margin: 0 auto 23px;
            position: relative;
            display: grid;
            place-items: center;
        }

        .scan-ring {
            position: absolute;
            inset: 0;
            border: 1px solid rgba(91,151,255,.4);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .scan-ring:nth-child(2) {
            inset: 10px;
            animation-delay: .5s;
        }

        .scan-icon {
            width: 75px;
            height: 75px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: rgba(57,121,246,.18);
            color: #80aaff;
            font-size: 31px;
            border: 1px solid rgba(91,151,255,.3);
        }

        @keyframes pulse {
            0% {
                transform: scale(.85);
                opacity: .8;
            }
            100% {
                transform: scale(1.35);
                opacity: 0;
            }
        }

        .scan-title {
            font-size: 19px;
            font-weight: 800;
            margin-bottom: 9px;
        }

        .scan-description {
            color: #9eafc9;
            font-size: 11px;
            line-height: 1.8;
        }

        .scan-instruction {
            margin-top: 25px;
            padding: 13px;
            border-radius: 10px;
            background: rgba(57,121,246,.09);
            color: #a8c4ff;
            font-size: 10px;
            font-weight: 600;
        }

        .scan-instruction i {
            margin-right: 7px;
        }

        .reader-button {
            margin-top: 17px;
            border: 1px solid rgba(255,255,255,.15);
            background: rgba(255,255,255,.06);
            color: #d7e3f6;
            padding: 10px 17px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
        }

        .reader-button:hover {
            background: rgba(255,255,255,.12);
            color: white;
        }

        .result-area {
            display: none;
            margin-top: 25px;
            padding: 20px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 14px;
            text-align: left;
        }

        .result-area.show {
            display: block;
            animation: appear .3s ease;
        }

        @keyframes appear {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .result-profile {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .result-photo {
            width: 55px;
            height: 55px;
            border-radius: 13px;
            display: grid;
            place-items: center;
            background: rgba(57,121,246,.2);
            color: #a9c6ff;
            font-size: 20px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .result-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .result-name {
            font-size: 15px;
            font-weight: 800;
        }

        .result-detail {
            font-size: 10px;
            color: var(--muted);
            margin-top: 5px;
        }

        .result-status {
            margin-left: auto;
            font-size: 10px;
            font-weight: 800;
            padding: 7px 10px;
            border-radius: 20px;
            background: rgba(33,197,142,.13);
            color: var(--green);
            white-space: nowrap;
        }

        .result-status.error {
            color: var(--red);
            background: rgba(241,107,118,.13);
        }

        .result-message {
            margin-top: 17px;
            border-top: 1px solid rgba(255,255,255,.1);
            padding-top: 15px;
            font-size: 11px;
            color: #d7e3f6;
            line-height: 1.7;
        }

        .result-time {
            color: var(--green);
            font-size: 19px;
            font-weight: 800;
            margin-top: 5px;
        }

        .bottom-info {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .info-chip {
            padding: 9px 13px;
            border-radius: 8px;
            background: rgba(255,255,255,.045);
            color: #a7b7d0;
            font-size: 10px;
        }

        .info-chip i {
            color: #79a5ff;
            margin-right: 6px;
        }

        .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            color: #71829e;
            font-size: 10px;
            border-top: 1px solid rgba(255,255,255,.08);
            padding-top: 20px;
        }

        .footer strong {
            color: #9aafd0;
        }

        #rfidInput {
            position: fixed;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
            left: -100px;
            top: -100px;
        }

        .processing .scan-icon {
            color: var(--yellow);
            background: rgba(244,185,66,.15);
        }

        .processing .scan-ring {
            border-color: rgba(244,185,66,.4);
        }

        .success .scan-icon {
            color: var(--green);
            background: rgba(33,197,142,.15);
        }

        .error-state .scan-icon {
            color: var(--red);
            background: rgba(241,107,118,.15);
        }

        .locked .scan-icon {
            color: var(--red);
            background: rgba(241,107,118,.15);
        }

        @media (max-width: 600px) {
            .kiosk {
                padding: 18px 15px;
            }

            .main-content {
                padding: 45px 0;
            }

            .attendance-card {
                padding: 25px 18px;
            }

            .brand-name {
                font-size: 18px;
            }

            .brand-icon {
                width: 39px;
                height: 39px;
            }

            .system-status {
                padding: 8px 10px;
                font-size: 9px;
            }

            .result-profile {
                flex-wrap: wrap;
            }

            .result-status {
                margin-left: 0;
            }

            .footer {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>

<body>

<div class="kiosk">

    <header class="topbar">
        <div class="brand">
            <div class="brand-icon">
                <i class="fa-solid fa-fingerprint"></i>
            </div>

            <div>
                <div class="brand-name">Attend<span>ly</span></div>
                <div class="brand-subtitle">Smart Attendance System</div>
            </div>
        </div>

        <div class="system-status">
            <span class="status-dot" id="connectionDot"></span>
            <span id="connectionText">Memeriksa koneksi...</span>
        </div>
    </header>

    <main class="main-content">

        <section class="welcome">
            <div class="welcome-label">
                <i class="fa-solid fa-building"></i>
                <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <h1>Selamat Datang</h1>
            <p>Silakan lakukan absensi kehadiran Anda.</p>
        </section>

        <section class="clock">
            <div class="clock-time" id="clockTime">00:00:00</div>
            <div class="clock-date" id="clockDate">Memuat tanggal...</div>
        </section>

        <section class="attendance-card" id="attendanceCard">

            <div class="scan-animation">
                <div class="scan-ring"></div>
                <div class="scan-ring"></div>

                <div class="scan-icon">
                    <i class="fa-solid fa-id-card" id="scanIcon"></i>
                </div>
            </div>

            <div class="scan-title" id="scanTitle">
                Tempelkan Kartu RFID Anda
            </div>

            <div class="scan-description" id="scanDescription">
                Dekatkan kartu identitas ke perangkat pembaca RFID.<br>
                Sistem akan mengenali identitas Anda secara otomatis.
            </div>

            <div class="scan-instruction">
                <i class="fa-solid fa-wifi"></i>
                Pastikan kartu berada dekat dengan RFID Reader.
            </div>

            <button type="button" class="reader-button" id="activateReader">
                <i class="fa-solid fa-keyboard me-1"></i>
                Aktifkan RFID Reader
            </button>

            <div class="result-area" id="resultArea">

                <div class="result-profile">
                    <div class="result-photo" id="resultPhoto">
                        <i class="fa-solid fa-user"></i>
                    </div>

                    <div>
                        <div class="result-name" id="resultName">-</div>
                        <div class="result-detail" id="resultNik">NIK: -</div>
                        <div class="result-detail" id="resultDepartment">
                            Departemen: -
                        </div>
                    </div>

                    <div class="result-status" id="resultStatus">-</div>
                </div>

                <div class="result-message" id="resultMessage">-</div>
                <div class="result-time" id="resultTime"></div>

            </div>

        </section>

        <div class="bottom-info">
            <div class="info-chip">
                <i class="fa-solid fa-clock"></i>
                Absensi Real-time
            </div>

            <div class="info-chip">
                <i class="fa-solid fa-shield-halved"></i>
                Secure Attendance
            </div>

            <div class="info-chip">
                <i class="fa-solid fa-id-card"></i>
                RFID Enabled
            </div>
        </div>

    </main>

    <footer class="footer">
        <div>Powered by <strong>Attendly</strong></div>
        <div id="deviceInfo">Attendance Terminal · Laptop RFID Mode</div>
    </footer>

</div>

<input
    type="text"
    id="rfidInput"
    autocomplete="off"
    autocapitalize="off"
    spellcheck="false"
>

<script>
/* =========================================================
   CONFIG
========================================================= */

const KIOSK_URL = window.location.pathname;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

const rfidInput = document.getElementById('rfidInput');
const attendanceCard = document.getElementById('attendanceCard');

let scanLocked = false;
let resultTimer = null;
let lockTimer = null;
let lockedUntil = 0;

/* =========================================================
   DIGITAL CLOCK
========================================================= */

function updateClock() {
    const now = new Date();

    document.getElementById('clockTime').textContent =
        now.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });

    document.getElementById('clockDate').textContent =
        now.toLocaleDateString('id-ID', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
}

updateClock();
setInterval(updateClock, 1000);

/* =========================================================
   SERVER CONNECTION
========================================================= */

async function checkConnection() {
    const dot = document.getElementById('connectionDot');
    const text = document.getElementById('connectionText');

    try {
        const response = await fetch(
            KIOSK_URL + '?action=health',
            {
                method: 'GET',
                cache: 'no-store'
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error('Database tidak terhubung.');
        }

        dot.className = 'status-dot online';
        text.textContent = 'Database Terhubung';

    } catch (error) {
        dot.className = 'status-dot offline';
        text.textContent = 'Database Tidak Terhubung';
    }
}

checkConnection();
setInterval(checkConnection, 30000);

/* =========================================================
   RFID READER
========================================================= */

function activateReader() {
    if (lockedUntil > Date.now()) {
        return;
    }

    if (!scanLocked) {
        rfidInput.focus();
    }
}

document.getElementById('activateReader')
    .addEventListener('click', activateReader);

document.addEventListener('click', function(event) {
    if (
        !event.target.closest('button') &&
        !event.target.closest('a')
    ) {
        activateReader();
    }
});

window.addEventListener('load', activateReader);

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        activateReader();
    }
});

rfidInput.addEventListener('keydown', function(event) {
    if (event.key !== 'Enter') {
        return;
    }

    event.preventDefault();

    const uid = rfidInput.value.trim();
    rfidInput.value = '';

    if (!uid) {
        return;
    }

    processRFID(uid);
});

/* =========================================================
   PROCESS RFID
========================================================= */

async function processRFID(uid) {
    if (scanLocked || lockedUntil > Date.now()) {
        return;
    }

    scanLocked = true;
    clearTimeout(resultTimer);

    document.getElementById('resultArea').classList.remove('show');

    attendanceCard.classList.remove('success', 'error-state', 'locked');
    attendanceCard.classList.add('processing');

    document.getElementById('scanIcon').className =
        'fa-solid fa-spinner fa-spin';

    document.getElementById('scanTitle').textContent =
        'Memproses Kartu...';

    document.getElementById('scanDescription').textContent =
        'Sedang memverifikasi UID dan status kehadiran Anda.';

    try {
        const response = await fetch(KIOSK_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                uid: uid,
                csrf_token: CSRF_TOKEN
            }),
            cache: 'no-store'
        });

        const data = await response.json();

        if (data.locked) {
            startLockCountdown(data.remaining || 60);
            showError(data.message || 'Kiosk dikunci sementara.');
            return;
        }

        if (!response.ok || !data.success) {
            showError(data.message || 'Absensi tidak dapat diproses.');

            if (data.attempts_remaining !== undefined) {
                document.getElementById('scanDescription').textContent =
                    data.message +
                    ' Sisa percobaan: ' +
                    data.attempts_remaining;
            }

            return;
        }

        showSuccess(data);

    } catch (error) {
        showError(
            error.message || 'Tidak dapat menghubungi server.'
        );

    } finally {
        scanLocked = false;

        if (lockedUntil <= Date.now()) {
            resultTimer = setTimeout(resetScreen, 7000);
            activateReader();
        }
    }
}

/* =========================================================
   SUCCESS
========================================================= */

function showSuccess(data) {
    const employee = data.employee || {};
    const attendance = data.attendance || {};

    attendanceCard.classList.remove('processing', 'error-state', 'locked');
    attendanceCard.classList.add('success');

    document.getElementById('scanIcon').className =
        'fa-solid fa-check';

    document.getElementById('scanTitle').textContent =
        'Absensi Berhasil!';

    document.getElementById('scanDescription').textContent =
        'Data kehadiran Anda berhasil diproses.';

    document.getElementById('resultName').textContent =
        employee.name || 'Karyawan';

    document.getElementById('resultNik').textContent =
        'NIK: ' + (employee.nik || '-');

    document.getElementById('resultDepartment').textContent =
        'Departemen: ' + (employee.department || '-');

    const photo = document.getElementById('resultPhoto');
    photo.innerHTML = '';

    if (employee.photo) {
        const image = document.createElement('img');
        image.src = employee.photo;
        image.alt = 'Foto karyawan';
        image.onerror = function() {
            photo.innerHTML = '<i class="fa-solid fa-user"></i>';
        };
        photo.appendChild(image);
    } else {
        photo.innerHTML = '<i class="fa-solid fa-user"></i>';
    }

    const status = document.getElementById('resultStatus');
    status.className = 'result-status';
    status.textContent = attendance.status_label || 'Berhasil';

    document.getElementById('resultMessage').textContent =
        data.message || 'Absensi berhasil dicatat.';

    document.getElementById('resultTime').textContent =
        attendance.time || new Date().toLocaleTimeString('id-ID');

    document.getElementById('resultArea').classList.add('show');

    playTone(850, 150);
}

/* =========================================================
   ERROR
========================================================= */

function showError(message) {
    attendanceCard.classList.remove('processing', 'success');
    attendanceCard.classList.add('error-state');

    document.getElementById('scanIcon').className =
        'fa-solid fa-xmark';

    document.getElementById('scanTitle').textContent =
        'Absensi Gagal';

    document.getElementById('scanDescription').textContent =
        message;

    document.getElementById('resultName').textContent =
        'Kartu tidak dapat diproses';

    document.getElementById('resultNik').textContent =
        'Silakan periksa kartu RFID Anda';

    document.getElementById('resultDepartment').textContent = '';

    document.getElementById('resultPhoto').innerHTML =
        '<i class="fa-solid fa-triangle-exclamation"></i>';

    const status = document.getElementById('resultStatus');
    status.className = 'result-status error';
    status.textContent = 'Gagal';

    document.getElementById('resultMessage').textContent = message;
    document.getElementById('resultTime').textContent = '';

    document.getElementById('resultArea').classList.add('show');

    playTone(300, 250);
}

/* =========================================================
   LOCK COUNTDOWN
========================================================= */

function startLockCountdown(seconds) {
    clearInterval(lockTimer);
    clearTimeout(resultTimer);

    lockedUntil = Date.now() + (seconds * 1000);

    attendanceCard.classList.remove('processing', 'success');
    attendanceCard.classList.add('locked');

    function updateLock() {
        const remaining = Math.max(
            0,
            Math.ceil((lockedUntil - Date.now()) / 1000)
        );

        if (remaining <= 0) {
            clearInterval(lockTimer);
            lockedUntil = 0;
            resetScreen();
            return;
        }

        document.getElementById('scanIcon').className =
            'fa-solid fa-lock';

        document.getElementById('scanTitle').textContent =
            'Pemindaian Dikunci';

        document.getElementById('scanDescription').textContent =
            'Terlalu banyak percobaan gagal. Coba kembali dalam ' +
            remaining + ' detik.';

        document.getElementById('resultName').textContent =
            'Akses sementara dibatasi';

        document.getElementById('resultNik').textContent =
            'Terlalu banyak UID tidak dikenal';

        document.getElementById('resultDepartment').textContent = '';

        document.getElementById('resultPhoto').innerHTML =
            '<i class="fa-solid fa-lock"></i>';

        const status = document.getElementById('resultStatus');
        status.className = 'result-status error';
        status.textContent = 'Dikunci';

        document.getElementById('resultMessage').textContent =
            'Kiosk akan aktif kembali setelah hitung mundur selesai.';

        document.getElementById('resultTime').textContent =
            remaining + ' detik';

        document.getElementById('resultArea').classList.add('show');
    }

    updateLock();
    lockTimer = setInterval(updateLock, 1000);
}

/* =========================================================
   RESET KIOSK
========================================================= */

function resetScreen() {
    if (lockedUntil > Date.now()) {
        return;
    }

    attendanceCard.classList.remove(
        'processing',
        'success',
        'error-state',
        'locked'
    );

    document.getElementById('scanIcon').className =
        'fa-solid fa-id-card';

    document.getElementById('scanTitle').textContent =
        'Tempelkan Kartu RFID Anda';

    document.getElementById('scanDescription').innerHTML =
        'Dekatkan kartu identitas ke perangkat pembaca RFID.<br>' +
        'Sistem akan mengenali identitas Anda secara otomatis.';

    document.getElementById('resultArea').classList.remove('show');

    rfidInput.value = '';
    activateReader();
}

/* =========================================================
   SOUND FEEDBACK
========================================================= */

function playTone(frequency, duration) {
    try {
        const AudioContext =
            window.AudioContext || window.webkitAudioContext;

        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();

        oscillator.connect(gain);
        gain.connect(context.destination);

        oscillator.frequency.value = frequency;
        oscillator.type = 'sine';

        gain.gain.setValueAtTime(0.12, context.currentTime);

        gain.gain.exponentialRampToValueAtTime(
            0.001,
            context.currentTime + duration / 1000
        );

        oscillator.start();
        oscillator.stop(context.currentTime + duration / 1000);

        oscillator.onended = () => context.close();

    } catch (error) {
        // Audio tidak didukung browser.
    }
}
</script>

</body>
</html>

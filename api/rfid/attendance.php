
<?php
/**
 * Attendly - RFID Attendance API
 * File: api/rfid/attendance.php
 *
 * USB HID Laptop / ESP32 RFID
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/db.php';

/**
 * Format response JSON.
 */
function api_response(
    bool $success,
    string $message,
    array $extra = [],
    int $httpCode = 200
): void {
    http_response_code($httpCode);

    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/**
 * Ambil Bearer Token.
 */
function get_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization']
            ?? $headers['authorization']
            ?? '';
    }

    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

/**
 * Catat event RFID.
 */
function save_rfid_event(
    PDO $pdo,
    int $companyId,
    ?int $deviceId,
    ?int $employeeId,
    string $uid,
    string $eventType,
    string $processingStatus,
    string $message,
    array $payload = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO attendance_events (
            company_id,
            device_id,
            employee_id,
            card_uid,
            event_type,
            event_at,
            payload,
            processing_status,
            processing_message
        ) VALUES (
            :company_id,
            :device_id,
            :employee_id,
            :card_uid,
            :event_type,
            NOW(),
            :payload,
            :processing_status,
            :processing_message
        )
    ");

    $stmt->execute([
        ':company_id' => $companyId,
        ':device_id' => $deviceId,
        ':employee_id' => $employeeId,
        ':card_uid' => $uid,
        ':event_type' => $eventType,
        ':payload' => json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        ':processing_status' => $processingStatus,
        ':processing_message' => mb_substr($message, 0, 255)
    ]);
}

/**
 * GET: pengecekan API.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'health') {
        try {
            $pdo->query('SELECT 1');

            api_response(true, 'Attendly RFID API aktif.', [
                'data' => [
                    'service' => 'Attendly RFID',
                    'database' => 'connected',
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (Throwable $e) {
            api_response(false, 'Database tidak dapat diakses.', [], 503);
        }
    }

    api_response(false, 'Action tidak ditemukan.', [], 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    api_response(false, 'Method tidak diizinkan.', [], 405);
}

/**
 * Validasi koneksi database.
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    api_response(false, 'Koneksi database tidak tersedia.', [], 500);
}

/**
 * Ambil JSON request.
 */
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    api_response(false, 'Format JSON tidak valid.', [], 400);
}

/**
 * Normalisasi UID.
 */
$uid = strtoupper(trim((string)($input['uid'] ?? '')));
$uid = preg_replace('/[\s:.-]+/', '', $uid);

if (
    $uid === '' ||
    strlen($uid) < 4 ||
    strlen($uid) > 100 ||
    !preg_match('/^[A-Z0-9]+$/', $uid)
) {
    api_response(false, 'UID RFID tidak valid.', [], 422);
}

/**
 * Autentikasi perangkat menggunakan Bearer Token.
 */
$plainToken = get_bearer_token();

if ($plainToken === '' || strlen($plainToken) < 32) {
    api_response(false, 'Token perangkat tidak ditemukan atau tidak valid.', [], 401);
}

$tokenHash = hash('sha256', $plainToken);

try {
    $stmt = $pdo->prepare("
        SELECT
            t.id AS token_id,
            t.company_id,
            t.device_id,
            t.device_name,
            d.device_code,
            d.name AS device_label,
            d.device_type,
            d.location_id,
            d.status AS device_status,
            c.name AS company_name,
            c.status AS company_status,
            c.timezone
        FROM rfid_api_tokens t
        LEFT JOIN attendance_devices d
            ON d.id = t.device_id
            AND d.company_id = t.company_id
        INNER JOIN companies c
            ON c.id = t.company_id
        WHERE
            t.token_hash = :token_hash
            AND t.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([':token_hash' => $tokenHash]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        api_response(false, 'Token perangkat tidak terdaftar atau sudah dinonaktifkan.', [], 401);
    }

    $companyId = (int)$device['company_id'];
    $deviceId = $device['device_id'] !== null
        ? (int)$device['device_id']
        : null;

    if ($deviceId !== null && $device['device_status'] !== 'active') {
        api_response(false, 'Perangkat absensi tidak aktif.', [], 403);
    }

    if ($device['company_status'] !== 'active') {
        api_response(false, 'Perusahaan sedang tidak aktif.', [], 403);
    }

    /**
     * Periksa pengaturan RFID perusahaan.
     */
    $settingStmt = $pdo->prepare("
        SELECT setting_value
        FROM company_settings
        WHERE company_id = :company_id
          AND setting_key = 'rfid_enabled'
        LIMIT 1
    ");

    $settingStmt->execute([':company_id' => $companyId]);
    $rfidSetting = $settingStmt->fetchColumn();

    if ($rfidSetting !== false) {
        $rfidEnabled = json_decode((string)$rfidSetting, true);

        if (!in_array($rfidEnabled, [1, '1', true], true)) {
            api_response(false, 'Fitur RFID sedang dinonaktifkan.', [], 403);
        }
    }

    /**
     * Atur timezone berdasarkan perusahaan.
     */
    $timezone = $device['timezone'] ?: 'Asia/Jakarta';

    try {
        date_default_timezone_set($timezone);
    } catch (Throwable $e) {
        date_default_timezone_set('Asia/Jakarta');
    }

    /**
     * Update waktu terakhir perangkat digunakan.
     */
    $pdo->prepare("
        UPDATE rfid_api_tokens
        SET last_used_at = NOW()
        WHERE id = :id
    ")->execute([':id' => (int)$device['token_id']]);

    if ($deviceId !== null) {
        $pdo->prepare("
            UPDATE attendance_devices
            SET last_seen_at = NOW()
            WHERE id = :id
              AND company_id = :company_id
        ")->execute([
            ':id' => $deviceId,
            ':company_id' => $companyId
        ]);
    }

    /**
     * Cari kartu dan karyawan.
     */
    $cardStmt = $pdo->prepare("
        SELECT
            ec.id AS card_id,
            ec.card_uid,
            ec.status AS card_status,
            e.id AS employee_id,
            e.nik,
            e.name,
            e.photo,
            e.photo_path,
            e.status AS employee_status
        FROM employee_cards ec
        INNER JOIN employees e
            ON e.id = ec.employee_id
            AND e.company_id = ec.company_id
        WHERE
            ec.company_id = :company_id
            AND ec.card_uid = :uid
        LIMIT 1
    ");

    $cardStmt->execute([
        ':company_id' => $companyId,
        ':uid' => $uid
    ]);

    $card = $cardStmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        save_rfid_event(
            $pdo,
            $companyId,
            $deviceId,
            null,
            $uid,
            'unknown_card',
            'rejected',
            'Kartu RFID tidak terdaftar.',
            ['mode' => $input['mode'] ?? 'unknown']
        );

        api_response(false, 'Kartu RFID tidak terdaftar.', [
            'uid' => $uid
        ], 404);
    }

    $employeeId = (int)$card['employee_id'];

    if (
        $card['card_status'] !== 'active' ||
        $card['employee_status'] !== 'active'
    ) {
        save_rfid_event(
            $pdo,
            $companyId,
            $deviceId,
            $employeeId,
            $uid,
            'unknown_card',
            'rejected',
            'Kartu atau status karyawan tidak aktif.'
        );

        api_response(false, 'Kartu atau status karyawan tidak aktif.', [
            'employee' => [
                'name' => $card['name'],
                'nik' => $card['nik']
            ]
        ], 403);
    }

    /**
     * Mulai transaksi absensi.
     */
    $pdo->beginTransaction();

    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $nowString = $now->format('Y-m-d H:i:s');
    $today = $now->format('Y-m-d');
    $currentTime = $now->format('H:i:s');

    /**
     * Cari jadwal aktif karyawan.
     */
    $scheduleStmt = $pdo->prepare("
        SELECT
            ws.id,
            ws.name,
            ws.dock_in,
            ws.dock_out,
            ws.late_tolerance_minutes,
            ws.cross_day
        FROM employee_schedules es
        INNER JOIN work_schedules ws
            ON ws.id = es.schedule_id
            AND ws.company_id = es.company_id
        WHERE
            es.company_id = :company_id
            AND es.employee_id = :employee_id
            AND es.effective_from <= :today
            AND (
                es.effective_until IS NULL
                OR es.effective_until >= :today
            )
            AND ws.status = 'active'
        ORDER BY es.effective_from DESC
        LIMIT 1
    ");

    $scheduleStmt->execute([
        ':company_id' => $companyId,
        ':employee_id' => $employeeId,
        ':today' => $today
    ]);

    $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

    /**
     * Jadwal lintas hari:
     * Jika sekarang masih sebelum jam pulang jadwal malam,
     * tanggal absensi menggunakan tanggal sebelumnya.
     */
    $attendanceDate = $today;

    if (
        $schedule &&
        (int)$schedule['cross_day'] === 1 &&
        $currentTime <= $schedule['dock_out']
    ) {
        $attendanceDate = $now->modify('-1 day')->format('Y-m-d');
    }

    $scheduleId = $schedule ? (int)$schedule['id'] : null;

    /**
     * Kunci data absensi agar scan bersamaan tidak membuat
     * dua proses masuk/pulang pada waktu yang sama.
     */
    $attendanceStmt = $pdo->prepare("
        SELECT *
        FROM attendances
        WHERE company_id = :company_id
          AND employee_id = :employee_id
          AND attendance_date = :attendance_date
        LIMIT 1
        FOR UPDATE
    ");

    $attendanceStmt->execute([
        ':company_id' => $companyId,
        ':employee_id' => $employeeId,
        ':attendance_date' => $attendanceDate
    ]);

    $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);

    $eventType = '';
    $attendanceStatus = '';
    $statusLabel = '';
    $attendanceTime = '';
    $lateMinutes = 0;
    $workMinutes = 0;

    /**
     * BELUM ADA ABSENSI: catat masuk.
     */
    if (!$attendance) {
        $attendanceStatus = 'present';

        if ($schedule) {
            $scheduledTime = new DateTimeImmutable(
                $attendanceDate . ' ' . $schedule['dock_in'],
                new DateTimeZone($timezone)
            );

            $allowedTime = $scheduledTime->modify(
                '+' . (int)$schedule['late_tolerance_minutes'] . ' minutes'
            );

            if ($now > $allowedTime) {
                $attendanceStatus = 'late';

                $lateMinutes = max(
                    0,
                    (int)floor(
                        ($now->getTimestamp() - $scheduledTime->getTimestamp()) / 60
                    )
                );
            }
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO attendances (
                company_id,
                employee_id,
                location_id,
                schedule_id,
                attendance_date,
                clock_in_at,
                clock_in_method,
                late_minutes,
                status,
                notes
            ) VALUES (
                :company_id,
                :employee_id,
                :location_id,
                :schedule_id,
                :attendance_date,
                :clock_in_at,
                'rfid',
                :late_minutes,
                :status,
                NULL
            )
        ");

        $insertStmt->execute([
            ':company_id' => $companyId,
            ':employee_id' => $employeeId,
            ':location_id' => $device['location_id'],
            ':schedule_id' => $scheduleId,
            ':attendance_date' => $attendanceDate,
            ':clock_in_at' => $nowString,
            ':late_minutes' => $lateMinutes,
            ':status' => $attendanceStatus
        ]);

        $attendanceId = (int)$pdo->lastInsertId();

        $eventType = 'clock_in';
        $statusLabel = $attendanceStatus === 'late'
            ? 'Terlambat'
            : 'Tepat waktu';

        $attendanceTime = $now->format('H:i:s');

    /**
     * SUDAH MASUK, BELUM PULANG: catat pulang.
     */
    } elseif (
        !empty($attendance['clock_in_at']) &&
        empty($attendance['clock_out_at'])
    ) {
        $clockIn = new DateTimeImmutable(
            $attendance['clock_in_at'],
            new DateTimeZone($timezone)
        );

        $workMinutes = max(
            0,
            (int)floor(
                ($now->getTimestamp() - $clockIn->getTimestamp()) / 60
            )
        );

        $attendanceId = (int)$attendance['id'];

        $updateStmt = $pdo->prepare("
            UPDATE attendances
            SET
                clock_out_at = :clock_out_at,
                clock_out_method = 'rfid',
                work_minutes = :work_minutes
            WHERE
                id = :id
                AND company_id = :company_id
        ");

        $updateStmt->execute([
            ':clock_out_at' => $nowString,
            ':work_minutes' => $workMinutes,
            ':id' => $attendanceId,
            ':company_id' => $companyId
        ]);

        $eventType = 'clock_out';
        $attendanceStatus = $attendance['status'];
        $statusLabel = 'Berhasil pulang';
        $attendanceTime = $now->format('H:i:s');

    /**
     * SUDAH MASUK DAN PULANG.
     */
    } else {
        $pdo->rollBack();

        api_response(false, 'Absensi hari ini sudah lengkap.', [
            'employee' => [
                'name' => $card['name'],
                'nik' => $card['nik']
            ],
            'attendance' => [
                'status' => 'completed',
                'status_label' => 'Sudah masuk dan pulang'
            ]
        ], 409);
    }

    /**
     * Simpan event pemindaian.
     */
    save_rfid_event(
        $pdo,
        $companyId,
        $deviceId,
        $employeeId,
        $uid,
        $eventType,
        'processed',
        $eventType === 'clock_in'
            ? 'Absensi masuk berhasil dicatat.'
            : 'Absensi pulang berhasil dicatat.',
        [
            'mode' => $input['mode'] ?? 'unknown',
            'device_type' => $input['device_type'] ?? null,
            'attendance_id' => $attendanceId,
            'attendance_date' => $attendanceDate
        ]
    );

    $pdo->commit();

    /**
     * Response untuk halaman karyawan/absen.php.
     */
    api_response(true, $eventType === 'clock_in'
        ? 'Absensi masuk berhasil dicatat.'
        : 'Absensi pulang berhasil dicatat.', [
        'employee' => [
            'id' => $employeeId,
            'name' => $card['name'],
            'nik' => $card['nik'],
            'photo' => $card['photo'] ?: $card['photo_path']
        ],
        'attendance' => [
            'id' => $attendanceId,
            'date' => $attendanceDate,
            'event' => $eventType,
            'status' => $attendanceStatus,
            'status_label' => $statusLabel,
            'time' => $attendanceTime,
            'late_minutes' => $lateMinutes,
            'work_minutes' => $workMinutes
        ],
        'device' => [
            'name' => $device['device_label'] ?: $device['device_name'],
            'code' => $device['device_code']
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[Attendly RFID API] ' . $e->getMessage());

    api_response(
        false,
        'Terjadi kesalahan saat memproses absensi. Silakan coba kembali.',
        [],
        500
    );
}

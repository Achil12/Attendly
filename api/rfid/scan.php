
<?php
// File: api/rfid/scan.php

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function response($success, $message, $data = [], $httpCode = 200)
{
    http_response_code($httpCode);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    response(false, 'Method tidak diizinkan.', [], 405);
}

// Ambil API Key
$headers = function_exists('getallheaders') ? getallheaders() : [];

$apiKey = $headers['X-API-Key']
    ?? $headers['x-api-key']
    ?? '';

if (!$apiKey && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'];

    if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
        $apiKey = trim($matches[1]);
    }
}

if (!$apiKey) {
    response(false, 'API Key wajib dikirim.', [], 401);
}

// Baca JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    response(false, 'Format JSON tidak valid.', [], 400);
}

$cardUid = strtoupper(trim($input['uid'] ?? ''));

if ($cardUid === '') {
    response(false, 'UID kartu wajib diisi.', [], 422);
}

if (!preg_match('/^[A-F0-9]{4,32}$/', $cardUid)) {
    response(false, 'Format UID kartu tidak valid.', [], 422);
}

try {
    // Validasi token perangkat
    $tokenHash = hash('sha256', $apiKey);

    $stmt = $pdo->prepare("
        SELECT id, company_id, device_id, device_name
        FROM rfid_api_tokens
        WHERE token_hash = ?
          AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$tokenHash]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        response(false, 'API Key tidak valid atau tidak aktif.', [], 401);
    }

    $companyId = (int)$device['company_id'];

    // Validasi kartu dan karyawan
    $stmt = $pdo->prepare("
        SELECT
            ec.id AS card_id,
            ec.card_uid,
            ec.employee_id,
            e.nik,
            e.name AS employee_name
        FROM employee_cards ec
        INNER JOIN employees e
            ON e.id = ec.employee_id
           AND e.company_id = ec.company_id
        WHERE ec.company_id = ?
          AND UPPER(ec.card_uid) = ?
          AND ec.status = 'active'
          AND e.status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$companyId, $cardUid]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        response(false, 'Kartu tidak terdaftar atau karyawan tidak aktif.', [
            'uid' => $cardUid
        ], 404);
    }

    // Gunakan zona waktu Indonesia
    $timezone = new DateTimeZone('Asia/Jakarta');

    $now = new DateTimeImmutable('now', $timezone);
    $attendanceDate = $now->format('Y-m-d');
    $currentTime = $now->format('H:i:s');

    $pdo->beginTransaction();

    // Cari absensi karyawan hari ini
    $stmt = $pdo->prepare("
        SELECT id, check_in, check_out
        FROM attendances
        WHERE company_id = ?
          AND employee_id = ?
          AND attendance_date = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $companyId,
        $employee['employee_id'],
        $attendanceDate
    ]);

    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attendance) {
        // Check-in pertama
        $stmt = $pdo->prepare("
            INSERT INTO attendances (
                company_id,
                employee_id,
                attendance_date,
                check_in,
                status,
                source,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, 'hadir', 'rfid', NOW(), NOW())
        ");

        $stmt->execute([
            $companyId,
            $employee['employee_id'],
            $attendanceDate,
            $currentTime
        ]);

        $attendanceId = (int)$pdo->lastInsertId();
        $action = 'check_in';
        $message = 'Check-in berhasil. Selamat bekerja!';

    } elseif (empty($attendance['check_out'])) {
        // Check-out
        $stmt = $pdo->prepare("
            UPDATE attendances
            SET check_out = ?,
                source = 'rfid',
                updated_at = NOW()
            WHERE id = ?
              AND company_id = ?
        ");

        $stmt->execute([
            $currentTime,
            $attendance['id'],
            $companyId
        ]);

        $attendanceId = (int)$attendance['id'];
        $action = 'check_out';
        $message = 'Check-out berhasil. Terima kasih!';

    } else {
        $pdo->rollBack();

        response(false, 'Absensi hari ini sudah lengkap.', [
            'employee' => $employee['employee_name'],
            'attendance_date' => $attendanceDate,
            'check_in' => $attendance['check_in'],
            'check_out' => $attendance['check_out']
        ], 409);
    }

    // Update waktu komunikasi perangkat
    $stmt = $pdo->prepare("
        UPDATE rfid_api_tokens
        SET last_used_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([$device['id']]);

    $pdo->commit();

    response(true, $message, [
        'attendance_id' => $attendanceId,
        'action' => $action,
        'uid' => $cardUid,
        'employee' => [
            'id' => (int)$employee['employee_id'],
            'nik' => $employee['nik'],
            'name' => $employee['employee_name']
        ],
        'device' => $device['device_name'],
        'attendance_date' => $attendanceDate,
        'time' => $currentTime
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Attendly RFID API: ' . $e->getMessage());

    response(false, 'Terjadi kesalahan pada server RFID API.', [], 500);
}

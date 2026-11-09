<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Akses ditolak.');
}

require_once __DIR__ . '/../config/db.php';

date_default_timezone_set('Asia/Jakarta');

try {
    $pdo->beginTransaction();

    // Ambil subscription yang masih aktif dan belum berakhir.
    $stmt = $pdo->query("
        SELECT id, company_id, end_date,
               DATEDIFF(DATE(end_date), CURDATE()) AS days_left
        FROM subscriptions
        WHERE status = 'active'
          AND end_date IS NOT NULL
          AND DATE(end_date) >= CURDATE()
          AND DATEDIFF(DATE(end_date), CURDATE()) IN (7, 3, 1, 0)
    ");

    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $noticeMessages = [
        7 => [
            'type' => '7_days',
            'title' => 'Langganan akan berakhir dalam 7 hari',
            'message' => 'Masa langganan perusahaan Anda akan berakhir dalam 7 hari. Segera lakukan perpanjangan agar layanan Attendly tetap dapat digunakan.'
        ],
        3 => [
            'type' => '3_days',
            'title' => 'Langganan akan berakhir dalam 3 hari',
            'message' => 'Masa langganan perusahaan Anda akan berakhir dalam 3 hari. Segera lakukan perpanjangan.'
        ],
        1 => [
            'type' => '1_day',
            'title' => 'Langganan berakhir besok',
            'message' => 'Besok adalah hari terakhir masa aktif langganan perusahaan Anda. Segera lakukan perpanjangan.'
        ],
        0 => [
            'type' => 'expires_today',
            'title' => 'Langganan berakhir hari ini',
            'message' => 'Masa langganan perusahaan Anda berakhir hari ini. Segera lakukan perpanjangan agar akses Attendly tidak terhenti.'
        ]
    ];

    $insertNotice = $pdo->prepare("
        INSERT IGNORE INTO subscription_notices
            (company_id, subscription_id, notice_type, title, message)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($subscriptions as $subscription) {
        $daysLeft = (int) $subscription['days_left'];
        $notice = $noticeMessages[$daysLeft];

        $insertNotice->execute([
            $subscription['company_id'],
            $subscription['id'],
            $notice['type'],
            $notice['title'],
            $notice['message']
        ]);
    }

    // Kedaluwarsa setelah tanggal akhir terlewati.
    $expireStmt = $pdo->prepare("
        UPDATE subscriptions
        SET status = 'expired'
        WHERE status = 'active'
          AND end_date IS NOT NULL
          AND DATE(end_date) < CURDATE()
    ");

    $expireStmt->execute();
    $expiredCount = $expireStmt->rowCount();

    // Catat notifikasi untuk subscription yang baru kedaluwarsa.
    // Notifikasi ini tetap hanya dibuat satu kali.
    $expiredSubscriptions = $pdo->query("
        SELECT id, company_id
        FROM subscriptions
        WHERE status = 'expired'
          AND end_date IS NOT NULL
          AND DATE(end_date) < CURDATE()
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredSubscriptions as $subscription) {
        $insertNotice->execute([
            $subscription['company_id'],
            $subscription['id'],
            'expired',
            'Masa langganan telah berakhir',
            'Masa langganan perusahaan Anda telah berakhir. Akses Attendly dibatasi sampai langganan diperpanjang.'
        ]);
    }

    $pdo->commit();

    echo '[' . date('Y-m-d H:i:s') . '] Pemeriksaan selesai. ';
    echo 'Notifikasi diperiksa: ' . count($subscriptions) . '. ';
    echo 'Subscription kedaluwarsa: ' . $expiredCount . PHP_EOL;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[Attendly Subscription Checker] ' . $e->getMessage());

    echo 'Terjadi kesalahan saat memeriksa subscription.' . PHP_EOL;
    exit(1);
}
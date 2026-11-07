
<?php
require_once __DIR__ . 'db.php';

echo '<h2>Attendly Database</h2>';
echo '<p style="color:green;">Koneksi database berhasil!</p>';

try {
    $stmt = $pdo->query("SELECT DATABASE() AS database_name");
    $result = $stmt->fetch();

    echo 'Database: ' . htmlspecialchars($result['database_name']);
} catch (PDOException $e) {
    echo 'Query gagal.';
}

<?php

$token = bin2hex(random_bytes(32));

echo '<h3>Attendly RFID Token</h3>';

echo '<p><strong>Token:</strong></p>';
echo '<textarea style="width:100%;height:80px;">'
    . htmlspecialchars($token)
    . '</textarea>';

echo '<p><strong>Token Hash:</strong></p>';
echo '<textarea style="width:100%;height:80px;">'
    . hash('sha256', $token)
    . '</textarea>';
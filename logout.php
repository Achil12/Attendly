
<?php

require_once __DIR__ . '/config/session.php';

attendly_logout();

header('Location: login.php');
exit;

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
if (current_user()) {
    audit($conn, $_SESSION['user']['id'], 'logout', '');
}
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;

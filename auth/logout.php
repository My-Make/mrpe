<?php
require_once __DIR__ . '/../includes/functions.php';
if (is_logged_in()) {
    log_attivita($pdo, 'logout', 'utenti', $_SESSION['user_id']);
}
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;

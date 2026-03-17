<?php
require_once 'includes/config.php';

setcookie('auth_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

$_SESSION = [];
session_destroy();

header('Location: index.php');
exit;

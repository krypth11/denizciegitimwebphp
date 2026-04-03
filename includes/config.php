<?php
// includes/config.php

// Hata raporlama (hataları logla, kullanıcıya basma)
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Timezone
date_default_timezone_set('Europe/Istanbul');

// Database bağlantı
define('DB_HOST', 'localhost');
define('DB_USER', 'u2621168_dbadmin');
define('DB_PASS', 'ARDAbeyza59.');
define('DB_NAME', 'u2621168_denizciegitim');

// Site ayarları
define('SITE_URL', 'https://denizciegitim.com');
define('SITE_NAME', 'Denizci Eğitim Admin Panel');
define('STORY_PUBLIC_BASE_URL', getenv('STORY_PUBLIC_BASE_URL') ?: '');

// JWT ayarları
define('JWT_SECRET', 'dEnIzCi_EgItIm_2026_sEcReT_kEy_ChAnGe_Me');
define('JWT_EXPIRY', 86400); // 24 saat
define('SESSION_TIMEOUT', 1800); // 30 dakika

// Email / OTP ayarları (environment üzerinden yönet)
define('SMTP_HOST', getenv('SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_ENCRYPTION', strtolower((string)(getenv('SMTP_ENCRYPTION') ?: 'tls'))); // tls|ssl|none
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: SITE_NAME);

define('EMAIL_OTP_LENGTH', 6);
define('EMAIL_OTP_EXPIRY_SECONDS', 600); // 10 dk
define('EMAIL_OTP_RESEND_COOLDOWN_SECONDS', 60); // 60 sn
define('EMAIL_OTP_MAX_ATTEMPTS', 5);

// Session
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_lifetime', '86400');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// PDO bağlantı
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    die('Sistem hatası oluştu. Lütfen daha sonra tekrar deneyin.');
}

function json_response($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

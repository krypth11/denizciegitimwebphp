<?php
// includes/config.php

// Hata raporlama (hataları logla, kullanıcıya basma)
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Timezone
date_default_timezone_set('Europe/Istanbul');

require_once __DIR__ . '/security_config.php';

function config_required_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Required application configuration is missing: ' . $name);
    }
    return trim($value);
}

// Database bağlantı: güvenlik nedeniyle bütün değerler zorunlu environment değişkenleridir.
define('DB_HOST', config_required_env('DB_HOST'));
define('DB_PORT', security_env_int('DB_PORT', 3306, 1, 65535));
define('DB_USER', config_required_env('DB_USER'));
define('DB_PASS', config_required_env('DB_PASSWORD'));
define('DB_NAME', config_required_env('DB_NAME'));

// Site ayarları
define('SITE_URL', 'https://denizciegitim.com');
define('SITE_NAME', 'Denizci Eğitim Admin Panel');
define('STORY_PUBLIC_BASE_URL', getenv('STORY_PUBLIC_BASE_URL') ?: '');
define('SHARED_UPLOADS_ROOT', getenv('SHARED_UPLOADS_ROOT') ?: '/home/u2621168/shared_uploads');
define('STORY_UPLOAD_ROOT', getenv('STORY_UPLOAD_ROOT') ?: SHARED_UPLOADS_ROOT);
define('UPLOADS_PUBLIC_PREFIX', getenv('UPLOADS_PUBLIC_PREFIX') ?: 'uploads');
define('PROFILE_PHOTOS_UPLOAD_MODULE', getenv('PROFILE_PHOTOS_UPLOAD_MODULE') ?: 'profile-photos');
define('PROFILE_PHOTO_MAX_BYTES', (int)(getenv('PROFILE_PHOTO_MAX_BYTES') ?: (5 * 1024 * 1024)));
define('PROFILE_DEFAULT_AVATAR_IDS', [
    '1', '2', '3', '4', '5',
    '6', '7', '8', '9', '10',
    '11', '12', '13', '14', '15',
    '16', '17', '18', '19', '20',
]);

// Firebase (FCM HTTP v1)
define('FIREBASE_SERVICE_ACCOUNT_PATH', getenv('FIREBASE_SERVICE_ACCOUNT_PATH') ?: '/home/u2621168/firebase/denizci-egitim-firebase-adminsdk-fbsvc-e2454f0564.json');
define('FIREBASE_FCM_SCOPE', 'https://www.googleapis.com/auth/firebase.messaging');
define('FIREBASE_FCM_TIMEOUT', 15);

define('SESSION_TIMEOUT', 1800); // 30 dakika

define('AUTH_RATE_LIMIT_MAX_ATTEMPTS', security_env_int('AUTH_RATE_LIMIT_MAX_ATTEMPTS', 8, 3, 100));
define('AUTH_RATE_LIMIT_WINDOW_SECONDS', security_env_int('AUTH_RATE_LIMIT_WINDOW_SECONDS', 900, 60, 86400));
define('AUTH_RATE_LIMIT_BLOCK_SECONDS', security_env_int('AUTH_RATE_LIMIT_BLOCK_SECONDS', 900, 60, 86400));
define('PASSWORD_RESET_MAX_REQUESTS', security_env_int('PASSWORD_RESET_MAX_REQUESTS', 3, 1, 20));
define('PASSWORD_RESET_WINDOW_SECONDS', security_env_int('PASSWORD_RESET_WINDOW_SECONDS', 3600, 60, 86400));

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
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection could not be established.');
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

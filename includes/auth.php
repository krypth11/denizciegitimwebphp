<?php
// includes/auth.php

function auth_runtime_log($message, array $context = [])
{
    if (!empty($context)) {
        error_log('[AUTH][SESSION] ' . $message . ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        return;
    }
    error_log('[AUTH][SESSION] ' . $message);
}

function redirect_to($url)
{
    if (!headers_sent($file, $line)) {
        header('Location: ' . $url);
        exit;
    }

    auth_runtime_log('Header zaten gönderilmiş, fallback redirect uygulanıyor', [
        'file' => $file,
        'line' => $line,
        'url' => $url,
    ]);

    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

function is_ajax_request()
{
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    return strtolower($requestedWith) === 'xmlhttprequest'
        || str_contains(strtolower($accept), 'application/json')
        || str_contains($requestUri, '/ajax/');
}

function base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data)
{
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode($payload)
{
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $base64UrlHeader = base64url_encode($header);
    $base64UrlPayload = base64url_encode(json_encode($payload));
    $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = base64url_encode($signature);

    return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
}

function jwt_decode($jwt)
{
    $tokenParts = explode('.', (string)$jwt);
    if (count($tokenParts) !== 3) {
        return false;
    }

    $header = base64url_decode($tokenParts[0]);
    $payload = base64url_decode($tokenParts[1]);
    $signatureProvided = $tokenParts[2];

    $base64UrlHeader = base64url_encode($header);
    $base64UrlPayload = base64url_encode($payload);
    $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, JWT_SECRET, true);
    $base64UrlSignature = base64url_encode($signature);

    if (!hash_equals($base64UrlSignature, $signatureProvided)) {
        return false;
    }

    $payload = json_decode($payload, true);
    if (!is_array($payload)) {
        return false;
    }

    if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
        return false;
    }

    return $payload;
}

function create_token($user_id, $email, $is_admin = false)
{
    $payload = [
        'user_id' => $user_id,
        'email' => $email,
        'is_admin' => (bool)$is_admin,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY,
    ];

    return jwt_encode($payload);
}

function set_auth_cookie($token)
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('auth_token', $token, [
        'expires' => time() + JWT_EXPIRY,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_auth_cookie()
{
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('auth_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function logout_user()
{
    clear_auth_cookie();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function is_authenticated_session()
{
    auth_runtime_log('Session doğrulama başladı', [
        'session_status' => session_status(),
        'session_id_exists' => session_id() !== '',
        'has_user_id' => isset($_SESSION['user_id']),
        'has_email' => isset($_SESSION['email']),
        'has_is_admin' => isset($_SESSION['is_admin']),
    ]);

    if (empty($_SESSION['user_id']) || empty($_SESSION['email']) || !isset($_SESSION['is_admin'])) {
        auth_runtime_log('Session doğrulama başarısız: gerekli anahtarlar eksik');
        return false;
    }

    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > SESSION_TIMEOUT) {
        auth_runtime_log('Session doğrulama başarısız: timeout', [
            'last_activity' => $lastActivity,
            'timeout' => SESSION_TIMEOUT,
        ]);
        logout_user();
        return false;
    }

    $sessionUa = $_SESSION['user_agent'] ?? '';
    $currentUa = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    if (!empty($sessionUa) && !hash_equals($sessionUa, $currentUa)) {
        auth_runtime_log('Session doğrulama başarısız: user-agent mismatch');
        logout_user();
        return false;
    }

    $_SESSION['last_activity'] = time();
    auth_runtime_log('Session doğrulama başarılı');
    return true;
}

function verify_token()
{
    // Eski akışla uyumluluk: artık session bazlı doğrulama esas
    if (is_authenticated_session()) {
        return [
            'user_id' => $_SESSION['user_id'],
            'email' => $_SESSION['email'],
            'is_admin' => (bool)$_SESSION['is_admin'],
        ];
    }

    return false;
}

function require_auth()
{
    if (!is_authenticated_session()) {
        auth_runtime_log('require_auth: oturum yok, login sayfasına yönlendiriliyor');
        if (is_ajax_request()) {
            json_response([
                'success' => false,
                'message' => 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.',
            ], 401);
        }
        redirect_to('/index.php');
    }

    if (empty($_SESSION['is_admin'])) {
        auth_runtime_log('require_auth: oturum var ama admin değil, logout uygulanıyor');
        logout_user();
        if (is_ajax_request()) {
            json_response([
                'success' => false,
                'message' => 'Admin yetkisi gerekli!',
            ], 403);
        }
        redirect_to('/index.php');
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    return [
        'user_id' => $_SESSION['user_id'],
        'email' => $_SESSION['email'],
        'is_admin' => (bool)$_SESSION['is_admin'],
    ];
}

function require_admin()
{
    $user = require_auth();

    if (empty($user['is_admin'])) {
        if (is_ajax_request()) {
            json_response([
                'success' => false,
                'message' => 'Admin yetkisi gerekli!',
            ], 403);
        }
        redirect_to('/index.php');
    }

    return $user;
}

function hash_password($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function is_supported_password_hash($hash)
{
    if (!is_string($hash) || trim($hash) === '') {
        return false;
    }

    $info = password_get_info($hash);
    return !empty($info['algo']);
}

function verify_password($password, $hash)
{
    if (!is_supported_password_hash($hash)) {
        return false;
    }

    return password_verify($password, $hash);
}

function get_table_columns(PDO $pdo, $table)
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $cols = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['Field'])) {
                $cols[] = $row['Field'];
            }
        }
    } catch (Throwable $e) {
        error_log('Column discovery failed for ' . $table . ': ' . $e->getMessage());
    }

    $cache[$table] = $cols;
    return $cols;
}

function extract_password_hash_from_row(array $row)
{
    $candidates = [
        'up_password_hash',
        'up_password',
        'up_hashed_password',
        'up_pass_hash',
        'up_passwd',
        'au_password_hash',
        'au_password',
        'au_hashed_password',
        'au_pass_hash',
        'au_passwd',
    ];

    foreach ($candidates as $field) {
        if (!empty($row[$field]) && is_supported_password_hash($row[$field])) {
            return $row[$field];
        }
    }

    return null;
}

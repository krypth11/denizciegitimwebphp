<?php
// includes/auth.php

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
    $tokenParts = explode('.', $jwt);
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

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }

    return $payload;
}

function create_token($user_id, $email, $is_admin = false)
{
    $payload = [
        'user_id' => $user_id,
        'email' => $email,
        'is_admin' => $is_admin,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY,
    ];

    return jwt_encode($payload);
}

function verify_token()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = null;

    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
    }

    if (!$token && isset($_COOKIE['auth_token'])) {
        $token = $_COOKIE['auth_token'];
    }

    if (!$token) {
        return false;
    }

    return jwt_decode($token);
}

function require_auth()
{
    $user = verify_token();
    if (!$user) {
        header('Location: /index.php');
        exit;
    }

    return $user;
}

function require_admin()
{
    $user = require_auth();
    if (empty($user['is_admin'])) {
        die('Admin yetkisi gerekli!');
    }

    return $user;
}

function hash_password($password)
{
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password($password, $hash)
{
    return password_verify($password, $hash);
}

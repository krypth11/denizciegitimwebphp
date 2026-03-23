<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $email = trim((string)($payload['email'] ?? ''));
    $password = (string)($payload['password'] ?? '');

    if ($email === '' || $password === '') {
        api_error('Email ve şifre zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    $user = api_find_user_by_email($pdo, $email);
    if (!$user) {
        api_error('Email veya şifre hatalı.', 401);
    }

    $hash = (string)($user['password_hash'] ?? '');
    if ($hash === '' || !verify_password($password, $hash)) {
        api_error('Email veya şifre hatalı.', 401);
    }

    $token = api_create_user_token($pdo, (string)$user['id']);
    api_update_last_sign_in($pdo, (string)$user['id']);

    api_success('Giriş başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, (string)$user['id']),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

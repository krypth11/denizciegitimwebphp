<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/auth_rate_limit_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $password = (string)($payload['password'] ?? '');

    if ($email === '' || $password === '') {
        api_error('Email ve şifre zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    auth_rate_limit_assert_allowed($pdo, 'login', $email);

    $user = api_find_user_by_email($pdo, $email);
    $hash = (string)($user['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuu9Bx7i8uWlBqVqDPY4VjA9M5YtQhF9C');
    if (!$user || !verify_password($password, $hash)) {
        auth_rate_limit_record(
            $pdo,
            'login',
            $email,
            AUTH_RATE_LIMIT_MAX_ATTEMPTS,
            AUTH_RATE_LIMIT_WINDOW_SECONDS,
            AUTH_RATE_LIMIT_BLOCK_SECONDS
        );
        api_error('Email veya şifre hatalı.', 401);
    }

    auth_rate_limit_clear($pdo, 'login', $email);

    $token = api_create_user_token($pdo, (string)$user['id']);
    api_update_last_sign_in($pdo, (string)$user['id']);

    api_success('Giriş başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, (string)$user['id']),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

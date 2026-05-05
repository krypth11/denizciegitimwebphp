<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $code = trim((string)($payload['token'] ?? ($payload['code'] ?? '')));
    $password = (string)($payload['password'] ?? '');
    $passwordConfirm = (string)($payload['password_confirm'] ?? '');

    if ($email === '') {
        api_error('email zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    if (!preg_match('/^\d{6}$/', $code)) {
        api_error('Kod 6 haneli olmalıdır.', 422);
    }

    if ($password === '' || mb_strlen($password) < 6) {
        api_error('password en az 6 karakter olmalıdır.', 422);
    }

    if ($password !== $passwordConfirm) {
        api_error('password ve password_confirm aynı olmalıdır.', 422);
    }

    $result = api_complete_password_reset($pdo, $email, $code, $password);

    api_success('Şifre başarıyla güncellendi.', [
        'token' => $result['token'],
        'user' => $result['user'],
    ]);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    if ($status >= 400 && $status < 500) {
        api_error($e->getMessage(), $status);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

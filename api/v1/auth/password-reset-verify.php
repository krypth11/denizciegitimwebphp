<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $code = trim((string)($payload['token'] ?? ($payload['code'] ?? '')));

    if ($email === '') {
        api_error('email zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    if ($code === '') {
        api_error('token zorunludur.', 422);
    }

    if (!preg_match('/^\d{6}$/', $code)) {
        api_error('Kod 6 haneli olmalıdır.', 422);
    }

    $result = api_verify_password_reset_otp($pdo, $email, $code);

    api_success('Kod doğrulandı.', $result);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    if ($status >= 400 && $status < 500) {
        api_error($e->getMessage(), $status);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

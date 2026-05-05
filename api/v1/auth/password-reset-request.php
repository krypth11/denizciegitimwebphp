<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();
    $email = strtolower(trim((string)($payload['email'] ?? '')));

    if ($email === '') {
        api_error('email zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    api_request_password_reset_otp($pdo, $email);

    api_success('Şifre sıfırlama kodu gönderildi.', [
        'success' => true,
    ]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code >= 400 && $code < 500) {
        api_error($e->getMessage(), $code);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

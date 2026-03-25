<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $purpose = api_validate_email_verification_purpose((string)($payload['purpose'] ?? ''));
    $otpToken = trim((string)($payload['token'] ?? ($payload['code'] ?? '')));

    if ($email === '') {
        api_error('email zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    if ($otpToken === '') {
        api_error('token zorunludur.', 422);
    }

    if (!preg_match('/^\d{6}$/', $otpToken)) {
        api_error('Geçersiz OTP.', 422);
    }

    $user = api_verify_email_otp($pdo, $email, $purpose, $otpToken);

    api_success('Email doğrulaması başarılı.', [
        'verified' => true,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code >= 400 && $code < 500) {
        api_error($e->getMessage(), $code);
    }
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

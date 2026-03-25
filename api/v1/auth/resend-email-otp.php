<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $purpose = api_validate_email_verification_purpose((string)($payload['purpose'] ?? ''));

    if ($email === '') {
        api_error('email zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    $result = api_resend_email_otp($pdo, $email, $purpose);

    api_success('Doğrulama kodu yeniden gönderildi.', [
        'email' => $result['email'],
        'verification_purpose' => $result['purpose'],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

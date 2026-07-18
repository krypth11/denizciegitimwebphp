<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/auth_rate_limit_helper.php';

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

    auth_rate_limit_assert_allowed($pdo, 'password_reset', $email);
    auth_rate_limit_record(
        $pdo,
        'password_reset',
        $email,
        PASSWORD_RESET_MAX_REQUESTS,
        PASSWORD_RESET_WINDOW_SECONDS,
        PASSWORD_RESET_WINDOW_SECONDS
    );

    try {
        api_request_password_reset_otp($pdo, $email);
    } catch (Throwable $mailError) {
        error_log('[password-reset-request] Request could not be completed.');
    }

    api_success('Hesap mevcutsa şifre sıfırlama kodu gönderildi.', [
        'success' => true,
    ]);
} catch (Throwable $e) {
    api_success('Hesap mevcutsa şifre sıfırlama kodu gönderildi.', ['success' => true]);
}

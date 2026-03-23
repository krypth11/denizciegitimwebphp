<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();

    $fullName = trim((string)($payload['full_name'] ?? ''));
    $email = trim(strtolower((string)($payload['email'] ?? '')));
    $password = (string)($payload['password'] ?? '');

    if ($fullName === '') {
        api_error('full_name zorunludur.', 422);
    }

    if ($email === '') {
        api_error('email zorunludur.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Geçersiz email formatı.', 422);
    }

    if ($password === '' || mb_strlen($password) < 6) {
        api_error('password en az 6 karakter olmalıdır.', 422);
    }

    if (api_email_exists($pdo, $email)) {
        api_error('Bu email zaten kayıtlı.', 409);
    }

    $userId = api_create_user_profile($pdo, [
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => hash_password($password),
        'is_admin' => 0,
        'is_guest' => 0,
        'onboarding_completed' => 0,
        'current_qualification_id' => null,
        'target_qualification_id' => null,
    ]);

    $token = api_create_user_token($pdo, $userId);
    api_update_last_sign_in($pdo, $userId);

    api_success('Kayıt başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, $userId),
    ]);
} catch (Throwable $e) {
    if (api_is_duplicate_error($e)) {
        api_error('Bu email zaten kayıtlı.', 409);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

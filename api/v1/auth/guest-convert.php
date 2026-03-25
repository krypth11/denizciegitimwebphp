<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

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

    if (mb_strlen($password) < 6) {
        api_error('password en az 6 karakter olmalıdır.', 422);
    }

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı bulunamadı.', 404);
    }

    if (!api_is_guest_user($pdo, $userId)) {
        api_error('Bu hesap zaten kayıtlı kullanıcıdır.', 409);
    }

    $existing = api_find_user_by_email($pdo, $email);
    if ($existing && (string)$existing['id'] !== $userId) {
        api_error('Bu email zaten kayıtlı.', 409);
    }

    $profileSchema = api_get_profile_schema($pdo);
    $updates = [];

    if ($profileSchema['full_name']) {
        $updates[$profileSchema['full_name']] = $fullName;
    }

    if ($profileSchema['pending_email']) {
        $updates[$profileSchema['pending_email']] = $email;
    }

    if ($profileSchema['password']) {
        $updates[$profileSchema['password']] = hash_password($password);
    }

    api_update_profile_fields($pdo, $userId, $updates);

    try {
        api_create_and_send_email_otp($pdo, $userId, $email, 'guest_convert');
    } catch (Throwable $e) {
        api_send_json([
            'success' => false,
            'message' => $e->getMessage(),
            'step' => 'email_otp_send_failed',
            'endpoint' => 'guest_convert',
        ], 500);
    }

    // Token stabil kalsın diye mevcut tokenı koruyoruz; yine de responsea verelim
    $bearerToken = api_get_bearer_token();

    api_success('Hesap tamamlama için email doğrulama gerekli.', [
        'requires_email_verification' => true,
        'verification_purpose' => 'guest_convert',
        'email' => $email,
        'user' => api_build_auth_user_payload($pdo, $userId),
        'token' => $bearerToken,
    ]);
} catch (Throwable $e) {
    if (api_is_duplicate_error($e)) {
        api_error('Bu email zaten kayıtlı.', 409);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

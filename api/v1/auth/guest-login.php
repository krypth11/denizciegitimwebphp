<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $guestEmail = 'guest_' . bin2hex(random_bytes(8)) . '@guest.local';

    $schema = api_get_profile_schema($pdo);
    $passwordHash = $schema['password'] ? hash_password(bin2hex(random_bytes(12))) : '';

    $userId = api_create_user_profile($pdo, [
        'full_name' => 'Misafir Kullanıcı',
        'email' => $guestEmail,
        'password_hash' => $passwordHash,
        'is_admin' => 0,
        'is_guest' => 1,
        'onboarding_completed' => 0,
        'current_qualification_id' => null,
        'target_qualification_id' => null,
    ]);

    $token = api_create_user_token($pdo, $userId);
    api_update_last_sign_in($pdo, $userId);

    api_success('Misafir girişi başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, $userId),
    ]);
} catch (Throwable $e) {
    if (api_is_duplicate_error($e)) {
        // Çok düşük olasılıkla guest email çakışırsa tekrar dene mesajı
        api_error('Misafir kullanıcı oluşturulamadı, lütfen tekrar deneyin.', 409);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

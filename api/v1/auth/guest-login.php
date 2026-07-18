<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/guest_device_quota_helper.php';
require_once dirname(__DIR__, 3) . '/includes/user_lifecycle_helper.php';

api_require_method('POST');

$ownsTransaction = false;
try {
    $input = api_get_request_data();
    $installationId = guest_device_quota_validate_installation_id($input['installation_id'] ?? null);
    $guestEmail = 'guest_' . bin2hex(random_bytes(8)) . '@guest.local';

    $schema = api_get_profile_schema($pdo);
    $passwordHash = $schema['password'] ? hash_password(bin2hex(random_bytes(12))) : '';

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

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

    guest_device_quota_bind($pdo, $userId, $installationId);

    if ($ownsTransaction) {
        $pdo->commit();
    }

    $token = api_create_user_token($pdo, $userId);
    api_update_last_sign_in($pdo, $userId);

    user_lifecycle_log_event(
        $pdo,
        $userId,
        'account_created_guest',
        'Misafir hesap oluşturuldu',
        'auth.guest_login',
        null,
        null,
        ['email' => $guestEmail]
    );

    api_success('Misafir girişi başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, $userId),
    ]);
} catch (InvalidArgumentException $e) {
    if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e->getMessage(), 422);
} catch (Throwable $e) {
    if ($ownsTransaction && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (api_is_duplicate_error($e)) {
        // Çok düşük olasılıkla guest email çakışırsa tekrar dene mesajı
        api_error('Misafir kullanıcı oluşturulamadı, lütfen tekrar deneyin.', 409);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

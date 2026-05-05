<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $payload = api_get_request_data();
    $idToken = trim((string)($payload['id_token'] ?? ''));

    if ($idToken === '') {
        api_error('id_token zorunludur.', 422);
    }

    $google = api_verify_google_id_token($idToken);
    if (!$google) {
        api_error('Geçersiz Google id_token.', 401);
    }

    $googleSub = trim((string)($google['sub'] ?? ''));
    $googleEmail = strtolower(trim((string)($google['email'] ?? '')));
    $emailVerified = !empty($google['email_verified']);
    $googleName = trim((string)($google['name'] ?? ''));
    $googlePicture = trim((string)($google['picture'] ?? ''));

    if ($googleEmail === '' || !$emailVerified) {
        api_error('Google hesabı email doğrulaması gerekli.', 401);
    }

    $provider = 'google';
    $existingUserId = api_find_user_id_by_auth_provider($pdo, $provider, $googleSub);
    if ($existingUserId) {
        $token = api_create_user_token($pdo, $existingUserId);
        api_update_last_sign_in($pdo, $existingUserId);

        api_success('Giriş başarılı.', [
            'token' => $token,
            'user' => api_build_auth_user_payload($pdo, $existingUserId),
        ]);
    }

    $activeUserByEmail = api_find_active_user_by_email($pdo, $googleEmail);
    if ($activeUserByEmail) {
        api_error('Bu e-posta zaten kullanılıyor. Lütfen e-posta ve şifrenizle giriş yapın.', 409);
    }

    try {
        $pdo->beginTransaction();

        // yarış durumu için transaction içinde email ve provider tekrar kontrolü
        $existingUserIdTx = api_find_user_id_by_auth_provider($pdo, $provider, $googleSub);
        if ($existingUserIdTx) {
            $token = api_create_user_token($pdo, $existingUserIdTx);
            api_update_last_sign_in($pdo, $existingUserIdTx);
            $pdo->commit();

            api_success('Giriş başarılı.', [
                'token' => $token,
                'user' => api_build_auth_user_payload($pdo, $existingUserIdTx),
            ]);
        }

        $activeUserByEmailTx = api_find_active_user_by_email($pdo, $googleEmail);
        if ($activeUserByEmailTx) {
            throw new RuntimeException('Bu e-posta zaten kullanılıyor. Lütfen e-posta ve şifrenizle giriş yapın.', 409);
        }

        $newUserId = api_create_user_profile($pdo, [
            'full_name' => $googleName,
            'email' => $googleEmail,
            'password_hash' => '',
            'is_admin' => 0,
            'is_guest' => 0,
            'email_verified' => 1,
            'email_verified_at_now' => true,
            // onboarding_completed verilmez, mevcut sistem defaultu kullanılır
        ]);

        api_create_user_auth_provider($pdo, $newUserId, $provider, $googleSub, [
            'provider_email' => $googleEmail,
            'provider_name' => $googleName,
            'provider_avatar' => $googlePicture,
        ]);

        $token = api_create_user_token($pdo, $newUserId);
        api_update_last_sign_in($pdo, $newUserId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $status = (int)$e->getCode();
        if ($status >= 400 && $status < 500) {
            api_error($e->getMessage(), $status);
        }

        if (api_is_duplicate_error($e)) {
            api_error('Bu e-posta zaten kullanılıyor. Lütfen e-posta ve şifrenizle giriş yapın.', 409);
        }

        throw $e;
    }

    api_success('Giriş başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, $newUserId),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

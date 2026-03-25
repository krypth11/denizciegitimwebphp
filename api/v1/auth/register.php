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

    $activeUser = api_find_active_user_by_email($pdo, $email);
    if ($activeUser) {
        api_error('Bu email zaten kullanımda', 409);
    }

    $passwordHash = hash_password($password);
    if (!is_string($passwordHash) || trim($passwordHash) === '') {
        api_error('Kayıt sırasında bir sunucu hatası oluştu.', 500);
    }

    try {
        $pdo->beginTransaction();

        // Yarış durumları için transaction içinde aktif kullanıcı kontrolünü tekrarla.
        $activeUserTx = api_find_active_user_by_email($pdo, $email);
        if ($activeUserTx) {
            throw new RuntimeException('Bu email zaten kullanımda', 409);
        }

        // Sadece signup pending kaydı varsa sessizce temizle ve akışı sıfırdan başlat.
        $pendingSignup = api_find_pending_signup_by_email($pdo, $email);
        if ($pendingSignup && !empty($pendingSignup['id'])) {
            api_delete_pending_signup($pdo, (string)$pendingSignup['id']);
        }

        // Aynı email için guest-convert pending state varsa temizle (guest hesabı korunur).
        $pendingGuestConvert = api_find_pending_guest_convert_by_email($pdo, $email);
        if ($pendingGuestConvert && !empty($pendingGuestConvert['id'])) {
            api_clear_pending_guest_convert($pdo, (string)$pendingGuestConvert['id']);
        }

        $tempEmail = generate_uuid() . '@pending.local';

        $userId = api_create_user_profile($pdo, [
            'full_name' => $fullName,
            'email' => $tempEmail,
            'password_hash' => $passwordHash,
            'is_admin' => 0,
            'is_guest' => 1,
            'onboarding_completed' => 0,
            'email_verified' => 0,
            'email_verified_at' => null,
            'pending_email' => $email,
            'current_qualification_id' => null,
            'target_qualification_id' => null,
        ]);

        // Internal guard: pending kullanıcıda password hash gerçekten kaydedilmiş olmalı.
        $profileSchema = api_get_profile_schema($pdo);
        if (!$profileSchema['password']) {
            throw new RuntimeException('password_hash kolonu bulunamadı', 500);
        }
        $stmtPwd = $pdo->prepare(
            'SELECT `' . $profileSchema['password'] . '` FROM `' . $profileSchema['table'] . '` WHERE `' . $profileSchema['id'] . '` = ? LIMIT 1'
        );
        $stmtPwd->execute([$userId]);
        $storedPasswordHash = (string)($stmtPwd->fetchColumn() ?? '');
        if (trim($storedPasswordHash) === '') {
            throw new RuntimeException('pending signup password_hash boş kaydedildi', 500);
        }

        $token = api_create_user_token($pdo, $userId);
        api_update_last_sign_in($pdo, $userId);

        api_create_and_send_email_otp($pdo, $userId, $email, 'signup');

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $code = (int)$e->getCode();
        if ($code >= 400 && $code < 500) {
            api_error($e->getMessage(), $code);
        }

        api_send_json([
            'success' => false,
            'message' => 'Kayıt sırasında bir sunucu hatası oluştu.',
            'step' => 'email_otp_send_failed',
            'endpoint' => 'register',
        ], 500);
    }

    api_success('Kayıt başarılı.', [
        'requires_email_verification' => true,
        'verification_purpose' => 'signup',
        'email' => $email,
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, $userId),
    ]);
} catch (Throwable $e) {
    if (api_is_duplicate_error($e)) {
        api_error('Bu email zaten kayıtlı.', 409);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

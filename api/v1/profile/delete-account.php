<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

/**
 * 12 karakterlik (A-Z, a-z, 0-9) rastgele şifre üretir.
 */
function api_generate_random_alnum_password(int $length = 12): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $maxIndex = strlen($chars) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $maxIndex)];
    }

    return $password;
}

/**
 * silinen{random}@denizciegitim.com formatında benzersiz email üretir.
 */
function api_generate_deleted_email(PDO $pdo, array $profileSchema, int $maxAttempts = 30): string
{
    $table = $profileSchema['table'];
    $emailCol = $profileSchema['email'];

    $checkSql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE LOWER(`' . $emailCol . '`) = LOWER(?)';
    $checkStmt = $pdo->prepare($checkSql);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $candidate = 'silinen' . random_int(10000, 1000000) . '@denizciegitim.com';
        $checkStmt->execute([$candidate]);
        if (((int)$checkStmt->fetchColumn()) === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Silinen kullanıcı için benzersiz email üretilemedi.');
}

/**
 * silinen{random}@denizciegitim.com emailinden denizci{random} display name üretir.
 */
function api_deleted_email_to_display_name(string $deletedEmail): string
{
    $prefix = strtolower(trim(strstr($deletedEmail, '@', true) ?: ''));
    if (preg_match('/^silinen(\d+)$/', $prefix, $matches)) {
        return 'denizci' . $matches[1];
    }

    return 'denizci' . random_int(10000, 1000000);
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $password = (string)($payload['password'] ?? '');
    if (trim($password) === '') {
        api_error('Şifre zorunludur.', 422);
    }

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı bulunamadı.', 404);
    }

    if (!empty($profile['is_guest'])) {
        api_error('Guest kullanıcılar hesap silme işlemi yapamaz.', 403);
    }

    $userSchema = api_get_user_schema($pdo);
    $profileSchema = api_get_profile_schema($pdo);

    $passwordCol = $userSchema['password'];
    if (!$passwordCol) {
        api_error('Bu hesap türü için şifre doğrulama desteklenmiyor.', 401);
    }

    $selectParts = [
        '`' . $userSchema['id'] . '` AS id',
        '`' . $passwordCol . '` AS password_hash',
    ];

    if ($userSchema['is_deleted']) {
        $selectParts[] = '`' . $userSchema['is_deleted'] . '` AS is_deleted';
    } else {
        $selectParts[] = '0 AS is_deleted';
    }

    $userSql = 'SELECT ' . implode(', ', $selectParts)
        . ' FROM `' . $userSchema['table'] . '` WHERE `' . $userSchema['id'] . '` = ? LIMIT 1';
    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        api_error('Kullanıcı bulunamadı.', 404);
    }

    if (((int)($userRow['is_deleted'] ?? 0)) === 1) {
        api_error('Yetkisiz erişim.', 401);
    }

    $currentHash = (string)($userRow['password_hash'] ?? '');
    if ($currentHash === '' || !verify_password($password, $currentHash)) {
        api_error('Şifre hatalı.', 401);
    }

    $pdo->beginTransaction();

    try {
        $deletedEmail = api_generate_deleted_email($pdo, $profileSchema, 30);
        $randomPassword = api_generate_random_alnum_password(12);
        $newPasswordHash = hash_password($randomPassword);

        if (!is_string($newPasswordHash) || trim($newPasswordHash) === '') {
            throw new RuntimeException('Yeni şifre hash üretilemedi.');
        }

        $updates = [];

        if ($profileSchema['is_deleted']) {
            $updates[$profileSchema['is_deleted']] = 1;
        }

        $updates[$profileSchema['email']] = $deletedEmail;

        if ($profileSchema['password']) {
            $updates[$profileSchema['password']] = $newPasswordHash;
        }

        if ($profileSchema['full_name']) {
            $updates[$profileSchema['full_name']] = api_deleted_email_to_display_name($deletedEmail);
        }

        if ($profileSchema['pending_email']) {
            $updates[$profileSchema['pending_email']] = null;
        }

        if ($profileSchema['email_verified']) {
            $updates[$profileSchema['email_verified']] = 0;
        }

        if ($profileSchema['avatar_type']) {
            $updates[$profileSchema['avatar_type']] = 'default';
        }

        if ($profileSchema['avatar_id']) {
            $updates[$profileSchema['avatar_id']] = 'avatar_01';
        }

        if ($profileSchema['profile_photo_url']) {
            $updates[$profileSchema['profile_photo_url']] = null;
        }

        api_update_profile_fields($pdo, $userId, $updates);

        $revokeSql = 'UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ?';
        $revokeStmt = $pdo->prepare($revokeSql);
        $revokeStmt->execute([$userId]);

        try {
            $providerSchema = api_get_user_auth_provider_schema($pdo);
            $deleteProviderSql = 'DELETE FROM `' . $providerSchema['table'] . '` WHERE `' . $providerSchema['user_id'] . '` = ?';
            $deleteProviderStmt = $pdo->prepare($deleteProviderSql);
            $deleteProviderStmt->execute([$userId]);
        } catch (Throwable $providerCleanupError) {
            error_log('[delete-account provider cleanup] ' . $providerCleanupError->getMessage());
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    api_send_json([
        'success' => true,
        'message' => 'Hesabınız başarıyla silindi.',
    ], 200);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

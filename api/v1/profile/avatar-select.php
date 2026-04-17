<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/upload_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $rawAvatarId = $payload['avatar_id'] ?? null;
    $avatarId = api_profile_normalize_avatar_id($rawAvatarId);
    $isAllowedAvatar = ($avatarId !== null) && api_profile_is_allowed_avatar_id($avatarId);
    $avatarDebugEnabled = ((string)($payload['debug_avatar_flow'] ?? '0') === '1');

    // Geçici debug: sadece debug_avatar_flow=1 gönderildiğinde log basar.
    if ($avatarDebugEnabled) {
        error_log('[avatar_select] ' . json_encode([
            'user_id' => $userId,
            'raw_avatar_id' => is_scalar($rawAvatarId) ? (string)$rawAvatarId : gettype($rawAvatarId),
            'normalized_avatar_id' => $avatarId,
            'allowed' => $isAllowedAvatar,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($avatarId === null) {
        api_error('Geçersiz avatar seçimi. Lütfen izin verilen avatarlardan birini seçin.', 422);
    }

    if (!$isAllowedAvatar) {
        api_error('Geçersiz avatar seçimi. Lütfen izin verilen avatarlardan birini seçin.', 422);
    }

    $profileSchema = api_get_profile_schema($pdo);
    api_profile_assert_avatar_schema_supported($profileSchema);

    $profileBeforeUpdate = api_find_profile_by_user_id($pdo, $userId);
    if (!$profileBeforeUpdate) {
        api_error('Profil bulunamadı.', 404);
    }

    $updates = [
        $profileSchema['avatar_type'] => 'default',
        $profileSchema['avatar_id'] => $avatarId,
    ];

    if (!empty($profileSchema['profile_photo_url'])) {
        $updates[$profileSchema['profile_photo_url']] = null;
    }

    api_update_profile_fields($pdo, $userId, $updates);

    $module = (string)(defined('PROFILE_PHOTOS_UPLOAD_MODULE') ? PROFILE_PHOTOS_UPLOAD_MODULE : 'profile-photos');
    upload_safe_delete((string)($profileBeforeUpdate['profile_photo_url'] ?? ''), $module);

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    api_success('Avatar seçimi güncellendi.', [
        'profile' => $profile,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/upload_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $avatarId = trim((string)($payload['avatar_id'] ?? ''));
    if ($avatarId === '') {
        api_error('avatar_id alanı zorunludur.', 422);
    }

    if (!api_profile_is_allowed_avatar_id($avatarId)) {
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

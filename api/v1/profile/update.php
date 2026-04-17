<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('PUT');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $hasFullName = array_key_exists('full_name', $payload);
    $fullName = trim((string)($payload['full_name'] ?? ''));
    $hasAvatarId = array_key_exists('avatar_id', $payload);
    $avatarId = trim((string)($payload['avatar_id'] ?? ''));

    if (!$hasFullName && !$hasAvatarId) {
        api_error('Güncellenecek alan bulunamadı. full_name veya avatar_id gönderilmelidir.', 422);
    }

    $profileSchema = api_get_profile_schema($pdo);
    $updates = [];
    $profileBeforeUpdate = null;

    if ($hasFullName) {
        if ($fullName === '') {
            api_error('full_name boş bırakılamaz.', 422);
        }

        if (mb_strlen($fullName) > 120) {
            api_error('full_name en fazla 120 karakter olabilir.', 422);
        }

        if (!$profileSchema['full_name']) {
            api_error('Profil adı alanı bu sistemde desteklenmiyor.', 400);
        }

        $updates[$profileSchema['full_name']] = $fullName;
    }

    if ($hasAvatarId) {
        api_profile_assert_avatar_schema_supported($profileSchema);
        $profileBeforeUpdate = api_find_profile_by_user_id($pdo, $userId);
        if (!$profileBeforeUpdate) {
            api_error('Profil bulunamadı.', 404);
        }

        if (!api_profile_is_allowed_avatar_id($avatarId)) {
            api_error('Geçersiz avatar seçimi. Lütfen izin verilen avatarlardan birini seçin.', 422);
        }

        $updates[$profileSchema['avatar_type']] = 'default';
        $updates[$profileSchema['avatar_id']] = $avatarId;
        if (!empty($profileSchema['profile_photo_url'])) {
            $updates[$profileSchema['profile_photo_url']] = null;
        }
    }

    if (empty($updates)) {
        api_error('Güncellenecek geçerli alan bulunamadı.', 400);
    }

    api_update_profile_fields($pdo, $userId, $updates);

    if ($hasAvatarId && is_array($profileBeforeUpdate)) {
        $module = (string)(defined('PROFILE_PHOTOS_UPLOAD_MODULE') ? PROFILE_PHOTOS_UPLOAD_MODULE : 'profile-photos');
        upload_safe_delete((string)($profileBeforeUpdate['profile_photo_url'] ?? ''), $module);
    }

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    api_success('Profil güncellendi.', [
        'profile' => $profile,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

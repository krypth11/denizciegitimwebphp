<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/upload_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    if (!usage_limits_is_user_pro($pdo, $userId)) {
        api_error('Profil fotoğrafı yüklemek için premium üyelik gereklidir.', 403);
    }

    if (!isset($_FILES['photo'])) {
        api_error('photo alanı zorunludur.', 422);
    }

    $profileSchema = api_get_profile_schema($pdo);
    api_profile_assert_photo_schema_supported($profileSchema);

    $currentProfile = api_find_profile_by_user_id($pdo, $userId);
    if (!$currentProfile) {
        api_error('Profil bulunamadı.', 404);
    }

    $module = (string)(defined('PROFILE_PHOTOS_UPLOAD_MODULE') ? PROFILE_PHOTOS_UPLOAD_MODULE : 'profile-photos');
    $module = upload_sanitize_relative_path($module);
    if ($module === '') {
        $module = 'profile-photos';
    }

    $stored = upload_store_image_file(
        $module,
        $userId,
        $_FILES['photo'],
        [
            'max_bytes' => (int)(defined('PROFILE_PHOTO_MAX_BYTES') ? PROFILE_PHOTO_MAX_BYTES : (5 * 1024 * 1024)),
            'filename_prefix' => 'profile-photo',
        ]
    );

    $oldPhoto = (string)($currentProfile['profile_photo_url'] ?? '');

    try {
        api_update_profile_fields($pdo, $userId, [
            $profileSchema['avatar_type'] => 'uploaded',
            $profileSchema['profile_photo_url'] => (string)($stored['relative_path'] ?? ''),
            $profileSchema['avatar_id'] => null,
        ]);
    } catch (Throwable $e) {
        upload_safe_delete((string)($stored['relative_path'] ?? ''), $module);
        throw $e;
    }

    upload_safe_delete($oldPhoto, $module);

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    api_success('Profil fotoğrafı güncellendi.', [
        'profile' => $profile,
    ]);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 422);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

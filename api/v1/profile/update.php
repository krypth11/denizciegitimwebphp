<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('PUT');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $fullName = trim((string)($payload['full_name'] ?? ''));

    if ($fullName === '') {
        api_error('full_name alanı zorunludur.', 422);
    }

    if (mb_strlen($fullName) > 120) {
        api_error('full_name en fazla 120 karakter olabilir.', 422);
    }

    $profileSchema = api_get_profile_schema($pdo);
    if (!$profileSchema['full_name']) {
        api_error('Profil adı alanı bu sistemde desteklenmiyor.', 400);
    }

    api_update_profile_fields($pdo, $userId, [
        $profileSchema['full_name'] => $fullName,
    ]);

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

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('PUT');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $qualificationId = trim((string)($payload['current_qualification_id'] ?? ''));

    if ($qualificationId === '') {
        api_error('current_qualification_id alanı zorunludur.', 422);
    }

    if (!api_qualification_exists($pdo, $qualificationId)) {
        api_error('Geçersiz qualification id.', 422);
    }

    $profileSchema = api_get_profile_schema($pdo);
    if (!$profileSchema['current_qualification_id']) {
        api_error('current_qualification_id alanı bu sistemde desteklenmiyor.', 400);
    }

    $updates = [
        $profileSchema['current_qualification_id'] => $qualificationId,
    ];

    if ($profileSchema['onboarding_completed']) {
        $updates[$profileSchema['onboarding_completed']] = 1;
    }

    api_update_profile_fields($pdo, $userId, $updates);

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    api_success('Current qualification güncellendi.', [
        'profile' => $profile,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    $onboardingCompleted = (bool)($profile['onboarding_completed'] ?? false);
    $currentQualificationId = $profile['current_qualification_id'] ?? null;

    api_success('Onboarding durumu getirildi.', [
        'onboarding_completed' => $onboardingCompleted,
        'current_qualification_id' => $currentQualificationId,
        'needs_onboarding' => !$onboardingCompleted || empty($currentQualificationId),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

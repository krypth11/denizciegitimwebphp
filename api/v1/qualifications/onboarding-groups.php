<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/qualification_heading_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);

    error_log('[QUALIFICATION_HEADING] grouped onboarding endpoint hit');

    $groups = qualification_onboarding_groups($pdo);

    error_log('[QUALIFICATION_HEADING] grouped response count=' . count($groups));

    api_send_json([
        'success' => true,
        'data' => [
            'groups' => $groups,
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

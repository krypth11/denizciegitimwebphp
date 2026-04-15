<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $subscriptionStatus = usage_limits_get_user_subscription_status($pdo, $userId);
    $computedIsPro = usage_limits_is_subscription_active($subscriptionStatus);
    if (!$computedIsPro) {
        usage_limits_subscription_debug_log('offline_premium_required', [
            'endpoint' => 'api/v1/offline/qualifications.php',
            'user_id' => $userId,
            'db_subscription_row' => usage_limits_normalize_subscription_row($subscriptionStatus, $userId),
            'computed_is_pro' => $computedIsPro,
        ]);

        usage_limits_business_error(
            'PREMIUM_REQUIRED',
            'Offline içerik Pro üyelik gerektirir.',
            403
        );
    }

    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'offline.qualifications');

    $items = offline_get_downloadable_qualifications($pdo, $currentQualificationId);

    api_qualification_access_log('offline qualifications returned count', [
        'context' => 'offline.qualifications',
        'count' => count($items),
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('offline qualification returned', [
        'context' => 'offline.qualifications',
        'offline qualification returned' => $currentQualificationId,
    ]);

    api_success('Offline indirilebilir yeterlilikler getirildi.', [
        'qualifications' => $items,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

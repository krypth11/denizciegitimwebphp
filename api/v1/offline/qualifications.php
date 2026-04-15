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
    $beforeRow = $subscriptionStatus;
    $afterRow = $subscriptionStatus;
    $repair = [
        'before' => $beforeRow,
        'after' => $afterRow,
        'repaired' => false,
        'verified_active' => false,
        'rc_app_user_id' => usage_limits_resolve_revenuecat_app_user_id($pdo, $userId, $beforeRow),
        'rc_app_user_id_candidates' => [],
    ];
    $computedIsPro = usage_limits_is_subscription_active($subscriptionStatus);

    if (!$computedIsPro) {
        $repair = usage_limits_try_repair_subscription_status($pdo, $userId, [
            'preferred_rc_app_user_id' => $repair['rc_app_user_id'] ?? null,
        ]);
        $subscriptionStatus = is_array($repair['after'] ?? null) ? $repair['after'] : $subscriptionStatus;
        $afterRow = $subscriptionStatus;
        $computedIsPro = usage_limits_is_subscription_active($subscriptionStatus);

        if (!$computedIsPro && (!empty($repair['verified_active']) || !empty($repair['repaired']))) {
            $computedIsPro = true;
        }

        if ($computedIsPro) {
            usage_limits_subscription_debug_log('offline_premium_access_granted_after_repair', [
                'endpoint' => 'api/v1/offline/qualifications.php',
                'user_id' => $userId,
                'before_row' => usage_limits_normalize_subscription_row(($repair['before'] ?? $beforeRow), $userId),
                'after_row' => usage_limits_normalize_subscription_row(($repair['after'] ?? $afterRow), $userId),
                'computed_is_pro' => $computedIsPro,
                'repaired' => !empty($repair['repaired']),
                'verified_active' => !empty($repair['verified_active']),
                'rc_app_user_id' => $repair['rc_app_user_id'] ?? null,
                'rc_app_user_id_candidates' => $repair['rc_app_user_id_candidates'] ?? [],
            ]);
        }
    }

    if (!$computedIsPro) {
        $beforeNormalized = usage_limits_normalize_subscription_row(($repair['before'] ?? $beforeRow), $userId);
        $afterNormalized = usage_limits_normalize_subscription_row(($repair['after'] ?? $afterRow), $userId);
        usage_limits_subscription_debug_log('offline_premium_required', [
            'endpoint' => 'api/v1/offline/qualifications.php',
            'user_id' => $userId,
            'before_row' => $beforeNormalized,
            'after_row' => $afterNormalized,
            'computed_is_pro' => $computedIsPro,
            'repaired' => !empty($repair['repaired']),
            'verified_active' => !empty($repair['verified_active']),
            'rc_app_user_id' => $repair['rc_app_user_id'] ?? ($afterNormalized['rc_app_user_id'] ?? null),
            'rc_app_user_id_candidates' => $repair['rc_app_user_id_candidates'] ?? [],
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

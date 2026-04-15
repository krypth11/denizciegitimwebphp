<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('GET');

function offline_try_refresh_subscription_from_revenuecat(PDO $pdo, string $userId, array $subscriptionStatus, string $endpoint): array
{
    $normalized = usage_limits_normalize_subscription_row($subscriptionStatus, $userId);
    $rcAppUserId = trim((string)($normalized['rc_app_user_id'] ?? ''));
    if ($rcAppUserId === '' || !usage_limits_revenuecat_verification_enabled()) {
        return $subscriptionStatus;
    }

    usage_limits_subscription_debug_log('offline_rc_fallback_attempt', [
        'endpoint' => $endpoint,
        'user_id' => $userId,
        'db_subscription_row' => $normalized,
        'rc_app_user_id' => $rcAppUserId,
    ]);

    try {
        $truth = usage_limits_fetch_revenuecat_subscription_truth($rcAppUserId, $normalized['entitlement_id'] ?? null);
        $truthIsPro = usage_limits_is_subscription_active($truth);

        usage_limits_subscription_debug_log('offline_rc_fallback_truth', [
            'endpoint' => $endpoint,
            'user_id' => $userId,
            'rc_app_user_id' => $rcAppUserId,
            'truth' => $truth,
            'truth_is_pro' => $truthIsPro,
        ]);

        if ($truthIsPro) {
            usage_limits_upsert_subscription_status($pdo, $userId, [
                'is_pro' => true,
                'plan_code' => $truth['plan_code'] ?? null,
                'entitlement_id' => $truth['entitlement_id'] ?? null,
                'rc_app_user_id' => $truth['rc_app_user_id'] ?? $rcAppUserId,
                'expires_at' => $truth['expires_at'] ?? null,
            ]);

            $refreshed = usage_limits_get_user_subscription_status($pdo, $userId);
            usage_limits_subscription_debug_log('offline_rc_fallback_upserted', [
                'endpoint' => $endpoint,
                'user_id' => $userId,
                'before_subscription_row' => $normalized,
                'after_subscription_row' => usage_limits_normalize_subscription_row($refreshed, $userId),
            ]);

            return $refreshed;
        }
    } catch (Throwable $e) {
        usage_limits_subscription_debug_log('offline_rc_fallback_failed', [
            'endpoint' => $endpoint,
            'user_id' => $userId,
            'rc_app_user_id' => $rcAppUserId,
            'error_message' => $e->getMessage(),
        ]);
    }

    return $subscriptionStatus;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $subscriptionStatus = usage_limits_get_user_subscription_status($pdo, $userId);
    $computedIsPro = usage_limits_is_subscription_active($subscriptionStatus);

    if (!$computedIsPro) {
        $subscriptionStatus = offline_try_refresh_subscription_from_revenuecat(
            $pdo,
            $userId,
            $subscriptionStatus,
            'api/v1/offline/qualifications.php'
        );
        $computedIsPro = usage_limits_is_subscription_active($subscriptionStatus);
    }

    if (!$computedIsPro) {
        $normalizedSubscriptionRow = usage_limits_normalize_subscription_row($subscriptionStatus, $userId);
        usage_limits_subscription_debug_log('offline_premium_required', [
            'endpoint' => 'api/v1/offline/qualifications.php',
            'user_id' => $userId,
            'db_subscription_row' => $normalizedSubscriptionRow,
            'computed_is_pro' => $computedIsPro,
            'rc_app_user_id' => $normalizedSubscriptionRow['rc_app_user_id'] ?? null,
            'expires_at' => $normalizedSubscriptionRow['expires_at'] ?? null,
            'is_pro' => !empty($normalizedSubscriptionRow['is_pro']),
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

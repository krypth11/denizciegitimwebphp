<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $payload = api_get_request_data();

    if (!array_key_exists('is_pro', $payload)) {
        api_error('is_pro zorunludur.', 422);
    }

    $isProRaw = $payload['is_pro'];
    $isPro = filter_var($isProRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($isPro === null && !is_bool($isProRaw) && !in_array($isProRaw, [0, 1, '0', '1'], true)) {
        api_error('is_pro boolean olmalıdır.', 422);
    }

    $planCode = trim((string)($payload['plan_code'] ?? ''));
    if ($planCode !== '' && !in_array($planCode, ['monthly', 'quarterly', 'semiannual', 'annual'], true)) {
        api_error('Geçersiz plan_code.', 422);
    }

    $entitlementId = trim((string)($payload['entitlement_id'] ?? ''));
    $rcAppUserId = trim((string)($payload['rc_app_user_id'] ?? ''));
    $expiresAtRaw = $payload['expires_at'] ?? null;
    $expiresAt = null;
    if ($expiresAtRaw !== null) {
        $expiresAtText = trim((string)$expiresAtRaw);
        if ($expiresAtText !== '') {
            $ts = strtotime($expiresAtText);
            if ($ts === false) {
                api_error('Geçersiz expires_at.', 422);
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }
    }

    $status = usage_limits_upsert_subscription_status($pdo, $userId, [
        'is_pro' => (bool)$isPro,
        'plan_code' => ($planCode !== '' ? $planCode : null),
        'entitlement_id' => ($entitlementId !== '' ? $entitlementId : null),
        'rc_app_user_id' => ($rcAppUserId !== '' ? $rcAppUserId : null),
        'expires_at' => $expiresAt,
    ]);

    api_success('Abonelik durumu senkronize edildi.', [
        'subscription' => [
            'is_pro' => (bool)($status['is_pro'] ?? false),
            'plan_code' => $status['plan_code'] ?? null,
            'entitlement_id' => $status['entitlement_id'] ?? null,
            'rc_app_user_id' => $status['rc_app_user_id'] ?? null,
            'expires_at' => $status['expires_at'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

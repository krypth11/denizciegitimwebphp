<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 2) . '/includes/user_lifecycle_helper.php';

api_require_method('POST');

function subscription_sync_debug_enabled(): bool
{
    return usage_limits_subscription_debug_enabled();
}

function subscription_sync_debug_log(string $message, array $context = []): void
{
    usage_limits_subscription_debug_log($message, $context);
}

function subscription_sync_validation_error(string $message, array $context = []): void
{
    subscription_sync_debug_log('validation_error', [
        'message' => $message,
        'context' => $context,
    ]);

    api_error($message, 422);
}

function subscription_sync_parse_optional_bool(array $payload, string $key): ?bool
{
    if (!array_key_exists($key, $payload)) {
        return null;
    }

    $raw = $payload[$key];
    if (is_bool($raw)) {
        return $raw;
    }

    if (is_int($raw) || is_float($raw)) {
        if ((int)$raw === 1) {
            return true;
        }
        if ((int)$raw === 0) {
            return false;
        }
    }

    if (is_string($raw)) {
        $normalized = strtolower(trim($raw));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }
    }

    subscription_sync_validation_error($key . ' boolean olmalıdır.', ['raw_value' => $raw]);
}

function subscription_sync_parse_optional_string(array $payload, string $key, int $maxLen = 191): ?string
{
    if (!array_key_exists($key, $payload)) {
        return null;
    }

    $value = trim((string)$payload[$key]);
    if ($value === '') {
        return null;
    }

    if ($maxLen > 0 && mb_strlen($value) > $maxLen) {
        subscription_sync_validation_error('Geçersiz ' . $key . '.', ['length' => mb_strlen($value)]);
    }

    return $value;
}

function subscription_sync_parse_expires_at(array $payload): ?string
{
    if (!array_key_exists('expires_at', $payload)) {
        return null;
    }

    $raw = $payload['expires_at'];
    if ($raw === null) {
        return null;
    }

    $normalized = usage_limits_normalize_datetime_to_mysql($raw);
    if ($normalized === null && trim((string)$raw) !== '') {
        subscription_sync_validation_error('Geçersiz expires_at.', ['raw_value' => $raw]);
    }

    return $normalized;
}

function subscription_sync_apply_lifecycle(PDO $pdo, string $userId, array $beforeStatus, array $afterStatus): void
{
    $beforeIsPro = !empty($beforeStatus['is_active']);
    $afterIsPro = !empty($afterStatus['is_active']);
    $beforeExpiry = (string)($beforeStatus['expires_at'] ?? '');
    $afterExpiry = (string)($afterStatus['expires_at'] ?? '');
    $changes = usage_limits_get_subscription_state_changes($beforeStatus, $afterStatus);

    if (!$beforeIsPro && $afterIsPro) {
        user_lifecycle_log_event(
            $pdo,
            $userId,
            'premium_started',
            'Premium başlatıldı',
            'subscription.sync',
            null,
            ($afterExpiry !== '' ? $afterExpiry : 'active'),
            ['plan_code' => $afterStatus['plan_code'] ?? null]
        );
        return;
    }

    if ($beforeIsPro && $afterIsPro && !empty($changes) && $beforeExpiry !== $afterExpiry && $afterExpiry !== '') {
        user_lifecycle_log_event(
            $pdo,
            $userId,
            'premium_renewed',
            'Premium yenilendi',
            'subscription.sync',
            ($beforeExpiry !== '' ? $beforeExpiry : null),
            $afterExpiry,
            ['plan_code' => $afterStatus['plan_code'] ?? null]
        );
        return;
    }

    if ($beforeIsPro && !$afterIsPro) {
        $eventType = ($beforeExpiry !== '' && strtotime($beforeExpiry) !== false && strtotime($beforeExpiry) <= time())
            ? 'premium_expired'
            : 'premium_cancelled';
        $title = $eventType === 'premium_expired' ? 'Premium süresi doldu' : 'Premium iptal edildi';

        user_lifecycle_log_event(
            $pdo,
            $userId,
            $eventType,
            $title,
            'subscription.sync',
            ($beforeExpiry !== '' ? $beforeExpiry : 'active'),
            'free',
            ['plan_code' => $beforeStatus['plan_code'] ?? null]
        );
    }
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $payload = api_get_request_data();

    subscription_sync_debug_log('request_received', [
        'authenticated_user_id' => $userId,
        'request_payload' => $payload,
        'server_side_verification_enabled' => usage_limits_revenuecat_verification_enabled(),
    ]);

    $beforeStatus = usage_limits_get_user_subscription_status($pdo, $userId);

    $clientIsPro = subscription_sync_parse_optional_bool($payload, 'is_pro');
    $clientPlanCode = subscription_sync_parse_optional_string($payload, 'plan_code');
    $clientEntitlementId = subscription_sync_parse_optional_string($payload, 'entitlement_id');
    $clientRcAppUserId = subscription_sync_parse_optional_string($payload, 'rc_app_user_id');
    $clientExpiresAt = subscription_sync_parse_expires_at($payload);

    $verificationMode = 'client_payload';
    $verificationTruth = null;

    if (usage_limits_revenuecat_verification_enabled()) {
        if ($clientRcAppUserId !== null) {
            try {
                $verificationTruth = usage_limits_fetch_revenuecat_subscription_truth($clientRcAppUserId, $clientEntitlementId);
                $verificationMode = 'revenuecat_server';
            } catch (Throwable $verificationError) {
                subscription_sync_debug_log('revenuecat_verification_unavailable', [
                    'authenticated_user_id' => $userId,
                    'client_rc_app_user_id' => $clientRcAppUserId,
                    'verification_mode' => 'revenuecat_server',
                    'verification_truth' => null,
                    'error_message' => $verificationError->getMessage(),
                ]);
                throw $verificationError;
            }
        } else {
            subscription_sync_debug_log('revenuecat_verification_skipped_missing_rc_app_user_id', [
                'authenticated_user_id' => $userId,
                'verification_mode' => 'client_payload',
                'client_rc_app_user_id' => $clientRcAppUserId,
            ]);
        }
    }

    if ($verificationTruth === null && $clientIsPro === null) {
        subscription_sync_validation_error('is_pro zorunludur (RevenueCat doğrulaması yoksa).');
    }

    $effectiveState = [
        'is_pro' => $verificationTruth['is_pro'] ?? (bool)$clientIsPro,
        'plan_code' => $verificationTruth['plan_code'] ?? $clientPlanCode,
        'entitlement_id' => $verificationTruth['entitlement_id'] ?? $clientEntitlementId,
        'rc_app_user_id' => $verificationTruth['rc_app_user_id'] ?? $clientRcAppUserId,
        'expires_at' => $verificationTruth['expires_at'] ?? $clientExpiresAt,
    ];

    if (!empty($effectiveState['is_pro']) && empty($effectiveState['expires_at'])) {
        throw new RuntimeException('Premium kullanıcı için expires_at boş olamaz.');
    }

    subscription_sync_debug_log('computed_effective_state', [
        'authenticated_user_id' => $userId,
        'client_rc_app_user_id' => $clientRcAppUserId,
        'verification_mode' => $verificationMode,
        'verification_truth' => $verificationTruth,
        'computed_is_pro' => !empty($effectiveState['is_pro']),
    ]);

    subscription_sync_debug_log('before_upsert', [
        'authenticated_user_id' => $userId,
        'verification_mode' => $verificationMode,
        'before_subscription_row' => usage_limits_normalize_subscription_row($beforeStatus, $userId),
        'client_state' => [
            'is_pro' => $clientIsPro,
            'plan_code' => $clientPlanCode,
            'entitlement_id' => $clientEntitlementId,
            'rc_app_user_id' => $clientRcAppUserId,
            'expires_at' => $clientExpiresAt,
        ],
        'verification_truth' => $verificationTruth,
        'effective_state' => $effectiveState,
    ]);

    usage_limits_upsert_subscription_status($pdo, $userId, $effectiveState);
    $afterStatus = usage_limits_get_user_subscription_status($pdo, $userId);

    $expectedIsPro = !empty($effectiveState['is_pro']);
    $expectedExpiresAt = usage_limits_normalize_datetime_to_mysql($effectiveState['expires_at'] ?? null);
    $actualIsPro = ((int)($afterStatus['is_pro'] ?? 0) === 1);
    $actualExpiresAt = usage_limits_normalize_datetime_to_mysql($afterStatus['expires_at'] ?? null);

    if ($expectedIsPro && (!$actualIsPro || empty($actualExpiresAt))) {
        throw new RuntimeException(
            'Subscription upsert doğrulaması başarısız: expected is_pro=1 ve expires_at dolu, actual is_pro='
            . ($actualIsPro ? '1' : '0')
            . ', expires_at=' . (string)$actualExpiresAt
        );
    }

    if (!$expectedIsPro && $actualIsPro && $expectedExpiresAt !== null) {
        throw new RuntimeException(
            'Subscription upsert doğrulaması başarısız: free beklenirken DB is_pro=1 döndü.'
        );
    }

    $changes = usage_limits_get_subscription_state_changes($beforeStatus, $afterStatus);
    $stateChanged = !empty($changes);
    $computedIsPro = usage_limits_is_subscription_active($afterStatus);

    subscription_sync_debug_log('after_upsert', [
        'authenticated_user_id' => $userId,
        'after_subscription_row' => usage_limits_normalize_subscription_row($afterStatus, $userId),
        'state_changed' => $stateChanged,
        'changes' => $changes,
        'computed_is_pro' => $computedIsPro,
    ]);

    subscription_sync_apply_lifecycle($pdo, $userId, $beforeStatus, $afterStatus);

    $response = [
        'success' => true,
    ];

    if (subscription_sync_debug_enabled()) {
        $response['debug'] = [
            'subscription_row' => usage_limits_normalize_subscription_row($afterStatus, $userId),
            'is_active' => (bool)($afterStatus['is_active'] ?? false),
            'expires_at' => $afterStatus['expires_at'] ?? null,
            'rc_app_user_id' => $afterStatus['rc_app_user_id'] ?? ($effectiveState['rc_app_user_id'] ?? null),
            'user_id' => $userId,
            'computed_is_pro' => $computedIsPro,
            'state_changed' => $stateChanged,
            'verification_mode' => $verificationMode,
        ];
    }

    api_send_json($response, 200);
} catch (Throwable $e) {
    usage_limits_log_exception('subscription_sync_failed', $e, [
        'endpoint' => 'api/v1/subscription/sync.php',
        'authenticated_user_id' => $userId ?? null,
        'request_payload' => $payload ?? null,
    ]);

    api_send_json([
        'success' => false,
        'message' => 'Abonelik senkronizasyonu sırasında bir hata oluştu.',
        'data' => null,
        'debug' => subscription_sync_debug_enabled() ? [
            'exception_message' => $e->getMessage(),
            'user_id' => $userId ?? null,
        ] : null,
    ], 500);
}

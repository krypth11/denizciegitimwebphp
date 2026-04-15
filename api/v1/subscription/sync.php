<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 2) . '/includes/user_lifecycle_helper.php';

api_require_method('POST');

function subscription_sync_validation_error(string $message, array $context = []): void
{
    usage_limits_subscription_debug_log('validation_error', [
        'message' => $message,
        'context' => $context,
    ]);

    api_error($message, 422);
}

function subscription_sync_parse_bool_required(array $payload, string $key): bool
{
    if (!array_key_exists($key, $payload)) {
        subscription_sync_validation_error($key . ' zorunludur.');
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
        $truthy = ['1', 'true', 'yes', 'on'];
        $falsy = ['0', 'false', 'no', 'off', ''];
        if (in_array($normalized, $truthy, true)) {
            return true;
        }
        if (in_array($normalized, $falsy, true)) {
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

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $payload = api_get_request_data();

    usage_limits_subscription_debug_log('request_received', [
        'user_id' => $userId,
        'payload' => $payload,
    ]);

    $isPro = subscription_sync_parse_bool_required($payload, 'is_pro');
    $planCode = subscription_sync_parse_optional_string($payload, 'plan_code');
    $entitlementId = subscription_sync_parse_optional_string($payload, 'entitlement_id');
    $rcAppUserId = subscription_sync_parse_optional_string($payload, 'rc_app_user_id');
    $expiresAt = subscription_sync_parse_expires_at($payload);

    $beforeStatus = usage_limits_get_user_subscription_status($pdo, $userId);
    $incomingState = [
        'exists' => !empty($beforeStatus['exists']),
        'user_id' => $userId,
        'is_pro' => $isPro,
        'plan_code' => $planCode,
        'entitlement_id' => $entitlementId,
        'rc_app_user_id' => $rcAppUserId,
        'expires_at' => $expiresAt,
    ];

    $incomingChanges = usage_limits_get_subscription_state_changes($beforeStatus, $incomingState);
    usage_limits_subscription_debug_log('normalized_payload', [
        'user_id' => $userId,
        'normalized_expires_at' => $expiresAt,
        'before' => $beforeStatus,
        'incoming' => usage_limits_normalize_subscription_row($incomingState, $userId),
        'incoming_changes' => $incomingChanges,
    ]);

    $status = usage_limits_upsert_subscription_status($pdo, $userId, [
        'is_pro' => $isPro,
        'plan_code' => $planCode,
        'entitlement_id' => $entitlementId,
        'rc_app_user_id' => $rcAppUserId,
        'expires_at' => $expiresAt,
    ]);

    $changes = usage_limits_get_subscription_state_changes($beforeStatus, $status);
    usage_limits_subscription_debug_log('upsert_completed', [
        'user_id' => $userId,
        'before' => $beforeStatus,
        'after' => $status,
        'changes' => $changes,
        'updated' => !empty($changes),
    ]);

    $beforeIsPro = !empty($beforeStatus['is_active']);
    $afterIsPro = !empty($status['is_active']);
    $beforeExpiry = (string)($beforeStatus['expires_at'] ?? '');
    $afterExpiry = (string)($status['expires_at'] ?? '');

    if (!$beforeIsPro && $afterIsPro) {
        user_lifecycle_log_event(
            $pdo,
            $userId,
            'premium_started',
            'Premium başlatıldı',
            'subscription.sync',
            null,
            ($afterExpiry !== '' ? $afterExpiry : 'active'),
            ['plan_code' => $status['plan_code'] ?? null]
        );
    } elseif ($beforeIsPro && $afterIsPro && !empty($changes) && $beforeExpiry !== $afterExpiry && $afterExpiry !== '') {
        user_lifecycle_log_event(
            $pdo,
            $userId,
            'premium_renewed',
            'Premium yenilendi',
            'subscription.sync',
            ($beforeExpiry !== '' ? $beforeExpiry : null),
            $afterExpiry,
            ['plan_code' => $status['plan_code'] ?? null]
        );
    } elseif ($beforeIsPro && !$afterIsPro) {
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

    api_success('Abonelik durumu senkronize edildi.', [
        'subscription' => [
            'is_pro' => (bool)($status['is_pro'] ?? false),
            'is_active' => (bool)($status['is_active'] ?? false),
            'plan_code' => $status['plan_code'] ?? null,
            'entitlement_id' => $status['entitlement_id'] ?? null,
            'rc_app_user_id' => $status['rc_app_user_id'] ?? null,
            'expires_at' => $status['expires_at'] ?? null,
        ],
        'subscription_state' => [
            'before' => usage_limits_normalize_subscription_row($beforeStatus, $userId),
            'after' => usage_limits_normalize_subscription_row($status, $userId),
            'changes' => $changes,
            'updated' => !empty($changes),
        ],
    ]);
} catch (Throwable $e) {
    usage_limits_subscription_debug_log('sync_failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

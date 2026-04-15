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
        'payload' => $payload,
        'request_payload' => $payload,
        'server_side_verification_enabled' => usage_limits_revenuecat_verification_enabled(),
    ]);

    $beforeStatus = usage_limits_get_user_subscription_status($pdo, $userId);

    $clientIsPro = subscription_sync_parse_optional_bool($payload, 'is_pro');
    $clientPlanCode = subscription_sync_parse_optional_string($payload, 'plan_code');
    $clientEntitlementId = subscription_sync_parse_optional_string($payload, 'entitlement_id');
    $clientRcAppUserId = subscription_sync_parse_optional_string($payload, 'rc_app_user_id');
    $currentRcAppUserId = subscription_sync_parse_optional_string($payload, 'current_rc_app_user_id');
    $originalAppUserId = subscription_sync_parse_optional_string($payload, 'original_app_user_id');
    $loggedInAppUserId = subscription_sync_parse_optional_string($payload, 'logged_in_app_user_id');
    $productId = subscription_sync_parse_optional_string($payload, 'product_id');
    $entitlementActive = subscription_sync_parse_optional_bool($payload, 'entitlement_active');
    $purchaseSource = subscription_sync_parse_optional_string($payload, 'purchase_source');
    $clientExpiresAt = subscription_sync_parse_expires_at($payload);
    $clientFallbackEligible =
        (!empty($clientIsPro))
        || (!empty($entitlementActive))
        || ($productId !== null && $productId !== '');
    $existingState = usage_limits_normalize_subscription_row($beforeStatus, $userId);
    $resolverOptions = [
        'preferred_rc_app_user_id' => $currentRcAppUserId ?? $originalAppUserId ?? $clientRcAppUserId ?? $loggedInAppUserId,
        'current_rc_app_user_id' => $currentRcAppUserId,
        'original_app_user_id' => $originalAppUserId,
        'rc_app_user_id' => $clientRcAppUserId,
        'logged_in_app_user_id' => $loggedInAppUserId,
        'latest_sync_payload' => [
            'current_rc_app_user_id' => $currentRcAppUserId,
            'original_app_user_id' => $originalAppUserId,
            'rc_app_user_id' => $clientRcAppUserId,
            'logged_in_app_user_id' => $loggedInAppUserId,
        ],
    ];
    $rcAppUserIdCandidates = usage_limits_collect_revenuecat_app_user_id_candidates($pdo, $userId, $beforeStatus, $resolverOptions);
    $resolvedRcAppUserId = $currentRcAppUserId
        ?? $originalAppUserId
        ?? $clientRcAppUserId
        ?? $loggedInAppUserId
        ?? usage_limits_resolve_revenuecat_app_user_id($pdo, $userId, $beforeStatus, $resolverOptions);
    $selectedRcAppUserId = $resolvedRcAppUserId;

    $verificationMode = 'client_payload';
    $verificationTruth = null;
    $verificationFailed = false;
    $verificationErrorMessage = null;

    if (usage_limits_revenuecat_verification_enabled()) {
        if ($resolvedRcAppUserId !== null) {
            try {
                $verificationTruth = usage_limits_fetch_revenuecat_subscription_truth($resolvedRcAppUserId, $clientEntitlementId);
                $verificationMode = 'revenuecat_server';
                $resolvedRcAppUserId = $verificationTruth['rc_app_user_id'] ?? $resolvedRcAppUserId;
                $selectedRcAppUserId = $resolvedRcAppUserId;
            } catch (Throwable $verificationError) {
                $verificationFailed = true;
                $verificationErrorMessage = $verificationError->getMessage();
                $verificationMode = $clientFallbackEligible
                    ? 'client_payload_fallback_after_verification_failure'
                    : 'verification_failed_existing_row_fallback';
                subscription_sync_debug_log('revenuecat_verification_unavailable', [
                    'authenticated_user_id' => $userId,
                    'payload' => $payload,
                    'client_rc_app_user_id' => $clientRcAppUserId,
                    'current_rc_app_user_id' => $currentRcAppUserId,
                    'original_app_user_id' => $originalAppUserId,
                    'logged_in_app_user_id' => $loggedInAppUserId,
                    'selected_rc_app_user_id' => $selectedRcAppUserId,
                    'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
                    'resolved_rc_app_user_id' => $resolvedRcAppUserId,
                    'verification_mode' => $verificationMode,
                    'verification_truth' => null,
                    'client_fallback_eligible' => $clientFallbackEligible,
                    'product_id' => $productId,
                    'entitlement_active' => $entitlementActive,
                    'purchase_source' => $purchaseSource,
                    'error_message' => $verificationError->getMessage(),
                ]);
            }
        } else {
            subscription_sync_debug_log('revenuecat_verification_skipped_missing_rc_app_user_id', [
                'authenticated_user_id' => $userId,
                'payload' => $payload,
                'verification_mode' => 'client_payload',
                'client_rc_app_user_id' => $clientRcAppUserId,
                'current_rc_app_user_id' => $currentRcAppUserId,
                'original_app_user_id' => $originalAppUserId,
                'logged_in_app_user_id' => $loggedInAppUserId,
                'selected_rc_app_user_id' => $selectedRcAppUserId,
                'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
                'resolved_rc_app_user_id' => $resolvedRcAppUserId,
                'product_id' => $productId,
                'entitlement_active' => $entitlementActive,
                'purchase_source' => $purchaseSource,
            ]);
        }
    }

    if ($verificationTruth === null && $clientIsPro === null && empty($existingState['exists'])) {
        subscription_sync_validation_error('is_pro zorunludur (RevenueCat doğrulaması yoksa).');
    }

    $clientExpiresAtTs = ($clientExpiresAt !== null ? strtotime($clientExpiresAt) : false);
    $clientHasFutureExpiry = ($clientExpiresAtTs !== false && $clientExpiresAtTs > time());
    $effectiveClientIsPro =
        ($clientIsPro !== null ? (bool)$clientIsPro : false)
        || ($entitlementActive !== null ? (bool)$entitlementActive : false)
        || ($productId !== null && $productId !== '');

    $effectiveState = [
        'is_pro' => $verificationTruth['is_pro']
            ?? $effectiveClientIsPro
            ?? (bool)($existingState['is_pro'] ?? false),
        'plan_code' => $verificationTruth['plan_code']
            ?? $clientPlanCode
            ?? $productId
            ?? ($existingState['plan_code'] ?? null),
        'entitlement_id' => $verificationTruth['entitlement_id']
            ?? $clientEntitlementId
            ?? ($existingState['entitlement_id'] ?? null),
        'rc_app_user_id' => $verificationTruth['rc_app_user_id']
            ?? $resolvedRcAppUserId
            ?? ($existingState['rc_app_user_id'] ?? null),
        'expires_at' => $verificationTruth['expires_at']
            ?? $clientExpiresAt
            ?? ($existingState['expires_at'] ?? null),
    ];

    if ($verificationTruth === null && $verificationFailed && usage_limits_is_subscription_active($beforeStatus) && empty($effectiveState['is_pro'])) {
        $effectiveState = [
            'is_pro' => true,
            'plan_code' => $existingState['plan_code'] ?? null,
            'entitlement_id' => $existingState['entitlement_id'] ?? null,
            'rc_app_user_id' => $existingState['rc_app_user_id'] ?? $clientRcAppUserId ?? $resolvedRcAppUserId,
            'expires_at' => $existingState['expires_at'] ?? $clientExpiresAt,
        ];
        $verificationMode = 'existing_row_preserved_after_verification_failure';
    }

    subscription_sync_debug_log('computed_effective_state', [
        'authenticated_user_id' => $userId,
        'payload' => $payload,
        'client_rc_app_user_id' => $clientRcAppUserId,
        'current_rc_app_user_id' => $currentRcAppUserId,
        'original_app_user_id' => $originalAppUserId,
        'logged_in_app_user_id' => $loggedInAppUserId,
        'selected_rc_app_user_id' => $selectedRcAppUserId,
        'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
        'resolved_rc_app_user_id' => $resolvedRcAppUserId,
        'verification_mode' => $verificationMode,
        'verification_truth' => $verificationTruth,
        'verification_failed' => $verificationFailed,
        'verification_error_message' => $verificationErrorMessage,
        'client_fallback_eligible' => $clientFallbackEligible,
        'product_id' => $productId,
        'entitlement_active' => $entitlementActive,
        'purchase_source' => $purchaseSource,
        'effective_client_is_pro' => $effectiveClientIsPro,
        'computed_is_pro' => !empty($effectiveState['is_pro']),
    ]);

    subscription_sync_debug_log('before_upsert', [
        'authenticated_user_id' => $userId,
        'payload' => $payload,
        'verification_mode' => $verificationMode,
        'selected_rc_app_user_id' => $selectedRcAppUserId,
        'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
        'resolved_rc_app_user_id' => $resolvedRcAppUserId,
        'before_subscription_row' => usage_limits_normalize_subscription_row($beforeStatus, $userId),
        'client_state' => [
            'is_pro' => $clientIsPro,
            'plan_code' => $clientPlanCode,
            'entitlement_id' => $clientEntitlementId,
            'rc_app_user_id' => $clientRcAppUserId,
            'current_rc_app_user_id' => $currentRcAppUserId,
            'original_app_user_id' => $originalAppUserId,
            'logged_in_app_user_id' => $loggedInAppUserId,
            'product_id' => $productId,
            'entitlement_active' => $entitlementActive,
            'purchase_source' => $purchaseSource,
            'expires_at' => $clientExpiresAt,
        ],
        'verification_truth' => $verificationTruth,
        'effective_state' => $effectiveState,
        'effective_client_is_pro' => $effectiveClientIsPro,
    ]);

    usage_limits_upsert_subscription_status($pdo, $userId, $effectiveState);
    $afterStatus = usage_limits_get_user_subscription_status($pdo, $userId);

    $expectedIsPro = !empty($effectiveState['is_pro']);
    $actualIsPro = ((int)($afterStatus['is_pro'] ?? 0) === 1);
    $postCheckRow = usage_limits_normalize_subscription_row($afterStatus, $userId);
    $postCheckComputedIsPro = usage_limits_is_subscription_active($afterStatus);

    subscription_sync_debug_log('subscription_sync_post_check', [
        'authenticated_user_id' => $userId,
        'verification_mode' => $verificationMode,
        'selected_rc_app_user_id' => $selectedRcAppUserId,
        'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
        'effective_state' => $effectiveState,
        'is_pro' => !empty($postCheckRow['is_pro']),
        'entitlement_id' => $postCheckRow['entitlement_id'] ?? null,
        'rc_app_user_id' => $postCheckRow['rc_app_user_id'] ?? null,
        'expires_at' => $postCheckRow['expires_at'] ?? null,
        'computed_is_pro' => $postCheckComputedIsPro,
    ]);

    if ($expectedIsPro && !$actualIsPro) {
        subscription_sync_debug_log('subscription_sync_post_check_mismatch', [
            'authenticated_user_id' => $userId,
            'verification_mode' => $verificationMode,
            'verification_failed' => $verificationFailed,
            'verification_error_message' => $verificationErrorMessage,
            'selected_rc_app_user_id' => $selectedRcAppUserId,
            'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
            'verification_truth' => $verificationTruth,
            'effective_state' => $effectiveState,
            'expected_is_pro' => true,
            'actual_is_pro' => false,
            'after_subscription_row' => $postCheckRow,
        ]);
    }

    $changes = usage_limits_get_subscription_state_changes($beforeStatus, $afterStatus);
    $stateChanged = !empty($changes);
    $computedIsPro = usage_limits_is_subscription_active($afterStatus);
    $beforeComputedIsPro = usage_limits_is_subscription_active($beforeStatus);
    $repairedOrNot = (!$beforeComputedIsPro && $computedIsPro);

    subscription_sync_debug_log('after_upsert', [
        'authenticated_user_id' => $userId,
        'payload' => $payload,
        'current_rc_app_user_id' => $currentRcAppUserId,
        'original_app_user_id' => $originalAppUserId,
        'logged_in_app_user_id' => $loggedInAppUserId,
        'selected_rc_app_user_id' => $selectedRcAppUserId,
        'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
        'product_id' => $productId,
        'entitlement_active' => $entitlementActive,
        'purchase_source' => $purchaseSource,
        'resolved_rc_app_user_id' => $resolvedRcAppUserId,
        'verification_truth' => $verificationTruth,
        'before_subscription_row' => usage_limits_normalize_subscription_row($beforeStatus, $userId),
        'after_subscription_row' => usage_limits_normalize_subscription_row($afterStatus, $userId),
        'state_changed' => $stateChanged,
        'changes' => $changes,
        'computed_is_pro' => $computedIsPro,
        'repaired_or_not' => $repairedOrNot,
    ]);

    subscription_sync_debug_log('sync_result_summary', [
        'authenticated_user_id' => $userId,
        'payload' => $payload,
        'request_payload' => $payload,
        'client_rc_app_user_id' => $clientRcAppUserId,
        'current_rc_app_user_id' => $currentRcAppUserId,
        'original_app_user_id' => $originalAppUserId,
        'logged_in_app_user_id' => $loggedInAppUserId,
        'selected_rc_app_user_id' => $selectedRcAppUserId,
        'all_candidate_rc_app_user_ids' => $rcAppUserIdCandidates,
        'product_id' => $productId,
        'entitlement_active' => $entitlementActive,
        'purchase_source' => $purchaseSource,
        'resolved_rc_app_user_id' => $resolvedRcAppUserId,
        'verification_mode' => $verificationMode,
        'verification_truth' => $verificationTruth,
        'before_subscription_row' => usage_limits_normalize_subscription_row($beforeStatus, $userId),
        'after_subscription_row' => usage_limits_normalize_subscription_row($afterStatus, $userId),
        'computed_is_pro' => $computedIsPro,
        'repaired_or_not' => $repairedOrNot,
        'state_changed' => $stateChanged,
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

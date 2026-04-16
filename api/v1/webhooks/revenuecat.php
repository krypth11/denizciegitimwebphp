<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/subscription_management_helper.php';

api_require_method('POST');

$rawBody = (string)(file_get_contents('php://input') ?: '');
$headers = subscription_mgmt_headers();
$sourceIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
$secret = subscription_mgmt_webhook_secret();

if (!subscription_mgmt_validate_secret($headers, $secret)) {
    api_send_json([
        'success' => false,
        'message' => 'Webhook yetkilendirme doğrulaması başarısız.',
        'data' => ['accepted' => false],
    ], 401);
}

$payload = subscription_mgmt_payload_to_array($rawBody);
if (!is_array($payload)) {
    api_send_json([
        'success' => false,
        'message' => 'Geçersiz JSON payload.',
        'data' => ['accepted' => false, 'reason' => 'invalid_json'],
    ], 200);
}

$event = subscription_mgmt_extract_event_payload($payload);
$eventTypeRaw = trim((string)($event['type'] ?? $event['event_type'] ?? ''));
$eventType = subscription_mgmt_normalize_event_type($eventTypeRaw);
$eventId = subscription_mgmt_derive_event_id($event, $rawBody);
$eventTimestamp = subscription_mgmt_extract_datetime($event);
$appUserId = trim((string)($event['app_user_id'] ?? ''));
$originalAppUserId = trim((string)($event['original_app_user_id'] ?? ''));
$store = trim((string)($event['store'] ?? ''));
$productId = trim((string)($event['product_id'] ?? $event['product_identifier'] ?? ''));
$entitlementId = trim((string)($event['entitlement_id'] ?? ''));
$rcAppUserId = $appUserId !== '' ? $appUserId : ($originalAppUserId !== '' ? $originalAppUserId : null);
$aliases = subscription_mgmt_extract_aliases($event);
$environment = trim((string)($event['environment'] ?? ''));
$provider = 'revenuecat';

$existing = subscription_mgmt_find_existing_event($pdo, $eventId);
if ($existing) {
    try {
        subscription_mgmt_insert_webhook_event($pdo, [
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type_raw' => $eventTypeRaw,
            'event_type' => $eventType,
            'environment' => $environment !== '' ? $environment : null,
            'app_user_id' => $appUserId !== '' ? $appUserId : null,
            'original_app_user_id' => $originalAppUserId !== '' ? $originalAppUserId : null,
            'aliases_json' => subscription_mgmt_safe_json($aliases),
            'rc_app_user_id' => $rcAppUserId,
            'is_matched' => 0,
            'is_duplicate' => 1,
            'process_status' => 'duplicate',
            'error_message' => 'Duplicate event skipped.',
            'payload_json' => subscription_mgmt_safe_json($payload),
            'headers_json' => subscription_mgmt_safe_json($headers),
            'source_ip' => $sourceIp !== '' ? $sourceIp : null,
            'event_timestamp' => $eventTimestamp,
        ]);
    } catch (Throwable $ignored) {
    }

    api_send_json([
        'success' => true,
        'message' => 'Duplicate event algılandı, tekrar işlenmedi.',
        'data' => [
            'accepted' => true,
            'duplicate' => true,
            'event_id' => $eventId,
            'event_type' => $eventType,
        ],
    ], 200);
}

$eventRowId = null;
$historyInserted = false;

try {
    $rcCandidates = subscription_mgmt_collect_rc_candidates($event);
    $matched = subscription_mgmt_find_user_id($pdo, $rcCandidates);
    $matchedUserId = $matched['user_id'] ?? null;
    $matchedVia = $matched['matched_via'] ?? null;
    $beforeStatus = [];
    $afterStatus = [];
    $processStatus = 'processed';
    $errorMessage = null;

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->beginTransaction();

    if ($matchedUserId) {
        $beforeStatus = usage_limits_get_user_subscription_status($pdo, $matchedUserId);
        $nextState = subscription_mgmt_compute_next_state($beforeStatus, $event, $eventType);
        usage_limits_upsert_subscription_status($pdo, $matchedUserId, $nextState);
        $afterStatus = usage_limits_get_user_subscription_status($pdo, $matchedUserId);

        if (!empty($afterStatus['is_pro']) && usage_limits_is_expired_pro_row($afterStatus)) {
            usage_limits_self_heal_expired_subscription_row($pdo, $matchedUserId, $afterStatus, ['source' => 'revenuecat.webhook']);
            $afterStatus = usage_limits_get_user_subscription_status($pdo, $matchedUserId);
            $processStatus = 'conflict';
            $errorMessage = 'expired but stale state repaired';
        }

        $processStatus = $errorMessage ? $processStatus : subscription_mgmt_process_status_from_result($beforeStatus, $afterStatus);

        $eventRowId = subscription_mgmt_insert_webhook_event($pdo, [
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type_raw' => $eventTypeRaw,
            'event_type' => $eventType,
            'environment' => $environment !== '' ? $environment : null,
            'app_user_id' => $appUserId !== '' ? $appUserId : null,
            'original_app_user_id' => $originalAppUserId !== '' ? $originalAppUserId : null,
            'aliases_json' => subscription_mgmt_safe_json($aliases),
            'rc_app_user_id' => $nextState['rc_app_user_id'] ?? ($appUserId !== '' ? $appUserId : null),
            'user_id' => $matchedUserId,
            'is_matched' => 1,
            'is_duplicate' => 0,
            'process_status' => $processStatus,
            'error_message' => $errorMessage,
            'payload_json' => subscription_mgmt_safe_json($payload),
            'headers_json' => subscription_mgmt_safe_json($headers),
            'source_ip' => $sourceIp !== '' ? $sourceIp : null,
            'event_timestamp' => $eventTimestamp,
        ]);

        subscription_mgmt_insert_history($pdo, [
            'user_id' => $matchedUserId,
            'webhook_event_id' => $eventRowId,
            'source_event_id' => $eventId,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'plan_code' => $nextState['plan_code'] ?? null,
            'provider' => $provider,
            'store' => $store !== '' ? $store : null,
            'entitlement_id' => ($nextState['entitlement_id'] ?? null) ?: ($entitlementId !== '' ? $entitlementId : null),
            'old_value' => !empty(usage_limits_is_subscription_active($beforeStatus)) ? 'premium_active' : 'free',
            'new_value' => !empty(usage_limits_is_subscription_active($afterStatus)) ? 'premium_active' : 'free',
            'source' => 'revenuecat.webhook',
            'event_at' => $eventTimestamp,
            'event_title' => $eventType,
            'product_id' => ($nextState['plan_code'] ?? null) ?: ($productId !== '' ? $productId : null),
            'rc_app_user_id' => ($nextState['rc_app_user_id'] ?? null) ?: $rcAppUserId,
            'meta_json' => subscription_mgmt_safe_json([
                'matched_via' => $matchedVia,
                'event_type_raw' => $eventTypeRaw,
                'event_type' => $eventType,
                'rc_candidates' => $rcCandidates,
                'source_event_id' => $eventId,
                'store' => $store !== '' ? $store : null,
                'product_id' => $productId !== '' ? $productId : null,
                'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
                'environment' => $environment !== '' ? $environment : null,
                'before' => usage_limits_normalize_subscription_row($beforeStatus, $matchedUserId),
                'after' => usage_limits_normalize_subscription_row($afterStatus, $matchedUserId),
            ]),
        ]);
        $historyInserted = true;

        subscription_mgmt_apply_lifecycle_event($pdo, $matchedUserId, $beforeStatus, $afterStatus, $eventType);
    } else {
        $processStatus = 'unmatched_user';
        $errorMessage = 'unmatched user';

        $eventRowId = subscription_mgmt_insert_webhook_event($pdo, [
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type_raw' => $eventTypeRaw,
            'event_type' => $eventType,
            'environment' => $environment !== '' ? $environment : null,
            'app_user_id' => $appUserId !== '' ? $appUserId : null,
            'original_app_user_id' => $originalAppUserId !== '' ? $originalAppUserId : null,
            'aliases_json' => subscription_mgmt_safe_json($aliases),
            'rc_app_user_id' => $rcAppUserId,
            'user_id' => null,
            'is_matched' => 0,
            'is_duplicate' => 0,
            'process_status' => $processStatus,
            'error_message' => $errorMessage,
            'payload_json' => subscription_mgmt_safe_json($payload),
            'headers_json' => subscription_mgmt_safe_json($headers),
            'source_ip' => $sourceIp !== '' ? $sourceIp : null,
            'event_timestamp' => $eventTimestamp,
        ]);
    }

    $pdo->commit();

    api_send_json([
        'success' => true,
        'message' => 'Webhook event işlendi.',
        'data' => [
            'accepted' => true,
            'duplicate' => false,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'process_status' => $processStatus,
            'matched_user' => $matchedUserId,
            'history_inserted' => $historyInserted,
            'event_row_id' => $eventRowId,
        ],
    ], 200);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    usage_limits_log_exception('revenuecat_webhook_processing_failed', $e, [
        'event_id' => $eventId,
        'event_type' => $eventType,
    ]);

    try {
        subscription_mgmt_insert_webhook_event($pdo, [
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type_raw' => $eventTypeRaw,
            'event_type' => $eventType,
            'environment' => $environment !== '' ? $environment : null,
            'app_user_id' => $appUserId !== '' ? $appUserId : null,
            'original_app_user_id' => $originalAppUserId !== '' ? $originalAppUserId : null,
            'aliases_json' => subscription_mgmt_safe_json($aliases),
            'rc_app_user_id' => $rcAppUserId,
            'user_id' => null,
            'is_matched' => 0,
            'is_duplicate' => 0,
            'process_status' => 'failed',
            'error_message' => mb_substr($e->getMessage(), 0, 500),
            'payload_json' => subscription_mgmt_safe_json($payload),
            'headers_json' => subscription_mgmt_safe_json($headers),
            'source_ip' => $sourceIp !== '' ? $sourceIp : null,
            'event_timestamp' => $eventTimestamp,
        ]);
    } catch (Throwable $ignored) {
    }

    api_send_json([
        'success' => false,
        'message' => 'Webhook işlendi ancak iç hata kaydedildi.',
        'data' => [
            'accepted' => false,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'process_status' => 'failed',
        ],
    ], 200);
}

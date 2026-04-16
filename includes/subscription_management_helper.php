<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_lifecycle_helper.php';
require_once __DIR__ . '/../api/v1/usage_limits_helper.php';

function subscription_mgmt_q(string $identifier): string
{
    return '`' . str_replace('`', '', $identifier) . '`';
}

function subscription_mgmt_table_exists(PDO $pdo, string $table): bool
{
    return !empty(get_table_columns($pdo, $table));
}

function subscription_mgmt_pick_column(array $columns, array $candidates, bool $required = false): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    if ($required) {
        throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    }

    return null;
}

function subscription_mgmt_webhook_schema(PDO $pdo): ?array
{
    $table = 'revenuecat_webhook_events';
    $cols = get_table_columns($pdo, $table);
    if (empty($cols)) {
        $table = 'subscription_webhook_events';
        $cols = get_table_columns($pdo, $table);
    }
    if (empty($cols)) {
        return null;
    }

    return [
        'table' => $table,
        'id' => subscription_mgmt_pick_column($cols, ['id'], false),
        'provider' => subscription_mgmt_pick_column($cols, ['provider'], false),
        'event_id' => subscription_mgmt_pick_column($cols, ['event_id'], true),
        'event_type_raw' => subscription_mgmt_pick_column($cols, ['event_type_raw', 'event_type_original'], false),
        'event_type' => subscription_mgmt_pick_column($cols, ['event_type', 'event_type_normalized'], true),
        'environment' => subscription_mgmt_pick_column($cols, ['environment'], false),
        'app_user_id' => subscription_mgmt_pick_column($cols, ['app_user_id'], false),
        'original_app_user_id' => subscription_mgmt_pick_column($cols, ['original_app_user_id'], false),
        'aliases_json' => subscription_mgmt_pick_column($cols, ['aliases_json'], false),
        'rc_app_user_id' => subscription_mgmt_pick_column($cols, ['rc_app_user_id'], false),
        'user_id' => subscription_mgmt_pick_column($cols, ['matched_user_id', 'user_id'], false),
        'is_matched' => subscription_mgmt_pick_column($cols, ['is_matched'], false),
        'is_duplicate' => subscription_mgmt_pick_column($cols, ['is_duplicate'], false),
        'process_status' => subscription_mgmt_pick_column($cols, ['processing_status', 'process_status', 'status'], false),
        'error_message' => subscription_mgmt_pick_column($cols, ['processing_error', 'error_message', 'error'], false),
        'payload_json' => subscription_mgmt_pick_column($cols, ['raw_payload_json', 'payload_json'], false),
        'headers_json' => subscription_mgmt_pick_column($cols, ['headers_json', 'request_headers_json'], false),
        'source_ip' => subscription_mgmt_pick_column($cols, ['source_ip', 'ip_address'], false),
        'event_timestamp' => subscription_mgmt_pick_column($cols, ['event_timestamp', 'event_at'], false),
        'processed_at' => subscription_mgmt_pick_column($cols, ['processed_at'], false),
        'created_at' => subscription_mgmt_pick_column($cols, ['created_at'], false),
        'updated_at' => subscription_mgmt_pick_column($cols, ['updated_at'], false),
    ];
}

function subscription_mgmt_history_schema(PDO $pdo): ?array
{
    $table = 'subscription_event_history';
    $cols = get_table_columns($pdo, $table);
    if (empty($cols)) {
        $table = 'subscription_history';
        $cols = get_table_columns($pdo, $table);
    }
    if (empty($cols)) {
        return null;
    }

    return [
        'table' => $table,
        'id' => subscription_mgmt_pick_column($cols, ['id'], false),
        'user_id' => subscription_mgmt_pick_column($cols, ['user_id'], false),
        'webhook_event_id' => subscription_mgmt_pick_column($cols, ['webhook_event_id'], false),
        'event_id' => subscription_mgmt_pick_column($cols, ['source_event_id', 'event_id'], false),
        'event_type' => subscription_mgmt_pick_column($cols, ['event_type'], false),
        'plan_code' => subscription_mgmt_pick_column($cols, ['plan_code'], false),
        'provider' => subscription_mgmt_pick_column($cols, ['provider'], false),
        'store' => subscription_mgmt_pick_column($cols, ['store'], false),
        'entitlement_id' => subscription_mgmt_pick_column($cols, ['entitlement_id'], false),
        'old_value' => subscription_mgmt_pick_column($cols, ['old_value'], false),
        'new_value' => subscription_mgmt_pick_column($cols, ['new_value'], false),
        'source' => subscription_mgmt_pick_column($cols, ['source'], false),
        'meta_json' => subscription_mgmt_pick_column($cols, ['meta_json'], false),
        'event_title' => subscription_mgmt_pick_column($cols, ['event_title'], false),
        'product_id' => subscription_mgmt_pick_column($cols, ['product_id'], false),
        'rc_app_user_id' => subscription_mgmt_pick_column($cols, ['rc_app_user_id'], false),
        'event_at' => subscription_mgmt_pick_column($cols, ['event_at'], false),
        'created_at' => subscription_mgmt_pick_column($cols, ['created_at'], false),
        'updated_at' => subscription_mgmt_pick_column($cols, ['updated_at'], false),
    ];
}

function subscription_mgmt_headers(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with((string)$key, 'HTTP_')) {
            continue;
        }
        $name = str_replace('_', '-', substr((string)$key, 5));
        $headers[strtolower($name)] = trim((string)$value);
    }
    return $headers;
}

function subscription_mgmt_webhook_secret(): ?string
{
    $candidates = [
        defined('REVENUECAT_WEBHOOK_SECRET') ? (string)REVENUECAT_WEBHOOK_SECRET : '',
        defined('RC_WEBHOOK_SECRET') ? (string)RC_WEBHOOK_SECRET : '',
        defined('RC_WEBHOOK_AUTH_TOKEN') ? (string)RC_WEBHOOK_AUTH_TOKEN : '',
        (string)(getenv('REVENUECAT_WEBHOOK_SECRET') ?: ''),
        (string)(getenv('RC_WEBHOOK_SECRET') ?: ''),
        (string)(getenv('RC_WEBHOOK_AUTH_TOKEN') ?: ''),
    ];

    foreach ($candidates as $candidate) {
        $v = trim($candidate);
        if ($v !== '') {
            return $v;
        }
    }

    return null;
}

function subscription_mgmt_validate_secret(array $headers, ?string $secret): bool
{
    if ($secret === null || trim($secret) === '') {
        return true;
    }

    $auth = trim((string)($headers['authorization'] ?? ''));
    if ($auth !== '') {
        if (stripos($auth, 'Bearer ') === 0) {
            $auth = trim(substr($auth, 7));
        }
        if (hash_equals($secret, $auth)) {
            return true;
        }
    }

    $direct = [
        $headers['x-webhook-secret'] ?? null,
        $headers['x-revenuecat-signature'] ?? null,
        $headers['x-revenuecat-webhook-secret'] ?? null,
    ];

    foreach ($direct as $value) {
        $value = trim((string)$value);
        if ($value !== '' && hash_equals($secret, $value)) {
            return true;
        }
    }

    return false;
}

function subscription_mgmt_normalize_event_type(?string $eventType): string
{
    $normalized = strtoupper(trim((string)$eventType));
    $map = [
        'INITIAL_PURCHASE' => 'INITIAL_PURCHASE',
        'RENEWAL' => 'RENEWAL',
        'CANCELLATION' => 'CANCELLATION',
        'EXPIRATION' => 'EXPIRATION',
        'BILLING_ISSUE' => 'BILLING_ISSUE',
        'PRODUCT_CHANGE' => 'PRODUCT_CHANGE',
        'UNCANCELLATION' => 'UNCANCELLATION',
        'REFUND' => 'REFUND',
        'TRANSFER' => 'TRANSFER',
        'SUBSCRIPTION_PAUSED' => 'SUBSCRIPTION_PAUSED',
        'TEMPORARY_ENTITLEMENT_GRANT' => 'TEMPORARY_ENTITLEMENT_GRANT',
    ];

    return $map[$normalized] ?? ($normalized !== '' ? $normalized : 'UNKNOWN');
}

function subscription_mgmt_event_type_label_map_tr(): array
{
    return [
        'INITIAL_PURCHASE' => 'İlk Satın Alma',
        'RENEWAL' => 'Yenileme',
        'EXPIRATION' => 'Süre Doldu',
        'CANCELLATION' => 'İptal',
        'BILLING_ISSUE' => 'Ödeme Sorunu',
        'REFUND' => 'İade',
        'PRODUCT_CHANGE' => 'Paket Değişikliği',
        'UNCANCELLATION' => 'İptal Geri Alındı',
        'TRANSFER' => 'Transfer',
        'SUBSCRIPTION_PAUSED' => 'Abonelik Duraklatıldı',
        'TEMPORARY_ENTITLEMENT_GRANT' => 'Geçici Erişim',
    ];
}

function subscription_mgmt_status_label_map_tr(): array
{
    return [
        'processed' => 'İşlendi',
        'duplicate' => 'Tekrar',
        'unmatched_user' => 'Eşleşmedi',
        'conflict' => 'Çakışma',
        'failed' => 'Hata',
    ];
}

function subscription_mgmt_event_type_label_tr(?string $eventType): string
{
    $key = subscription_mgmt_normalize_event_type($eventType);
    $map = subscription_mgmt_event_type_label_map_tr();
    return $map[$key] ?? ($key !== '' ? $key : '-');
}

function subscription_mgmt_status_label_tr(?string $status): string
{
    $key = strtolower(trim((string)$status));
    $map = subscription_mgmt_status_label_map_tr();
    return $map[$key] ?? ($key !== '' ? $key : '-');
}

function subscription_mgmt_extract_event_payload(array $payload): array
{
    if (isset($payload['event']) && is_array($payload['event'])) {
        return $payload['event'];
    }
    return $payload;
}

function subscription_mgmt_extract_aliases(array $event): array
{
    $aliases = $event['aliases'] ?? [];
    if (!is_array($aliases)) {
        $aliases = [];
    }
    $clean = [];
    foreach ($aliases as $alias) {
        $value = trim((string)$alias);
        if ($value !== '' && !in_array($value, $clean, true)) {
            $clean[] = $value;
        }
    }
    return $clean;
}

function subscription_mgmt_extract_datetime(array $event): ?string
{
    $candidates = [
        $event['event_timestamp_ms'] ?? null,
        $event['purchased_at_ms'] ?? null,
        $event['expiration_at_ms'] ?? null,
        $event['event_timestamp'] ?? null,
        $event['purchased_at'] ?? null,
        $event['expiration_at'] ?? null,
        $event['expires_at'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $dt = usage_limits_normalize_datetime_to_mysql($candidate);
        if ($dt !== null) {
            return $dt;
        }
    }

    return null;
}

function subscription_mgmt_extract_expiration(array $event): ?string
{
    $candidates = [
        $event['expiration_at_ms'] ?? null,
        $event['expires_at_ms'] ?? null,
        $event['expiration_at'] ?? null,
        $event['expires_at'] ?? null,
        $event['expires_date'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $dt = usage_limits_normalize_datetime_to_mysql($candidate);
        if ($dt !== null) {
            return $dt;
        }
    }
    return null;
}

function subscription_mgmt_collect_rc_candidates(array $event): array
{
    $candidates = [];
    $add = static function (array &$target, $value): void {
        $v = trim((string)$value);
        if ($v !== '' && !in_array($v, $target, true)) {
            $target[] = $v;
        }
    };

    $add($candidates, $event['app_user_id'] ?? null);
    $add($candidates, $event['original_app_user_id'] ?? null);

    foreach (subscription_mgmt_extract_aliases($event) as $alias) {
        $add($candidates, $alias);
    }

    $nonAnon = [];
    $anon = [];
    foreach ($candidates as $candidate) {
        if (usage_limits_is_revenuecat_anonymous_app_user_id($candidate)) {
            if (!in_array($candidate, $anon, true)) {
                $anon[] = $candidate;
            }
            continue;
        }
        if (!in_array($candidate, $nonAnon, true)) {
            $nonAnon[] = $candidate;
        }
    }

    return array_merge($nonAnon, $anon);
}

function subscription_mgmt_find_user_id(PDO $pdo, array $rcCandidates): ?array
{
    if (empty($rcCandidates)) {
        return null;
    }

    $profileCols = get_table_columns($pdo, 'user_profiles');
    if (!empty($profileCols)) {
        $in = implode(', ', array_fill(0, count($rcCandidates), '?'));

        $stmt = $pdo->prepare('SELECT id FROM user_profiles WHERE id IN (' . $in . ') LIMIT 1');
        $stmt->execute($rcCandidates);
        $direct = $stmt->fetchColumn();
        if ($direct) {
            return ['user_id' => (string)$direct, 'matched_via' => 'user_profiles.id'];
        }
    }

    if (subscription_mgmt_table_exists($pdo, 'user_subscription_status')) {
        $in = implode(', ', array_fill(0, count($rcCandidates), '?'));
        $stmt = $pdo->prepare('SELECT user_id FROM user_subscription_status WHERE rc_app_user_id IN (' . $in . ') ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute($rcCandidates);
        $fromStatus = $stmt->fetchColumn();
        if ($fromStatus) {
            return ['user_id' => (string)$fromStatus, 'matched_via' => 'user_subscription_status.rc_app_user_id'];
        }
    }

    $profileRcColumns = array_values(array_intersect($profileCols, ['rc_app_user_id', 'revenuecat_app_user_id', 'app_user_id', 'original_app_user_id']));
    foreach ($profileRcColumns as $col) {
        $in = implode(', ', array_fill(0, count($rcCandidates), '?'));
        $sql = 'SELECT id FROM user_profiles WHERE ' . subscription_mgmt_q($col) . ' IN (' . $in . ') LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($rcCandidates);
        $fromProfile = $stmt->fetchColumn();
        if ($fromProfile) {
            return ['user_id' => (string)$fromProfile, 'matched_via' => 'user_profiles.' . $col];
        }
    }

    return null;
}

function subscription_mgmt_payload_to_array(string $rawBody): ?array
{
    $trimmed = trim($rawBody);
    if ($trimmed === '') {
        return null;
    }

    try {
        $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        return null;
    }
}

function subscription_mgmt_safe_json($value): ?string
{
    if ($value === null) {
        return null;
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? null : $encoded;
}

function subscription_mgmt_derive_event_id(array $event, string $rawBody): string
{
    $idCandidates = [
        $event['id'] ?? null,
        $event['event_id'] ?? null,
        $event['transaction_id'] ?? null,
    ];
    foreach ($idCandidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') {
            return $value;
        }
    }
    return 'hash_' . hash('sha256', $rawBody);
}

function subscription_mgmt_find_existing_event(PDO $pdo, string $eventId): ?array
{
    $schema = subscription_mgmt_webhook_schema($pdo);
    if (!$schema || !$schema['event_id']) {
        return null;
    }

    $select = [
        ($schema['id'] ? subscription_mgmt_q($schema['id']) : 'NULL') . ' AS id',
        subscription_mgmt_q($schema['event_id']) . ' AS event_id',
    ];
    if ($schema['process_status']) {
        $select[] = subscription_mgmt_q($schema['process_status']) . ' AS process_status';
    }
    if ($schema['created_at']) {
        $select[] = subscription_mgmt_q($schema['created_at']) . ' AS created_at';
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . subscription_mgmt_q($schema['table'])
        . ' WHERE ' . subscription_mgmt_q($schema['event_id']) . ' = ?'
        . ' ORDER BY ' . subscription_mgmt_q($schema['created_at'] ?: ($schema['id'] ?: $schema['event_id'])) . ' DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function subscription_mgmt_insert_webhook_event(PDO $pdo, array $payload): ?string
{
    $schema = subscription_mgmt_webhook_schema($pdo);
    if (!$schema) {
        return null;
    }

    $cols = [];
    $holders = [];
    $values = [];

    $append = static function (?string $col, $value) use (&$cols, &$holders, &$values): void {
        if (!$col) {
            return;
        }
        $cols[] = subscription_mgmt_q($col);
        $holders[] = '?';
        $values[] = $value;
    };

    $eventRowId = null;
    if ($schema['id']) {
        $eventRowId = generate_uuid();
        $append($schema['id'], $eventRowId);
    }

    $append($schema['provider'], $payload['provider'] ?? 'revenuecat');
    $append($schema['event_id'], $payload['event_id'] ?? null);
    $append($schema['event_type_raw'], $payload['event_type_raw'] ?? null);
    $append($schema['event_type'], $payload['event_type'] ?? null);
    $append($schema['environment'], $payload['environment'] ?? null);
    $append($schema['app_user_id'], $payload['app_user_id'] ?? null);
    $append($schema['original_app_user_id'], $payload['original_app_user_id'] ?? null);
    $append($schema['aliases_json'], $payload['aliases_json'] ?? null);
    $append($schema['rc_app_user_id'], $payload['rc_app_user_id'] ?? null);
    $append($schema['user_id'], $payload['user_id'] ?? null);
    $append($schema['is_matched'], $payload['is_matched'] ?? 0);
    $append($schema['is_duplicate'], $payload['is_duplicate'] ?? 0);
    $append($schema['process_status'], $payload['processing_status'] ?? ($payload['process_status'] ?? 'received'));
    $append($schema['error_message'], $payload['processing_error'] ?? ($payload['error_message'] ?? null));
    $append($schema['payload_json'], $payload['raw_payload_json'] ?? ($payload['payload_json'] ?? null));
    $append($schema['headers_json'], $payload['headers_json'] ?? null);
    $append($schema['source_ip'], $payload['source_ip'] ?? null);
    $append($schema['event_timestamp'], $payload['event_timestamp'] ?? null);

    if ($schema['processed_at']) {
        $cols[] = subscription_mgmt_q($schema['processed_at']);
        $holders[] = 'NOW()';
    }
    if ($schema['created_at']) {
        $cols[] = subscription_mgmt_q($schema['created_at']);
        $holders[] = 'NOW()';
    }
    if ($schema['updated_at']) {
        $cols[] = subscription_mgmt_q($schema['updated_at']);
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . subscription_mgmt_q($schema['table'])
        . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    if ($eventRowId !== null) {
        return $eventRowId;
    }

    return null;
}

function subscription_mgmt_insert_history(PDO $pdo, array $historyPayload): void
{
    $schema = subscription_mgmt_history_schema($pdo);
    if (!$schema) {
        return;
    }

    $cols = [];
    $holders = [];
    $values = [];

    $append = static function (?string $col, $value) use (&$cols, &$holders, &$values): void {
        if (!$col) {
            return;
        }
        $cols[] = subscription_mgmt_q($col);
        $holders[] = '?';
        $values[] = $value;
    };

    if ($schema['id']) {
        $append($schema['id'], generate_uuid());
    }

    $sourceEventId = $historyPayload['source_event_id'] ?? ($historyPayload['event_id'] ?? null);
    $eventAt = usage_limits_normalize_datetime_to_mysql(
        $historyPayload['event_at']
        ?? $historyPayload['event_timestamp']
        ?? $historyPayload['created_at']
        ?? null
    );

    $append($schema['user_id'], $historyPayload['user_id'] ?? null);
    $append($schema['webhook_event_id'], $historyPayload['webhook_event_id'] ?? null);
    $append($schema['event_id'], $sourceEventId);
    $append($schema['event_type'], $historyPayload['event_type'] ?? null);
    $append($schema['plan_code'], $historyPayload['plan_code'] ?? null);
    $append($schema['provider'], $historyPayload['provider'] ?? 'revenuecat');
    $append($schema['store'], $historyPayload['store'] ?? null);
    $append($schema['entitlement_id'], $historyPayload['entitlement_id'] ?? null);
    $append($schema['old_value'], $historyPayload['old_value'] ?? null);
    $append($schema['new_value'], $historyPayload['new_value'] ?? null);
    $append($schema['source'], $historyPayload['source'] ?? 'revenuecat.webhook');
    $append($schema['meta_json'], $historyPayload['meta_json'] ?? null);
    $append($schema['event_title'], $historyPayload['event_title'] ?? null);
    $append($schema['product_id'], $historyPayload['product_id'] ?? null);
    $append($schema['rc_app_user_id'], $historyPayload['rc_app_user_id'] ?? null);
    $append($schema['event_at'], $eventAt);

    if ($schema['created_at']) {
        $cols[] = subscription_mgmt_q($schema['created_at']);
        $holders[] = 'NOW()';
    }
    if ($schema['updated_at']) {
        $cols[] = subscription_mgmt_q($schema['updated_at']);
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . subscription_mgmt_q($schema['table'])
        . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function subscription_mgmt_apply_lifecycle_event(PDO $pdo, string $userId, array $beforeStatus, array $afterStatus, string $normalizedType): void
{
    $beforeActive = usage_limits_is_subscription_active($beforeStatus);
    $afterActive = usage_limits_is_subscription_active($afterStatus);

    if (!$beforeActive && $afterActive) {
        user_lifecycle_log_event($pdo, $userId, 'premium_started', 'Premium başlatıldı', 'revenuecat.webhook', null, $afterStatus['expires_at'] ?? 'active', ['event_type' => $normalizedType]);
        return;
    }

    if ($beforeActive && $afterActive && in_array($normalizedType, ['RENEWAL', 'PRODUCT_CHANGE', 'UNCANCELLATION'], true)) {
        user_lifecycle_log_event($pdo, $userId, 'premium_renewed', 'Premium yenilendi', 'revenuecat.webhook', $beforeStatus['expires_at'] ?? null, $afterStatus['expires_at'] ?? null, ['event_type' => $normalizedType]);
        return;
    }

    if ($beforeActive && !$afterActive) {
        $eventType = $normalizedType === 'EXPIRATION' ? 'premium_expired' : 'premium_cancelled';
        $title = $eventType === 'premium_expired' ? 'Premium süresi doldu' : 'Premium iptal edildi';
        user_lifecycle_log_event($pdo, $userId, $eventType, $title, 'revenuecat.webhook', $beforeStatus['expires_at'] ?? null, 'free', ['event_type' => $normalizedType]);
    }
}

function subscription_mgmt_should_be_active_by_expiry(?string $expiresAt): bool
{
    $expiresAt = usage_limits_normalize_datetime_to_mysql($expiresAt);
    if ($expiresAt === null || $expiresAt === '') {
        return true;
    }

    $ts = strtotime($expiresAt);
    return $ts !== false && $ts > time();
}

function subscription_mgmt_compute_next_state(array $beforeStatus, array $event, string $normalizedType): array
{
    $before = usage_limits_normalize_subscription_row($beforeStatus, (string)($beforeStatus['user_id'] ?? ''));
    $eventExpiry = subscription_mgmt_extract_expiration($event);
    $planCode = trim((string)($event['product_id'] ?? $event['product_identifier'] ?? $before['plan_code'] ?? ''));
    $entitlementId = trim((string)($event['entitlement_id'] ?? $before['entitlement_id'] ?? ''));

    $next = [
        'is_pro' => !empty($before['is_pro']),
        'plan_code' => $planCode !== '' ? $planCode : null,
        'entitlement_id' => $entitlementId !== '' ? $entitlementId : null,
        'rc_app_user_id' => trim((string)($event['app_user_id'] ?? $event['original_app_user_id'] ?? $before['rc_app_user_id'] ?? '')) ?: null,
        'expires_at' => $eventExpiry ?? ($before['expires_at'] ?? null),
        'last_synced_at' => gmdate('Y-m-d H:i:s'),
    ];

    switch ($normalizedType) {
        case 'INITIAL_PURCHASE':
        case 'RENEWAL':
        case 'PRODUCT_CHANGE':
        case 'UNCANCELLATION':
        case 'TEMPORARY_ENTITLEMENT_GRANT':
            $next['is_pro'] = true;
            if ($eventExpiry !== null) {
                $next['expires_at'] = $eventExpiry;
            }
            break;

        case 'EXPIRATION':
        case 'REFUND':
            $next['is_pro'] = false;
            if ($eventExpiry !== null) {
                $next['expires_at'] = $eventExpiry;
            }
            break;

        case 'CANCELLATION':
            if ($eventExpiry !== null) {
                $next['expires_at'] = $eventExpiry;
            }
            $next['is_pro'] = subscription_mgmt_should_be_active_by_expiry($next['expires_at']);
            break;

        case 'BILLING_ISSUE':
        case 'SUBSCRIPTION_PAUSED':
            if ($eventExpiry !== null) {
                $next['expires_at'] = $eventExpiry;
            }
            $next['is_pro'] = subscription_mgmt_should_be_active_by_expiry($next['expires_at']) && !empty($before['is_pro']);
            break;

        case 'TRANSFER':
            if ($eventExpiry !== null) {
                $next['expires_at'] = $eventExpiry;
                $next['is_pro'] = subscription_mgmt_should_be_active_by_expiry($eventExpiry);
            }
            break;

        default:
            if ($eventExpiry !== null) {
                $next['expires_at'] = $eventExpiry;
            }
            break;
    }

    if (!empty($next['is_pro']) && !subscription_mgmt_should_be_active_by_expiry($next['expires_at'])) {
        $next['is_pro'] = false;
    }

    return $next;
}

function subscription_mgmt_process_status_from_result(array $beforeStatus, array $afterStatus): string
{
    $before = usage_limits_normalize_subscription_row($beforeStatus, (string)($beforeStatus['user_id'] ?? ''));
    $after = usage_limits_normalize_subscription_row($afterStatus, (string)($afterStatus['user_id'] ?? ''));
    $changes = usage_limits_get_subscription_state_changes($before, $after);
    if (empty($changes)) {
        return 'processed';
    }

    $afterStale = !empty($after['is_pro']) && !subscription_mgmt_should_be_active_by_expiry($after['expires_at'] ?? null);
    if ($afterStale) {
        return 'conflict';
    }

    return 'processed';
}

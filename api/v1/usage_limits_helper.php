<?php

require_once __DIR__ . '/auth_helper.php';

if (!defined('USAGE_LIMITS_SUBSCRIPTION_DEBUG_PARAM')) {
    define('USAGE_LIMITS_SUBSCRIPTION_DEBUG_PARAM', 'debug_subscription_sync');
}

if (!defined('USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN')) {
    define('USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN', 'study_question_open');
}
if (!defined('USAGE_LIMIT_FEATURE_MOCK_EXAM_START')) {
    define('USAGE_LIMIT_FEATURE_MOCK_EXAM_START', 'mock_exam_start');
}

if (!defined('USAGE_LIMIT_DAILY_STUDY_QUESTION_OPEN')) {
    define('USAGE_LIMIT_DAILY_STUDY_QUESTION_OPEN', 60);
}
if (!defined('USAGE_LIMIT_DAILY_MOCK_EXAM_START')) {
    define('USAGE_LIMIT_DAILY_MOCK_EXAM_START', 3);
}

function usage_limits_tr_date(): string
{
    return gmdate('Y-m-d', time() + (3 * 3600));
}

function usage_limits_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function usage_limits_pick_column(array $columns, array $candidates, bool $required = false): ?string
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

function usage_limits_get_subscription_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'user_subscription_status');
    if (!$cols) {
        throw new RuntimeException('user_subscription_status tablosu okunamadı.');
    }

    return [
        'table' => 'user_subscription_status',
        'id' => usage_limits_pick_column($cols, ['id'], false),
        'user_id' => usage_limits_pick_column($cols, ['user_id'], true),
        'is_pro' => usage_limits_pick_column($cols, ['is_pro'], true),
        'plan_code' => usage_limits_pick_column($cols, ['plan_code'], false),
        'entitlement_id' => usage_limits_pick_column($cols, ['entitlement_id'], false),
        'rc_app_user_id' => usage_limits_pick_column($cols, ['rc_app_user_id'], false),
        'expires_at' => usage_limits_pick_column($cols, ['expires_at'], false),
        'created_at' => usage_limits_pick_column($cols, ['created_at'], false),
        'updated_at' => usage_limits_pick_column($cols, ['updated_at'], false),
    ];
}

function usage_limits_get_daily_counter_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'user_daily_usage_counters');
    if (!$cols) {
        throw new RuntimeException('user_daily_usage_counters tablosu okunamadı.');
    }

    return [
        'table' => 'user_daily_usage_counters',
        'id' => usage_limits_pick_column($cols, ['id'], false),
        'user_id' => usage_limits_pick_column($cols, ['user_id'], true),
        'qualification_id' => usage_limits_pick_column($cols, ['qualification_id'], true),
        'usage_date_tr' => usage_limits_pick_column($cols, ['usage_date_tr'], true),
        'feature_key' => usage_limits_pick_column($cols, ['feature_key'], true),
        'used_count' => usage_limits_pick_column($cols, ['used_count'], true),
        'created_at' => usage_limits_pick_column($cols, ['created_at'], false),
        'updated_at' => usage_limits_pick_column($cols, ['updated_at'], false),
    ];
}

function usage_limits_get_daily_limit(string $featureKey): int
{
    if ($featureKey === USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN) {
        return USAGE_LIMIT_DAILY_STUDY_QUESTION_OPEN;
    }
    if ($featureKey === USAGE_LIMIT_FEATURE_MOCK_EXAM_START) {
        return USAGE_LIMIT_DAILY_MOCK_EXAM_START;
    }

    throw new RuntimeException('Geçersiz feature_key: ' . $featureKey);
}

function usage_limits_subscription_debug_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $flag = $_GET[USAGE_LIMITS_SUBSCRIPTION_DEBUG_PARAM]
        ?? $_POST[USAGE_LIMITS_SUBSCRIPTION_DEBUG_PARAM]
        ?? $_SERVER['HTTP_X_DEBUG_SUBSCRIPTION_SYNC']
        ?? null;

    $enabled = in_array(strtolower(trim((string)$flag)), ['1', 'true', 'on', 'yes'], true);
    return $enabled;
}

function usage_limits_subscription_debug_log(string $message, array $context = []): void
{
    if (!usage_limits_subscription_debug_enabled()) {
        return;
    }

    $line = '[subscription_sync] ' . $message;
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line .= ' | ' . ($json !== false ? $json : '{}');
    }

    error_log($line);
}

function usage_limits_log_exception(string $message, Throwable $e, array $context = []): void
{
    $payload = array_merge($context, [
        'exception_message' => $e->getMessage(),
        'exception_file' => $e->getFile(),
        'exception_line' => $e->getLine(),
        'exception_trace' => $e->getTraceAsString(),
    ]);

    error_log('[usage_limits] ' . $message . ' | ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    try {
        usage_limits_subscription_debug_log($message, $payload);
    } catch (Throwable $ignored) {
        // debug logger kendisi başarısız olursa ana akışı bozma
    }
}

function usage_limits_revenuecat_verification_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $apiKey = usage_limits_get_revenuecat_api_key();
    $enabled = is_string($apiKey) && trim($apiKey) !== '';
    return $enabled;
}

function usage_limits_get_revenuecat_api_key(): ?string
{
    $constantCandidates = ['REVENUECAT_SECRET_API_KEY', 'RC_SECRET_API_KEY', 'REVENUECAT_API_KEY'];
    foreach ($constantCandidates as $const) {
        if (defined($const)) {
            $value = trim((string)constant($const));
            if ($value !== '') {
                return $value;
            }
        }
    }

    $envCandidates = ['REVENUECAT_SECRET_API_KEY', 'RC_SECRET_API_KEY', 'REVENUECAT_API_KEY'];
    foreach ($envCandidates as $envKey) {
        $value = trim((string)(getenv($envKey) ?: ''));
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function usage_limits_http_get_json(string $url, array $headers = [], int $timeoutSeconds = 15): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('HTTP istemcisi başlatılamadı.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP isteği başarısız: ' . $err);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('HTTP isteği başarısız (file_get_contents).');
        }

        $statusCode = 0;
        if (!empty($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d{3})/i', (string)$headerLine, $m)) {
                    $statusCode = (int)$m[1];
                    break;
                }
            }
        }
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'status_code' => $statusCode,
        'body' => (string)$body,
        'json' => $decoded,
    ];
}

function usage_limits_extract_revenuecat_truth(array $responseJson, string $rcAppUserId, ?string $preferredEntitlementId = null): array
{
    $subscriber = $responseJson['subscriber'] ?? null;
    if (!is_array($subscriber)) {
        throw new RuntimeException('RevenueCat yanıtı geçersiz: subscriber alanı yok.');
    }

    $entitlements = $subscriber['entitlements'] ?? [];
    if (!is_array($entitlements)) {
        $entitlements = [];
    }

    $nowTs = time();
    $activeEntitlements = [];
    foreach ($entitlements as $entitlementKey => $entitlementData) {
        if (!is_array($entitlementData)) {
            continue;
        }

        $expiresAt = usage_limits_normalize_datetime_to_mysql($entitlementData['expires_date'] ?? null);
        $isActive = false;
        if ($expiresAt === null) {
            $isActive = true;
            $expiresAt = '9999-12-31 23:59:59';
        } else {
            $expTs = strtotime($expiresAt);
            $isActive = ($expTs !== false && $expTs > $nowTs);
        }

        if ($isActive) {
            $activeEntitlements[] = [
                'entitlement_id' => (string)$entitlementKey,
                'expires_at' => $expiresAt,
                'plan_code' => (($v = trim((string)($entitlementData['product_identifier'] ?? ''))) !== '' ? $v : null),
            ];
        }
    }

    $selected = null;
    if ($preferredEntitlementId !== null && $preferredEntitlementId !== '') {
        foreach ($activeEntitlements as $item) {
            if (($item['entitlement_id'] ?? '') === $preferredEntitlementId) {
                $selected = $item;
                break;
            }
        }
    }

    if ($selected === null && !empty($activeEntitlements)) {
        usort($activeEntitlements, static function (array $a, array $b): int {
            return strcmp((string)($b['expires_at'] ?? ''), (string)($a['expires_at'] ?? ''));
        });
        $selected = $activeEntitlements[0];
    }

    if ($selected !== null) {
        return [
            'source' => 'revenuecat_server',
            'user_id' => null,
            'rc_app_user_id' => $rcAppUserId,
            'is_pro' => true,
            'is_active' => true,
            'expires_at' => $selected['expires_at'] ?? null,
            'plan_code' => $selected['plan_code'] ?? null,
            'entitlement_id' => $selected['entitlement_id'] ?? $preferredEntitlementId,
        ];
    }

    $latestExpiration = usage_limits_normalize_datetime_to_mysql($subscriber['latest_expiration_date'] ?? null);

    return [
        'source' => 'revenuecat_server',
        'user_id' => null,
        'rc_app_user_id' => $rcAppUserId,
        'is_pro' => false,
        'is_active' => false,
        'expires_at' => $latestExpiration,
        'plan_code' => null,
        'entitlement_id' => $preferredEntitlementId,
    ];
}

function usage_limits_fetch_revenuecat_subscription_truth(string $rcAppUserId, ?string $preferredEntitlementId = null): array
{
    $apiKey = usage_limits_get_revenuecat_api_key();
    if ($apiKey === null || trim($apiKey) === '') {
        throw new RuntimeException('RevenueCat server verification aktif değil: API key bulunamadı.');
    }

    $url = 'https://api.revenuecat.com/v1/subscribers/' . rawurlencode($rcAppUserId);
    usage_limits_subscription_debug_log('revenuecat_verification_request', [
        'request_app_user_id' => $rcAppUserId,
        'rc_app_user_id' => $rcAppUserId,
        'preferred_entitlement_id' => $preferredEntitlementId,
        'url' => $url,
    ]);

    $http = usage_limits_http_get_json($url, [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
    ], 15);

    $statusCode = (int)($http['status_code'] ?? 0);
    $json = is_array($http['json'] ?? null) ? $http['json'] : [];
    $subscriber = $json['subscriber'] ?? null;
    $subscriber = is_array($subscriber) ? $subscriber : [];
    $entitlements = $subscriber['entitlements'] ?? [];
    $entitlements = is_array($entitlements) ? $entitlements : [];

    $responseOriginalAppUserId = trim((string)($subscriber['original_app_user_id'] ?? ''));
    $responseAppUserId = trim((string)($subscriber['app_user_id'] ?? ''));
    $wrongSubscriberIdSuspected = false;
    if ($responseOriginalAppUserId !== '' && $responseOriginalAppUserId !== $rcAppUserId) {
        $wrongSubscriberIdSuspected = true;
    }
    if ($responseAppUserId !== '' && $responseAppUserId !== $rcAppUserId) {
        $wrongSubscriberIdSuspected = true;
    }

    $fetchedEntitlements = [];
    foreach ($entitlements as $entitlementId => $entitlementRow) {
        if (!is_array($entitlementRow)) {
            continue;
        }

        $fetchedEntitlements[] = [
            'entitlement_id' => (string)$entitlementId,
            'product_identifier' => (($v = trim((string)($entitlementRow['product_identifier'] ?? ''))) !== '' ? $v : null),
            'expires_date_raw' => $entitlementRow['expires_date'] ?? null,
            'expires_date' => usage_limits_normalize_datetime_to_mysql($entitlementRow['expires_date'] ?? null),
        ];
    }

    $activeEntitlementExists = false;
    $nowTs = time();
    foreach ($fetchedEntitlements as $entitlementRow) {
        $exp = $entitlementRow['expires_date'] ?? null;
        if ($exp === null || $exp === '') {
            $activeEntitlementExists = true;
            break;
        }

        $expTs = strtotime((string)$exp);
        if ($expTs !== false && $expTs > $nowTs) {
            $activeEntitlementExists = true;
            break;
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        usage_limits_subscription_debug_log('revenuecat_verification_failed', [
            'request_app_user_id' => $rcAppUserId,
            'rc_app_user_id' => $rcAppUserId,
            'preferred_entitlement_id' => $preferredEntitlementId,
            'response_status' => $statusCode,
            'wrong_subscriber_id_suspected' => $wrongSubscriberIdSuspected || ($statusCode === 404),
            'response_original_app_user_id' => ($responseOriginalAppUserId !== '' ? $responseOriginalAppUserId : null),
            'response_subscriber_app_user_id' => ($responseAppUserId !== '' ? $responseAppUserId : null),
            'response_app_user_id' => ($responseAppUserId !== '' ? $responseAppUserId : null),
            'empty_entitlement' => empty($fetchedEntitlements),
            'entitlements' => $fetchedEntitlements,
            'expires_dates' => array_values(array_map(static function (array $row) {
                return $row['expires_date'] ?? null;
            }, $fetchedEntitlements)),
            'active_entitlement_exists' => $activeEntitlementExists,
            'fetched_entitlements' => $fetchedEntitlements,
            'response_body_preview' => mb_substr((string)($http['body'] ?? ''), 0, 2000),
        ]);

        throw new RuntimeException('RevenueCat doğrulama isteği başarısız. HTTP=' . $statusCode);
    }

    $truth = usage_limits_extract_revenuecat_truth($json, $rcAppUserId, $preferredEntitlementId);

    if (empty($truth['is_pro'])) {
        usage_limits_subscription_debug_log('revenuecat_verification_no_active_entitlement', [
            'request_app_user_id' => $rcAppUserId,
            'rc_app_user_id' => $rcAppUserId,
            'preferred_entitlement_id' => $preferredEntitlementId,
            'response_status' => $statusCode,
            'wrong_subscriber_id_suspected' => $wrongSubscriberIdSuspected,
            'response_original_app_user_id' => ($responseOriginalAppUserId !== '' ? $responseOriginalAppUserId : null),
            'response_subscriber_app_user_id' => ($responseAppUserId !== '' ? $responseAppUserId : null),
            'response_app_user_id' => ($responseAppUserId !== '' ? $responseAppUserId : null),
            'empty_entitlement' => empty($fetchedEntitlements),
            'entitlements' => $fetchedEntitlements,
            'expires_dates' => array_values(array_map(static function (array $row) {
                return $row['expires_date'] ?? null;
            }, $fetchedEntitlements)),
            'active_entitlement_exists' => $activeEntitlementExists,
            'fetched_entitlements' => $fetchedEntitlements,
            'truth' => $truth,
        ]);
    }

    usage_limits_subscription_debug_log('revenuecat_verified', [
        'request_app_user_id' => $rcAppUserId,
        'rc_app_user_id' => $rcAppUserId,
        'preferred_entitlement_id' => $preferredEntitlementId,
        'response_status' => $statusCode,
        'response_original_app_user_id' => ($responseOriginalAppUserId !== '' ? $responseOriginalAppUserId : null),
        'response_subscriber_app_user_id' => ($responseAppUserId !== '' ? $responseAppUserId : null),
        'response_app_user_id' => ($responseAppUserId !== '' ? $responseAppUserId : null),
        'entitlements' => $fetchedEntitlements,
        'expires_dates' => array_values(array_map(static function (array $row) {
            return $row['expires_date'] ?? null;
        }, $fetchedEntitlements)),
        'active_entitlement_exists' => $activeEntitlementExists,
        'fetched_entitlements' => $fetchedEntitlements,
        'empty_entitlement' => empty($fetchedEntitlements),
        'wrong_subscriber_id_suspected' => $wrongSubscriberIdSuspected,
        'truth' => $truth,
    ]);

    return $truth;
}

function usage_limits_normalize_datetime_to_mysql($value, bool $allowNull = true): ?string
{
    if ($value === null) {
        return $allowNull ? null : '';
    }

    if ($value instanceof DateTimeInterface) {
        $dt = DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    }

    if (is_int($value) || (is_string($value) && preg_match('/^\d{10}(?:\d{3})?$/', trim($value)))) {
        $raw = (string)$value;
        $seconds = strlen($raw) > 10 ? ((int)$raw / 1000) : (int)$raw;
        try {
            $dt = (new DateTimeImmutable('@' . (string)$seconds))->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    $text = trim((string)$value);
    if ($text === '' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
        return $allowNull ? null : '';
    }

    try {
        $dt = new DateTimeImmutable($text);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $ts = strtotime($text);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }
}

function usage_limits_normalize_subscription_row(array $row, ?string $fallbackUserId = null): array
{
    return [
        'exists' => !empty($row['exists']),
        'id' => $row['id'] ?? null,
        'user_id' => (string)($row['user_id'] ?? $fallbackUserId ?? ''),
        'is_pro' => ((int)($row['is_pro'] ?? 0) === 1),
        'plan_code' => (($v = trim((string)($row['plan_code'] ?? ''))) !== '' ? $v : null),
        'entitlement_id' => (($v = trim((string)($row['entitlement_id'] ?? ''))) !== '' ? $v : null),
        'rc_app_user_id' => (($v = trim((string)($row['rc_app_user_id'] ?? ''))) !== '' ? $v : null),
        'expires_at' => usage_limits_normalize_datetime_to_mysql($row['expires_at'] ?? null),
    ];
}

function usage_limits_is_subscription_active(array $status, ?int $nowTs = null): bool
{
    $normalized = usage_limits_normalize_subscription_row($status, (string)($status['user_id'] ?? ''));
    $nowTs = $nowTs ?? time();
    $expiresAt = $normalized['expires_at'] ?? null;
    $expiresAtText = trim((string)$expiresAt);

    if (empty($normalized['is_pro'])) {
        usage_limits_subscription_debug_log('is_subscription_active_false', [
            'user_id' => (string)($normalized['user_id'] ?? ''),
            'is_pro_field' => false,
            'expires_at_field' => ($expiresAtText !== '' ? $expiresAtText : null),
            'expires_at_parse_result' => 'skipped_is_pro_false',
            'current_utc_time' => gmdate('Y-m-d H:i:s', $nowTs),
            'final_active_calculation' => false,
            'reason' => 'is_pro_false',
        ]);
        return false;
    }

    if ($expiresAt === null || $expiresAt === '') {
        return true;
    }

    $ts = strtotime($expiresAt);
    if ($ts === false) {
        usage_limits_subscription_debug_log('is_subscription_active_false', [
            'user_id' => (string)($normalized['user_id'] ?? ''),
            'is_pro_field' => true,
            'expires_at_field' => ($expiresAtText !== '' ? $expiresAtText : null),
            'expires_at_parse_result' => 'invalid_datetime',
            'current_utc_time' => gmdate('Y-m-d H:i:s', $nowTs),
            'final_active_calculation' => false,
            'reason' => 'expires_at_parse_failed',
        ]);
        return false;
    }

    $isActive = $ts > $nowTs;
    if (!$isActive) {
        usage_limits_subscription_debug_log('is_subscription_active_false', [
            'user_id' => (string)($normalized['user_id'] ?? ''),
            'is_pro_field' => true,
            'expires_at_field' => ($expiresAtText !== '' ? $expiresAtText : null),
            'expires_at_parse_result' => gmdate('Y-m-d H:i:s', $ts),
            'current_utc_time' => gmdate('Y-m-d H:i:s', $nowTs),
            'final_active_calculation' => false,
            'reason' => 'expired_or_now',
        ]);
    }

    return $isActive;
}

function usage_limits_get_subscription_state_changes(array $before, array $after): array
{
    $normalizedBefore = usage_limits_normalize_subscription_row($before, (string)($before['user_id'] ?? ''));
    $normalizedAfter = usage_limits_normalize_subscription_row($after, (string)($after['user_id'] ?? ''));
    $fields = ['is_pro', 'plan_code', 'entitlement_id', 'rc_app_user_id', 'expires_at'];
    $changes = [];

    foreach ($fields as $field) {
        if (($normalizedBefore[$field] ?? null) !== ($normalizedAfter[$field] ?? null)) {
            $changes[$field] = [
                'before' => $normalizedBefore[$field] ?? null,
                'after' => $normalizedAfter[$field] ?? null,
            ];
        }
    }

    $beforeActive = usage_limits_is_subscription_active($normalizedBefore);
    $afterActive = usage_limits_is_subscription_active($normalizedAfter);
    if ($beforeActive !== $afterActive) {
        $changes['is_active'] = [
            'before' => $beforeActive,
            'after' => $afterActive,
        ];
    }

    return $changes;
}

function usage_limits_get_user_subscription_status(PDO $pdo, string $userId): array
{
    $s = usage_limits_get_subscription_schema($pdo);
    $orderCol = $s['updated_at'] ?: ($s['created_at'] ?: ($s['id'] ?: $s['user_id']));

    $sql = 'SELECT '
        . ($s['id'] ? usage_limits_q($s['id']) : 'NULL') . ' AS id, '
        . usage_limits_q($s['user_id']) . ' AS user_id, '
        . usage_limits_q($s['is_pro']) . ' AS is_pro, '
        . ($s['plan_code'] ? usage_limits_q($s['plan_code']) : 'NULL') . ' AS plan_code, '
        . ($s['entitlement_id'] ? usage_limits_q($s['entitlement_id']) : 'NULL') . ' AS entitlement_id, '
        . ($s['rc_app_user_id'] ? usage_limits_q($s['rc_app_user_id']) : 'NULL') . ' AS rc_app_user_id, '
        . ($s['expires_at'] ? usage_limits_q($s['expires_at']) : 'NULL') . ' AS expires_at '
        . 'FROM ' . usage_limits_q($s['table'])
        . ' WHERE ' . usage_limits_q($s['user_id']) . ' = ?'
        . ' ORDER BY ' . usage_limits_q($orderCol) . ' DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $exists = is_array($row) && !empty($row);
    $row = $exists ? $row : [];

    $normalized = usage_limits_normalize_subscription_row($row, $userId);
    $isActive = usage_limits_is_subscription_active($normalized);

    return [
        'exists' => $exists,
        'id' => $row['id'] ?? null,
        'user_id' => $normalized['user_id'],
        'is_pro' => $normalized['is_pro'],
        'plan_code' => $normalized['plan_code'],
        'entitlement_id' => $normalized['entitlement_id'],
        'rc_app_user_id' => $normalized['rc_app_user_id'],
        'expires_at' => $normalized['expires_at'],
        'is_active' => $isActive,
    ];
}

function usage_limits_collect_revenuecat_app_user_id_candidates(PDO $pdo, string $userId, array $beforeRow = []): array
{
    $candidates = [];

    $beforeNormalized = usage_limits_normalize_subscription_row($beforeRow, $userId);
    if (!empty($beforeNormalized['rc_app_user_id'])) {
        $candidates[] = (string)$beforeNormalized['rc_app_user_id'];
    }

    try {
        $s = usage_limits_get_subscription_schema($pdo);
        if (!empty($s['rc_app_user_id'])) {
            $orderCol = $s['updated_at'] ?: ($s['created_at'] ?: ($s['id'] ?: $s['user_id']));
            $sql = 'SELECT ' . usage_limits_q($s['rc_app_user_id']) . ' AS rc_app_user_id '
                . 'FROM ' . usage_limits_q($s['table'])
                . ' WHERE ' . usage_limits_q($s['user_id']) . ' = ?'
                . ' AND ' . usage_limits_q($s['rc_app_user_id']) . ' IS NOT NULL'
                . " AND TRIM(" . usage_limits_q($s['rc_app_user_id']) . ") <> ''"
                . ' ORDER BY ' . usage_limits_q($orderCol) . ' DESC LIMIT 5';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $v = trim((string)($row['rc_app_user_id'] ?? ''));
                if ($v !== '') {
                    $candidates[] = $v;
                }
            }
        }
    } catch (Throwable $e) {
        usage_limits_log_exception('resolve_rc_app_user_id_from_subscription_rows_failed', $e, [
            'user_id' => $userId,
        ]);
    }

    try {
        $profileCols = get_table_columns($pdo, 'user_profiles');
        if (!empty($profileCols)) {
            $possibleRcCols = [
                'rc_app_user_id',
                'revenuecat_app_user_id',
                'app_user_id',
                'original_app_user_id',
            ];

            $presentCols = [];
            foreach ($possibleRcCols as $col) {
                if (in_array($col, $profileCols, true)) {
                    $presentCols[] = $col;
                }
            }

            if (!empty($presentCols)) {
                $select = [];
                foreach ($presentCols as $col) {
                    $select[] = usage_limits_q($col) . ' AS ' . usage_limits_q($col);
                }

                $sql = 'SELECT ' . implode(', ', $select)
                    . ' FROM `user_profiles` WHERE `id` = ? LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId]);
                $profileRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                foreach ($presentCols as $col) {
                    $v = trim((string)($profileRow[$col] ?? ''));
                    if ($v !== '') {
                        $candidates[] = $v;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        usage_limits_log_exception('resolve_rc_app_user_id_from_user_profiles_failed', $e, [
            'user_id' => $userId,
        ]);
    }

    $metaTables = ['user_metadata', 'user_meta'];
    foreach ($metaTables as $metaTable) {
        try {
            $cols = get_table_columns($pdo, $metaTable);
            if (empty($cols)) {
                continue;
            }

            $colUserId = usage_limits_pick_column($cols, ['user_id'], false);
            $colMetaKey = usage_limits_pick_column($cols, ['meta_key', 'key', 'name'], false);
            $colMetaValue = usage_limits_pick_column($cols, ['meta_value', 'value'], false);
            if (!$colUserId || !$colMetaKey || !$colMetaValue) {
                continue;
            }

            $metaKeys = ['rc_app_user_id', 'revenuecat_app_user_id', 'app_user_id', 'original_app_user_id'];
            $in = implode(', ', array_fill(0, count($metaKeys), '?'));
            $sql = 'SELECT ' . usage_limits_q($colMetaValue) . ' AS rc_app_user_id '
                . 'FROM ' . usage_limits_q($metaTable)
                . ' WHERE ' . usage_limits_q($colUserId) . ' = ?'
                . ' AND ' . usage_limits_q($colMetaKey) . ' IN (' . $in . ')'
                . ' LIMIT 10';
            $params = array_merge([$userId], $metaKeys);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $v = trim((string)($row['rc_app_user_id'] ?? ''));
                if ($v !== '') {
                    $candidates[] = $v;
                }
            }
        } catch (Throwable $e) {
            usage_limits_log_exception('resolve_rc_app_user_id_from_meta_table_failed', $e, [
                'user_id' => $userId,
                'meta_table' => $metaTable,
            ]);
        }
    }

    $deduped = [];
    foreach ($candidates as $candidate) {
        $v = trim((string)$candidate);
        if ($v === '') {
            continue;
        }
        if (!in_array($v, $deduped, true)) {
            $deduped[] = $v;
        }
    }

    return $deduped;
}

function usage_limits_resolve_revenuecat_app_user_id(PDO $pdo, string $userId, array $beforeRow = []): ?string
{
    $candidates = usage_limits_collect_revenuecat_app_user_id_candidates($pdo, $userId, $beforeRow);
    return !empty($candidates) ? (string)$candidates[0] : null;
}

function usage_limits_try_repair_subscription_status(PDO $pdo, string $userId): array
{
    $beforeRow = usage_limits_get_user_subscription_status($pdo, $userId);
    $beforeActive = usage_limits_is_subscription_active($beforeRow);

    $result = [
        'before' => $beforeRow,
        'after' => $beforeRow,
        'repaired' => false,
        'verified_active' => $beforeActive,
        'rc_app_user_id' => usage_limits_resolve_revenuecat_app_user_id($pdo, $userId, $beforeRow),
    ];

    if ($beforeActive) {
        usage_limits_subscription_debug_log('subscription_repair_skipped_already_active', [
            'user_id' => $userId,
            'before_row' => usage_limits_normalize_subscription_row($beforeRow, $userId),
            'resolved_rc_app_user_id' => $result['rc_app_user_id'],
        ]);
        return $result;
    }

    if (!usage_limits_revenuecat_verification_enabled()) {
        usage_limits_subscription_debug_log('subscription_repair_skipped_verification_disabled', [
            'user_id' => $userId,
            'before_row' => usage_limits_normalize_subscription_row($beforeRow, $userId),
            'resolved_rc_app_user_id' => $result['rc_app_user_id'],
        ]);
        return $result;
    }

    $resolvedRcAppUserId = trim((string)($result['rc_app_user_id'] ?? ''));
    if ($resolvedRcAppUserId === '') {
        usage_limits_subscription_debug_log('subscription_repair_skipped_missing_rc_app_user_id', [
            'user_id' => $userId,
            'before_row' => usage_limits_normalize_subscription_row($beforeRow, $userId),
        ]);
        return $result;
    }

    try {
        $truth = usage_limits_fetch_revenuecat_subscription_truth($resolvedRcAppUserId, $beforeRow['entitlement_id'] ?? null);
        $verifiedActive = usage_limits_is_subscription_active($truth);
        $result['verified_active'] = $verifiedActive;

        usage_limits_subscription_debug_log('subscription_repair_verification_truth', [
            'user_id' => $userId,
            'resolved_rc_app_user_id' => $resolvedRcAppUserId,
            'before_row' => usage_limits_normalize_subscription_row($beforeRow, $userId),
            'verification_truth' => $truth,
            'verified_active' => $verifiedActive,
        ]);

        if ($verifiedActive) {
            usage_limits_upsert_subscription_status($pdo, $userId, [
                'is_pro' => true,
                'plan_code' => $truth['plan_code'] ?? null,
                'entitlement_id' => $truth['entitlement_id'] ?? ($beforeRow['entitlement_id'] ?? null),
                'rc_app_user_id' => $truth['rc_app_user_id'] ?? $resolvedRcAppUserId,
                'expires_at' => $truth['expires_at'] ?? null,
            ]);
        }

        $afterRow = usage_limits_get_user_subscription_status($pdo, $userId);
        $result['after'] = $afterRow;

        $afterActive = usage_limits_is_subscription_active($afterRow);
        $result['repaired'] = (!$beforeActive && $afterActive);

        usage_limits_subscription_debug_log('subscription_repair_result', [
            'user_id' => $userId,
            'resolved_rc_app_user_id' => $resolvedRcAppUserId,
            'before_row' => usage_limits_normalize_subscription_row($beforeRow, $userId),
            'after_row' => usage_limits_normalize_subscription_row($afterRow, $userId),
            'verified_active' => $result['verified_active'],
            'repaired' => $result['repaired'],
            'computed_is_pro' => $afterActive,
        ]);
    } catch (Throwable $e) {
        usage_limits_log_exception('subscription_repair_failed', $e, [
            'user_id' => $userId,
            'resolved_rc_app_user_id' => $resolvedRcAppUserId,
            'before_row' => usage_limits_normalize_subscription_row($beforeRow, $userId),
        ]);
    }

    return $result;
}

function usage_limits_is_user_pro(PDO $pdo, string $userId): bool
{
    $status = usage_limits_get_user_subscription_status($pdo, $userId);
    $isActive = usage_limits_is_subscription_active($status);

    if (!$isActive) {
        $expiresAt = $status['expires_at'] ?? null;
        $expiresAtText = trim((string)$expiresAt);
        $expiresAtTs = null;
        $expiresAtParseResult = 'empty';

        if ($expiresAtText !== '') {
            $expiresAtTs = strtotime($expiresAtText);
            if ($expiresAtTs === false) {
                $expiresAtParseResult = 'invalid_datetime';
                $expiresAtTs = null;
            } else {
                $expiresAtParseResult = gmdate('Y-m-d H:i:s', $expiresAtTs);
            }
        }

        usage_limits_subscription_debug_log('is_user_pro_false', [
            'user_id' => $userId,
            'is_pro_field' => !empty($status['is_pro']),
            'expires_at_field' => ($expiresAtText !== '' ? $expiresAtText : null),
            'expires_at_parse_result' => $expiresAtParseResult,
            'current_utc_time' => gmdate('Y-m-d H:i:s'),
            'final_active_calculation' => $isActive,
            'subscription_row' => usage_limits_normalize_subscription_row($status, $userId),
        ]);
    }

    return $isActive;
}

function usage_limits_upsert_subscription_status(PDO $pdo, string $userId, array $payload): array
{
    $s = usage_limits_get_subscription_schema($pdo);

    $existing = usage_limits_get_user_subscription_status($pdo, $userId);
    $exists = !empty($existing['exists']);

    $normalizedPayload = usage_limits_normalize_subscription_row([
        'exists' => $exists,
        'user_id' => $userId,
        'is_pro' => !empty($payload['is_pro']) ? 1 : 0,
        'plan_code' => $payload['plan_code'] ?? null,
        'entitlement_id' => $payload['entitlement_id'] ?? null,
        'rc_app_user_id' => $payload['rc_app_user_id'] ?? null,
        'expires_at' => $payload['expires_at'] ?? null,
    ], $userId);

    $changes = usage_limits_get_subscription_state_changes($existing, $normalizedPayload);
    if ($exists && empty($changes)) {
        return $existing;
    }

    $isPro = $normalizedPayload['is_pro'] ? 1 : 0;
    $planCode = $normalizedPayload['plan_code'];
    $entitlementId = $normalizedPayload['entitlement_id'];
    $rcAppUserId = $normalizedPayload['rc_app_user_id'];
    $expiresAt = $normalizedPayload['expires_at'];

    if ($exists) {
        $set = [usage_limits_q($s['is_pro']) . ' = ?'];
        $params = [$isPro];

        if ($s['plan_code']) {
            $set[] = usage_limits_q($s['plan_code']) . ' = ?';
            $params[] = $planCode;
        }
        if ($s['entitlement_id']) {
            $set[] = usage_limits_q($s['entitlement_id']) . ' = ?';
            $params[] = $entitlementId;
        }
        if ($s['rc_app_user_id']) {
            $set[] = usage_limits_q($s['rc_app_user_id']) . ' = ?';
            $params[] = $rcAppUserId;
        }
        if ($s['expires_at']) {
            $set[] = usage_limits_q($s['expires_at']) . ' = ?';
            $params[] = $expiresAt;
        }
        if ($s['updated_at']) {
            $set[] = usage_limits_q($s['updated_at']) . ' = NOW()';
        }

        $whereCol = $s['id'] ?: $s['user_id'];
        $whereVal = $s['id'] ? (string)$existing['id'] : $userId;
        $params[] = $whereVal;

        $sql = 'UPDATE ' . usage_limits_q($s['table'])
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . usage_limits_q($whereCol) . ' = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $cols = [usage_limits_q($s['user_id']), usage_limits_q($s['is_pro'])];
        $vals = ['?', '?'];
        $params = [$userId, $isPro];

        if ($s['id']) {
            $cols[] = usage_limits_q($s['id']);
            $vals[] = '?';
            $params[] = generate_uuid();
        }
        if ($s['plan_code']) {
            $cols[] = usage_limits_q($s['plan_code']);
            $vals[] = '?';
            $params[] = $planCode;
        }
        if ($s['entitlement_id']) {
            $cols[] = usage_limits_q($s['entitlement_id']);
            $vals[] = '?';
            $params[] = $entitlementId;
        }
        if ($s['rc_app_user_id']) {
            $cols[] = usage_limits_q($s['rc_app_user_id']);
            $vals[] = '?';
            $params[] = $rcAppUserId;
        }
        if ($s['expires_at']) {
            $cols[] = usage_limits_q($s['expires_at']);
            $vals[] = '?';
            $params[] = $expiresAt;
        }
        if ($s['created_at']) {
            $cols[] = usage_limits_q($s['created_at']);
            $vals[] = 'NOW()';
        }
        if ($s['updated_at']) {
            $cols[] = usage_limits_q($s['updated_at']);
            $vals[] = 'NOW()';
        }

        $sql = 'INSERT INTO ' . usage_limits_q($s['table'])
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    return usage_limits_get_user_subscription_status($pdo, $userId);
}

function usage_limits_get_or_create_counter(
    PDO $pdo,
    string $userId,
    string $qualificationId,
    string $featureKey,
    ?string $usageDateTr = null
): array {
    $c = usage_limits_get_daily_counter_schema($pdo);
    $usageDateTr = $usageDateTr ?: usage_limits_tr_date();

    $selectSql = 'SELECT '
        . ($c['id'] ? usage_limits_q($c['id']) : 'NULL') . ' AS id, '
        . usage_limits_q($c['used_count']) . ' AS used_count '
        . 'FROM ' . usage_limits_q($c['table'])
        . ' WHERE ' . usage_limits_q($c['user_id']) . ' = ?'
        . ' AND ' . usage_limits_q($c['qualification_id']) . ' = ?'
        . ' AND ' . usage_limits_q($c['usage_date_tr']) . ' = ?'
        . ' AND ' . usage_limits_q($c['feature_key']) . ' = ?'
        . ' LIMIT 1';

    $selectStmt = $pdo->prepare($selectSql);
    $selectStmt->execute([$userId, $qualificationId, $usageDateTr, $featureKey]);
    $row = $selectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $cols = [
            usage_limits_q($c['user_id']),
            usage_limits_q($c['qualification_id']),
            usage_limits_q($c['usage_date_tr']),
            usage_limits_q($c['feature_key']),
            usage_limits_q($c['used_count']),
        ];
        $vals = ['?', '?', '?', '?', '?'];
        $params = [$userId, $qualificationId, $usageDateTr, $featureKey, 0];

        if ($c['id']) {
            $cols[] = usage_limits_q($c['id']);
            $vals[] = '?';
            $params[] = generate_uuid();
        }
        if ($c['created_at']) {
            $cols[] = usage_limits_q($c['created_at']);
            $vals[] = 'NOW()';
        }
        if ($c['updated_at']) {
            $cols[] = usage_limits_q($c['updated_at']);
            $vals[] = 'NOW()';
        }

        try {
            $insertSql = 'INSERT INTO ' . usage_limits_q($c['table'])
                . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute($params);
        } catch (Throwable $e) {
            // yarış durumunda aynı kayıt başka istek tarafından eklenmiş olabilir.
        }

        $selectStmt->execute([$userId, $qualificationId, $usageDateTr, $featureKey]);
        $row = $selectStmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => null, 'used_count' => 0];
    }

    return [
        'id' => $row['id'] ?? null,
        'user_id' => $userId,
        'qualification_id' => $qualificationId,
        'usage_date_tr' => $usageDateTr,
        'feature_key' => $featureKey,
        'used_count' => (int)($row['used_count'] ?? 0),
    ];
}

function usage_limits_increment_counter(
    PDO $pdo,
    string $userId,
    string $qualificationId,
    string $featureKey,
    int $amount = 1,
    ?string $usageDateTr = null
): array {
    $amount = max(1, $amount);
    $c = usage_limits_get_daily_counter_schema($pdo);
    $usageDateTr = $usageDateTr ?: usage_limits_tr_date();
    $counter = usage_limits_get_or_create_counter($pdo, $userId, $qualificationId, $featureKey, $usageDateTr);

    $set = [usage_limits_q($c['used_count']) . ' = ' . usage_limits_q($c['used_count']) . ' + ?'];
    $params = [$amount];
    if ($c['updated_at']) {
        $set[] = usage_limits_q($c['updated_at']) . ' = NOW()';
    }

    if ($c['id'] && !empty($counter['id'])) {
        $where = usage_limits_q($c['id']) . ' = ?';
        $params[] = $counter['id'];
    } else {
        $where = usage_limits_q($c['user_id']) . ' = ?'
            . ' AND ' . usage_limits_q($c['qualification_id']) . ' = ?'
            . ' AND ' . usage_limits_q($c['usage_date_tr']) . ' = ?'
            . ' AND ' . usage_limits_q($c['feature_key']) . ' = ?';
        $params[] = $userId;
        $params[] = $qualificationId;
        $params[] = $usageDateTr;
        $params[] = $featureKey;
    }

    $sql = 'UPDATE ' . usage_limits_q($c['table'])
        . ' SET ' . implode(', ', $set)
        . ' WHERE ' . $where;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return usage_limits_get_or_create_counter($pdo, $userId, $qualificationId, $featureKey, $usageDateTr);
}

function usage_limits_build_feature_summary(
    PDO $pdo,
    string $userId,
    string $qualificationId,
    string $featureKey,
    bool $isPro,
    ?string $usageDateTr = null
): array {
    $usageDateTr = $usageDateTr ?: usage_limits_tr_date();
    $dailyLimit = usage_limits_get_daily_limit($featureKey);
    $counter = usage_limits_get_or_create_counter($pdo, $userId, $qualificationId, $featureKey, $usageDateTr);
    $usedCount = (int)($counter['used_count'] ?? 0);

    if ($isPro) {
        return [
            'feature_key' => $featureKey,
            'daily_limit' => $dailyLimit,
            'used_count' => $usedCount,
            'remaining_count' => null,
            'remainingCount' => null,
            'state' => 'premium',
        ];
    }

    $remaining = max(0, $dailyLimit - $usedCount);
    return [
        'feature_key' => $featureKey,
        'daily_limit' => $dailyLimit,
        'used_count' => $usedCount,
        'remaining_count' => $remaining,
        'remainingCount' => $remaining,
        'state' => ($remaining > 0 ? 'available' : 'exhausted'),
    ];
}

function usage_limits_get_summary(PDO $pdo, string $userId, string $qualificationId): array
{
    $usageDateTr = usage_limits_tr_date();
    $subscription = usage_limits_get_user_subscription_status($pdo, $userId);
    $isPro = usage_limits_is_subscription_active($subscription);

    return [
        'is_pro' => $isPro,
        'state' => $isPro ? 'premium' : 'free',
        'qualification_id' => $qualificationId,
        'usage_date_tr' => $usageDateTr,
        'study' => usage_limits_build_feature_summary(
            $pdo,
            $userId,
            $qualificationId,
            USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN,
            $isPro,
            $usageDateTr
        ),
        'mock_exam' => usage_limits_build_feature_summary(
            $pdo,
            $userId,
            $qualificationId,
            USAGE_LIMIT_FEATURE_MOCK_EXAM_START,
            $isPro,
            $usageDateTr
        ),
    ];
}

function usage_limits_consume(PDO $pdo, string $userId, string $qualificationId, string $featureKey, int $amount = 1): array
{
    $amount = max(1, $amount);
    $usageDateTr = usage_limits_tr_date();
    $isPro = usage_limits_is_user_pro($pdo, $userId);

    if ($isPro) {
        return [
            'is_pro' => true,
            'consumed' => false,
            'summary' => usage_limits_get_summary($pdo, $userId, $qualificationId),
        ];
    }

    $dailyLimit = usage_limits_get_daily_limit($featureKey);
    $counter = usage_limits_get_or_create_counter($pdo, $userId, $qualificationId, $featureKey, $usageDateTr);
    $used = (int)($counter['used_count'] ?? 0);

    if (($used + $amount) > $dailyLimit) {
        return [
            'is_pro' => false,
            'consumed' => false,
            'summary' => usage_limits_get_summary($pdo, $userId, $qualificationId),
        ];
    }

    usage_limits_increment_counter($pdo, $userId, $qualificationId, $featureKey, $amount, $usageDateTr);

    return [
        'is_pro' => false,
        'consumed' => true,
        'summary' => usage_limits_get_summary($pdo, $userId, $qualificationId),
    ];
}

function usage_limits_business_error(string $code, string $message, int $status, ?array $summary = null): void
{
    api_send_json([
        'success' => false,
        'code' => $code,
        'message' => $message,
        'summary' => $summary,
        'data' => null,
    ], $status);
}

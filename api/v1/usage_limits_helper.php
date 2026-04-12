<?php

require_once __DIR__ . '/auth_helper.php';

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

    $isPro = ((int)($row['is_pro'] ?? 0) === 1);
    $expiresAt = $row['expires_at'] ?? null;
    if ($isPro && is_string($expiresAt) && trim($expiresAt) !== '') {
        $expiresTs = strtotime($expiresAt);
        if ($expiresTs !== false && $expiresTs <= time()) {
            $isPro = false;
        }
    }

    return [
        'exists' => $exists,
        'id' => $row['id'] ?? null,
        'user_id' => (string)($row['user_id'] ?? $userId),
        'is_pro' => $isPro,
        'plan_code' => $row['plan_code'] ?? null,
        'entitlement_id' => $row['entitlement_id'] ?? null,
        'rc_app_user_id' => $row['rc_app_user_id'] ?? null,
        'expires_at' => $row['expires_at'] ?? null,
    ];
}

function usage_limits_is_user_pro(PDO $pdo, string $userId): bool
{
    $status = usage_limits_get_user_subscription_status($pdo, $userId);
    return (bool)($status['is_pro'] ?? false);
}

function usage_limits_upsert_subscription_status(PDO $pdo, string $userId, array $payload): array
{
    $s = usage_limits_get_subscription_schema($pdo);

    $existing = usage_limits_get_user_subscription_status($pdo, $userId);
    $exists = !empty($existing['exists']);

    $isPro = !empty($payload['is_pro']) ? 1 : 0;
    $planCode = $payload['plan_code'] ?? null;
    $entitlementId = $payload['entitlement_id'] ?? null;
    $rcAppUserId = $payload['rc_app_user_id'] ?? null;
    $expiresAt = $payload['expires_at'] ?? null;

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
            'state' => 'premium',
        ];
    }

    $remaining = max(0, $dailyLimit - $usedCount);
    return [
        'feature_key' => $featureKey,
        'daily_limit' => $dailyLimit,
        'used_count' => $usedCount,
        'remaining_count' => $remaining,
        'state' => ($remaining > 0 ? 'available' : 'exhausted'),
    ];
}

function usage_limits_get_summary(PDO $pdo, string $userId, string $qualificationId): array
{
    $usageDateTr = usage_limits_tr_date();
    $isPro = usage_limits_is_user_pro($pdo, $userId);

    return [
        'is_pro' => $isPro,
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

<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/user_lifecycle_helper.php';

$authUser = require_admin();

function users_response($success, $message = '', $data = [], $status = 200, $errors = [])
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function users_pick_column(array $columns, array $candidates, bool $required = true): ?string
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

function users_has_table(PDO $pdo, string $table): bool
{
    static $currentDatabase = null;
    static $tableExistsCache = [];
    static $loggedTables = [];

    $table = trim($table);
    if ($table === '') {
        return false;
    }

    if (array_key_exists($table, $tableExistsCache)) {
        return $tableExistsCache[$table];
    }

    try {
        if ($currentDatabase === null) {
            $dbStmt = $pdo->query('SELECT DATABASE()');
            $currentDatabase = $dbStmt ? trim((string)$dbStmt->fetchColumn()) : '';
        }

        if ($currentDatabase === '') {
            $tableExistsCache[$table] = false;
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$currentDatabase, $table]);
        $exists = (bool)$stmt->fetchColumn();
        $tableExistsCache[$table] = $exists;

        $watchedTables = [
            'qualifications',
            'question_attempt_events',
            'mock_exam_attempts',
            'api_tokens',
            'user_push_tokens',
        ];
        if (in_array($table, $watchedTables, true) && !isset($loggedTables[$table])) {
            $loggedTables[$table] = true;
            users_debug_log('users_has_table result', [
                'table' => $table,
                'exists' => $exists,
                'database' => $currentDatabase,
            ]);
        }

        return $exists;
    } catch (Throwable $e) {
        $tableExistsCache[$table] = false;
        $watchedTables = [
            'qualifications',
            'question_attempt_events',
            'mock_exam_attempts',
            'api_tokens',
            'user_push_tokens',
        ];
        if (in_array($table, $watchedTables, true) && !isset($loggedTables[$table])) {
            $loggedTables[$table] = true;
            users_debug_log('users_has_table failed', [
                'table' => $table,
                'database' => $currentDatabase,
                'error' => $e->getMessage(),
            ]);
        }
        return false;
    }
}

function users_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'user_profiles');
    if (empty($cols)) {
        throw new RuntimeException('user_profiles tablosu okunamadı.');
    }

    return [
        'table' => 'user_profiles',
        'id' => users_pick_column($cols, ['id']),
        'full_name' => users_pick_column($cols, ['full_name', 'name', 'display_name'], false),
        'email' => users_pick_column($cols, ['email']),
        'is_guest' => users_pick_column($cols, ['is_guest', 'guest'], false),
        'is_admin' => users_pick_column($cols, ['is_admin'], false),
        'password' => users_pick_column($cols, ['password_hash', 'hashed_password', 'password', 'pass_hash', 'passwd'], false),
        'created_at' => users_pick_column($cols, ['created_at', 'created_on'], false),
        'updated_at' => users_pick_column($cols, ['updated_at', 'updated_on'], false),
        'last_sign_in_at' => users_pick_column($cols, ['last_sign_in_at', 'last_login_at'], false),
        'onboarding_completed' => users_pick_column($cols, ['onboarding_completed', 'is_onboarding_completed'], false),
        'email_verified' => users_pick_column($cols, ['email_verified'], false),
        'email_verified_at' => users_pick_column($cols, ['email_verified_at'], false),
        'pending_email' => users_pick_column($cols, ['pending_email'], false),
        'is_deleted' => users_pick_column($cols, ['is_deleted'], false),
        'deleted_at' => users_pick_column($cols, ['deleted_at'], false),
        'current_qualification_id' => users_pick_column($cols, ['current_qualification_id', 'qualification_id'], false),
        'target_qualification_id' => users_pick_column($cols, ['target_qualification_id'], false),
    ];
}

function users_subscription_schema(PDO $pdo): ?array
{
    $cols = get_table_columns($pdo, 'user_subscription_status');
    if (empty($cols)) {
        return null;
    }

    return [
        'table' => 'user_subscription_status',
        'id' => users_pick_column($cols, ['id'], false),
        'user_id' => users_pick_column($cols, ['user_id']),
        'is_pro' => users_pick_column($cols, ['is_pro'], false),
        'plan_code' => users_pick_column($cols, ['plan_code'], false),
        'provider' => users_pick_column($cols, ['provider'], false),
        'entitlement_id' => users_pick_column($cols, ['entitlement_id'], false),
        'rc_app_user_id' => users_pick_column($cols, ['rc_app_user_id'], false),
        'expires_at' => users_pick_column($cols, ['expires_at'], false),
        'last_synced_at' => users_pick_column($cols, ['last_synced_at'], false),
        'created_at' => users_pick_column($cols, ['created_at'], false),
        'updated_at' => users_pick_column($cols, ['updated_at'], false),
    ];
}

function users_admin_notes_schema(PDO $pdo): ?array
{
    $cols = get_table_columns($pdo, 'user_admin_notes');
    if (empty($cols)) {
        return null;
    }

    return [
        'table' => 'user_admin_notes',
        'id' => users_pick_column($cols, ['id'], false),
        'user_id' => users_pick_column($cols, ['user_id']),
        'note' => users_pick_column($cols, ['note', 'note_text', 'content', 'message'], false),
        'admin_user_id' => users_pick_column($cols, ['admin_user_id', 'created_by', 'author_user_id'], false),
        'admin_name' => users_pick_column($cols, ['admin_name', 'created_by_name', 'author_name'], false),
        'created_at' => users_pick_column($cols, ['created_at', 'created_on'], false),
        'updated_at' => users_pick_column($cols, ['updated_at', 'updated_on'], false),
        'is_deleted' => users_pick_column($cols, ['is_deleted'], false),
    ];
}

function users_bool($value): int
{
    return in_array((string)$value, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

function users_now(): string
{
    return date('Y-m-d H:i:s');
}

function users_fmt_bool($value): int
{
    return ((int)$value === 1) ? 1 : 0;
}

function users_is_premium_active(array $subscription): bool
{
    $isPro = !empty($subscription['is_pro']) && (int)$subscription['is_pro'] === 1;
    if (!$isPro) {
        return false;
    }

    $expiresAt = trim((string)($subscription['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return true;
    }

    $ts = strtotime($expiresAt);
    if ($ts === false) {
        return false;
    }

    return $ts > time();
}

function users_detect_guest(array $user, array $schema): bool
{
    if (!empty($schema['is_guest'])) {
        return ((int)($user['is_guest'] ?? 0) === 1);
    }

    $email = strtolower(trim((string)($user['email'] ?? '')));
    $fullName = strtolower(trim((string)($user['full_name'] ?? '')));
    return ($email !== '' && str_ends_with($email, '@guest.local'))
        || in_array($fullName, ['misafir kullanıcı', 'misafir kullanici', 'guest user'], true);
}

function users_select_clause(array $schema): string
{
    $select = [
        "`{$schema['id']}` AS id",
        "`{$schema['email']}` AS email",
    ];

    $select[] = $schema['full_name'] ? "`{$schema['full_name']}` AS full_name" : "'' AS full_name";
    $select[] = $schema['is_guest'] ? "`{$schema['is_guest']}` AS is_guest" : '0 AS is_guest';
    $select[] = $schema['is_admin'] ? "`{$schema['is_admin']}` AS is_admin" : '0 AS is_admin';
    $select[] = $schema['created_at'] ? "`{$schema['created_at']}` AS created_at" : 'NULL AS created_at';
    $select[] = $schema['updated_at'] ? "`{$schema['updated_at']}` AS updated_at" : 'NULL AS updated_at';
    $select[] = $schema['last_sign_in_at'] ? "`{$schema['last_sign_in_at']}` AS last_sign_in_at" : 'NULL AS last_sign_in_at';
    $select[] = $schema['onboarding_completed'] ? "`{$schema['onboarding_completed']}` AS onboarding_completed" : '0 AS onboarding_completed';
    $select[] = $schema['email_verified'] ? "`{$schema['email_verified']}` AS email_verified" : '0 AS email_verified';
    $select[] = $schema['email_verified_at'] ? "`{$schema['email_verified_at']}` AS email_verified_at" : 'NULL AS email_verified_at';
    $select[] = $schema['pending_email'] ? "`{$schema['pending_email']}` AS pending_email" : 'NULL AS pending_email';
    $select[] = $schema['is_deleted'] ? "`{$schema['is_deleted']}` AS is_deleted" : '0 AS is_deleted';
    $select[] = $schema['current_qualification_id'] ? "`{$schema['current_qualification_id']}` AS current_qualification_id" : 'NULL AS current_qualification_id';
    $select[] = $schema['target_qualification_id'] ? "`{$schema['target_qualification_id']}` AS target_qualification_id" : 'NULL AS target_qualification_id';

    return implode(', ', $select);
}

function users_get_subscription_map(PDO $pdo, array $userIds): array
{
    $userIds = array_values(array_filter(array_map('strval', $userIds), static fn($v) => $v !== ''));
    if (empty($userIds)) {
        return [];
    }

    $schema = users_subscription_schema($pdo);
    if (!$schema) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
    $orderCol = $schema['updated_at'] ?: ($schema['created_at'] ?: ($schema['id'] ?: $schema['user_id']));

    $select = [
        "`{$schema['user_id']}` AS user_id",
        ($schema['is_pro'] ? "`{$schema['is_pro']}`" : '0') . ' AS is_pro',
        ($schema['plan_code'] ? "`{$schema['plan_code']}`" : 'NULL') . ' AS plan_code',
        ($schema['provider'] ? "`{$schema['provider']}`" : 'NULL') . ' AS provider',
        ($schema['entitlement_id'] ? "`{$schema['entitlement_id']}`" : 'NULL') . ' AS entitlement_id',
        ($schema['rc_app_user_id'] ? "`{$schema['rc_app_user_id']}`" : 'NULL') . ' AS rc_app_user_id',
        ($schema['expires_at'] ? "`{$schema['expires_at']}`" : 'NULL') . ' AS expires_at',
        ($schema['last_synced_at'] ? "`{$schema['last_synced_at']}`" : 'NULL') . ' AS last_synced_at',
        ($schema['created_at'] ? "`{$schema['created_at']}`" : 'NULL') . ' AS created_at',
        ($schema['updated_at'] ? "`{$schema['updated_at']}`" : 'NULL') . ' AS updated_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM `{$schema['table']}` WHERE `{$schema['user_id']}` IN ({$placeholders})"
        . " ORDER BY `{$schema['user_id']}` ASC, `{$orderCol}` DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($userIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $uid = (string)($row['user_id'] ?? '');
        if ($uid === '' || isset($map[$uid])) {
            continue;
        }
        $map[$uid] = $row;
    }

    return $map;
}

function users_find_by_id(PDO $pdo, array $schema, $id): ?array
{
    $sql = 'SELECT ' . users_select_clause($schema) . " FROM `{$schema['table']}` WHERE `{$schema['id']}` = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$user) {
        return null;
    }
    if ($schema['is_deleted'] && ((int)($user['is_deleted'] ?? 0) === 1)) {
        return null;
    }
    return $user;
}

function users_get_qualification_map(PDO $pdo): array
{
    if (!users_has_table($pdo, 'qualifications')) {
        users_debug_log('qualification map skipped: qualifications table not found');
        return [];
    }

    $map = [];
    try {
        $cols = get_table_columns($pdo, 'qualifications');
        $idCol = users_pick_column($cols, ['id'], false);
        $nameCol = users_pick_column($cols, ['name', 'title'], false);
        if (!$idCol || !$nameCol) {
            error_log('[USERS][DETAIL] qualification map query failed | ' . json_encode([
                'error' => 'qualification columns missing',
                'columns' => $cols,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            users_debug_log('qualification map required columns unavailable', [
                'columns' => $cols,
            ]);
            return [];
        }

        $stmt = $pdo->query('SELECT ' . users_q($idCol) . ' AS id, ' . users_q($nameCol) . ' AS name FROM ' . users_q('qualifications'));
        if (!$stmt) {
            users_debug_log('qualification map query returned no statement');
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            users_debug_log('qualification map query returned empty rows');
        }

        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            $map[$id] = $name;
        }
    } catch (Throwable $e) {
        error_log('[USERS][DETAIL] qualification map query failed | ' . json_encode([
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        users_debug_log('qualification map query failed', [
            'error' => $e->getMessage(),
        ]);
    }

    if (empty($map)) {
        users_debug_log('qualification map is empty after build');
    }

    return $map;
}

function users_resolve_qualification_label(array $qualificationMap, ?string $qualificationId): string
{
    $qid = trim((string)($qualificationId ?? ''));
    if ($qid === '') {
        return '-';
    }

    $name = trim((string)($qualificationMap[$qid] ?? ''));
    if ($name !== '') {
        return $name;
    }

    users_debug_log('qualification name missing in map', [
        'qualification_id' => $qid,
        'map_size' => count($qualificationMap),
    ]);

    return 'Tanımsız Yeterlilik';
}

function users_feature_daily_limit(string $featureKey): ?int
{
    $key = strtolower(trim($featureKey));
    return match ($key) {
        'study_question_open' => 60,
        'mock_exam_start' => 3,
        default => null,
    };
}

function users_pick_latest_datetime(?string ...$values): ?string
{
    $latest = null;
    $latestTs = null;
    foreach ($values as $value) {
        $v = trim((string)($value ?? ''));
        if ($v === '') {
            continue;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            continue;
        }
        if ($latestTs === null || $ts > $latestTs) {
            $latestTs = $ts;
            $latest = $v;
        }
    }
    return $latest;
}

function users_pick_user_fk_column(array $columns): ?string
{
    return users_pick_column($columns, ['user_id', 'user_profile_id', 'profile_id', 'account_user_id', 'owner_user_id', 'actor_user_id'], false);
}

function users_pick_best_user_fk_column(PDO $pdo, string $table, string $userId): ?string
{
    if (!users_has_table($pdo, $table)) {
        return null;
    }

    $cols = get_table_columns($pdo, $table);
    if (empty($cols)) {
        return null;
    }

    $candidates = array_values(array_filter(['user_id', 'user_profile_id', 'profile_id', 'account_user_id', 'owner_user_id', 'actor_user_id'], static fn($c) => in_array($c, $cols, true)));
    if (empty($candidates)) {
        return null;
    }

    $bestCol = $candidates[0];
    $bestCount = -1;
    foreach ($candidates as $col) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` = ?");
            $stmt->execute([$userId]);
            $count = (int)$stmt->fetchColumn();
            if ($count > $bestCount) {
                $bestCol = $col;
                $bestCount = $count;
            }
        } catch (Throwable $e) {
        }
    }

    return $bestCol;
}

function users_pick_effective_user_fk_column(PDO $pdo, string $table, array $columns, string $userId): ?string
{
    $best = users_pick_best_user_fk_column($pdo, $table, $userId);
    if ($best && in_array($best, $columns, true)) {
        return $best;
    }

    return users_pick_user_fk_column($columns);
}

function users_decimal_rate(int $num, int $den): float
{
    if ($den <= 0) {
        return 0.0;
    }
    return round(($num / $den) * 100, 2);
}

function users_event_source_whitelist(): array
{
    return ['study', 'daily_quiz', 'mock_exam'];
}

function users_is_event_source_allowed(?string $source): bool
{
    $s = strtolower(trim((string)$source));
    return in_array($s, users_event_source_whitelist(), true);
}

function users_exam_status_normalize($rawStatus, $submittedAt = null, $abandonedAt = null, $startedAt = null): string
{
    $status = strtolower(trim((string)$rawStatus));

    if ($status !== '') {
        if (str_contains($status, 'complete') || str_contains($status, 'submit') || str_contains($status, 'finish')) {
            return 'completed';
        }
        if (str_contains($status, 'abandon') || str_contains($status, 'cancel') || str_contains($status, 'expire')) {
            return 'abandoned';
        }
        if (str_contains($status, 'progress') || str_contains($status, 'active') || str_contains($status, 'start')) {
            return 'in_progress';
        }
    }

    if (in_array($status, ['completed', 'submitted', 'finished'], true)) {
        return 'completed';
    }
    if (in_array($status, ['abandoned', 'cancelled', 'expired'], true)) {
        return 'abandoned';
    }
    if (in_array($status, ['in_progress', 'active', 'started'], true)) {
        return 'in_progress';
    }

    if (trim((string)$submittedAt) !== '') {
        return 'completed';
    }
    if (trim((string)$abandonedAt) !== '') {
        return 'abandoned';
    }
    if (trim((string)$startedAt) !== '') {
        return 'in_progress';
    }

    return 'unknown';
}

function users_exam_status_label(string $normalizedStatus): string
{
    return match ($normalizedStatus) {
        'completed' => 'Tamamlandı',
        'in_progress' => 'Devam Ediyor',
        'abandoned' => 'Terk Edildi',
        default => 'Bilinmiyor',
    };
}

function users_push_token_preview(?string $token): string
{
    $t = trim((string)($token ?? ''));
    if ($t === '') {
        return '-';
    }
    if (mb_strlen($t) <= 14) {
        return $t;
    }

    return mb_substr($t, 0, 7) . '...' . mb_substr($t, -7);
}

function users_debug_log(string $message, array $context = []): void
{
    static $enabled = null;
    if ($enabled === null) {
        $enabled = in_array((string)($_GET['debug_user_detail'] ?? ''), ['1', 'true', 'on'], true);
    }

    if (!$enabled) {
        return;
    }

    $prefix = '[USERS][DETAIL] ' . $message;
    if (!empty($context)) {
        $payload = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log($prefix . ' | ' . ($payload !== false ? $payload : '{}'));
        return;
    }
    error_log($prefix);
}

function users_count_rows_by_user(PDO $pdo, string $table, string $userId): int
{
    if (!users_has_table($pdo, $table)) {
        return 0;
    }

    $cols = get_table_columns($pdo, $table);
    if (empty($cols)) {
        return 0;
    }

    $userCol = users_pick_effective_user_fk_column($pdo, $table, $cols, $userId);
    if (!$userCol) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$userCol}` = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        users_debug_log('raw count failed', [
            'table' => $table,
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
        return 0;
    }
}

function users_log_raw_user_counts(PDO $pdo, string $userId): array
{
    $counts = [
        'user_progress' => users_count_rows_by_user($pdo, 'user_progress', $userId),
        'question_attempt_events' => users_count_rows_by_user($pdo, 'question_attempt_events', $userId),
        'mock_exam_attempts' => users_count_rows_by_user($pdo, 'mock_exam_attempts', $userId),
        'user_daily_usage_counters' => users_count_rows_by_user($pdo, 'user_daily_usage_counters', $userId),
        'api_tokens' => users_count_rows_by_user($pdo, 'api_tokens', $userId),
        'user_push_tokens' => users_count_rows_by_user($pdo, 'user_push_tokens', $userId),
    ];

    users_debug_log('raw user table counts', [
        'user_id' => $userId,
        'counts' => $counts,
    ]);

    return $counts;
}

function users_resolve_qualification_name(array $qualificationMap, ?string $qualificationId): string
{
    return users_resolve_qualification_label($qualificationMap, $qualificationId);
}

function users_q(string $identifier): string
{
    return '`' . str_replace('`', '', $identifier) . '`';
}

function users_pick_existing_table(PDO $pdo, array $tables): ?string
{
    foreach ($tables as $table) {
        if (users_has_table($pdo, $table)) {
            return $table;
        }
    }

    return null;
}

function users_get_name_lookup_map(PDO $pdo, array $tables, array $nameCandidates = ['name']): array
{
    $table = users_pick_existing_table($pdo, $tables);
    if (!$table) {
        return [];
    }

    $cols = get_table_columns($pdo, $table);
    if (empty($cols)) {
        return [];
    }

    $idCol = users_pick_column($cols, ['id'], false);
    $nameCol = users_pick_column($cols, $nameCandidates, false);
    if (!$idCol || !$nameCol) {
        return [];
    }

    try {
        $sql = 'SELECT ' . users_q($idCol) . ' AS id, ' . users_q($nameCol) . ' AS name FROM ' . users_q($table);
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $map[$id] = trim((string)($row['name'] ?? ''));
        }
        return $map;
    } catch (Throwable $e) {
        error_log('[USERS][DETAIL] qualification map query failed | ' . json_encode([
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        users_debug_log('lookup map read failed', ['table' => $table, 'error' => $e->getMessage()]);
        return [];
    }
}

function users_resolve_lookup_name(array $map, ?string $id, string $fallback = '-'): string
{
    $key = trim((string)($id ?? ''));
    if ($key === '') {
        return $fallback;
    }

    $name = trim((string)($map[$key] ?? ''));
    return $name !== '' ? $name : $fallback;
}

function users_get_group_distribution(PDO $pdo, string $table, string $userCol, string $groupCol, string $userId, array $lookupMap = [], string $fallbackName = '-'): array
{
    try {
        $sql = 'SELECT ' . users_q($groupCol) . ' AS group_id, COUNT(*) AS total'
            . ' FROM ' . users_q($table)
            . ' WHERE ' . users_q($userCol) . ' = ?'
            . ' GROUP BY ' . users_q($groupCol)
            . ' ORDER BY total DESC'
            . ' LIMIT 15';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        users_debug_log('group distribution query failed', [
            'table' => $table,
            'group_col' => $groupCol,
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
        return [];
    }

    $distribution = [];
    foreach ($rows as $row) {
        $groupId = trim((string)($row['group_id'] ?? ''));
        $distribution[] = [
            'id' => $groupId !== '' ? $groupId : null,
            'name' => users_resolve_lookup_name($lookupMap, $groupId, $fallbackName),
            'total' => (int)($row['total'] ?? 0),
        ];
    }

    return $distribution;
}

function users_is_nonempty_datetime(?string $value): bool
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00' || $raw === '0000-00-00') {
        return false;
    }

    return strtotime($raw) !== false;
}

function users_build_premium_state_summary(array $subscription): string
{
    if (users_is_premium_active($subscription)) {
        return 'Premium Aktif';
    }

    if (!empty($subscription['is_pro']) && (int)$subscription['is_pro'] === 1) {
        return 'Premium Süresi Dolmuş';
    }

    return 'Ücretsiz';
}

function users_build_premium_expiry_summary(array $subscription): string
{
    $expiresAt = trim((string)($subscription['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return '-';
    }

    return $expiresAt;
}

function users_build_top_summary(array $studyStats, array $examStats): array
{
    return [
        'total_solved' => (int)($studyStats['total_solved'] ?? 0),
        'total_correct' => (int)($studyStats['total_correct'] ?? 0),
        'total_wrong' => (int)($studyStats['total_wrong'] ?? 0),
        'success_rate' => (float)($studyStats['success_rate'] ?? 0),
        'total_exams' => (int)($examStats['total_exams'] ?? 0),
        'completed_exams' => (int)($examStats['completed_exams'] ?? 0),
    ];
}

function users_validate_consistency(array $topSummary, array $studyStats, array $examStats): void
{
    $ok = (
        (int)($topSummary['total_solved'] ?? 0) === (int)($studyStats['total_solved'] ?? 0)
        && (int)($topSummary['total_exams'] ?? 0) === (int)($examStats['total_exams'] ?? 0)
        && (int)($topSummary['completed_exams'] ?? 0) === (int)($examStats['completed_exams'] ?? 0)
    );

    if (!$ok) {
        users_debug_log('detail consistency mismatch', [
            'top_summary' => $topSummary,
            'study' => [
                'total_solved' => $studyStats['total_solved'] ?? null,
            ],
            'exam' => [
                'total_exams' => $examStats['total_exams'] ?? null,
                'completed_exams' => $examStats['completed_exams'] ?? null,
            ],
        ]);
    }
}

function users_get_admin_notes(PDO $pdo, string $userId): array
{
    $schema = users_admin_notes_schema($pdo);
    if (!$schema || !$schema['note']) {
        return [];
    }

    $where = ["`{$schema['user_id']}` = ?"];
    $params = [$userId];
    if ($schema['is_deleted']) {
        $where[] = "`{$schema['is_deleted']}` = 0";
    }

    $orderCol = $schema['updated_at'] ?: ($schema['created_at'] ?: ($schema['id'] ?: $schema['user_id']));
    $select = [
        ($schema['id'] ? "`{$schema['id']}`" : 'NULL') . ' AS id',
        "`{$schema['user_id']}` AS user_id",
        "`{$schema['note']}` AS note",
        ($schema['admin_user_id'] ? "`{$schema['admin_user_id']}`" : 'NULL') . ' AS admin_user_id',
        ($schema['admin_name'] ? "`{$schema['admin_name']}`" : 'NULL') . ' AS admin_name',
        ($schema['created_at'] ? "`{$schema['created_at']}`" : 'NULL') . ' AS created_at',
        ($schema['updated_at'] ? "`{$schema['updated_at']}`" : 'NULL') . ' AS updated_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM `{$schema['table']}` WHERE " . implode(' AND ', $where)
        . " ORDER BY `{$orderCol}` DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function users_get_user_study_stats(PDO $pdo, string $userId): array
{
    $eventTotal = 0;
    $eventCorrect = 0;
    $eventWrong = 0;
    $eventLastAt = null;
    $distribution = [];
    $recent = [];
    $breakdown = ['qualification' => [], 'course' => [], 'topic' => []];
    $rawEventRows = 0;
    $rawProgressRows = 0;
    $rawSessionRows = 0;

    if (users_has_table($pdo, 'question_attempt_events')) {
        $cols = get_table_columns($pdo, 'question_attempt_events');
        $uid = users_pick_effective_user_fk_column($pdo, 'question_attempt_events', $cols, $userId);
        if ($uid) {
            $rawEventRows = users_count_rows_by_user($pdo, 'question_attempt_events', $userId);
            $isCorrectCol = users_pick_column($cols, ['is_correct', 'correct', 'is_correct_answer'], false);
            $sourceCol = users_pick_column($cols, ['source', 'attempt_source', 'origin'], false);
            $eventAtCol = users_pick_column($cols, ['attempted_at', 'answered_at', 'event_at', 'created_at', 'updated_at'], false);
            $qidCol = users_pick_column($cols, ['question_id'], false);
            $qualCol = users_pick_column($cols, ['qualification_id'], false);
            $courseCol = users_pick_column($cols, ['course_id'], false);
            $topicCol = users_pick_column($cols, ['topic_id'], false);
            $qualificationMap = users_get_qualification_map($pdo);
            $courseMap = users_get_name_lookup_map($pdo, ['courses']);
            $topicMap = users_get_name_lookup_map($pdo, ['topics']);

            $correctExpr = $isCorrectCol ? "SUM(CASE WHEN `{$isCorrectCol}` = 1 THEN 1 ELSE 0 END)" : '0';
            $wrongExpr = $isCorrectCol ? "SUM(CASE WHEN `{$isCorrectCol}` = 0 THEN 1 ELSE 0 END)" : '0';
            $lastExpr = $eventAtCol ? "MAX(`{$eventAtCol}`)" : 'NULL';

            $totSql = "SELECT COUNT(*) AS total_solved, {$correctExpr} AS correct, {$wrongExpr} AS wrong, {$lastExpr} AS last_study_at FROM question_attempt_events WHERE `{$uid}` = ?";
            $totStmt = $pdo->prepare($totSql);
            $totStmt->execute([$userId]);
            $tot = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $eventTotal = (int)($tot['total_solved'] ?? 0);
            $eventCorrect = (int)($tot['correct'] ?? 0);
            $eventWrong = (int)($tot['wrong'] ?? 0);
            $eventLastAt = $tot['last_study_at'] ?? null;

            if ($sourceCol) {
                $dSql = "SELECT `{$sourceCol}` AS source, COUNT(*) AS total FROM question_attempt_events WHERE `{$uid}` = ? GROUP BY `{$sourceCol}` ORDER BY total DESC";
                $dStmt = $pdo->prepare($dSql);
                $dStmt->execute([$userId]);
                $distribution = $dStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($eventTotal > 0) {
                $distribution = [[
                    'source' => 'study',
                    'total' => $eventTotal,
                ]];
            }

            $selectRecent = [];
            $selectRecent[] = $eventAtCol ? "`{$eventAtCol}` AS event_at" : 'NULL AS event_at';
            $selectRecent[] = $sourceCol ? "`{$sourceCol}` AS source" : 'NULL AS source';
            $selectRecent[] = $isCorrectCol ? "`{$isCorrectCol}` AS is_correct" : 'NULL AS is_correct';
            $selectRecent[] = $qidCol ? "`{$qidCol}` AS question_id" : 'NULL AS question_id';
            $selectRecent[] = $qualCol ? "`{$qualCol}` AS qualification_id" : 'NULL AS qualification_id';
            $selectRecent[] = $courseCol ? "`{$courseCol}` AS course_id" : 'NULL AS course_id';
            $selectRecent[] = $topicCol ? "`{$topicCol}` AS topic_id" : 'NULL AS topic_id';
            $orderCol = $eventAtCol ?: ($qidCol ?: $uid);
            $rSql = 'SELECT ' . implode(', ', $selectRecent) . " FROM question_attempt_events WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 20";
            $rStmt = $pdo->prepare($rSql);
            $rStmt->execute([$userId]);
            $recent = $rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($qualCol) {
                $breakdown['qualification'] = users_get_group_distribution(
                    $pdo,
                    'question_attempt_events',
                    $uid,
                    $qualCol,
                    $userId,
                    $qualificationMap,
                    'Tanımsız Yeterlilik'
                );
            }
            if ($courseCol) {
                $breakdown['course'] = users_get_group_distribution(
                    $pdo,
                    'question_attempt_events',
                    $uid,
                    $courseCol,
                    $userId,
                    $courseMap,
                    'Tanımsız Ders'
                );
            }
            if ($topicCol) {
                $breakdown['topic'] = users_get_group_distribution(
                    $pdo,
                    'question_attempt_events',
                    $uid,
                    $topicCol,
                    $userId,
                    $topicMap,
                    'Tanımsız Konu'
                );
            }
        }
    }

    $progressTotal = 0;
    $progressCorrect = 0;
    $progressWrong = 0;
    $progressLastAt = null;
    if (users_has_table($pdo, 'user_progress')) {
        $rawProgressRows = users_count_rows_by_user($pdo, 'user_progress', $userId);
        $cols = get_table_columns($pdo, 'user_progress');
        $uid = users_pick_effective_user_fk_column($pdo, 'user_progress', $cols, $userId);
        if ($uid) {
            $totalAnswerCol = users_pick_column($cols, ['total_answer_count', 'answer_count', 'total_answers'], false);
            $correctAnswerCol = users_pick_column($cols, ['correct_answer_count', 'correct_count'], false);
            $wrongAnswerCol = users_pick_column($cols, ['wrong_answer_count', 'wrong_count', 'incorrect_count'], false);
            $isAnsweredCol = users_pick_column($cols, ['is_answered'], false);
            $lastProgressAtCol = users_pick_column($cols, ['last_answered_at', 'answered_at', 'updated_at', 'created_at'], false);

            $pTotalExpr = $totalAnswerCol
                ? "SUM(COALESCE(`{$totalAnswerCol}`, 0))"
                : ($isAnsweredCol ? "SUM(CASE WHEN `{$isAnsweredCol}` = 1 THEN 1 ELSE 0 END)" : 'COUNT(*)');
            $pCorrectExpr = $correctAnswerCol ? "SUM(COALESCE(`{$correctAnswerCol}`, 0))" : '0';
            $pWrongExpr = $wrongAnswerCol ? "SUM(COALESCE(`{$wrongAnswerCol}`, 0))" : '0';
            $pLastExpr = $lastProgressAtCol ? "MAX(`{$lastProgressAtCol}`)" : 'NULL';

            $sql = "SELECT {$pTotalExpr} AS total_solved, {$pCorrectExpr} AS correct, {$pWrongExpr} AS wrong, {$pLastExpr} AS last_study_at FROM user_progress WHERE `{$uid}` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $progressTotal = (int)($row['total_solved'] ?? 0);
            $progressCorrect = (int)($row['correct'] ?? 0);
            $progressWrong = (int)($row['wrong'] ?? 0);
            $progressLastAt = $row['last_study_at'] ?? null;
        }
    }

    $sessionLastAt = null;
    if (users_has_table($pdo, 'study_sessions')) {
        $rawSessionRows = users_count_rows_by_user($pdo, 'study_sessions', $userId);
        $cols = get_table_columns($pdo, 'study_sessions');
        $uid = users_pick_effective_user_fk_column($pdo, 'study_sessions', $cols, $userId);
        if ($uid) {
            $sessionDateCol = users_pick_column($cols, ['completed_at', 'ended_at', 'updated_at', 'created_at'], false);
            if ($sessionDateCol) {
                $sql = "SELECT MAX(`{$sessionDateCol}`) AS last_study_at FROM study_sessions WHERE `{$uid}` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId]);
                $sessionLastAt = $stmt->fetchColumn() ?: null;
            }
        }
    }

    if ($rawEventRows > 0 && $eventTotal === 0) {
        $eventTotal = $rawEventRows;
    }

    $hasEventData = $rawEventRows > 0;
    $totalSolved = $hasEventData ? $eventTotal : $progressTotal;
    $correct = $hasEventData ? $eventCorrect : $progressCorrect;
    $wrong = $hasEventData ? $eventWrong : $progressWrong;
    if (!$hasEventData && $totalSolved < ($correct + $wrong)) {
        $totalSolved = $correct + $wrong;
    }
    $successRate = $totalSolved > 0 ? round(($correct / $totalSolved) * 100, 2) : 0;
    $lastStudyAt = $hasEventData
        ? users_pick_latest_datetime($eventLastAt, $progressLastAt, $sessionLastAt)
        : users_pick_latest_datetime($progressLastAt, $eventLastAt, $sessionLastAt);

    foreach ($distribution as &$distRow) {
        $src = trim((string)($distRow['source'] ?? ''));
        $distRow['source'] = $src !== '' ? $src : 'study';
        $distRow['total'] = (int)($distRow['total'] ?? 0);
    }
    unset($distRow);

    $qualificationMap = $qualificationMap ?? users_get_qualification_map($pdo);
    $courseMap = $courseMap ?? users_get_name_lookup_map($pdo, ['courses']);
    $topicMap = $topicMap ?? users_get_name_lookup_map($pdo, ['topics']);

    foreach ($recent as &$recentRow) {
        $recentRow['source'] = trim((string)($recentRow['source'] ?? '')) ?: 'study';
        $recentRow['is_correct'] = $recentRow['is_correct'] !== null ? (int)$recentRow['is_correct'] : null;
        $recentRow['qualification_name'] = users_resolve_qualification_name($qualificationMap, $recentRow['qualification_id'] ?? null);
        $recentRow['course_name'] = users_resolve_lookup_name($courseMap, $recentRow['course_id'] ?? null, 'Tanımsız Ders');
        $recentRow['topic_name'] = users_resolve_lookup_name($topicMap, $recentRow['topic_id'] ?? null, 'Tanımsız Konu');
    }
    unset($recentRow);

    users_debug_log('study stats computed', [
        'user_id' => $userId,
        'raw_rows' => [
            'question_attempt_events' => $rawEventRows,
            'user_progress' => $rawProgressRows,
            'study_sessions' => $rawSessionRows,
        ],
        'event_totals' => [
            'total' => $eventTotal,
            'correct' => $eventCorrect,
            'wrong' => $eventWrong,
        ],
        'progress_totals' => [
            'total' => $progressTotal,
            'correct' => $progressCorrect,
            'wrong' => $progressWrong,
        ],
        'final_totals' => [
            'total' => $totalSolved,
            'correct' => $correct,
            'wrong' => $wrong,
            'success_rate' => $successRate,
        ],
        'recent_event_count' => count($recent),
        'source_distribution_count' => count($distribution),
    ]);

    return [
        'total_solved' => $totalSolved,
        'total_correct' => $correct,
        'total_wrong' => $wrong,
        'success_rate' => $successRate,
        'last_study_at' => $lastStudyAt,
        'qualification_distribution' => $breakdown['qualification'],
        'course_distribution' => $breakdown['course'],
        'topic_distribution' => $breakdown['topic'],
        'recent_attempts' => $recent,
        'totals' => [
            'total_solved' => $totalSolved,
            'total_correct' => $correct,
            'total_wrong' => $wrong,
            'correct' => $correct,
            'wrong' => $wrong,
            'success_rate' => $successRate,
            'last_study_at' => $lastStudyAt,
        ],
        'source_distribution' => $distribution,
        'recent_attempts' => $recent,
        'recent_events' => $recent,
        'breakdowns' => $breakdown,
    ];
}

function users_get_user_exam_stats(PDO $pdo, string $userId): array
{
    if (!users_has_table($pdo, 'mock_exam_attempts')) {
        return [
            'summary' => [
                'total' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'abandoned' => 0,
                'last_exam_at' => null,
            ],
            'attempts' => [],
        ];
    }

    $cols = get_table_columns($pdo, 'mock_exam_attempts');
    $uid = users_pick_effective_user_fk_column($pdo, 'mock_exam_attempts', $cols, $userId);
    if (!$uid) {
        return [
            'summary' => [
                'total' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'abandoned' => 0,
                'last_exam_at' => null,
            ],
            'attempts' => [],
        ];
    }

    $statusCol = users_pick_column($cols, ['status', 'attempt_status', 'state'], false);
    $startedCol = users_pick_column($cols, ['started_at', 'created_at'], false);
    $submittedCol = users_pick_column($cols, ['submitted_at'], false);
    $abandonedAtCol = users_pick_column($cols, ['abandoned_at'], false);
    $requestedQuestionCountCol = users_pick_column($cols, ['requested_question_count'], false);
    $actualQuestionCountCol = users_pick_column($cols, ['actual_question_count'], false);
    $elapsedSecondsCol = users_pick_column($cols, ['elapsed_seconds'], false);
    $qualCol = users_pick_column($cols, ['qualification_id'], false);
    $modeCol = users_pick_column($cols, ['mode'], false);
    $poolTypeCol = users_pick_column($cols, ['pool_type'], false);
    $warningCol = users_pick_column($cols, ['warning_message'], false);
    $idCol = users_pick_column($cols, ['id'], false);
    $correctCountCol = users_pick_column($cols, ['correct_count'], false);
    $wrongCountCol = users_pick_column($cols, ['wrong_count'], false);
    $blankCountCol = users_pick_column($cols, ['blank_count'], false);
    $successRateCol = users_pick_column($cols, ['success_rate'], false);

    $summarySelect = [];
    $summarySelect[] = $statusCol ? "`{$statusCol}` AS status" : 'NULL AS status';
    $summarySelect[] = $startedCol ? "`{$startedCol}` AS started_at" : 'NULL AS started_at';
    $summarySelect[] = $submittedCol ? "`{$submittedCol}` AS submitted_at" : 'NULL AS submitted_at';
    $summarySelect[] = $abandonedAtCol ? "`{$abandonedAtCol}` AS abandoned_at" : 'NULL AS abandoned_at';
    $summarySql = 'SELECT ' . implode(', ', $summarySelect) . " FROM mock_exam_attempts WHERE `{$uid}` = ?";
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute([$userId]);
    $summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $select = [];
    $select[] = $idCol ? users_q($idCol) . ' AS id' : 'NULL AS id';
    $select[] = $statusCol ? "`{$statusCol}` AS status" : 'NULL AS status';
    $select[] = $startedCol ? "`{$startedCol}` AS started_at" : 'NULL AS started_at';
    $select[] = $submittedCol ? "`{$submittedCol}` AS submitted_at" : 'NULL AS submitted_at';
    $select[] = $abandonedAtCol ? "`{$abandonedAtCol}` AS abandoned_at" : 'NULL AS abandoned_at';
    $select[] = $requestedQuestionCountCol ? "`{$requestedQuestionCountCol}` AS requested_question_count" : 'NULL AS requested_question_count';
    $select[] = $actualQuestionCountCol ? "`{$actualQuestionCountCol}` AS actual_question_count" : 'NULL AS actual_question_count';
    $select[] = $elapsedSecondsCol ? "`{$elapsedSecondsCol}` AS elapsed_seconds" : 'NULL AS elapsed_seconds';
    $select[] = $qualCol ? "`{$qualCol}` AS qualification_id" : 'NULL AS qualification_id';
    $select[] = $modeCol ? "`{$modeCol}` AS mode" : 'NULL AS mode';
    $select[] = $poolTypeCol ? "`{$poolTypeCol}` AS pool_type" : 'NULL AS pool_type';
    $select[] = $warningCol ? "`{$warningCol}` AS warning_message" : 'NULL AS warning_message';
    $select[] = $correctCountCol ? users_q($correctCountCol) . ' AS correct_count' : 'NULL AS correct_count';
    $select[] = $wrongCountCol ? users_q($wrongCountCol) . ' AS wrong_count' : 'NULL AS wrong_count';
    $select[] = $blankCountCol ? users_q($blankCountCol) . ' AS blank_count' : 'NULL AS blank_count';
    $select[] = $successRateCol ? users_q($successRateCol) . ' AS success_rate' : 'NULL AS success_rate';
    $orderCol = $submittedCol ?: ($abandonedAtCol ?: ($startedCol ?: $uid));
    $listSql = 'SELECT ' . implode(', ', $select) . " FROM mock_exam_attempts WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 100";
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute([$userId]);
    $attempts = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summary = [
        'total' => 0,
        'completed' => 0,
        'in_progress' => 0,
        'abandoned' => 0,
        'last_exam_at' => null,
    ];
    $qMap = users_get_qualification_map($pdo);
    foreach ($summaryRows as $summaryRow) {
        $normalizedStatus = users_exam_status_normalize(
            $summaryRow['status'] ?? '',
            $summaryRow['submitted_at'] ?? null,
            $summaryRow['abandoned_at'] ?? null,
            $summaryRow['started_at'] ?? null
        );
        $summary['total']++;
        if ($normalizedStatus === 'completed') {
            $summary['completed']++;
        } elseif ($normalizedStatus === 'in_progress') {
            $summary['in_progress']++;
        } elseif ($normalizedStatus === 'abandoned') {
            $summary['abandoned']++;
        }

        $summary['last_exam_at'] = users_pick_latest_datetime(
            $summary['last_exam_at'],
            $summaryRow['submitted_at'] ?? null,
            $summaryRow['abandoned_at'] ?? null,
            $summaryRow['started_at'] ?? null
        );
    }

    foreach ($attempts as &$attempt) {
        $rawStatus = (string)($attempt['status'] ?? '');
        $attempt['raw_status'] = $rawStatus;
        $attempt['status'] = users_exam_status_normalize(
            $attempt['status'] ?? '',
            $attempt['submitted_at'] ?? null,
            $attempt['abandoned_at'] ?? null,
            $attempt['started_at'] ?? null
        );
        $attempt['status_label'] = users_exam_status_label((string)$attempt['status']);
        $qid = (string)($attempt['qualification_id'] ?? '');
        $attempt['qualification_name'] = users_resolve_qualification_name($qMap, $qid);
        $attempt['requested_question_count'] = $attempt['requested_question_count'] !== null ? (int)$attempt['requested_question_count'] : null;
        $attempt['actual_question_count'] = $attempt['actual_question_count'] !== null ? (int)$attempt['actual_question_count'] : null;
        $attempt['elapsed_seconds'] = $attempt['elapsed_seconds'] !== null ? (int)$attempt['elapsed_seconds'] : null;
        $attempt['correct_count'] = $attempt['correct_count'] !== null ? (int)$attempt['correct_count'] : null;
        $attempt['wrong_count'] = $attempt['wrong_count'] !== null ? (int)$attempt['wrong_count'] : null;
        $attempt['blank_count'] = $attempt['blank_count'] !== null ? (int)$attempt['blank_count'] : null;
        $attempt['success_rate'] = $attempt['success_rate'] !== null ? (float)$attempt['success_rate'] : null;
        $attempt['warning_message'] = trim((string)($attempt['warning_message'] ?? ''));
    }
    unset($attempt);

    if (empty($summaryRows) && !empty($attempts)) {
        foreach ($attempts as $attempt) {
            $normalizedStatus = (string)($attempt['status'] ?? 'unknown');
            $summary['total']++;
            if ($normalizedStatus === 'completed') {
                $summary['completed']++;
            } elseif ($normalizedStatus === 'in_progress') {
                $summary['in_progress']++;
            } elseif ($normalizedStatus === 'abandoned') {
                $summary['abandoned']++;
            }

            $summary['last_exam_at'] = users_pick_latest_datetime(
                $summary['last_exam_at'],
                $attempt['submitted_at'] ?? null,
                $attempt['abandoned_at'] ?? null,
                $attempt['started_at'] ?? null
            );
        }
    }

    $rawRows = users_count_rows_by_user($pdo, 'mock_exam_attempts', $userId);
    users_debug_log('exam stats computed', [
        'user_id' => $userId,
        'raw_rows' => $rawRows,
        'summary_total' => (int)($summary['total'] ?? 0),
        'attempts_count' => count($attempts),
        'summary' => [
            'completed' => (int)($summary['completed'] ?? 0),
            'in_progress' => (int)($summary['in_progress'] ?? 0),
            'abandoned' => (int)($summary['abandoned'] ?? 0),
            'last_exam_at' => $summary['last_exam_at'] ?? null,
        ],
    ]);

    return [
        'total_exams' => (int)($summary['total'] ?? 0),
        'completed_exams' => (int)($summary['completed'] ?? 0),
        'in_progress_exams' => (int)($summary['in_progress'] ?? 0),
        'abandoned_exams' => (int)($summary['abandoned'] ?? 0),
        'last_exam_at' => $summary['last_exam_at'] ?? null,
        'exam_rows' => $attempts,
        'summary' => [
            'total' => (int)($summary['total'] ?? 0),
            'total_exams' => (int)($summary['total'] ?? 0),
            'completed' => (int)($summary['completed'] ?? 0),
            'completed_exams' => (int)($summary['completed'] ?? 0),
            'in_progress' => (int)($summary['in_progress'] ?? 0),
            'in_progress_exams' => (int)($summary['in_progress'] ?? 0),
            'abandoned' => (int)($summary['abandoned'] ?? 0),
            'abandoned_exams' => (int)($summary['abandoned'] ?? 0),
            'last_exam_at' => $summary['last_exam_at'] ?? null,
        ],
        'attempts' => $attempts,
    ];
}

function users_get_user_usage_limits(PDO $pdo, string $userId): array
{
    if (!users_has_table($pdo, 'user_daily_usage_counters')) {
        return [
            'summary_by_feature' => [],
            'usage_rows' => [],
        ];
    }

    $cols = get_table_columns($pdo, 'user_daily_usage_counters');
    $uid = users_pick_effective_user_fk_column($pdo, 'user_daily_usage_counters', $cols, $userId);
    if (!$uid) {
        return ['summary_by_feature' => [], 'usage_rows' => []];
    }

    $featureCol = users_pick_column($cols, ['feature_key'], false);
    $usedCol = users_pick_column($cols, ['used_count'], false);
    $dateCol = users_pick_column($cols, ['usage_date_tr', 'usage_date'], false);
    $qualCol = users_pick_column($cols, ['qualification_id'], false);
    $dailyLimitCol = users_pick_column($cols, ['daily_limit'], false);
    $createdCol = users_pick_column($cols, ['created_at'], false);
    $updatedCol = users_pick_column($cols, ['updated_at'], false);

    $select = [];
    $select[] = $dateCol ? "`{$dateCol}` AS usage_date_tr" : 'NULL AS usage_date_tr';
    $select[] = $featureCol ? "`{$featureCol}` AS feature_key" : 'NULL AS feature_key';
    $select[] = $usedCol ? "`{$usedCol}` AS used_count" : '0 AS used_count';
    $select[] = $dailyLimitCol ? "`{$dailyLimitCol}` AS daily_limit" : 'NULL AS daily_limit';
    $select[] = $qualCol ? "`{$qualCol}` AS qualification_id" : 'NULL AS qualification_id';
    $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
    $select[] = $updatedCol ? "`{$updatedCol}` AS updated_at" : 'NULL AS updated_at';

    $orderCol = $updatedCol ?: ($createdCol ?: ($dateCol ?: $uid));
    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM user_daily_usage_counters WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $qMap = users_get_qualification_map($pdo);

    $summary = [];
    foreach ($rows as &$row) {
        $key = trim((string)($row['feature_key'] ?? ''));
        if ($key === '') {
            $key = 'unknown';
        }
        if (!isset($summary[$key])) {
            $summary[$key] = ['used_count' => 0, 'total_used' => 0, 'daily_limit' => null];
        }
        $summary[$key]['used_count'] += (int)($row['used_count'] ?? 0);
        $summary[$key]['total_used'] += (int)($row['used_count'] ?? 0);

        $detectedDailyLimit = $row['daily_limit'] !== null ? (int)$row['daily_limit'] : users_feature_daily_limit($key);
        if ($summary[$key]['daily_limit'] === null && $detectedDailyLimit !== null) {
            $summary[$key]['daily_limit'] = $detectedDailyLimit;
        }

        $qid = (string)($row['qualification_id'] ?? '');
        $row['qualification_name'] = users_resolve_qualification_name($qMap, $qid);
        $row['usage_date_tr'] = $row['usage_date_tr'] ?? null;
        $row['daily_limit'] = $detectedDailyLimit;
        $row['used_count'] = (int)($row['used_count'] ?? 0);
    }
    unset($row);

    users_debug_log('usage limits computed', [
        'user_id' => $userId,
        'raw_rows' => users_count_rows_by_user($pdo, 'user_daily_usage_counters', $userId),
        'rows_count' => count($rows),
        'summary_keys' => array_keys($summary),
    ]);

    return [
        'summary_by_feature' => $summary,
        'usage_rows' => $rows,
        'summary' => $summary,
        'rows' => $rows,
    ];
}

function users_get_user_devices(PDO $pdo, string $userId): array
{
    $apiTokens = [];
    if (users_has_table($pdo, 'api_tokens')) {
        $cols = get_table_columns($pdo, 'api_tokens');
        $uid = users_pick_effective_user_fk_column($pdo, 'api_tokens', $cols, $userId);
        if ($uid) {
            $nameCol = users_pick_column($cols, ['name'], false);
            $createdCol = users_pick_column($cols, ['created_at'], false);
            $lastUsedCol = users_pick_column($cols, ['last_used_at'], false);
            $expiresCol = users_pick_column($cols, ['expires_at'], false);
            $revokedCol = users_pick_column($cols, ['revoked_at'], false);
            $tokenHashCol = users_pick_column($cols, ['token_hash'], false);

            $select = [];
            $select[] = users_pick_column($cols, ['id'], false) ? '`' . users_pick_column($cols, ['id'], false) . '` AS id' : 'NULL AS id';
            $select[] = $nameCol ? "`{$nameCol}` AS name" : 'NULL AS name';
            $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
            $select[] = $lastUsedCol ? "`{$lastUsedCol}` AS last_used_at" : 'NULL AS last_used_at';
            $select[] = $expiresCol ? "`{$expiresCol}` AS expires_at" : 'NULL AS expires_at';
            $select[] = $revokedCol ? "`{$revokedCol}` AS revoked_at" : 'NULL AS revoked_at';
            $select[] = $tokenHashCol ? "`{$tokenHashCol}` AS token_hash" : 'NULL AS token_hash';
            $orderCol = $lastUsedCol ?: ($createdCol ?: $uid);
            $sql = 'SELECT ' . implode(', ', $select)
                . " FROM api_tokens WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $apiTokens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($apiTokens as &$tokenRow) {
                $tokenRow['name'] = trim((string)($tokenRow['name'] ?? ''));
                $tokenHash = trim((string)($tokenRow['token_hash'] ?? ''));
                $tokenRow['token_hash_preview'] = $tokenHash !== ''
                    ? (mb_strlen($tokenHash) > 16 ? (mb_substr($tokenHash, 0, 8) . '...' . mb_substr($tokenHash, -8)) : $tokenHash)
                    : null;
                unset($tokenRow['token_hash']);
            }
            unset($tokenRow);
        }
    }

    $pushTokens = [];
    if (users_has_table($pdo, 'user_push_tokens')) {
        $cols = get_table_columns($pdo, 'user_push_tokens');
        $uid = users_pick_effective_user_fk_column($pdo, 'user_push_tokens', $cols, $userId);
        if ($uid) {
            $tokenCol = users_pick_column($cols, ['fcm_token', 'token'], false);
            $platformCol = users_pick_column($cols, ['platform'], false);
            $installationIdCol = users_pick_column($cols, ['installation_id'], false);
            $deviceNameCol = users_pick_column($cols, ['device_name', 'device_id'], false);
            $appVersionCol = users_pick_column($cols, ['app_version'], false);
            $permissionStatusCol = users_pick_column($cols, ['permission_status'], false);
            $isActiveCol = users_pick_column($cols, ['is_active'], false);
            $lastSeenCol = users_pick_column($cols, ['last_seen_at', 'last_used_at'], false);
            $createdCol = users_pick_column($cols, ['created_at'], false);
            $updatedCol = users_pick_column($cols, ['updated_at'], false);

            $select = [];
            $select[] = users_pick_column($cols, ['id'], false) ? '`' . users_pick_column($cols, ['id'], false) . '` AS id' : 'NULL AS id';
            $select[] = $tokenCol ? "`{$tokenCol}` AS fcm_token" : 'NULL AS fcm_token';
            $select[] = $platformCol ? "`{$platformCol}` AS platform" : 'NULL AS platform';
            $select[] = $installationIdCol ? "`{$installationIdCol}` AS installation_id" : 'NULL AS installation_id';
            $select[] = $deviceNameCol ? "`{$deviceNameCol}` AS device_name" : 'NULL AS device_name';
            $select[] = $appVersionCol ? "`{$appVersionCol}` AS app_version" : 'NULL AS app_version';
            $select[] = $permissionStatusCol ? "`{$permissionStatusCol}` AS permission_status" : 'NULL AS permission_status';
            $select[] = $isActiveCol ? "`{$isActiveCol}` AS is_active" : '1 AS is_active';
            $select[] = $lastSeenCol ? "`{$lastSeenCol}` AS last_seen_at" : 'NULL AS last_seen_at';
            $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
            $select[] = $updatedCol ? "`{$updatedCol}` AS updated_at" : 'NULL AS updated_at';

            $orderCol = $lastSeenCol ?: ($updatedCol ?: ($createdCol ?: $uid));
            $sql = 'SELECT ' . implode(', ', $select)
                . " FROM user_push_tokens WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $pushTokens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($pushTokens as &$pushRow) {
                $pushRow['is_active'] = (int)($pushRow['is_active'] ?? 0);
                $pushRow['fcm_token_preview'] = users_push_token_preview($pushRow['fcm_token'] ?? null);
            }
            unset($pushRow);
        }
    }

    users_debug_log('device stats computed', [
        'user_id' => $userId,
        'raw_rows' => [
            'api_tokens' => users_count_rows_by_user($pdo, 'api_tokens', $userId),
            'user_push_tokens' => users_count_rows_by_user($pdo, 'user_push_tokens', $userId),
        ],
        'payload_rows' => [
            'api_tokens' => count($apiTokens),
            'push_tokens' => count($pushTokens),
        ],
    ]);

    return [
        'api_tokens' => $apiTokens,
        'push_tokens' => $pushTokens,
    ];
}

function users_get_user_lifecycle(PDO $pdo, string $userId): array
{
    $schema = user_lifecycle_schema($pdo);
    if (!$schema) {
        return [];
    }

    $orderCol = $schema['created_at'] ?: ($schema['id'] ?: $schema['user_id']);
    $select = [
        ($schema['id'] ? "`{$schema['id']}`" : 'NULL') . ' AS id',
        "`{$schema['user_id']}` AS user_id",
        ($schema['event_type'] ? "`{$schema['event_type']}`" : 'NULL') . ' AS event_type',
        ($schema['title'] ? "`{$schema['title']}`" : 'NULL') . ' AS title',
        ($schema['old_value'] ? "`{$schema['old_value']}`" : 'NULL') . ' AS old_value',
        ($schema['new_value'] ? "`{$schema['new_value']}`" : 'NULL') . ' AS new_value',
        ($schema['source'] ? "`{$schema['source']}`" : 'NULL') . ' AS source',
        ($schema['meta_json'] ? "`{$schema['meta_json']}`" : 'NULL') . ' AS meta_json',
        ($schema['created_at'] ? "`{$schema['created_at']}`" : 'NULL') . ' AS created_at',
    ];
    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM `{$schema['table']}` WHERE `{$schema['user_id']}` = ? ORDER BY `{$orderCol}` DESC LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $decoded = null;
        $meta = $row['meta_json'] ?? null;
        if (is_string($meta) && trim($meta) !== '') {
            $parsed = json_decode($meta, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }
        $row['meta'] = $decoded;
    }
    unset($row);

    return $rows;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $schema = users_schema($pdo);
    $currentUserId = (string)($authUser['user_id'] ?? ($_SESSION['user_id'] ?? ''));

    switch ($action) {
        case 'list': {
            $search = trim((string)($_GET['search'] ?? ''));
            $role = trim((string)($_GET['role'] ?? 'all'));

            $legacyStatus = trim((string)($_GET['status'] ?? 'all'));
            $userStatus = trim((string)($_GET['user_status'] ?? 'all'));
            if ($userStatus === '' || $userStatus === 'all') {
                if (in_array($legacyStatus, ['guest', 'registered', 'registered_free', 'premium_active'], true)) {
                    $userStatus = $legacyStatus;
                } else {
                    $userStatus = 'all';
                }
            }

            $emailVerifiedFilter = trim((string)($_GET['email_verified'] ?? 'all'));
            $onboardingFilter = trim((string)($_GET['onboarding'] ?? 'all'));
            $qualificationFilter = trim((string)($_GET['current_qualification_id'] ?? ''));
            $createdFrom = trim((string)($_GET['created_from'] ?? ''));
            $createdTo = trim((string)($_GET['created_to'] ?? ''));
            $lastSignInFrom = trim((string)($_GET['last_sign_in_from'] ?? ''));
            $lastSignInTo = trim((string)($_GET['last_sign_in_to'] ?? ''));

            $where = ['1=1'];
            $params = [];

            if ($search !== '') {
                $fullNameSql = $schema['full_name'] ? "`{$schema['full_name']}`" : "''";
                $where[] = "({$fullNameSql} LIKE ? OR `{$schema['email']}` LIKE ? OR `{$schema['id']}` LIKE ?)";
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }

            if ($role === 'admin' && $schema['is_admin']) {
                $where[] = "`{$schema['is_admin']}` = 1";
            } elseif ($role === 'user' && $schema['is_admin']) {
                $where[] = "`{$schema['is_admin']}` = 0";
            }

            if ($schema['is_deleted']) {
                if ($legacyStatus === 'passive') {
                    $where[] = "`{$schema['is_deleted']}` = 1";
                } else {
                    $where[] = "`{$schema['is_deleted']}` = 0";
                }
            }

            if ($emailVerifiedFilter === 'yes' && $schema['email_verified']) {
                $where[] = "`{$schema['email_verified']}` = 1";
            } elseif ($emailVerifiedFilter === 'no' && $schema['email_verified']) {
                $where[] = "`{$schema['email_verified']}` = 0";
            }

            if ($onboardingFilter === 'yes' && $schema['onboarding_completed']) {
                $where[] = "`{$schema['onboarding_completed']}` = 1";
            } elseif ($onboardingFilter === 'no' && $schema['onboarding_completed']) {
                $where[] = "`{$schema['onboarding_completed']}` = 0";
            }

            if ($qualificationFilter !== '' && $schema['current_qualification_id']) {
                $where[] = "`{$schema['current_qualification_id']}` = ?";
                $params[] = $qualificationFilter;
            }

            if ($createdFrom !== '' && $schema['created_at']) {
                $where[] = "`{$schema['created_at']}` >= ?";
                $params[] = $createdFrom . ' 00:00:00';
            }
            if ($createdTo !== '' && $schema['created_at']) {
                $where[] = "`{$schema['created_at']}` <= ?";
                $params[] = $createdTo . ' 23:59:59';
            }
            if ($lastSignInFrom !== '' && $schema['last_sign_in_at']) {
                $where[] = "`{$schema['last_sign_in_at']}` >= ?";
                $params[] = $lastSignInFrom . ' 00:00:00';
            }
            if ($lastSignInTo !== '' && $schema['last_sign_in_at']) {
                $where[] = "`{$schema['last_sign_in_at']}` <= ?";
                $params[] = $lastSignInTo . ' 23:59:59';
            }

            $orderCol = $schema['created_at'] ?: $schema['id'];
            $sql = 'SELECT ' . users_select_clause($schema)
                . " FROM `{$schema['table']}`"
                . ' WHERE ' . implode(' AND ', $where)
                . " ORDER BY `{$orderCol}` DESC LIMIT 700";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $userIds = array_map(static fn($u) => (string)($u['id'] ?? ''), $users);
            $subMap = users_get_subscription_map($pdo, $userIds);
            $qualificationMap = users_get_qualification_map($pdo);

            $normalized = [];
            foreach ($users as $u) {
                $id = (string)($u['id'] ?? '');
                $sub = $subMap[$id] ?? [];
                $isGuest = users_detect_guest($u, $schema);
                $isPremium = users_is_premium_active($sub);
                $computedStatus = $isGuest ? 'guest' : ($isPremium ? 'premium_active' : 'registered_free');

                if ($userStatus !== 'all') {
                    if ($userStatus === 'registered') {
                        if ($isGuest) {
                            continue;
                        }
                    } elseif ($computedStatus !== $userStatus) {
                        continue;
                    }
                }

                $qualificationId = (string)($u['current_qualification_id'] ?? '');

                $normalized[] = [
                    'id' => $id,
                    'full_name' => (string)($u['full_name'] ?? ''),
                    'email' => (string)($u['email'] ?? ''),
                    'is_guest' => $isGuest ? 1 : 0,
                    'is_admin' => users_fmt_bool($u['is_admin'] ?? 0),
                    'is_deleted' => users_fmt_bool($u['is_deleted'] ?? 0),
                    'user_type' => $isGuest ? 'guest' : 'registered',
                    'status' => $computedStatus,
                    'email_verified' => users_fmt_bool($u['email_verified'] ?? 0),
                    'onboarding_completed' => users_fmt_bool($u['onboarding_completed'] ?? 0),
                    'current_qualification_id' => $qualificationId !== '' ? $qualificationId : null,
                    'current_qualification_name' => $qualificationId !== '' ? ($qualificationMap[$qualificationId] ?? '-') : '-',
                    'created_at' => $u['created_at'] ?? null,
                    'updated_at' => $u['updated_at'] ?? null,
                    'last_sign_in_at' => $u['last_sign_in_at'] ?? null,
                    'premium_expires_at' => $sub['expires_at'] ?? null,
                    'premium_is_active' => $isPremium ? 1 : 0,
                    'premium_plan_code' => $sub['plan_code'] ?? null,
                ];
            }

            $summary = [
                'total_users' => 0,
                'guest' => 0,
                'registered_free' => 0,
                'premium_active' => 0,
                'new_last_7_days' => 0,
            ];

            foreach ($normalized as $u) {
                $summary['total_users']++;
                if ($u['status'] === 'guest') {
                    $summary['guest']++;
                } elseif ($u['status'] === 'premium_active') {
                    $summary['premium_active']++;
                } else {
                    $summary['registered_free']++;
                }

                if (!empty($u['created_at'])) {
                    $ts = strtotime((string)$u['created_at']);
                    if ($ts !== false && $ts >= strtotime('-7 days')) {
                        $summary['new_last_7_days']++;
                    }
                }
            }

            users_response(true, '', [
                'users' => $normalized,
                'current_user_id' => $currentUserId,
                'summary' => $summary,
                'qualifications' => array_map(static function ($id) use ($qualificationMap) {
                    return ['id' => $id, 'name' => $qualificationMap[$id]];
                }, array_keys($qualificationMap)),
            ]);
            break;
        }

        case 'get': {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }

            $user = users_find_by_id($pdo, $schema, $id);
            if (!$user) {
                users_response(false, 'Kullanıcı bulunamadı.', [], 404);
            }

            users_response(true, '', ['user' => [
                'id' => (string)$user['id'],
                'full_name' => (string)($user['full_name'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'is_admin' => users_fmt_bool($user['is_admin'] ?? 0),
                'is_deleted' => users_fmt_bool($user['is_deleted'] ?? 0),
            ]]);
            break;
        }

        case 'get_user_detail': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }

            $user = users_find_by_id($pdo, $schema, $id);
            if (!$user) {
                users_response(false, 'Kullanıcı bulunamadı.', [], 404);
            }

            $sub = users_get_subscription_map($pdo, [$id]);
            $subData = $sub[$id] ?? [];
            $isGuest = users_detect_guest($user, $schema);
            $isPremium = users_is_premium_active($subData);
            $status = $isGuest ? 'guest' : ($isPremium ? 'premium_active' : 'registered_free');

            $qualificationMap = users_get_qualification_map($pdo);
            $currentQ = trim((string)($user['current_qualification_id'] ?? ''));
            $targetQ = trim((string)($user['target_qualification_id'] ?? ''));
            $currentQualificationName = users_resolve_qualification_label($qualificationMap, $currentQ);
            $targetQualificationName = users_resolve_qualification_label($qualificationMap, $targetQ);

            users_debug_log('qualification resolution snapshot', [
                'user_id' => $id,
                'current_qualification_id' => $currentQ,
                'target_qualification_id' => $targetQ,
                'current_qualification_name' => $currentQualificationName,
                'target_qualification_name' => $targetQualificationName,
                'qualification_map_size' => count($qualificationMap),
            ]);

            $studyStats = users_get_user_study_stats($pdo, $id);
            $examStats = users_get_user_exam_stats($pdo, $id);
            $rawCounts = users_log_raw_user_counts($pdo, $id);
            users_debug_log('detail endpoint consistency snapshot', [
                'user_id' => $id,
                'kpi_source' => [
                    'study_total' => (int)($studyStats['totals']['total_solved'] ?? 0),
                    'exam_total' => (int)($examStats['summary']['total'] ?? 0),
                ],
                'raw_counts' => $rawCounts,
            ]);

            users_response(true, '', [
                'user' => [
                    'id' => (string)($user['id'] ?? ''),
                    'full_name' => (string)($user['full_name'] ?? ''),
                    'email' => (string)($user['email'] ?? ''),
                    'pending_email' => $user['pending_email'] ?? null,
                    'is_guest' => $isGuest ? 1 : 0,
                    'is_admin' => users_fmt_bool($user['is_admin'] ?? 0),
                    'email_verified' => users_fmt_bool($user['email_verified'] ?? 0),
                    'email_verified_at' => $user['email_verified_at'] ?? null,
                    'onboarding_completed' => users_fmt_bool($user['onboarding_completed'] ?? 0),
                    'current_qualification_id' => $currentQ !== '' ? $currentQ : null,
                    'current_qualification_name' => $currentQualificationName,
                    'target_qualification_id' => $targetQ !== '' ? $targetQ : null,
                    'target_qualification_name' => $targetQualificationName,
                    'created_at' => $user['created_at'] ?? null,
                    'updated_at' => $user['updated_at'] ?? null,
                    'last_sign_in_at' => $user['last_sign_in_at'] ?? null,
                    'is_deleted' => users_fmt_bool($user['is_deleted'] ?? 0),
                    'status' => $status,
                    'premium' => [
                        'is_active' => $isPremium ? 1 : 0,
                        'is_pro' => users_fmt_bool($subData['is_pro'] ?? 0),
                        'plan_code' => $subData['plan_code'] ?? null,
                        'provider' => $subData['provider'] ?? null,
                        'entitlement_id' => $subData['entitlement_id'] ?? null,
                        'rc_app_user_id' => $subData['rc_app_user_id'] ?? null,
                        'expires_at' => $subData['expires_at'] ?? null,
                        'last_synced_at' => $subData['last_synced_at'] ?? null,
                        'created_at' => $subData['created_at'] ?? null,
                        'updated_at' => $subData['updated_at'] ?? null,
                    ],
                    'premium_summary' => [
                        'status' => $isPremium ? 'active' : 'inactive',
                        'expires_at' => $subData['expires_at'] ?? null,
                    ],
                ],
                'kpi' => [
                    'total_solved' => (int)($studyStats['total_solved'] ?? 0),
                    'total_correct' => (int)($studyStats['total_correct'] ?? 0),
                    'total_wrong' => (int)($studyStats['total_wrong'] ?? 0),
                    'correct' => (int)($studyStats['total_correct'] ?? 0),
                    'wrong' => (int)($studyStats['total_wrong'] ?? 0),
                    'success_rate' => (float)($studyStats['success_rate'] ?? 0),
                    'total_exams' => (int)($examStats['total_exams'] ?? 0),
                    'completed_exams' => (int)($examStats['completed_exams'] ?? 0),
                    'premium_active' => $isPremium ? 1 : 0,
                ],
                'top_summary' => users_build_top_summary($studyStats, $examStats),
                'admin_notes' => users_get_admin_notes($pdo, $id),
            ]);
            break;
        }

        case 'get_user_lifecycle': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }
            users_response(true, '', ['items' => users_get_user_lifecycle($pdo, $id)]);
            break;
        }

        case 'get_user_subscription': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }
            $sub = users_get_subscription_map($pdo, [$id]);
            $row = $sub[$id] ?? [];
            $row['premium_active'] = users_is_premium_active($row) ? 1 : 0;
            users_response(true, '', ['subscription' => $row]);
            break;
        }

        case 'get_user_study_stats': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }
            users_response(true, '', users_get_user_study_stats($pdo, $id));
            break;
        }

        case 'get_user_exam_stats': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }
            users_response(true, '', users_get_user_exam_stats($pdo, $id));
            break;
        }

        case 'get_user_usage_limits': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }
            users_response(true, '', users_get_user_usage_limits($pdo, $id));
            break;
        }

        case 'get_user_devices': {
            $id = trim((string)($_GET['id'] ?? $_GET['user_id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }
            users_response(true, '', users_get_user_devices($pdo, $id));
            break;
        }

        case 'add_note': {
            $userId = trim((string)($_POST['user_id'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            if ($userId === '') {
                users_response(false, 'Kullanıcı ID gerekli.', [], 422, ['user_id' => 'required']);
            }
            if ($note === '') {
                users_response(false, 'Not boş olamaz.', [], 422, ['note' => 'required']);
            }

            $schemaN = users_admin_notes_schema($pdo);
            if (!$schemaN || !$schemaN['note']) {
                users_response(false, 'Not altyapısı bulunamadı.', [], 400);
            }

            $columns = ["`{$schemaN['user_id']}`", "`{$schemaN['note']}`"];
            $holders = ['?', '?'];
            $values = [$userId, $note];

            if ($schemaN['id']) {
                $columns[] = "`{$schemaN['id']}`";
                $holders[] = '?';
                $values[] = generate_uuid();
            }
            if ($schemaN['admin_user_id']) {
                $columns[] = "`{$schemaN['admin_user_id']}`";
                $holders[] = '?';
                $values[] = (string)($authUser['user_id'] ?? '');
            }
            if ($schemaN['admin_name']) {
                $columns[] = "`{$schemaN['admin_name']}`";
                $holders[] = '?';
                $values[] = (string)($authUser['email'] ?? 'Admin');
            }
            if ($schemaN['is_deleted']) {
                $columns[] = "`{$schemaN['is_deleted']}`";
                $holders[] = '0';
            }
            if ($schemaN['created_at']) {
                $columns[] = "`{$schemaN['created_at']}`";
                $holders[] = 'NOW()';
            }
            if ($schemaN['updated_at']) {
                $columns[] = "`{$schemaN['updated_at']}`";
                $holders[] = 'NOW()';
            }

            $sql = 'INSERT INTO `' . $schemaN['table'] . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            user_lifecycle_log_event(
                $pdo,
                $userId,
                'admin_note_added',
                'Admin notu eklendi',
                'admin_panel',
                null,
                null,
                ['admin_id' => (string)($authUser['user_id'] ?? ''), 'admin_email' => (string)($authUser['email'] ?? ''), 'note_preview' => mb_substr($note, 0, 120)]
            );

            users_response(true, 'Not eklendi.', ['notes' => users_get_admin_notes($pdo, $userId)]);
            break;
        }

        case 'update_note': {
            $noteId = trim((string)($_POST['note_id'] ?? $_POST['id'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            if ($noteId === '') {
                users_response(false, 'Not ID gerekli.', [], 422, ['note_id' => 'required']);
            }
            if ($note === '') {
                users_response(false, 'Not boş olamaz.', [], 422, ['note' => 'required']);
            }

            $schemaN = users_admin_notes_schema($pdo);
            if (!$schemaN || !$schemaN['note'] || !$schemaN['id']) {
                users_response(false, 'Not güncelleme desteklenmiyor.', [], 400);
            }

            $set = ["`{$schemaN['note']}` = ?"];
            $values = [$note];
            if ($schemaN['updated_at']) {
                $set[] = "`{$schemaN['updated_at']}` = NOW()";
            }

            $sql = 'UPDATE `' . $schemaN['table'] . '` SET ' . implode(', ', $set)
                . " WHERE `{$schemaN['id']}` = ?";
            $values[] = $noteId;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            $sqlUser = "SELECT `{$schemaN['user_id']}` FROM `{$schemaN['table']}` WHERE `{$schemaN['id']}` = ? LIMIT 1";
            $stUser = $pdo->prepare($sqlUser);
            $stUser->execute([$noteId]);
            $userId = (string)($stUser->fetchColumn() ?? '');

            users_response(true, 'Not güncellendi.', ['notes' => $userId !== '' ? users_get_admin_notes($pdo, $userId) : []]);
            break;
        }

        case 'delete_note': {
            $noteId = trim((string)($_POST['note_id'] ?? $_POST['id'] ?? ''));
            if ($noteId === '') {
                users_response(false, 'Not ID gerekli.', [], 422, ['note_id' => 'required']);
            }

            $schemaN = users_admin_notes_schema($pdo);
            if (!$schemaN || !$schemaN['id']) {
                users_response(false, 'Not silme desteklenmiyor.', [], 400);
            }

            $sqlUser = "SELECT `{$schemaN['user_id']}` FROM `{$schemaN['table']}` WHERE `{$schemaN['id']}` = ? LIMIT 1";
            $stUser = $pdo->prepare($sqlUser);
            $stUser->execute([$noteId]);
            $userId = (string)($stUser->fetchColumn() ?? '');

            if ($schemaN['is_deleted']) {
                $set = ["`{$schemaN['is_deleted']}` = 1"];
                if ($schemaN['updated_at']) {
                    $set[] = "`{$schemaN['updated_at']}` = NOW()";
                }
                $sql = 'UPDATE `' . $schemaN['table'] . '` SET ' . implode(', ', $set)
                    . " WHERE `{$schemaN['id']}` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$noteId]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM `' . $schemaN['table'] . "` WHERE `{$schemaN['id']}` = ?");
                $stmt->execute([$noteId]);
            }

            users_response(true, 'Not silindi.', ['notes' => $userId !== '' ? users_get_admin_notes($pdo, $userId) : []]);
            break;
        }

        case 'add': {
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            $isAdmin = users_bool($_POST['is_admin'] ?? 0);

            if ($fullName === '') users_response(false, 'Ad Soyad zorunludur.', [], 422, ['full_name' => 'required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) users_response(false, 'Geçerli bir email giriniz.', [], 422, ['email' => 'invalid']);
            if (mb_strlen($password) < 6) users_response(false, 'Şifre en az 6 karakter olmalıdır.', [], 422, ['password' => 'min_6']);
            if ($password !== $passwordConfirm) users_response(false, 'Şifre tekrarı eşleşmiyor.', [], 422, ['password_confirm' => 'mismatch']);

            $checkSql = "SELECT COUNT(*) FROM `{$schema['table']}` WHERE LOWER(`{$schema['email']}`) = LOWER(?)";
            if ($schema['is_deleted']) {
                $checkSql .= " AND `{$schema['is_deleted']}` = 0";
            }
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute([$email]);
            if ((int)$stmt->fetchColumn() > 0) {
                users_response(false, 'Bu email adresi zaten kayıtlı.', [], 422, ['email' => 'duplicate']);
            }

            $payload = [
                $schema['email'] => $email,
            ];
            if ($schema['full_name']) $payload[$schema['full_name']] = $fullName;
            if ($schema['is_admin']) $payload[$schema['is_admin']] = $isAdmin;
            if ($schema['password']) $payload[$schema['password']] = hash_password($password);
            if ($schema['is_deleted']) $payload[$schema['is_deleted']] = 0;
            if ($schema['is_guest']) $payload[$schema['is_guest']] = 0;
            if ($schema['created_at']) $payload[$schema['created_at']] = users_now();
            if ($schema['updated_at']) $payload[$schema['updated_at']] = users_now();

            $metaStmt = $pdo->query("SHOW COLUMNS FROM `{$schema['table']}`");
            $metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
            $idMeta = null;
            foreach ($metaRows as $mr) {
                if (($mr['Field'] ?? '') === $schema['id']) {
                    $idMeta = $mr;
                    break;
                }
            }
            $isAuto = $idMeta && str_contains(strtolower((string)($idMeta['Extra'] ?? '')), 'auto_increment');
            if (!$isAuto) {
                $payload[$schema['id']] = generate_uuid();
            }

            $cols = array_keys($payload);
            $holders = implode(', ', array_fill(0, count($cols), '?'));
            $quoted = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $sql = "INSERT INTO `{$schema['table']}` ({$quoted}) VALUES ({$holders})";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($payload));

            users_response(true, 'Kullanıcı eklendi.');
            break;
        }

        case 'update': {
            $id = trim((string)($_POST['id'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            $isAdmin = users_bool($_POST['is_admin'] ?? 0);

            if ($id === '') users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            if ($fullName === '') users_response(false, 'Ad Soyad zorunludur.', [], 422, ['full_name' => 'required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) users_response(false, 'Geçerli bir email giriniz.', [], 422, ['email' => 'invalid']);

            $target = users_find_by_id($pdo, $schema, $id);
            if (!$target) users_response(false, 'Kullanıcı bulunamadı.', [], 404);

            $checkSql = "SELECT COUNT(*) FROM `{$schema['table']}` WHERE LOWER(`{$schema['email']}`) = LOWER(?) AND `{$schema['id']}` <> ?";
            $checkParams = [$email, $id];
            if ($schema['is_deleted']) {
                $checkSql .= " AND `{$schema['is_deleted']}` = 0";
            }
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute($checkParams);
            if ((int)$stmt->fetchColumn() > 0) {
                users_response(false, 'Bu email başka bir kullanıcıda kayıtlı.', [], 422, ['email' => 'duplicate']);
            }

            $updates = [
                $schema['email'] => $email,
            ];
            if ($schema['full_name']) $updates[$schema['full_name']] = $fullName;
            if ($schema['is_admin']) $updates[$schema['is_admin']] = $isAdmin;
            if ($schema['updated_at']) $updates[$schema['updated_at']] = users_now();

            if ($password !== '' || $passwordConfirm !== '') {
                if (mb_strlen($password) < 6) users_response(false, 'Yeni şifre en az 6 karakter olmalıdır.', [], 422, ['password' => 'min_6']);
                if ($password !== $passwordConfirm) users_response(false, 'Şifre tekrarı eşleşmiyor.', [], 422, ['password_confirm' => 'mismatch']);
                if ($schema['password']) {
                    $updates[$schema['password']] = hash_password($password);
                }
            }

            $set = [];
            $vals = [];
            foreach ($updates as $col => $val) {
                $set[] = "`{$col}` = ?";
                $vals[] = $val;
            }
            $vals[] = $id;

            $sql = "UPDATE `{$schema['table']}` SET " . implode(', ', $set) . " WHERE `{$schema['id']}` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);

            users_response(true, 'Kullanıcı güncellendi.');
            break;
        }

        case 'delete': {
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            if ((string)$id === (string)$currentUserId) {
                users_response(false, 'Kendi hesabınızı silemezsiniz.', [], 422, ['id' => 'self_delete']);
            }

            $target = users_find_by_id($pdo, $schema, $id);
            if (!$target) users_response(false, 'Kullanıcı bulunamadı.', [], 404);

            if ($schema['is_deleted']) {
                $updates = ["`{$schema['is_deleted']}` = 1"];
                $vals = [];
                if ($schema['updated_at']) {
                    $updates[] = "`{$schema['updated_at']}` = ?";
                    $vals[] = users_now();
                }
                if ($schema['deleted_at']) {
                    $updates[] = "`{$schema['deleted_at']}` = ?";
                    $vals[] = users_now();
                }
                $vals[] = $id;
                $sql = "UPDATE `{$schema['table']}` SET " . implode(', ', $updates) . " WHERE `{$schema['id']}` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($vals);
            } else {
                $stmt = $pdo->prepare("DELETE FROM `{$schema['table']}` WHERE `{$schema['id']}` = ?");
                $stmt->execute([$id]);
            }

            users_response(true, 'Kullanıcı silindi.');
            break;
        }

        default:
            users_response(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    users_response(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

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
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
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
        return [];
    }

    $map = [];
    try {
        $rows = $pdo->query('SELECT id, name FROM qualifications')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $map[(string)$row['id']] = (string)($row['name'] ?? '-');
        }
    } catch (Throwable $e) {
    }

    return $map;
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
    if (!users_has_table($pdo, 'question_attempt_events')) {
        return [
            'totals' => [
                'total_solved' => 0,
                'correct' => 0,
                'wrong' => 0,
                'success_rate' => 0,
                'last_study_at' => null,
            ],
            'source_distribution' => [],
            'recent_events' => [],
            'breakdowns' => [
                'qualification' => [],
                'course' => [],
                'topic' => [],
            ],
        ];
    }

    $cols = get_table_columns($pdo, 'question_attempt_events');
    $uid = users_pick_column($cols, ['user_id'], false);
    if (!$uid) {
        return [
            'totals' => [
                'total_solved' => 0,
                'correct' => 0,
                'wrong' => 0,
                'success_rate' => 0,
                'last_study_at' => null,
            ],
            'source_distribution' => [],
            'recent_events' => [],
            'breakdowns' => [
                'qualification' => [],
                'course' => [],
                'topic' => [],
            ],
        ];
    }

    $isCorrectCol = users_pick_column($cols, ['is_correct', 'correct', 'is_correct_answer'], false);
    $sourceCol = users_pick_column($cols, ['source'], false);
    $createdCol = users_pick_column($cols, ['created_at', 'answered_at', 'event_at'], false);
    $qidCol = users_pick_column($cols, ['question_id'], false);
    $qualCol = users_pick_column($cols, ['qualification_id'], false);
    $courseCol = users_pick_column($cols, ['course_id'], false);
    $topicCol = users_pick_column($cols, ['topic_id'], false);

    $correctExpr = $isCorrectCol ? "SUM(CASE WHEN `{$isCorrectCol}` = 1 THEN 1 ELSE 0 END)" : '0';
    $wrongExpr = $isCorrectCol ? "SUM(CASE WHEN `{$isCorrectCol}` = 0 THEN 1 ELSE 0 END)" : '0';
    $lastExpr = $createdCol ? "MAX(`{$createdCol}`)" : 'NULL';

    $totSql = "SELECT COUNT(*) AS total_solved, {$correctExpr} AS correct, {$wrongExpr} AS wrong, {$lastExpr} AS last_study_at FROM question_attempt_events WHERE `{$uid}` = ?";
    $totStmt = $pdo->prepare($totSql);
    $totStmt->execute([$userId]);
    $tot = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalSolved = (int)($tot['total_solved'] ?? 0);
    $correct = (int)($tot['correct'] ?? 0);
    $wrong = (int)($tot['wrong'] ?? 0);
    $successRate = $totalSolved > 0 ? round(($correct / $totalSolved) * 100, 2) : 0;

    $distribution = [];
    if ($sourceCol) {
        $dSql = "SELECT `{$sourceCol}` AS source, COUNT(*) AS total FROM question_attempt_events WHERE `{$uid}` = ? GROUP BY `{$sourceCol}` ORDER BY total DESC";
        $dStmt = $pdo->prepare($dSql);
        $dStmt->execute([$userId]);
        $distribution = $dStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $selectRecent = [];
    $selectRecent[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
    $selectRecent[] = $sourceCol ? "`{$sourceCol}` AS source" : 'NULL AS source';
    $selectRecent[] = $isCorrectCol ? "`{$isCorrectCol}` AS is_correct" : 'NULL AS is_correct';
    $selectRecent[] = $qidCol ? "`{$qidCol}` AS question_id" : 'NULL AS question_id';
    $selectRecent[] = $qualCol ? "`{$qualCol}` AS qualification_id" : 'NULL AS qualification_id';
    $selectRecent[] = $courseCol ? "`{$courseCol}` AS course_id" : 'NULL AS course_id';
    $selectRecent[] = $topicCol ? "`{$topicCol}` AS topic_id" : 'NULL AS topic_id';
    $orderCol = $createdCol ?: ($qidCol ?: $uid);
    $rSql = 'SELECT ' . implode(', ', $selectRecent) . " FROM question_attempt_events WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 20";
    $rStmt = $pdo->prepare($rSql);
    $rStmt->execute([$userId]);
    $recent = $rStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $breakdown = ['qualification' => [], 'course' => [], 'topic' => []];
    try {
        if ($qualCol && users_has_table($pdo, 'qualifications')) {
            $sql = "SELECT q.id, q.name, COUNT(*) AS total
                    FROM question_attempt_events e
                    LEFT JOIN qualifications q ON q.id = e.`{$qualCol}`
                    WHERE e.`{$uid}` = ?
                    GROUP BY q.id, q.name
                    ORDER BY total DESC
                    LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $breakdown['qualification'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($courseCol && users_has_table($pdo, 'courses')) {
            $sql = "SELECT c.id, c.name, COUNT(*) AS total
                    FROM question_attempt_events e
                    LEFT JOIN courses c ON c.id = e.`{$courseCol}`
                    WHERE e.`{$uid}` = ?
                    GROUP BY c.id, c.name
                    ORDER BY total DESC
                    LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $breakdown['course'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($topicCol && users_has_table($pdo, 'topics')) {
            $sql = "SELECT t.id, t.name, COUNT(*) AS total
                    FROM question_attempt_events e
                    LEFT JOIN topics t ON t.id = e.`{$topicCol}`
                    WHERE e.`{$uid}` = ?
                    GROUP BY t.id, t.name
                    ORDER BY total DESC
                    LIMIT 15";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $breakdown['topic'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
    }

    return [
        'totals' => [
            'total_solved' => $totalSolved,
            'correct' => $correct,
            'wrong' => $wrong,
            'success_rate' => $successRate,
            'last_study_at' => $tot['last_study_at'] ?? null,
        ],
        'source_distribution' => $distribution,
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
    $uid = users_pick_column($cols, ['user_id'], false);
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
    $createdCol = users_pick_column($cols, ['created_at', 'started_at'], false);
    $updatedCol = users_pick_column($cols, ['updated_at', 'ended_at', 'submitted_at'], false);
    $scoreCol = users_pick_column($cols, ['score', 'score_percent', 'success_rate'], false);
    $qualCol = users_pick_column($cols, ['qualification_id'], false);

    $completedExpr = $statusCol ? "SUM(CASE WHEN `{$statusCol}` IN ('completed', 'submitted', 'finished') THEN 1 ELSE 0 END)" : '0';
    $progressExpr = $statusCol ? "SUM(CASE WHEN `{$statusCol}` IN ('in_progress', 'active', 'started') THEN 1 ELSE 0 END)" : '0';
    $abandonedExpr = $statusCol ? "SUM(CASE WHEN `{$statusCol}` IN ('abandoned', 'cancelled', 'expired') THEN 1 ELSE 0 END)" : '0';
    $lastCol = $updatedCol ?: $createdCol;
    $lastExpr = $lastCol ? "MAX(`{$lastCol}`)" : 'NULL';

    $sql = "SELECT COUNT(*) AS total, {$completedExpr} AS completed, {$progressExpr} AS in_progress, {$abandonedExpr} AS abandoned, {$lastExpr} AS last_exam_at FROM mock_exam_attempts WHERE `{$uid}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $select = [];
    $select[] = users_pick_column($cols, ['id'], false) ? '`' . users_pick_column($cols, ['id'], false) . '` AS id' : 'NULL AS id';
    $select[] = $statusCol ? "`{$statusCol}` AS status" : 'NULL AS status';
    $select[] = $scoreCol ? "`{$scoreCol}` AS score" : 'NULL AS score';
    $select[] = $qualCol ? "`{$qualCol}` AS qualification_id" : 'NULL AS qualification_id';
    $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
    $select[] = $updatedCol ? "`{$updatedCol}` AS updated_at" : 'NULL AS updated_at';
    $orderCol = $updatedCol ?: ($createdCol ?: $uid);
    $listSql = 'SELECT ' . implode(', ', $select) . " FROM mock_exam_attempts WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 100";
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute([$userId]);

    return [
        'summary' => [
            'total' => (int)($summary['total'] ?? 0),
            'completed' => (int)($summary['completed'] ?? 0),
            'in_progress' => (int)($summary['in_progress'] ?? 0),
            'abandoned' => (int)($summary['abandoned'] ?? 0),
            'last_exam_at' => $summary['last_exam_at'] ?? null,
        ],
        'attempts' => $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

function users_get_user_usage_limits(PDO $pdo, string $userId): array
{
    if (!users_has_table($pdo, 'user_daily_usage_counters')) {
        return [
            'summary' => [],
            'rows' => [],
        ];
    }

    $cols = get_table_columns($pdo, 'user_daily_usage_counters');
    $uid = users_pick_column($cols, ['user_id'], false);
    if (!$uid) {
        return ['summary' => [], 'rows' => []];
    }

    $featureCol = users_pick_column($cols, ['feature_key'], false);
    $usedCol = users_pick_column($cols, ['used_count'], false);
    $dateCol = users_pick_column($cols, ['usage_date_tr', 'usage_date'], false);
    $qualCol = users_pick_column($cols, ['qualification_id'], false);
    $createdCol = users_pick_column($cols, ['created_at'], false);
    $updatedCol = users_pick_column($cols, ['updated_at'], false);

    $select = [];
    $select[] = $dateCol ? "`{$dateCol}` AS usage_date" : 'NULL AS usage_date';
    $select[] = $featureCol ? "`{$featureCol}` AS feature_key" : 'NULL AS feature_key';
    $select[] = $usedCol ? "`{$usedCol}` AS used_count" : '0 AS used_count';
    $select[] = $qualCol ? "`{$qualCol}` AS qualification_id" : 'NULL AS qualification_id';
    $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
    $select[] = $updatedCol ? "`{$updatedCol}` AS updated_at" : 'NULL AS updated_at';

    $orderCol = $updatedCol ?: ($createdCol ?: ($dateCol ?: $uid));
    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM user_daily_usage_counters WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summary = [];
    foreach ($rows as $row) {
        $key = (string)($row['feature_key'] ?? 'unknown');
        if (!isset($summary[$key])) {
            $summary[$key] = 0;
        }
        $summary[$key] += (int)($row['used_count'] ?? 0);
    }

    return [
        'summary' => $summary,
        'rows' => $rows,
    ];
}

function users_get_user_devices(PDO $pdo, string $userId): array
{
    $apiTokens = [];
    if (users_has_table($pdo, 'api_tokens')) {
        $cols = get_table_columns($pdo, 'api_tokens');
        $uid = users_pick_column($cols, ['user_id'], false);
        if ($uid) {
            $nameCol = users_pick_column($cols, ['name'], false);
            $createdCol = users_pick_column($cols, ['created_at'], false);
            $lastUsedCol = users_pick_column($cols, ['last_used_at'], false);
            $expiresCol = users_pick_column($cols, ['expires_at'], false);
            $revokedCol = users_pick_column($cols, ['revoked_at'], false);

            $select = [];
            $select[] = users_pick_column($cols, ['id'], false) ? '`' . users_pick_column($cols, ['id'], false) . '` AS id' : 'NULL AS id';
            $select[] = $nameCol ? "`{$nameCol}` AS name" : 'NULL AS name';
            $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
            $select[] = $lastUsedCol ? "`{$lastUsedCol}` AS last_used_at" : 'NULL AS last_used_at';
            $select[] = $expiresCol ? "`{$expiresCol}` AS expires_at" : 'NULL AS expires_at';
            $select[] = $revokedCol ? "`{$revokedCol}` AS revoked_at" : 'NULL AS revoked_at';
            $orderCol = $lastUsedCol ?: ($createdCol ?: $uid);
            $sql = 'SELECT ' . implode(', ', $select)
                . " FROM api_tokens WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $apiTokens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $pushTokens = [];
    if (users_has_table($pdo, 'user_push_tokens')) {
        $cols = get_table_columns($pdo, 'user_push_tokens');
        $uid = users_pick_column($cols, ['user_id'], false);
        if ($uid) {
            $tokenCol = users_pick_column($cols, ['push_token', 'token'], false);
            $platformCol = users_pick_column($cols, ['platform'], false);
            $deviceCol = users_pick_column($cols, ['device_id', 'device_name'], false);
            $lastSeenCol = users_pick_column($cols, ['last_seen_at', 'last_used_at'], false);
            $createdCol = users_pick_column($cols, ['created_at'], false);
            $revokedCol = users_pick_column($cols, ['revoked_at', 'deleted_at'], false);

            $select = [];
            $select[] = users_pick_column($cols, ['id'], false) ? '`' . users_pick_column($cols, ['id'], false) . '` AS id' : 'NULL AS id';
            $select[] = $tokenCol ? "`{$tokenCol}` AS token" : 'NULL AS token';
            $select[] = $platformCol ? "`{$platformCol}` AS platform" : 'NULL AS platform';
            $select[] = $deviceCol ? "`{$deviceCol}` AS device_id" : 'NULL AS device_id';
            $select[] = $lastSeenCol ? "`{$lastSeenCol}` AS last_seen_at" : 'NULL AS last_seen_at';
            $select[] = $createdCol ? "`{$createdCol}` AS created_at" : 'NULL AS created_at';
            $select[] = $revokedCol ? "`{$revokedCol}` AS revoked_at" : 'NULL AS revoked_at';

            $orderCol = $lastSeenCol ?: ($createdCol ?: $uid);
            $sql = 'SELECT ' . implode(', ', $select)
                . " FROM user_push_tokens WHERE `{$uid}` = ? ORDER BY `{$orderCol}` DESC LIMIT 100";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $pushTokens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

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
            $currentQ = (string)($user['current_qualification_id'] ?? '');
            $targetQ = (string)($user['target_qualification_id'] ?? '');

            $studyStats = users_get_user_study_stats($pdo, $id);
            $examStats = users_get_user_exam_stats($pdo, $id);

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
                    'current_qualification_name' => $currentQ !== '' ? ($qualificationMap[$currentQ] ?? '-') : '-',
                    'target_qualification_id' => $targetQ !== '' ? $targetQ : null,
                    'target_qualification_name' => $targetQ !== '' ? ($qualificationMap[$targetQ] ?? '-') : '-',
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
                ],
                'kpi' => [
                    'total_solved' => (int)($studyStats['totals']['total_solved'] ?? 0),
                    'correct' => (int)($studyStats['totals']['correct'] ?? 0),
                    'wrong' => (int)($studyStats['totals']['wrong'] ?? 0),
                    'success_rate' => (float)($studyStats['totals']['success_rate'] ?? 0),
                    'total_exams' => (int)($examStats['summary']['total'] ?? 0),
                    'completed_exams' => (int)($examStats['summary']['completed'] ?? 0),
                    'premium_active' => $isPremium ? 1 : 0,
                ],
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

<?php

require_once dirname(__DIR__) . '/usage_limits_helper.php';

function pc_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function pc_first_col(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function pc_to_int($value): int
{
    return (int)($value ?? 0);
}

function pc_to_float($value, int $precision = 2): float
{
    return round((float)($value ?? 0), $precision);
}

function pc_calc_success_rate(int $correctCount, int $wrongCount): float
{
    $solved = $correctCount + $wrongCount;
    if ($solved <= 0) {
        return 0.0;
    }

    return round(($correctCount / $solved) * 100, 2);
}

function pc_safe_delta(?float $userRate, ?float $benchmarkRate): ?float
{
    if ($userRate === null || $benchmarkRate === null) {
        return null;
    }

    return round($userRate - $benchmarkRate, 2);
}

function pc_sql_correct_count_expr(string $tableAlias, string $isCorrectCol): string
{
    return 'COALESCE(SUM(CASE WHEN ' . $tableAlias . '.' . pc_q($isCorrectCol) . ' = 1 THEN 1 ELSE 0 END), 0)';
}

function pc_sql_wrong_count_expr(string $tableAlias, string $isCorrectCol): string
{
    return 'COALESCE(SUM(CASE WHEN ' . $tableAlias . '.' . pc_q($isCorrectCol) . ' = 0 THEN 1 ELSE 0 END), 0)';
}

function pc_sql_solved_count_expr(string $tableAlias): string
{
    return 'COUNT(' . $tableAlias . '.*)';
}

function pc_sql_success_rate_expr(string $tableAlias, string $isCorrectCol): string
{
    $correctExpr = pc_sql_correct_count_expr($tableAlias, $isCorrectCol);
    $solvedExpr = pc_sql_solved_count_expr($tableAlias);

    return 'ROUND((' . $correctExpr . ' * 100.0) / NULLIF(' . $solvedExpr . ', 0), 2)';
}

function pc_resolve_window(?string $rangeRaw): array
{
    $range = strtolower(trim((string)$rangeRaw));
    if ($range === '') {
        $range = '30d';
    }

    $allowed = ['7d', '30d', '90d', 'all'];
    if (!in_array($range, $allowed, true)) {
        api_error('Geçersiz range parametresi.', 422);
    }

    if ($range === 'all') {
        return [
            'range' => 'all',
            'start_date' => null,
            'end_date' => null,
            'is_all_time' => true,
        ];
    }

    $days = (int)str_replace('d', '', $range);
    $today = new DateTimeImmutable('today');
    $startDate = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $endDate = $today->format('Y-m-d');

    return [
        'range' => $range,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'is_all_time' => false,
    ];
}

function pc_get_event_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'question_attempt_events');
    if (!$cols) {
        throw new RuntimeException('question_attempt_events tablosu okunamadı.');
    }

    $schema = [
        'table' => 'question_attempt_events',
        'user_id' => pc_first_col($cols, ['user_id']),
        'qualification_id' => pc_first_col($cols, ['qualification_id']),
        'course_id' => pc_first_col($cols, ['course_id']),
        'topic_id' => pc_first_col($cols, ['topic_id']),
        'is_correct' => pc_first_col($cols, ['is_correct']),
        'attempted_at' => pc_first_col($cols, ['attempted_at', 'created_at', 'updated_at']),
    ];

    foreach (['user_id', 'qualification_id', 'course_id', 'topic_id', 'is_correct', 'attempted_at'] as $requiredKey) {
        if (!$schema[$requiredKey]) {
            throw new RuntimeException('question_attempt_events şemasında eksik kolon: ' . $requiredKey);
        }
    }

    return $schema;
}

function pc_get_current_qualification_context(PDO $pdo, string $userId): array
{
    $qualificationId = get_current_user_qualification_id($pdo, $userId);
    if ($qualificationId === null || trim($qualificationId) === '') {
        api_error('Current qualification bulunamadı. Önce yeterlilik seçmelisiniz.', 403);
    }

    $stmt = $pdo->prepare('SELECT id, name FROM qualifications WHERE id = ? LIMIT 1');
    $stmt->execute([$qualificationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'qualification_id' => (string)$qualificationId,
        'qualification_name' => (string)($row['name'] ?? ''),
    ];
}

function pc_get_course_context(PDO $pdo, string $courseId): ?array
{
    $stmt = $pdo->prepare('SELECT c.id, c.name, c.qualification_id, q.name AS qualification_name
                           FROM courses c
                           LEFT JOIN qualifications q ON q.id = c.qualification_id
                           WHERE c.id = ?
                           LIMIT 1');
    $stmt->execute([$courseId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }

    return [
        'course_id' => (string)($row['id'] ?? ''),
        'course_name' => (string)($row['name'] ?? ''),
        'qualification_id' => (string)($row['qualification_id'] ?? ''),
        'qualification_name' => (string)($row['qualification_name'] ?? ''),
    ];
}

function pc_get_topic_context(PDO $pdo, string $topicId): ?array
{
    $stmt = $pdo->prepare('SELECT t.id, t.name, t.course_id, c.name AS course_name, c.qualification_id, q.name AS qualification_name
                           FROM topics t
                           INNER JOIN courses c ON c.id = t.course_id
                           LEFT JOIN qualifications q ON q.id = c.qualification_id
                           WHERE t.id = ?
                           LIMIT 1');
    $stmt->execute([$topicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }

    return [
        'topic_id' => (string)($row['id'] ?? ''),
        'topic_name' => (string)($row['name'] ?? ''),
        'course_id' => (string)($row['course_id'] ?? ''),
        'course_name' => (string)($row['course_name'] ?? ''),
        'qualification_id' => (string)($row['qualification_id'] ?? ''),
        'qualification_name' => (string)($row['qualification_name'] ?? ''),
    ];
}

function pc_resolve_scope_context(PDO $pdo, string $userId, string $scope, string $courseId, string $topicId): array
{
    $qualification = pc_get_current_qualification_context($pdo, $userId);

    $context = [
        'qualification_id' => (string)$qualification['qualification_id'],
        'qualification_name' => (string)$qualification['qualification_name'],
        'course_id' => null,
        'course_name' => null,
        'topic_id' => null,
        'topic_name' => null,
    ];

    if ($scope === 'qualification') {
        if ($courseId !== '') {
            $course = pc_get_course_context($pdo, $courseId);
            if (!$course || (string)$course['qualification_id'] !== (string)$qualification['qualification_id']) {
                api_error('course_id current qualification ile uyumlu değil.', 422);
            }
            $context['course_id'] = (string)$course['course_id'];
            $context['course_name'] = (string)$course['course_name'];
        }

        if ($topicId !== '') {
            $topic = pc_get_topic_context($pdo, $topicId);
            if (!$topic || (string)$topic['qualification_id'] !== (string)$qualification['qualification_id']) {
                api_error('topic_id current qualification ile uyumlu değil.', 422);
            }
            if ($context['course_id'] !== null && (string)$topic['course_id'] !== (string)$context['course_id']) {
                api_error('topic_id ile course_id uyumsuz.', 422);
            }

            $context['course_id'] = (string)$topic['course_id'];
            $context['course_name'] = (string)$topic['course_name'];
            $context['topic_id'] = (string)$topic['topic_id'];
            $context['topic_name'] = (string)$topic['topic_name'];
        }

        return $context;
    }

    if ($scope === 'course') {
        if ($courseId === '') {
            api_error('course scope için course_id zorunludur.', 422);
        }

        $course = pc_get_course_context($pdo, $courseId);
        if (!$course) {
            api_error('Geçersiz course_id.', 422);
        }
        if ((string)$course['qualification_id'] !== (string)$qualification['qualification_id']) {
            api_error('course_id current qualification ile uyumlu değil.', 422);
        }

        $context['course_id'] = (string)$course['course_id'];
        $context['course_name'] = (string)$course['course_name'];

        if ($topicId !== '') {
            $topic = pc_get_topic_context($pdo, $topicId);
            if (!$topic || (string)$topic['course_id'] !== (string)$course['course_id']) {
                api_error('topic_id bu course ile uyumlu değil.', 422);
            }

            $context['topic_id'] = (string)$topic['topic_id'];
            $context['topic_name'] = (string)$topic['topic_name'];
        }

        return $context;
    }

    if ($scope === 'topic') {
        if ($topicId === '') {
            api_error('topic scope için topic_id zorunludur.', 422);
        }

        $topic = pc_get_topic_context($pdo, $topicId);
        if (!$topic) {
            api_error('Geçersiz topic_id.', 422);
        }

        if ((string)$topic['qualification_id'] !== (string)$qualification['qualification_id']) {
            api_error('topic current qualification ile uyumlu değil.', 422);
        }

        if ($courseId !== '' && (string)$topic['course_id'] !== $courseId) {
            api_error('topic_id ile course_id uyumsuz.', 422);
        }

        $context['course_id'] = (string)$topic['course_id'];
        $context['course_name'] = (string)$topic['course_name'];
        $context['topic_id'] = (string)$topic['topic_id'];
        $context['topic_name'] = (string)$topic['topic_name'];

        return $context;
    }

    api_error('Geçersiz scope parametresi.', 422);
}

function pc_build_scope_filters(array $eventSchema, string $scope, array $window, array $context): array
{
    $alias = 'e';
    $where = [];
    $params = [];

    $qualificationIdCol = $eventSchema['qualification_id'];
    $courseIdCol = $eventSchema['course_id'];
    $topicIdCol = $eventSchema['topic_id'];
    $attemptedAtCol = $eventSchema['attempted_at'];

    $where[] = $alias . '.' . pc_q($qualificationIdCol) . ' = ?';
    $params[] = (string)($context['qualification_id'] ?? '');

    if ($scope === 'course' || $scope === 'topic' || !empty($context['course_id'])) {
        $where[] = $alias . '.' . pc_q($courseIdCol) . ' = ?';
        $params[] = (string)($context['course_id'] ?? '');
    }

    if ($scope === 'topic' || !empty($context['topic_id'])) {
        $where[] = $alias . '.' . pc_q($topicIdCol) . ' = ?';
        $params[] = (string)($context['topic_id'] ?? '');
    }

    if (empty($window['is_all_time'])) {
        $where[] = 'DATE(' . $alias . '.' . pc_q($attemptedAtCol) . ') BETWEEN ? AND ?';
        $params[] = (string)$window['start_date'];
        $params[] = (string)$window['end_date'];
    }

    return [
        'where_sql' => implode(' AND ', $where),
        'params' => $params,
    ];
}

function pc_build_benchmark_profile_filters(PDO $pdo, string $profileAlias = 'up'): array
{
    $clauses = [];

    try {
        $profileSchema = api_get_profile_schema($pdo);
    } catch (Throwable $e) {
        $profileSchema = [
            'table' => 'user_profiles',
            'id' => 'id',
            'email' => 'email',
            'full_name' => 'full_name',
            'is_deleted' => null,
            'is_guest' => null,
            'current_qualification_id' => null,
        ];
    }

    if (!empty($profileSchema['is_deleted'])) {
        $clauses[] = 'COALESCE(' . $profileAlias . '.' . pc_q((string)$profileSchema['is_deleted']) . ', 0) = 0';
    }

    if (!empty($profileSchema['is_guest'])) {
        $clauses[] = 'COALESCE(' . $profileAlias . '.' . pc_q((string)$profileSchema['is_guest']) . ', 0) = 0';
    } else {
        $emailExpr = !empty($profileSchema['email'])
            ? 'LOWER(COALESCE(' . $profileAlias . '.' . pc_q((string)$profileSchema['email']) . ', ""))'
            : "''";

        $nameExpr = !empty($profileSchema['full_name'])
            ? 'LOWER(TRIM(COALESCE(' . $profileAlias . '.' . pc_q((string)$profileSchema['full_name']) . ', "")))'
            : "''";

        $clauses[] = 'NOT ((' . $emailExpr . " LIKE '%@guest.local') OR (" . $nameExpr . " IN ('misafir kullanıcı', 'misafir kullanici', 'guest user')))";
    }

    if (!empty($profileSchema['current_qualification_id'])) {
        $clauses[] = 'TRIM(COALESCE(' . $profileAlias . '.' . pc_q((string)$profileSchema['current_qualification_id']) . ', "")) <> ""';
    }

    return [
        'profile_schema' => $profileSchema,
        'clauses' => $clauses,
    ];
}

function pc_fetch_user_summary(PDO $pdo, string $userId, array $eventSchema, array $scopeFilters, array $context): array
{
    $userIdCol = $eventSchema['user_id'];
    $isCorrectCol = $eventSchema['is_correct'];
    $correctExpr = pc_sql_correct_count_expr('e', $isCorrectCol);
    $wrongExpr = pc_sql_wrong_count_expr('e', $isCorrectCol);

    $sql = 'SELECT '
        . $correctExpr . ' AS correct_count, '
        . $wrongExpr . ' AS wrong_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'WHERE e.' . pc_q($userIdCol) . ' = ?'
        . ' AND ' . $scopeFilters['where_sql'];

    $params = array_merge([$userId], $scopeFilters['params']);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $correctCount = pc_to_int($row['correct_count'] ?? 0);
    $wrongCount = pc_to_int($row['wrong_count'] ?? 0);
    $solvedCount = $correctCount + $wrongCount;
    $successRate = pc_calc_success_rate($correctCount, $wrongCount);

    return [
        'solved_count' => $solvedCount,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'success_rate' => $successRate,
        'delta_vs_benchmark' => null,
        'percentile' => null,
        'rank_label' => 'Yeterli veri yok',
        'qualification_id' => $context['qualification_id'] ?? null,
        'qualification_name' => $context['qualification_name'] ?? null,
        'course_id' => $context['course_id'] ?? null,
        'course_name' => $context['course_name'] ?? null,
        'topic_id' => $context['topic_id'] ?? null,
        'topic_name' => $context['topic_name'] ?? null,
    ];
}

function pc_fetch_benchmark_user_rows(PDO $pdo, string $userId, array $eventSchema, array $scopeFilters): array
{
    $userIdCol = $eventSchema['user_id'];
    $isCorrectCol = $eventSchema['is_correct'];
    $correctExpr = pc_sql_correct_count_expr('e', $isCorrectCol);
    $wrongExpr = pc_sql_wrong_count_expr('e', $isCorrectCol);
    $solvedExpr = pc_sql_solved_count_expr('e');

    $profile = pc_build_benchmark_profile_filters($pdo, 'up');
    $profileSchema = $profile['profile_schema'];
    $profileClauses = $profile['clauses'];

    $where = [
        'e.' . pc_q($userIdCol) . ' <> ?',
        $scopeFilters['where_sql'],
    ];
    if (!empty($profileClauses)) {
        $where = array_merge($where, $profileClauses);
    }

    $sql = 'SELECT '
        . 'e.' . pc_q($userIdCol) . ' AS user_id, '
        . $correctExpr . ' AS correct_count, '
        . $wrongExpr . ' AS wrong_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'INNER JOIN `' . $profileSchema['table'] . '` up ON up.' . pc_q((string)$profileSchema['id']) . ' = e.' . pc_q($userIdCol) . ' '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'GROUP BY e.' . pc_q($userIdCol) . ' '
        . 'HAVING ' . $solvedExpr . ' > 0';

    $params = array_merge([$userId], $scopeFilters['params']);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $correctCount = pc_to_int($row['correct_count'] ?? 0);
        $wrongCount = pc_to_int($row['wrong_count'] ?? 0);
        $solvedCount = $correctCount + $wrongCount;

        $result[] = [
            'user_id' => (string)($row['user_id'] ?? ''),
            'solved_count' => $solvedCount,
            'correct_count' => $correctCount,
            'wrong_count' => $wrongCount,
            'success_rate' => pc_calc_success_rate($correctCount, $wrongCount),
        ];
    }

    return $result;
}

function pc_fetch_benchmark_summary(array $benchmarkRows): array
{
    $participants = count($benchmarkRows);
    if ($participants <= 0) {
        return [
            'participants_count' => 0,
            'avg_solved_count' => 0,
            'avg_correct_count' => 0,
            'avg_wrong_count' => 0,
            'avg_success_rate' => null,
            'top20_success_rate' => null,
            'benchmark_label' => 'Yeterli benchmark verisi yok',
        ];
    }

    $sumSolved = 0;
    $sumCorrect = 0;
    $sumWrong = 0;
    $sumSuccess = 0.0;
    $rates = [];

    foreach ($benchmarkRows as $row) {
        $sumSolved += pc_to_int($row['solved_count'] ?? 0);
        $sumCorrect += pc_to_int($row['correct_count'] ?? 0);
        $sumWrong += pc_to_int($row['wrong_count'] ?? 0);
        $rate = pc_to_float($row['success_rate'] ?? 0);
        $sumSuccess += $rate;
        $rates[] = $rate;
    }

    rsort($rates, SORT_NUMERIC);
    $topCount = max(1, (int)ceil($participants * 0.2));
    $topSlice = array_slice($rates, 0, $topCount);
    $top20 = !empty($topSlice) ? round(array_sum($topSlice) / count($topSlice), 2) : null;

    return [
        'participants_count' => $participants,
        'avg_solved_count' => round($sumSolved / $participants, 2),
        'avg_correct_count' => round($sumCorrect / $participants, 2),
        'avg_wrong_count' => round($sumWrong / $participants, 2),
        'avg_success_rate' => round($sumSuccess / $participants, 2),
        'top20_success_rate' => $top20,
        'benchmark_label' => 'Benzer kullanıcı ortalaması',
    ];
}

function pc_fetch_percentile(float $userSuccessRate, array $benchmarkRows): ?float
{
    $participants = count($benchmarkRows);
    if ($participants <= 0) {
        return null;
    }

    $lowerCount = 0;
    foreach ($benchmarkRows as $row) {
        $rate = pc_to_float($row['success_rate'] ?? 0);
        if ($rate < $userSuccessRate) {
            $lowerCount++;
        }
    }

    return round(($lowerCount / $participants) * 100, 2);
}

function pc_fetch_course_topic_counts(PDO $pdo, array $courseIds): array
{
    if (empty($courseIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $sql = 'SELECT course_id, COUNT(*) AS topic_count FROM topics WHERE course_id IN (' . $placeholders . ') GROUP BY course_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($courseIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $map[(string)($row['course_id'] ?? '')] = pc_to_int($row['topic_count'] ?? 0);
    }

    return $map;
}

function pc_fetch_course_breakdown(PDO $pdo, string $userId, array $eventSchema, array $scopeFilters): array
{
    $userIdCol = $eventSchema['user_id'];
    $isCorrectCol = $eventSchema['is_correct'];
    $courseIdCol = $eventSchema['course_id'];
    $correctExpr = pc_sql_correct_count_expr('e', $isCorrectCol);
    $wrongExpr = pc_sql_wrong_count_expr('e', $isCorrectCol);
    $solvedExpr = pc_sql_solved_count_expr('e');

    $userSql = 'SELECT '
        . 'c.id AS id, c.name AS name, '
        . $correctExpr . ' AS correct_count, '
        . $wrongExpr . ' AS wrong_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'INNER JOIN courses c ON c.id = e.' . pc_q($courseIdCol) . ' '
        . 'WHERE e.' . pc_q($userIdCol) . ' = ? AND ' . $scopeFilters['where_sql'] . ' '
        . 'GROUP BY c.id, c.name '
        . 'HAVING ' . $solvedExpr . ' > 0 '
        . 'ORDER BY ' . $solvedExpr . ' DESC, c.name ASC';

    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute(array_merge([$userId], $scopeFilters['params']));
    $userRows = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $profile = pc_build_benchmark_profile_filters($pdo, 'up');
    $profileSchema = $profile['profile_schema'];
    $benchWhere = [
        'e.' . pc_q($userIdCol) . ' <> ?',
        $scopeFilters['where_sql'],
    ];
    if (!empty($profile['clauses'])) {
        $benchWhere = array_merge($benchWhere, $profile['clauses']);
    }

    $benchSql = 'SELECT '
        . 'c.id AS id, '
        . $correctExpr . ' AS total_correct, '
        . $wrongExpr . ' AS total_wrong, '
        . 'COUNT(DISTINCT e.' . pc_q($userIdCol) . ') AS participants_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'INNER JOIN courses c ON c.id = e.' . pc_q($courseIdCol) . ' '
        . 'INNER JOIN `' . $profileSchema['table'] . '` up ON up.' . pc_q((string)$profileSchema['id']) . ' = e.' . pc_q($userIdCol) . ' '
        . 'WHERE ' . implode(' AND ', $benchWhere) . ' '
        . 'GROUP BY c.id';

    $benchStmt = $pdo->prepare($benchSql);
    $benchStmt->execute(array_merge([$userId], $scopeFilters['params']));
    $benchRows = $benchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $benchMap = [];
    foreach ($benchRows as $row) {
        $id = (string)($row['id'] ?? '');
        $correct = pc_to_int($row['total_correct'] ?? 0);
        $wrong = pc_to_int($row['total_wrong'] ?? 0);
        $benchMap[$id] = [
            'benchmark_success_rate' => pc_calc_success_rate($correct, $wrong),
            'participants_count' => pc_to_int($row['participants_count'] ?? 0),
        ];
    }

    $courseIds = [];
    foreach ($userRows as $row) {
        $id = (string)($row['id'] ?? '');
        if ($id !== '') {
            $courseIds[] = $id;
        }
    }
    $topicCounts = pc_fetch_course_topic_counts($pdo, array_values(array_unique($courseIds)));

    $result = [];
    foreach ($userRows as $row) {
        $id = (string)($row['id'] ?? '');
        $correct = pc_to_int($row['correct_count'] ?? 0);
        $wrong = pc_to_int($row['wrong_count'] ?? 0);
        $solved = $correct + $wrong;
        $userRate = pc_calc_success_rate($correct, $wrong);

        $benchmarkRate = null;
        $participants = 0;
        if (isset($benchMap[$id])) {
            $benchmarkRate = pc_to_float($benchMap[$id]['benchmark_success_rate'] ?? 0);
            $participants = pc_to_int($benchMap[$id]['participants_count'] ?? 0);
        }

        $result[] = [
            'id' => $id,
            'name' => (string)($row['name'] ?? ''),
            'solved_count' => $solved,
            'correct_count' => $correct,
            'wrong_count' => $wrong,
            'success_rate' => $userRate,
            'benchmark_success_rate' => $benchmarkRate,
            'delta_vs_benchmark' => pc_safe_delta($userRate, $benchmarkRate),
            'has_topics' => ((int)($topicCounts[$id] ?? 0) > 0),
            'participants_count' => $participants,
        ];
    }

    return $result;
}

function pc_fetch_topic_breakdown(PDO $pdo, string $userId, array $eventSchema, array $scopeFilters): array
{
    $userIdCol = $eventSchema['user_id'];
    $isCorrectCol = $eventSchema['is_correct'];
    $topicIdCol = $eventSchema['topic_id'];
    $correctExpr = pc_sql_correct_count_expr('e', $isCorrectCol);
    $wrongExpr = pc_sql_wrong_count_expr('e', $isCorrectCol);
    $solvedExpr = pc_sql_solved_count_expr('e');

    $userSql = 'SELECT '
        . 't.id AS id, t.name AS name, '
        . $correctExpr . ' AS correct_count, '
        . $wrongExpr . ' AS wrong_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'INNER JOIN topics t ON t.id = e.' . pc_q($topicIdCol) . ' '
        . 'WHERE e.' . pc_q($userIdCol) . ' = ? AND ' . $scopeFilters['where_sql'] . ' '
        . 'GROUP BY t.id, t.name '
        . 'HAVING ' . $solvedExpr . ' > 0 '
        . 'ORDER BY ' . $solvedExpr . ' DESC, t.name ASC';

    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute(array_merge([$userId], $scopeFilters['params']));
    $userRows = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $profile = pc_build_benchmark_profile_filters($pdo, 'up');
    $profileSchema = $profile['profile_schema'];
    $benchWhere = [
        'e.' . pc_q($userIdCol) . ' <> ?',
        $scopeFilters['where_sql'],
    ];
    if (!empty($profile['clauses'])) {
        $benchWhere = array_merge($benchWhere, $profile['clauses']);
    }

    $benchSql = 'SELECT '
        . 't.id AS id, '
        . $correctExpr . ' AS total_correct, '
        . $wrongExpr . ' AS total_wrong, '
        . 'COUNT(DISTINCT e.' . pc_q($userIdCol) . ') AS participants_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'INNER JOIN topics t ON t.id = e.' . pc_q($topicIdCol) . ' '
        . 'INNER JOIN `' . $profileSchema['table'] . '` up ON up.' . pc_q((string)$profileSchema['id']) . ' = e.' . pc_q($userIdCol) . ' '
        . 'WHERE ' . implode(' AND ', $benchWhere) . ' '
        . 'GROUP BY t.id';

    $benchStmt = $pdo->prepare($benchSql);
    $benchStmt->execute(array_merge([$userId], $scopeFilters['params']));
    $benchRows = $benchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $benchMap = [];
    foreach ($benchRows as $row) {
        $id = (string)($row['id'] ?? '');
        $correct = pc_to_int($row['total_correct'] ?? 0);
        $wrong = pc_to_int($row['total_wrong'] ?? 0);
        $benchMap[$id] = [
            'benchmark_success_rate' => pc_calc_success_rate($correct, $wrong),
            'participants_count' => pc_to_int($row['participants_count'] ?? 0),
        ];
    }

    $result = [];
    foreach ($userRows as $row) {
        $id = (string)($row['id'] ?? '');
        $correct = pc_to_int($row['correct_count'] ?? 0);
        $wrong = pc_to_int($row['wrong_count'] ?? 0);
        $solved = $correct + $wrong;
        $userRate = pc_calc_success_rate($correct, $wrong);

        $benchmarkRate = null;
        $participants = 0;
        if (isset($benchMap[$id])) {
            $benchmarkRate = pc_to_float($benchMap[$id]['benchmark_success_rate'] ?? 0);
            $participants = pc_to_int($benchMap[$id]['participants_count'] ?? 0);
        }

        $result[] = [
            'id' => $id,
            'name' => (string)($row['name'] ?? ''),
            'solved_count' => $solved,
            'correct_count' => $correct,
            'wrong_count' => $wrong,
            'success_rate' => $userRate,
            'benchmark_success_rate' => $benchmarkRate,
            'delta_vs_benchmark' => pc_safe_delta($userRate, $benchmarkRate),
            'participants_count' => $participants,
        ];
    }

    return $result;
}

function pc_fetch_trend_points(PDO $pdo, string $userId, array $eventSchema, array $scopeFilters, array $window): array
{
    $userIdCol = $eventSchema['user_id'];
    $isCorrectCol = $eventSchema['is_correct'];
    $attemptedAtCol = $eventSchema['attempted_at'];
    $correctExpr = pc_sql_correct_count_expr('e', $isCorrectCol);
    $wrongExpr = pc_sql_wrong_count_expr('e', $isCorrectCol);
    $solvedExpr = pc_sql_solved_count_expr('e');
    $successExpr = pc_sql_success_rate_expr('e', $isCorrectCol);

    $userSql = 'SELECT '
        . 'DATE(e.' . pc_q($attemptedAtCol) . ') AS d, '
        . $correctExpr . ' AS correct_count, '
        . $wrongExpr . ' AS wrong_count '
        . 'FROM `' . $eventSchema['table'] . '` e '
        . 'WHERE e.' . pc_q($userIdCol) . ' = ? AND ' . $scopeFilters['where_sql'] . ' '
        . 'GROUP BY DATE(e.' . pc_q($attemptedAtCol) . ')';

    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute(array_merge([$userId], $scopeFilters['params']));
    $userRows = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $userMap = [];
    foreach ($userRows as $row) {
        $date = (string)($row['d'] ?? '');
        if ($date === '') {
            continue;
        }
        $correct = pc_to_int($row['correct_count'] ?? 0);
        $wrong = pc_to_int($row['wrong_count'] ?? 0);
        $userMap[$date] = [
            'user_solved_count' => $correct + $wrong,
            'user_success_rate' => pc_calc_success_rate($correct, $wrong),
        ];
    }

    $profile = pc_build_benchmark_profile_filters($pdo, 'up');
    $profileSchema = $profile['profile_schema'];
    $benchWhere = [
        'e.' . pc_q($userIdCol) . ' <> ?',
        $scopeFilters['where_sql'],
    ];
    if (!empty($profile['clauses'])) {
        $benchWhere = array_merge($benchWhere, $profile['clauses']);
    }

    $benchSql = 'SELECT '
        . 'x.d, '
        . 'ROUND(AVG(x.solved_count), 2) AS benchmark_avg_solved_count, '
        . 'ROUND(AVG(x.success_rate), 2) AS benchmark_success_rate '
        . 'FROM ( '
            . 'SELECT '
            . 'DATE(e.' . pc_q($attemptedAtCol) . ') AS d, '
            . 'e.' . pc_q($userIdCol) . ' AS uid, '
            . $solvedExpr . ' AS solved_count, '
            . $successExpr . ' AS success_rate '
            . 'FROM `' . $eventSchema['table'] . '` e '
            . 'INNER JOIN `' . $profileSchema['table'] . '` up ON up.' . pc_q((string)$profileSchema['id']) . ' = e.' . pc_q($userIdCol) . ' '
            . 'WHERE ' . implode(' AND ', $benchWhere) . ' '
            . 'GROUP BY DATE(e.' . pc_q($attemptedAtCol) . '), e.' . pc_q($userIdCol)
        . ') x '
        . 'GROUP BY x.d';

    $benchStmt = $pdo->prepare($benchSql);
    $benchStmt->execute(array_merge([$userId], $scopeFilters['params']));
    $benchRows = $benchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $benchMap = [];
    foreach ($benchRows as $row) {
        $date = (string)($row['d'] ?? '');
        if ($date === '') {
            continue;
        }
        $benchMap[$date] = [
            'benchmark_success_rate' => $row['benchmark_success_rate'] !== null ? pc_to_float($row['benchmark_success_rate']) : null,
            'benchmark_avg_solved_count' => pc_to_float($row['benchmark_avg_solved_count'] ?? 0),
        ];
    }

    $dates = [];
    if (!empty($window['is_all_time'])) {
        $dates = array_values(array_unique(array_merge(array_keys($userMap), array_keys($benchMap))));
        sort($dates);
    } else {
        $start = new DateTimeImmutable((string)$window['start_date']);
        $end = new DateTimeImmutable((string)$window['end_date']);
        $cursor = $start;
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
    }

    $result = [];
    foreach ($dates as $date) {
        $userPoint = $userMap[$date] ?? ['user_success_rate' => 0.0, 'user_solved_count' => 0];
        $benchPoint = $benchMap[$date] ?? ['benchmark_success_rate' => null, 'benchmark_avg_solved_count' => 0.0];

        $result[] = [
            'date' => $date,
            'user_success_rate' => pc_to_float($userPoint['user_success_rate'] ?? 0),
            'benchmark_success_rate' => $benchPoint['benchmark_success_rate'] !== null
                ? pc_to_float($benchPoint['benchmark_success_rate'])
                : null,
            'user_solved_count' => pc_to_int($userPoint['user_solved_count'] ?? 0),
            'benchmark_avg_solved_count' => pc_to_float($benchPoint['benchmark_avg_solved_count'] ?? 0),
        ];
    }

    return $result;
}

function pc_build_rank_label(?float $percentile): string
{
    if ($percentile === null) {
        return 'Yeterli veri yok';
    }
    if ($percentile >= 80) {
        return 'Çok Güçlü';
    }
    if ($percentile >= 60) {
        return 'Güçlü';
    }
    if ($percentile >= 40) {
        return 'Orta';
    }
    if ($percentile >= 20) {
        return 'Geliştirilmeli';
    }

    return 'Kritik Gelişim Alanı';
}

function pc_build_insights(array $userSummary, array $benchmarkSummary, array $items, array $trendPoints): array
{
    $strongest = $items;
    usort($strongest, static function (array $a, array $b): int {
        return (float)($b['delta_vs_benchmark'] ?? -9999) <=> (float)($a['delta_vs_benchmark'] ?? -9999);
    });

    $strongestComparable = array_values(array_filter($strongest, static function (array $item): bool {
        return (($item['delta_vs_benchmark'] ?? null) !== null);
    }));
    $strongestMapped = array_map(static function (array $item): array {
        return [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? '',
            'delta_vs_benchmark' => $item['delta_vs_benchmark'] ?? null,
            'success_rate' => $item['success_rate'] ?? 0,
        ];
    }, $strongestComparable);
    $strongest = array_slice($strongestMapped, 0, 3);

    $weakest = $items;
    usort($weakest, static function (array $a, array $b): int {
        return (float)($a['delta_vs_benchmark'] ?? 9999) <=> (float)($b['delta_vs_benchmark'] ?? 9999);
    });

    $weakestComparable = array_values(array_filter($weakest, static function (array $item): bool {
        return (($item['delta_vs_benchmark'] ?? null) !== null);
    }));
    $weakestMapped = array_map(static function (array $item): array {
        return [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? '',
            'delta_vs_benchmark' => $item['delta_vs_benchmark'] ?? null,
            'success_rate' => $item['success_rate'] ?? 0,
        ];
    }, $weakestComparable);
    $weakest = array_slice($weakestMapped, 0, 3);

    $summaryText = 'Bu aralıkta karşılaştırma için yeterli veri yok.';
    $focusText = 'Önce düzenli çözüm yaparak veri oluşmasını bekleyin.';
    $trendText = 'Trend üretilemedi.';

    $solved = pc_to_int($userSummary['solved_count'] ?? 0);
    $delta = $userSummary['delta_vs_benchmark'] ?? null;
    if ($solved <= 0) {
        $summaryText = 'Bu aralıkta çözüm verin yok. Insight üretilemedi.';
    } elseif ($delta === null) {
        $summaryText = 'Benchmark karşılaştırması için yeterli katılımcı bulunamadı.';
    } elseif ($delta >= 3) {
        $summaryText = 'Bu kapsamda benchmark ortalamasının üzerindesin.';
    } elseif ($delta <= -3) {
        $summaryText = 'Bu kapsamda benchmark ortalamasının altındasın.';
    } else {
        $summaryText = 'Benchmark ile benzer bir performans çizgisindesin.';
    }

    if (!empty($weakest)) {
        $focusText = 'En büyük gelişim alanı: ' . (string)($weakest[0]['name'] ?? '');
    } elseif (!empty($strongest)) {
        $focusText = 'Güçlü alanını koru: ' . (string)($strongest[0]['name'] ?? '');
    }

    $comparable = array_values(array_filter($trendPoints, static function (array $point): bool {
        return ($point['benchmark_success_rate'] ?? null) !== null;
    }));
    if (count($comparable) >= 2) {
        $first = $comparable[0];
        $last = $comparable[count($comparable) - 1];
        $firstGap = pc_safe_delta((float)($first['user_success_rate'] ?? 0), (float)($first['benchmark_success_rate'] ?? 0));
        $lastGap = pc_safe_delta((float)($last['user_success_rate'] ?? 0), (float)($last['benchmark_success_rate'] ?? 0));
        if ($firstGap !== null && $lastGap !== null) {
            if ($lastGap > $firstGap + 1) {
                $trendText = 'Son günlerde benchmarka yaklaşıyorsun.';
            } elseif ($lastGap < $firstGap - 1) {
                $trendText = 'Son günlerde benchmark ile fark açılıyor.';
            } else {
                $trendText = 'Trend dengeli ilerliyor.';
            }
        }
    }

    return [
        'strongest_items' => $strongest,
        'weakest_items' => $weakest,
        'summary_text' => $summaryText,
        'trend_text' => $trendText,
        'focus_text' => $focusText,
    ];
}

function pc_has_topics_for_course(PDO $pdo, ?string $courseId): bool
{
    $courseId = trim((string)$courseId);
    if ($courseId === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM topics WHERE course_id = ?');
    $stmt->execute([$courseId]);

    return ((int)$stmt->fetchColumn()) > 0;
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function rss_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function rss_first_col(array $columns, array $candidates, bool $required = true): ?string
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

function rss_normalize_topic_ids(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $seen = [];
    $topicIds = [];
    foreach (explode(',', $raw) as $item) {
        $topicId = trim($item);
        if ($topicId === '') {
            continue;
        }
        if (mb_strlen($topicId) > 191) {
            api_error('Geçersiz topic_ids.', 422);
        }
        if (!isset($seen[$topicId])) {
            $seen[$topicId] = true;
            $topicIds[] = $topicId;
        }
    }

    return $topicIds;
}

function rss_normalize_question_type(string $raw): string
{
    $value = trim($raw);
    if ($value === '') {
        return 'all';
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $ascii = str_replace(['ı', 'İ', 'ş', 'Ş'], ['i', 'i', 's', 's'], $lower);

    if (in_array($ascii, ['all', 'tum', 'tumu', 'hepsi'], true)) {
        return 'all';
    }
    if ($ascii === 'sayisal') {
        return 'sayısal';
    }
    if ($ascii === 'sozel') {
        return 'sözel';
    }

    api_error('question_type all / sayısal / sözel olabilir.', 422);
}

function rss_percent(int $correct, int $solved): float
{
    if ($solved <= 0) {
        return 0.0;
    }

    return round(($correct * 100.0) / $solved, 1);
}

function rss_status_text(?float $delta): string
{
    if ($delta === null) {
        return 'Karşılaştırma için yeterli veri yok';
    }
    if ($delta > 0) {
        return 'Platform ortalamasının üzerindesin';
    }
    if ($delta < 0) {
        return 'Platform ortalamasının altındasın';
    }

    return 'Platform ortalamasıyla aynı seviyedesin';
}

function rss_fetch_course(PDO $pdo, string $courseId, string $currentQualificationId): array
{
    $stmt = $pdo->prepare('SELECT id, name, qualification_id FROM courses WHERE id = ? LIMIT 1');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        api_error('course_id bulunamadı.', 404);
    }

    if ((string)($course['qualification_id'] ?? '') !== $currentQualificationId) {
        api_error('Bu ders için erişim yetkiniz yok.', 403);
    }

    return [
        'id' => (string)($course['id'] ?? ''),
        'name' => (string)($course['name'] ?? ''),
        'qualification_id' => (string)($course['qualification_id'] ?? ''),
    ];
}

function rss_fetch_topics(PDO $pdo, string $courseId, array $topicIds): array
{
    if (empty($topicIds)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($topicIds), '?'));
    $stmt = $pdo->prepare('SELECT id, name, course_id FROM topics WHERE id IN (' . $placeholders . ')');
    $stmt->execute($topicIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $found = [];
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        $found[$id] = [
            'id' => $id,
            'name' => (string)($row['name'] ?? ''),
            'course_id' => (string)($row['course_id'] ?? ''),
        ];
    }

    foreach ($topicIds as $topicId) {
        if (!isset($found[$topicId])) {
            api_error('topic_ids içinde bulunamayan konu var.', 404);
        }
        if ((string)$found[$topicId]['course_id'] !== $courseId) {
            api_error('topic_ids course_id ile uyumlu olmalıdır.', 422);
        }
    }

    $ordered = [];
    foreach ($topicIds as $topicId) {
        $ordered[] = $found[$topicId];
    }

    return $ordered;
}

function rss_schema(PDO $pdo): array
{
    $eventCols = get_table_columns($pdo, 'question_attempt_events');
    $questionCols = get_table_columns($pdo, 'questions');
    if (!$eventCols) {
        throw new RuntimeException('question_attempt_events tablosu okunamadı.');
    }
    if (!$questionCols) {
        throw new RuntimeException('questions tablosu okunamadı.');
    }

    return [
        'e_user_id' => rss_first_col($eventCols, ['user_id']),
        'e_question_id' => rss_first_col($eventCols, ['question_id']),
        'e_course_id' => rss_first_col($eventCols, ['course_id'], false),
        'e_topic_id' => rss_first_col($eventCols, ['topic_id'], false),
        'e_source' => rss_first_col($eventCols, ['source'], false),
        'e_is_correct' => rss_first_col($eventCols, ['is_correct']),
        'q_id' => rss_first_col($questionCols, ['id']),
        'q_course_id' => rss_first_col($questionCols, ['course_id']),
        'q_topic_id' => rss_first_col($questionCols, ['topic_id'], false),
        'q_question_type' => rss_first_col($questionCols, ['question_type'], false),
    ];
}

function rss_build_filters(array $schema, string $courseId, array $topicIds, string $questionType, string $source, array &$params, ?string $userId = null, bool $excludeUser = false): array
{
    $where = [];

    if ($userId !== null && !$excludeUser) {
        $where[] = 'e.' . rss_q($schema['e_user_id']) . ' = ?';
        $params[] = $userId;
    } elseif ($userId !== null && $excludeUser) {
        $where[] = 'e.' . rss_q($schema['e_user_id']) . ' <> ?';
        $params[] = $userId;
    }

    $courseExpr = $schema['e_course_id']
        ? 'COALESCE(e.' . rss_q($schema['e_course_id']) . ', q.' . rss_q($schema['q_course_id']) . ')'
        : 'q.' . rss_q($schema['q_course_id']);
    $where[] = $courseExpr . ' = ?';
    $params[] = $courseId;

    if (!empty($topicIds)) {
        if (!$schema['e_topic_id'] && !$schema['q_topic_id']) {
            api_error('Konu bazlı hesaplama için topic_id kolonu bulunamadı.', 422);
        }
        $topicExpr = $schema['e_topic_id'] && $schema['q_topic_id']
            ? 'COALESCE(e.' . rss_q($schema['e_topic_id']) . ', q.' . rss_q($schema['q_topic_id']) . ')'
            : ($schema['e_topic_id'] ? 'e.' . rss_q($schema['e_topic_id']) : 'q.' . rss_q($schema['q_topic_id']));
        $where[] = $topicExpr . ' IN (' . implode(', ', array_fill(0, count($topicIds), '?')) . ')';
        array_push($params, ...$topicIds);
    }

    if ($schema['e_source']) {
        $where[] = 'LOWER(TRIM(COALESCE(e.' . rss_q($schema['e_source']) . ', \'\'))) = ?';
        $params[] = $source;
    }

    $where[] = 'e.' . rss_q($schema['e_is_correct']) . ' IN (0, 1)';

    if ($questionType !== 'all') {
        if (!$schema['q_question_type']) {
            api_error('question_type filtresi bu sistemde desteklenmiyor.', 422);
        }
        $where[] = 'LOWER(TRIM(q.' . rss_q($schema['q_question_type']) . ')) = ?';
        $params[] = $questionType;
    }

    return $where;
}

function rss_fetch_user_summary(PDO $pdo, array $schema, string $userId, string $courseId, array $topicIds, string $questionType, string $source): array
{
    $params = [];
    $where = rss_build_filters($schema, $courseId, $topicIds, $questionType, $source, $params, $userId, false);

    $sql = 'SELECT COUNT(*) AS total_solved, '
        . 'COALESCE(SUM(CASE WHEN e.' . rss_q($schema['e_is_correct']) . ' = 1 THEN 1 ELSE 0 END), 0) AS total_correct, '
        . 'COALESCE(SUM(CASE WHEN e.' . rss_q($schema['e_is_correct']) . ' = 0 THEN 1 ELSE 0 END), 0) AS total_wrong '
        . 'FROM question_attempt_events e '
        . 'LEFT JOIN questions q ON q.' . rss_q($schema['q_id']) . ' = e.' . rss_q($schema['e_question_id']) . ' '
        . 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $solved = (int)($row['total_solved'] ?? 0);
    $correct = (int)($row['total_correct'] ?? 0);
    $wrong = (int)($row['total_wrong'] ?? 0);

    return [
        'total_solved' => $solved,
        'total_correct' => $correct,
        'total_wrong' => $wrong,
        'success_rate' => rss_percent($correct, $solved),
    ];
}

function rss_fetch_platform_summary(PDO $pdo, array $schema, string $currentUserId, string $courseId, array $topicIds, string $questionType, string $source): array
{
    $params = [];
    $where = rss_build_filters($schema, $courseId, $topicIds, $questionType, $source, $params, $currentUserId, true);
    $userCol = 'e.' . rss_q($schema['e_user_id']);
    $correctExpr = 'COALESCE(SUM(CASE WHEN e.' . rss_q($schema['e_is_correct']) . ' = 1 THEN 1 ELSE 0 END), 0)';
    $wrongExpr = 'COALESCE(SUM(CASE WHEN e.' . rss_q($schema['e_is_correct']) . ' = 0 THEN 1 ELSE 0 END), 0)';

    $sql = 'SELECT COUNT(*) AS participants_count, '
        . 'ROUND(AVG(user_stats.success_rate), 1) AS avg_success_rate, '
        . 'ROUND(AVG(user_stats.solved_count), 1) AS avg_solved_count '
        . 'FROM ( '
        . 'SELECT ' . $userCol . ' AS user_id, '
        . 'COUNT(*) AS solved_count, '
        . $correctExpr . ' AS correct_count, '
        . $wrongExpr . ' AS wrong_count, '
        . 'ROUND((' . $correctExpr . ' * 100.0) / NULLIF(COUNT(*), 0), 1) AS success_rate '
        . 'FROM question_attempt_events e '
        . 'LEFT JOIN questions q ON q.' . rss_q($schema['q_id']) . ' = e.' . rss_q($schema['e_question_id']) . ' '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'GROUP BY ' . $userCol . ' '
        . ') user_stats';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $participants = (int)($row['participants_count'] ?? 0);
    if ($participants <= 0) {
        return [
            'participants_count' => 0,
            'avg_success_rate' => null,
            'avg_solved_count' => null,
        ];
    }

    return [
        'participants_count' => $participants,
        'avg_success_rate' => $row['avg_success_rate'] !== null ? round((float)$row['avg_success_rate'], 1) : null,
        'avg_solved_count' => $row['avg_solved_count'] !== null ? round((float)$row['avg_solved_count'], 1) : null,
    ];
}

try {
    $auth = api_require_auth($pdo);
    $currentUserId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study.result_scope_summary');

    $courseId = trim((string)($_GET['course_id'] ?? ''));
    if ($courseId === '') {
        api_error('course_id zorunludur.', 422);
    }
    if (mb_strlen($courseId) > 191) {
        api_error('Geçersiz course_id.', 422);
    }

    $source = strtolower(trim((string)($_GET['source'] ?? 'study')));
    if ($source === '') {
        $source = 'study';
    }
    if ($source !== 'study') {
        api_error('Bu endpoint sadece source=study için kullanılabilir.', 422);
    }

    $questionType = rss_normalize_question_type((string)($_GET['question_type'] ?? 'all'));
    $topicIds = rss_normalize_topic_ids((string)($_GET['topic_ids'] ?? ''));

    $course = rss_fetch_course($pdo, $courseId, $currentQualificationId);
    $topics = rss_fetch_topics($pdo, $courseId, $topicIds);
    $topicNames = array_map(static fn(array $topic): string => (string)$topic['name'], $topics);

    $schema = rss_schema($pdo);
    $userSummary = rss_fetch_user_summary($pdo, $schema, $currentUserId, $courseId, $topicIds, $questionType, $source);
    $platformSummary = rss_fetch_platform_summary($pdo, $schema, $currentUserId, $courseId, $topicIds, $questionType, $source);

    $delta = null;
    if ($platformSummary['avg_success_rate'] !== null) {
        $delta = round((float)$userSummary['success_rate'] - (float)$platformSummary['avg_success_rate'], 1);
    }

    if (empty($topicIds)) {
        $label = $course['name'];
        $description = 'Ders bazlı çalışma';
    } elseif (count($topicIds) === 1) {
        $label = $topicNames[0] ?? '';
        $description = 'Konu bazlı çalışma';
    } else {
        $label = count($topicIds) . ' konu seçildi';
        $description = 'Çoklu konu bazlı çalışma';
    }

    api_send_json([
        'success' => true,
        'data' => [
            'scope' => [
                'course_id' => $course['id'],
                'course_name' => $course['name'],
                'topic_ids' => $topicIds,
                'topic_names' => $topicNames,
                'question_type' => $questionType,
                'label' => $label,
                'description' => $description,
            ],
            'user_summary' => $userSummary,
            'platform_summary' => $platformSummary,
            'comparison' => [
                'delta_success_rate' => $delta,
                'status_text' => rss_status_text($delta),
            ],
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
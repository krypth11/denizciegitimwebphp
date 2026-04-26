<?php

require_once __DIR__ . '/auth_helper.php';

function study_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function study_pick_column(array $columns, array $candidates, bool $required = false): ?string
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

function study_get_column_meta(PDO $pdo, string $table, string $column): ?array
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
        $stmt->execute([$column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$key] = is_array($row) ? $row : null;
    } catch (Throwable $e) {
        $cache[$key] = null;
    }

    return $cache[$key];
}

function study_parse_enum_values(?string $dbType): array
{
    $type = strtolower(trim((string)$dbType));
    if ($type === '' || !str_starts_with($type, 'enum(')) {
        return [];
    }

    if (!preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches)) {
        return [];
    }

    $values = [];
    foreach ($matches[1] as $raw) {
        $values[] = strtoupper(str_replace("\\'", "'", $raw));
    }

    return array_values(array_unique($values));
}

function study_can_persist_selected_answer(PDO $pdo, array $schema, string $selectedAnswer): bool
{
    if (empty($schema['last_selected_answer'])) {
        return true;
    }

    $meta = study_get_column_meta($pdo, $schema['table'], $schema['last_selected_answer']);
    if (!$meta) {
        return true;
    }

    $enumValues = study_parse_enum_values($meta['Type'] ?? null);
    if (!$enumValues) {
        return true;
    }

    return in_array(strtoupper($selectedAnswer), $enumValues, true);
}

function study_get_user_progress_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'user_progress');
    if (!$cols) {
        throw new RuntimeException('user_progress tablosu okunamadı.');
    }

    return [
        'table' => 'user_progress',
        'id' => study_pick_column($cols, ['id'], false),
        'user_id' => study_pick_column($cols, ['user_id'], true),
        'question_id' => study_pick_column($cols, ['question_id'], true),
        'is_answered' => study_pick_column($cols, ['is_answered'], false),
        'is_correct' => study_pick_column($cols, ['is_correct'], false),
        'is_bookmarked' => study_pick_column($cols, ['is_bookmarked', 'bookmarked'], false),
        'total_answer_count' => study_pick_column($cols, ['total_answer_count', 'answer_count', 'total_answers'], false),
        'correct_answer_count' => study_pick_column($cols, ['correct_answer_count', 'correct_count'], false),
        'wrong_answer_count' => study_pick_column($cols, ['wrong_answer_count', 'wrong_count', 'incorrect_count'], false),
        'last_selected_answer' => study_pick_column($cols, ['last_selected_answer', 'selected_answer', 'last_answer'], false),
        'first_answered_at' => study_pick_column($cols, ['first_answered_at'], false),
        'last_answered_at' => study_pick_column($cols, ['last_answered_at'], false),
        'answered_at' => study_pick_column($cols, ['answered_at'], false),
        'updated_at' => study_pick_column($cols, ['updated_at'], false),
        'created_at' => study_pick_column($cols, ['created_at'], false),
    ];
}

function study_get_question_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'questions');
    if (!$cols) {
        throw new RuntimeException('questions tablosu okunamadı.');
    }

    return [
        'table' => 'questions',
        'id' => study_pick_column($cols, ['id'], true),
        'correct_answer' => study_pick_column($cols, ['correct_answer'], false),
        'option_e' => study_pick_column($cols, ['option_e'], false),
        'course_id' => study_pick_column($cols, ['course_id'], false),
        'topic_id' => study_pick_column($cols, ['topic_id'], false),
    ];
}

function study_get_course_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'courses');
    if (!$cols) {
        return [
            'table' => 'courses',
            'id' => null,
            'qualification_id' => null,
        ];
    }

    return [
        'table' => 'courses',
        'id' => study_pick_column($cols, ['id'], true),
        'qualification_id' => study_pick_column($cols, ['qualification_id'], false),
    ];
}

function study_get_question_meta(PDO $pdo, string $questionId): array
{
    $schema = study_get_question_schema($pdo);

    $select = [study_q($schema['id']) . ' AS id'];
    if ($schema['correct_answer']) {
        $select[] = study_q($schema['correct_answer']) . ' AS correct_answer';
    } else {
        $select[] = "'' AS correct_answer";
    }
    if ($schema['option_e']) {
        $select[] = study_q($schema['option_e']) . ' AS option_e';
    } else {
        $select[] = 'NULL AS option_e';
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . study_q($schema['table'])
        . ' WHERE ' . study_q($schema['id']) . ' = ? LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['exists' => false, 'correct_answer' => null, 'option_e' => null];
    }

    $correct = strtoupper(trim((string)($row['correct_answer'] ?? '')));
    return [
        'exists' => true,
        'correct_answer' => ($correct !== '' ? $correct : null),
        'option_e' => $row['option_e'] ?? null,
    ];
}

function study_get_question_meta_with_relations(PDO $pdo, string $questionId): array
{
    $qSchema = study_get_question_schema($pdo);
    $cSchema = study_get_course_schema($pdo);

    $select = [
        'q.' . study_q($qSchema['id']) . ' AS id',
        ($qSchema['correct_answer'] ? 'q.' . study_q($qSchema['correct_answer']) : "''") . ' AS correct_answer',
        ($qSchema['option_e'] ? 'q.' . study_q($qSchema['option_e']) : 'NULL') . ' AS option_e',
        ($qSchema['course_id'] ? 'q.' . study_q($qSchema['course_id']) : 'NULL') . ' AS course_id',
        ($qSchema['topic_id'] ? 'q.' . study_q($qSchema['topic_id']) : 'NULL') . ' AS topic_id',
    ];

    $joinCourses = '';
    if ($qSchema['course_id'] && $cSchema['id'] && $cSchema['qualification_id']) {
        $select[] = 'c.' . study_q($cSchema['qualification_id']) . ' AS qualification_id';
        $joinCourses = ' LEFT JOIN `' . $cSchema['table'] . '` c ON q.' . study_q($qSchema['course_id']) . ' = c.' . study_q($cSchema['id']) . ' ';
    } else {
        $select[] = 'NULL AS qualification_id';
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM `' . $qSchema['table'] . '` q '
        . $joinCourses
        . ' WHERE q.' . study_q($qSchema['id']) . ' = ? LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'exists' => false,
            'correct_answer' => null,
            'option_e' => null,
            'course_id' => null,
            'qualification_id' => null,
            'topic_id' => null,
        ];
    }

    $correct = strtoupper(trim((string)($row['correct_answer'] ?? '')));
    return [
        'exists' => true,
        'correct_answer' => ($correct !== '' ? $correct : null),
        'option_e' => $row['option_e'] ?? null,
        'course_id' => $row['course_id'] ?? null,
        'qualification_id' => $row['qualification_id'] ?? null,
        'topic_id' => $row['topic_id'] ?? null,
    ];
}

function study_get_wrong_score_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'user_question_wrong_scores');
    if (!$cols) {
        return [];
    }

    return [
        'table' => 'user_question_wrong_scores',
        'user_id' => study_pick_column($cols, ['user_id'], true),
        'question_id' => study_pick_column($cols, ['question_id'], true),
        'qualification_id' => study_pick_column($cols, ['qualification_id'], false),
        'course_id' => study_pick_column($cols, ['course_id'], false),
        'topic_id' => study_pick_column($cols, ['topic_id'], false),
        'wrong_score' => study_pick_column($cols, ['wrong_score'], true),
        'wrong_count' => study_pick_column($cols, ['wrong_count'], false),
        'correct_recovery_count' => study_pick_column($cols, ['correct_recovery_count'], false),
        'last_answered_at' => study_pick_column($cols, ['last_answered_at'], false),
        'last_wrong_at' => study_pick_column($cols, ['last_wrong_at'], false),
        'last_correct_at' => study_pick_column($cols, ['last_correct_at'], false),
        'updated_at' => study_pick_column($cols, ['updated_at'], false),
    ];
}

function study_update_question_wrong_score(PDO $pdo, array $payload): void
{
    $userId = trim((string)($payload['user_id'] ?? ''));
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $qualificationId = array_key_exists('qualification_id', $payload) ? $payload['qualification_id'] : null;
    $courseId = array_key_exists('course_id', $payload) ? $payload['course_id'] : null;
    $topicId = array_key_exists('topic_id', $payload) ? $payload['topic_id'] : null;
    $isCorrect = !empty($payload['is_correct']);

    if ($userId === '' || $questionId === '') {
        throw new RuntimeException('wrong score güncellemesi için user_id ve question_id zorunludur.');
    }

    $schema = study_get_wrong_score_schema($pdo);
    if (!$schema) {
        throw new RuntimeException('user_question_wrong_scores tablosu okunamadı.');
    }

    $insertCols = [
        $schema['user_id'],
        $schema['question_id'],
    ];
    $insertVals = ['?', '?'];
    $params = [$userId, $questionId];

    foreach (['qualification_id' => $qualificationId, 'course_id' => $courseId, 'topic_id' => $topicId] as $k => $value) {
        if (!empty($schema[$k])) {
            $insertCols[] = $schema[$k];
            $insertVals[] = '?';
            $params[] = $value;
        }
    }

    $insertCols[] = $schema['wrong_score'];
    $insertVals[] = '?';
    $params[] = $isCorrect ? 0 : 1;

    if (!empty($schema['wrong_count'])) {
        $insertCols[] = $schema['wrong_count'];
        $insertVals[] = '?';
        $params[] = $isCorrect ? 0 : 1;
    }
    if (!empty($schema['correct_recovery_count'])) {
        $insertCols[] = $schema['correct_recovery_count'];
        $insertVals[] = '?';
        $params[] = $isCorrect ? 1 : 0;
    }

    foreach (['last_answered_at', 'updated_at'] as $k) {
        if (!empty($schema[$k])) {
            $insertCols[] = $schema[$k];
            $insertVals[] = 'NOW()';
        }
    }

    if ($isCorrect) {
        if (!empty($schema['last_correct_at'])) {
            $insertCols[] = $schema['last_correct_at'];
            $insertVals[] = 'NOW()';
        }
    } else {
        if (!empty($schema['last_wrong_at'])) {
            $insertCols[] = $schema['last_wrong_at'];
            $insertVals[] = 'NOW()';
        }
    }

    $updates = [];
    foreach (['qualification_id', 'course_id', 'topic_id'] as $k) {
        if (!empty($schema[$k])) {
            $updates[] = study_q($schema[$k]) . ' = VALUES(' . study_q($schema[$k]) . ')';
        }
    }

    if ($isCorrect) {
        $updates[] = study_q($schema['wrong_score'])
            . ' = GREATEST(COALESCE(' . study_q($schema['wrong_score']) . ', 0) - 1, 0)';
        if (!empty($schema['correct_recovery_count'])) {
            $updates[] = study_q($schema['correct_recovery_count'])
                . ' = COALESCE(' . study_q($schema['correct_recovery_count']) . ', 0) + 1';
        }
        if (!empty($schema['last_correct_at'])) {
            $updates[] = study_q($schema['last_correct_at']) . ' = NOW()';
        }
    } else {
        $updates[] = study_q($schema['wrong_score']) . ' = COALESCE(' . study_q($schema['wrong_score']) . ', 0) + 1';
        if (!empty($schema['wrong_count'])) {
            $updates[] = study_q($schema['wrong_count'])
                . ' = COALESCE(' . study_q($schema['wrong_count']) . ', 0) + 1';
        }
        if (!empty($schema['last_wrong_at'])) {
            $updates[] = study_q($schema['last_wrong_at']) . ' = NOW()';
        }
    }

    if (!empty($schema['last_answered_at'])) {
        $updates[] = study_q($schema['last_answered_at']) . ' = NOW()';
    }
    if (!empty($schema['updated_at'])) {
        $updates[] = study_q($schema['updated_at']) . ' = NOW()';
    }

    $sql = 'INSERT INTO ' . study_q($schema['table'])
        . ' (' . implode(', ', array_map('study_q', $insertCols)) . ')'
        . ' VALUES (' . implode(', ', $insertVals) . ')'
        . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function study_get_attempt_event_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'question_attempt_events');
    if (!$cols) {
        return [];
    }

    return [
        'table' => 'question_attempt_events',
        'id' => study_pick_column($cols, ['id'], false),
        'user_id' => study_pick_column($cols, ['user_id'], true),
        'question_id' => study_pick_column($cols, ['question_id'], true),
        'course_id' => study_pick_column($cols, ['course_id'], false),
        'qualification_id' => study_pick_column($cols, ['qualification_id'], false),
        'topic_id' => study_pick_column($cols, ['topic_id'], false),
        'session_id' => study_pick_column($cols, ['session_id'], false),
        'source' => study_pick_column($cols, ['source'], false),
        'selected_answer' => study_pick_column($cols, ['selected_answer'], false),
        'is_correct' => study_pick_column($cols, ['is_correct'], false),
        'attempted_at' => study_pick_column($cols, ['attempted_at'], false),
        'created_at' => study_pick_column($cols, ['created_at'], false),
    ];
}

function study_insert_attempt_event(PDO $pdo, array $event): bool
{
    $schema = study_get_attempt_event_schema($pdo);
    if (!$schema) {
        return false;
    }

    $values = [];

    if ($schema['id']) {
        $values[$schema['id']] = (string)($event['id'] ?? generate_uuid());
    }

    $required = ['user_id', 'question_id'];
    foreach ($required as $key) {
        if (empty($schema[$key])) {
            return false;
        }
        $values[$schema[$key]] = (string)($event[$key] ?? '');
        if ($values[$schema[$key]] === '') {
            return false;
        }
    }

    if ($schema['course_id'] && array_key_exists('course_id', $event)) {
        $values[$schema['course_id']] = $event['course_id'];
    }
    if ($schema['qualification_id'] && array_key_exists('qualification_id', $event)) {
        $values[$schema['qualification_id']] = $event['qualification_id'];
    }
    if ($schema['topic_id'] && array_key_exists('topic_id', $event)) {
        $values[$schema['topic_id']] = $event['topic_id'];
    }
    if ($schema['session_id'] && array_key_exists('session_id', $event)) {
        $values[$schema['session_id']] = $event['session_id'];
    }
    if ($schema['source'] && array_key_exists('source', $event)) {
        $values[$schema['source']] = (string)$event['source'];
    }
    if ($schema['selected_answer'] && array_key_exists('selected_answer', $event)) {
        $values[$schema['selected_answer']] = (string)$event['selected_answer'];
    }
    if ($schema['is_correct'] && array_key_exists('is_correct', $event)) {
        $values[$schema['is_correct']] = !empty($event['is_correct']) ? 1 : 0;
    }

    $nowColumns = [];
    foreach (['attempted_at', 'created_at'] as $k) {
        if (!empty($schema[$k])) {
            $nowColumns[] = $schema[$k];
        }
    }

    study_insert_progress_row($pdo, $schema, $values, $nowColumns);
    return true;
}

function study_log_question_attempt_event(
    PDO $pdo,
    string $userId,
    string $questionId,
    string $selectedAnswer,
    bool $isCorrect,
    string $source = 'study',
    ?string $sessionId = null
): bool {
    $meta = study_get_question_meta_with_relations($pdo, $questionId);
    if (!$meta['exists']) {
        return false;
    }

    return study_insert_attempt_event($pdo, [
        'user_id' => $userId,
        'question_id' => $questionId,
        'course_id' => $meta['course_id'] ?? null,
        'qualification_id' => $meta['qualification_id'] ?? null,
        'topic_id' => $meta['topic_id'] ?? null,
        'session_id' => $sessionId,
        'source' => $source,
        'selected_answer' => $selectedAnswer,
        'is_correct' => $isCorrect,
    ]);
}

function study_get_progress_by_user_question(PDO $pdo, array $schema, string $userId, string $questionId): ?array
{
    $sql = 'SELECT * FROM ' . study_q($schema['table'])
        . ' WHERE ' . study_q($schema['user_id']) . ' = ? AND ' . study_q($schema['question_id']) . ' = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function study_progress_row_payload(array $row, array $schema): array
{
    $get = static function (string $key, $default = null) use ($row, $schema) {
        $col = $schema[$key] ?? null;
        if (!$col) {
            return $default;
        }
        return $row[$col] ?? $default;
    };

    return [
        'question_id' => (string)$get('question_id', ''),
        'is_answered' => ((int)$get('is_answered', 0)) === 1,
        'is_correct' => ((int)$get('is_correct', 0)) === 1,
        'is_bookmarked' => ((int)$get('is_bookmarked', 0)) === 1,
        'total_answer_count' => (int)$get('total_answer_count', 0),
        'correct_answer_count' => (int)$get('correct_answer_count', 0),
        'wrong_answer_count' => (int)$get('wrong_answer_count', 0),
        'last_selected_answer' => $get('last_selected_answer', null),
        'first_answered_at' => $get('first_answered_at', null),
        'last_answered_at' => $get('last_answered_at', null),
        'answered_at' => $get('answered_at', null),
        'updated_at' => $get('updated_at', null),
    ];
}

function study_get_progress_map(PDO $pdo, string $userId, array $questionIds): array
{
    if (!$questionIds) {
        return [];
    }

    $schema = study_get_user_progress_schema($pdo);
    $questionIds = array_values(array_unique($questionIds));
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

    $sql = 'SELECT * FROM ' . study_q($schema['table'])
        . ' WHERE ' . study_q($schema['user_id']) . ' = ?'
        . ' AND ' . study_q($schema['question_id']) . ' IN (' . $placeholders . ')';

    $params = array_merge([$userId], $questionIds);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $payload = study_progress_row_payload($row, $schema);
        $qid = $payload['question_id'];
        if ($qid !== '') {
            $map[$qid] = $payload;
        }
    }

    return $map;
}

function study_insert_progress_row(PDO $pdo, array $schema, array $values, array $nowColumns = []): void
{
    $cols = [];
    $holders = [];
    $params = [];

    foreach ($values as $col => $value) {
        $cols[] = study_q($col);
        $holders[] = '?';
        $params[] = $value;
    }

    foreach ($nowColumns as $col) {
        $cols[] = study_q($col);
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . study_q($schema['table'])
        . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function study_update_progress_row(PDO $pdo, array $schema, string $userId, string $questionId, array $values, array $nowColumns = []): void
{
    $set = [];
    $params = [];

    foreach ($values as $col => $value) {
        $set[] = study_q($col) . ' = ?';
        $params[] = $value;
    }

    foreach ($nowColumns as $col) {
        $set[] = study_q($col) . ' = NOW()';
    }

    $params[] = $userId;
    $params[] = $questionId;

    $sql = 'UPDATE ' . study_q($schema['table'])
        . ' SET ' . implode(', ', $set)
        . ' WHERE ' . study_q($schema['user_id']) . ' = ?'
        . ' AND ' . study_q($schema['question_id']) . ' = ?';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function study_upsert_answer_progress(PDO $pdo, string $userId, string $questionId, string $selectedAnswer, bool $isCorrect): array
{
    $schema = study_get_user_progress_schema($pdo);
    $existing = study_get_progress_by_user_question($pdo, $schema, $userId, $questionId);
    $canPersistSelectedAnswer = study_can_persist_selected_answer($pdo, $schema, $selectedAnswer);

    if ($existing) {
        $total = (int)($schema['total_answer_count'] ? ($existing[$schema['total_answer_count']] ?? 0) : 0);
        $correctCount = (int)($schema['correct_answer_count'] ? ($existing[$schema['correct_answer_count']] ?? 0) : 0);
        $wrongCount = (int)($schema['wrong_answer_count'] ? ($existing[$schema['wrong_answer_count']] ?? 0) : 0);

        $updateValues = [];
        if ($schema['is_answered']) {
            $updateValues[$schema['is_answered']] = 1;
        }
        if ($schema['is_correct']) {
            $updateValues[$schema['is_correct']] = $isCorrect ? 1 : 0;
        }
        if ($schema['last_selected_answer'] && $canPersistSelectedAnswer) {
            $updateValues[$schema['last_selected_answer']] = $selectedAnswer;
        }
        if ($schema['total_answer_count']) {
            $updateValues[$schema['total_answer_count']] = $total + 1;
        }
        if ($schema['correct_answer_count']) {
            $updateValues[$schema['correct_answer_count']] = $correctCount + ($isCorrect ? 1 : 0);
        }
        if ($schema['wrong_answer_count']) {
            $updateValues[$schema['wrong_answer_count']] = $wrongCount + ($isCorrect ? 0 : 1);
        }

        $nowColumns = [];
        foreach (['last_answered_at', 'answered_at', 'updated_at'] as $k) {
            if (!empty($schema[$k])) {
                $nowColumns[] = $schema[$k];
            }
        }

        if (!empty($schema['first_answered_at']) && empty($existing[$schema['first_answered_at']])) {
            $nowColumns[] = $schema['first_answered_at'];
        }

        study_update_progress_row($pdo, $schema, $userId, $questionId, $updateValues, $nowColumns);
    } else {
        $insertValues = [
            $schema['user_id'] => $userId,
            $schema['question_id'] => $questionId,
        ];

        if ($schema['id']) {
            $insertValues[$schema['id']] = generate_uuid();
        }
        if ($schema['is_answered']) {
            $insertValues[$schema['is_answered']] = 1;
        }
        if ($schema['is_correct']) {
            $insertValues[$schema['is_correct']] = $isCorrect ? 1 : 0;
        }
        if ($schema['is_bookmarked']) {
            $insertValues[$schema['is_bookmarked']] = 0;
        }
        if ($schema['last_selected_answer'] && $canPersistSelectedAnswer) {
            $insertValues[$schema['last_selected_answer']] = $selectedAnswer;
        }
        if ($schema['total_answer_count']) {
            $insertValues[$schema['total_answer_count']] = 1;
        }
        if ($schema['correct_answer_count']) {
            $insertValues[$schema['correct_answer_count']] = $isCorrect ? 1 : 0;
        }
        if ($schema['wrong_answer_count']) {
            $insertValues[$schema['wrong_answer_count']] = $isCorrect ? 0 : 1;
        }

        $nowColumns = [];
        foreach (['first_answered_at', 'last_answered_at', 'answered_at', 'created_at', 'updated_at'] as $k) {
            if (!empty($schema[$k])) {
                $nowColumns[] = $schema[$k];
            }
        }

        study_insert_progress_row($pdo, $schema, $insertValues, $nowColumns);
    }

    $fresh = study_get_progress_by_user_question($pdo, $schema, $userId, $questionId);
    if (!$fresh) {
        throw new RuntimeException('Progress kaydı alınamadı.');
    }

    return study_progress_row_payload($fresh, $schema);
}

function study_get_question_answer_history(PDO $pdo, string $userId, string $questionId): array
{
    $progressSchema = study_get_user_progress_schema($pdo);
    $progressRow = study_get_progress_by_user_question($pdo, $progressSchema, $userId, $questionId);

    $totalSolvedCount = 0;
    $correctCount = 0;
    $wrongCount = 0;
    $lastSelectedAnswer = null;
    $lastAnsweredAt = null;

    if ($progressRow) {
        if (!empty($progressSchema['total_answer_count'])) {
            $totalSolvedCount = (int)($progressRow[$progressSchema['total_answer_count']] ?? 0);
        }
        if (!empty($progressSchema['correct_answer_count'])) {
            $correctCount = (int)($progressRow[$progressSchema['correct_answer_count']] ?? 0);
        }
        if (!empty($progressSchema['wrong_answer_count'])) {
            $wrongCount = (int)($progressRow[$progressSchema['wrong_answer_count']] ?? 0);
        }
        if (!empty($progressSchema['last_selected_answer'])) {
            $rawLastSelectedAnswer = $progressRow[$progressSchema['last_selected_answer']] ?? null;
            $lastSelectedAnswer = ($rawLastSelectedAnswer === '' ? null : $rawLastSelectedAnswer);
        }
        if (!empty($progressSchema['last_answered_at'])) {
            $rawLastAnsweredAt = $progressRow[$progressSchema['last_answered_at']] ?? null;
            $lastAnsweredAt = ($rawLastAnsweredAt === '' ? null : $rawLastAnsweredAt);
        }
    }

    $wrongScore = 0;
    $wrongScoreSchema = study_get_wrong_score_schema($pdo);
    if (!empty($wrongScoreSchema)) {
        $selectCols = [
            study_q($wrongScoreSchema['wrong_score']) . ' AS wrong_score',
        ];

        if (!empty($wrongScoreSchema['wrong_count'])) {
            $selectCols[] = study_q($wrongScoreSchema['wrong_count']) . ' AS wrong_count';
        }
        if (!empty($wrongScoreSchema['correct_recovery_count'])) {
            $selectCols[] = study_q($wrongScoreSchema['correct_recovery_count']) . ' AS correct_recovery_count';
        }

        $sql = 'SELECT ' . implode(', ', $selectCols)
            . ' FROM ' . study_q($wrongScoreSchema['table'])
            . ' WHERE ' . study_q($wrongScoreSchema['user_id']) . ' = ?'
            . ' AND ' . study_q($wrongScoreSchema['question_id']) . ' = ?'
            . ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $questionId]);
        $wrongScoreRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($wrongScoreRow) {
            $wrongScore = (int)($wrongScoreRow['wrong_score'] ?? 0);
        }
    }

    $isFirstTime = ($totalSolvedCount <= 1);
    $successRate = null;
    if ($totalSolvedCount > 0) {
        $successRate = (int)round(($correctCount * 100) / $totalSolvedCount);
    }

    if ($totalSolvedCount === 1) {
        $message = 'Bu soruyu ilk kez çözdünüz.';
    } elseif ($totalSolvedCount > 1) {
        $message = 'Bu soruyu toplam ' . $totalSolvedCount . ' kez çözdünüz. '
            . $correctCount . ' doğru, ' . $wrongCount . ' yanlış yaptınız.';
        if ($successRate !== null) {
            $message .= ' Başarı oranınız %' . $successRate . '.';
        }
    } else {
        $message = 'Bu soruya henüz çözüm kaydınız bulunmuyor.';
    }

    if ($wrongScore > 0) {
        $message .= ' Güncel yanlış puanı: ' . $wrongScore . '.';
    }

    return [
        'is_first_time' => $isFirstTime,
        'total_solved_count' => $totalSolvedCount,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'success_rate' => $successRate,
        'wrong_score' => $wrongScore,
        'last_selected_answer' => $lastSelectedAnswer,
        'last_answered_at' => $lastAnsweredAt,
        'message' => $message,
    ];
}

function study_toggle_bookmark(PDO $pdo, string $userId, string $questionId): array
{
    $schema = study_get_user_progress_schema($pdo);
    $existing = study_get_progress_by_user_question($pdo, $schema, $userId, $questionId);

    if (!$schema['is_bookmarked']) {
        throw new RuntimeException('is_bookmarked kolonu bulunamadı.');
    }

    if ($existing) {
        $current = ((int)($existing[$schema['is_bookmarked']] ?? 0)) === 1;
        $next = $current ? 0 : 1;

        $nowColumns = [];
        if (!empty($schema['updated_at'])) {
            $nowColumns[] = $schema['updated_at'];
        }

        study_update_progress_row($pdo, $schema, $userId, $questionId, [
            $schema['is_bookmarked'] => $next,
        ], $nowColumns);
    } else {
        $insertValues = [
            $schema['user_id'] => $userId,
            $schema['question_id'] => $questionId,
            $schema['is_bookmarked'] => 1,
        ];

        if ($schema['id']) {
            $insertValues[$schema['id']] = generate_uuid();
        }
        if ($schema['is_answered']) {
            $insertValues[$schema['is_answered']] = 0;
        }
        if ($schema['is_correct']) {
            $insertValues[$schema['is_correct']] = 0;
        }
        if ($schema['total_answer_count']) {
            $insertValues[$schema['total_answer_count']] = 0;
        }
        if ($schema['correct_answer_count']) {
            $insertValues[$schema['correct_answer_count']] = 0;
        }
        if ($schema['wrong_answer_count']) {
            $insertValues[$schema['wrong_answer_count']] = 0;
        }

        $nowColumns = [];
        foreach (['created_at', 'updated_at'] as $k) {
            if (!empty($schema[$k])) {
                $nowColumns[] = $schema[$k];
            }
        }

        study_insert_progress_row($pdo, $schema, $insertValues, $nowColumns);
    }

    $fresh = study_get_progress_by_user_question($pdo, $schema, $userId, $questionId);
    if (!$fresh) {
        throw new RuntimeException('Bookmark güncellemesi doğrulanamadı.');
    }

    return [
        'question_id' => $questionId,
        'is_bookmarked' => ((int)($fresh[$schema['is_bookmarked']] ?? 0)) === 1,
    ];
}

function study_set_bookmark_state(PDO $pdo, string $userId, string $questionId, bool $isBookmarked): array
{
    $schema = study_get_user_progress_schema($pdo);
    $existing = study_get_progress_by_user_question($pdo, $schema, $userId, $questionId);

    if (!$schema['is_bookmarked']) {
        throw new RuntimeException('is_bookmarked kolonu bulunamadı.');
    }

    $bookmarkValue = $isBookmarked ? 1 : 0;

    if ($existing) {
        $nowColumns = [];
        if (!empty($schema['updated_at'])) {
            $nowColumns[] = $schema['updated_at'];
        }

        study_update_progress_row($pdo, $schema, $userId, $questionId, [
            $schema['is_bookmarked'] => $bookmarkValue,
        ], $nowColumns);
    } else {
        $insertValues = [
            $schema['user_id'] => $userId,
            $schema['question_id'] => $questionId,
            $schema['is_bookmarked'] => $bookmarkValue,
        ];

        if ($schema['id']) {
            $insertValues[$schema['id']] = generate_uuid();
        }
        if ($schema['is_answered']) {
            $insertValues[$schema['is_answered']] = 0;
        }
        if ($schema['is_correct']) {
            $insertValues[$schema['is_correct']] = 0;
        }
        if ($schema['total_answer_count']) {
            $insertValues[$schema['total_answer_count']] = 0;
        }
        if ($schema['correct_answer_count']) {
            $insertValues[$schema['correct_answer_count']] = 0;
        }
        if ($schema['wrong_answer_count']) {
            $insertValues[$schema['wrong_answer_count']] = 0;
        }

        $nowColumns = [];
        foreach (['created_at', 'updated_at'] as $k) {
            if (!empty($schema[$k])) {
                $nowColumns[] = $schema[$k];
            }
        }

        study_insert_progress_row($pdo, $schema, $insertValues, $nowColumns);
    }

    return [
        'question_id' => $questionId,
        'is_bookmarked' => $isBookmarked,
    ];
}

function study_insert_session(PDO $pdo, string $userId, array $payload): array
{
    $cols = get_table_columns($pdo, 'study_sessions');
    if (!$cols) {
        throw new RuntimeException('study_sessions tablosu okunamadı.');
    }

    $idCol = study_pick_column($cols, ['id'], false);
    $userCol = study_pick_column($cols, ['user_id'], true);
    $createdAtCol = study_pick_column($cols, ['created_at'], false);

    $fieldMap = [
        'source_session_id' => study_pick_column($cols, ['source_session_id', 'offline_session_id', 'client_session_id', 'session_id'], false),
        'course_id' => study_pick_column($cols, ['course_id'], false),
        'qualification_id' => study_pick_column($cols, ['qualification_id'], false),
        'question_type' => study_pick_column($cols, ['question_type'], false),
        'pool_type' => study_pick_column($cols, ['pool_type'], false),
        'requested_question_count' => study_pick_column($cols, ['requested_question_count'], false),
        'served_question_count' => study_pick_column($cols, ['served_question_count'], false),
        'correct_count' => study_pick_column($cols, ['correct_count'], false),
        'wrong_count' => study_pick_column($cols, ['wrong_count'], false),
        'duration_seconds' => study_pick_column($cols, ['duration_seconds'], false),
    ];

    $insertValues = [$userCol => $userId];
    $sessionId = null;

    if ($idCol) {
        $sessionId = generate_uuid();
        $insertValues[$idCol] = $sessionId;
    }

    foreach ($fieldMap as $inputKey => $column) {
        if (!$column || !array_key_exists($inputKey, $payload)) {
            continue;
        }

        $value = $payload[$inputKey];
        if (in_array($inputKey, ['requested_question_count', 'served_question_count', 'correct_count', 'wrong_count', 'duration_seconds'], true)) {
            $value = (int)$value;
        } else {
            $value = is_string($value) ? trim($value) : $value;
        }

        $insertValues[$column] = $value;
    }

    $colsSql = [];
    $holdersSql = [];
    $params = [];

    foreach ($insertValues as $col => $val) {
        $colsSql[] = study_q($col);
        $holdersSql[] = '?';
        $params[] = $val;
    }

    if ($createdAtCol) {
        $colsSql[] = study_q($createdAtCol);
        $holdersSql[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . study_q('study_sessions')
        . ' (' . implode(', ', $colsSql) . ') VALUES (' . implode(', ', $holdersSql) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'id' => $sessionId ?: (string)$pdo->lastInsertId(),
        'summary' => [
            'source_session_id' => $payload['source_session_id'] ?? null,
            'course_id' => $payload['course_id'] ?? null,
            'qualification_id' => $payload['qualification_id'] ?? null,
            'question_type' => $payload['question_type'] ?? null,
            'pool_type' => $payload['pool_type'] ?? null,
            'requested_question_count' => isset($payload['requested_question_count']) ? (int)$payload['requested_question_count'] : null,
            'served_question_count' => isset($payload['served_question_count']) ? (int)$payload['served_question_count'] : null,
            'correct_count' => isset($payload['correct_count']) ? (int)$payload['correct_count'] : null,
            'wrong_count' => isset($payload['wrong_count']) ? (int)$payload['wrong_count'] : null,
            'duration_seconds' => isset($payload['duration_seconds']) ? (int)$payload['duration_seconds'] : null,
        ],
    ];
}

function study_insert_question_report(PDO $pdo, string $userId, string $questionId, string $reportText, $questionSnapshot = null): string
{
    $cols = get_table_columns($pdo, 'question_reports');
    if (!$cols) {
        throw new RuntimeException('question_reports tablosu bulunamadı.');
    }

    $idCol = study_pick_column($cols, ['id'], false);
    $userCol = study_pick_column($cols, ['user_id', 'reported_by_user_id', 'reporter_user_id'], true);
    $questionCol = study_pick_column($cols, ['question_id'], true);
    $reportTextCol = study_pick_column($cols, ['report_text', 'description', 'reason', 'message'], true);
    $snapshotCol = study_pick_column($cols, ['question_snapshot', 'snapshot', 'question_data'], false);
    $createdAtCol = study_pick_column($cols, ['created_at'], false);

    $insertValues = [
        $userCol => $userId,
        $questionCol => $questionId,
        $reportTextCol => $reportText,
    ];

    $reportId = null;
    if ($idCol) {
        $reportId = generate_uuid();
        $insertValues[$idCol] = $reportId;
    }

    if ($snapshotCol && $questionSnapshot !== null) {
        if (is_array($questionSnapshot) || is_object($questionSnapshot)) {
            $insertValues[$snapshotCol] = json_encode($questionSnapshot, JSON_UNESCAPED_UNICODE);
        } else {
            $insertValues[$snapshotCol] = (string)$questionSnapshot;
        }
    }

    $colsSql = [];
    $holdersSql = [];
    $params = [];

    foreach ($insertValues as $col => $val) {
        $colsSql[] = study_q($col);
        $holdersSql[] = '?';
        $params[] = $val;
    }

    if ($createdAtCol) {
        $colsSql[] = study_q($createdAtCol);
        $holdersSql[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . study_q('question_reports')
        . ' (' . implode(', ', $colsSql) . ') VALUES (' . implode(', ', $holdersSql) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $reportId ?: (string)$pdo->lastInsertId();
}

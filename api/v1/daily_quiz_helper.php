<?php

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/study_helper.php';

function dq_log_attempt_event(PDO $pdo, string $userId, array $event): bool
{
    $questionId = trim((string)($event['question_id'] ?? ''));
    if ($questionId === '') {
        return false;
    }

    return study_insert_attempt_event($pdo, [
        'user_id' => $userId,
        'question_id' => $questionId,
        'course_id' => $event['course_id'] ?? null,
        'qualification_id' => $event['qualification_id'] ?? null,
        'topic_id' => $event['topic_id'] ?? null,
        'session_id' => $event['session_id'] ?? null,
        'source' => 'daily_quiz',
        'selected_answer' => $event['selected_answer'] ?? null,
        'is_correct' => !empty($event['is_correct']),
    ]);
}

function dq_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function dq_pick_column(array $columns, array $candidates, bool $required = false): ?string
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

function dq_get_question_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'questions');
    if (!$cols) {
        throw new RuntimeException('questions tablosu okunamadı.');
    }

    return [
        'table' => 'questions',
        'id' => dq_pick_column($cols, ['id'], true),
        'course_id' => dq_pick_column($cols, ['course_id'], false),
        'topic_id' => dq_pick_column($cols, ['topic_id'], false),
        'question_type' => dq_pick_column($cols, ['question_type'], false),
        'question_text' => dq_pick_column($cols, ['question_text'], false),
        'option_a' => dq_pick_column($cols, ['option_a'], false),
        'option_b' => dq_pick_column($cols, ['option_b'], false),
        'option_c' => dq_pick_column($cols, ['option_c'], false),
        'option_d' => dq_pick_column($cols, ['option_d'], false),
        'correct_answer' => dq_pick_column($cols, ['correct_answer'], false),
        'explanation' => dq_pick_column($cols, ['explanation'], false),
        'image_url' => dq_pick_column($cols, ['image_url'], false),
        'difficulty' => dq_pick_column($cols, ['difficulty'], false),
        'created_at' => dq_pick_column($cols, ['created_at'], false),
    ];
}

function dq_get_progress_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'daily_quiz_progress');
    if (!$cols) {
        throw new RuntimeException('daily_quiz_progress tablosu okunamadı.');
    }

    return [
        'table' => 'daily_quiz_progress',
        'id' => dq_pick_column($cols, ['id'], false),
        'user_id' => dq_pick_column($cols, ['user_id'], true),
        'quiz_date' => dq_pick_column($cols, ['quiz_date', 'date'], true),
        'correct_answers' => dq_pick_column($cols, ['correct_answers', 'correct_count'], false),
        'total_questions' => dq_pick_column($cols, ['total_questions', 'question_count'], false),
        'completed_at' => dq_pick_column($cols, ['completed_at'], false),
        'created_at' => dq_pick_column($cols, ['created_at'], false),
        'updated_at' => dq_pick_column($cols, ['updated_at'], false),
    ];
}

function dq_fetch_daily_questions(PDO $pdo, string $userId, int $limit): array
{
    $schema = dq_get_question_schema($pdo);
    $todaySeed = date('Y-m-d') . ':' . $userId;

    $select = [
        dq_q($schema['id']) . ' AS id',
        ($schema['course_id'] ? dq_q($schema['course_id']) : 'NULL') . ' AS course_id',
        ($schema['topic_id'] ? dq_q($schema['topic_id']) : 'NULL') . ' AS topic_id',
        ($schema['question_type'] ? dq_q($schema['question_type']) : "''") . ' AS question_type',
        ($schema['question_text'] ? dq_q($schema['question_text']) : "''") . ' AS question_text',
        ($schema['option_a'] ? dq_q($schema['option_a']) : "''") . ' AS option_a',
        ($schema['option_b'] ? dq_q($schema['option_b']) : "''") . ' AS option_b',
        ($schema['option_c'] ? dq_q($schema['option_c']) : "''") . ' AS option_c',
        ($schema['option_d'] ? dq_q($schema['option_d']) : "''") . ' AS option_d',
        ($schema['correct_answer'] ? dq_q($schema['correct_answer']) : "''") . ' AS correct_answer',
        ($schema['explanation'] ? dq_q($schema['explanation']) : "''") . ' AS explanation',
        ($schema['image_url'] ? dq_q($schema['image_url']) : 'NULL') . ' AS image_url',
        ($schema['difficulty'] ? dq_q($schema['difficulty']) : 'NULL') . ' AS difficulty',
        ($schema['created_at'] ? dq_q($schema['created_at']) : 'NULL') . ' AS created_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . dq_q($schema['table'])
        . ' ORDER BY SHA2(CONCAT(' . dq_q($schema['id']) . ', ?), 256) ASC'
        . ' LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$todaySeed]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function dq_fetch_today_progress(PDO $pdo, string $userId): ?array
{
    $schema = dq_get_progress_schema($pdo);
    $today = date('Y-m-d');

    $select = [
        dq_q($schema['quiz_date']) . ' AS quiz_date',
        ($schema['correct_answers'] ? dq_q($schema['correct_answers']) : '0') . ' AS correct_answers',
        ($schema['total_questions'] ? dq_q($schema['total_questions']) : '0') . ' AS total_questions',
        ($schema['completed_at'] ? dq_q($schema['completed_at']) : 'NULL') . ' AS completed_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . dq_q($schema['table'])
        . ' WHERE ' . dq_q($schema['user_id']) . ' = ?'
        . ' AND DATE(' . dq_q($schema['quiz_date']) . ') = ?'
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'quiz_date' => $row['quiz_date'] ?? $today,
        'correct_answers' => (int)($row['correct_answers'] ?? 0),
        'total_questions' => (int)($row['total_questions'] ?? 0),
        'completed_at' => $row['completed_at'] ?? null,
    ];
}

function dq_save_today_progress(PDO $pdo, string $userId, int $correctAnswers, int $totalQuestions): array
{
    $schema = dq_get_progress_schema($pdo);
    $today = date('Y-m-d');

    $findSql = 'SELECT * FROM ' . dq_q($schema['table'])
        . ' WHERE ' . dq_q($schema['user_id']) . ' = ?'
        . ' AND DATE(' . dq_q($schema['quiz_date']) . ') = ?'
        . ' LIMIT 1';

    $findStmt = $pdo->prepare($findSql);
    $findStmt->execute([$userId, $today]);
    $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $set = [];
        $params = [];

        if ($schema['correct_answers']) {
            $set[] = dq_q($schema['correct_answers']) . ' = ?';
            $params[] = $correctAnswers;
        }
        if ($schema['total_questions']) {
            $set[] = dq_q($schema['total_questions']) . ' = ?';
            $params[] = $totalQuestions;
        }
        if ($schema['completed_at']) {
            $set[] = dq_q($schema['completed_at']) . ' = NOW()';
        }
        if ($schema['updated_at']) {
            $set[] = dq_q($schema['updated_at']) . ' = NOW()';
        }

        $params[] = $userId;
        $params[] = $today;

        $updateSql = 'UPDATE ' . dq_q($schema['table'])
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . dq_q($schema['user_id']) . ' = ?'
            . ' AND DATE(' . dq_q($schema['quiz_date']) . ') = ?';

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);
    } else {
        $insertCols = [];
        $insertValues = [];
        $placeholders = [];

        if ($schema['id']) {
            $insertCols[] = dq_q($schema['id']);
            $placeholders[] = '?';
            $insertValues[] = generate_uuid();
        }

        $insertCols[] = dq_q($schema['user_id']);
        $placeholders[] = '?';
        $insertValues[] = $userId;

        $insertCols[] = dq_q($schema['quiz_date']);
        $placeholders[] = '?';
        $insertValues[] = $today;

        if ($schema['correct_answers']) {
            $insertCols[] = dq_q($schema['correct_answers']);
            $placeholders[] = '?';
            $insertValues[] = $correctAnswers;
        }
        if ($schema['total_questions']) {
            $insertCols[] = dq_q($schema['total_questions']);
            $placeholders[] = '?';
            $insertValues[] = $totalQuestions;
        }
        if ($schema['completed_at']) {
            $insertCols[] = dq_q($schema['completed_at']);
            $placeholders[] = 'NOW()';
        }
        if ($schema['created_at']) {
            $insertCols[] = dq_q($schema['created_at']);
            $placeholders[] = 'NOW()';
        }
        if ($schema['updated_at']) {
            $insertCols[] = dq_q($schema['updated_at']);
            $placeholders[] = 'NOW()';
        }

        $insertSql = 'INSERT INTO ' . dq_q($schema['table'])
            . ' (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute($insertValues);
    }

    $progress = dq_fetch_today_progress($pdo, $userId);
    if (!$progress) {
        throw new RuntimeException('Progress kaydı alınamadı.');
    }

    return $progress;
}

function dq_completed_today(PDO $pdo, string $userId): bool
{
    $progress = dq_fetch_today_progress($pdo, $userId);
    return $progress !== null;
}

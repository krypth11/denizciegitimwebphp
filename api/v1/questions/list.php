<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);

    $columns = get_table_columns($pdo, 'questions');
    if (!$columns) {
        api_error('Sorular tablosu bulunamadı.', 500);
    }

    $hasCol = static fn(string $col): bool => in_array($col, $columns, true);

    $courseId = trim((string)($_GET['course_id'] ?? ''));
    $topicId = trim((string)($_GET['topic_id'] ?? ''));
    $questionType = trim((string)($_GET['question_type'] ?? ''));
    $orderParam = strtolower(trim((string)($_GET['order'] ?? 'desc')));

    $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT, [
        'options' => ['default' => 50, 'min_range' => 1, 'max_range' => 200],
    ]);

    $order = in_array($orderParam, ['asc', 'desc'], true) ? strtoupper($orderParam) : 'DESC';

    $select = [
        $hasCol('id') ? 'id' : "'' AS id",
        $hasCol('topic_id') ? 'topic_id' : 'NULL AS topic_id',
        $hasCol('course_id') ? 'course_id' : 'NULL AS course_id',
        $hasCol('question_type') ? 'question_type' : "'' AS question_type",
        $hasCol('question_text') ? 'question_text' : "'' AS question_text",
        $hasCol('option_a') ? 'option_a' : "'' AS option_a",
        $hasCol('option_b') ? 'option_b' : "'' AS option_b",
        $hasCol('option_c') ? 'option_c' : "'' AS option_c",
        $hasCol('option_d') ? 'option_d' : "'' AS option_d",
        $hasCol('correct_answer') ? 'correct_answer' : "'' AS correct_answer",
        $hasCol('explanation') ? 'explanation' : "'' AS explanation",
        $hasCol('image_url') ? 'image_url' : 'NULL AS image_url',
        $hasCol('difficulty') ? 'difficulty' : 'NULL AS difficulty',
        $hasCol('created_at') ? 'created_at' : 'NULL AS created_at',
    ];

    $where = [];
    $params = [];

    if ($courseId !== '') {
        if (mb_strlen($courseId) > 191) {
            api_error('Geçersiz course_id.', 422);
        }
        if ($hasCol('course_id')) {
            $where[] = 'course_id = ?';
            $params[] = $courseId;
        }
    }

    if ($topicId !== '') {
        if (mb_strlen($topicId) > 191) {
            api_error('Geçersiz topic_id.', 422);
        }
        if ($hasCol('topic_id')) {
            $where[] = 'topic_id = ?';
            $params[] = $topicId;
        }
    }

    if ($questionType !== '') {
        if (mb_strlen($questionType) > 50) {
            api_error('Geçersiz question_type.', 422);
        }
        if ($hasCol('question_type')) {
            $where[] = 'question_type = ?';
            $params[] = $questionType;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM questions';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $orderBy = $hasCol('created_at') ? 'created_at' : ($hasCol('id') ? 'id' : '1');
    $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $questions = array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'topic_id' => $row['topic_id'] ?? null,
            'course_id' => $row['course_id'] ?? null,
            'question_type' => (string)($row['question_type'] ?? ''),
            'question_text' => (string)($row['question_text'] ?? ''),
            'option_a' => (string)($row['option_a'] ?? ''),
            'option_b' => (string)($row['option_b'] ?? ''),
            'option_c' => (string)($row['option_c'] ?? ''),
            'option_d' => (string)($row['option_d'] ?? ''),
            'correct_answer' => (string)($row['correct_answer'] ?? ''),
            'explanation' => (string)($row['explanation'] ?? ''),
            'image_url' => $row['image_url'] ?? null,
            'difficulty' => $row['difficulty'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $rows);

    api_success('Soru listesi getirildi.', [
        'questions' => $questions,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

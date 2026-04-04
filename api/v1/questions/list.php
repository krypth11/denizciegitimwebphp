<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'questions.list');

    $columns = get_table_columns($pdo, 'questions');
    if (!$columns) {
        api_error('Sorular tablosu bulunamadı.', 500);
    }

    $hasCol = static fn(string $col): bool => in_array($col, $columns, true);

    $courseId = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id', 191);
    $topicId = api_validate_optional_id((string)($_GET['topic_id'] ?? ''), 'topic_id', 191);
    $questionType = trim((string)($_GET['question_type'] ?? ''));
    $orderParam = strtolower(trim((string)($_GET['order'] ?? 'desc')));

    $limit = api_get_int_query('limit', 200, 1, 10000);

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
        $hasCol('option_e') ? 'option_e' : 'NULL AS option_e',
        $hasCol('correct_answer') ? 'correct_answer' : "'' AS correct_answer",
        $hasCol('explanation') ? 'explanation' : "'' AS explanation",
        $hasCol('image_url') ? 'image_url' : 'NULL AS image_url',
        $hasCol('difficulty') ? 'difficulty' : 'NULL AS difficulty',
        $hasCol('created_at') ? 'created_at' : 'NULL AS created_at',
    ];

    $where = [];
    $params = [];

    $qualificationFilterApplied = false;
    if ($hasCol('qualification_id')) {
        $where[] = 'qualification_id = ?';
        $params[] = $currentQualificationId;
        $qualificationFilterApplied = true;
    }

    if ($hasCol('course_id')) {
        if ($courseId !== '') {
            $courseGuardStmt = $pdo->prepare('SELECT qualification_id FROM courses WHERE id = ? LIMIT 1');
            $courseGuardStmt->execute([$courseId]);
            $courseGuardRow = $courseGuardStmt->fetch(PDO::FETCH_ASSOC);
            if (!$courseGuardRow) {
                api_error('Kurs bulunamadı.', 404);
            }
            api_assert_requested_qualification_matches_current(
                $pdo,
                $auth,
                (string)($courseGuardRow['qualification_id'] ?? ''),
                'questions.list.course_guard'
            );
        }

        if (!$qualificationFilterApplied) {
            $where[] = 'course_id IN (SELECT id FROM courses WHERE qualification_id = ?)';
            $params[] = $currentQualificationId;
            $qualificationFilterApplied = true;
        }
    }

    if ($topicId !== '' && $hasCol('topic_id')) {
        $topicGuardStmt = $pdo->prepare(
            'SELECT c.qualification_id
             FROM topics t
             INNER JOIN courses c ON t.course_id = c.id
             WHERE t.id = ?
             LIMIT 1'
        );
        $topicGuardStmt->execute([$topicId]);
        $topicGuardRow = $topicGuardStmt->fetch(PDO::FETCH_ASSOC);
        if (!$topicGuardRow) {
            api_error('Konu bulunamadı.', 404);
        }
        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            (string)($topicGuardRow['qualification_id'] ?? ''),
            'questions.list.topic_guard'
        );
    }

    if ($courseId !== '') {
        if ($hasCol('course_id')) {
            $where[] = 'course_id = ?';
            $params[] = $courseId;
        }
    }

    if ($topicId !== '') {
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
            'option_e' => $row['option_e'] ?? null,
            'correct_answer' => (string)($row['correct_answer'] ?? ''),
            'explanation' => (string)($row['explanation'] ?? ''),
            'image_url' => $row['image_url'] ?? null,
            'difficulty' => $row['difficulty'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $rows);

    api_qualification_access_log('study qualifications returned count', [
        'context' => 'questions.list',
        'count' => count($questions),
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_success('Soru listesi getirildi.', [
        'questions' => $questions,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

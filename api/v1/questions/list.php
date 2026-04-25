<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once dirname(__DIR__, 2) . '/includes/app_runtime_settings_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'questions.list');

    $columns = get_table_columns($pdo, 'questions');
    if (!$columns) {
        api_error('Sorular tablosu bulunamadı.', 500);
    }

    $hasCol = static fn(string $col): bool => in_array($col, $columns, true);

    $courseId = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id', 191);
    $topicId = api_validate_optional_id((string)($_GET['topic_id'] ?? ''), 'topic_id', 191);
    $questionType = trim((string)($_GET['question_type'] ?? ''));
    $poolType = strtolower(trim((string)($_GET['pool_type'] ?? '')));
    $orderParam = strtolower(trim((string)($_GET['order'] ?? 'desc')));

    $allQuestionsPoolTypes = ['all', 'all_questions', 'all-questions', 'tum_sorular', 'tum-sorular'];
    if (in_array($poolType, $allQuestionsPoolTypes, true)) {
        $runtime = app_runtime_settings_get($pdo);
        $allQuestionsMaxLimit = app_runtime_settings_int($runtime, 'study_all_questions_max_limit', 100);
        $limit = api_get_int_query('limit', $allQuestionsMaxLimit, 1, $allQuestionsMaxLimit);
    } else {
        $limit = api_get_int_query('limit', 200, 1, 10000);
    }

    $order = in_array($orderParam, ['asc', 'desc'], true) ? strtoupper($orderParam) : 'DESC';

    $q = 'q';
    $qc = static fn(string $col): string => $q . '.`' . str_replace('`', '', $col) . '`';
    $select = [
        $hasCol('id') ? ($qc('id') . ' AS id') : "'' AS id",
        $hasCol('topic_id') ? ($qc('topic_id') . ' AS topic_id') : 'NULL AS topic_id',
        $hasCol('course_id') ? ($qc('course_id') . ' AS course_id') : 'NULL AS course_id',
        $hasCol('question_type') ? ($qc('question_type') . ' AS question_type') : "'' AS question_type",
        $hasCol('question_text') ? ($qc('question_text') . ' AS question_text') : "'' AS question_text",
        $hasCol('option_a') ? ($qc('option_a') . ' AS option_a') : "'' AS option_a",
        $hasCol('option_b') ? ($qc('option_b') . ' AS option_b') : "'' AS option_b",
        $hasCol('option_c') ? ($qc('option_c') . ' AS option_c') : "'' AS option_c",
        $hasCol('option_d') ? ($qc('option_d') . ' AS option_d') : "'' AS option_d",
        $hasCol('option_e') ? ($qc('option_e') . ' AS option_e') : 'NULL AS option_e',
        $hasCol('correct_answer') ? ($qc('correct_answer') . ' AS correct_answer') : "'' AS correct_answer",
        $hasCol('explanation') ? ($qc('explanation') . ' AS explanation') : "'' AS explanation",
        $hasCol('image_url') ? ($qc('image_url') . ' AS image_url') : 'NULL AS image_url',
        $hasCol('difficulty') ? ($qc('difficulty') . ' AS difficulty') : 'NULL AS difficulty',
        $hasCol('created_at') ? ($qc('created_at') . ' AS created_at') : 'NULL AS created_at',
    ];

    $where = [];
    $params = [];

    $qualificationFilterApplied = false;
    if ($hasCol('qualification_id')) {
        $where[] = $qc('qualification_id') . ' = ?';
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
            $where[] = $qc('course_id') . ' IN (SELECT id FROM courses WHERE qualification_id = ?)';
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
            $where[] = $qc('course_id') . ' = ?';
            $params[] = $courseId;
        }
    }

    if ($topicId !== '') {
        if ($hasCol('topic_id')) {
            $where[] = $qc('topic_id') . ' = ?';
            $params[] = $topicId;
        }
    }

    if ($questionType !== '') {
        if (mb_strlen($questionType) > 50) {
            api_error('Geçersiz question_type.', 422);
        }
        if ($hasCol('question_type')) {
            $where[] = $qc('question_type') . ' = ?';
            $params[] = $questionType;
        }
    }

    $rows = [];
    if ($poolType === 'most_wrong') {
        $ws = study_get_wrong_score_schema($pdo);
        if ($ws && !empty($ws['user_id']) && !empty($ws['question_id']) && !empty($ws['wrong_score'])) {
            $select[] = 'COALESCE(ws.' . study_q($ws['wrong_score']) . ', 0) AS wrong_score';

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM questions ' . $q
                . ' INNER JOIN ' . study_q($ws['table']) . ' ws'
                . ' ON ws.' . study_q($ws['question_id']) . ' = ' . $qc('id')
                . ' AND ws.' . study_q($ws['user_id']) . ' = ?';

            $joinParams = [$userId];
            if (!empty($ws['qualification_id'])) {
                $sql .= ' AND ws.' . study_q($ws['qualification_id']) . ' = ?';
                $joinParams[] = $currentQualificationId;
            }
            $paramsMostWrong = array_merge($joinParams, $params);

            $whereMostWrong = $where;
            $whereMostWrong[] = 'COALESCE(ws.' . study_q($ws['wrong_score']) . ', 0) > 0';
            if ($whereMostWrong) {
                $sql .= ' WHERE ' . implode(' AND ', $whereMostWrong);
            }

            $orderByMostWrong = ['COALESCE(ws.' . study_q($ws['wrong_score']) . ', 0) DESC'];
            if (!empty($ws['last_answered_at'])) {
                $orderByMostWrong[] = 'ws.' . study_q($ws['last_answered_at']) . ' DESC';
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderByMostWrong);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsMostWrong);
            $rankedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $topRows = array_slice($rankedRows, 0, 50);
            $remainingRows = array_slice($rankedRows, 50);

            if (count($topRows) > 1) {
                shuffle($topRows);
            }
            if (count($remainingRows) > 1) {
                shuffle($remainingRows);
            }

            $rows = array_merge($topRows, $remainingRows);
            if ($limit > 0) {
                $rows = array_slice($rows, 0, $limit);
            }
        }
    } else {
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM questions ' . $q;
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $orderBy = $hasCol('created_at') ? $qc('created_at') : ($hasCol('id') ? $qc('id') : '1');
        $sql .= ' ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $questions = array_map(static function (array $row): array {
        $explanation = (string)($row['explanation'] ?? '');
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
            'explanation' => $explanation,
            'formatted_explanation' => format_explanation_text($explanation),
            'image_url' => $row['image_url'] ?? null,
            'difficulty' => $row['difficulty'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'wrong_score' => array_key_exists('wrong_score', $row) ? (int)$row['wrong_score'] : null,
        ];
    }, $rows);

    api_qualification_access_log('study qualifications returned count', [
        'context' => 'questions.list',
        'count' => count($questions),
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('study qualification returned', [
        'context' => 'questions.list',
        'study qualification returned' => $currentQualificationId,
    ]);

    api_success('Soru listesi getirildi.', [
        'questions' => $questions,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

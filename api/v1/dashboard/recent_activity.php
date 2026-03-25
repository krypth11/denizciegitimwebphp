<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

api_require_method('GET');

function ra_first_col(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function ra_detect_guest_sql(string $emailExpr, ?string $fullNameExpr): string
{
    $nameExpr = $fullNameExpr ? 'LOWER(TRIM(' . $fullNameExpr . '))' : "''";
    return '(LOWER(' . $emailExpr . ") LIKE '%@guest.local' OR " . $nameExpr . " IN ('misafir kullanıcı', 'misafir kullanici', 'guest user'))";
}

function ra_fetch_daily_quiz_questions(PDO $pdo, string $userId, string $quizDate, int $max = 50): array
{
    $aCols = get_table_columns($pdo, 'question_attempt_events');
    $qCols = get_table_columns($pdo, 'questions');
    if (empty($aCols) || empty($qCols)) {
        return [];
    }

    $aUser = ra_first_col($aCols, ['user_id']);
    $aQuestion = ra_first_col($aCols, ['question_id']);
    $aSelected = ra_first_col($aCols, ['selected_answer', 'answer', 'user_answer']);
    $aCorrectFlag = ra_first_col($aCols, ['is_correct']);
    $aAttempted = ra_first_col($aCols, ['attempted_at', 'created_at']);
    $aSource = ra_first_col($aCols, ['source']);

    $qId = ra_first_col($qCols, ['id']);
    $qCode = ra_first_col($qCols, ['question_code', 'code']);
    $qText = ra_first_col($qCols, ['question', 'question_text', 'text', 'content']);
    $qCorrect = ra_first_col($qCols, ['correct_answer', 'correct_option', 'answer']);

    if (!$aUser || !$aQuestion || !$aAttempted || !$qId) {
        return [];
    }

    $sql = 'SELECT e.`' . $aQuestion . '` AS question_id, '
        . ($qCode ? 'q.`' . $qCode . '`' : 'NULL') . ' AS question_code, '
        . ($qText ? 'q.`' . $qText . '`' : 'NULL') . ' AS question_text, '
        . ($aSelected ? 'e.`' . $aSelected . '`' : 'NULL') . ' AS selected_answer, '
        . ($qCorrect ? 'q.`' . $qCorrect . '`' : 'NULL') . ' AS correct_answer, '
        . ($aCorrectFlag ? 'e.`' . $aCorrectFlag . '`' : 'NULL') . ' AS is_correct '
        . 'FROM `question_attempt_events` e '
        . 'LEFT JOIN `questions` q ON e.`' . $aQuestion . '` = q.`' . $qId . '` '
        . 'WHERE e.`' . $aUser . '` = ? AND DATE(e.`' . $aAttempted . '`) = ? ';

    $params = [$userId, $quizDate];
    if ($aSource) {
        $sql .= 'AND LOWER(TRIM(COALESCE(e.`' . $aSource . "`, ''))) IN ('daily_quiz','daily-quiz','daily quiz') ";
    }

    $sql .= 'ORDER BY e.`' . $aAttempted . '` ASC LIMIT ' . max(1, min(100, $max));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = [];
    $order = 1;
    foreach ($rows as $row) {
        $selected = strtoupper(trim((string)($row['selected_answer'] ?? '')));
        $correct = strtoupper(trim((string)($row['correct_answer'] ?? '')));
        $isCorrect = isset($row['is_correct']) ? ((int)$row['is_correct'] === 1) : ($selected !== '' && $correct !== '' && $selected === $correct);

        $result[] = [
            'order_no' => $order++,
            'question_id' => (string)($row['question_id'] ?? ''),
            'question_code' => $row['question_code'] ?? null,
            'question_text' => $row['question_text'] ?? null,
            'selected_answer' => ($selected !== '' ? $selected : null),
            'correct_answer' => ($correct !== '' ? $correct : null),
            'is_correct' => $isCorrect,
        ];
    }

    return $result;
}

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    $allowedTypes = ['registrations', 'daily_quiz', 'solved_questions', 'profile_updates'];
    $types = stats_parse_types_from_query($allowedTypes);
    $limit = api_get_int_query('limit', 25, 10, 100);

    $rows = [];

    $uCols = get_table_columns($pdo, 'user_profiles');
    $uId = ra_first_col($uCols, ['id']);
    $uEmail = ra_first_col($uCols, ['email']);
    $uFullName = ra_first_col($uCols, ['full_name', 'name', 'display_name']);
    $uCreated = ra_first_col($uCols, ['created_at']);
    $uUpdated = ra_first_col($uCols, ['updated_at']);
    $uDeleted = ra_first_col($uCols, ['is_deleted']);
    $uCurrentQualification = ra_first_col($uCols, ['current_qualification_id', 'qualification_id']);
    $uEmailVerified = ra_first_col($uCols, ['email_verified']);

    if (in_array('registrations', $types, true) && $uId && $uCreated && $uEmail) {
        $guestExpr = ra_detect_guest_sql('u.`' . $uEmail . '`', $uFullName ? ('u.`' . $uFullName . '`') : null);
        $sql = 'SELECT u.`' . $uId . '` AS user_id, u.`' . $uEmail . '` AS email, '
            . ($uFullName ? 'u.`' . $uFullName . '`' : "''") . ' AS full_name, '
            . 'u.`' . $uCreated . '` AS created_at, '
            . ($uCurrentQualification ? 'u.`' . $uCurrentQualification . '`' : 'NULL') . ' AS current_qualification_id, '
            . ($uEmailVerified ? 'u.`' . $uEmailVerified . '`' : 'NULL') . ' AS email_verified, '
            . 'CASE WHEN ' . $guestExpr . " THEN 'guest' ELSE 'registered' END AS user_type "
            . 'FROM `user_profiles` u '
            . ($uDeleted ? 'WHERE u.`' . $uDeleted . '` = 0 ' : '')
            . 'ORDER BY u.`' . $uCreated . '` DESC LIMIT ' . (int)$limit;

        $list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as $item) {
            $rows[] = [
                'type' => 'registrations',
                'title' => 'Yeni kullanıcı kaydı',
                'subtitle' => trim((string)($item['full_name'] ?? '')) !== '' ? (string)$item['full_name'] : (string)($item['email'] ?? ''),
                'created_at' => $item['created_at'] ?? null,
                'user' => [
                    'id' => (string)($item['user_id'] ?? ''),
                    'email' => (string)($item['email'] ?? ''),
                    'full_name' => (string)($item['full_name'] ?? ''),
                    'user_type' => (string)($item['user_type'] ?? 'registered'),
                ],
                'detail' => [
                    'registration_at' => $item['created_at'] ?? null,
                    'qualification_name' => null,
                    'email_verified' => isset($item['email_verified']) ? ((int)$item['email_verified'] === 1) : null,
                ],
            ];
        }

        if ($uCurrentQualification) {
            $qualMap = [];
            foreach ($rows as $rowIndex => $rowItem) {
                if (($rowItem['type'] ?? '') !== 'registrations') {
                    continue;
                }
                $qid = (string)($list[$rowIndex]['current_qualification_id'] ?? '');
                if ($qid !== '') {
                    $qualMap[$qid] = true;
                }
            }

            if (!empty($qualMap)) {
                $ids = array_keys($qualMap);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $qStmt = $pdo->prepare('SELECT id, name FROM qualifications WHERE id IN (' . $placeholders . ')');
                $qStmt->execute($ids);
                $qRows = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $nameById = [];
                foreach ($qRows as $qr) {
                    $nameById[(string)$qr['id']] = (string)($qr['name'] ?? '');
                }

                foreach ($rows as $rowIndex => $rowItem) {
                    if (($rowItem['type'] ?? '') !== 'registrations') {
                        continue;
                    }
                    $qid = (string)($list[$rowIndex]['current_qualification_id'] ?? '');
                    if ($qid !== '' && isset($nameById[$qid])) {
                        $rows[$rowIndex]['detail']['qualification_name'] = $nameById[$qid];
                    }
                }
            }
        }
    }

    $aCols = get_table_columns($pdo, 'question_attempt_events');
    $aUserId = ra_first_col($aCols, ['user_id']);
    $aQuestionId = ra_first_col($aCols, ['question_id']);
    $aCorrect = ra_first_col($aCols, ['is_correct']);
    $aDate = ra_first_col($aCols, ['attempted_at', 'created_at']);
    $aSelected = ra_first_col($aCols, ['selected_answer', 'answer', 'user_answer']);

    $qCols = get_table_columns($pdo, 'questions');
    $qId = ra_first_col($qCols, ['id']);
    $qCourse = ra_first_col($qCols, ['course_id']);
    $qCode = ra_first_col($qCols, ['question_code', 'code']);
    $qText = ra_first_col($qCols, ['question', 'question_text', 'text', 'content']);
    $qOptionA = ra_first_col($qCols, ['option_a', 'a_option', 'answer_a']);
    $qOptionB = ra_first_col($qCols, ['option_b', 'b_option', 'answer_b']);
    $qOptionC = ra_first_col($qCols, ['option_c', 'c_option', 'answer_c']);
    $qOptionD = ra_first_col($qCols, ['option_d', 'd_option', 'answer_d']);
    $qOptionE = ra_first_col($qCols, ['option_e', 'e_option', 'answer_e'],);
    $qCorrect = ra_first_col($qCols, ['correct_answer', 'correct_option', 'answer']);

    $cCols = get_table_columns($pdo, 'courses');
    $cId = ra_first_col($cCols, ['id']);
    $cName = ra_first_col($cCols, ['name', 'title']);
    $cQual = ra_first_col($cCols, ['qualification_id']);

    $qualCols = get_table_columns($pdo, 'qualifications');
    $qualId = ra_first_col($qualCols, ['id']);
    $qualName = ra_first_col($qualCols, ['name', 'title']);

    if (in_array('solved_questions', $types, true) && $aUserId && $aQuestionId && $aDate && $uId && $uEmail) {
        $guestExpr = ra_detect_guest_sql('u.`' . $uEmail . '`', $uFullName ? ('u.`' . $uFullName . '`') : null);
        $sql = 'SELECT e.`' . $aDate . '` AS created_at, e.`' . $aQuestionId . '` AS question_id, '
            . ($aCorrect ? 'e.`' . $aCorrect . '`' : 'NULL') . ' AS is_correct, '
            . ($aSelected ? 'e.`' . $aSelected . '`' : 'NULL') . ' AS selected_answer, '
            . 'u.`' . $uId . '` AS user_id, u.`' . $uEmail . '` AS email, '
            . ($uFullName ? 'u.`' . $uFullName . '`' : "''") . ' AS full_name, '
            . 'CASE WHEN ' . $guestExpr . " THEN 'guest' ELSE 'registered' END AS user_type, "
            . ($qCode ? 'q.`' . $qCode . '`' : 'NULL') . ' AS question_code, '
            . ($qText ? 'q.`' . $qText . '`' : 'NULL') . ' AS question_text, '
            . ($qOptionA ? 'q.`' . $qOptionA . '`' : 'NULL') . ' AS option_a, '
            . ($qOptionB ? 'q.`' . $qOptionB . '`' : 'NULL') . ' AS option_b, '
            . ($qOptionC ? 'q.`' . $qOptionC . '`' : 'NULL') . ' AS option_c, '
            . ($qOptionD ? 'q.`' . $qOptionD . '`' : 'NULL') . ' AS option_d, '
            . ($qOptionE ? 'q.`' . $qOptionE . '`' : 'NULL') . ' AS option_e, '
            . ($qCorrect ? 'q.`' . $qCorrect . '`' : 'NULL') . ' AS correct_answer, '
            . ($cName ? 'c.`' . $cName . '`' : 'NULL') . ' AS course_name, '
            . (($qualName && $qualId && $cQual) ? 'qf.`' . $qualName . '`' : 'NULL') . ' AS qualification_name '
            . 'FROM `question_attempt_events` e '
            . 'LEFT JOIN `user_profiles` u ON e.`' . $aUserId . '` = u.`' . $uId . '` '
            . (($qId && $qCourse && $aQuestionId) ? 'LEFT JOIN `questions` q ON e.`' . $aQuestionId . '` = q.`' . $qId . '` ' : '')
            . (($cId && $qCourse) ? 'LEFT JOIN `courses` c ON q.`' . $qCourse . '` = c.`' . $cId . '` ' : '')
            . (($qualId && $qualName && $cQual) ? 'LEFT JOIN `qualifications` qf ON c.`' . $cQual . '` = qf.`' . $qualId . '` ' : '')
            . 'ORDER BY e.`' . $aDate . '` DESC LIMIT ' . (int)$limit;

        $list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as $item) {
            $correctLabel = isset($item['is_correct']) ? ((int)$item['is_correct'] === 1 ? 'Doğru' : 'Yanlış') : 'Bilinmiyor';
            $rows[] = [
                'type' => 'solved_questions',
                'title' => 'Soru çözüldü (' . $correctLabel . ')',
                'subtitle' => (string)($item['qualification_name'] ?? '-') . ' / ' . (string)($item['course_name'] ?? '-'),
                'created_at' => $item['created_at'] ?? null,
                'user' => [
                    'id' => (string)($item['user_id'] ?? ''),
                    'email' => (string)($item['email'] ?? ''),
                    'full_name' => (string)($item['full_name'] ?? ''),
                    'user_type' => (string)($item['user_type'] ?? 'registered'),
                ],
                'detail' => [
                    'question_id' => (string)($item['question_id'] ?? ''),
                    'question_code' => $item['question_code'] ?? null,
                    'question_text' => $item['question_text'] ?? null,
                    'option_a' => $item['option_a'] ?? null,
                    'option_b' => $item['option_b'] ?? null,
                    'option_c' => $item['option_c'] ?? null,
                    'option_d' => $item['option_d'] ?? null,
                    'option_e' => $item['option_e'] ?? null,
                    'correct_answer' => $item['correct_answer'] ?? null,
                    'selected_answer' => $item['selected_answer'] ?? null,
                    'is_correct' => isset($item['is_correct']) ? ((int)$item['is_correct'] === 1) : null,
                    'qualification_name' => (string)($item['qualification_name'] ?? ''),
                    'course_name' => (string)($item['course_name'] ?? ''),
                    'attempted_at' => $item['created_at'] ?? null,
                ],
            ];
        }
    }

    $dqCols = get_table_columns($pdo, 'daily_quiz_progress');
    $dqUser = ra_first_col($dqCols, ['user_id']);
    $dqDate = ra_first_col($dqCols, ['quiz_date', 'date']);
    $dqCorrect = ra_first_col($dqCols, ['correct_answers', 'correct_count']);
    $dqTotal = ra_first_col($dqCols, ['total_questions', 'question_count']);
    $dqCompleted = ra_first_col($dqCols, ['completed_at', 'updated_at', 'created_at']);
    if (in_array('daily_quiz', $types, true) && $dqUser && $dqDate && $dqCompleted && $uId && $uEmail) {
        $guestExpr = ra_detect_guest_sql('u.`' . $uEmail . '`', $uFullName ? ('u.`' . $uFullName . '`') : null);
        $sql = 'SELECT d.`' . $dqCompleted . '` AS created_at, d.`' . $dqDate . '` AS quiz_date, '
            . ($dqCorrect ? 'd.`' . $dqCorrect . '`' : '0') . ' AS correct_count, '
            . ($dqTotal ? 'd.`' . $dqTotal . '`' : '0') . ' AS total_count, '
            . 'u.`' . $uId . '` AS user_id, u.`' . $uEmail . '` AS email, '
            . ($uFullName ? 'u.`' . $uFullName . '`' : "''") . ' AS full_name, '
            . 'CASE WHEN ' . $guestExpr . " THEN 'guest' ELSE 'registered' END AS user_type "
            . 'FROM `daily_quiz_progress` d '
            . 'LEFT JOIN `user_profiles` u ON d.`' . $dqUser . '` = u.`' . $uId . '` '
            . 'ORDER BY d.`' . $dqCompleted . '` DESC LIMIT ' . (int)$limit;

        $list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as $item) {
            $total = (int)($item['total_count'] ?? 0);
            $correct = (int)($item['correct_count'] ?? 0);
            $wrong = max(0, $total - $correct);
            $rows[] = [
                'type' => 'daily_quiz',
                'title' => 'Daily quiz aktivitesi',
                'subtitle' => 'Doğru: ' . $correct . ' / Yanlış: ' . $wrong,
                'created_at' => $item['created_at'] ?? null,
                'user' => [
                    'id' => (string)($item['user_id'] ?? ''),
                    'email' => (string)($item['email'] ?? ''),
                    'full_name' => (string)($item['full_name'] ?? ''),
                    'user_type' => (string)($item['user_type'] ?? 'registered'),
                ],
                'detail' => [
                    'quiz_date' => $item['quiz_date'] ?? null,
                    'correct_count' => $correct,
                    'wrong_count' => $wrong,
                    'total_count' => $total,
                    'completed' => ($item['created_at'] ?? null) !== null,
                    'questions' => [],
                ],
            ];

            $quizDate = (string)($item['quiz_date'] ?? '');
            $userId = (string)($item['user_id'] ?? '');
            if ($quizDate !== '' && $userId !== '') {
                $rows[count($rows) - 1]['detail']['questions'] = ra_fetch_daily_quiz_questions($pdo, $userId, $quizDate, max(10, $total));
            }
        }
    }

    $profileUpdatesSourceAvailable = (bool)($uCreated && $uUpdated);
    if (in_array('profile_updates', $types, true) && $uId && $uUpdated && $uCreated && $uEmail) {
        $guestExpr = ra_detect_guest_sql('u.`' . $uEmail . '`', $uFullName ? ('u.`' . $uFullName . '`') : null);
        $where = ['u.`' . $uUpdated . '` > u.`' . $uCreated . '`'];
        if ($uDeleted) {
            $where[] = 'u.`' . $uDeleted . '` = 0';
        }
        $sql = 'SELECT u.`' . $uUpdated . '` AS created_at, u.`' . $uId . '` AS user_id, '
            . 'u.`' . $uEmail . '` AS email, '
            . ($uFullName ? 'u.`' . $uFullName . '`' : "''") . ' AS full_name, '
            . 'CASE WHEN ' . $guestExpr . " THEN 'guest' ELSE 'registered' END AS user_type "
            . 'FROM `user_profiles` u WHERE ' . implode(' AND ', $where)
            . ' ORDER BY u.`' . $uUpdated . '` DESC LIMIT ' . (int)$limit;

        $list = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as $item) {
            $rows[] = [
                'type' => 'profile_updates',
                'title' => 'Profil güncellemesi',
                'subtitle' => 'Profil alanlarında değişiklik yapıldı',
                'created_at' => $item['created_at'] ?? null,
                'user' => [
                    'id' => (string)($item['user_id'] ?? ''),
                    'email' => (string)($item['email'] ?? ''),
                    'full_name' => (string)($item['full_name'] ?? ''),
                    'user_type' => (string)($item['user_type'] ?? 'registered'),
                ],
                'detail' => [
                    'changed_fields' => [],
                    'source' => 'user_profiles.updated_at > user_profiles.created_at',
                ],
            ];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return strtotime((string)($b['created_at'] ?? '1970-01-01')) <=> strtotime((string)($a['created_at'] ?? '1970-01-01'));
    });
    $rows = array_slice($rows, 0, $limit);

    api_success('Dashboard son aktiviteler alındı.', [
        'activities' => $rows,
        'meta' => [
            'types' => $types,
            'limit' => $limit,
            'profile_updates_source_available' => $profileUpdatesSourceAvailable,
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

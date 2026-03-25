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

    if (in_array('registrations', $types, true) && $uId && $uCreated && $uEmail) {
        $guestExpr = ra_detect_guest_sql('u.`' . $uEmail . '`', $uFullName ? ('u.`' . $uFullName . '`') : null);
        $sql = 'SELECT u.`' . $uId . '` AS user_id, u.`' . $uEmail . '` AS email, '
            . ($uFullName ? 'u.`' . $uFullName . '`' : "''") . ' AS full_name, '
            . 'u.`' . $uCreated . '` AS created_at, '
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
                ],
            ];
        }
    }

    $aCols = get_table_columns($pdo, 'question_attempt_events');
    $aUserId = ra_first_col($aCols, ['user_id']);
    $aQuestionId = ra_first_col($aCols, ['question_id']);
    $aCorrect = ra_first_col($aCols, ['is_correct']);
    $aDate = ra_first_col($aCols, ['attempted_at', 'created_at']);

    $qCols = get_table_columns($pdo, 'questions');
    $qId = ra_first_col($qCols, ['id']);
    $qCourse = ra_first_col($qCols, ['course_id']);
    $qCode = ra_first_col($qCols, ['question_code', 'code']);

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
            . 'u.`' . $uId . '` AS user_id, u.`' . $uEmail . '` AS email, '
            . ($uFullName ? 'u.`' . $uFullName . '`' : "''") . ' AS full_name, '
            . 'CASE WHEN ' . $guestExpr . " THEN 'guest' ELSE 'registered' END AS user_type, "
            . ($qCode ? 'q.`' . $qCode . '`' : 'NULL') . ' AS question_code, '
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
                    'is_correct' => isset($item['is_correct']) ? ((int)$item['is_correct'] === 1) : null,
                    'qualification_name' => (string)($item['qualification_name'] ?? ''),
                    'course_name' => (string)($item['course_name'] ?? ''),
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
                ],
            ];
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

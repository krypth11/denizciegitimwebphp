<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

api_require_method('GET');

function dd_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function dd_first(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function dd_rate(int $correct, int $wrong): float
{
    $total = $correct + $wrong;
    return $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
}

if (($_GET['scope'] ?? '') === 'admin') {
    try {
        $auth = api_require_auth($pdo);
        $isAdmin = !empty($auth['user']['is_admin']);
        if (!$isAdmin) {
            api_error('Admin yetkisi gerekli.', 403);
        }

        $qualificationId = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id');

        $qualifications = [];
        $courses = [];

        $qualCols = get_table_columns($pdo, 'qualifications');
        $qId = dd_first($qualCols, ['id']);
        $qName = dd_first($qualCols, ['name', 'title']);
        $qOrder = dd_first($qualCols, ['order_index']);
        if ($qId && $qName) {
            $sql = 'SELECT `' . $qId . '` AS id, `' . $qName . '` AS name'
                . ($qOrder ? ', `' . $qOrder . '` AS order_index' : ', 0 AS order_index')
                . ' FROM `qualifications`'
                . ' ORDER BY order_index ASC, name ASC';
            $qualifications = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $courseCols = get_table_columns($pdo, 'courses');
        $cId = dd_first($courseCols, ['id']);
        $cName = dd_first($courseCols, ['name', 'title']);
        $cQual = dd_first($courseCols, ['qualification_id']);
        $cOrder = dd_first($courseCols, ['order_index']);
        if ($cId && $cName) {
            $sql = 'SELECT `' . $cId . '` AS id, `' . $cName . '` AS name'
                . ($cQual ? ', `' . $cQual . '` AS qualification_id' : ', NULL AS qualification_id')
                . ($cOrder ? ', `' . $cOrder . '` AS order_index' : ', 0 AS order_index')
                . ' FROM `courses`';
            $params = [];
            if ($qualificationId !== '' && $cQual) {
                $sql .= ' WHERE `' . $cQual . '` = ?';
                $params[] = $qualificationId;
            }
            $sql .= ' ORDER BY order_index ASC, name ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        api_success('Dashboard admin filtre detayları alındı.', [
            'details' => [
                'qualifications' => $qualifications,
                'courses' => $courses,
            ],
        ]);
    } catch (Throwable $e) {
        api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
    }
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $minimumSolved = api_get_int_query('min_solved', 10, 1, 100);
    $window = stats_resolve_date_window($_GET, 'range', 'start_date', 'end_date', 'all');

    $details = [
        'qualification_stats' => [],
        'course_stats' => [],
        'topic_stats' => [],
        'strengths' => [],
        'weaknesses' => [],
        'bookmarks_summary' => [
            'total_bookmarked' => 0,
            'by_qualification' => [],
            'by_course' => [],
        ],
        'recent_activity_summary' => [
            'last_activity_at' => null,
            'last_active_days' => [],
        ],
        'consistency' => [
            'current_streak_days' => 0,
            'active_days_last_7' => 0,
            'active_days_last_30' => 0,
        ],
    ];

    $upCols = get_table_columns($pdo, 'user_progress');
    $qCols = get_table_columns($pdo, 'questions');
    $cCols = get_table_columns($pdo, 'courses');
    $qualCols = get_table_columns($pdo, 'qualifications');
    $tCols = get_table_columns($pdo, 'topics');
    $evCols = get_table_columns($pdo, 'question_attempt_events');
    $metCols = get_table_columns($pdo, 'maritime_english_topics');
    $mecCols = get_table_columns($pdo, 'maritime_english_categories');

    if (empty($upCols) || empty($qCols) || !in_array('user_id', $upCols, true)) {
        api_success('Dashboard detay istatistikleri alındı.', ['details' => $details]);
    }

    $upQuestionId = dd_first($upCols, ['question_id']);
    $upCorrectCount = dd_first($upCols, ['correct_answer_count', 'correct_count']);
    $upWrongCount = dd_first($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count']);
    $upTotalAnswerCount = dd_first($upCols, ['total_answer_count', 'answer_count', 'total_answers']);
    $upIsBookmarked = dd_first($upCols, ['is_bookmarked', 'bookmarked']);
    $upDateCol = dd_first($upCols, ['last_answered_at', 'answered_at', 'updated_at', 'created_at']);

    $qId = dd_first($qCols, ['id']);
    $qCourseId = dd_first($qCols, ['course_id']);

    $cId = dd_first($cCols, ['id']);
    $cName = dd_first($cCols, ['name', 'title']);
    $cQualificationId = dd_first($cCols, ['qualification_id']);

    $qualId = dd_first($qualCols, ['id']);
    $qualName = dd_first($qualCols, ['name', 'title']);

    $evUserId = dd_first($evCols, ['user_id']);
    $evTopicId = dd_first($evCols, ['topic_id']);
    $evSource = dd_first($evCols, ['source']);
    $evCorrect = dd_first($evCols, ['is_correct']);
    $evAttemptedAt = dd_first($evCols, ['attempted_at', 'created_at', 'updated_at']);

    $tId = dd_first($tCols, ['id']);
    $tName = dd_first($tCols, ['name', 'title']);
    $tCourseId = dd_first($tCols, ['course_id']);

    $metId = dd_first($metCols, ['id', 'topic_id']);
    $metName = dd_first($metCols, ['name', 'title', 'topic_name']);
    $metCategoryId = dd_first($metCols, ['category_id', 'maritime_english_category_id']);

    $mecId = dd_first($mecCols, ['id', 'category_id']);
    $mecName = dd_first($mecCols, ['name', 'title', 'category_name']);

    if (!$upQuestionId || !$qId || !$qCourseId || !$cId || !$cName) {
        api_success('Dashboard detay istatistikleri alındı.', ['details' => $details]);
    }

    $attemptExpr = $upTotalAnswerCount
        ? 'COALESCE(SUM(up.' . dd_q($upTotalAnswerCount) . '), 0)'
        : 'COUNT(*)';
    $correctExpr = $upCorrectCount
        ? 'COALESCE(SUM(up.' . dd_q($upCorrectCount) . '), 0)'
        : '0';
    $wrongExpr = $upWrongCount
        ? 'COALESCE(SUM(up.' . dd_q($upWrongCount) . '), 0)'
        : '0';
    $bookmarkExpr = $upIsBookmarked
        ? 'COALESCE(SUM(CASE WHEN up.' . dd_q($upIsBookmarked) . ' = 1 THEN 1 ELSE 0 END), 0)'
        : '0';

    $courseSql = 'SELECT '
        . 'c.' . dd_q($cId) . ' AS course_id, '
        . 'c.' . dd_q($cName) . ' AS course_name, '
        . ($cQualificationId ? 'c.' . dd_q($cQualificationId) . ' AS qualification_id, ' : 'NULL AS qualification_id, ')
        . (($cQualificationId && $qualId && $qualName) ? 'qf.' . dd_q($qualName) . ' AS qualification_name, ' : "'' AS qualification_name, ")
        . $attemptExpr . ' AS total_answer_attempts, '
        . $correctExpr . ' AS total_correct, '
        . $wrongExpr . ' AS total_wrong, '
        . $bookmarkExpr . ' AS bookmark_count, '
        . ($upDateCol ? 'MAX(up.' . dd_q($upDateCol) . ')' : 'NULL') . ' AS last_activity_at '
        . 'FROM `user_progress` up '
        . 'INNER JOIN `questions` q ON up.' . dd_q($upQuestionId) . ' = q.' . dd_q($qId) . ' '
        . 'INNER JOIN `courses` c ON q.' . dd_q($qCourseId) . ' = c.' . dd_q($cId) . ' '
        . (($cQualificationId && $qualId && $qualName) ? 'LEFT JOIN `qualifications` qf ON c.' . dd_q($cQualificationId) . ' = qf.' . dd_q($qualId) . ' ' : '')
        . 'WHERE up.`user_id` = ? '
        . 'GROUP BY c.' . dd_q($cId) . ', c.' . dd_q($cName)
        . ($cQualificationId ? ', c.' . dd_q($cQualificationId) : '')
        . (($cQualificationId && $qualId && $qualName) ? ', qf.' . dd_q($qualName) : '')
        . ' ORDER BY total_answer_attempts DESC, course_name ASC';

    $stmt = $pdo->prepare($courseSql);
    $stmt->execute([$userId]);
    $courseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($courseRows as $row) {
        $correct = (int)($row['total_correct'] ?? 0);
        $wrong = (int)($row['total_wrong'] ?? 0);
        $details['course_stats'][] = [
            'course_id' => (string)($row['course_id'] ?? ''),
            'course_name' => (string)($row['course_name'] ?? ''),
            'qualification_id' => $row['qualification_id'] ?? null,
            'qualification_name' => (string)($row['qualification_name'] ?? ''),
            'total_answer_attempts' => (int)($row['total_answer_attempts'] ?? 0),
            'total_correct' => $correct,
            'total_wrong' => $wrong,
            'success_rate' => dd_rate($correct, $wrong),
            'bookmark_count' => (int)($row['bookmark_count'] ?? 0),
            'last_activity_at' => $row['last_activity_at'] ?? null,
        ];
    }

    if ($evUserId && $evTopicId && $evCorrect && $evAttemptedAt) {
        $dateParams = [];
        $dateSql = stats_build_date_between_sql('e.' . dd_q($evAttemptedAt), $window['start_date'], $window['end_date'], $dateParams);

        // Standart (topics tablosu) topic istatistikleri
        if ($tId && $tName && $tCourseId && $cId && $cName) {
            $where = [
                'e.' . dd_q($evUserId) . ' = ?',
                'e.' . dd_q($evTopicId) . ' IS NOT NULL',
                'TRIM(COALESCE(e.' . dd_q($evTopicId) . ', "")) <> ""',
            ];
            $params = [$userId];
            if ($dateSql !== '') {
                $where[] = $dateSql;
                $params = array_merge($params, $dateParams);
            }
            if ($evSource) {
                $where[] = 'LOWER(TRIM(COALESCE(e.' . dd_q($evSource) . ', ""))) NOT IN ("maritime_english", "maritime-english", "me", "me_quiz", "maritime_english_quiz")';
            }

            $sql = 'SELECT '
                . 't.' . dd_q($tId) . ' AS topic_id, '
                . 't.' . dd_q($tName) . ' AS topic_name, '
                . 'c.' . dd_q($cId) . ' AS course_id, '
                . 'c.' . dd_q($cName) . ' AS course_name, '
                . ($cQualificationId ? 'c.' . dd_q($cQualificationId) . ' AS qualification_id, ' : 'NULL AS qualification_id, ')
                . (($cQualificationId && $qualId && $qualName) ? 'qf.' . dd_q($qualName) . ' AS qualification_name, ' : "'' AS qualification_name, ")
                . 'COUNT(*) AS total_answer_attempts, '
                . 'SUM(CASE WHEN e.' . dd_q($evCorrect) . ' = 1 THEN 1 ELSE 0 END) AS total_correct, '
                . 'SUM(CASE WHEN e.' . dd_q($evCorrect) . ' = 0 THEN 1 ELSE 0 END) AS total_wrong, '
                . 'MAX(e.' . dd_q($evAttemptedAt) . ') AS last_activity_at '
                . 'FROM `question_attempt_events` e '
                . 'INNER JOIN `topics` t ON e.' . dd_q($evTopicId) . ' = t.' . dd_q($tId) . ' '
                . 'INNER JOIN `courses` c ON t.' . dd_q($tCourseId) . ' = c.' . dd_q($cId) . ' '
                . (($cQualificationId && $qualId && $qualName) ? 'LEFT JOIN `qualifications` qf ON c.' . dd_q($cQualificationId) . ' = qf.' . dd_q($qualId) . ' ' : '')
                . 'WHERE ' . implode(' AND ', $where) . ' '
                . 'GROUP BY '
                . 't.' . dd_q($tId) . ', t.' . dd_q($tName) . ', '
                . 'c.' . dd_q($cId) . ', c.' . dd_q($cName)
                . ($cQualificationId ? ', c.' . dd_q($cQualificationId) : '')
                . (($cQualificationId && $qualId && $qualName) ? ', qf.' . dd_q($qualName) : '')
                . ' ORDER BY total_answer_attempts DESC, topic_name ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $correct = (int)($row['total_correct'] ?? 0);
                $wrong = (int)($row['total_wrong'] ?? 0);
                $attempts = (int)($row['total_answer_attempts'] ?? 0);
                $details['topic_stats'][] = [
                    'topic_id' => (string)($row['topic_id'] ?? ''),
                    'topic_name' => (string)($row['topic_name'] ?? ''),
                    'course_id' => $row['course_id'] ?? null,
                    'course_name' => (string)($row['course_name'] ?? ''),
                    'qualification_id' => $row['qualification_id'] ?? null,
                    'qualification_name' => (string)($row['qualification_name'] ?? ''),
                    'total_answer_attempts' => $attempts,
                    'total_correct' => $correct,
                    'total_wrong' => $wrong,
                    'success_rate' => dd_rate($correct, $wrong),
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                    'solved_count' => $attempts,
                    'answered_count' => $attempts,
                ];
            }
        }

        // Maritime English topic istatistikleri (events source = maritime_english*)
        if ($evSource && $metId && $metName && $metCategoryId) {
            $where = [
                'e.' . dd_q($evUserId) . ' = ?',
                'e.' . dd_q($evTopicId) . ' IS NOT NULL',
                'TRIM(COALESCE(e.' . dd_q($evTopicId) . ', "")) <> ""',
                'LOWER(TRIM(COALESCE(e.' . dd_q($evSource) . ', ""))) IN ("maritime_english", "maritime-english", "me", "me_quiz", "maritime_english_quiz")',
            ];
            $params = [$userId];
            if ($dateSql !== '') {
                $where[] = $dateSql;
                $params = array_merge($params, $dateParams);
            }

            $sql = 'SELECT '
                . 'mt.' . dd_q($metId) . ' AS topic_id, '
                . 'mt.' . dd_q($metName) . ' AS topic_name, '
                . "'maritime_english' AS course_id, "
                . "'Maritime English' AS course_name, "
                . ($mecId ? 'mc.' . dd_q($mecId) . ' AS qualification_id, ' : 'NULL AS qualification_id, ')
                . (($mecId && $mecName) ? 'mc.' . dd_q($mecName) . ' AS qualification_name, ' : "'Maritime English' AS qualification_name, ")
                . 'COUNT(*) AS total_answer_attempts, '
                . 'SUM(CASE WHEN e.' . dd_q($evCorrect) . ' = 1 THEN 1 ELSE 0 END) AS total_correct, '
                . 'SUM(CASE WHEN e.' . dd_q($evCorrect) . ' = 0 THEN 1 ELSE 0 END) AS total_wrong, '
                . 'MAX(e.' . dd_q($evAttemptedAt) . ') AS last_activity_at '
                . 'FROM `question_attempt_events` e '
                . 'INNER JOIN `maritime_english_topics` mt ON e.' . dd_q($evTopicId) . ' = mt.' . dd_q($metId) . ' '
                . (($mecId && $mecName) ? 'LEFT JOIN `maritime_english_categories` mc ON mt.' . dd_q($metCategoryId) . ' = mc.' . dd_q($mecId) . ' ' : '')
                . 'WHERE ' . implode(' AND ', $where) . ' '
                . 'GROUP BY mt.' . dd_q($metId) . ', mt.' . dd_q($metName)
                . (($mecId && $mecName) ? ', mc.' . dd_q($mecId) . ', mc.' . dd_q($mecName) : '')
                . ' ORDER BY total_answer_attempts DESC, topic_name ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $correct = (int)($row['total_correct'] ?? 0);
                $wrong = (int)($row['total_wrong'] ?? 0);
                $attempts = (int)($row['total_answer_attempts'] ?? 0);
                $details['topic_stats'][] = [
                    'topic_id' => (string)($row['topic_id'] ?? ''),
                    'topic_name' => (string)($row['topic_name'] ?? ''),
                    'course_id' => $row['course_id'] ?? null,
                    'course_name' => (string)($row['course_name'] ?? ''),
                    'qualification_id' => $row['qualification_id'] ?? null,
                    'qualification_name' => (string)($row['qualification_name'] ?? ''),
                    'total_answer_attempts' => $attempts,
                    'total_correct' => $correct,
                    'total_wrong' => $wrong,
                    'success_rate' => dd_rate($correct, $wrong),
                    'last_activity_at' => $row['last_activity_at'] ?? null,
                    'solved_count' => $attempts,
                    'answered_count' => $attempts,
                ];
            }
        }

        usort($details['topic_stats'], static function (array $a, array $b): int {
            $attemptCmp = ((int)($b['total_answer_attempts'] ?? 0)) <=> ((int)($a['total_answer_attempts'] ?? 0));
            if ($attemptCmp !== 0) {
                return $attemptCmp;
            }
            return strcmp((string)($a['topic_name'] ?? ''), (string)($b['topic_name'] ?? ''));
        });
    }

    $byQualification = [];
    foreach ($details['course_stats'] as $course) {
        $qid = (string)($course['qualification_id'] ?? '');
        if ($qid === '') {
            $qid = '__null__';
        }
        if (!isset($byQualification[$qid])) {
            $byQualification[$qid] = [
                'qualification_id' => $course['qualification_id'] ?? null,
                'qualification_name' => $course['qualification_name'],
                'total_answer_attempts' => 0,
                'total_correct' => 0,
                'total_wrong' => 0,
                'success_rate' => 0.0,
                'course_count' => 0,
                'last_activity_at' => null,
            ];
        }

        $byQualification[$qid]['total_answer_attempts'] += (int)$course['total_answer_attempts'];
        $byQualification[$qid]['total_correct'] += (int)$course['total_correct'];
        $byQualification[$qid]['total_wrong'] += (int)$course['total_wrong'];
        $byQualification[$qid]['course_count'] += 1;

        $lastAt = $course['last_activity_at'];
        if ($lastAt && (!$byQualification[$qid]['last_activity_at'] || strtotime((string)$lastAt) > strtotime((string)$byQualification[$qid]['last_activity_at']))) {
            $byQualification[$qid]['last_activity_at'] = $lastAt;
        }
    }

    foreach ($byQualification as $row) {
        $row['success_rate'] = dd_rate((int)$row['total_correct'], (int)$row['total_wrong']);
        $details['qualification_stats'][] = $row;
    }

    usort($details['qualification_stats'], static fn(array $a, array $b): int => $b['total_answer_attempts'] <=> $a['total_answer_attempts']);

    $details['bookmarks_summary']['total_bookmarked'] = (int)array_sum(array_map(static fn(array $c): int => (int)$c['bookmark_count'], $details['course_stats']));

    foreach ($details['course_stats'] as $course) {
        if ((int)$course['bookmark_count'] > 0) {
            $details['bookmarks_summary']['by_course'][] = [
                'course_id' => $course['course_id'],
                'course_name' => $course['course_name'],
                'total' => (int)$course['bookmark_count'],
            ];
        }
    }

    foreach ($details['qualification_stats'] as $qualification) {
        $qid = (string)($qualification['qualification_id'] ?? '');
        $sum = 0;
        foreach ($details['course_stats'] as $course) {
            if ((string)($course['qualification_id'] ?? '') === $qid) {
                $sum += (int)$course['bookmark_count'];
            }
        }
        if ($sum > 0) {
            $details['bookmarks_summary']['by_qualification'][] = [
                'qualification_id' => $qualification['qualification_id'],
                'qualification_name' => $qualification['qualification_name'],
                'total' => $sum,
            ];
        }
    }

    usort($details['bookmarks_summary']['by_course'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    usort($details['bookmarks_summary']['by_qualification'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

    $eligibleCourses = array_values(array_filter($details['course_stats'], static fn(array $c): bool => ((int)$c['total_answer_attempts']) >= $minimumSolved));

    $strengths = $eligibleCourses;
    usort($strengths, static function (array $a, array $b): int {
        if ($a['success_rate'] == $b['success_rate']) {
            return $b['total_answer_attempts'] <=> $a['total_answer_attempts'];
        }
        return $b['success_rate'] <=> $a['success_rate'];
    });
    $details['strengths'] = array_slice(array_map(static function (array $c): array {
        return [
            'course_id' => $c['course_id'],
            'course_name' => $c['course_name'],
            'total_answer_attempts' => (int)$c['total_answer_attempts'],
            'total_correct' => (int)$c['total_correct'],
            'total_wrong' => (int)$c['total_wrong'],
            'success_rate' => (float)$c['success_rate'],
        ];
    }, $strengths), 0, 5);

    $weaknesses = $eligibleCourses;
    usort($weaknesses, static function (array $a, array $b): int {
        if ($a['success_rate'] == $b['success_rate']) {
            return $b['total_answer_attempts'] <=> $a['total_answer_attempts'];
        }
        return $a['success_rate'] <=> $b['success_rate'];
    });
    $details['weaknesses'] = array_slice(array_map(static function (array $c): array {
        return [
            'course_id' => $c['course_id'],
            'course_name' => $c['course_name'],
            'total_answer_attempts' => (int)$c['total_answer_attempts'],
            'total_correct' => (int)$c['total_correct'],
            'total_wrong' => (int)$c['total_wrong'],
            'success_rate' => (float)$c['success_rate'],
        ];
    }, $weaknesses), 0, 5);

    // recent_activity_summary (all-time, latest active days)
    if ($upDateCol) {
        $sql = 'SELECT DATE(' . dd_q($upDateCol) . ') AS activity_date, '
            . 'COALESCE(SUM(' . ($upTotalAnswerCount ? dd_q($upTotalAnswerCount) : '1') . '),0) AS total_answer_attempts, '
            . ($upCorrectCount ? 'COALESCE(SUM(' . dd_q($upCorrectCount) . '),0)' : '0') . ' AS total_correct, '
            . ($upWrongCount ? 'COALESCE(SUM(' . dd_q($upWrongCount) . '),0)' : '0') . ' AS total_wrong '
            . 'FROM `user_progress` WHERE `user_id` = ? '
            . 'GROUP BY DATE(' . dd_q($upDateCol) . ') '
            . 'ORDER BY activity_date DESC LIMIT 7';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $correct = (int)($row['total_correct'] ?? 0);
            $wrong = (int)($row['total_wrong'] ?? 0);
            $details['recent_activity_summary']['last_active_days'][] = [
                'date' => (string)($row['activity_date'] ?? ''),
                'total_answer_attempts' => (int)($row['total_answer_attempts'] ?? 0),
                'total_correct' => $correct,
                'total_wrong' => $wrong,
                'success_rate' => dd_rate($correct, $wrong),
            ];
        }

        if (!empty($details['recent_activity_summary']['last_active_days'])) {
            $details['recent_activity_summary']['last_activity_at'] = $details['recent_activity_summary']['last_active_days'][0]['date'];
        }
    }

    // consistency (all-time snapshot kaynağı: user_progress tarih kolonları)
    if ($upDateCol) {
        $sql = 'SELECT DISTINCT DATE(' . dd_q($upDateCol) . ') AS d FROM `user_progress` WHERE `user_id` = ? ORDER BY d DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $days = array_values(array_filter(array_map(static fn(array $r): string => (string)($r['d'] ?? ''), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));

        $set = array_fill_keys($days, true);
        $today = new DateTimeImmutable('today');
        $last7 = 0;
        $last30 = 0;

        for ($i = 0; $i < 30; $i++) {
            $d = $today->modify('-' . $i . ' day')->format('Y-m-d');
            if (isset($set[$d])) {
                if ($i < 7) {
                    $last7++;
                }
                $last30++;
            }
        }

        $streak = 0;
        if (!empty($days)) {
            $cursor = new DateTimeImmutable($days[0]);
            while (isset($set[$cursor->format('Y-m-d')])) {
                $streak++;
                $cursor = $cursor->modify('-1 day');
            }
        }

        $details['consistency'] = [
            'current_streak_days' => $streak,
            'active_days_last_7' => $last7,
            'active_days_last_30' => $last30,
        ];
    }

    api_success('Dashboard detay istatistikleri alındı.', [
        'details' => $details,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

api_require_method('GET');

function sd_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function sd_first(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function sd_rate(int $correct, int $wrong): float
{
    $total = $correct + $wrong;
    return $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $filters = stats_resolve_filters_from_query();
    $minimumSolvedForAnalysis = api_get_int_query('min_solved', 10, 1, 100);
    $courseFilter = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id', 191);
    $qualificationFilter = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id', 191);

    $details = [
        'filters' => [
            'range' => $filters['range'],
            'available_ranges' => $filters['available_ranges'],
            'start_date' => $filters['start_date'],
            'end_date' => $filters['end_date'],
            'course_id' => $courseFilter !== '' ? $courseFilter : null,
            'qualification_id' => $qualificationFilter !== '' ? $qualificationFilter : null,
            'minimum_solved_for_analysis' => $minimumSolvedForAnalysis,
        ],
        'summary' => [
            'total_solved_questions' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0.0,
            'bookmarked_count' => 0,
            'unique_answered_questions' => 0,
            'unanswered_question_count' => 0,
            'completed_daily_quiz_count' => 0,
            'last_activity_at' => null,
        ],
        'charts' => [
            'daily_activity' => [],
        ],
        'qualification_stats' => [],
        'course_stats' => [],
        'strengths' => [],
        'weaknesses' => [],
        'question_type_distribution' => [
            'available' => false,
            'items' => [],
        ],
        'bookmarks_summary' => [
            'total_bookmarked' => 0,
            'by_qualification' => [],
            'by_course' => [],
        ],
        'consistency' => [
            'current_streak_days' => 0,
            'active_days_last_7' => 0,
            'active_days_last_30' => 0,
        ],
        'recent_activity' => [],
    ];

    $upCols = get_table_columns($pdo, 'user_progress');
    $qCols = get_table_columns($pdo, 'questions');
    $cCols = get_table_columns($pdo, 'courses');
    $qualCols = get_table_columns($pdo, 'qualifications');
    $tCols = get_table_columns($pdo, 'topics');
    $ssCols = get_table_columns($pdo, 'study_sessions');
    $dqCols = get_table_columns($pdo, 'daily_quiz_progress');

    if (empty($upCols) || empty($qCols) || !in_array('user_id', $upCols, true)) {
        api_success('Dashboard detay istatistikleri alındı.', ['details' => $details]);
    }

    $upQuestionId = sd_first($upCols, ['question_id']);
    $upIsAnswered = sd_first($upCols, ['is_answered']);
    $upIsCorrect = sd_first($upCols, ['is_correct']);
    $upIsBookmarked = sd_first($upCols, ['is_bookmarked', 'bookmarked']);
    $upTotalAnswerCount = sd_first($upCols, ['total_answer_count', 'answer_count', 'total_answers']);
    $upCorrectCount = sd_first($upCols, ['correct_answer_count', 'correct_count']);
    $upWrongCount = sd_first($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count']);
    $upDateCol = sd_first($upCols, ['answered_at', 'last_answered_at', 'updated_at', 'created_at']);

    $qId = sd_first($qCols, ['id']);
    $qType = sd_first($qCols, ['question_type']);
    $qCourseId = sd_first($qCols, ['course_id']);
    $qTopicId = sd_first($qCols, ['topic_id']);

    if (!$upQuestionId || !$qId) {
        api_success('Dashboard detay istatistikleri alındı.', ['details' => $details]);
    }

    $tId = sd_first($tCols, ['id']);
    $tCourseId = sd_first($tCols, ['course_id']);
    $cId = sd_first($cCols, ['id']);
    $cName = sd_first($cCols, ['name', 'title']);
    $cQualificationId = sd_first($cCols, ['qualification_id']);
    $qualId = sd_first($qualCols, ['id']);
    $qualName = sd_first($qualCols, ['name', 'title']);

    $joinTopics = '';
    $courseRefExpr = null;
    if ($qCourseId) {
        $courseRefExpr = 'q.' . sd_q($qCourseId);
    } elseif ($qTopicId && $tId && $tCourseId) {
        $joinTopics = ' LEFT JOIN `topics` t ON q.' . sd_q($qTopicId) . ' = t.' . sd_q($tId) . ' ';
        $courseRefExpr = 't.' . sd_q($tCourseId);
    }

    $answeredBoolExpr = '1';
    if ($upIsAnswered) {
        $answeredBoolExpr = 'CASE WHEN up.' . sd_q($upIsAnswered) . ' = 1 THEN 1 ELSE 0 END';
    } elseif ($upTotalAnswerCount) {
        $answeredBoolExpr = 'CASE WHEN COALESCE(up.' . sd_q($upTotalAnswerCount) . ', 0) > 0 THEN 1 ELSE 0 END';
    } elseif ($upCorrectCount || $upWrongCount) {
        $correctPiece = $upCorrectCount ? 'COALESCE(up.' . sd_q($upCorrectCount) . ', 0)' : '0';
        $wrongPiece = $upWrongCount ? 'COALESCE(up.' . sd_q($upWrongCount) . ', 0)' : '0';
        $answeredBoolExpr = 'CASE WHEN (' . $correctPiece . ' + ' . $wrongPiece . ') > 0 THEN 1 ELSE 0 END';
    }

    $attemptExpr = $upTotalAnswerCount
        ? 'COALESCE(up.' . sd_q($upTotalAnswerCount) . ', 0)'
        : $answeredBoolExpr;

    $correctExpr = $upCorrectCount
        ? 'COALESCE(up.' . sd_q($upCorrectCount) . ', 0)'
        : ($upIsCorrect ? 'CASE WHEN up.' . sd_q($upIsCorrect) . ' = 1 THEN 1 ELSE 0 END' : '0');

    $wrongExpr = $upWrongCount
        ? 'COALESCE(up.' . sd_q($upWrongCount) . ', 0)'
        : ($upIsCorrect ? 'CASE WHEN up.' . sd_q($upIsCorrect) . ' = 0 THEN 1 ELSE 0 END' : '0');

    $bookmarkExpr = $upIsBookmarked
        ? 'CASE WHEN up.' . sd_q($upIsBookmarked) . ' = 1 THEN 1 ELSE 0 END'
        : '0';

    $baseFrom = ' FROM `user_progress` up '
        . 'INNER JOIN `questions` q ON up.' . sd_q($upQuestionId) . ' = q.' . sd_q($qId) . ' '
        . $joinTopics;

    $applyScope = static function (bool $withDateFilter, bool $withCourseQualFilter) use (
        $userId,
        $filters,
        $upDateCol,
        $courseFilter,
        $qualificationFilter,
        $courseRefExpr,
        $cId,
        $cQualificationId
    ): array {
        $where = ['up.`user_id` = ?'];
        $params = [$userId];

        if ($withDateFilter && $upDateCol) {
            $dateSql = stats_build_date_between_sql('up.`' . $upDateCol . '`', $filters['start_date'], $filters['end_date'], $params);
            if ($dateSql !== '') {
                $where[] = $dateSql;
            }
        }

        if ($withCourseQualFilter && $courseRefExpr && $courseFilter !== '') {
            $where[] = $courseRefExpr . ' = ?';
            $params[] = $courseFilter;
        }

        if ($withCourseQualFilter && $courseRefExpr && $qualificationFilter !== '' && $cId && $cQualificationId) {
            $where[] = 'c_scope.`' . $cQualificationId . '` = ?';
            $params[] = $qualificationFilter;
        }

        return [$where, $params];
    };

    $scopeJoinForQualification = '';
    if ($courseRefExpr && $cId && $cQualificationId) {
        $scopeJoinForQualification = ' LEFT JOIN `courses` c_scope ON ' . $courseRefExpr . ' = c_scope.`' . $cId . '` ';
    }

    // SUMMARY
    [$summaryWhere, $summaryParams] = $applyScope(true, true);
    $summarySql = 'SELECT '
        . 'COALESCE(SUM(' . $attemptExpr . '), 0) AS total_solved_questions, '
        . 'COALESCE(SUM(' . $correctExpr . '), 0) AS total_correct, '
        . 'COALESCE(SUM(' . $wrongExpr . '), 0) AS total_wrong, '
        . 'COALESCE(SUM(' . $bookmarkExpr . '), 0) AS bookmarked_count, '
        . 'COUNT(DISTINCT CASE WHEN (' . $answeredBoolExpr . ') = 1 THEN up.' . sd_q($upQuestionId) . ' END) AS unique_answered_questions '
        . $baseFrom
        . $scopeJoinForQualification
        . ' WHERE ' . implode(' AND ', $summaryWhere);

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $details['summary']['total_solved_questions'] = (int)($summaryRow['total_solved_questions'] ?? 0);
    $details['summary']['total_correct'] = (int)($summaryRow['total_correct'] ?? 0);
    $details['summary']['total_wrong'] = (int)($summaryRow['total_wrong'] ?? 0);
    $details['summary']['bookmarked_count'] = (int)($summaryRow['bookmarked_count'] ?? 0);
    $details['summary']['unique_answered_questions'] = (int)($summaryRow['unique_answered_questions'] ?? 0);
    $details['summary']['success_rate'] = sd_rate($details['summary']['total_correct'], $details['summary']['total_wrong']);

    // total question pool + unanswered
    $questionPoolWhere = [];
    $questionPoolParams = [];
    $questionPoolFrom = ' FROM `questions` q ' . $joinTopics;
    if ($courseRefExpr && $courseFilter !== '') {
        $questionPoolWhere[] = $courseRefExpr . ' = ?';
        $questionPoolParams[] = $courseFilter;
    }
    if ($courseRefExpr && $qualificationFilter !== '' && $cId && $cQualificationId) {
        $questionPoolFrom .= ' LEFT JOIN `courses` c_scope ON ' . $courseRefExpr . ' = c_scope.`' . $cId . '` ';
        $questionPoolWhere[] = 'c_scope.`' . $cQualificationId . '` = ?';
        $questionPoolParams[] = $qualificationFilter;
    }
    $poolSql = 'SELECT COUNT(*) ' . $questionPoolFrom;
    if ($questionPoolWhere) {
        $poolSql .= ' WHERE ' . implode(' AND ', $questionPoolWhere);
    }
    $poolStmt = $pdo->prepare($poolSql);
    $poolStmt->execute($questionPoolParams);
    $totalQuestionPool = (int)$poolStmt->fetchColumn();
    $details['summary']['unanswered_question_count'] = max(0, $totalQuestionPool - $details['summary']['unique_answered_questions']);

    // Daily quiz completed count (filtered by selected range)
    if (!empty($dqCols) && in_array('user_id', $dqCols, true)) {
        $dqDateCol = sd_first($dqCols, ['quiz_date', 'date', 'created_at']);
        if ($dqDateCol) {
            $dqParams = [$userId];
            $dqWhere = ['`user_id` = ?'];
            $dqDateSql = stats_build_date_between_sql('`' . $dqDateCol . '`', $filters['start_date'], $filters['end_date'], $dqParams);
            if ($dqDateSql !== '') {
                $dqWhere[] = $dqDateSql;
            }

            $dqSql = 'SELECT COUNT(DISTINCT DATE(`' . $dqDateCol . '`)) FROM `daily_quiz_progress` WHERE ' . implode(' AND ', $dqWhere);
            $dqStmt = $pdo->prepare($dqSql);
            $dqStmt->execute($dqParams);
            $details['summary']['completed_daily_quiz_count'] = (int)$dqStmt->fetchColumn();
        }
    }

    // last activity (all-time, scope aware)
    $lastActivityCandidates = [];
    if ($upDateCol) {
        [$laWhere, $laParams] = $applyScope(false, true);
        $laSql = 'SELECT MAX(up.' . sd_q($upDateCol) . ') ' . $baseFrom . $scopeJoinForQualification . ' WHERE ' . implode(' AND ', $laWhere);
        $stmt = $pdo->prepare($laSql);
        $stmt->execute($laParams);
        $v = $stmt->fetchColumn();
        if ($v) {
            $lastActivityCandidates[] = (string)$v;
        }
    }

    if (!empty($ssCols) && in_array('user_id', $ssCols, true)) {
        $ssDateCol = sd_first($ssCols, ['created_at', 'updated_at']);
        if ($ssDateCol) {
            $ssWhere = ['`user_id` = ?'];
            $ssParams = [$userId];

            $ssCourseCol = sd_first($ssCols, ['course_id']);
            $ssQualCol = sd_first($ssCols, ['qualification_id']);
            if ($courseFilter !== '' && $ssCourseCol) {
                $ssWhere[] = '`' . $ssCourseCol . '` = ?';
                $ssParams[] = $courseFilter;
            }
            if ($qualificationFilter !== '' && $ssQualCol) {
                $ssWhere[] = '`' . $ssQualCol . '` = ?';
                $ssParams[] = $qualificationFilter;
            }

            $ssSql = 'SELECT MAX(`' . $ssDateCol . '`) FROM `study_sessions` WHERE ' . implode(' AND ', $ssWhere);
            $stmt = $pdo->prepare($ssSql);
            $stmt->execute($ssParams);
            $v = $stmt->fetchColumn();
            if ($v) {
                $lastActivityCandidates[] = (string)$v;
            }
        }
    }

    if ($lastActivityCandidates) {
        usort($lastActivityCandidates, static fn(string $a, string $b): int => strtotime($b) <=> strtotime($a));
        $details['summary']['last_activity_at'] = $lastActivityCandidates[0];
    }

    // CHARTS - daily activity in selected range
    if ($upDateCol) {
        [$chartWhere, $chartParams] = $applyScope(true, true);
        $chartSql = 'SELECT DATE(up.' . sd_q($upDateCol) . ') AS day, '
            . 'COALESCE(SUM(' . $attemptExpr . '), 0) AS solved, '
            . 'COALESCE(SUM(' . $correctExpr . '), 0) AS correct, '
            . 'COALESCE(SUM(' . $wrongExpr . '), 0) AS wrong '
            . $baseFrom
            . $scopeJoinForQualification
            . ' WHERE ' . implode(' AND ', $chartWhere)
            . ' GROUP BY DATE(up.' . sd_q($upDateCol) . ') ORDER BY day ASC';

        $stmt = $pdo->prepare($chartSql);
        $stmt->execute($chartParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $correct = (int)($row['correct'] ?? 0);
            $wrong = (int)($row['wrong'] ?? 0);
            $details['charts']['daily_activity'][] = [
                'date' => (string)($row['day'] ?? ''),
                'solved' => (int)($row['solved'] ?? 0),
                'correct' => $correct,
                'wrong' => $wrong,
                'success_rate' => sd_rate($correct, $wrong),
            ];
        }
    }

    // COURSE + QUALIFICATION STATS (named)
    if ($courseRefExpr && $cId && $cName) {
        $courseFrom = $baseFrom
            . ' INNER JOIN `courses` c ON ' . $courseRefExpr . ' = c.' . sd_q($cId) . ' ';
        $qualificationJoin = ($cQualificationId && $qualId && $qualName)
            ? ' LEFT JOIN `qualifications` qf ON c.' . sd_q($cQualificationId) . ' = qf.' . sd_q($qualId) . ' '
            : '';

        [$courseWhere, $courseParams] = $applyScope(true, false);
        if ($courseFilter !== '') {
            $courseWhere[] = 'c.' . sd_q($cId) . ' = ?';
            $courseParams[] = $courseFilter;
        }
        if ($qualificationFilter !== '' && $cQualificationId) {
            $courseWhere[] = 'c.' . sd_q($cQualificationId) . ' = ?';
            $courseParams[] = $qualificationFilter;
        }

        $courseSql = 'SELECT '
            . 'c.' . sd_q($cId) . ' AS course_id, '
            . 'c.' . sd_q($cName) . ' AS course_name, '
            . ($cQualificationId ? 'c.' . sd_q($cQualificationId) . ' AS qualification_id, ' : 'NULL AS qualification_id, ')
            . (($cQualificationId && $qualId && $qualName) ? 'qf.' . sd_q($qualName) . ' AS qualification_name, ' : "'' AS qualification_name, ")
            . 'COALESCE(SUM(' . $attemptExpr . '), 0) AS solved, '
            . 'COALESCE(SUM(' . $correctExpr . '), 0) AS correct, '
            . 'COALESCE(SUM(' . $wrongExpr . '), 0) AS wrong, '
            . 'COALESCE(SUM(' . $bookmarkExpr . '), 0) AS bookmark_count, '
            . ($upDateCol ? 'MAX(up.' . sd_q($upDateCol) . ')' : 'NULL') . ' AS last_solved_at '
            . $courseFrom
            . $qualificationJoin
            . ' WHERE ' . implode(' AND ', $courseWhere)
            . ' GROUP BY c.' . sd_q($cId) . ', c.' . sd_q($cName)
            . ($cQualificationId ? ', c.' . sd_q($cQualificationId) : '')
            . (($cQualificationId && $qualId && $qualName) ? ', qf.' . sd_q($qualName) : '')
            . ' ORDER BY solved DESC, course_name ASC';

        $stmt = $pdo->prepare($courseSql);
        $stmt->execute($courseParams);
        $courseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($courseRows as $row) {
            $correct = (int)($row['correct'] ?? 0);
            $wrong = (int)($row['wrong'] ?? 0);
            $details['course_stats'][] = [
                'course_id' => (string)($row['course_id'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'qualification_id' => $row['qualification_id'] ?? null,
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'solved' => (int)($row['solved'] ?? 0),
                'correct' => $correct,
                'wrong' => $wrong,
                'success_rate' => sd_rate($correct, $wrong),
                'bookmark_count' => (int)($row['bookmark_count'] ?? 0),
                'last_solved_at' => $row['last_solved_at'] ?? null,
            ];
        }

        // qualification aggregation with strongest/weakest course
        $byQualification = [];
        foreach ($details['course_stats'] as $courseStat) {
            $qid = (string)($courseStat['qualification_id'] ?? '');
            if ($qid === '') {
                $qid = '__null__';
            }

            if (!isset($byQualification[$qid])) {
                $byQualification[$qid] = [
                    'qualification_id' => $courseStat['qualification_id'] ?? null,
                    'qualification_name' => (string)($courseStat['qualification_name'] ?? ''),
                    'solved' => 0,
                    'correct' => 0,
                    'wrong' => 0,
                    'success_rate' => 0.0,
                    'course_count' => 0,
                    'last_active_at' => null,
                    'strongest_course' => null,
                    'weakest_course' => null,
                    '_courses' => [],
                ];
            }

            $byQualification[$qid]['solved'] += (int)$courseStat['solved'];
            $byQualification[$qid]['correct'] += (int)$courseStat['correct'];
            $byQualification[$qid]['wrong'] += (int)$courseStat['wrong'];
            $byQualification[$qid]['course_count'] += 1;
            $byQualification[$qid]['_courses'][] = $courseStat;

            $last = $courseStat['last_solved_at'];
            if ($last && (!$byQualification[$qid]['last_active_at'] || strtotime((string)$last) > strtotime((string)$byQualification[$qid]['last_active_at']))) {
                $byQualification[$qid]['last_active_at'] = $last;
            }
        }

        foreach ($byQualification as $qid => $row) {
            $row['success_rate'] = sd_rate((int)$row['correct'], (int)$row['wrong']);

            $strongest = null;
            $weakest = null;
            foreach ($row['_courses'] as $courseStat) {
                if ($strongest === null || $courseStat['success_rate'] > $strongest['success_rate']) {
                    $strongest = $courseStat;
                }
                if ($weakest === null || $courseStat['success_rate'] < $weakest['success_rate']) {
                    $weakest = $courseStat;
                }
            }

            $row['strongest_course'] = $strongest ? [
                'course_id' => $strongest['course_id'],
                'course_name' => $strongest['course_name'],
                'success_rate' => (float)$strongest['success_rate'],
            ] : null;
            $row['weakest_course'] = $weakest ? [
                'course_id' => $weakest['course_id'],
                'course_name' => $weakest['course_name'],
                'success_rate' => (float)$weakest['success_rate'],
            ] : null;

            unset($row['_courses']);
            $details['qualification_stats'][] = $row;
        }

        usort($details['qualification_stats'], static fn(array $a, array $b): int => $b['solved'] <=> $a['solved']);
    }

    // strengths / weaknesses (minimum solved threshold)
    $eligibleCourses = array_values(array_filter($details['course_stats'], static function (array $row) use ($minimumSolvedForAnalysis): bool {
        return ((int)($row['solved'] ?? 0)) >= $minimumSolvedForAnalysis;
    }));

    if ($eligibleCourses) {
        $strengths = $eligibleCourses;
        usort($strengths, static function (array $a, array $b): int {
            if ($a['success_rate'] == $b['success_rate']) {
                return $b['solved'] <=> $a['solved'];
            }
            return $b['success_rate'] <=> $a['success_rate'];
        });

        $weaknesses = $eligibleCourses;
        usort($weaknesses, static function (array $a, array $b): int {
            if ($a['success_rate'] == $b['success_rate']) {
                return $b['solved'] <=> $a['solved'];
            }
            return $a['success_rate'] <=> $b['success_rate'];
        });

        $details['strengths'] = array_slice(array_map(static function (array $row): array {
            return [
                'course_id' => $row['course_id'],
                'course_name' => $row['course_name'],
                'solved' => (int)$row['solved'],
                'correct' => (int)$row['correct'],
                'wrong' => (int)$row['wrong'],
                'success_rate' => (float)$row['success_rate'],
            ];
        }, $strengths), 0, 5);

        $details['weaknesses'] = array_slice(array_map(static function (array $row): array {
            return [
                'course_id' => $row['course_id'],
                'course_name' => $row['course_name'],
                'solved' => (int)$row['solved'],
                'correct' => (int)$row['correct'],
                'wrong' => (int)$row['wrong'],
                'success_rate' => (float)$row['success_rate'],
            ];
        }, $weaknesses), 0, 5);
    }

    // question_type_distribution
    if ($qType) {
        $details['question_type_distribution']['available'] = true;

        // available totals by type in scope
        $typeTotalWhere = [];
        $typeTotalParams = [];
        $typeTotalFrom = ' FROM `questions` q ' . $joinTopics;

        if ($courseRefExpr && $courseFilter !== '') {
            $typeTotalWhere[] = $courseRefExpr . ' = ?';
            $typeTotalParams[] = $courseFilter;
        }
        if ($courseRefExpr && $qualificationFilter !== '' && $cId && $cQualificationId) {
            $typeTotalFrom .= ' LEFT JOIN `courses` c_scope ON ' . $courseRefExpr . ' = c_scope.`' . $cId . '` ';
            $typeTotalWhere[] = 'c_scope.`' . $cQualificationId . '` = ?';
            $typeTotalParams[] = $qualificationFilter;
        }

        $typeTotalSql = 'SELECT COALESCE(q.' . sd_q($qType) . ', "") AS question_type, COUNT(*) AS total_questions '
            . $typeTotalFrom;
        if ($typeTotalWhere) {
            $typeTotalSql .= ' WHERE ' . implode(' AND ', $typeTotalWhere);
        }
        $typeTotalSql .= ' GROUP BY COALESCE(q.' . sd_q($qType) . ', "")';

        $stmt = $pdo->prepare($typeTotalSql);
        $stmt->execute($typeTotalParams);
        $totalRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $typeMap = [];
        foreach ($totalRows as $row) {
            $key = (string)($row['question_type'] ?? '');
            $typeMap[$key] = [
                'question_type' => $key,
                'total_questions' => (int)($row['total_questions'] ?? 0),
                'answered' => 0,
                'correct' => 0,
                'wrong' => 0,
                'success_rate' => 0.0,
            ];
        }

        // solved by type in selected date range/scope
        [$typeWhere, $typeParams] = $applyScope(true, true);
        $typeSql = 'SELECT '
            . 'COALESCE(q.' . sd_q($qType) . ', "") AS question_type, '
            . 'COALESCE(SUM(' . $attemptExpr . '), 0) AS solved, '
            . 'COALESCE(SUM(' . $correctExpr . '), 0) AS correct, '
            . 'COALESCE(SUM(' . $wrongExpr . '), 0) AS wrong '
            . $baseFrom
            . $scopeJoinForQualification
            . ' WHERE ' . implode(' AND ', $typeWhere)
            . ' GROUP BY COALESCE(q.' . sd_q($qType) . ', "")';

        $stmt = $pdo->prepare($typeSql);
        $stmt->execute($typeParams);
        $typeRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($typeRows as $row) {
            $key = (string)($row['question_type'] ?? '');
            if (!isset($typeMap[$key])) {
                $typeMap[$key] = [
                    'question_type' => $key,
                    'total_questions' => 0,
                    'answered' => 0,
                    'correct' => 0,
                    'wrong' => 0,
                    'success_rate' => 0.0,
                ];
            }

            $correct = (int)($row['correct'] ?? 0);
            $wrong = (int)($row['wrong'] ?? 0);
            $typeMap[$key]['answered'] = (int)($row['solved'] ?? 0);
            $typeMap[$key]['correct'] = $correct;
            $typeMap[$key]['wrong'] = $wrong;
            $typeMap[$key]['success_rate'] = sd_rate($correct, $wrong);
        }

        $items = array_values($typeMap);
        usort($items, static fn(array $a, array $b): int => $b['answered'] <=> $a['answered']);
        $details['question_type_distribution']['items'] = $items;
    }

    // bookmarks_summary
    $details['bookmarks_summary']['total_bookmarked'] = (int)$details['summary']['bookmarked_count'];
    if ($upIsBookmarked && $courseRefExpr && $cId && $cName) {
        $bookmarkFrom = $baseFrom
            . ' INNER JOIN `courses` c ON ' . $courseRefExpr . ' = c.' . sd_q($cId) . ' ';
        $bookmarkQualJoin = ($cQualificationId && $qualId && $qualName)
            ? ' LEFT JOIN `qualifications` qf ON c.' . sd_q($cQualificationId) . ' = qf.' . sd_q($qualId) . ' '
            : '';

        [$bookmarkWhere, $bookmarkParams] = $applyScope(false, false);
        $bookmarkWhere[] = 'up.' . sd_q($upIsBookmarked) . ' = 1';
        if ($courseFilter !== '') {
            $bookmarkWhere[] = 'c.' . sd_q($cId) . ' = ?';
            $bookmarkParams[] = $courseFilter;
        }
        if ($qualificationFilter !== '' && $cQualificationId) {
            $bookmarkWhere[] = 'c.' . sd_q($cQualificationId) . ' = ?';
            $bookmarkParams[] = $qualificationFilter;
        }

        $bookmarkByCourseSql = 'SELECT c.' . sd_q($cId) . ' AS course_id, c.' . sd_q($cName) . ' AS course_name, COUNT(*) AS total '
            . $bookmarkFrom
            . $bookmarkQualJoin
            . ' WHERE ' . implode(' AND ', $bookmarkWhere)
            . ' GROUP BY c.' . sd_q($cId) . ', c.' . sd_q($cName)
            . ' ORDER BY total DESC, course_name ASC';
        $stmt = $pdo->prepare($bookmarkByCourseSql);
        $stmt->execute($bookmarkParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $details['bookmarks_summary']['by_course'] = array_map(static function (array $row): array {
            return [
                'course_id' => (string)($row['course_id'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'total' => (int)($row['total'] ?? 0),
            ];
        }, $rows);

        if ($cQualificationId && $qualId && $qualName) {
            $bookmarkByQualificationSql = 'SELECT '
                . 'qf.' . sd_q($qualId) . ' AS qualification_id, '
                . 'qf.' . sd_q($qualName) . ' AS qualification_name, '
                . 'COUNT(*) AS total '
                . $bookmarkFrom
                . $bookmarkQualJoin
                . ' WHERE ' . implode(' AND ', $bookmarkWhere)
                . ' GROUP BY qf.' . sd_q($qualId) . ', qf.' . sd_q($qualName)
                . ' ORDER BY total DESC, qualification_name ASC';
            $stmt = $pdo->prepare($bookmarkByQualificationSql);
            $stmt->execute($bookmarkParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $details['bookmarks_summary']['by_qualification'] = array_map(static function (array $row): array {
                return [
                    'qualification_id' => $row['qualification_id'] ?? null,
                    'qualification_name' => (string)($row['qualification_name'] ?? ''),
                    'total' => (int)($row['total'] ?? 0),
                ];
            }, $rows);
        }
    }

    // consistency + recent activity
    if ($upDateCol) {
        [$activeWhere, $activeParams] = $applyScope(false, true);
        $activeSql = 'SELECT DISTINCT DATE(up.' . sd_q($upDateCol) . ') AS active_day '
            . $baseFrom
            . $scopeJoinForQualification
            . ' WHERE ' . implode(' AND ', $activeWhere)
            . ' ORDER BY active_day DESC';
        $stmt = $pdo->prepare($activeSql);
        $stmt->execute($activeParams);
        $activeRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $activeDays = array_values(array_filter(array_map(static fn(array $r): string => (string)($r['active_day'] ?? ''), $activeRows)));
        $activeSet = array_fill_keys($activeDays, true);
        $today = new DateTimeImmutable('today');

        $activeLast7 = 0;
        $activeLast30 = 0;
        for ($i = 0; $i < 30; $i++) {
            $d = $today->modify('-' . $i . ' day')->format('Y-m-d');
            if (isset($activeSet[$d])) {
                if ($i < 7) {
                    $activeLast7++;
                }
                $activeLast30++;
            }
        }

        $streak = 0;
        if (!empty($activeDays)) {
            $cursor = new DateTimeImmutable($activeDays[0]);
            while (isset($activeSet[$cursor->format('Y-m-d')])) {
                $streak++;
                $cursor = $cursor->modify('-1 day');
            }
        }

        $details['consistency'] = [
            'current_streak_days' => $streak,
            'active_days_last_7' => $activeLast7,
            'active_days_last_30' => $activeLast30,
        ];

        [$recentWhere, $recentParams] = $applyScope(false, true);
        $recentSql = 'SELECT DATE(up.' . sd_q($upDateCol) . ') AS date, '
            . 'COALESCE(SUM(' . $attemptExpr . '), 0) AS solved, '
            . 'COALESCE(SUM(' . $correctExpr . '), 0) AS correct, '
            . 'COALESCE(SUM(' . $wrongExpr . '), 0) AS wrong '
            . $baseFrom
            . $scopeJoinForQualification
            . ' WHERE ' . implode(' AND ', $recentWhere)
            . ' GROUP BY DATE(up.' . sd_q($upDateCol) . ') '
            . ' ORDER BY date DESC LIMIT 7';
        $stmt = $pdo->prepare($recentSql);
        $stmt->execute($recentParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $details['recent_activity'] = array_map(static function (array $row): array {
            $correct = (int)($row['correct'] ?? 0);
            $wrong = (int)($row['wrong'] ?? 0);
            return [
                'date' => (string)($row['date'] ?? ''),
                'solved' => (int)($row['solved'] ?? 0),
                'correct' => $correct,
                'wrong' => $wrong,
                'success_rate' => sd_rate($correct, $wrong),
            ];
        }, $rows);
    }

    api_success('Dashboard detay istatistikleri alındı.', [
        'details' => $details,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

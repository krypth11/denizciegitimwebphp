<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

api_require_method('GET');

function dt_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function dt_first(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function dt_rate(int $correct, int $wrong): float
{
    $total = $correct + $wrong;
    return $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $filters = stats_resolve_filters_from_query();
    $courseFilter = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id', 191);
    $qualificationFilter = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id', 191);

    $payload = [
        'filters' => [
            'range' => $filters['range'],
            'available_ranges' => $filters['available_ranges'],
            'start_date' => $filters['start_date'],
            'end_date' => $filters['end_date'],
            'course_id' => $courseFilter !== '' ? $courseFilter : null,
            'qualification_id' => $qualificationFilter !== '' ? $qualificationFilter : null,
        ],
        'summary_for_range' => [
            'total_answer_attempts' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0.0,
            'unique_answered_count' => 0,
        ],
        'daily_activity' => [],
        'success_rate_trend' => [],
        'qualification_stats_for_range' => [],
        'course_stats_for_range' => [],
        'active_days' => [],
        'streak' => [
            'current_streak_days' => 0,
            'active_days_last_7' => 0,
            'active_days_last_30' => 0,
        ],
        'recent_activity' => [],
    ];

    $evCols = get_table_columns($pdo, 'question_attempt_events');
    if (empty($evCols) || !in_array('user_id', $evCols, true)) {
        api_success('Dashboard trend istatistikleri alındı.', ['trends' => $payload]);
    }

    $evQuestionId = dt_first($evCols, ['question_id']);
    $evCourseId = dt_first($evCols, ['course_id']);
    $evQualificationId = dt_first($evCols, ['qualification_id']);
    $evTopicId = dt_first($evCols, ['topic_id']);
    $evSource = dt_first($evCols, ['source']);
    $evSelectedAnswer = dt_first($evCols, ['selected_answer']);
    $evIsCorrect = dt_first($evCols, ['is_correct']);
    $evDate = dt_first($evCols, ['attempted_at', 'created_at']);

    if (!$evDate) {
        api_success('Dashboard trend istatistikleri alındı.', ['trends' => $payload]);
    }

    $baseWhere = ['e.`user_id` = ?'];
    $baseParams = [$userId];

    $dateSql = stats_build_date_between_sql('e.' . dt_q($evDate), $filters['start_date'], $filters['end_date'], $baseParams);
    if ($dateSql !== '') {
        $baseWhere[] = $dateSql;
    }

    if ($courseFilter !== '' && $evCourseId) {
        $baseWhere[] = 'e.' . dt_q($evCourseId) . ' = ?';
        $baseParams[] = $courseFilter;
    }
    if ($qualificationFilter !== '' && $evQualificationId) {
        $baseWhere[] = 'e.' . dt_q($evQualificationId) . ' = ?';
        $baseParams[] = $qualificationFilter;
    }

    $whereSql = implode(' AND ', $baseWhere);
    $correctExpr = $evIsCorrect ? 'CASE WHEN e.' . dt_q($evIsCorrect) . ' = 1 THEN 1 ELSE 0 END' : '0';
    $wrongExpr = $evIsCorrect ? 'CASE WHEN e.' . dt_q($evIsCorrect) . ' = 0 THEN 1 ELSE 0 END' : '0';

    // Summary for range
    $summarySql = 'SELECT COUNT(*) AS total_answer_attempts, '
        . 'COALESCE(SUM(' . $correctExpr . '),0) AS total_correct, '
        . 'COALESCE(SUM(' . $wrongExpr . '),0) AS total_wrong, '
        . ($evQuestionId ? 'COUNT(DISTINCT e.' . dt_q($evQuestionId) . ')' : '0') . ' AS unique_answered_count '
        . 'FROM `question_attempt_events` e '
        . 'WHERE ' . $whereSql;

    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($baseParams);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $payload['summary_for_range']['total_answer_attempts'] = (int)($summary['total_answer_attempts'] ?? 0);
    $payload['summary_for_range']['total_correct'] = (int)($summary['total_correct'] ?? 0);
    $payload['summary_for_range']['total_wrong'] = (int)($summary['total_wrong'] ?? 0);
    $payload['summary_for_range']['unique_answered_count'] = (int)($summary['unique_answered_count'] ?? 0);
    $payload['summary_for_range']['success_rate'] = dt_rate(
        $payload['summary_for_range']['total_correct'],
        $payload['summary_for_range']['total_wrong']
    );

    // Daily activity + success rate trend
    $dailySql = 'SELECT DATE(e.' . dt_q($evDate) . ') AS activity_date, '
        . 'COUNT(*) AS total_answer_attempts, '
        . 'COALESCE(SUM(' . $correctExpr . '),0) AS total_correct, '
        . 'COALESCE(SUM(' . $wrongExpr . '),0) AS total_wrong '
        . 'FROM `question_attempt_events` e '
        . 'WHERE ' . $whereSql . ' '
        . 'GROUP BY DATE(e.' . dt_q($evDate) . ') '
        . 'ORDER BY activity_date ASC';

    $stmt = $pdo->prepare($dailySql);
    $stmt->execute($baseParams);
    $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($dailyRows as $row) {
        $correct = (int)($row['total_correct'] ?? 0);
        $wrong = (int)($row['total_wrong'] ?? 0);
        $rate = dt_rate($correct, $wrong);

        $payload['daily_activity'][] = [
            'date' => (string)($row['activity_date'] ?? ''),
            'total_answer_attempts' => (int)($row['total_answer_attempts'] ?? 0),
            'total_correct' => $correct,
            'total_wrong' => $wrong,
            'success_rate' => $rate,
        ];

        $payload['success_rate_trend'][] = [
            'date' => (string)($row['activity_date'] ?? ''),
            'success_rate' => $rate,
        ];
    }

    // Qualification/Course stats for range
    $cCols = get_table_columns($pdo, 'courses');
    $qualCols = get_table_columns($pdo, 'qualifications');
    $cId = dt_first($cCols, ['id']);
    $cName = dt_first($cCols, ['name', 'title']);
    $cQualificationId = dt_first($cCols, ['qualification_id']);
    $qualId = dt_first($qualCols, ['id']);
    $qualName = dt_first($qualCols, ['name', 'title']);

    if ($evCourseId && $cId && $cName) {
        $courseSql = 'SELECT '
            . 'e.' . dt_q($evCourseId) . ' AS course_id, '
            . 'c.' . dt_q($cName) . ' AS course_name, '
            . (($cQualificationId && $evQualificationId) ? 'COALESCE(e.' . dt_q($evQualificationId) . ', c.' . dt_q($cQualificationId) . ') AS qualification_id, ' : 'NULL AS qualification_id, ')
            . (($qualName && $qualId && $cQualificationId) ? 'qf.' . dt_q($qualName) . ' AS qualification_name, ' : "'' AS qualification_name, ")
            . 'COUNT(*) AS total_answer_attempts, '
            . 'COALESCE(SUM(' . $correctExpr . '),0) AS total_correct, '
            . 'COALESCE(SUM(' . $wrongExpr . '),0) AS total_wrong '
            . 'FROM `question_attempt_events` e '
            . 'LEFT JOIN `courses` c ON e.' . dt_q($evCourseId) . ' = c.' . dt_q($cId) . ' '
            . (($qualName && $qualId && $cQualificationId) ? 'LEFT JOIN `qualifications` qf ON c.' . dt_q($cQualificationId) . ' = qf.' . dt_q($qualId) . ' ' : '')
            . 'WHERE ' . $whereSql . ' '
            . 'GROUP BY e.' . dt_q($evCourseId) . ', c.' . dt_q($cName)
            . (($cQualificationId && $evQualificationId) ? ', COALESCE(e.' . dt_q($evQualificationId) . ', c.' . dt_q($cQualificationId) . ')' : '')
            . (($qualName && $qualId && $cQualificationId) ? ', qf.' . dt_q($qualName) : '')
            . ' ORDER BY total_answer_attempts DESC';

        $stmt = $pdo->prepare($courseSql);
        $stmt->execute($baseParams);
        $courseRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($courseRows as $row) {
            $correct = (int)($row['total_correct'] ?? 0);
            $wrong = (int)($row['total_wrong'] ?? 0);
            $payload['course_stats_for_range'][] = [
                'course_id' => $row['course_id'] ?? null,
                'course_name' => (string)($row['course_name'] ?? ''),
                'qualification_id' => $row['qualification_id'] ?? null,
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'total_answer_attempts' => (int)($row['total_answer_attempts'] ?? 0),
                'total_correct' => $correct,
                'total_wrong' => $wrong,
                'success_rate' => dt_rate($correct, $wrong),
            ];
        }

        $byQualification = [];
        foreach ($payload['course_stats_for_range'] as $course) {
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
                ];
            }

            $byQualification[$qid]['total_answer_attempts'] += (int)$course['total_answer_attempts'];
            $byQualification[$qid]['total_correct'] += (int)$course['total_correct'];
            $byQualification[$qid]['total_wrong'] += (int)$course['total_wrong'];
            $byQualification[$qid]['course_count'] += 1;
        }

        foreach ($byQualification as $row) {
            $row['success_rate'] = dt_rate((int)$row['total_correct'], (int)$row['total_wrong']);
            $payload['qualification_stats_for_range'][] = $row;
        }

        usort($payload['qualification_stats_for_range'], static fn(array $a, array $b): int => $b['total_answer_attempts'] <=> $a['total_answer_attempts']);
    }

    // active days + streak
    $activeSql = 'SELECT DISTINCT DATE(e.' . dt_q($evDate) . ') AS d FROM `question_attempt_events` e WHERE ' . $whereSql . ' ORDER BY d DESC';
    $stmt = $pdo->prepare($activeSql);
    $stmt->execute($baseParams);
    $days = array_values(array_filter(array_map(static fn(array $r): string => (string)($r['d'] ?? ''), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));
    $payload['active_days'] = $days;

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

    $payload['streak'] = [
        'current_streak_days' => $streak,
        'active_days_last_7' => $last7,
        'active_days_last_30' => $last30,
    ];

    // recent activity events
    $selectRecent = [
        ($evDate ? 'e.' . dt_q($evDate) : 'NULL') . ' AS attempted_at',
        ($evQuestionId ? 'e.' . dt_q($evQuestionId) : 'NULL') . ' AS question_id',
        ($evCourseId ? 'e.' . dt_q($evCourseId) : 'NULL') . ' AS course_id',
        ($evQualificationId ? 'e.' . dt_q($evQualificationId) : 'NULL') . ' AS qualification_id',
        ($evTopicId ? 'e.' . dt_q($evTopicId) : 'NULL') . ' AS topic_id',
        ($evSource ? 'e.' . dt_q($evSource) : "''") . ' AS source',
        ($evSelectedAnswer ? 'e.' . dt_q($evSelectedAnswer) : 'NULL') . ' AS selected_answer',
        ($evIsCorrect ? 'e.' . dt_q($evIsCorrect) : 'NULL') . ' AS is_correct',
    ];

    $recentSql = 'SELECT ' . implode(', ', $selectRecent)
        . ' FROM `question_attempt_events` e '
        . ' WHERE ' . $whereSql
        . ' ORDER BY e.' . dt_q($evDate) . ' DESC LIMIT 20';

    $stmt = $pdo->prepare($recentSql);
    $stmt->execute($baseParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $payload['recent_activity'][] = [
            'attempted_at' => $row['attempted_at'] ?? null,
            'question_id' => $row['question_id'] ?? null,
            'course_id' => $row['course_id'] ?? null,
            'qualification_id' => $row['qualification_id'] ?? null,
            'topic_id' => $row['topic_id'] ?? null,
            'source' => (string)($row['source'] ?? ''),
            'selected_answer' => $row['selected_answer'] ?? null,
            'is_correct' => isset($row['is_correct']) ? ((int)$row['is_correct'] === 1) : null,
        ];
    }

    api_success('Dashboard trend istatistikleri alındı.', [
        'trends' => $payload,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

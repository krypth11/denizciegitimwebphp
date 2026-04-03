<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

api_require_method('GET');

function stats_dbg(string $message, array $context = []): void
{
    $suffix = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    error_log('[dashboard.statistics] ' . $message . $suffix);
}

function stats_first_col(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function stats_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function stats_rate(int $correct, int $wrong): float
{
    $den = $correct + $wrong;
    if ($den <= 0) {
        return 0.0;
    }
    return round(($correct / $den) * 100, 2);
}

function stats_normalize_bucket(int $correct, int $wrong): array
{
    $c = max(0, $correct);
    $w = max(0, $wrong);
    return [
        'total_correct' => $c,
        'total_wrong' => $w,
        'total_solved' => $c + $w,
        'success_rate' => stats_rate($c, $w),
    ];
}

function stats_normalize_source(?string $source): string
{
    $normalized = strtolower(trim((string)$source));
    if ($normalized === '' || $normalized === 'study') {
        return 'study';
    }

    if (in_array($normalized, ['maritime_english', 'maritime-english', 'me', 'me_quiz', 'maritime_english_quiz'], true)) {
        return 'maritime_english';
    }

    if (in_array($normalized, ['daily_quiz', 'daily-quiz', 'daily quiz'], true)) {
        return 'daily_quiz';
    }

    if (in_array($normalized, ['mock_exam', 'mock-exam', 'exam', 'exam_attempt', 'exam_attempt_completed'], true)) {
        return 'mock_exam';
    }

    return str_replace(' ', '_', $normalized);
}

function admin_stats_detect_guest_sql(string $emailExpr, ?string $fullNameExpr): string
{
    $nameExpr = $fullNameExpr ? 'LOWER(TRIM(' . $fullNameExpr . '))' : "''";
    return '(LOWER(' . $emailExpr . ") LIKE '%@guest.local' OR " . $nameExpr . " IN ('misafir kullanıcı', 'misafir kullanici', 'guest user'))";
}

if (($_GET['scope'] ?? '') === 'admin') {
    try {
        $auth = api_require_auth($pdo);
        $isAdmin = !empty($auth['user']['is_admin']);
        if (!$isAdmin) {
            api_error('Admin yetkisi gerekli.', 403);
        }

        $qCols = get_table_columns($pdo, 'questions');
        $cCols = get_table_columns($pdo, 'courses');
        $upCols = get_table_columns($pdo, 'user_profiles');
        $attemptCols = get_table_columns($pdo, 'question_attempt_events');

        $qCourseCol = stats_first_col($qCols, ['course_id']);
        $cIdCol = stats_first_col($cCols, ['id']);
        $cQualificationCol = stats_first_col($cCols, ['qualification_id']);

        $qualificationId = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id');
        $courseId = api_validate_optional_id((string)($_GET['course_id'] ?? ''), 'course_id');
        $userType = strtolower(trim((string)($_GET['user_type'] ?? 'all')));
        if (!in_array($userType, ['all', 'guest', 'registered'], true)) {
            $userType = 'all';
        }

        $solvedWindow = stats_resolve_date_window($_GET, 'solved_range', 'solved_start_date', 'solved_end_date', '7d');

        // Card 1: Toplam Soru
        $qWhere = ['1=1'];
        $qParams = [];
        $qJoin = '';
        if ($qCourseCol && $cIdCol && $cQualificationCol) {
            $qJoin = ' LEFT JOIN `courses` c ON q.`' . $qCourseCol . '` = c.`' . $cIdCol . '`';
            if ($qualificationId !== '') {
                $qWhere[] = 'c.`' . $cQualificationCol . '` = ?';
                $qParams[] = $qualificationId;
            }
            if ($courseId !== '') {
                $qWhere[] = 'q.`' . $qCourseCol . '` = ?';
                $qParams[] = $courseId;
            }
        }

        $sqlQuestions = 'SELECT COUNT(*) FROM `questions` q' . $qJoin . ' WHERE ' . implode(' AND ', $qWhere);
        $stmtQuestions = $pdo->prepare($sqlQuestions);
        $stmtQuestions->execute($qParams);
        $totalQuestions = (int)$stmtQuestions->fetchColumn();

        // Card 2: Çözülen Soru Sayısı
        $attemptDateCol = stats_first_col($attemptCols, ['attempted_at', 'created_at', 'updated_at']);
        $solvedCount = 0;
        if (!empty($attemptCols) && $attemptDateCol) {
            $sWhere = ['1=1'];
            $sParams = [];
            $dateSql = stats_build_date_between_sql('e.`' . $attemptDateCol . '`', $solvedWindow['start_date'], $solvedWindow['end_date'], $sParams);
            if ($dateSql !== '') {
                $sWhere[] = $dateSql;
            }

            $sqlSolved = 'SELECT COUNT(*) FROM `question_attempt_events` e WHERE ' . implode(' AND ', $sWhere);
            $stmtSolved = $pdo->prepare($sqlSolved);
            $stmtSolved->execute($sParams);
            $solvedCount = (int)$stmtSolved->fetchColumn();
        }

        // Card 3: Toplam Kullanıcı Sayısı
        $uEmailCol = stats_first_col($upCols, ['email']);
        $uFullNameCol = stats_first_col($upCols, ['full_name', 'name', 'display_name']);
        $uDeletedCol = stats_first_col($upCols, ['is_deleted']);

        $uWhere = ['1=1'];
        $uParams = [];
        if ($uDeletedCol) {
            $uWhere[] = '`' . $uDeletedCol . '` = 0';
        }
        if ($uEmailCol) {
            $guestSql = admin_stats_detect_guest_sql('`' . $uEmailCol . '`', $uFullNameCol ? ('`' . $uFullNameCol . '`') : null);
            if ($userType === 'guest') {
                $uWhere[] = $guestSql;
            } elseif ($userType === 'registered') {
                $uWhere[] = 'NOT ' . $guestSql;
            }
        }

        $sqlUsers = 'SELECT COUNT(*) FROM `user_profiles` WHERE ' . implode(' AND ', $uWhere);
        $stmtUsers = $pdo->prepare($sqlUsers);
        $stmtUsers->execute($uParams);
        $totalUsers = (int)$stmtUsers->fetchColumn();

        api_success('Dashboard admin istatistikleri alındı.', [
            'statistics' => [
                'total_questions' => $totalQuestions,
                'solved_questions_count' => $solvedCount,
                'total_users' => $totalUsers,
                'filters' => [
                    'qualification_id' => $qualificationId,
                    'course_id' => $courseId,
                    'user_type' => $userType,
                    'solved_range' => $solvedWindow['range'],
                    'solved_start_date' => $solvedWindow['start_date'],
                    'solved_end_date' => $solvedWindow['end_date'],
                ],
            ],
        ]);
    } catch (Throwable $e) {
        api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
    }
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $statistics = [
        'total_solved' => 0,
        'total_correct' => 0,
        'total_wrong' => 0,
        'total_study_duration_seconds' => 0,
        'total_sessions' => 0,
        'active_days_last_7' => 0,
        'success_rate_last_7' => 0.0,
        'source_stats' => [],
        'qualification_stats' => [],
        'course_stats' => [],
        'stats_rows' => [],
    ];

    $sqlTotals = 'SELECT '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 1 THEN 1 ELSE 0 END),0) AS total_correct, '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 0 THEN 1 ELSE 0 END),0) AS total_wrong, '
        . 'COALESCE(SUM(CASE WHEN `is_correct` IS NULL THEN 1 ELSE 0 END),0) AS total_unknown '
        . 'FROM `question_attempt_events` WHERE `user_id` = ?';
    $stmtTotals = $pdo->prepare($sqlTotals);
    $stmtTotals->execute([$userId]);
    $rowTotals = $stmtTotals->fetch(PDO::FETCH_ASSOC) ?: [];

    $normalizedTotals = stats_normalize_bucket((int)($rowTotals['total_correct'] ?? 0), (int)($rowTotals['total_wrong'] ?? 0));
    $statistics['total_correct'] = (int)$normalizedTotals['total_correct'];
    $statistics['total_wrong'] = (int)$normalizedTotals['total_wrong'];
    $statistics['total_solved'] = (int)$normalizedTotals['total_solved'];

    $sourceMap = [
        'study' => [
            'source' => 'study',
            'total_solved' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0.0,
        ],
        'maritime_english' => [
            'source' => 'maritime_english',
            'total_solved' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0.0,
        ],
        'daily_quiz' => [
            'source' => 'daily_quiz',
            'total_solved' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0.0,
        ],
        'mock_exam' => [
            'source' => 'mock_exam',
            'total_solved' => 0,
            'total_correct' => 0,
            'total_wrong' => 0,
            'success_rate' => 0.0,
        ],
    ];

    $sqlSourceStats = 'SELECT '
        . 'LOWER(TRIM(COALESCE(`source`, ""))) AS source_raw, '
        . 'COUNT(*) AS total_solved, '
        . 'SUM(CASE WHEN `is_correct` = 1 THEN 1 ELSE 0 END) AS total_correct, '
        . 'SUM(CASE WHEN `is_correct` = 0 THEN 1 ELSE 0 END) AS total_wrong '
        . 'FROM `question_attempt_events` '
        . 'WHERE `user_id` = ? '
        . 'GROUP BY LOWER(TRIM(COALESCE(`source`, "")))';
    $stmtSourceStats = $pdo->prepare($sqlSourceStats);
    $stmtSourceStats->execute([$userId]);
    $rowsSourceStats = $stmtSourceStats->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rowsSourceStats as $row) {
        $sourceKey = stats_normalize_source((string)($row['source_raw'] ?? ''));
        if (!isset($sourceMap[$sourceKey])) {
            $sourceMap[$sourceKey] = [
                'source' => $sourceKey,
                'total_solved' => 0,
                'total_correct' => 0,
                'total_wrong' => 0,
                'success_rate' => 0.0,
            ];
        }

        $sourceMap[$sourceKey]['total_correct'] += (int)($row['total_correct'] ?? 0);
        $sourceMap[$sourceKey]['total_wrong'] += (int)($row['total_wrong'] ?? 0);
    }

    foreach ($sourceMap as $key => $item) {
        $normalized = stats_normalize_bucket((int)$item['total_correct'], (int)$item['total_wrong']);
        $sourceMap[$key]['total_solved'] = (int)$normalized['total_solved'];
        $sourceMap[$key]['total_correct'] = (int)$normalized['total_correct'];
        $sourceMap[$key]['total_wrong'] = (int)$normalized['total_wrong'];
        $sourceMap[$key]['success_rate'] = (float)$normalized['success_rate'];
    }

    $statistics['source_stats'] = array_values($sourceMap);

    $rawStudyTotals = $sourceMap['study'] ?? ['total_solved' => 0, 'total_correct' => 0, 'total_wrong' => 0];
    $rawMockExamTotals = $sourceMap['mock_exam'] ?? ['total_solved' => 0, 'total_correct' => 0, 'total_wrong' => 0];
    stats_dbg('raw source totals', [
        'user_id' => $userId,
        'study' => $rawStudyTotals,
        'mock_exam' => $rawMockExamTotals,
    ]);

    $mergedCorrect = 0;
    $mergedWrong = 0;
    foreach ($sourceMap as $s) {
        $mergedCorrect += (int)($s['total_correct'] ?? 0);
        $mergedWrong += (int)($s['total_wrong'] ?? 0);
    }
    $mergedNormalized = stats_normalize_bucket($mergedCorrect, $mergedWrong);
    stats_dbg('merged totals', [
        'user_id' => $userId,
        'merged_correct' => $mergedCorrect,
        'merged_wrong' => $mergedWrong,
        'merged_solved' => $mergedCorrect + $mergedWrong,
    ]);
    stats_dbg('normalized totals', [
        'user_id' => $userId,
        'total_correct' => $mergedNormalized['total_correct'],
        'total_wrong' => $mergedNormalized['total_wrong'],
        'total_solved' => $mergedNormalized['total_solved'],
        'total_unknown_is_correct' => (int)($rowTotals['total_unknown'] ?? 0),
    ]);

    $statistics['total_correct'] = (int)$mergedNormalized['total_correct'];
    $statistics['total_wrong'] = (int)$mergedNormalized['total_wrong'];
    $statistics['total_solved'] = (int)$mergedNormalized['total_solved'];

    // Son 7 gün event bazlı metrikler (active_days + success_rate)
    $sqlLast7 = 'SELECT '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 1 THEN 1 ELSE 0 END),0) AS correct_last_7, '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 0 THEN 1 ELSE 0 END),0) AS wrong_last_7, '
        . 'COUNT(DISTINCT DATE(`attempted_at`)) AS active_days_last_7 '
        . 'FROM `question_attempt_events` '
        . 'WHERE `user_id` = ? '
        . 'AND `attempted_at` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';

    $stmtLast7 = $pdo->prepare($sqlLast7);
    $stmtLast7->execute([$userId]);
    $rowLast7 = $stmtLast7->fetch(PDO::FETCH_ASSOC) ?: [];

    $correctLast7 = (int)($rowLast7['correct_last_7'] ?? 0);
    $wrongLast7 = (int)($rowLast7['wrong_last_7'] ?? 0);
    $statistics['active_days_last_7'] = (int)($rowLast7['active_days_last_7'] ?? 0);
    $statistics['success_rate_last_7'] = (float)stats_rate($correctLast7, $wrongLast7);

    // Session tabanlı metrikler (toplam süre + toplam session)
    $ssCols = get_table_columns($pdo, 'study_sessions');
    if (!empty($ssCols) && in_array('user_id', $ssCols, true)) {
        $durationCol = stats_first_col($ssCols, ['duration_seconds']);

        $sqlSessions = 'SELECT COUNT(*) AS total_sessions, '
            . ($durationCol ? 'COALESCE(SUM(' . stats_q($durationCol) . '),0)' : '0') . ' AS total_study_duration_seconds '
            . 'FROM `study_sessions` WHERE `user_id` = ?';

        $stmtSessions = $pdo->prepare($sqlSessions);
        $stmtSessions->execute([$userId]);
        $rowSessions = $stmtSessions->fetch(PDO::FETCH_ASSOC) ?: [];

        $statistics['total_sessions'] = (int)($rowSessions['total_sessions'] ?? 0);
        $statistics['total_study_duration_seconds'] = (int)($rowSessions['total_study_duration_seconds'] ?? 0);
    }

    // Qualification/Course bazlı istatistikler (event + joins)
    $qCols = get_table_columns($pdo, 'questions');
    $cCols = get_table_columns($pdo, 'courses');
    $qualCols = get_table_columns($pdo, 'qualifications');
    $meqCols = get_table_columns($pdo, 'maritime_english_questions');
    $metCols = get_table_columns($pdo, 'maritime_english_topics');

    $qIdCol = stats_first_col($qCols, ['id']);
    $qCourseIdCol = stats_first_col($qCols, ['course_id']);
    $cIdCol = stats_first_col($cCols, ['id']);
    $cNameCol = stats_first_col($cCols, ['name', 'title']);
    $cQualificationIdCol = stats_first_col($cCols, ['qualification_id']);
    $qualIdCol = stats_first_col($qualCols, ['id']);
    $qualNameCol = stats_first_col($qualCols, ['name', 'title']);

    $meqIdCol = stats_first_col($meqCols, ['id', 'question_id']);
    $meqTopicIdCol = stats_first_col($meqCols, ['topic_id', 'maritime_english_topic_id']);
    $metIdCol = stats_first_col($metCols, ['id', 'topic_id']);
    $metNameCol = stats_first_col($metCols, ['name', 'title', 'topic_name']);

    if ($qIdCol && $qCourseIdCol && $cIdCol && $cNameCol && $cQualificationIdCol && $qualIdCol && $qualNameCol) {
        $sqlQualificationStats = 'SELECT '
            . 'qf.' . stats_q($qualIdCol) . ' AS qualification_id, '
            . 'qf.' . stats_q($qualNameCol) . ' AS qualification_name, '
            . 'SUM(CASE WHEN e.`is_correct` IN (0,1) THEN 1 ELSE 0 END) AS total_solved, '
            . 'SUM(CASE WHEN e.`is_correct` = 1 THEN 1 ELSE 0 END) AS total_correct, '
            . 'SUM(CASE WHEN e.`is_correct` = 0 THEN 1 ELSE 0 END) AS total_wrong '
            . 'FROM `question_attempt_events` e '
            . 'INNER JOIN `questions` q ON e.`question_id` = q.' . stats_q($qIdCol) . ' '
            . 'INNER JOIN `courses` c ON q.' . stats_q($qCourseIdCol) . ' = c.' . stats_q($cIdCol) . ' '
            . 'INNER JOIN `qualifications` qf ON c.' . stats_q($cQualificationIdCol) . ' = qf.' . stats_q($qualIdCol) . ' '
            . 'WHERE e.`user_id` = ? '
            . 'GROUP BY qf.' . stats_q($qualIdCol) . ', qf.' . stats_q($qualNameCol) . ' '
            . 'ORDER BY total_solved DESC, qualification_name ASC';

        $stmtQualificationStats = $pdo->prepare($sqlQualificationStats);
        $stmtQualificationStats->execute([$userId]);
        $rowsQualificationStats = $stmtQualificationStats->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rowsQualificationStats as $row) {
            $normalized = stats_normalize_bucket((int)($row['total_correct'] ?? 0), (int)($row['total_wrong'] ?? 0));

            $statistics['qualification_stats'][] = [
                'qualification_id' => (string)($row['qualification_id'] ?? ''),
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'total_solved' => (int)$normalized['total_solved'],
                'total_correct' => (int)$normalized['total_correct'],
                'total_wrong' => (int)$normalized['total_wrong'],
                'success_rate' => (float)$normalized['success_rate'],
            ];
        }

        $sqlCourseStats = 'SELECT '
            . 'c.' . stats_q($cIdCol) . ' AS course_id, '
            . 'c.' . stats_q($cNameCol) . ' AS course_name, '
            . 'qf.' . stats_q($qualIdCol) . ' AS qualification_id, '
            . 'qf.' . stats_q($qualNameCol) . ' AS qualification_name, '
            . 'SUM(CASE WHEN e.`is_correct` IN (0,1) THEN 1 ELSE 0 END) AS total_solved, '
            . 'SUM(CASE WHEN e.`is_correct` = 1 THEN 1 ELSE 0 END) AS total_correct, '
            . 'SUM(CASE WHEN e.`is_correct` = 0 THEN 1 ELSE 0 END) AS total_wrong '
            . 'FROM `question_attempt_events` e '
            . 'INNER JOIN `questions` q ON e.`question_id` = q.' . stats_q($qIdCol) . ' '
            . 'INNER JOIN `courses` c ON q.' . stats_q($qCourseIdCol) . ' = c.' . stats_q($cIdCol) . ' '
            . 'INNER JOIN `qualifications` qf ON c.' . stats_q($cQualificationIdCol) . ' = qf.' . stats_q($qualIdCol) . ' '
            . 'WHERE e.`user_id` = ? '
            . 'GROUP BY c.' . stats_q($cIdCol) . ', c.' . stats_q($cNameCol) . ', qf.' . stats_q($qualIdCol) . ', qf.' . stats_q($qualNameCol) . ' '
            . 'ORDER BY total_solved DESC, course_name ASC';

        $stmtCourseStats = $pdo->prepare($sqlCourseStats);
        $stmtCourseStats->execute([$userId]);
        $rowsCourseStats = $stmtCourseStats->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rowsCourseStats as $row) {
            $normalized = stats_normalize_bucket((int)($row['total_correct'] ?? 0), (int)($row['total_wrong'] ?? 0));

            $statistics['course_stats'][] = [
                'course_id' => (string)($row['course_id'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'qualification_id' => (string)($row['qualification_id'] ?? ''),
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'total_solved' => (int)$normalized['total_solved'],
                'total_correct' => (int)$normalized['total_correct'],
                'total_wrong' => (int)$normalized['total_wrong'],
                'success_rate' => (float)$normalized['success_rate'],
            ];
        }

        // Flutter için sade tek liste (qualification + course)
        $sqlStatsRows = 'SELECT '
            . 'qf.' . stats_q($qualNameCol) . ' AS qualification_name, '
            . 'c.' . stats_q($cNameCol) . ' AS course_name, '
            . 'SUM(CASE WHEN e.`is_correct` IN (0,1) THEN 1 ELSE 0 END) AS total_solved, '
            . 'SUM(CASE WHEN e.`is_correct` = 1 THEN 1 ELSE 0 END) AS total_correct, '
            . 'SUM(CASE WHEN e.`is_correct` = 0 THEN 1 ELSE 0 END) AS total_wrong '
            . 'FROM `question_attempt_events` e '
            . 'INNER JOIN `questions` q ON e.`question_id` = q.' . stats_q($qIdCol) . ' '
            . 'INNER JOIN `courses` c ON q.' . stats_q($qCourseIdCol) . ' = c.' . stats_q($cIdCol) . ' '
            . 'INNER JOIN `qualifications` qf ON c.' . stats_q($cQualificationIdCol) . ' = qf.' . stats_q($qualIdCol) . ' '
            . 'WHERE e.`user_id` = ? '
            . 'GROUP BY qf.' . stats_q($qualNameCol) . ', c.' . stats_q($cNameCol) . ' '
            . 'ORDER BY qualification_name ASC, course_name ASC';

        $stmtStatsRows = $pdo->prepare($sqlStatsRows);
        $stmtStatsRows->execute([$userId]);
        $rowsStatsRows = $stmtStatsRows->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rowsStatsRows as $row) {
            $normalized = stats_normalize_bucket((int)($row['total_correct'] ?? 0), (int)($row['total_wrong'] ?? 0));

            $statistics['stats_rows'][] = [
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'total_solved' => (int)$normalized['total_solved'],
                'total_correct' => (int)$normalized['total_correct'],
                'total_wrong' => (int)$normalized['total_wrong'],
                'success_rate' => (float)$normalized['success_rate'],
            ];
        }
    }

    if ($meqIdCol && $meqTopicIdCol && $metIdCol && $metNameCol) {
        $sqlMeCourseStats = 'SELECT '
            . 't.' . stats_q($metIdCol) . ' AS topic_id, '
            . 't.' . stats_q($metNameCol) . ' AS topic_name, '
            . 'COUNT(*) AS total_solved, '
            . 'SUM(CASE WHEN e.`is_correct` = 1 THEN 1 ELSE 0 END) AS total_correct, '
            . 'SUM(CASE WHEN e.`is_correct` = 0 THEN 1 ELSE 0 END) AS total_wrong '
            . 'FROM `question_attempt_events` e '
            . 'INNER JOIN `maritime_english_questions` meq ON e.`question_id` = meq.' . stats_q($meqIdCol) . ' '
            . 'INNER JOIN `maritime_english_topics` t ON meq.' . stats_q($meqTopicIdCol) . ' = t.' . stats_q($metIdCol) . ' '
            . 'WHERE e.`user_id` = ? '
            . 'AND LOWER(TRIM(COALESCE(e.`source`, ""))) IN ("maritime_english", "maritime-english", "me", "me_quiz", "maritime_english_quiz") '
            . 'GROUP BY t.' . stats_q($metIdCol) . ', t.' . stats_q($metNameCol) . ' '
            . 'ORDER BY total_solved DESC, topic_name ASC';

        $stmtMeCourseStats = $pdo->prepare($sqlMeCourseStats);
        $stmtMeCourseStats->execute([$userId]);
        $rowsMeCourseStats = $stmtMeCourseStats->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $meTotalSolved = 0;
        $meTotalCorrect = 0;
        $meTotalWrong = 0;

        foreach ($rowsMeCourseStats as $row) {
            $normalized = stats_normalize_bucket((int)($row['total_correct'] ?? 0), (int)($row['total_wrong'] ?? 0));
            $totalSolved = (int)$normalized['total_solved'];
            $totalCorrect = (int)$normalized['total_correct'];
            $totalWrong = (int)$normalized['total_wrong'];

            $meTotalSolved += $totalSolved;
            $meTotalCorrect += $totalCorrect;
            $meTotalWrong += $totalWrong;

            $statistics['course_stats'][] = [
                'course_id' => (string)($row['topic_id'] ?? ''),
                'course_name' => (string)($row['topic_name'] ?? ''),
                'qualification_id' => 'maritime_english',
                'qualification_name' => 'Maritime English',
                'total_solved' => $totalSolved,
                'total_correct' => $totalCorrect,
                'total_wrong' => $totalWrong,
                'success_rate' => (float)$normalized['success_rate'],
            ];

            $statistics['stats_rows'][] = [
                'qualification_name' => 'Maritime English',
                'course_name' => (string)($row['topic_name'] ?? ''),
                'total_solved' => $totalSolved,
                'total_correct' => $totalCorrect,
                'total_wrong' => $totalWrong,
                'success_rate' => (float)$normalized['success_rate'],
            ];
        }

        if ($meTotalSolved > 0) {
            $meNormalized = stats_normalize_bucket($meTotalCorrect, $meTotalWrong);
            $statistics['qualification_stats'][] = [
                'qualification_id' => 'maritime_english',
                'qualification_name' => 'Maritime English',
                'total_solved' => (int)$meNormalized['total_solved'],
                'total_correct' => (int)$meNormalized['total_correct'],
                'total_wrong' => (int)$meNormalized['total_wrong'],
                'success_rate' => (float)$meNormalized['success_rate'],
            ];
        }
    }

    // Debug: 0 durumunda query + user_id context logla
    if ($statistics['total_solved'] === 0) {
        stats_dbg('total_solved is zero', [
            'user_id' => $userId,
            'query_totals' => $sqlTotals,
            'total_correct' => $statistics['total_correct'],
            'total_wrong' => $statistics['total_wrong'],
        ]);
    }

    api_success('Dashboard istatistikleri alındı.', [
        'statistics' => $statistics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

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
        'qualification_stats' => [],
        'course_stats' => [],
        'stats_rows' => [],
    ];

    $sqlSolved = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE `user_id` = ?';
    $stmtSolved = $pdo->prepare($sqlSolved);
    $stmtSolved->execute([$userId]);
    $statistics['total_solved'] = (int)$stmtSolved->fetchColumn();

    $sqlCorrect = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE `user_id` = ? AND `is_correct` = 1';
    $stmtCorrect = $pdo->prepare($sqlCorrect);
    $stmtCorrect->execute([$userId]);
    $statistics['total_correct'] = (int)$stmtCorrect->fetchColumn();

    $sqlWrong = 'SELECT COUNT(*) FROM `question_attempt_events` WHERE `user_id` = ? AND `is_correct` = 0';
    $stmtWrong = $pdo->prepare($sqlWrong);
    $stmtWrong->execute([$userId]);
    $statistics['total_wrong'] = (int)$stmtWrong->fetchColumn();

    // İstenen kesin tutarlılık
    $statistics['total_solved'] = $statistics['total_correct'] + $statistics['total_wrong'];

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

    $qIdCol = stats_first_col($qCols, ['id']);
    $qCourseIdCol = stats_first_col($qCols, ['course_id']);
    $cIdCol = stats_first_col($cCols, ['id']);
    $cNameCol = stats_first_col($cCols, ['name', 'title']);
    $cQualificationIdCol = stats_first_col($cCols, ['qualification_id']);
    $qualIdCol = stats_first_col($qualCols, ['id']);
    $qualNameCol = stats_first_col($qualCols, ['name', 'title']);

    if ($qIdCol && $qCourseIdCol && $cIdCol && $cNameCol && $cQualificationIdCol && $qualIdCol && $qualNameCol) {
        $sqlQualificationStats = 'SELECT '
            . 'qf.' . stats_q($qualIdCol) . ' AS qualification_id, '
            . 'qf.' . stats_q($qualNameCol) . ' AS qualification_name, '
            . 'COUNT(*) AS total_solved, '
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
            $totalSolved = (int)($row['total_solved'] ?? 0);
            $totalCorrect = (int)($row['total_correct'] ?? 0);
            $totalWrong = (int)($row['total_wrong'] ?? 0);

            $statistics['qualification_stats'][] = [
                'qualification_id' => (string)($row['qualification_id'] ?? ''),
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'total_solved' => $totalSolved,
                'total_correct' => $totalCorrect,
                'total_wrong' => $totalWrong,
                'success_rate' => $totalSolved > 0 ? (float)round(($totalCorrect / $totalSolved) * 100, 2) : 0.0,
            ];
        }

        $sqlCourseStats = 'SELECT '
            . 'c.' . stats_q($cIdCol) . ' AS course_id, '
            . 'c.' . stats_q($cNameCol) . ' AS course_name, '
            . 'qf.' . stats_q($qualIdCol) . ' AS qualification_id, '
            . 'qf.' . stats_q($qualNameCol) . ' AS qualification_name, '
            . 'COUNT(*) AS total_solved, '
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
            $totalSolved = (int)($row['total_solved'] ?? 0);
            $totalCorrect = (int)($row['total_correct'] ?? 0);
            $totalWrong = (int)($row['total_wrong'] ?? 0);

            $statistics['course_stats'][] = [
                'course_id' => (string)($row['course_id'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'qualification_id' => (string)($row['qualification_id'] ?? ''),
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'total_solved' => $totalSolved,
                'total_correct' => $totalCorrect,
                'total_wrong' => $totalWrong,
                'success_rate' => $totalSolved > 0 ? (float)round(($totalCorrect / $totalSolved) * 100, 2) : 0.0,
            ];
        }

        // Flutter için sade tek liste (qualification + course)
        $sqlStatsRows = 'SELECT '
            . 'qf.' . stats_q($qualNameCol) . ' AS qualification_name, '
            . 'c.' . stats_q($cNameCol) . ' AS course_name, '
            . 'COUNT(*) AS total_solved, '
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
            $totalSolved = (int)($row['total_solved'] ?? 0);
            $totalCorrect = (int)($row['total_correct'] ?? 0);
            $totalWrong = (int)($row['total_wrong'] ?? 0);

            $statistics['stats_rows'][] = [
                'qualification_name' => (string)($row['qualification_name'] ?? ''),
                'course_name' => (string)($row['course_name'] ?? ''),
                'total_solved' => $totalSolved,
                'total_correct' => $totalCorrect,
                'total_wrong' => $totalWrong,
                'success_rate' => $totalSolved > 0 ? (float)round(($totalCorrect / $totalSolved) * 100, 2) : 0.0,
            ];
        }
    }

    // Debug: 0 durumunda query + user_id context logla
    if ($statistics['total_solved'] === 0) {
        stats_dbg('total_solved is zero', [
            'user_id' => $userId,
            'query_solved' => $sqlSolved,
            'query_correct' => $sqlCorrect,
            'query_wrong' => $sqlWrong,
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

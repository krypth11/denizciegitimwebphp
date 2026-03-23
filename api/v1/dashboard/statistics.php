<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $statistics = [
        'total_answered_questions' => 0,
        'total_correct_answers' => 0,
        'total_wrong_answers' => 0,
        'success_rate' => 0,
        'total_bookmarked_questions' => 0,
        'current_qualification_id' => null,
        'current_qualification_name' => null,
        'total_study_sessions' => 0,
        'total_study_duration_seconds' => 0,
        'completed_daily_quiz_today' => false,
        'answered_today' => 0,
        'correct_today' => 0,
        'last_study_at' => null,
    ];

    // Profil + qualification bilgisi
    $profile = api_find_profile_by_user_id($pdo, $userId);
    if ($profile) {
        $statistics['current_qualification_id'] = $profile['current_qualification_id'] ?? null;
        $statistics['current_qualification_name'] = $profile['current_qualification_name'] ?? null;
    }

    // user_progress özetleri
    $upCols = get_table_columns($pdo, 'user_progress');
    if (!empty($upCols) && in_array('user_id', $upCols, true)) {
        $has = static fn(string $col): bool => in_array($col, $upCols, true);
        $q = static fn(string $col): string => '`' . str_replace('`', '', $col) . '`';

        $isAnsweredCol = $has('is_answered') ? 'is_answered' : null;
        $isCorrectCol = $has('is_correct') ? 'is_correct' : null;
        $isBookmarkedCol = $has('is_bookmarked') ? 'is_bookmarked' : null;
        $questionIdCol = $has('question_id') ? 'question_id' : null;
        $correctCountCol = $has('correct_answer_count') ? 'correct_answer_count' : null;
        $wrongCountCol = $has('wrong_answer_count') ? 'wrong_answer_count' : null;

        $answeredAtCol = $has('answered_at') ? 'answered_at' : ($has('last_answered_at') ? 'last_answered_at' : null);
        $updatedAtCol = $has('updated_at') ? 'updated_at' : null;

        $answeredExpr = '0';
        if ($isAnsweredCol) {
            $answeredExpr = 'SUM(CASE WHEN ' . $q($isAnsweredCol) . ' = 1 THEN 1 ELSE 0 END)';
        } elseif ($questionIdCol) {
            $answeredExpr = 'COUNT(DISTINCT ' . $q($questionIdCol) . ')';
        }

        $correctExpr = '0';
        if ($correctCountCol) {
            $correctExpr = 'COALESCE(SUM(' . $q($correctCountCol) . '), 0)';
        } elseif ($isCorrectCol) {
            $correctExpr = 'SUM(CASE WHEN ' . $q($isCorrectCol) . ' = 1 THEN 1 ELSE 0 END)';
        }

        $wrongExpr = '0';
        if ($wrongCountCol) {
            $wrongExpr = 'COALESCE(SUM(' . $q($wrongCountCol) . '), 0)';
        } elseif ($isCorrectCol) {
            if ($isAnsweredCol) {
                $wrongExpr = 'SUM(CASE WHEN ' . $q($isAnsweredCol) . ' = 1 AND ' . $q($isCorrectCol) . ' = 0 THEN 1 ELSE 0 END)';
            } else {
                $wrongExpr = 'SUM(CASE WHEN ' . $q($isCorrectCol) . ' = 0 THEN 1 ELSE 0 END)';
            }
        }

        $bookmarkedExpr = $isBookmarkedCol
            ? 'SUM(CASE WHEN ' . $q($isBookmarkedCol) . ' = 1 THEN 1 ELSE 0 END)'
            : '0';

        $answeredTodayExpr = '0';
        $correctTodayExpr = '0';
        if ($answeredAtCol) {
            $answeredCondition = $isAnsweredCol ? ($q($isAnsweredCol) . ' = 1') : '1=1';
            $answeredTodayExpr = 'SUM(CASE WHEN DATE(' . $q($answeredAtCol) . ') = CURDATE() AND ' . $answeredCondition . ' THEN 1 ELSE 0 END)';

            if ($isCorrectCol) {
                $correctTodayExpr = 'SUM(CASE WHEN DATE(' . $q($answeredAtCol) . ') = CURDATE() AND ' . $q($isCorrectCol) . ' = 1 THEN 1 ELSE 0 END)';
            }
        }

        $lastStudyUpExpr = 'NULL';
        if ($updatedAtCol) {
            $lastStudyUpExpr = 'MAX(' . $q($updatedAtCol) . ')';
        } elseif ($answeredAtCol) {
            $lastStudyUpExpr = 'MAX(' . $q($answeredAtCol) . ')';
        }

        $upSql = 'SELECT '
            . 'COALESCE(' . $answeredExpr . ', 0) AS total_answered_questions, '
            . 'COALESCE(' . $correctExpr . ', 0) AS total_correct_answers, '
            . 'COALESCE(' . $wrongExpr . ', 0) AS total_wrong_answers, '
            . 'COALESCE(' . $bookmarkedExpr . ', 0) AS total_bookmarked_questions, '
            . 'COALESCE(' . $answeredTodayExpr . ', 0) AS answered_today, '
            . 'COALESCE(' . $correctTodayExpr . ', 0) AS correct_today, '
            . $lastStudyUpExpr . ' AS last_study_progress_at '
            . 'FROM `user_progress` '
            . 'WHERE `user_id` = ?';

        $upStmt = $pdo->prepare($upSql);
        $upStmt->execute([$userId]);
        $up = $upStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $statistics['total_answered_questions'] = (int)($up['total_answered_questions'] ?? 0);
        $statistics['total_correct_answers'] = (int)($up['total_correct_answers'] ?? 0);
        $statistics['total_wrong_answers'] = (int)($up['total_wrong_answers'] ?? 0);
        $statistics['total_bookmarked_questions'] = (int)($up['total_bookmarked_questions'] ?? 0);
        $statistics['answered_today'] = (int)($up['answered_today'] ?? 0);
        $statistics['correct_today'] = (int)($up['correct_today'] ?? 0);
        $statistics['last_study_at'] = $up['last_study_progress_at'] ?? null;
    }

    // study_sessions özetleri
    $ssCols = get_table_columns($pdo, 'study_sessions');
    if (!empty($ssCols) && in_array('user_id', $ssCols, true)) {
        $has = static fn(string $col): bool => in_array($col, $ssCols, true);
        $q = static fn(string $col): string => '`' . str_replace('`', '', $col) . '`';

        $durationExpr = $has('duration_seconds') ? 'COALESCE(SUM(' . $q('duration_seconds') . '), 0)' : '0';
        $lastStudyExpr = $has('created_at') ? 'MAX(' . $q('created_at') . ')' : 'NULL';

        $ssSql = 'SELECT COUNT(*) AS total_study_sessions, '
            . $durationExpr . ' AS total_study_duration_seconds, '
            . $lastStudyExpr . ' AS last_study_session_at '
            . 'FROM `study_sessions` WHERE `user_id` = ?';

        $ssStmt = $pdo->prepare($ssSql);
        $ssStmt->execute([$userId]);
        $ss = $ssStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $statistics['total_study_sessions'] = (int)($ss['total_study_sessions'] ?? 0);
        $statistics['total_study_duration_seconds'] = (int)($ss['total_study_duration_seconds'] ?? 0);

        if (!empty($ss['last_study_session_at'])) {
            if (empty($statistics['last_study_at']) || strtotime((string)$ss['last_study_session_at']) > strtotime((string)$statistics['last_study_at'])) {
                $statistics['last_study_at'] = $ss['last_study_session_at'];
            }
        }
    }

    // daily quiz completed today
    $dqCols = get_table_columns($pdo, 'daily_quiz_progress');
    if (!empty($dqCols) && in_array('user_id', $dqCols, true)) {
        $dateCol = in_array('quiz_date', $dqCols, true) ? 'quiz_date' : (in_array('date', $dqCols, true) ? 'date' : null);
        if ($dateCol) {
            $dqSql = 'SELECT COUNT(*) FROM `daily_quiz_progress` WHERE `user_id` = ? AND DATE(`' . $dateCol . '`) = CURDATE()';
            $dqStmt = $pdo->prepare($dqSql);
            $dqStmt->execute([$userId]);
            $statistics['completed_daily_quiz_today'] = ((int)$dqStmt->fetchColumn()) > 0;
        }
    }

    $totalDecisionAnswers = (int)$statistics['total_correct_answers'] + (int)$statistics['total_wrong_answers'];
    if ($totalDecisionAnswers > 0) {
        $statistics['success_rate'] = round(((int)$statistics['total_correct_answers'] / $totalDecisionAnswers) * 100, 2);
    } else {
        $statistics['success_rate'] = 0;
    }

    api_success('Dashboard istatistikleri alındı.', [
        'statistics' => $statistics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

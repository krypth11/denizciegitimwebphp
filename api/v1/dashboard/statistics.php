<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function ds_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function ds_first(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function ds_rate(int $correct, int $wrong): float
{
    $total = $correct + $wrong;
    return $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $statistics = [
        'total_answer_attempts' => 0,
        'total_correct' => 0,
        'total_wrong' => 0,
        'success_rate' => 0.0,
        'bookmarked_count' => 0,
        'unique_answered_count' => 0,
        'total_question_pool' => 0,
        'unanswered_count' => 0,
        'total_study_sessions' => 0,
        'total_study_duration_seconds' => 0,
        'completed_daily_quiz_today' => false,
        'current_qualification_id' => null,
        'current_qualification_name' => null,
        'last_activity_at' => null,
    ];

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if ($profile) {
        $statistics['current_qualification_id'] = $profile['current_qualification_id'] ?? null;
        $statistics['current_qualification_name'] = $profile['current_qualification_name'] ?? null;
    }

    $upCols = get_table_columns($pdo, 'user_progress');
    $qCols = get_table_columns($pdo, 'questions');
    $ssCols = get_table_columns($pdo, 'study_sessions');
    $dqCols = get_table_columns($pdo, 'daily_quiz_progress');

    if (!empty($qCols)) {
        $statistics['total_question_pool'] = (int)$pdo->query('SELECT COUNT(*) FROM `questions`')->fetchColumn();
    }

    if (!empty($upCols) && in_array('user_id', $upCols, true)) {
        $upQuestionId = ds_first($upCols, ['question_id']);
        $upIsAnswered = ds_first($upCols, ['is_answered']);
        $upTotalAnswerCount = ds_first($upCols, ['total_answer_count', 'answer_count', 'total_answers']);
        $upCorrectCount = ds_first($upCols, ['correct_answer_count', 'correct_count']);
        $upWrongCount = ds_first($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count']);
        $upIsCorrect = ds_first($upCols, ['is_correct']);
        $upIsBookmarked = ds_first($upCols, ['is_bookmarked', 'bookmarked']);
        $upDateCol = ds_first($upCols, ['last_answered_at', 'answered_at', 'updated_at', 'created_at']);

        $attemptExpr = $upTotalAnswerCount
            ? 'COALESCE(SUM(up.' . ds_q($upTotalAnswerCount) . '),0)'
            : ($upIsAnswered ? 'COALESCE(SUM(CASE WHEN up.' . ds_q($upIsAnswered) . ' = 1 THEN 1 ELSE 0 END),0)' : '0');

        $correctExpr = $upCorrectCount
            ? 'COALESCE(SUM(up.' . ds_q($upCorrectCount) . '),0)'
            : ($upIsCorrect ? 'COALESCE(SUM(CASE WHEN up.' . ds_q($upIsCorrect) . ' = 1 THEN 1 ELSE 0 END),0)' : '0');

        $wrongExpr = $upWrongCount
            ? 'COALESCE(SUM(up.' . ds_q($upWrongCount) . '),0)'
            : ($upIsCorrect ? 'COALESCE(SUM(CASE WHEN up.' . ds_q($upIsCorrect) . ' = 0 THEN 1 ELSE 0 END),0)' : '0');

        $bookmarkExpr = $upIsBookmarked
            ? 'COALESCE(SUM(CASE WHEN up.' . ds_q($upIsBookmarked) . ' = 1 THEN 1 ELSE 0 END),0)'
            : '0';

        $uniqueExpr = ($upQuestionId && $upIsAnswered)
            ? 'COUNT(DISTINCT CASE WHEN up.' . ds_q($upIsAnswered) . ' = 1 THEN up.' . ds_q($upQuestionId) . ' END)'
            : ($upQuestionId ? 'COUNT(DISTINCT up.' . ds_q($upQuestionId) . ')' : '0');

        $lastExpr = $upDateCol ? 'MAX(up.' . ds_q($upDateCol) . ')' : 'NULL';

        $sql = 'SELECT '
            . $attemptExpr . ' AS total_answer_attempts, '
            . $correctExpr . ' AS total_correct, '
            . $wrongExpr . ' AS total_wrong, '
            . $bookmarkExpr . ' AS bookmarked_count, '
            . $uniqueExpr . ' AS unique_answered_count, '
            . $lastExpr . ' AS last_activity_up '
            . 'FROM `user_progress` up WHERE up.`user_id` = ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $statistics['total_answer_attempts'] = (int)($row['total_answer_attempts'] ?? 0);
        $statistics['total_correct'] = (int)($row['total_correct'] ?? 0);
        $statistics['total_wrong'] = (int)($row['total_wrong'] ?? 0);
        $statistics['bookmarked_count'] = (int)($row['bookmarked_count'] ?? 0);
        $statistics['unique_answered_count'] = (int)($row['unique_answered_count'] ?? 0);
        $statistics['last_activity_at'] = $row['last_activity_up'] ?? null;
    }

    $statistics['unanswered_count'] = max(0, $statistics['total_question_pool'] - $statistics['unique_answered_count']);

    if (!empty($ssCols) && in_array('user_id', $ssCols, true)) {
        $ssDuration = ds_first($ssCols, ['duration_seconds']);
        $ssDate = ds_first($ssCols, ['created_at', 'updated_at']);

        $sql = 'SELECT COUNT(*) AS total_sessions, '
            . ($ssDuration ? 'COALESCE(SUM(' . ds_q($ssDuration) . '),0)' : '0') . ' AS total_duration, '
            . ($ssDate ? 'MAX(' . ds_q($ssDate) . ')' : 'NULL') . ' AS last_activity_ss '
            . 'FROM `study_sessions` WHERE `user_id` = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $statistics['total_study_sessions'] = (int)($row['total_sessions'] ?? 0);
        $statistics['total_study_duration_seconds'] = (int)($row['total_duration'] ?? 0);

        $ssLast = $row['last_activity_ss'] ?? null;
        if ($ssLast && (!$statistics['last_activity_at'] || strtotime((string)$ssLast) > strtotime((string)$statistics['last_activity_at']))) {
            $statistics['last_activity_at'] = $ssLast;
        }
    }

    if (!empty($dqCols) && in_array('user_id', $dqCols, true)) {
        $dqDate = ds_first($dqCols, ['quiz_date', 'date']);
        if ($dqDate) {
            $sql = 'SELECT COUNT(*) FROM `daily_quiz_progress` WHERE `user_id` = ? AND DATE(' . ds_q($dqDate) . ') = CURDATE()';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $statistics['completed_daily_quiz_today'] = ((int)$stmt->fetchColumn()) > 0;
        }
    }

    $statistics['success_rate'] = ds_rate($statistics['total_correct'], $statistics['total_wrong']);

    api_success('Dashboard istatistikleri alındı.', [
        'statistics' => $statistics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

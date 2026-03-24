<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

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

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $statistics = [
        'total_solved' => 0,
        'total_correct' => 0,
        'total_wrong' => 0,
    ];

    // Öncelikli kaynak: question_attempt_events (event-level en doğru deneme sayımı)
    $evCols = get_table_columns($pdo, 'question_attempt_events');
    if (!empty($evCols) && in_array('user_id', $evCols, true)) {
        $evIsCorrect = stats_first_col($evCols, ['is_correct']);

        if ($evIsCorrect) {
            $sql = 'SELECT '
                . 'COALESCE(SUM(CASE WHEN ' . stats_q($evIsCorrect) . ' = 1 THEN 1 ELSE 0 END),0) AS total_correct, '
                . 'COALESCE(SUM(CASE WHEN ' . stats_q($evIsCorrect) . ' = 0 THEN 1 ELSE 0 END),0) AS total_wrong '
                . 'FROM `question_attempt_events` '
                . 'WHERE `user_id` = ?';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $statistics['total_correct'] = (int)($row['total_correct'] ?? 0);
            $statistics['total_wrong'] = (int)($row['total_wrong'] ?? 0);
            $statistics['total_solved'] = $statistics['total_correct'] + $statistics['total_wrong'];

            api_success('Dashboard istatistikleri alındı.', [
                'statistics' => $statistics,
            ]);
        }
    }

    // Fallback: user_progress snapshot aggregate
    $upCols = get_table_columns($pdo, 'user_progress');
    if (!empty($upCols) && in_array('user_id', $upCols, true)) {
        $upCorrectCount = stats_first_col($upCols, ['correct_answer_count', 'correct_count']);
        $upWrongCount = stats_first_col($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count']);
        $upIsCorrect = stats_first_col($upCols, ['is_correct']);

        $correctExpr = $upCorrectCount
            ? 'COALESCE(SUM(' . stats_q($upCorrectCount) . '),0)'
            : ($upIsCorrect ? 'COALESCE(SUM(CASE WHEN ' . stats_q($upIsCorrect) . ' = 1 THEN 1 ELSE 0 END),0)' : '0');

        $wrongExpr = $upWrongCount
            ? 'COALESCE(SUM(' . stats_q($upWrongCount) . '),0)'
            : ($upIsCorrect ? 'COALESCE(SUM(CASE WHEN ' . stats_q($upIsCorrect) . ' = 0 THEN 1 ELSE 0 END),0)' : '0');

        $sql = 'SELECT '
            . $correctExpr . ' AS total_correct, '
            . $wrongExpr . ' AS total_wrong '
            . 'FROM `user_progress` '
            . 'WHERE `user_id` = ?';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $statistics['total_correct'] = (int)($row['total_correct'] ?? 0);
        $statistics['total_wrong'] = (int)($row['total_wrong'] ?? 0);
        $statistics['total_solved'] = $statistics['total_correct'] + $statistics['total_wrong'];
    }

    api_success('Dashboard istatistikleri alındı.', [
        'statistics' => $statistics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

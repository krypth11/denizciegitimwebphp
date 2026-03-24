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

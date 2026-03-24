<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function trends_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function trends_first_col(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function trends_dbg(string $message, array $context = []): void
{
    $suffix = $context ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    error_log('[dashboard.trends] ' . $message . $suffix);
}

function trends_rate(int $correct, int $wrong): float
{
    $den = $correct + $wrong;
    if ($den <= 0) {
        return 0.0;
    }
    return round(($correct / $den) * 100, 2);
}

/**
 * Son 7 günü (bugün dahil) en eski->en yeni döndürür.
 * İleride range desteği eklemek için tek noktadan genişletilebilir.
 *
 * @return array<int, array{date:string,label:string,solved_count:int}>
 */
function trends_build_last_7_days_series(PDO $pdo, string $userId): array
{
    $result = [];

    $evCols = get_table_columns($pdo, 'question_attempt_events');
    if (empty($evCols) || !in_array('user_id', $evCols, true)) {
        $base = new DateTimeImmutable('today -6 day');
        for ($i = 0; $i < 7; $i++) {
            $d = $base->modify('+' . $i . ' day');
            $result[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $d->format('d.m'),
                'solved_count' => 0,
            ];
        }
        return $result;
    }

    $evDate = trends_first_col($evCols, ['attempted_at']);
    if (!$evDate) {
        trends_dbg('attempted_at column missing', ['user_id' => $userId]);
        $base = new DateTimeImmutable('today -6 day');
        for ($i = 0; $i < 7; $i++) {
            $d = $base->modify('+' . $i . ' day');
            $result[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $d->format('d.m'),
                'solved_count' => 0,
            ];
        }
        return $result;
    }

    $start = new DateTimeImmutable('today -6 day');

    $sql = 'SELECT DATE(' . trends_q($evDate) . ') AS event_date, COUNT(*) AS solved_count '
        . 'FROM `question_attempt_events` '
        . 'WHERE `user_id` = ? '
        . 'AND ' . trends_q($evDate) . ' >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) '
        . 'GROUP BY DATE(' . trends_q($evDate) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $date = (string)($row['event_date'] ?? '');
        if ($date !== '') {
            $map[$date] = (int)($row['solved_count'] ?? 0);
        }
    }

    for ($i = 0; $i < 7; $i++) {
        $d = $start->modify('+' . $i . ' day');
        $date = $d->format('Y-m-d');
        $result[] = [
            'date' => $date,
            'label' => $d->format('d.m'),
            'solved_count' => (int)($map[$date] ?? 0),
        ];
    }

    $totalSolved = 0;
    foreach ($result as $item) {
        $totalSolved += (int)$item['solved_count'];
    }
    if ($totalSolved === 0) {
        trends_dbg('last_7_days all zero', [
            'user_id' => $userId,
            'query' => $sql,
            'start_date_sql' => 'DATE_SUB(CURDATE(), INTERVAL 6 DAY)',
        ]);
    }

    return $result;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $activeDaysLast7 = 0;
    $successRateLast7 = 0.0;
    $studyDurationLast7Seconds = 0;

    $sqlEventLast7 = 'SELECT '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 1 THEN 1 ELSE 0 END),0) AS correct_last_7, '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 0 THEN 1 ELSE 0 END),0) AS wrong_last_7, '
        . 'COUNT(DISTINCT DATE(`attempted_at`)) AS active_days_last_7 '
        . 'FROM `question_attempt_events` '
        . 'WHERE `user_id` = ? '
        . 'AND `attempted_at` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';

    $stmtEventLast7 = $pdo->prepare($sqlEventLast7);
    $stmtEventLast7->execute([$userId]);
    $rowEventLast7 = $stmtEventLast7->fetch(PDO::FETCH_ASSOC) ?: [];

    $correctLast7 = (int)($rowEventLast7['correct_last_7'] ?? 0);
    $wrongLast7 = (int)($rowEventLast7['wrong_last_7'] ?? 0);
    $activeDaysLast7 = (int)($rowEventLast7['active_days_last_7'] ?? 0);
    $successRateLast7 = (float)trends_rate($correctLast7, $wrongLast7);

    $ssCols = get_table_columns($pdo, 'study_sessions');
    if (!empty($ssCols) && in_array('user_id', $ssCols, true) && in_array('duration_seconds', $ssCols, true) && in_array('created_at', $ssCols, true)) {
        $sqlDurationLast7 = 'SELECT COALESCE(SUM(`duration_seconds`),0) '
            . 'FROM `study_sessions` '
            . 'WHERE `user_id` = ? '
            . 'AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';

        $stmtDurationLast7 = $pdo->prepare($sqlDurationLast7);
        $stmtDurationLast7->execute([$userId]);
        $studyDurationLast7Seconds = (int)$stmtDurationLast7->fetchColumn();
    }

    $trends = [
        'last_7_days' => trends_build_last_7_days_series($pdo, $userId),
        'study_duration_last_7_seconds' => $studyDurationLast7Seconds,
        'active_days_last_7' => $activeDaysLast7,
        'success_rate_last_7' => $successRateLast7,
    ];

    api_success('Dashboard trend verisi alındı.', [
        'trends' => $trends,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

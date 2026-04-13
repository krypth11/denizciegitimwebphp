<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

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

function trends_series_empty(array $labels): array
{
    return array_fill(0, count($labels), 0);
}

function trends_labels_from_dates(string $startDate, string $endDate): array
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    $labels = [];
    $dates = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $labels[] = $cursor->format('d.m');
        $cursor = $cursor->modify('+1 day');
    }
    return [$dates, $labels];
}

if (($_GET['scope'] ?? '') === 'admin') {
    try {
        $auth = api_require_auth($pdo);
        $isAdmin = !empty($auth['user']['is_admin']);
        if (!$isAdmin) {
            api_error('Admin yetkisi gerekli.', 403);
        }

        $window = stats_resolve_date_window($_GET, 'range', 'start_date', 'end_date', '7d');
        if ($window['is_all_time']) {
            $window = stats_resolve_date_window(['range' => '30d'], 'range', 'start_date', 'end_date', '30d');
        }

        $types = stats_parse_types_from_query(['registrations', 'solved_questions', 'daily_quiz_completed', 'added_questions']);
        [$dateKeys, $labels] = trends_labels_from_dates((string)$window['start_date'], (string)$window['end_date']);
        $dateIndex = array_flip($dateKeys);

        $series = [
            'registrations' => trends_series_empty($labels),
            'solved_questions' => trends_series_empty($labels),
            'daily_quiz_completed' => trends_series_empty($labels),
            'added_questions' => trends_series_empty($labels),
        ];

        $totals = [
            'registrations' => 0,
            'solved_questions' => 0,
            'daily_quiz_completed' => 0,
            'added_questions' => 0,
        ];

        $uCols = get_table_columns($pdo, 'user_profiles');
        $uCreated = trends_first_col($uCols, ['created_at']);
        $uUpdated = trends_first_col($uCols, ['updated_at']);
        $uDeleted = trends_first_col($uCols, ['is_deleted']);

        if (in_array('registrations', $types, true) && $uCreated) {
            $params = [];
            $where = ['DATE(`' . $uCreated . '`) BETWEEN ? AND ?'];
            $params[] = $window['start_date'];
            $params[] = $window['end_date'];
            if ($uDeleted) {
                $where[] = '`' . $uDeleted . '` = 0';
            }
            $sql = 'SELECT DATE(`' . $uCreated . '`) AS d, COUNT(*) AS c FROM `user_profiles` WHERE ' . implode(' AND ', $where) . ' GROUP BY DATE(`' . $uCreated . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $v = (int)($row['c'] ?? 0);
                $series['registrations'][$dateIndex[$d]] = $v;
                $totals['registrations'] += $v;
            }
        }

        $aCols = get_table_columns($pdo, 'question_attempt_events');
        $aDate = trends_first_col($aCols, ['attempted_at', 'created_at']);
        if (in_array('solved_questions', $types, true) && $aDate) {
            $sql = 'SELECT DATE(`' . $aDate . '`) AS d, COUNT(*) AS c FROM `question_attempt_events` WHERE DATE(`' . $aDate . '`) BETWEEN ? AND ? GROUP BY DATE(`' . $aDate . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$window['start_date'], $window['end_date']]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $v = (int)($row['c'] ?? 0);
                $series['solved_questions'][$dateIndex[$d]] = $v;
                $totals['solved_questions'] += $v;
            }
        }

        $dqCols = get_table_columns($pdo, 'daily_quiz_progress');
        $dqCompleted = trends_first_col($dqCols, ['completed_at']);
        $dqDate = trends_first_col($dqCols, ['quiz_date', 'date']);
        if (in_array('daily_quiz_completed', $types, true) && ($dqCompleted || $dqDate)) {
            $dateCol = $dqCompleted ?: $dqDate;
            $where = 'DATE(`' . $dateCol . '`) BETWEEN ? AND ?';
            if ($dqCompleted) {
                $where .= ' AND `' . $dqCompleted . '` IS NOT NULL';
            }
            $sql = 'SELECT DATE(`' . $dateCol . '`) AS d, COUNT(*) AS c FROM `daily_quiz_progress` WHERE ' . $where . ' GROUP BY DATE(`' . $dateCol . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$window['start_date'], $window['end_date']]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $v = (int)($row['c'] ?? 0);
                $series['daily_quiz_completed'][$dateIndex[$d]] = $v;
                $totals['daily_quiz_completed'] += $v;
            }
        }

        $qCols = get_table_columns($pdo, 'questions');
        $qCreated = trends_first_col($qCols, ['created_at']);
        if (in_array('added_questions', $types, true) && $qCreated) {
            $sql = 'SELECT DATE(`' . $qCreated . '`) AS d, COUNT(*) AS c FROM `questions` WHERE DATE(`' . $qCreated . '`) BETWEEN ? AND ? GROUP BY DATE(`' . $qCreated . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$window['start_date'], $window['end_date']]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $v = (int)($row['c'] ?? 0);
                $series['added_questions'][$dateIndex[$d]] = $v;
                $totals['added_questions'] += $v;
            }
        }

        api_success('Dashboard admin trend verisi alındı.', [
            'trends' => [
                'labels' => $labels,
                'series' => $series,
                'totals' => $totals,
                'filters' => [
                    'range' => $window['range'],
                    'start_date' => $window['start_date'],
                    'end_date' => $window['end_date'],
                    'types' => $types,
                ],
            ],
        ]);
    } catch (Throwable $e) {
        api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
    }
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

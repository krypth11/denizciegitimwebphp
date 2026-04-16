<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';
require_once dirname(__DIR__, 3) . '/includes/subscription_management_helper.php';

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

function trends_normalize_plan_code(?string $planCode): string
{
    $value = trim((string)$planCode);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    $value = strtr($value, [
        'ı' => 'i',
        'İ' => 'i',
        'ş' => 's',
        'Ş' => 's',
        'ğ' => 'g',
        'Ğ' => 'g',
        'ü' => 'u',
        'Ü' => 'u',
        'ö' => 'o',
        'Ö' => 'o',
        'ç' => 'c',
        'Ç' => 'c',
    ]);

    $value = preg_replace('/[\s_\-]+/u', '', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9]/u', '', $value) ?? $value;

    return $value;
}

function trends_subscription_series_from_plan_code(?string $planCode): ?string
{
    $normalized = trends_normalize_plan_code($planCode);
    if ($normalized === '') {
        return null;
    }

    $seriesTokens = [
        'subscription_annual' => ['annual', 'yearly', '12month', '12ay', 'yillik'],
        'subscription_semiannual' => ['semiannual', '6month', '6ay', '6aylik'],
        'subscription_quarterly' => ['quarterly', '3month', '3ay', '3aylik'],
        'subscription_monthly' => ['monthly', '1month', '1ay', 'aylik'],
    ];

    foreach ($seriesTokens as $seriesKey => $tokens) {
        foreach ($tokens as $token) {
            if (strpos($normalized, $token) !== false) {
                return $seriesKey;
            }
        }
    }

    return null;
}

function trends_subscription_event_series(?string $eventType): ?string
{
    $type = strtoupper(trim((string)$eventType));
    return match ($type) {
        'INITIAL_PURCHASE' => 'subscription_started',
        'RENEWAL' => 'subscription_renewed',
        'EXPIRATION' => 'subscription_expired',
        'CANCELLATION' => 'subscription_cancelled',
        default => null,
    };
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

        $types = stats_parse_types_from_query(dashboard_filter_keys_for_surface('chart'));
        $typeSet = array_flip($types);
        [$dateKeys, $labels] = trends_labels_from_dates((string)$window['start_date'], (string)$window['end_date']);
        $dateIndex = array_flip($dateKeys);

        $series = [
            'registrations' => trends_series_empty($labels),
            'guest_users' => trends_series_empty($labels),
            'registered_users' => trends_series_empty($labels),
            'solved_questions_daily' => trends_series_empty($labels),
            'solved_correct' => trends_series_empty($labels),
            'solved_wrong' => trends_series_empty($labels),
            'daily_quiz' => trends_series_empty($labels),
            'daily_quiz_completed' => trends_series_empty($labels),
            'added_questions_daily' => trends_series_empty($labels),
            'subscription_started' => trends_series_empty($labels),
            'subscription_renewed' => trends_series_empty($labels),
            'subscription_monthly' => trends_series_empty($labels),
            'subscription_quarterly' => trends_series_empty($labels),
            'subscription_semiannual' => trends_series_empty($labels),
            'subscription_annual' => trends_series_empty($labels),
            'subscription_expired' => trends_series_empty($labels),
            'subscription_cancelled' => trends_series_empty($labels),
        ];

        $totals = [
            'registrations' => 0,
            'guest_users' => 0,
            'registered_users' => 0,
            'solved_questions_daily' => 0,
            'solved_correct' => 0,
            'solved_wrong' => 0,
            'daily_quiz' => 0,
            'daily_quiz_completed' => 0,
            'added_questions_daily' => 0,
            'subscription_started' => 0,
            'subscription_renewed' => 0,
            'subscription_monthly' => 0,
            'subscription_quarterly' => 0,
            'subscription_semiannual' => 0,
            'subscription_annual' => 0,
            'subscription_expired' => 0,
            'subscription_cancelled' => 0,
        ];

        $uCols = get_table_columns($pdo, 'user_profiles');
        $uCreated = trends_first_col($uCols, ['created_at']);
        $uUpdated = trends_first_col($uCols, ['updated_at']);
        $uDeleted = trends_first_col($uCols, ['is_deleted']);

        if ((isset($typeSet['registrations']) || isset($typeSet['guest_users']) || isset($typeSet['registered_users'])) && $uCreated) {
            $uEmail = trends_first_col($uCols, ['email']);
            $uFullName = trends_first_col($uCols, ['full_name', 'name', 'display_name']);
            $params = [];
            $where = ['DATE(`' . $uCreated . '`) BETWEEN ? AND ?'];
            $params[] = $window['start_date'];
            $params[] = $window['end_date'];
            if ($uDeleted) {
                $where[] = '`' . $uDeleted . '` = 0';
            }

            $guestExpr = null;
            if ($uEmail) {
                $nameExpr = $uFullName ? ('LOWER(TRIM(`' . $uFullName . '`))') : "''";
                $guestExpr = '(LOWER(`' . $uEmail . "`) LIKE '%@guest.local' OR " . $nameExpr . " IN ('misafir kullanıcı','misafir kullanici','guest user'))";
            }

            $sql = 'SELECT DATE(`' . $uCreated . '`) AS d, COUNT(*) AS c';
            if ($guestExpr) {
                $sql .= ', SUM(CASE WHEN ' . $guestExpr . ' THEN 1 ELSE 0 END) AS guest_c'
                    . ', SUM(CASE WHEN NOT (' . $guestExpr . ') THEN 1 ELSE 0 END) AS registered_c';
            } else {
                $sql .= ', 0 AS guest_c, COUNT(*) AS registered_c';
            }
            $sql .= ' FROM `user_profiles` WHERE ' . implode(' AND ', $where) . ' GROUP BY DATE(`' . $uCreated . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $v = (int)($row['c'] ?? 0);
                $guestV = (int)($row['guest_c'] ?? 0);
                $registeredV = (int)($row['registered_c'] ?? max(0, $v - $guestV));
                if (isset($typeSet['registrations'])) {
                    $series['registrations'][$dateIndex[$d]] = $v;
                    $totals['registrations'] += $v;
                }
                if (isset($typeSet['guest_users'])) {
                    $series['guest_users'][$dateIndex[$d]] = $guestV;
                    $totals['guest_users'] += $guestV;
                }
                if (isset($typeSet['registered_users'])) {
                    $series['registered_users'][$dateIndex[$d]] = $registeredV;
                    $totals['registered_users'] += $registeredV;
                }
            }
        }

        $attemptCols = get_table_columns($pdo, 'question_attempt_events');
        $attemptDate = trends_first_col($attemptCols, ['attempted_at', 'created_at']);
        $attemptCorrect = trends_first_col($attemptCols, ['is_correct']);
        if ((isset($typeSet['solved_questions_daily']) || isset($typeSet['solved_correct']) || isset($typeSet['solved_wrong'])) && $attemptDate) {
            $sql = 'SELECT DATE(`' . $attemptDate . '`) AS d, COUNT(*) AS c, '
                . ($attemptCorrect ? 'SUM(CASE WHEN `' . $attemptCorrect . '` = 1 THEN 1 ELSE 0 END)' : '0') . ' AS correct_c, '
                . ($attemptCorrect ? 'SUM(CASE WHEN `' . $attemptCorrect . '` = 0 THEN 1 ELSE 0 END)' : '0') . ' AS wrong_c '
                . 'FROM `question_attempt_events` WHERE DATE(`' . $attemptDate . '`) BETWEEN ? AND ? GROUP BY DATE(`' . $attemptDate . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$window['start_date'], $window['end_date']]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $totalV = (int)($row['c'] ?? 0);
                $correctV = (int)($row['correct_c'] ?? 0);
                $wrongV = (int)($row['wrong_c'] ?? 0);
                if (isset($typeSet['solved_questions_daily'])) {
                    $series['solved_questions_daily'][$dateIndex[$d]] = $totalV;
                    $totals['solved_questions_daily'] += $totalV;
                }
                if (isset($typeSet['solved_correct'])) {
                    $series['solved_correct'][$dateIndex[$d]] = $correctV;
                    $totals['solved_correct'] += $correctV;
                }
                if (isset($typeSet['solved_wrong'])) {
                    $series['solved_wrong'][$dateIndex[$d]] = $wrongV;
                    $totals['solved_wrong'] += $wrongV;
                }
            }
        }

        $dqCols = get_table_columns($pdo, 'daily_quiz_progress');
        $dqDate = trends_first_col($dqCols, ['quiz_date', 'date']);
        $dqCompleted = trends_first_col($dqCols, ['completed_at']);
        if ((isset($typeSet['daily_quiz']) || isset($typeSet['daily_quiz_completed'])) && $dqDate) {
            $sql = 'SELECT DATE(`' . $dqDate . '`) AS d, COUNT(*) AS c';
            if ($dqCompleted) {
                $sql .= ', SUM(CASE WHEN `' . $dqCompleted . '` IS NOT NULL THEN 1 ELSE 0 END) AS completed_c';
            } else {
                $sql .= ', COUNT(*) AS completed_c';
            }
            $sql .= ' FROM `daily_quiz_progress` WHERE DATE(`' . $dqDate . '`) BETWEEN ? AND ? GROUP BY DATE(`' . $dqDate . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$window['start_date'], $window['end_date']]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $totalV = (int)($row['c'] ?? 0);
                $completedV = (int)($row['completed_c'] ?? 0);
                if (isset($typeSet['daily_quiz'])) {
                    $series['daily_quiz'][$dateIndex[$d]] = $totalV;
                    $totals['daily_quiz'] += $totalV;
                }
                if (isset($typeSet['daily_quiz_completed'])) {
                    $series['daily_quiz_completed'][$dateIndex[$d]] = $completedV;
                    $totals['daily_quiz_completed'] += $completedV;
                }
            }
        }

        $questionCols = get_table_columns($pdo, 'questions');
        $questionCreated = trends_first_col($questionCols, ['created_at', 'updated_at']);
        if (isset($typeSet['added_questions_daily']) && $questionCreated) {
            $sql = 'SELECT DATE(`' . $questionCreated . '`) AS d, COUNT(*) AS c FROM `questions` WHERE DATE(`' . $questionCreated . '`) BETWEEN ? AND ? GROUP BY DATE(`' . $questionCreated . '`)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$window['start_date'], $window['end_date']]);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $d = (string)($row['d'] ?? '');
                if (!isset($dateIndex[$d])) continue;
                $v = (int)($row['c'] ?? 0);
                $series['added_questions_daily'][$dateIndex[$d]] = $v;
                $totals['added_questions_daily'] += $v;
            }
        }

        $requestedSubscriptionPlanTypes = array_values(array_intersect([
            'subscription_monthly',
            'subscription_quarterly',
            'subscription_semiannual',
            'subscription_annual',
        ], $types));
        $requestedSubscriptionEventSeries = array_values(array_intersect([
            'subscription_started',
            'subscription_renewed',
            'subscription_expired',
            'subscription_cancelled',
        ], $types));

        $sehCols = get_table_columns($pdo, 'subscription_event_history');
        if ((!empty($requestedSubscriptionPlanTypes) || !empty($requestedSubscriptionEventSeries)) && !empty($sehCols)) {
            $hEventType = trends_first_col($sehCols, ['event_type']);
            $hPlanCode = trends_first_col($sehCols, ['plan_code']);
            $hEventAt = trends_first_col($sehCols, ['event_at']);
            $hCreatedAt = trends_first_col($sehCols, ['created_at']);
            $dateCol = $hEventAt ?: $hCreatedAt;

            if ($hEventType && $dateCol) {
                $eventTypes = ['INITIAL_PURCHASE', 'RENEWAL', 'EXPIRATION', 'CANCELLATION'];
                $eventTypePlaceholders = implode(',', array_fill(0, count($eventTypes), '?'));
                $sql = 'SELECT DATE(`' . $dateCol . '`) AS d, UPPER(TRIM(`' . $hEventType . '`)) AS event_type, '
                    . ($hPlanCode ? '`' . $hPlanCode . '`' : 'NULL') . ' AS plan_code '
                    . 'FROM `subscription_event_history` '
                    . 'WHERE DATE(`' . $dateCol . '`) BETWEEN ? AND ? '
                    . 'AND UPPER(TRIM(`' . $hEventType . '`)) IN (' . $eventTypePlaceholders . ')';

                $params = array_merge([$window['start_date'], $window['end_date']], $eventTypes);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $unknownPlanCodes = [];
                foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                    $d = (string)($row['d'] ?? '');
                    if (!isset($dateIndex[$d])) {
                        continue;
                    }

                    $rawType = strtoupper(trim((string)($row['event_type'] ?? '')));
                    if (!in_array($rawType, $eventTypes, true)) {
                        continue;
                    }

                    $eventSeries = trends_subscription_event_series($rawType);
                    if ($eventSeries !== null && in_array($eventSeries, $requestedSubscriptionEventSeries, true)) {
                        $series[$eventSeries][$dateIndex[$d]] += 1;
                        $totals[$eventSeries] += 1;
                    }

                    if (!in_array($rawType, ['INITIAL_PURCHASE', 'RENEWAL'], true)) {
                        continue;
                    }

                    $planCodeRaw = (string)($row['plan_code'] ?? '');
                    $mappedType = trends_subscription_series_from_plan_code($planCodeRaw);
                    if ($mappedType === null || !isset($series[$mappedType])) {
                        $normalizedPlanCode = trim($planCodeRaw);
                        if ($normalizedPlanCode !== '') {
                            $unknownPlanCodes[$normalizedPlanCode] = true;
                        }
                        continue;
                    }

                    if (!in_array($mappedType, $requestedSubscriptionPlanTypes, true)) {
                        continue;
                    }

                    $series[$mappedType][$dateIndex[$d]] += 1;
                    $totals[$mappedType] += 1;
                }

                if (!empty($unknownPlanCodes)) {
                    trends_dbg('Eşleşmeyen plan_code nedeniyle bazı abonelik kayıtları grafiğe dahil edilmedi.', [
                        'plan_codes' => array_keys($unknownPlanCodes),
                        'count' => count($unknownPlanCodes),
                    ]);
                }
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

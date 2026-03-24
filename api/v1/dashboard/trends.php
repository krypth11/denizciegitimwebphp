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

    $evDate = trends_first_col($evCols, ['attempted_at', 'created_at']);
    if (!$evDate) {
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
    $endExclusive = new DateTimeImmutable('tomorrow');

    $sql = 'SELECT DATE(' . trends_q($evDate) . ') AS event_date, COUNT(*) AS solved_count '
        . 'FROM `question_attempt_events` '
        . 'WHERE `user_id` = ? '
        . 'AND ' . trends_q($evDate) . ' >= ? '
        . 'AND ' . trends_q($evDate) . ' < ? '
        . 'GROUP BY DATE(' . trends_q($evDate) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $start->format('Y-m-d 00:00:00'), $endExclusive->format('Y-m-d 00:00:00')]);
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

    return $result;
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $trends = [
        'last_7_days' => trends_build_last_7_days_series($pdo, $userId),
    ];

    api_success('Dashboard trend verisi alındı.', [
        'trends' => $trends,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

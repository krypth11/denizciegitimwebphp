<?php

function stats_parse_iso_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

function stats_resolve_filters_from_query(): array
{
    $range = strtolower(trim((string)($_GET['range'] ?? '30d')));
    $allowed = ['1d', '7d', '14d', '30d', 'all', 'custom'];
    if (!in_array($range, $allowed, true)) {
        $range = '30d';
    }

    $today = new DateTimeImmutable('today');
    $startDate = null;
    $endDate = $today->format('Y-m-d');

    switch ($range) {
        case '1d':
            $startDate = $today->format('Y-m-d');
            break;
        case '7d':
            $startDate = $today->modify('-6 days')->format('Y-m-d');
            break;
        case '14d':
            $startDate = $today->modify('-13 days')->format('Y-m-d');
            break;
        case '30d':
            $startDate = $today->modify('-29 days')->format('Y-m-d');
            break;
        case 'all':
            $startDate = null;
            $endDate = null;
            break;
        case 'custom':
            $customStart = stats_parse_iso_date((string)($_GET['start_date'] ?? ''));
            $customEnd = stats_parse_iso_date((string)($_GET['end_date'] ?? ''));
            if (!$customStart || !$customEnd) {
                api_error('custom range için start_date ve end_date (YYYY-MM-DD) zorunludur.', 422);
            }
            if ($customStart > $customEnd) {
                api_error('start_date end_date değerinden büyük olamaz.', 422);
            }
            $startDate = $customStart;
            $endDate = $customEnd;
            break;
    }

    return [
        'range' => $range,
        'available_ranges' => $allowed,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'is_all_time' => $range === 'all',
    ];
}

function stats_build_date_between_sql(string $columnExpression, ?string $startDate, ?string $endDate, array &$params): string
{
    if (!$startDate && !$endDate) {
        return '';
    }

    if ($startDate && $endDate) {
        $params[] = $startDate;
        $params[] = $endDate;
        return 'DATE(' . $columnExpression . ') BETWEEN ? AND ?';
    }

    if ($startDate) {
        $params[] = $startDate;
        return 'DATE(' . $columnExpression . ') >= ?';
    }

    $params[] = $endDate;
    return 'DATE(' . $columnExpression . ') <= ?';
}

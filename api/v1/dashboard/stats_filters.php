<?php

function stats_normalize_range(string $value, string $default = '7d'): string
{
    $allowed = ['1d', '3d', '7d', '15d', '30d', 'all', 'custom'];
    $value = strtolower(trim($value));
    if (!in_array($value, $allowed, true)) {
        return $default;
    }
    return $value;
}

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

function stats_get_range_days(string $range): ?int
{
    return match ($range) {
        '1d' => 1,
        '3d' => 3,
        '7d' => 7,
        '15d' => 15,
        '30d' => 30,
        default => null,
    };
}

function stats_resolve_date_window(array $input, string $rangeKey = 'range', string $startKey = 'start_date', string $endKey = 'end_date', string $defaultRange = '7d'): array
{
    $range = stats_normalize_range((string)($input[$rangeKey] ?? $defaultRange), $defaultRange);

    $today = new DateTimeImmutable('today');
    $startDate = null;
    $endDate = $today->format('Y-m-d');

    $customStart = stats_parse_iso_date((string)($input[$startKey] ?? ''));
    $customEnd = stats_parse_iso_date((string)($input[$endKey] ?? ''));

    // Custom tarih girildiyse custom baskın olsun.
    if ($customStart || $customEnd) {
        $range = 'custom';
        if (!$customStart || !$customEnd) {
            api_error('Tarih aralığı için başlangıç ve bitiş tarihi zorunludur.', 422);
        }
        if ($customStart > $customEnd) {
            api_error('start_date end_date değerinden büyük olamaz.', 422);
        }
        $startDate = $customStart;
        $endDate = $customEnd;
    } elseif ($range === 'all') {
        $startDate = null;
        $endDate = null;
    } else {
        $days = stats_get_range_days($range);
        if ($days === null) {
            $range = $defaultRange;
            $days = stats_get_range_days($defaultRange) ?? 7;
        }
        $startDate = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    }

    return [
        'range' => $range,
        'available_ranges' => ['1d', '3d', '7d', '15d', '30d', 'all', 'custom'],
        'start_date' => $startDate,
        'end_date' => $endDate,
        'is_all_time' => $range === 'all',
    ];
}

function stats_resolve_filters_from_query(): array
{
    return stats_resolve_date_window($_GET, 'range', 'start_date', 'end_date', '7d');
}

function stats_parse_types_from_query(array $allTypes, string $key = 'types'): array
{
    $raw = $_GET[$key] ?? [];
    if (is_string($raw)) {
        $raw = array_filter(array_map('trim', explode(',', $raw)));
    }
    if (!is_array($raw)) {
        $raw = [];
    }

    $raw = array_map(static fn($v) => strtolower(trim((string)$v)), $raw);
    $raw = array_values(array_unique(array_filter($raw, static fn($v) => $v !== '')));

    if (empty($raw)) {
        return $allTypes;
    }

    $valid = array_values(array_intersect($allTypes, $raw));
    return !empty($valid) ? $valid : $allTypes;
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

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

function dashboard_filter_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }

    $items = [
        [
            'key' => 'registrations',
            'label' => 'Kaydolan Kullanıcılar',
            'category' => 'Kullanıcı',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => true,
            'default_chart' => true,
            'order' => 10,
        ],
        [
            'key' => 'guest_users',
            'label' => 'Misafir Kullanıcılar',
            'category' => 'Kullanıcı',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 11,
        ],
        [
            'key' => 'registered_users',
            'label' => 'Kayıtlı Kullanıcılar',
            'category' => 'Kullanıcı',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 12,
        ],
        [
            'key' => 'solved_questions',
            'label' => 'Çözülen Sorular',
            'category' => 'Çalışma',
            'supports_activity' => true,
            'supports_chart' => false,
            'default_activity' => true,
            'default_chart' => false,
            'order' => 20,
        ],
        [
            'key' => 'solved_questions_daily',
            'label' => 'Günlük Çözülen Soru Sayısı',
            'category' => 'Çalışma',
            'supports_activity' => false,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 21,
        ],
        [
            'key' => 'solved_correct',
            'label' => 'Doğru Çözülen Sorular',
            'category' => 'Çalışma',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 22,
        ],
        [
            'key' => 'solved_wrong',
            'label' => 'Yanlış Çözülen Sorular',
            'category' => 'Çalışma',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 23,
        ],
        [
            'key' => 'daily_quiz',
            'label' => 'Daily Quiz',
            'category' => 'Daily Quiz',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => true,
            'default_chart' => false,
            'order' => 30,
        ],
        [
            'key' => 'daily_quiz_completed',
            'label' => 'Daily Quiz Tamamlayanlar',
            'category' => 'Daily Quiz',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 31,
        ],
        [
            'key' => 'added_questions',
            'label' => 'Eklenen Sorular',
            'category' => 'İçerik',
            'supports_activity' => true,
            'supports_chart' => false,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 40,
        ],
        [
            'key' => 'added_questions_daily',
            'label' => 'Günlük Eklenen Soru Sayısı',
            'category' => 'İçerik',
            'supports_activity' => false,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 41,
        ],
        [
            'key' => 'subscription_started',
            'label' => 'Yeni Abonelikler',
            'category' => 'Abonelik',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => true,
            'default_chart' => false,
            'order' => 50,
        ],
        [
            'key' => 'subscription_renewed',
            'label' => 'Abonelik Yenilemeleri',
            'category' => 'Abonelik',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => true,
            'default_chart' => false,
            'order' => 51,
        ],
        [
            'key' => 'subscription_monthly',
            'label' => '1 Aylık Abonelikler',
            'category' => 'Abonelik',
            'supports_activity' => false,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => true,
            'order' => 52,
        ],
        [
            'key' => 'subscription_quarterly',
            'label' => '3 Aylık Abonelikler',
            'category' => 'Abonelik',
            'supports_activity' => false,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => true,
            'order' => 53,
        ],
        [
            'key' => 'subscription_semiannual',
            'label' => '6 Aylık Abonelikler',
            'category' => 'Abonelik',
            'supports_activity' => false,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => true,
            'order' => 54,
        ],
        [
            'key' => 'subscription_annual',
            'label' => 'Yıllık Abonelikler',
            'category' => 'Abonelik',
            'supports_activity' => false,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => true,
            'order' => 55,
        ],
        [
            'key' => 'subscription_expired',
            'label' => 'Süresi Dolan Abonelikler',
            'category' => 'Abonelik',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 56,
        ],
        [
            'key' => 'subscription_cancelled',
            'label' => 'İptaller',
            'category' => 'Abonelik',
            'supports_activity' => true,
            'supports_chart' => true,
            'default_activity' => false,
            'default_chart' => false,
            'order' => 57,
        ],
    ];

    $catalog = [];
    foreach ($items as $item) {
        $catalog[$item['key']] = $item;
    }
    return $catalog;
}

function dashboard_filter_catalog_list(): array
{
    $list = array_values(dashboard_filter_catalog());
    usort($list, static function (array $a, array $b): int {
        return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
    });
    return $list;
}

function dashboard_filter_keys_for_surface(string $surface): array
{
    $surface = strtolower(trim($surface));
    $catalog = dashboard_filter_catalog();
    $keys = [];
    foreach ($catalog as $key => $item) {
        if ($surface === 'activity' && !empty($item['supports_activity'])) {
            $keys[] = $key;
        }
        if ($surface === 'chart' && !empty($item['supports_chart'])) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function dashboard_filter_default_keys(string $surface): array
{
    $surface = strtolower(trim($surface));
    $catalog = dashboard_filter_catalog();
    $keys = [];
    foreach ($catalog as $key => $item) {
        if ($surface === 'activity' && !empty($item['supports_activity']) && !empty($item['default_activity'])) {
            $keys[] = $key;
        }
        if ($surface === 'chart' && !empty($item['supports_chart']) && !empty($item['default_chart'])) {
            $keys[] = $key;
        }
    }
    return $keys;
}

function dashboard_filter_defaults(): array
{
    return [
        'activity' => [
            'types' => dashboard_filter_default_keys('activity'),
            'limit' => 25,
        ],
        'chart' => [
            'types' => dashboard_filter_default_keys('chart'),
            'range' => '7d',
            'start_date' => null,
            'end_date' => null,
        ],
    ];
}

function dashboard_normalize_filter_keys(array $keys, string $surface): array
{
    $allowed = dashboard_filter_keys_for_surface($surface);
    $allowedMap = array_flip($allowed);
    $normalized = [];
    foreach ($keys as $key) {
        $k = strtolower(trim((string)$key));
        if ($k === '' || !isset($allowedMap[$k])) {
            continue;
        }
        $normalized[$k] = true;
    }

    $result = array_keys($normalized);
    if (empty($result)) {
        $result = dashboard_filter_default_keys($surface);
    }
    return array_values($result);
}

function dashboard_preferences_schema(PDO $pdo): ?array
{
    static $schema = null;
    static $resolved = false;

    if ($resolved) {
        return $schema;
    }
    $resolved = true;

    $tables = ['admin_dashboard_preferences', 'dashboard_preferences', 'user_dashboard_preferences'];
    foreach ($tables as $table) {
        $cols = get_table_columns($pdo, $table);
        if (empty($cols)) {
            continue;
        }

        $pick = static function (array $candidates, bool $required = false) use ($cols): ?string {
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $cols, true)) {
                    return $candidate;
                }
            }
            if ($required) {
                return null;
            }
            return null;
        };

        $userCol = $pick(['user_id', 'admin_user_id', 'admin_id', 'profile_id'], true);
        $jsonCol = $pick(['preferences_json', 'preference_json', 'preferences', 'payload_json'], true);
        if (!$userCol || !$jsonCol) {
            continue;
        }

        $schema = [
            'table' => $table,
            'id' => $pick(['id']),
            'user_id' => $userCol,
            'json' => $jsonCol,
            'created_at' => $pick(['created_at']),
            'updated_at' => $pick(['updated_at']),
        ];
        return $schema;
    }

    return null;
}

function dashboard_preferences_default_payload(): array
{
    $defaults = dashboard_filter_defaults();
    return [
        'activity' => [
            'types' => $defaults['activity']['types'],
            'limit' => (int)$defaults['activity']['limit'],
        ],
        'chart' => [
            'types' => $defaults['chart']['types'],
            'range' => (string)$defaults['chart']['range'],
            'start_date' => $defaults['chart']['start_date'],
            'end_date' => $defaults['chart']['end_date'],
        ],
    ];
}

function dashboard_preferences_normalize_payload(array $payload): array
{
    $defaults = dashboard_preferences_default_payload();

    $activity = is_array($payload['activity'] ?? null) ? $payload['activity'] : [];
    $chart = is_array($payload['chart'] ?? null) ? $payload['chart'] : [];

    $activityTypesRaw = is_array($activity['types'] ?? null) ? $activity['types'] : $defaults['activity']['types'];
    $chartTypesRaw = is_array($chart['types'] ?? null) ? $chart['types'] : $defaults['chart']['types'];

    $activityLimit = (int)($activity['limit'] ?? $defaults['activity']['limit']);
    if (!in_array($activityLimit, [10, 25, 50, 100], true)) {
        $activityLimit = (int)$defaults['activity']['limit'];
    }

    $chartRange = stats_normalize_range((string)($chart['range'] ?? $defaults['chart']['range']), (string)$defaults['chart']['range']);
    if ($chartRange === 'all') {
        $chartRange = '30d';
    }

    $chartStart = stats_parse_iso_date((string)($chart['start_date'] ?? ''));
    $chartEnd = stats_parse_iso_date((string)($chart['end_date'] ?? ''));
    if (($chartStart && !$chartEnd) || (!$chartStart && $chartEnd) || ($chartStart && $chartEnd && $chartStart > $chartEnd)) {
        $chartStart = null;
        $chartEnd = null;
    }

    return [
        'activity' => [
            'types' => dashboard_normalize_filter_keys($activityTypesRaw, 'activity'),
            'limit' => $activityLimit,
        ],
        'chart' => [
            'types' => dashboard_normalize_filter_keys($chartTypesRaw, 'chart'),
            'range' => $chartRange,
            'start_date' => $chartStart,
            'end_date' => $chartEnd,
        ],
    ];
}

function dashboard_preferences_load(PDO $pdo, string $userId): array
{
    $defaults = dashboard_preferences_default_payload();
    $schema = dashboard_preferences_schema($pdo);
    if (!$schema) {
        return [
            'preferences' => $defaults,
            'meta' => ['source' => 'defaults', 'persisted' => false],
        ];
    }

    $sql = 'SELECT `' . $schema['json'] . '` AS preferences_json FROM `' . $schema['table'] . '` WHERE `' . $schema['user_id'] . '` = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return [
            'preferences' => $defaults,
            'meta' => ['source' => 'defaults', 'persisted' => true],
        ];
    }

    $decoded = json_decode((string)($row['preferences_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return [
            'preferences' => $defaults,
            'meta' => ['source' => 'defaults_invalid_payload', 'persisted' => true],
        ];
    }

    return [
        'preferences' => dashboard_preferences_normalize_payload($decoded),
        'meta' => ['source' => 'database', 'persisted' => true],
    ];
}

function dashboard_preferences_save(PDO $pdo, string $userId, array $inputPayload): array
{
    $schema = dashboard_preferences_schema($pdo);
    $normalized = dashboard_preferences_normalize_payload($inputPayload);

    if (!$schema) {
        return [
            'preferences' => $normalized,
            'meta' => ['persisted' => false, 'reason' => 'table_not_found'],
        ];
    }

    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        $json = '{}';
    }

    $checkSql = 'SELECT COUNT(*) FROM `' . $schema['table'] . '` WHERE `' . $schema['user_id'] . '` = ?';
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$userId]);
    $exists = ((int)$checkStmt->fetchColumn()) > 0;

    if ($exists) {
        $set = ['`' . $schema['json'] . '` = ?'];
        $params = [$json];
        if (!empty($schema['updated_at'])) {
            $set[] = '`' . $schema['updated_at'] . '` = NOW()';
        }
        $params[] = $userId;
        $sql = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set) . ' WHERE `' . $schema['user_id'] . '` = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $cols = ['`' . $schema['user_id'] . '`', '`' . $schema['json'] . '`'];
        $holders = ['?', '?'];
        $params = [$userId, $json];
        if (!empty($schema['id'])) {
            $cols[] = '`' . $schema['id'] . '`';
            $holders[] = '?';
            $params[] = generate_uuid();
        }
        if (!empty($schema['created_at'])) {
            $cols[] = '`' . $schema['created_at'] . '`';
            $holders[] = 'NOW()';
        }
        if (!empty($schema['updated_at'])) {
            $cols[] = '`' . $schema['updated_at'] . '`';
            $holders[] = 'NOW()';
        }

        $sql = 'INSERT INTO `' . $schema['table'] . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    return [
        'preferences' => $normalized,
        'meta' => ['persisted' => true],
    ];
}

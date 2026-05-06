<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$authUser = require_auth();
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

function rewarded_stats_json(bool $success, string $message = '', array $payload = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rewarded_stats_date(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
}

function rewarded_stats_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("\n        SELECT 1\n        FROM information_schema.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = ?\n        LIMIT 1\n    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

try {
    $range = strtolower(trim((string)($_GET['range'] ?? '7d')));
    $rewardType = strtolower(trim((string)($_GET['reward_type'] ?? 'all')));
    $platform = strtolower(trim((string)($_GET['platform'] ?? 'all')));
    $userSearch = trim((string)($_GET['user_search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));

    if (!in_array($range, ['today', '7d', '30d', '90d', 'custom'], true)) {
        rewarded_stats_json(false, 'Geçersiz range değeri.', [], 422);
    }
    if (!in_array($rewardType, ['all', 'study', 'exam'], true)) {
        rewarded_stats_json(false, 'Geçersiz reward_type değeri.', [], 422);
    }
    if (!in_array($platform, ['all', 'android', 'ios', 'unknown'], true)) {
        rewarded_stats_json(false, 'Geçersiz platform değeri.', [], 422);
    }

    $endDate = date('Y-m-d');
    $startDate = $endDate;
    if ($range === 'today') {
        $startDate = $endDate;
    } elseif ($range === '7d') {
        $startDate = date('Y-m-d', strtotime('-6 days'));
    } elseif ($range === '30d') {
        $startDate = date('Y-m-d', strtotime('-29 days'));
    } elseif ($range === '90d') {
        $startDate = date('Y-m-d', strtotime('-89 days'));
    } else {
        $startDate = rewarded_stats_date((string)($_GET['start_date'] ?? ''));
        $endDate = rewarded_stats_date((string)($_GET['end_date'] ?? ''));
        if (!$startDate || !$endDate) {
            rewarded_stats_json(false, 'custom range için start_date ve end_date zorunludur (Y-m-d).', [], 422);
        }
        if ($startDate > $endDate) {
            rewarded_stats_json(false, 'start_date end_date değerinden büyük olamaz.', [], 422);
        }
    }

    if (!rewarded_stats_table_exists($pdo, 'rewarded_ad_events')) {
        rewarded_stats_json(true, '', [
            'summary' => [
                'total_watches' => 0,
                'study_watches' => 0,
                'exam_watches' => 0,
                'android_watches' => 0,
                'ios_watches' => 0,
                'unknown_watches' => 0,
                'total_study_bonus' => 0,
                'total_exam_bonus' => 0,
                'unique_users' => 0,
                'avg_watches_per_user' => 0,
                'today_watches' => 0,
            ],
            'charts' => [
                'daily' => [],
                'type_distribution' => [
                    'study' => 0,
                    'exam' => 0,
                ],
                'platform_distribution' => [
                    'android' => 0,
                    'ios' => 0,
                    'unknown' => 0,
                ],
            ],
            'top_users' => [],
            'events' => [],
            'pagination' => [
                'page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'total_pages' => 0,
            ],
        ]);
    }

    $where = ['DATE(e.created_at) BETWEEN ? AND ?'];
    $params = [$startDate, $endDate];
    if ($rewardType !== 'all') {
        $where[] = 'e.reward_type = ?';
        $params[] = $rewardType;
    }
    if ($platform !== 'all') {
        $where[] = 'e.platform = ?';
        $params[] = $platform;
    }
    if ($userSearch !== '') {
        $where[] = '(u.email LIKE ? OR u.full_name LIKE ?)';
        $params[] = '%' . $userSearch . '%';
        $params[] = '%' . $userSearch . '%';
    }
    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $summarySql = 'SELECT '
        . 'COUNT(*) AS total_watches,'
        . 'SUM(CASE WHEN e.reward_type = \'study\' THEN 1 ELSE 0 END) AS study_watches,'
        . 'SUM(CASE WHEN e.reward_type = \'exam\' THEN 1 ELSE 0 END) AS exam_watches,'
        . 'SUM(CASE WHEN e.platform = \'android\' THEN 1 ELSE 0 END) AS android_watches,'
        . 'SUM(CASE WHEN e.platform = \'ios\' THEN 1 ELSE 0 END) AS ios_watches,'
        . 'SUM(CASE WHEN e.platform = \'unknown\' THEN 1 ELSE 0 END) AS unknown_watches,'
        . 'SUM(CASE WHEN e.reward_type = \'study\' THEN e.bonus_amount ELSE 0 END) AS total_study_bonus,'
        . 'SUM(CASE WHEN e.reward_type = \'exam\' THEN e.bonus_amount ELSE 0 END) AS total_exam_bonus,'
        . 'COUNT(DISTINCT e.user_id) AS unique_users,'
        . 'SUM(CASE WHEN DATE(e.created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_watches '
        . 'FROM rewarded_ad_events e '
        . 'LEFT JOIN user_profiles u ON u.id COLLATE utf8mb4_unicode_ci = e.user_id COLLATE utf8mb4_unicode_ci '
        . $whereSql;
    $stmt = $pdo->prepare($summarySql);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalWatches = (int)($summary['total_watches'] ?? 0);
    $uniqueUsers = max(0, (int)($summary['unique_users'] ?? 0));
    $summaryOut = [
        'total_watches' => $totalWatches,
        'study_watches' => (int)($summary['study_watches'] ?? 0),
        'exam_watches' => (int)($summary['exam_watches'] ?? 0),
        'android_watches' => (int)($summary['android_watches'] ?? 0),
        'ios_watches' => (int)($summary['ios_watches'] ?? 0),
        'unknown_watches' => (int)($summary['unknown_watches'] ?? 0),
        'total_study_bonus' => (int)($summary['total_study_bonus'] ?? 0),
        'total_exam_bonus' => (int)($summary['total_exam_bonus'] ?? 0),
        'unique_users' => $uniqueUsers,
        'avg_watches_per_user' => $uniqueUsers > 0 ? round($totalWatches / $uniqueUsers, 2) : 0,
        'today_watches' => (int)($summary['today_watches'] ?? 0),
    ];

    $dailySql = 'SELECT DATE(e.created_at) AS date, COUNT(*) AS total,'
        . 'SUM(CASE WHEN e.reward_type=\'study\' THEN 1 ELSE 0 END) AS study,'
        . 'SUM(CASE WHEN e.reward_type=\'exam\' THEN 1 ELSE 0 END) AS exam,'
        . 'SUM(CASE WHEN e.platform=\'android\' THEN 1 ELSE 0 END) AS android,'
        . 'SUM(CASE WHEN e.platform=\'ios\' THEN 1 ELSE 0 END) AS ios,'
        . 'SUM(CASE WHEN e.platform=\'unknown\' THEN 1 ELSE 0 END) AS unknown_count '
        . 'FROM rewarded_ad_events e LEFT JOIN user_profiles u ON u.id COLLATE utf8mb4_unicode_ci = e.user_id COLLATE utf8mb4_unicode_ci '
        . $whereSql . ' GROUP BY DATE(e.created_at) ORDER BY DATE(e.created_at) ASC';
    $stmt = $pdo->prepare($dailySql);
    $stmt->execute($params);
    $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $topSql = 'SELECT e.user_id, COALESCE(u.full_name, \'-\') AS full_name, COALESCE(u.email, \'-\') AS email, COUNT(*) AS total_watches,'
        . 'SUM(CASE WHEN e.reward_type=\'study\' THEN 1 ELSE 0 END) AS study_watches,'
        . 'SUM(CASE WHEN e.reward_type=\'exam\' THEN 1 ELSE 0 END) AS exam_watches,'
        . 'SUM(CASE WHEN e.reward_type=\'study\' THEN e.bonus_amount ELSE 0 END) AS total_study_bonus,'
        . 'SUM(CASE WHEN e.reward_type=\'exam\' THEN e.bonus_amount ELSE 0 END) AS total_exam_bonus,'
        . 'MAX(e.created_at) AS last_watch_at '
        . 'FROM rewarded_ad_events e LEFT JOIN user_profiles u ON u.id COLLATE utf8mb4_unicode_ci = e.user_id COLLATE utf8mb4_unicode_ci '
        . $whereSql . ' GROUP BY e.user_id, u.full_name, u.email ORDER BY total_watches DESC LIMIT 10';
    $stmt = $pdo->prepare($topSql);
    $stmt->execute($params);
    $topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countSql = 'SELECT COUNT(*) FROM rewarded_ad_events e LEFT JOIN user_profiles u ON u.id COLLATE utf8mb4_unicode_ci = e.user_id COLLATE utf8mb4_unicode_ci ' . $whereSql;
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $eventsSql = 'SELECT e.id, e.user_id, COALESCE(u.full_name, \'-\') AS full_name, COALESCE(u.email, \'-\') AS email, '
        . 'e.reward_type, e.platform, e.bonus_amount, e.created_at, e.ip_address '
        . 'FROM rewarded_ad_events e '
        . 'LEFT JOIN user_profiles u ON u.id COLLATE utf8mb4_unicode_ci = e.user_id COLLATE utf8mb4_unicode_ci '
        . $whereSql
        . ' ORDER BY e.created_at DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($eventsSql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dailyOut = array_map(static function (array $row): array {
        return [
            'date' => (string)($row['date'] ?? ''),
            'total' => (int)($row['total'] ?? 0),
            'study' => (int)($row['study'] ?? 0),
            'exam' => (int)($row['exam'] ?? 0),
            'android' => (int)($row['android'] ?? 0),
            'ios' => (int)($row['ios'] ?? 0),
            'unknown' => (int)($row['unknown_count'] ?? 0),
        ];
    }, $dailyRows);

    rewarded_stats_json(true, '', [
        'summary' => $summaryOut,
        'charts' => [
            'daily' => $dailyOut,
            'type_distribution' => [
                'study' => $summaryOut['study_watches'],
                'exam' => $summaryOut['exam_watches'],
            ],
            'platform_distribution' => [
                'android' => $summaryOut['android_watches'],
                'ios' => $summaryOut['ios_watches'],
                'unknown' => $summaryOut['unknown_watches'],
            ],
        ],
        'top_users' => $topUsers,
        'events' => $events,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[rewarded-ad-stats] ' . $e->getMessage());
    $payload = [];
    if (!empty($debug)) {
        $payload['debug'] = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
    rewarded_stats_json(false, 'Reklam istatistikleri alınırken hata oluştu.', $payload, 500);
}

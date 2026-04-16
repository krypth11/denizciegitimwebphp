<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/subscription_management_helper.php';
require_once '../api/v1/usage_limits_helper.php';

$authUser = require_admin();

function subscriptions_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function subscriptions_pick_column(array $columns, array $candidates, bool $required = false): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    if ($required) {
        throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    }

    return null;
}

function subscriptions_user_schema(PDO $pdo): ?array
{
    $cols = get_table_columns($pdo, 'user_profiles');
    if (empty($cols)) {
        return null;
    }

    return [
        'table' => 'user_profiles',
        'id' => subscriptions_pick_column($cols, ['id'], true),
        'full_name' => subscriptions_pick_column($cols, ['full_name', 'name', 'display_name'], false),
        'email' => subscriptions_pick_column($cols, ['email'], false),
    ];
}

function subscriptions_to_limit(int $default = 50, int $max = 500): int
{
    $limit = filter_var($_GET['limit'] ?? $default, FILTER_VALIDATE_INT);
    if (!$limit || $limit < 1) {
        return $default;
    }
    return min($max, $limit);
}

function subscriptions_get_dashboard(PDO $pdo): array
{
    $webhook = subscription_mgmt_webhook_schema($pdo);
    $history = subscription_mgmt_history_schema($pdo);
    $subSchema = null;
    try {
        $subSchema = usage_limits_get_subscription_schema($pdo);
    } catch (Throwable $e) {
        $subSchema = null;
    }

    $summary = [
        'active_premium_count' => 0,
        'last_30_initial_purchase' => 0,
        'last_30_renewal' => 0,
        'last_30_expiration' => 0,
        'last_30_cancellation' => 0,
        'last_successful_webhook_at' => null,
    ];

    if ($subSchema && !empty($subSchema['expires_at'])) {
        $sql = 'SELECT COUNT(*) FROM ' . subscription_mgmt_q($subSchema['table'])
            . ' WHERE ' . subscription_mgmt_q($subSchema['is_pro']) . ' = 1'
            . ' AND ' . subscription_mgmt_q($subSchema['expires_at']) . ' IS NOT NULL'
            . ' AND ' . subscription_mgmt_q($subSchema['expires_at']) . ' > NOW()';
        $summary['active_premium_count'] = (int)$pdo->query($sql)->fetchColumn();
    }

    $eventCountSql = null;
    if ($history && $history['event_type'] && $history['created_at']) {
        $eventCountSql = 'SELECT SUM(CASE WHEN ' . subscription_mgmt_q($history['event_type']) . ' = ? THEN 1 ELSE 0 END) AS c'
            . ' FROM ' . subscription_mgmt_q($history['table'])
            . ' WHERE ' . subscription_mgmt_q($history['created_at']) . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } elseif ($webhook && $webhook['event_type'] && $webhook['created_at']) {
        $eventCountSql = 'SELECT SUM(CASE WHEN ' . subscription_mgmt_q($webhook['event_type']) . ' = ? THEN 1 ELSE 0 END) AS c'
            . ' FROM ' . subscription_mgmt_q($webhook['table'])
            . ' WHERE ' . subscription_mgmt_q($webhook['created_at']) . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    }

    if ($eventCountSql) {
        $stmt = $pdo->prepare($eventCountSql);
        $stmt->execute(['INITIAL_PURCHASE']);
        $summary['last_30_initial_purchase'] = (int)($stmt->fetchColumn() ?: 0);

        $stmt->execute(['RENEWAL']);
        $summary['last_30_renewal'] = (int)($stmt->fetchColumn() ?: 0);

        $stmt->execute(['EXPIRATION']);
        $summary['last_30_expiration'] = (int)($stmt->fetchColumn() ?: 0);

        $stmt->execute(['CANCELLATION']);
        $summary['last_30_cancellation'] = (int)($stmt->fetchColumn() ?: 0);
    }

    if ($webhook && $webhook['process_status']) {
        $createdAtCol = $webhook['processed_at'] ?: ($webhook['created_at'] ?: $webhook['event_timestamp']);
        if ($createdAtCol) {
            $sql = 'SELECT MAX(' . subscription_mgmt_q($createdAtCol) . ') FROM ' . subscription_mgmt_q($webhook['table'])
                . ' WHERE ' . subscription_mgmt_q($webhook['process_status']) . " IN ('processed', 'conflict')";
            $summary['last_successful_webhook_at'] = $pdo->query($sql)->fetchColumn() ?: null;
        }
    }

    $recentEvents = [];
    if ($webhook) {
        $orderCol = $webhook['created_at'] ?: ($webhook['event_timestamp'] ?: $webhook['event_id']);
        $select = [
            ($webhook['id'] ? subscription_mgmt_q($webhook['id']) : 'NULL') . ' AS id',
            subscription_mgmt_q($webhook['event_id']) . ' AS event_id',
            subscription_mgmt_q($webhook['event_type']) . ' AS event_type',
            ($webhook['app_user_id'] ? subscription_mgmt_q($webhook['app_user_id']) : 'NULL') . ' AS app_user_id',
            ($webhook['user_id'] ? subscription_mgmt_q($webhook['user_id']) : 'NULL') . ' AS user_id',
            ($webhook['process_status'] ? subscription_mgmt_q($webhook['process_status']) : "'processed'") . ' AS process_status',
            ($webhook['error_message'] ? subscription_mgmt_q($webhook['error_message']) : 'NULL') . ' AS error_message',
            ($webhook['created_at'] ? subscription_mgmt_q($webhook['created_at']) : 'NULL') . ' AS created_at',
        ];

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . subscription_mgmt_q($webhook['table'])
            . ' ORDER BY ' . subscription_mgmt_q($orderCol) . ' DESC LIMIT 12';
        $recentEvents = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $recentIssues = subscriptions_get_issues($pdo, 10);

    return [
        'summary' => $summary,
        'recent_events' => $recentEvents,
        'recent_issues' => $recentIssues['items'] ?? [],
    ];
}

function subscriptions_get_events(PDO $pdo): array
{
    $webhook = subscription_mgmt_webhook_schema($pdo);
    if (!$webhook) {
        return ['items' => [], 'total_count' => 0];
    }

    $where = [];
    $params = [];

    $eventType = trim((string)($_GET['event_type'] ?? ''));
    if ($eventType !== '' && $webhook['event_type']) {
        $where[] = subscription_mgmt_q($webhook['event_type']) . ' = ?';
        $params[] = strtoupper($eventType);
    }

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '' && $webhook['process_status']) {
        if ($status === 'duplicate') {
            $where[] = subscription_mgmt_q($webhook['is_duplicate'] ?: $webhook['process_status']) . ($webhook['is_duplicate'] ? ' = 1' : " = 'duplicate'");
        } else {
            $where[] = subscription_mgmt_q($webhook['process_status']) . ' = ?';
            $params[] = $status;
        }
    }

    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo = trim((string)($_GET['date_to'] ?? ''));
    $dateCol = $webhook['created_at'] ?: $webhook['event_timestamp'];
    if ($dateCol && $dateFrom !== '') {
        $where[] = 'DATE(' . subscription_mgmt_q($dateCol) . ') >= ?';
        $params[] = $dateFrom;
    }
    if ($dateCol && $dateTo !== '') {
        $where[] = 'DATE(' . subscription_mgmt_q($dateCol) . ') <= ?';
        $params[] = $dateTo;
    }

    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $searchWhere = [];
        foreach ([$webhook['user_id'], $webhook['app_user_id'], $webhook['original_app_user_id'], $webhook['rc_app_user_id'], $webhook['event_id']] as $col) {
            if (!$col) {
                continue;
            }
            $searchWhere[] = subscription_mgmt_q($col) . ' LIKE ?';
            $params[] = '%' . $search . '%';
        }
        if (!empty($searchWhere)) {
            $where[] = '(' . implode(' OR ', $searchWhere) . ')';
        }
    }

    $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
    $limit = subscriptions_to_limit(50, 300);
    $orderCol = $webhook['created_at'] ?: ($webhook['event_timestamp'] ?: $webhook['event_id']);

    $countSql = 'SELECT COUNT(*) FROM ' . subscription_mgmt_q($webhook['table']) . $whereSql;
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalCount = (int)$stmtCount->fetchColumn();

    $select = [
        ($webhook['id'] ? subscription_mgmt_q($webhook['id']) : 'NULL') . ' AS id',
        subscription_mgmt_q($webhook['event_id']) . ' AS event_id',
        ($webhook['event_type_raw'] ? subscription_mgmt_q($webhook['event_type_raw']) : 'NULL') . ' AS event_type_raw',
        subscription_mgmt_q($webhook['event_type']) . ' AS event_type',
        ($webhook['environment'] ? subscription_mgmt_q($webhook['environment']) : 'NULL') . ' AS environment',
        ($webhook['app_user_id'] ? subscription_mgmt_q($webhook['app_user_id']) : 'NULL') . ' AS app_user_id',
        ($webhook['original_app_user_id'] ? subscription_mgmt_q($webhook['original_app_user_id']) : 'NULL') . ' AS original_app_user_id',
        ($webhook['rc_app_user_id'] ? subscription_mgmt_q($webhook['rc_app_user_id']) : 'NULL') . ' AS rc_app_user_id',
        ($webhook['user_id'] ? subscription_mgmt_q($webhook['user_id']) : 'NULL') . ' AS user_id',
        ($webhook['is_matched'] ? subscription_mgmt_q($webhook['is_matched']) : '0') . ' AS is_matched',
        ($webhook['is_duplicate'] ? subscription_mgmt_q($webhook['is_duplicate']) : '0') . ' AS is_duplicate',
        ($webhook['process_status'] ? subscription_mgmt_q($webhook['process_status']) : "'processed'") . ' AS process_status',
        ($webhook['error_message'] ? subscription_mgmt_q($webhook['error_message']) : 'NULL') . ' AS error_message',
        ($webhook['source_ip'] ? subscription_mgmt_q($webhook['source_ip']) : 'NULL') . ' AS source_ip',
        ($webhook['event_timestamp'] ? subscription_mgmt_q($webhook['event_timestamp']) : 'NULL') . ' AS event_timestamp',
        ($webhook['created_at'] ? subscription_mgmt_q($webhook['created_at']) : 'NULL') . ' AS created_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . subscription_mgmt_q($webhook['table'])
        . $whereSql
        . ' ORDER BY ' . subscription_mgmt_q($orderCol) . ' DESC'
        . ' LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['items' => $items, 'total_count' => $totalCount, 'listed_count' => count($items)];
}

function subscriptions_get_history(PDO $pdo): array
{
    $history = subscription_mgmt_history_schema($pdo);
    if (!$history) {
        return ['items' => [], 'total_count' => 0];
    }

    $where = [];
    $params = [];

    $eventType = trim((string)($_GET['event_type'] ?? ''));
    if ($eventType !== '' && $history['event_type']) {
        $where[] = subscription_mgmt_q($history['event_type']) . ' = ?';
        $params[] = strtoupper($eventType);
    }

    $userSearch = trim((string)($_GET['user'] ?? ''));
    if ($userSearch !== '' && $history['user_id']) {
        $where[] = subscription_mgmt_q($history['user_id']) . ' LIKE ?';
        $params[] = '%' . $userSearch . '%';
    }

    $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
    $limit = subscriptions_to_limit(100, 500);
    $orderCol = $history['created_at'] ?: ($history['id'] ?: $history['event_id']);

    $countSql = 'SELECT COUNT(*) FROM ' . subscription_mgmt_q($history['table']) . $whereSql;
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalCount = (int)$stmtCount->fetchColumn();

    $select = [
        ($history['id'] ? subscription_mgmt_q($history['id']) : 'NULL') . ' AS id',
        ($history['user_id'] ? subscription_mgmt_q($history['user_id']) : 'NULL') . ' AS user_id',
        ($history['event_type'] ? subscription_mgmt_q($history['event_type']) : 'NULL') . ' AS event_type',
        ($history['plan_code'] ? subscription_mgmt_q($history['plan_code']) : 'NULL') . ' AS plan_code',
        ($history['provider'] ? subscription_mgmt_q($history['provider']) : 'NULL') . ' AS provider',
        ($history['store'] ? subscription_mgmt_q($history['store']) : 'NULL') . ' AS store',
        ($history['entitlement_id'] ? subscription_mgmt_q($history['entitlement_id']) : 'NULL') . ' AS entitlement_id',
        ($history['old_value'] ? subscription_mgmt_q($history['old_value']) : 'NULL') . ' AS old_value',
        ($history['new_value'] ? subscription_mgmt_q($history['new_value']) : 'NULL') . ' AS new_value',
        ($history['source'] ? subscription_mgmt_q($history['source']) : 'NULL') . ' AS source',
        ($history['created_at'] ? subscription_mgmt_q($history['created_at']) : 'NULL') . ' AS created_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . subscription_mgmt_q($history['table'])
        . $whereSql
        . ' ORDER BY ' . subscription_mgmt_q($orderCol) . ' DESC'
        . ' LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['items' => $items, 'total_count' => $totalCount, 'listed_count' => count($items)];
}

function subscriptions_get_active(PDO $pdo): array
{
    $subSchema = null;
    try {
        $subSchema = usage_limits_get_subscription_schema($pdo);
    } catch (Throwable $e) {
        $subSchema = null;
    }

    $userSchema = subscriptions_user_schema($pdo);
    if (!$subSchema) {
        return ['items' => [], 'total_count' => 0];
    }

    $limit = subscriptions_to_limit(100, 500);
    $select = [
        subscription_mgmt_q($subSchema['user_id']) . ' AS user_id',
        subscription_mgmt_q($subSchema['is_pro']) . ' AS is_pro',
        ($subSchema['plan_code'] ? subscription_mgmt_q($subSchema['plan_code']) : 'NULL') . ' AS plan_code',
        ($subSchema['entitlement_id'] ? subscription_mgmt_q($subSchema['entitlement_id']) : 'NULL') . ' AS entitlement_id',
        ($subSchema['expires_at'] ? subscription_mgmt_q($subSchema['expires_at']) : 'NULL') . ' AS expires_at',
        ($subSchema['last_synced_at'] ? subscription_mgmt_q($subSchema['last_synced_at']) : 'NULL') . ' AS last_synced_at',
        ($subSchema['rc_app_user_id'] ? subscription_mgmt_q($subSchema['rc_app_user_id']) : 'NULL') . ' AS rc_app_user_id',
        "'revenuecat' AS provider",
    ];

    $join = '';
    if ($userSchema) {
        $select[] = ($userSchema['full_name'] ? ('u.' . subscription_mgmt_q($userSchema['full_name'])) : "''") . ' AS full_name';
        $select[] = ($userSchema['email'] ? ('u.' . subscription_mgmt_q($userSchema['email'])) : "''") . ' AS email';
        $join = ' LEFT JOIN ' . subscription_mgmt_q($userSchema['table']) . ' u ON u.' . subscription_mgmt_q($userSchema['id'])
            . ' = s.' . subscription_mgmt_q($subSchema['user_id']);
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . subscription_mgmt_q($subSchema['table']) . ' s'
        . $join
        . ' WHERE s.' . subscription_mgmt_q($subSchema['is_pro']) . ' = 1';

    if ($subSchema['expires_at']) {
        $sql .= ' AND s.' . subscription_mgmt_q($subSchema['expires_at']) . ' IS NOT NULL AND s.' . subscription_mgmt_q($subSchema['expires_at']) . ' > NOW()';
    }

    $sql .= ' ORDER BY s.' . subscription_mgmt_q($subSchema['expires_at'] ?: $subSchema['user_id']) . ' ASC';
    $sql .= ' LIMIT ' . (int)$limit;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['items' => $rows, 'total_count' => count($rows), 'listed_count' => count($rows)];
}

function subscriptions_get_issues(PDO $pdo, ?int $forceLimit = null): array
{
    $webhook = subscription_mgmt_webhook_schema($pdo);
    $subSchema = null;
    try {
        $subSchema = usage_limits_get_subscription_schema($pdo);
    } catch (Throwable $e) {
        $subSchema = null;
    }

    $limit = $forceLimit ?: subscriptions_to_limit(100, 500);
    $items = [];

    if ($webhook && $webhook['process_status']) {
        $orderCol = $webhook['created_at'] ?: ($webhook['event_timestamp'] ?: $webhook['event_id']);
        $select = [
            ($webhook['id'] ? subscription_mgmt_q($webhook['id']) : 'NULL') . ' AS id',
            subscription_mgmt_q($webhook['event_id']) . ' AS event_id',
            subscription_mgmt_q($webhook['event_type']) . ' AS event_type',
            ($webhook['user_id'] ? subscription_mgmt_q($webhook['user_id']) : 'NULL') . ' AS user_id',
            ($webhook['app_user_id'] ? subscription_mgmt_q($webhook['app_user_id']) : 'NULL') . ' AS app_user_id',
            subscription_mgmt_q($webhook['process_status']) . ' AS process_status',
            ($webhook['error_message'] ? subscription_mgmt_q($webhook['error_message']) : 'NULL') . ' AS error_message',
            ($webhook['is_duplicate'] ? subscription_mgmt_q($webhook['is_duplicate']) : '0') . ' AS is_duplicate',
            ($webhook['is_matched'] ? subscription_mgmt_q($webhook['is_matched']) : '0') . ' AS is_matched',
            ($webhook['created_at'] ? subscription_mgmt_q($webhook['created_at']) : 'NULL') . ' AS created_at',
        ];

        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . subscription_mgmt_q($webhook['table'])
            . ' WHERE ' . subscription_mgmt_q($webhook['process_status']) . " IN ('failed', 'duplicate', 'unmatched_user', 'conflict')"
            . ' OR ' . ($webhook['is_duplicate'] ? subscription_mgmt_q($webhook['is_duplicate']) . ' = 1' : "1=0")
            . ' ORDER BY ' . subscription_mgmt_q($orderCol) . ' DESC'
            . ' LIMIT ' . (int)$limit;

        $items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($subSchema && count($items) < $limit && $subSchema['expires_at']) {
        $remaining = $limit - count($items);
        $sqlStale = 'SELECT '
            . 's.' . subscription_mgmt_q($subSchema['user_id']) . ' AS user_id, '
            . "NULL AS event_id, 'STALE_PREMIUM' AS event_type, "
            . "'status_conflict' AS process_status, 'expired but stale state' AS error_message, "
            . 's.' . subscription_mgmt_q($subSchema['expires_at']) . ' AS created_at '
            . 'FROM ' . subscription_mgmt_q($subSchema['table']) . ' s '
            . 'WHERE s.' . subscription_mgmt_q($subSchema['is_pro']) . ' = 1'
            . ' AND s.' . subscription_mgmt_q($subSchema['expires_at']) . ' IS NOT NULL'
            . ' AND s.' . subscription_mgmt_q($subSchema['expires_at']) . ' <= NOW()'
            . ' ORDER BY s.' . subscription_mgmt_q($subSchema['expires_at']) . ' DESC'
            . ' LIMIT ' . (int)$remaining;
        $staleRows = $pdo->query($sqlStale)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($staleRows as $row) {
            $row['id'] = 'stale_' . ($row['user_id'] ?? uniqid('', true));
            $row['is_duplicate'] = 0;
            $row['is_matched'] = 1;
            $items[] = $row;
        }
    }

    return [
        'items' => $items,
        'total_count' => count($items),
        'listed_count' => count($items),
    ];
}

function subscriptions_get_event_payload_detail(PDO $pdo, string $id): array
{
    $webhook = subscription_mgmt_webhook_schema($pdo);
    if (!$webhook) {
        return [];
    }

    $idCol = $webhook['id'] ?: $webhook['event_id'];
    $select = [
        ($webhook['id'] ? subscription_mgmt_q($webhook['id']) : 'NULL') . ' AS id',
        subscription_mgmt_q($webhook['event_id']) . ' AS event_id',
        subscription_mgmt_q($webhook['event_type']) . ' AS event_type',
        ($webhook['payload_json'] ? subscription_mgmt_q($webhook['payload_json']) : 'NULL') . ' AS payload_json',
        ($webhook['headers_json'] ? subscription_mgmt_q($webhook['headers_json']) : 'NULL') . ' AS headers_json',
        ($webhook['error_message'] ? subscription_mgmt_q($webhook['error_message']) : 'NULL') . ' AS error_message',
        ($webhook['process_status'] ? subscription_mgmt_q($webhook['process_status']) : "'processed'") . ' AS process_status',
        ($webhook['created_at'] ? subscription_mgmt_q($webhook['created_at']) : 'NULL') . ' AS created_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . subscription_mgmt_q($webhook['table'])
        . ' WHERE ' . subscription_mgmt_q($idCol) . ' = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (empty($row)) {
        return [];
    }

    $row['payload'] = [];
    if (!empty($row['payload_json'])) {
        $decoded = json_decode((string)$row['payload_json'], true);
        if (is_array($decoded)) {
            $row['payload'] = $decoded;
        }
    }

    $row['headers'] = [];
    if (!empty($row['headers_json'])) {
        $decoded = json_decode((string)$row['headers_json'], true);
        if (is_array($decoded)) {
            $row['headers'] = $decoded;
        }
    }

    return $row;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'dashboard_summary':
            subscriptions_json(true, 'OK', subscriptions_get_dashboard($pdo));
            break;

        case 'list_events':
            subscriptions_json(true, 'OK', subscriptions_get_events($pdo));
            break;

        case 'list_history':
            subscriptions_json(true, 'OK', subscriptions_get_history($pdo));
            break;

        case 'list_active':
            subscriptions_json(true, 'OK', subscriptions_get_active($pdo));
            break;

        case 'list_issues':
            subscriptions_json(true, 'OK', subscriptions_get_issues($pdo));
            break;

        case 'event_payload_detail': {
            $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
            if ($id === '') {
                subscriptions_json(false, 'id parametresi zorunlu.', [], 422);
            }
            subscriptions_json(true, 'OK', ['item' => subscriptions_get_event_payload_detail($pdo, $id)]);
            break;
        }

        default:
            subscriptions_json(false, 'Geçersiz action.', [], 400);
    }
} catch (Throwable $e) {
    subscriptions_json(false, 'Abonelik verileri alınırken hata oluştu.', [
        'error' => $e->getMessage(),
    ], 500);
}

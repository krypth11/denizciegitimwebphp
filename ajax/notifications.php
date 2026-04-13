<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/notification_helper.php';

$authUser = require_admin();

function notifications_json(bool $success, string $message = '', array $data = [], int $status = 200, array $errors = []): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function notifications_safe_json_decode(?string $json): array
{
    $raw = trim((string)$json);
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new InvalidArgumentException('Payload JSON geçerli bir obje olmalıdır.');
    }

    return $decoded;
}

function notifications_validate_channel(string $channel): string
{
    $allowed = ['general', 'study', 'exam', 'community', 'premium', 'system'];
    return in_array($channel, $allowed, true) ? $channel : 'general';
}

function notifications_normalize_target_type(string $type): string
{
    $map = [
        'single_user' => 'single_user',
        'all_users' => 'all_users',
        'premium_users' => 'premium_users',
        'free_users' => 'free_users',
        'qualification' => 'qualification',
        'last_7_days_active' => 'last_7_days_active',
        'last_30_days_passive' => 'last_30_days_passive',
    ];
    return $map[$type] ?? 'all_users';
}

function notifications_build_target_value(string $targetType): array
{
    if ($targetType === 'single_user') {
        $userId = trim((string)($_POST['target_user_id'] ?? ''));
        if ($userId === '') {
            throw new InvalidArgumentException('Tek kullanıcı hedefi için kullanıcı seçimi zorunludur.');
        }
        return ['user_id' => $userId];
    }

    if ($targetType === 'qualification') {
        $qualificationId = trim((string)($_POST['target_qualification_id'] ?? ''));
        if ($qualificationId === '') {
            throw new InvalidArgumentException('Yeterlilik hedefi için yeterlilik seçimi zorunludur.');
        }
        return ['qualification_id' => $qualificationId];
    }

    return [];
}

function notifications_list_history(PDO $pdo): array
{
    $schema = notification_schema($pdo);
    $n = $schema['notifications'];

    $select = [
        notification_q($n['id']) . ' AS id',
        notification_q($n['title']) . ' AS title',
        notification_q($n['message']) . ' AS message',
    ];

    $optionalMap = [
        'channel' => $n['channel'],
        'target_type' => $n['target_type'],
        'status' => $n['status'],
        'scheduled_at' => $n['scheduled_at'],
        'sent_at' => $n['sent_at'],
        'created_at' => $n['created_at'],
        'total_target' => $n['total_target'],
        'success_count' => $n['success_count'],
        'failure_count' => $n['failure_count'],
    ];

    foreach ($optionalMap as $alias => $column) {
        $select[] = $column ? notification_q($column) . ' AS ' . $alias : 'NULL AS ' . $alias;
    }

    $orderBy = $n['created_at'] ? notification_q($n['created_at']) : notification_q($n['id']);
    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . notification_q($n['table'])
        . ' ORDER BY ' . $orderBy . ' DESC LIMIT 500';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function notifications_stats_summary(PDO $pdo): array
{
    $schema = notification_schema($pdo);
    $t = $schema['tokens'];
    $n = $schema['notifications'];
    $l = $schema['logs'];

    $totalTokens = (int)$pdo->query('SELECT COUNT(*) FROM ' . notification_q($t['table']))->fetchColumn();

    $activeTokens = 0;
    if ($t['is_active']) {
        $activeTokens = (int)$pdo->query('SELECT COUNT(*) FROM ' . notification_q($t['table']) . ' WHERE ' . notification_q($t['is_active']) . ' = 1')->fetchColumn();
    }

    $androidCount = 0;
    $iosCount = 0;
    if ($t['platform']) {
        $androidCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . notification_q($t['table']) . ' WHERE LOWER(' . notification_q($t['platform']) . ") = 'android'")->fetchColumn();
        $iosCount = (int)$pdo->query('SELECT COUNT(*) FROM ' . notification_q($t['table']) . ' WHERE LOWER(' . notification_q($t['platform']) . ") = 'ios'")->fetchColumn();
    }

    $todaySent = 0;
    if ($n['created_at']) {
        $todaySent = (int)$pdo->query('SELECT COUNT(*) FROM ' . notification_q($n['table']) . ' WHERE DATE(' . notification_q($n['created_at']) . ') = CURDATE()')->fetchColumn();
    }

    $successRate = 0;
    if ($l['created_at'] && $l['is_success']) {
        $sql = 'SELECT COUNT(*) AS total, SUM(CASE WHEN ' . notification_q($l['is_success']) . ' = 1 THEN 1 ELSE 0 END) AS ok
                FROM ' . notification_q($l['table']) . '
                WHERE ' . notification_q($l['created_at']) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'ok' => 0];
        $total = (int)($row['total'] ?? 0);
        $ok = (int)($row['ok'] ?? 0);
        $successRate = $total > 0 ? round(($ok / $total) * 100, 2) : 0;
    }

    $chart = [];
    $chartSql = 'SELECT DATE(' . notification_q($l['created_at'] ?: ($n['created_at'] ?: $n['id'])) . ') AS day, COUNT(*) AS total
                 FROM ' . notification_q($l['table']) . '
                 WHERE ' . notification_q($l['created_at'] ?: ($n['created_at'] ?: $n['id'])) . ' >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                 GROUP BY DATE(' . notification_q($l['created_at'] ?: ($n['created_at'] ?: $n['id'])) . ')
                 ORDER BY day ASC';

    try {
        $chartRows = $pdo->query($chartSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexed = [];
        foreach ($chartRows as $row) {
            $indexed[(string)$row['day']] = (int)$row['total'];
        }
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $chart[] = ['day' => $day, 'total' => $indexed[$day] ?? 0];
        }
    } catch (Throwable $e) {
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $chart[] = ['day' => $day, 'total' => 0];
        }
    }

    return [
        'total_tokens' => $totalTokens,
        'active_tokens' => $activeTokens,
        'android_devices' => $androidCount,
        'ios_devices' => $iosCount,
        'today_sent_notifications' => $todaySent,
        'success_rate_7d' => $successRate,
        'chart_7d' => $chart,
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $schema = notification_schema($pdo);
    $n = $schema['notifications'];
    $l = $schema['logs'];
    $r = $schema['rules'];
    $t = $schema['tokens'];
    $u = $schema['users'];

    switch ($action) {
        case 'create_notification':
        case 'save_draft':
        case 'send_now': {
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $imageUrl = trim((string)($_POST['image_url'] ?? ''));
            $deepLink = trim((string)($_POST['deep_link'] ?? ''));
            $payloadJsonRaw = (string)($_POST['payload_json'] ?? '');
            $channel = notifications_validate_channel(trim((string)($_POST['channel'] ?? 'general')));
            $targetType = notifications_normalize_target_type(trim((string)($_POST['target_type'] ?? 'all_users')));
            $requestScheduleMode = trim((string)($_POST['schedule_mode'] ?? 'now'));
            $scheduleMode = $requestScheduleMode;
            if ($action === 'save_draft') {
                $scheduleMode = 'draft';
            } elseif ($action === 'send_now') {
                $scheduleMode = 'now';
            }
            $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));

            if ($title === '' || $message === '') {
                notifications_json(false, 'Başlık ve mesaj zorunludur.', [], 422, ['title' => 'required', 'message' => 'required']);
            }

            $payloadJson = notifications_safe_json_decode($payloadJsonRaw);
            $targetValue = notifications_build_target_value($targetType);

            $status = 'queued';
            if ($scheduleMode === 'draft') {
                $status = 'draft';
            } elseif ($scheduleMode === 'scheduled') {
                if ($scheduledAt === '') {
                    notifications_json(false, 'Planlı gönderim için tarih/saat zorunludur.', [], 422, ['scheduled_at' => 'required']);
                }
                $status = 'scheduled';
            }

            $notificationPayload = [
                'title' => $title,
                'message' => $message,
                'image_url' => ($imageUrl !== '' ? $imageUrl : null),
                'deep_link' => ($deepLink !== '' ? $deepLink : null),
                'payload_json' => !empty($payloadJson) ? json_encode($payloadJson, JSON_UNESCAPED_UNICODE) : null,
                'channel' => $channel,
                'target_type' => $targetType,
                'target_value' => !empty($targetValue) ? json_encode($targetValue, JSON_UNESCAPED_UNICODE) : null,
                'status' => $status,
                'schedule_type' => $scheduleMode,
                'scheduled_at' => ($scheduleMode === 'scheduled' ? $scheduledAt : null),
                'created_by' => (string)($authUser['user_id'] ?? ($_SESSION['user_id'] ?? '')),
            ];

            $notificationId = trim((string)($_POST['notification_id'] ?? ''));
            $notificationId = notification_create_or_update($pdo, $notificationPayload, $notificationId !== '' ? $notificationId : null);

            if ($action === 'send_now' || ($action === 'create_notification' && $scheduleMode === 'now')) {
                $result = send_push_notification($pdo, $notificationId);
                notifications_json(true, 'Bildirim gönderim kuyruğuna alındı.', ['notification_id' => $notificationId, 'send_result' => $result]);
            }

            notifications_json(true, $status === 'draft' ? 'Taslak kaydedildi.' : 'Bildirim kaydedildi.', ['notification_id' => $notificationId]);
            break;
        }

        case 'list_history': {
            $items = notifications_list_history($pdo);
            notifications_json(true, '', ['items' => $items]);
            break;
        }

        case 'get_notification_detail': {
            $id = trim((string)($_GET['notification_id'] ?? $_POST['notification_id'] ?? ''));
            if ($id === '') {
                notifications_json(false, 'notification_id zorunludur.', [], 422, ['notification_id' => 'required']);
            }

            $notification = notification_fetch_one($pdo, $id);
            if (!$notification) {
                notifications_json(false, 'Bildirim bulunamadı.', [], 404);
            }

            $select = ['*'];
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . notification_q($l['table'])
                . ' WHERE ' . notification_q($l['notification_id']) . ' = ?';
            if ($l['created_at']) {
                $sql .= ' ORDER BY ' . notification_q($l['created_at']) . ' DESC';
            }
            $sql .= ' LIMIT 1000';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            notifications_json(true, '', ['notification' => $notification, 'logs' => $logs]);
            break;
        }

        case 'duplicate_notification': {
            $id = trim((string)($_POST['notification_id'] ?? ''));
            if ($id === '') {
                notifications_json(false, 'notification_id zorunludur.', [], 422, ['notification_id' => 'required']);
            }
            $notification = notification_fetch_one($pdo, $id);
            if (!$notification) {
                notifications_json(false, 'Bildirim bulunamadı.', [], 404);
            }

            $payload = [
                'title' => (string)($notification[$n['title']] ?? '') . ' (Kopya)',
                'message' => (string)($notification[$n['message']] ?? ''),
                'image_url' => $n['image_url'] ? ($notification[$n['image_url']] ?? null) : null,
                'deep_link' => $n['deep_link'] ? ($notification[$n['deep_link']] ?? null) : null,
                'payload_json' => $n['payload_json'] ? ($notification[$n['payload_json']] ?? null) : null,
                'channel' => $n['channel'] ? ($notification[$n['channel']] ?? 'general') : 'general',
                'target_type' => $n['target_type'] ? ($notification[$n['target_type']] ?? 'all_users') : 'all_users',
                'target_value' => $n['target_value'] ? ($notification[$n['target_value']] ?? null) : null,
                'status' => 'draft',
                'schedule_type' => 'draft',
                'created_by' => (string)($authUser['user_id'] ?? ($_SESSION['user_id'] ?? '')),
            ];

            $newId = notification_create_or_update($pdo, $payload);
            notifications_json(true, 'Bildirim kopyalandı.', ['notification_id' => $newId]);
            break;
        }

        case 'resend_notification': {
            $id = trim((string)($_POST['notification_id'] ?? ''));
            if ($id === '') {
                notifications_json(false, 'notification_id zorunludur.', [], 422, ['notification_id' => 'required']);
            }

            $notification = notification_fetch_one($pdo, $id);
            if (!$notification) {
                notifications_json(false, 'Bildirim bulunamadı.', [], 404);
            }

            $result = send_push_notification($pdo, $id);
            notifications_json(true, 'Bildirim yeniden gönderildi.', ['send_result' => $result]);
            break;
        }

        case 'list_tokens': {
            $where = ['1=1'];
            $params = [];

            $platform = strtolower(trim((string)($_GET['platform'] ?? '')));
            $active = trim((string)($_GET['active'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            if ($platform !== '' && $t['platform']) {
                $where[] = 'LOWER(t.' . notification_q($t['platform']) . ') = ?';
                $params[] = $platform;
            }

            if ($active !== '' && $t['is_active']) {
                $where[] = 't.' . notification_q($t['is_active']) . ' = ?';
                $params[] = ($active === '1' || strtolower($active) === 'active') ? 1 : 0;
            }

            if ($search !== '' && $u['email']) {
                $where[] = '(u.' . notification_q($u['email']) . ' LIKE ?' . ($u['full_name'] ? ' OR u.' . notification_q($u['full_name']) . ' LIKE ?' : '') . ')';
                $params[] = '%' . $search . '%';
                if ($u['full_name']) $params[] = '%' . $search . '%';
            }

            $select = [
                't.' . notification_q($t['id']) . ' AS id',
                't.' . notification_q($t['user_id']) . ' AS user_id',
                't.' . notification_q($t['fcm_token']) . ' AS fcm_token',
                ($t['platform'] ? 't.' . notification_q($t['platform']) : "'unknown'") . ' AS platform',
                ($t['app_version'] ? 't.' . notification_q($t['app_version']) : 'NULL') . ' AS app_version',
                ($t['is_active'] ? 't.' . notification_q($t['is_active']) : '1') . ' AS is_active',
                ($t['last_seen_at'] ? 't.' . notification_q($t['last_seen_at']) : 'NULL') . ' AS last_seen_at',
                ($u['email'] ? 'u.' . notification_q($u['email']) : 'NULL') . ' AS email',
                ($u['full_name'] ? 'u.' . notification_q($u['full_name']) : 'NULL') . ' AS full_name',
            ];

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . notification_q($t['table']) . ' t'
                . ' LEFT JOIN ' . notification_q($u['table']) . ' u ON u.' . notification_q($u['id']) . ' = t.' . notification_q($t['user_id'])
                . ' WHERE ' . implode(' AND ', $where);

            $order = $t['last_seen_at'] ? 't.' . notification_q($t['last_seen_at']) : 't.' . notification_q($t['id']);
            $sql .= ' ORDER BY ' . $order . ' DESC LIMIT 1000';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $rows = array_map(static function (array $row): array {
                $tokenRaw = (string)($row['fcm_token'] ?? '');
                $row['token_masked'] = mask_token($tokenRaw);
                unset($row['fcm_token']);
                return $row;
            }, $rows);

            notifications_json(true, '', ['items' => $rows]);
            break;
        }

        case 'toggle_rule': {
            $id = trim((string)($_POST['rule_id'] ?? ''));
            $active = trim((string)($_POST['is_active'] ?? ''));
            $configJson = trim((string)($_POST['config_json'] ?? ''));

            if ($id === '') {
                notifications_json(false, 'rule_id zorunludur.', [], 422, ['rule_id' => 'required']);
            }

            $payload = [];
            if ($r['is_active']) {
                $payload[$r['is_active']] = ($active === '1' || strtolower($active) === 'true' || strtolower($active) === 'active') ? 1 : 0;
            }
            if ($r['config_json'] && $configJson !== '') {
                notifications_safe_json_decode($configJson);
                $payload[$r['config_json']] = $configJson;
            }
            if ($r['updated_at']) {
                $payload[$r['updated_at']] = date('Y-m-d H:i:s');
            }

            notification_update_row($pdo, $r['table'], $payload, notification_q($r['id']) . ' = ?', [$id]);
            notifications_json(true, 'Kural güncellendi.');
            break;
        }

        case 'stats_summary': {
            notifications_json(true, '', notifications_stats_summary($pdo));
            break;
        }

        case 'list_rules': {
            $select = [
                notification_q($r['id']) . ' AS id',
                ($r['name'] ? notification_q($r['name']) : 'NULL') . ' AS name',
                ($r['slug'] ? notification_q($r['slug']) : 'NULL') . ' AS slug',
                ($r['description'] ? notification_q($r['description']) : 'NULL') . ' AS description',
                ($r['config_json'] ? notification_q($r['config_json']) : 'NULL') . ' AS config_json',
                ($r['is_active'] ? notification_q($r['is_active']) : '1') . ' AS is_active',
            ];
            $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . notification_q($r['table']) . ' ORDER BY ' . ($r['created_at'] ? notification_q($r['created_at']) : notification_q($r['id'])) . ' DESC';
            $items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            notifications_json(true, '', ['items' => $items]);
            break;
        }

        case 'search_users': {
            $q = trim((string)($_GET['q'] ?? ''));
            $select = [
                notification_q($u['id']) . ' AS id',
                ($u['full_name'] ? notification_q($u['full_name']) : 'NULL') . ' AS full_name',
                ($u['email'] ? notification_q($u['email']) : 'NULL') . ' AS email',
            ];

            $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . notification_q($u['table']) . ' WHERE 1=1';
            $params = [];

            if ($u['is_deleted']) {
                $sql .= ' AND ' . notification_q($u['is_deleted']) . ' = 0';
            }

            if ($q !== '' && $u['email']) {
                $sql .= ' AND (' . notification_q($u['email']) . ' LIKE ?' . ($u['full_name'] ? ' OR ' . notification_q($u['full_name']) . ' LIKE ?' : '') . ')';
                $params[] = '%' . $q . '%';
                if ($u['full_name']) $params[] = '%' . $q . '%';
            }

            $sql .= ' ORDER BY ' . ($u['full_name'] ? notification_q($u['full_name']) : notification_q($u['id'])) . ' ASC LIMIT 25';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            notifications_json(true, '', ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
            break;
        }

        case 'list_qualifications': {
            $qf = $schema['qualifications'];
            if (!$qf['id'] || !$qf['name']) {
                notifications_json(true, '', ['items' => []]);
            }

            $sql = 'SELECT ' . notification_q($qf['id']) . ' AS id, ' . notification_q($qf['name']) . ' AS name FROM ' . notification_q($qf['table']) . ' WHERE 1=1';
            if ($qf['is_active']) {
                $sql .= ' AND ' . notification_q($qf['is_active']) . ' = 1';
            }
            $sql .= ' ORDER BY ' . notification_q($qf['name']) . ' ASC';
            notifications_json(true, '', ['items' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []]);
            break;
        }

        default:
            notifications_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (InvalidArgumentException $e) {
    notifications_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('[ajax.notifications] ' . $e->getMessage());
    notifications_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

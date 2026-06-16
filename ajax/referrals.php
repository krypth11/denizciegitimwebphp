<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/referral_helper.php';

$authUser = require_admin();

function referrals_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function referrals_limit(int $default = 100, int $max = 500): int
{
    $v = filter_var($_GET['limit'] ?? $default, FILTER_VALIDATE_INT);
    return max(1, min($max, (int)($v ?: $default)));
}

function referrals_get_rules(PDO $pdo): array
{
    if (!referral_table_exists($pdo, 'referral_reward_rules')) return [];
    return $pdo->query('SELECT * FROM referral_reward_rules ORDER BY plan_code ASC, product_id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function referrals_save_rule(PDO $pdo, array $p): array
{
    $id = trim((string)($p['id'] ?? ''));
    $vals = [
        trim((string)($p['plan_code'] ?? 'monthly')) ?: 'monthly',
        trim((string)($p['product_id'] ?? '')) ?: null,
        max(0, (int)($p['referrer_reward_days'] ?? 0)),
        max(0, (int)($p['referred_reward_days'] ?? 0)),
        max(0, (int)($p['referrer_bonus_percent_delta'] ?? 0)),
        max(0, (int)($p['waiting_days'] ?? 0)),
        !empty($p['is_active']) ? 1 : 0,
    ];
    if ($id === '') {
        $id = generate_uuid();
        $pdo->prepare('INSERT INTO referral_reward_rules (id, plan_code, product_id, referrer_reward_days, referred_reward_days, referrer_bonus_percent_delta, waiting_days, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute(array_merge([$id], $vals));
    } else {
        $pdo->prepare('UPDATE referral_reward_rules SET plan_code=?, product_id=?, referrer_reward_days=?, referred_reward_days=?, referrer_bonus_percent_delta=?, waiting_days=?, is_active=?, updated_at=NOW() WHERE id=?')->execute(array_merge($vals, [$id]));
    }
    $st = $pdo->prepare('SELECT * FROM referral_reward_rules WHERE id=? LIMIT 1'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $id];
}

function referrals_list_events(PDO $pdo): array
{
    $where = []; $params = [];
    $status = trim((string)($_GET['status'] ?? $_POST['status'] ?? ''));
    if ($status !== '') { $where[] = 'e.status = ?'; $params[] = $status; }
    $kind = trim((string)($_GET['event_kind'] ?? $_POST['event_kind'] ?? ''));
    if ($kind !== '') { $where[] = 'e.event_kind = ?'; $params[] = $kind; }
    $search = trim((string)($_GET['search'] ?? $_POST['search'] ?? ''));
    if ($search !== '') { $where[] = '(e.referrer_user_id LIKE ? OR e.referred_user_id LIKE ? OR e.purchase_user_id LIKE ? OR e.product_id LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $limit = referrals_limit();
    $count = $pdo->prepare('SELECT COUNT(*) FROM referral_reward_events e' . $whereSql); $count->execute($params);
    $sql = "SELECT e.*, ru.full_name AS referrer_name, tu.full_name AS referred_name, pu.full_name AS purchase_user_name
            FROM referral_reward_events e
            LEFT JOIN user_profiles ru ON ru.id = e.referrer_user_id
            LEFT JOIN user_profiles tu ON tu.id = e.referred_user_id
            LEFT JOIN user_profiles pu ON pu.id = e.purchase_user_id" . $whereSql . " ORDER BY e.created_at DESC LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql); $st->execute($params);
    return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total_count' => (int)$count->fetchColumn()];
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$payload = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $raw = file_get_contents('php://input');
    $json = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (is_array($json)) $payload = array_merge($payload, $json);
}

try {
    switch ($action) {
        case 'get_settings':
            referrals_json(true, 'OK', ['settings' => referral_get_global_settings($pdo), 'rules' => referrals_get_rules($pdo)]);
        case 'save_global_settings':
            referrals_json(true, 'Ayarlar kaydedildi.', ['settings' => referral_save_global_settings($pdo, $payload)]);
        case 'save_rule':
            referrals_json(true, 'Kural kaydedildi.', ['rule' => referrals_save_rule($pdo, $payload)]);
        case 'list_events':
            referrals_json(true, 'OK', referrals_list_events($pdo));
        case 'approve_event':
            referrals_json(true, 'Event onaylandı.', referral_approve_reward_event($pdo, (string)($payload['id'] ?? ''), (string)($authUser['id'] ?? ''), (string)($payload['note'] ?? '')));
        case 'reject_event':
            referrals_json(true, 'Event reddedildi.', referral_reject_reward_event($pdo, (string)($payload['id'] ?? ''), (string)($authUser['id'] ?? ''), (string)($payload['note'] ?? '')));
        case 'reverse_event':
            referrals_json(true, 'Event geri alındı.', ['reversed_count' => referral_reverse_reward_event($pdo, (string)($payload['id'] ?? ''), (string)($payload['note'] ?? 'admin_reverse'))]);
        case 'process_pending':
            referrals_json(true, 'Pending eventler işlendi.', referral_process_pending_rewards($pdo, (int)($payload['limit'] ?? 200)));
        case 'get_event_detail':
            $st = $pdo->prepare('SELECT * FROM referral_reward_events WHERE id=? LIMIT 1'); $st->execute([(string)($_GET['id'] ?? $payload['id'] ?? '')]);
            referrals_json(true, 'OK', ['item' => $st->fetch(PDO::FETCH_ASSOC) ?: null]);
        case 'mark_suspicious':
            $pdo->prepare("UPDATE referral_reward_events SET status='suspicious', is_suspicious=1, admin_note=?, updated_at=NOW() WHERE id=? AND status='pending'")->execute([(string)($payload['note'] ?? 'admin_mark_suspicious'), (string)($payload['id'] ?? '')]);
            referrals_json(true, 'Event şüpheli işaretlendi.');
        default:
            referrals_json(false, 'Geçersiz action.', [], 400);
    }
} catch (Throwable $e) {
    referrals_json(false, 'Referans işlemi sırasında hata oluştu.', ['error' => $e->getMessage()], 500);
}

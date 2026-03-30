<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/community_helper.php';

$authUser = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function cr_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $room = community_room_schema($pdo);

    if ($action === 'list') {
        community_ensure_general_room($pdo);

        $quals = $pdo->query('SELECT id, name FROM qualifications')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($quals as $q) {
            community_sync_qualification_room($pdo, (string)$q['id'], (string)$q['name'], true);
        }

        $sql = "SELECT `{$room['id']}` AS id, `{$room['name']}` AS name, "
            . ($room['description'] ? "`{$room['description']}` AS description, " : "'' AS description, ")
            . "`{$room['type']}` AS type, "
            . ($room['qualification_id'] ? "`{$room['qualification_id']}` AS qualification_id, " : "NULL AS qualification_id, ")
            . ($room['sort_order'] ? "`{$room['sort_order']}` AS sort_order, " : "0 AS sort_order, ")
            . "`{$room['is_active']}` AS is_active"
            . " FROM `{$room['table']}`"
            . " ORDER BY COALESCE(" . ($room['sort_order'] ? "`{$room['sort_order']}`" : '0') . ",0) ASC, `{$room['name']}` ASC";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $qMap = [];
        foreach ($quals as $q) {
            $qMap[(string)$q['id']] = (string)$q['name'];
        }

        $rooms = array_map(static function (array $r) use ($qMap): array {
            $type = (string)($r['type'] ?? '');
            $isSystem = in_array($type, ['general', 'qualification'], true);
            $qid = (string)($r['qualification_id'] ?? '');
            return [
                'id' => (string)$r['id'],
                'name' => (string)$r['name'],
                'description' => (string)($r['description'] ?? ''),
                'type' => $type,
                'is_system' => $isSystem ? 1 : 0,
                'qualification_id' => $qid !== '' ? $qid : null,
                'qualification_name' => $qid !== '' ? ($qMap[$qid] ?? '-') : '-',
                'sort_order' => (int)($r['sort_order'] ?? 0),
                'is_active' => (int)($r['is_active'] ?? 0) === 1 ? 1 : 0,
            ];
        }, $rows);

        cr_json(true, '', ['rooms' => $rooms]);
    }

    if ($action === 'get') {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            cr_json(false, 'ID gerekli.', [], 422);
        }

        $sql = "SELECT `{$room['id']}` AS id, `{$room['name']}` AS name, "
            . ($room['description'] ? "`{$room['description']}` AS description, " : "'' AS description, ")
            . "`{$room['type']}` AS type, "
            . ($room['qualification_id'] ? "`{$room['qualification_id']}` AS qualification_id, " : "NULL AS qualification_id, ")
            . ($room['sort_order'] ? "`{$room['sort_order']}` AS sort_order, " : "0 AS sort_order, ")
            . "`{$room['is_active']}` AS is_active"
            . " FROM `{$room['table']}` WHERE `{$room['id']}` = ? LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            cr_json(false, 'Oda bulunamadı.', [], 404);
        }

        $row['is_system'] = in_array((string)$row['type'], ['general', 'qualification'], true) ? 1 : 0;
        $row['sort_order'] = (int)($row['sort_order'] ?? 0);
        $row['is_active'] = (int)($row['is_active'] ?? 0) === 1 ? 1 : 0;
        cr_json(true, '', ['room' => $row]);
    }

    if ($action === 'add_custom') {
        $name = sanitize_input($_POST['name'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($name === '') {
            cr_json(false, 'Oda adı zorunludur.', [], 422);
        }

        $cols = [$room['id'], $room['name'], $room['type'], $room['is_active']];
        $vals = [generate_uuid(), $name, 'custom', $isActive];
        if ($room['description']) {
            $cols[] = $room['description'];
            $vals[] = $description;
        }
        if ($room['sort_order']) {
            $cols[] = $room['sort_order'];
            $vals[] = $sortOrder;
        }
        if ($room['created_at']) {
            $cols[] = $room['created_at'];
            $vals[] = community_now();
        }
        if ($room['updated_at']) {
            $cols[] = $room['updated_at'];
            $vals[] = community_now();
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO `{$room['table']}` ({$quoted}) VALUES ({$holders})");
        $stmt->execute($vals);
        cr_json(true, 'Custom oda eklendi.');
    }

    if ($action === 'update') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            cr_json(false, 'ID gerekli.', [], 422);
        }

        $get = $pdo->prepare("SELECT `{$room['id']}` AS id, `{$room['type']}` AS type FROM `{$room['table']}` WHERE `{$room['id']}` = ? LIMIT 1");
        $get->execute([$id]);
        $exists = $get->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            cr_json(false, 'Oda bulunamadı.', [], 404);
        }

        $type = (string)$exists['type'];
        $isSystem = in_array($type, ['general', 'qualification'], true);

        $set = [];
        $vals = [];

        if (!$isSystem) {
            $name = sanitize_input($_POST['name'] ?? '');
            if ($name === '') {
                cr_json(false, 'Oda adı zorunludur.', [], 422);
            }
            $set[] = "`{$room['name']}` = ?";
            $vals[] = $name;
        }

        if ($room['description']) {
            $set[] = "`{$room['description']}` = ?";
            $vals[] = sanitize_input($_POST['description'] ?? '');
        }
        if ($room['sort_order']) {
            $set[] = "`{$room['sort_order']}` = ?";
            $vals[] = (int)($_POST['sort_order'] ?? 0);
        }
        $set[] = "`{$room['is_active']}` = ?";
        $vals[] = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($room['updated_at']) {
            $set[] = "`{$room['updated_at']}` = ?";
            $vals[] = community_now();
        }

        $vals[] = $id;
        $sql = "UPDATE `{$room['table']}` SET " . implode(', ', $set) . " WHERE `{$room['id']}` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        cr_json(true, 'Oda güncellendi.');
    }

    if ($action === 'get_rules') {
        $rules = community_get_rules_text($pdo, (string)($authUser['user_id'] ?? ''));
        cr_json(true, '', ['rules_text' => $rules]);
    }

    if ($action === 'save_rules') {
        $rulesText = trim((string)($_POST['rules_text'] ?? ''));
        community_save_rules_text($pdo, $rulesText, (string)($authUser['user_id'] ?? ''));
        cr_json(true, 'Topluluk kuralları kaydedildi.');
    }

    cr_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    cr_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}

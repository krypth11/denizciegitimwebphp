<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$authUser = require_admin();

function mm_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    $payload = array_merge(['success' => $success], $data);
    if ($message !== '') {
        $payload['message'] = $message;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mm_platform($value): string
{
    $platform = strtolower(trim((string)$value));
    if (!in_array($platform, ['app', 'portal'], true)) {
        mm_json(false, 'Geçersiz platform.', [], 422);
    }
    return $platform;
}

function mm_menu_exists(PDO $pdo, string $platform, string $menuKey): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_menu_visibility WHERE platform = ? AND menu_key = ?');
    $stmt->execute([$platform, $menuKey]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    if ($action === 'list') {
        $platform = mm_platform($_GET['platform'] ?? $_POST['platform'] ?? '');
        $sql = 'SELECT v.id, v.platform, v.menu_key, v.section, v.label, v.description, v.global_enabled, v.sort_order, v.is_core,
                       SUM(CASE WHEN v.global_enabled = 0 AND o.override_enabled = 1 THEN 1 ELSE 0 END) AS enabled_users_count,
                       SUM(CASE WHEN v.global_enabled = 1 AND o.override_enabled = 0 THEN 1 ELSE 0 END) AS disabled_users_count
                FROM app_menu_visibility v
                LEFT JOIN app_menu_user_overrides o ON o.platform = v.platform AND o.menu_key = v.menu_key
                WHERE v.platform = ?
                GROUP BY v.id, v.platform, v.menu_key, v.section, v.label, v.description, v.global_enabled, v.sort_order, v.is_core
                ORDER BY v.sort_order ASC, v.label ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$platform]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(static function (array $r) {
            return [
                'id' => $r['id'],
                'platform' => (string)$r['platform'],
                'menu_key' => (string)$r['menu_key'],
                'section' => (string)($r['section'] ?? ''),
                'label' => (string)($r['label'] ?? ''),
                'description' => (string)($r['description'] ?? ''),
                'global_enabled' => (int)$r['global_enabled'],
                'sort_order' => (int)$r['sort_order'],
                'is_core' => (int)$r['is_core'],
                'enabled_users_count' => (int)($r['enabled_users_count'] ?? 0),
                'disabled_users_count' => (int)($r['disabled_users_count'] ?? 0),
            ];
        }, $rows);

        mm_json(true, '', ['items' => $items]);
    }

    if ($action === 'save_item') {
        $platform = mm_platform($_POST['platform'] ?? '');
        $menuKey = trim((string)($_POST['menu_key'] ?? ''));
        if ($menuKey === '' || !mm_menu_exists($pdo, $platform, $menuKey)) {
            mm_json(false, 'Geçersiz menu_key.', [], 422);
        }

        $globalEnabled = in_array((string)($_POST['global_enabled'] ?? ''), ['1', 'true', 'on'], true) ? 1 : 0;
        $label = sanitize_input($_POST['label'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        $stmt = $pdo->prepare('UPDATE app_menu_visibility SET global_enabled = ?, label = ?, description = ?, sort_order = ? WHERE platform = ? AND menu_key = ?');
        $stmt->execute([$globalEnabled, $label, $description, $sortOrder, $platform, $menuKey]);
        mm_json(true, 'Menü güncellendi.');
    }

    if ($action === 'get_overrides') {
        $platform = mm_platform($_POST['platform'] ?? '');
        $menuKey = trim((string)($_POST['menu_key'] ?? ''));
        if ($menuKey === '' || !mm_menu_exists($pdo, $platform, $menuKey)) {
            mm_json(false, 'Geçersiz menu_key.', [], 422);
        }

        $baseStmt = $pdo->prepare('SELECT global_enabled FROM app_menu_visibility WHERE platform = ? AND menu_key = ? LIMIT 1');
        $baseStmt->execute([$platform, $menuKey]);
        $globalEnabled = (int)$baseStmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT o.user_id, o.override_enabled, u.full_name, u.email, s.is_pro
                               FROM app_menu_user_overrides o
                               LEFT JOIN user_profiles u ON u.id = o.user_id
                               LEFT JOIN user_subscription_status s ON s.user_id = o.user_id
                               WHERE o.platform = ? AND o.menu_key = ?
                               ORDER BY u.full_name ASC, u.email ASC');
        $stmt->execute([$platform, $menuKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $enabledUsers = [];
        $disabledUsers = [];
        foreach ($rows as $row) {
            $payload = [
                'id' => (string)$row['user_id'],
                'full_name' => (string)($row['full_name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'is_pro' => (int)($row['is_pro'] ?? 0),
            ];
            if ((int)$row['override_enabled'] === 1) $enabledUsers[] = $payload;
            if ((int)$row['override_enabled'] === 0) $disabledUsers[] = $payload;
        }

        mm_json(true, '', [
            'global_enabled' => $globalEnabled,
            'enabled_users' => $enabledUsers,
            'disabled_users' => $disabledUsers,
        ]);
    }

    if ($action === 'add_override') {
        $platform = mm_platform($_POST['platform'] ?? '');
        $menuKey = trim((string)($_POST['menu_key'] ?? ''));
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $overrideEnabled = in_array((string)($_POST['override_enabled'] ?? ''), ['1', 'true', 'on'], true) ? 1 : 0;

        if ($menuKey === '' || !mm_menu_exists($pdo, $platform, $menuKey)) {
            mm_json(false, 'Geçersiz menu_key.', [], 422);
        }
        if ($userId === '') {
            mm_json(false, 'user_id zorunludur.', [], 422);
        }

        $userCheck = $pdo->prepare('SELECT COUNT(*) FROM user_profiles WHERE id = ?');
        $userCheck->execute([$userId]);
        if ((int)$userCheck->fetchColumn() <= 0) {
            mm_json(false, 'Kullanıcı bulunamadı.', [], 422);
        }

        $stmt = $pdo->prepare('INSERT INTO app_menu_user_overrides (id, platform, menu_key, user_id, override_enabled, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                               ON DUPLICATE KEY UPDATE override_enabled = VALUES(override_enabled), updated_at = NOW()');
        $stmt->execute([generate_uuid(), $platform, $menuKey, $userId, $overrideEnabled]);
        mm_json(true, 'Kullanıcı istisnası kaydedildi.');
    }

    if ($action === 'remove_override') {
        $platform = mm_platform($_POST['platform'] ?? '');
        $menuKey = trim((string)($_POST['menu_key'] ?? ''));
        $userId = trim((string)($_POST['user_id'] ?? ''));

        if ($menuKey === '' || !mm_menu_exists($pdo, $platform, $menuKey)) {
            mm_json(false, 'Geçersiz menu_key.', [], 422);
        }
        if ($userId === '') {
            mm_json(false, 'user_id zorunludur.', [], 422);
        }

        $stmt = $pdo->prepare('DELETE FROM app_menu_user_overrides WHERE platform = ? AND menu_key = ? AND user_id = ?');
        $stmt->execute([$platform, $menuKey, $userId]);
        mm_json(true, 'Kullanıcı istisnası kaldırıldı.');
    }

    if ($action === 'search_users') {
        $q = trim((string)($_POST['q'] ?? ''));
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare('SELECT u.id, u.full_name, u.email, COALESCE(s.is_pro, 0) AS is_pro
                               FROM user_profiles u
                               LEFT JOIN user_subscription_status s ON s.user_id = u.id
                               WHERE (? = "" OR u.email LIKE ? OR u.full_name LIKE ?)
                               ORDER BY u.full_name ASC, u.email ASC
                               LIMIT 20');
        $stmt->execute([$q, $like, $like]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        mm_json(true, '', ['items' => $users]);
    }

    mm_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    mm_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

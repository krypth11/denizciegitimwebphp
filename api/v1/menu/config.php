<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

function menu_platform_or_fail($value): string
{
    $platform = strtolower(trim((string)$value));
    if (!in_array($platform, ['app', 'portal'], true)) {
        api_error('Geçersiz platform.', 422);
    }
    return $platform;
}

try {
    $platform = menu_platform_or_fail($_GET['platform'] ?? '');

    $auth = api_resolve_auth($pdo);
    if (!$auth) {
        $auth = api_resolve_web_auth($pdo);
    }
    $userId = $auth['user']['id'] ?? null;
    $userId = is_string($userId) ? trim($userId) : null;
    if ($userId === '') {
        $userId = null;
    }

    if ($userId === null) {
        $guestUserId = trim((string)($_GET['user_id'] ?? ''));
        if ($guestUserId !== '') {
            $guestStmt = $pdo->prepare('SELECT id FROM user_profiles WHERE id = ? LIMIT 1');
            $guestStmt->execute([$guestUserId]);
            if ($guestStmt->fetchColumn()) {
                $userId = $guestUserId;
            }
        }
    }

    $stmt = $pdo->prepare('SELECT menu_key, section, label, description, global_enabled, sort_order, is_core
                           FROM app_menu_visibility
                           WHERE platform = ?
                           ORDER BY sort_order ASC, label ASC');
    $stmt->execute([$platform]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $overrides = [];
    if ($userId !== null) {
        $ov = $pdo->prepare('SELECT menu_key, override_enabled FROM app_menu_user_overrides WHERE platform = ? AND user_id = ?');
        $ov->execute([$platform, $userId]);
        foreach (($ov->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $overrides[(string)$r['menu_key']] = (int)$r['override_enabled'];
        }
    }

    $menus = [];
    $items = [];
    foreach ($rows as $r) {
        $menuKey = (string)$r['menu_key'];
        $base = (int)$r['global_enabled'];
        $enabled = array_key_exists($menuKey, $overrides) ? (int)$overrides[$menuKey] : $base;
        $enabledBool = $enabled === 1;
        $menus[$menuKey] = $enabledBool;
        $items[] = [
            'menu_key' => $menuKey,
            'section' => (string)($r['section'] ?? ''),
            'label' => (string)($r['label'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'enabled' => $enabledBool,
            'sort_order' => (int)($r['sort_order'] ?? 0),
            'is_core' => (int)($r['is_core'] ?? 0),
        ];
    }

    api_send_json([
        'success' => true,
        'data' => [
            'platform' => $platform,
            'menus' => $menus,
            'items' => $items,
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

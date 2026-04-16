<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/stats_filters.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('Method not allowed.', 405);
}

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    $userId = (string)($auth['user']['id'] ?? '');
    if ($userId === '') {
        api_error('Kullanıcı doğrulanamadı.', 401);
    }

    if ($method === 'GET') {
        $loaded = dashboard_preferences_load($pdo, $userId);
        api_success('Dashboard tercihleri alındı.', [
            'preferences' => $loaded['preferences'],
            'meta' => $loaded['meta'],
        ]);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $saved = dashboard_preferences_save($pdo, $userId, $payload);
    api_success('Dashboard tercihleri kaydedildi.', [
        'preferences' => $saved['preferences'],
        'meta' => $saved['meta'],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

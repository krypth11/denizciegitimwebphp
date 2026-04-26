<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth_helper.php';
require_once dirname(__DIR__, 2) . '/response_helper.php';
require_once dirname(__DIR__, 4) . '/includes/news_helper.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    api_error('Method not allowed.', 405);
}

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    if ($method === 'GET') {
        $sources = news_list_sources($pdo);
        api_success('Kaynaklar getirildi.', [
            'sources' => $sources,
            'categories' => news_category_labels(),
        ]);
    }

    $payload = api_get_request_data();
    if (!is_array($payload)) {
        $payload = [];
    }

    $action = strtolower(trim((string)($payload['action'] ?? 'create')));

    if ($action === 'create') {
        $created = news_create_source($pdo, $payload);
        api_success('Kaynak eklendi.', ['source' => $created]);
    }

    if ($action === 'update') {
        $id = trim((string)($payload['id'] ?? ''));
        if ($id === '') {
            api_error('id zorunludur.', 422);
        }
        $updated = news_update_source($pdo, $id, $payload);
        api_success('Kaynak güncellendi.', ['source' => $updated]);
    }

    if ($action === 'toggle') {
        $id = trim((string)($payload['id'] ?? ''));
        if ($id === '') {
            api_error('id zorunludur.', 422);
        }

        $source = news_get_source($pdo, $id);
        if (!$source) {
            api_error('Kaynak bulunamadı.', 404);
        }

        $nextActive = ((int)($source['is_active'] ?? 0) === 1) ? 0 : 1;
        $updated = news_update_source($pdo, $id, ['is_active' => $nextActive]);
        api_success($nextActive === 1 ? 'Kaynak aktif edildi.' : 'Kaynak pasif edildi.', ['source' => $updated]);
    }

    if ($action === 'delete') {
        $id = trim((string)($payload['id'] ?? ''));
        if ($id === '') {
            api_error('id zorunludur.', 422);
        }
        news_delete_source($pdo, $id);
        api_success('Kaynak silindi.');
    }

    api_error('Geçersiz action.', 422);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 404);
} catch (Throwable $e) {
    error_log('[admin.news.sources] ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

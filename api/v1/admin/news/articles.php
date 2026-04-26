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
        $status = strtolower(trim((string)($_GET['status'] ?? 'pending')));
        $articles = news_list_admin_articles($pdo, $status);
        api_success('Haberler getirildi.', [
            'articles' => $articles,
            'status' => in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending',
            'categories' => news_category_labels(),
        ]);
    }

    $payload = api_get_request_data();
    if (!is_array($payload)) {
        $payload = [];
    }

    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $id = trim((string)($payload['id'] ?? ''));

    if ($action === '') {
        api_error('action zorunludur.', 422);
    }

    if (!in_array($action, ['approve', 'reject', 'delete', 'update', 'fetch_now', 'bulk_delete'], true)) {
        api_error('Geçersiz action.', 422);
    }

    if ($action === 'fetch_now') {
        $summary = news_fetch_all_active_sources($pdo);
        api_success('Haber çekme tamamlandı.', [
            'summary' => $summary,
        ]);
    }

    if ($action === 'bulk_delete') {
        $ids = $payload['ids'] ?? null;
        if (!is_array($ids)) {
            api_error('ids alanı dizi olmalıdır.', 422);
        }

        $cleanIds = array_values(array_filter(array_map(static function ($id): string {
            return trim((string)$id);
        }, $ids), static function (string $id): bool {
            return $id !== '';
        }));

        if (!$cleanIds) {
            api_error('Silinecek haber seçilmedi.', 422);
        }

        $deletedCount = news_bulk_delete_articles($pdo, $cleanIds);
        api_success('Seçili haberler silindi.', [
            'deleted_count' => $deletedCount,
        ]);
    }

    if ($id === '') {
        api_error('id zorunludur.', 422);
    }

    if ($action === 'approve') {
        $article = news_update_article_status($pdo, $id, 'approved');
        api_success('Haber onaylandı.', ['article' => $article]);
    }

    if ($action === 'reject') {
        $article = news_update_article_status($pdo, $id, 'rejected');
        api_success('Haber reddedildi.', ['article' => $article]);
    }

    if ($action === 'delete') {
        news_delete_article($pdo, $id);
        api_success('Haber silindi.');
    }

    $updateInput = [];
    foreach (['title', 'summary', 'source_name', 'source_url', 'image_url', 'category', 'published_at'] as $key) {
        if (array_key_exists($key, $payload)) {
            $updateInput[$key] = $payload[$key];
        }
    }

    $article = news_update_article($pdo, $id, $updateInput);
    api_success('Haber güncellendi.', ['article' => $article]);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 404);
} catch (Throwable $e) {
    error_log('[admin.news.articles] ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

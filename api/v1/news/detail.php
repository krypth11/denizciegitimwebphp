<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/news_helper.php';

api_require_method('GET');

try {
    $id = api_require_query_param('id', 191);
    $article = news_get_mobile_article($pdo, $id);

    if (!$article) {
        api_error('Haber bulunamadı.', 404);
    }

    api_success('Haber detayı getirildi.', [
        'article' => $article,
    ]);
} catch (Throwable $e) {
    error_log('[news.detail] ' . $e->getMessage());
    api_error('Haber detayı alınamadı.', 500);
}

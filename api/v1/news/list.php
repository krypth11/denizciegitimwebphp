<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/news_helper.php';

api_require_method('GET');

try {
    $page = api_get_int_query('page', 1, 1, 100000);
    $limit = api_get_int_query('limit', 20, 1, 50);
    $categoryRaw = trim((string)($_GET['category'] ?? ''));
    $region = strtolower(trim((string)($_GET['region'] ?? '')));

    $filters = [
        'page' => $page,
        'limit' => $limit,
    ];

    if ($categoryRaw !== '') {
        $filters['category'] = news_normalize_category($categoryRaw);
    }

    if ($region === 'local' || $region === 'global') {
        $filters['region'] = $region;
    }

    $result = news_list_mobile_articles($pdo, $filters);

    api_success('Haberler getirildi.', $result);
} catch (Throwable $e) {
    error_log('[news.list] ' . $e->getMessage());
    api_error('Haberler alınamadı.', 500);
}

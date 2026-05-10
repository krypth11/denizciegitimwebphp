<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $categoryId = api_require_query_param('category_id');
    if (!kg_get_category($pdo, $categoryId)) api_error('Kategori bulunamadı.', 404);

    api_send_json([
        'success' => true,
        'data' => kg_get_progress_summary($pdo, $userId, $categoryId),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

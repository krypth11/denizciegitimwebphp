<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $categoryId = api_require_query_param('category_id');
    if (!kg_get_category($pdo, $categoryId)) api_error('Kategori bulunamadı.', 404);
    $isPremium = usage_limits_is_user_pro($pdo, $userId);
    $dailyUsage = kg_get_daily_usage_status($pdo, $userId, $isPremium);

    $summary = kg_get_progress_summary($pdo, $userId, $categoryId);
    $summary['daily_attempt'] = kg_get_daily_attempt_status($pdo, $userId, $categoryId);
    $summary['daily_usage'] = [
        'practice' => $dailyUsage['practice'],
        'ranked' => $dailyUsage['ranked'],
        'is_premium' => $isPremium,
    ];

    api_send_json([
        'success' => true,
        'data' => $summary,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

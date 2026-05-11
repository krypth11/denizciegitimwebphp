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

    $seasonRaw = kg_get_active_leaderboard_season($pdo, $categoryId);
    $season = null;
    $rewards = [];

    if ($seasonRaw) {
        $season = [
            'id' => (string)($seasonRaw['id'] ?? ''),
            'title' => (string)($seasonRaw['title'] ?? ''),
            'reset_at' => (string)($seasonRaw['reset_at'] ?? ''),
        ];

        $rewardRows = kg_get_leaderboard_rewards($pdo, (string)$seasonRaw['id']);
        foreach ($rewardRows as $row) {
            $rewards[] = [
                'id' => (string)($row['id'] ?? ''),
                'rank_start' => (int)($row['rank_start'] ?? 0),
                'rank_end' => (int)($row['rank_end'] ?? 0),
                'reward_title' => (string)($row['reward_title'] ?? ''),
                'reward_description' => (string)($row['reward_description'] ?? ''),
            ];
        }
    }

    api_send_json([
        'success' => true,
        'data' => [
            'items' => kg_get_leaderboard($pdo, $categoryId, 100),
            'my_entry' => kg_get_leaderboard_entry($pdo, $categoryId, $userId),
            'season' => $season,
            'rewards' => $rewards,
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

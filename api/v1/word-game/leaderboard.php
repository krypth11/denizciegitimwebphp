<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/word_game_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $qualificationId = word_game_get_current_qualification_id($pdo, $userId);

    if (!$qualificationId) {
        api_send_json([
            'success' => false,
            'message' => 'Current qualification bulunamadı. Önce yeterlilik seçmelisiniz.',
            'data' => null,
        ], 403);
    }

    $limit = api_get_int_query('limit', 50, 1, 100);
    $items = word_game_get_leaderboard($pdo, $qualificationId, $limit);

    api_send_json([
        'success' => true,
        'data' => [
            'qualification_id' => $qualificationId,
            'items' => $items,
        ],
    ]);
} catch (Throwable $e) {
    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/leaderboard',
        'message' => $e->getMessage(),
    ]);

    api_send_json([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
    ], 422);
}

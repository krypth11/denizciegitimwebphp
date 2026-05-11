<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');

    if (api_is_guest_user($pdo, $userId)) {
        api_send_json([
            'success' => false,
            'error_code' => 'AUTH_REQUIRED',
            'message' => 'Kart oyununu oynamak için kayıt olmalısın.',
        ], 401);
    }

    $payload = api_get_request_data();
    if (!is_array($payload)) {
        $payload = [];
    }

    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $gameMode = strtolower(trim((string)($payload['game_mode'] ?? '')));
    $usedRewarded = (bool)($payload['used_rewarded'] ?? false);
    $allowedGameModes = ['quick', 'long', 'endless', 'daily'];

    if ($categoryId === '') {
        api_error('category_id zorunludur.', 422);
    }
    if (!in_array($gameMode, $allowedGameModes, true)) {
        api_error('Geçersiz game_mode.', 422);
    }
    if (!kg_get_category($pdo, $categoryId)) {
        api_error('Kategori bulunamadı.', 404);
    }

    $isPremium = usage_limits_is_user_pro($pdo, $userId);
    $consume = kg_consume_start_run_right($pdo, $userId, $gameMode, $isPremium, $usedRewarded);

    if (empty($consume['success'])) {
        $errorCode = (string)($consume['error_code'] ?? 'LIMIT_REACHED');
        $status = in_array($errorCode, ['VALIDATION_GAME_MODE_INVALID'], true) ? 422 : 429;
        api_send_json([
            'success' => false,
            'error_code' => $errorCode,
            'message' => (string)($consume['message'] ?? 'Oyun hakkın doldu.'),
        ], $status);
    }

    $response = [
        'granted' => true,
        'unlimited' => !empty($consume['unlimited']),
    ];

    if ($gameMode === 'daily') {
        // Ranked (daily): premium kullanıcıda reklam akışı yoktur.
        $response['used_rewarded'] = $isPremium ? false : !empty($consume['used_rewarded']);
        $response['remaining_total'] = (int)($consume['remaining_total'] ?? 0);
    } else {
        $response['remaining'] = (int)($consume['remaining'] ?? 0);
    }

    api_send_json([
        'success' => true,
        'data' => $response,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

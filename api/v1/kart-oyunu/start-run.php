<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';
require_once __DIR__ . '/secure_run_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if (api_is_guest_user($pdo, $userId)) {
        api_send_json(['success' => false, 'error_code' => 'AUTH_REQUIRED', 'message' => 'Kart oyununu oynamak için kayıt olmalısın.'], 401);
    }
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'kart-oyunu.start-run');
    $payload = api_get_request_data();
    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $gameMode = strtolower(trim((string)($payload['game_mode'] ?? '')));
    if ($categoryId === '' || !in_array($gameMode, ['quick', 'long', 'endless', 'daily'], true)) {
        api_error('Geçersiz kategori veya oyun modu.', 422);
    }
    if (!empty($payload['used_rewarded'])) {
        api_send_json(['success' => false, 'error_code' => 'REWARDED_VERIFICATION_UNAVAILABLE', 'message' => 'Ödüllü reklam özelliği şu anda kullanılamıyor.'], 503);
    }
    if (!kg_get_category($pdo, $categoryId)) api_error('Kategori bulunamadı.', 404);

    $isPremium = usage_limits_is_user_pro($pdo, $userId);
    $pdo->beginTransaction();
    $consume = kg_consume_start_run_right($pdo, $userId, $gameMode, $isPremium, false);
    if (empty($consume['success'])) {
        $pdo->rollBack();
        api_send_json([
            'success' => false,
            'error_code' => (string)($consume['error_code'] ?? 'LIMIT_REACHED'),
            'message' => (string)($consume['message'] ?? 'Oyun hakkın doldu.'),
            'daily_usage' => $consume['daily_usage'] ?? [],
        ], 429);
    }
    $secureRun = kg_secure_run_create($pdo, $userId, $qualificationId, $categoryId, $gameMode);
    $pdo->commit();

    api_send_json(['success' => true, 'data' => array_merge($secureRun, [
        'granted' => true,
        'is_premium' => $isPremium,
        'daily_usage' => $consume['daily_usage'] ?? kg_get_daily_usage_status($pdo, $userId, $isPremium),
    ])]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = (int)$e->getCode();
    api_error($status >= 400 && $status < 500 ? $e->getMessage() : 'İşlem sırasında bir sunucu hatası oluştu.', $status >= 400 && $status < 500 ? $status : 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $payload = api_get_request_data();

    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $gameMode = strtolower(trim((string)($payload['game_mode'] ?? 'normal')));
    if ($categoryId === '' || !in_array($gameMode, ['normal', 'endless'], true)) {
        api_error('category_id ve geçerli game_mode zorunludur.', 422);
    }

    $category = kg_get_category($pdo, $categoryId);
    if (!$category) api_error('Kategori bulunamadı.', 404);

    $fields = ['score'=>1000000,'total_questions'=>500,'correct_count'=>500,'wrong_count'=>500,'max_combo'=>500,'duration_seconds'=>7200];
    $run = ['game_mode' => $gameMode];
    foreach ($fields as $k => $max) {
        $v = filter_var($payload[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < 0 || $v > $max) api_error('Geçersiz alan: ' . $k, 422);
        $run[$k] = (int)$v;
    }
    if (($run['correct_count'] + $run['wrong_count']) > $run['total_questions']) {
        api_error('correct+wrong toplam_questions değerini aşamaz.', 422);
    }

    $xpRule = kg_get_xp_rule($pdo, $categoryId);
    if (!$xpRule) {
        kg_create_default_xp_rule($pdo, $categoryId);
        $xpRule = kg_get_xp_rule($pdo, $categoryId);
    }
    if (!$xpRule) api_error('XP kuralı bulunamadı.', 500);

    $earnedXp = kg_calculate_earned_xp($run, $xpRule);

    $pdo->beginTransaction();
    try {
        $progress = kg_update_user_progress_after_run($pdo, $userId, $categoryId, $run, $earnedXp);
        $lvl = kg_resolve_level_from_total_xp($pdo, (int)$progress['total_xp']);
        kg_save_run($pdo, $userId, $categoryId, $run, $earnedXp, (int)$progress['total_xp'], (int)$lvl['current_level']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    api_send_json([
        'success' => true,
        'data' => [
            'earned_xp' => $earnedXp,
            'total_xp' => (int)$progress['total_xp'],
            'current_level' => (int)$lvl['current_level'],
            'best_score' => (int)$progress['best_score'],
            'best_combo' => (int)$progress['best_combo'],
            'total_games' => (int)$progress['total_games'],
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

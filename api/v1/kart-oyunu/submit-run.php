<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';

api_require_method('POST');

function kg_submit_error(string $message, string $errorCode, int $status = 422): void
{
    api_send_json([
        'success' => false,
        'message' => $message,
        'error_code' => $errorCode,
    ], $status);
}

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

    // Legacy client compatibility: mode -> game_mode
    if (!isset($payload['game_mode']) && isset($payload['mode'])) {
        $payload['game_mode'] = $payload['mode'];
    }

    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $gameMode = strtolower(trim((string)($payload['game_mode'] ?? '')));
    $allowedGameModes = ['quick', 'long', 'endless', 'daily'];

    if ($categoryId === '') {
        kg_submit_error('category_id zorunludur.', 'VALIDATION_CATEGORY_ID_REQUIRED', 422);
    }
    if ($gameMode === '') {
        kg_submit_error('game_mode zorunludur.', 'VALIDATION_GAME_MODE_REQUIRED', 422);
    }
    if (!in_array($gameMode, $allowedGameModes, true)) {
        kg_submit_error('Geçersiz game_mode.', 'VALIDATION_GAME_MODE_INVALID', 422);
    }

    $category = kg_get_category($pdo, $categoryId);
    if (!$category) {
        kg_submit_error('Kategori bulunamadı.', 'CATEGORY_NOT_FOUND', 404);
    }

    $fields = [
        'score' => 1000000,
        'total_questions' => 500,
        'correct_count' => 500,
        'wrong_count' => 500,
        'max_combo' => 500,
        'duration_seconds' => 7200,
    ];
    $run = ['game_mode' => $gameMode];
    foreach ($fields as $k => $max) {
        if (!array_key_exists($k, $payload)) {
            kg_submit_error($k . ' zorunludur.', 'VALIDATION_' . strtoupper($k) . '_REQUIRED', 422);
        }
        $v = filter_var($payload[$k], FILTER_VALIDATE_INT);
        if ($v === false || $v < 0 || $v > $max) {
            kg_submit_error('Geçersiz alan: ' . $k, 'VALIDATION_' . strtoupper($k) . '_INVALID', 422);
        }
        $run[$k] = (int)$v;
    }

    if (($run['correct_count'] + $run['wrong_count']) > $run['total_questions']) {
        kg_submit_error('correct_count + wrong_count total_questions değerini aşamaz.', 'VALIDATION_COUNTS_INCONSISTENT', 422);
    }

    if ($gameMode !== 'daily') {
        $progress = kg_get_progress_summary($pdo, $userId, $categoryId);
        $lvl = kg_resolve_level_from_total_xp($pdo, (int)$progress['total_xp']);

        $pdo->beginTransaction();
        try {
            kg_save_run(
                $pdo,
                $userId,
                $categoryId,
                $run,
                0,
                (int)$progress['total_xp'],
                (int)$lvl['current_level']
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        api_send_json([
            'success' => true,
            'data' => [
                'earned_xp' => 0,
                'total_xp' => (int)$progress['total_xp'],
                'level' => (int)$lvl['current_level'],
                'current_level' => (int)$lvl['current_level'],
                'next_level_xp' => $lvl['next_level_xp'],
                'progress_percent' => (int)$progress['progress_percent'],
                'level_up' => false,
                'is_practice' => true,
                'leaderboard_eligible' => false,
                'message' => 'Bu mod pratik içindir. XP ve leaderboard puanı vermez.',
            ],
            'earned_xp' => 0,
            'total_xp' => (int)$progress['total_xp'],
            'level' => (int)$lvl['current_level'],
            'current_level' => (int)$lvl['current_level'],
            'next_level_xp' => $lvl['next_level_xp'],
            'progress_percent' => (int)$progress['progress_percent'],
            'level_up' => false,
        ]);
    }

    $xpRule = kg_get_xp_rule($pdo, $categoryId);
    if (!$xpRule) {
        kg_create_default_xp_rule($pdo, $categoryId);
        $xpRule = kg_get_xp_rule($pdo, $categoryId);
    }
    if (!$xpRule) {
        $xpRule = kg_default_xp_rule_payload();
    }

    $earnedXp = max(0, (int)kg_calculate_earned_xp($run, $xpRule));

    $pdo->beginTransaction();
    try {
        $progress = kg_update_user_progress_after_run($pdo, $userId, $categoryId, $run, $earnedXp);
        $beforeLevel = kg_resolve_level_from_total_xp($pdo, max(0, (int)$progress['total_xp'] - $earnedXp));
        $lvl = kg_resolve_level_from_total_xp($pdo, (int)$progress['total_xp']);
        $nextLevelXp = $lvl['next_level_xp'];
        $currentLevelXpFloor = (int)($lvl['current_level_xp'] ?? 0);
        $progressPercent = $nextLevelXp === null
            ? 100
            : (int)max(0, min(100, floor((((int)$progress['total_xp'] - $currentLevelXpFloor) / max(1, ((int)$nextLevelXp - $currentLevelXpFloor))) * 100)));
        $levelUp = ((int)$lvl['current_level'] > (int)$beforeLevel['current_level']);

        kg_save_run(
            $pdo,
            $userId,
            $categoryId,
            $run,
            $earnedXp,
            (int)$progress['total_xp'],
            (int)$lvl['current_level']
        );

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
            'level' => (int)$lvl['current_level'],
            'current_level' => (int)$lvl['current_level'],
            'next_level_xp' => $nextLevelXp,
            'progress_percent' => $progressPercent,
            'level_up' => $levelUp,
            'is_practice' => false,
            'leaderboard_eligible' => true,
        ],
        'earned_xp' => $earnedXp,
        'total_xp' => (int)$progress['total_xp'],
        'level' => (int)$lvl['current_level'],
        'current_level' => (int)$lvl['current_level'],
        'next_level_xp' => $nextLevelXp,
        'progress_percent' => $progressPercent,
        'level_up' => $levelUp,
    ]);
} catch (Throwable $e) {
    kg_submit_error('İşlem sırasında bir sunucu hatası oluştu.', 'INTERNAL_SERVER_ERROR', 500);
}

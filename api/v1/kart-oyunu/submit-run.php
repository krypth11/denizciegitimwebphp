<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';
require_once __DIR__ . '/secure_run_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if (api_is_guest_user($pdo, $userId)) api_error('Kayıtlı kullanıcı gerekli.', 401);
    $payload = api_get_request_data();
    $runId = trim((string)($payload['run_id'] ?? ''));
    if ($runId === '') api_error('run_id zorunludur.', 422);
    foreach (['score', 'correct_count', 'wrong_count', 'max_combo', 'earned_xp'] as $forbidden) {
        if (array_key_exists($forbidden, $payload)) api_error('İstemci tarafından hesaplanan sonuç alanları kabul edilmez.', 422);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM kart_game_secure_runs WHERE id = ? AND user_id = ? FOR UPDATE');
    $stmt->execute([$runId, $userId]);
    $secureRun = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$secureRun) throw new RuntimeException('Oyun turu bulunamadı.', 404);
    if ((string)$secureRun['status'] !== 'active') throw new RuntimeException('Bu oyun turu daha önce tamamlanmış.', 409);
    if ((strtotime((string)$secureRun['expires_at']) ?: 0) <= time()) {
        $pdo->prepare('UPDATE kart_game_secure_runs SET status = ?, updated_at = NOW() WHERE id = ?')->execute(['expired', $runId]);
        throw new RuntimeException('Oyun turunun süresi dolmuş.', 410);
    }

    $rounds = kg_secure_run_load_json_array($secureRun, 'rounds_json');
    $answers = kg_secure_run_load_json_array($secureRun, 'answers_json');
    $run = kg_secure_run_score($rounds, $answers);
    $run['game_mode'] = (string)$secureRun['game_mode'];
    $run['duration_seconds'] = max(0, time() - (strtotime((string)$secureRun['started_at']) ?: time()));
    $isPractice = $run['game_mode'] !== 'daily';
    $progress = kg_get_progress_summary($pdo, $userId, (string)$secureRun['category_id']);
    $earnedXp = 0;

    if (!$isPractice) {
        $xpRule = kg_get_xp_rule($pdo, (string)$secureRun['category_id']) ?: kg_default_xp_rule_payload();
        $earnedXp = max(0, (int)kg_calculate_earned_xp($run, $xpRule));
        $progress = kg_update_user_progress_after_run($pdo, $userId, (string)$secureRun['category_id'], $run, $earnedXp);
    }
    $level = kg_resolve_level_from_total_xp($pdo, (int)$progress['total_xp']);
    kg_save_run($pdo, $userId, (string)$secureRun['category_id'], $run, $earnedXp, (int)$progress['total_xp'], (int)$level['current_level']);
    $done = $pdo->prepare('UPDATE kart_game_secure_runs SET status = ?, completed_at = NOW(), result_json = ?, updated_at = NOW() WHERE id = ? AND status = ?');
    $done->execute(['completed', json_encode($run, JSON_UNESCAPED_UNICODE), $runId, 'active']);
    if ($done->rowCount() !== 1) throw new RuntimeException('Oyun turu eşzamanlı olarak tamamlanmış.', 409);
    $pdo->commit();

    api_send_json(['success' => true, 'data' => [
        'earned_xp' => $earnedXp,
        'total_xp' => (int)$progress['total_xp'],
        'level' => (int)$level['current_level'],
        'current_level' => (int)$level['current_level'],
        'current_level_xp' => (int)($level['current_level_xp'] ?? 0),
        'next_level_xp' => $level['next_level_xp'],
        'level_up' => false,
        'is_practice' => $isPractice,
        'leaderboard_eligible' => !$isPractice,
        'score' => $run['score'],
        'correct_count' => $run['correct_count'],
        'wrong_count' => $run['wrong_count'],
        'max_combo' => $run['max_combo'],
    ]]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = (int)$e->getCode();
    api_error($status >= 400 && $status < 500 ? $e->getMessage() : 'İşlem sırasında bir sunucu hatası oluştu.', $status >= 400 && $status < 500 ? $status : 500);
}

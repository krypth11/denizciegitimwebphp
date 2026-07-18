<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once __DIR__ . '/secure_run_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if (api_is_guest_user($pdo, $userId)) api_error('Kayıtlı kullanıcı gerekli.', 401);

    $payload = api_get_request_data();
    $runId = trim((string)($payload['run_id'] ?? ''));
    $roundId = trim((string)($payload['round_id'] ?? ''));
    $answer = $payload['answer'] ?? null;
    if ($runId === '' || $roundId === '' || !is_bool($answer)) {
        api_error('run_id, round_id ve boolean answer zorunludur.', 422);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM kart_game_secure_runs WHERE id = ? AND user_id = ? FOR UPDATE');
    $stmt->execute([$runId, $userId]);
    $run = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$run) throw new RuntimeException('Oyun turu bulunamadı.', 404);
    if ((string)$run['status'] !== 'active') throw new RuntimeException('Bu oyun turu artık aktif değil.', 409);
    if ((strtotime((string)$run['expires_at']) ?: 0) <= time()) {
        $pdo->prepare('UPDATE kart_game_secure_runs SET status = ?, updated_at = NOW() WHERE id = ?')->execute(['expired', $runId]);
        throw new RuntimeException('Oyun turunun süresi dolmuş.', 410);
    }

    $rounds = kg_secure_run_load_json_array($run, 'rounds_json');
    $answers = kg_secure_run_load_json_array($run, 'answers_json');
    $index = count($answers);
    if ($index >= count($rounds)) throw new RuntimeException('Tüm sorular daha önce cevaplanmış.', 409);
    $round = $rounds[$index];
    if (!hash_equals((string)($round['round_id'] ?? ''), $roundId)) {
        throw new RuntimeException('Cevap sırası oyun turuyla uyuşmuyor.', 409);
    }

    $isCorrect = $answer === (bool)($round['expected_answer'] ?? false);
    $answers[] = ['round_id' => $roundId, 'answer' => $answer];
    $update = $pdo->prepare('UPDATE kart_game_secure_runs SET answers_json = ?, updated_at = NOW() WHERE id = ? AND status = ?');
    $update->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), $runId, 'active']);
    if ($update->rowCount() !== 1) throw new RuntimeException('Cevap kaydedilemedi.', 409);
    $pdo->commit();

    api_send_json(['success' => true, 'data' => [
        'is_correct' => $isCorrect,
        'correct_text' => (string)($round['correct_text'] ?? ''),
        'answered_count' => count($answers),
        'total_questions' => count($rounds),
    ]]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = (int)$e->getCode();
    api_error($status >= 400 && $status < 500 ? $e->getMessage() : 'İşlem sırasında bir sunucu hatası oluştu.', $status >= 400 && $status < 500 ? $status : 500);
}

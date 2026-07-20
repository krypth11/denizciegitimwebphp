<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once __DIR__ . '/maritime_english_learning_helper.php';
api_require_method('POST');
try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $payload = api_get_request_data();
    $sessionId = trim((string)($payload['session_id'] ?? ''));
    $itemId = trim((string)($payload['session_item_id'] ?? ''));
    $answerKey = strtoupper(trim((string)($payload['answer_key'] ?? '')));
    $responseMs = max(0, min(600000, (int)($payload['response_ms'] ?? 0)));
    if ($sessionId === '' || $itemId === '' || !preg_match('/^[A-Z0-9]{1,8}$/', $answerKey)) api_error('Geçersiz cevap isteği.', 422);

    $pdo->beginTransaction();
    $session = me_load_session($pdo, $sessionId, $userId, true);
    if (!$session) throw new RuntimeException('Oturum bulunamadı.', 404);
    if ((string)$session['status'] !== 'active') throw new RuntimeException('Bu oturum aktif değil.', 409);
    if ((strtotime((string)$session['expires_at']) ?: 0) <= time()) {
        $pdo->prepare("UPDATE maritime_english_sessions SET status = 'expired', updated_at = NOW() WHERE id = ?")->execute([$sessionId]);
        throw new RuntimeException('Oturumun süresi dolmuş.', 410);
    }
    $stmt = $pdo->prepare('SELECT * FROM maritime_english_session_items WHERE id = ? AND session_id = ? FOR UPDATE');
    $stmt->execute([$itemId, $sessionId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('Soru bulunamadı.', 404);
    if ($item['answered_at'] !== null) throw new RuntimeException('Bu soru daha önce cevaplanmış.', 409);
    if ((int)$item['position'] !== ((int)$session['answered_count'] + 1)) throw new RuntimeException('Sorular sırayla cevaplanmalıdır.', 409);
    $options = me_decode_options((string)$item['options_snapshot_json']);
    if (!array_key_exists($answerKey, $options)) throw new RuntimeException('Seçilen cevap bu soruya ait değil.', 422);
    $correctKey = strtoupper((string)$item['correct_option_key']);
    $isCorrect = hash_equals($correctKey, $answerKey);
    $update = $pdo->prepare('UPDATE maritime_english_session_items SET answer_key = ?, is_correct = ?, response_ms = ?, answered_at = NOW() WHERE id = ? AND answered_at IS NULL');
    $update->execute([$answerKey, $isCorrect ? 1 : 0, $responseMs, $itemId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Cevap kaydedilemedi.', 409);
    me_update_term_progress($pdo, $userId, (string)$item['term_id'], $isCorrect);
    $answered = (int)$session['answered_count'] + 1;
    $completed = $answered >= (int)$session['question_count'];
    $pdo->prepare("UPDATE maritime_english_sessions SET answered_count = ?, correct_count = correct_count + ?, wrong_count = wrong_count + ?, status = ?, completed_at = IF(?, NOW(), completed_at), updated_at = NOW() WHERE id = ? AND status = 'active'")
        ->execute([$answered, $isCorrect ? 1 : 0, $isCorrect ? 0 : 1, $completed ? 'completed' : 'active', $completed ? 1 : 0, $sessionId]);
    $termStmt = $pdo->prepare('SELECT term_en, term_tr, short_explanation FROM maritime_english_terms WHERE id = ?');
    $termStmt->execute([(string)$item['term_id']]);
    $term = $termStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $data = [
        'is_correct' => $isCorrect, 'correct_option_key' => $correctKey,
        'correct_option_text' => (string)($options[$correctKey] ?? ''),
        'term_en' => (string)($term['term_en'] ?? ''), 'term_tr' => (string)($term['term_tr'] ?? ''),
        'explanation' => (string)($term['short_explanation'] ?: (($term['term_en'] ?? '') . ': ' . ($term['term_tr'] ?? ''))),
        'answered_count' => $answered, 'question_count' => (int)$session['question_count'], 'completed' => $completed,
    ];
    if ($completed) $data['result'] = me_result_payload($pdo, $sessionId, $userId);
    else $data['next_question'] = me_session_payload($pdo, $sessionId, $userId)['next_question'];
    $pdo->commit();
    api_send_json(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = (int)$e->getCode();
    api_error($status >= 400 && $status < 500 ? $e->getMessage() : 'Cevap kaydedilemedi.', $status >= 400 && $status < 500 ? $status : 500);
}

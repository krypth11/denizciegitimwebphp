<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/word_game_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $currentQualificationId = word_game_get_current_qualification_id($pdo, $userId);

    if (!$currentQualificationId) {
        api_send_json([
            'success' => false,
            'message' => 'Current qualification bulunamadı. Önce yeterlilik seçmelisiniz.',
            'data' => null,
        ], 403);
    }

    $payload = api_get_request_data();
    $sessionId = trim((string)($payload['session_id'] ?? ''));
    $remainingSeconds = filter_var($payload['remaining_seconds'] ?? null, FILTER_VALIDATE_INT);
    $status = strtolower(trim((string)($payload['status'] ?? '')));

    word_game_debug_log('reveal/check/finish session ids', [
        'endpoint' => 'word-game/finish',
        'session_id' => $sessionId,
    ]);

    if ($sessionId === '' || $remainingSeconds === false || $status === '') {
        api_send_json([
            'success' => false,
            'message' => 'session_id, remaining_seconds ve status zorunludur.',
            'data' => null,
        ], 422);
    }

    if (!in_array($status, ['completed', 'abandoned', 'timeout'], true)) {
        api_send_json([
            'success' => false,
            'message' => 'status sadece completed, abandoned, timeout olabilir.',
            'data' => null,
        ], 422);
    }

    $session = word_game_find_session($pdo, $sessionId, $userId);
    if (!$session) {
        api_send_json(['success' => false, 'message' => 'Oturum bulunamadı.', 'data' => null], 404);
    }

    if ((string)($session['qualification_id'] ?? '') !== $currentQualificationId) {
        api_send_json(['success' => false, 'message' => 'Bu yeterlilik için erişim yetkiniz yok.', 'data' => null], 403);
    }

    if ((string)($session['status'] ?? '') !== 'active') {
        api_send_json(['success' => false, 'message' => 'Bu oturum zaten tamamlanmış.', 'data' => null], 422);
    }

    $result = word_game_finish_session($pdo, $sessionId, $userId, (int)$remainingSeconds, $status);

    api_send_json([
        'success' => true,
        'data' => $result,
    ]);
} catch (Throwable $e) {
    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/finish',
        'message' => $e->getMessage(),
    ]);

    api_send_json([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
    ], 422);
}

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
    $sessionQuestionId = trim((string)($payload['session_question_id'] ?? ''));
    $submittedAnswer = (string)($payload['submitted_answer'] ?? '');

    word_game_debug_log('reveal/check/finish session ids', [
        'endpoint' => 'word-game/check-answer',
        'session_id' => $sessionId,
        'session_question_id' => $sessionQuestionId,
    ]);

    if ($sessionId === '' || $sessionQuestionId === '' || trim($submittedAnswer) === '') {
        api_send_json([
            'success' => false,
            'message' => 'session_id, session_question_id ve submitted_answer zorunludur.',
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
        api_send_json(['success' => false, 'message' => 'Sadece aktif oturumda işlem yapılabilir.', 'data' => null], 422);
    }

    $sessionQuestion = word_game_find_session_question($pdo, $sessionQuestionId, $sessionId);
    if (!$sessionQuestion) {
        api_send_json(['success' => false, 'message' => 'Oturum sorusu bulunamadı.', 'data' => null], 404);
    }

    if ((int)($sessionQuestion['is_completed'] ?? 0) === 1) {
        api_send_json(['success' => false, 'message' => 'Bu soru zaten tamamlandı.', 'data' => null], 422);
    }

    $result = word_game_check_answer($pdo, $sessionQuestion, $submittedAnswer);
    word_game_refresh_session_totals($pdo, $sessionId);

    $response = [
        'is_correct' => (bool)$result['is_correct'],
        'wrong_attempt_count' => (int)$result['wrong_attempt_count'],
        'question_completed' => (bool)$result['question_completed'],
    ];

    if ((bool)$result['is_correct']) {
        $response['earned_score'] = (int)$result['earned_score'];
    } else {
        $response['remaining_attempts'] = (int)$result['remaining_attempts'];
    }

    api_send_json([
        'success' => true,
        'data' => $response,
    ]);
} catch (Throwable $e) {
    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/check-answer',
        'message' => $e->getMessage(),
    ]);

    api_send_json([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
    ], 422);
}

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

    $pdo->beginTransaction();
    try {
        $session = word_game_find_session_for_update($pdo, $sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Oturum bulunamadı.');
        }

        if ((string)($session['qualification_id'] ?? '') !== $currentQualificationId) {
            throw new RuntimeException('Bu yeterlilik için erişim yetkiniz yok.');
        }

        if ((string)($session['status'] ?? '') !== 'active') {
            throw new RuntimeException('Sadece aktif oturumda işlem yapılabilir.');
        }

        $sessionQuestion = word_game_find_session_question_for_update($pdo, $sessionQuestionId, $sessionId);
        if (!$sessionQuestion) {
            throw new RuntimeException('Oturum sorusu bulunamadı.');
        }

        if ((int)($sessionQuestion['is_completed'] ?? 0) === 1) {
            throw new RuntimeException('Bu soru zaten tamamlandı.');
        }

        word_game_mark_session_question_seen_if_first_interaction($pdo, $sessionQuestion, $userId);
        $result = word_game_check_answer($pdo, $sessionQuestion, $submittedAnswer);
        word_game_refresh_session_totals($pdo, $sessionId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

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

    if (!empty($result['answer_reveal']) && (bool)$result['question_completed'] === true) {
        $response['answer_reveal'] = (string)$result['answer_reveal'];
    }

    api_send_json([
        'success' => true,
        'data' => $response,
    ]);
} catch (Throwable $e) {
    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/check-answer',
        'error_class' => get_class($e),
    ]);

    api_send_json(word_game_build_error_response('Cevap kontrolü yapılamadı.', $e), 422);
}

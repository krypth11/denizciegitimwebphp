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

    word_game_debug_log('reveal/check/finish session ids', [
        'endpoint' => 'word-game/reveal-letter',
        'session_id' => $sessionId,
        'session_question_id' => $sessionQuestionId,
    ]);

    if ($sessionId === '' || $sessionQuestionId === '') {
        api_send_json([
            'success' => false,
            'message' => 'session_id ve session_question_id zorunludur.',
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
        api_send_json(['success' => false, 'message' => 'Tamamlanan soruda harf açılamaz.', 'data' => null], 422);
    }

    $pdo->beginTransaction();
    word_game_debug_log('reveal transaction started', [
        'transaction_started' => true,
        'session_id' => $sessionId,
        'session_question_id' => $sessionQuestionId,
    ]);

    try {
        $lockedSessionQuestion = word_game_find_session_question_for_update($pdo, $sessionQuestionId, $sessionId);
        if (!$lockedSessionQuestion) {
            throw new RuntimeException('Oturum sorusu bulunamadı.');
        }

        if ((int)($lockedSessionQuestion['is_completed'] ?? 0) === 1) {
            throw new RuntimeException('Tamamlanan soruda harf açılamaz.');
        }

        word_game_debug_log('reveal locked session question', [
            'transaction_started' => $pdo->inTransaction(),
            'locked_session_question_id' => (string)($lockedSessionQuestion['id'] ?? ''),
            'revealed_indexes_json_before' => (string)($lockedSessionQuestion['revealed_indexes_json'] ?? '[]'),
        ]);

        word_game_mark_session_question_seen_if_first_interaction($pdo, $lockedSessionQuestion, $userId);
        $result = word_game_reveal_letter($pdo, $lockedSessionQuestion);
        word_game_refresh_session_totals($pdo, $sessionId);

        $sessionAfterReveal = word_game_find_session_for_update($pdo, $sessionId, $userId) ?: [];

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        word_game_debug_log('reveal transaction rollback', [
            'endpoint' => 'word-game/reveal-letter',
            'session_id' => $sessionId,
            'session_question_id' => $sessionQuestionId,
            'message' => $e->getMessage(),
        ]);

        $message = $e->getMessage() === 'Açılacak harf kalmadı.'
            ? 'Açılacak harf kalmadı.'
            : 'Harf açma işlemi başarısız oldu.';

        api_send_json(word_game_build_error_response($message, $e), 422);
    }

    api_send_json([
        'success' => true,
        'data' => [
            'revealed_index' => (int)$result['revealed_index'],
            'revealed_logical_index' => (int)$result['revealed_logical_index'],
            'revealed_index_legacy_1_based' => (int)$result['revealed_index_legacy_1_based'],
            'revealed_letter' => (string)$result['revealed_letter'],
            'letters_taken_count' => (int)$result['letters_taken_count'],
            'total_letters_taken' => (int)($sessionAfterReveal['total_letters_taken'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/reveal-letter',
        'error_class' => get_class($e),
    ]);

    api_send_json(word_game_build_error_response('Harf açma işlemi başarısız oldu.', $e), 422);
}

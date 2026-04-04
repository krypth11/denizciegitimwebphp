<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/word_game_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');

    $qualificationId = word_game_get_current_qualification_id($pdo, $userId);
    word_game_debug_log('word game start current qualification', [
        'user_id' => $userId,
        'qualification_id' => $qualificationId,
    ]);

    if (!$qualificationId) {
        api_send_json([
            'success' => false,
            'message' => 'Current qualification bulunamadı. Önce yeterlilik seçmelisiniz.',
            'data' => null,
        ], 403);
    }

    try {
        $questions = word_game_pick_questions($pdo, $qualificationId);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        if (str_starts_with($message, 'WORD_GAME_INSUFFICIENT_QUESTIONS|')) {
            $json = substr($message, strlen('WORD_GAME_INSUFFICIENT_QUESTIONS|'));
            $details = json_decode($json, true);
            if (!is_array($details)) {
                $details = [];
            }

            api_send_json([
                'success' => false,
                'message' => 'Soru havuzu yetersiz. Gerekli dağılım sağlanamadı.',
                'data' => [
                    'qualification_id' => $qualificationId,
                    'missing_lengths' => $details,
                ],
            ], 422);
        }

        throw $e;
    }

    $created = word_game_session_create($pdo, $userId, $qualificationId, $questions);

    api_send_json([
        'success' => true,
        'data' => [
            'session_id' => (string)$created['session_id'],
            'qualification_id' => (string)$created['qualification_id'],
            'duration_seconds' => (int)$created['duration_seconds'],
            'questions' => array_values($created['questions'] ?? []),
        ],
    ]);
} catch (Throwable $e) {
    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/start',
        'message' => $e->getMessage(),
    ]);

    api_send_json(word_game_build_error_response('Word game başlatılamadı.', $e), 422);
}

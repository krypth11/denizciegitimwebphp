<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $payload = api_get_request_data();

    $attemptId = trim((string)($payload['attempt_id'] ?? ''));
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $selectedAnswer = isset($payload['selected_answer']) ? (string)$payload['selected_answer'] : null;

    if ($attemptId === '' || $questionId === '') {
        api_error('attempt_id ve question_id zorunludur.', 422);
    }

    $result = mock_exam_save_answer($pdo, $userId, $attemptId, $questionId, $selectedAnswer);
    api_success('Cevap kaydedildi.', $result);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

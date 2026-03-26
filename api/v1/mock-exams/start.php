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

    $active = mock_exam_find_active_attempt($pdo, $userId);
    if ($active) {
        api_success('Aktif deneme bulundu.', [
            'has_active_attempt' => true,
            'resume_existing' => true,
            'attempt' => $active,
            'warning_message' => null,
            'questions' => [],
        ]);
    }

    $created = mock_exam_create_attempt($pdo, $userId, [
        'qualification_id' => (string)($payload['qualification_id'] ?? ''),
        'requested_question_count' => (int)($payload['requested_question_count'] ?? 0),
        'pool_type' => (string)($payload['pool_type'] ?? 'random'),
        'mode' => (string)($payload['mode'] ?? 'standard'),
        'source_attempt_id' => (string)($payload['source_attempt_id'] ?? ''),
    ]);

    api_success('Deneme başlatıldı.', [
        'has_active_attempt' => false,
        'resume_existing' => (bool)($created['resume_existing'] ?? false),
        'attempt' => $created['attempt'] ?? null,
        'warning_message' => $created['attempt']['warning_message'] ?? null,
        'questions' => $created['questions'] ?? [],
        'summary' => $created['summary'] ?? null,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

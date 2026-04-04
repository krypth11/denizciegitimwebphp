<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.submit');
    $payload = api_get_request_data();

    $attemptId = trim((string)($payload['attempt_id'] ?? ''));
    $elapsedSeconds = max(0, (int)($payload['elapsed_seconds'] ?? 0));

    if ($attemptId === '') {
        api_error('attempt_id zorunludur.', 422);
    }

    $attempt = mock_exam_find_attempt_by_id($pdo, $userId, $attemptId);
    if (!$attempt) {
        api_error('Deneme bulunamadı.', 404);
    }
    api_assert_requested_qualification_matches_current(
        $pdo,
        $auth,
        (string)(($attempt['qualification_id'] ?? null) ?: ''),
        'mock-exams.submit.attempt'
    );

    $result = mock_exam_submit($pdo, $userId, $attemptId, $elapsedSeconds);
    api_success('Deneme tamamlandı.', $result);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

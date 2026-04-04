<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.flag');
    $payload = api_get_request_data();

    $attemptId = trim((string)($payload['attempt_id'] ?? ''));
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $isFlagged = (bool)($payload['is_flagged'] ?? false);

    if ($attemptId === '' || $questionId === '') {
        api_error('attempt_id ve question_id zorunludur.', 422);
    }

    $attempt = mock_exam_find_attempt_by_id($pdo, $userId, $attemptId);
    if (!$attempt) {
        api_error('Deneme bulunamadı.', 404);
    }
    api_assert_requested_qualification_matches_current(
        $pdo,
        $auth,
        (string)(($attempt['qualification_id'] ?? null) ?: ''),
        'mock-exams.flag.attempt'
    );

    $result = mock_exam_toggle_flag($pdo, $userId, $attemptId, $questionId, $isFlagged);
    api_success('İşaretleme güncellendi.', $result);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

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

    $sourceAttemptId = trim((string)($payload['source_attempt_id'] ?? ''));
    if ($sourceAttemptId === '') {
        api_error('source_attempt_id zorunludur.', 422);
    }

    $sourceAttempt = mock_exam_find_attempt_by_id($pdo, $userId, $sourceAttemptId);
    if (!$sourceAttempt) {
        api_error('Kaynak deneme bulunamadı.', 404);
    }

    $qualificationId = (string)($payload['qualification_id'] ?? ($sourceAttempt['qualification_id'] ?? ''));
    if (trim($qualificationId) === '') {
        api_error('qualification_id zorunludur.', 422);
    }

    $mode = (string)($payload['mode'] ?? 'similar');
    $requestedQuestionCount = (int)($payload['requested_question_count'] ?? ($sourceAttempt['requested_question_count'] ?? 20));
    $poolType = (string)($payload['pool_type'] ?? ($sourceAttempt['pool_type'] ?? 'random'));

    $activeDetail = mock_exam_fetch_active_attempt_detail($pdo, $userId);
    if ($activeDetail) {
        $activeQuestions = $activeDetail['questions'] ?? [];
        if (empty($activeQuestions)) {
            api_error('Bu kriterlere uygun deneme soruları oluşturulamadı.', 422);
        }

        api_success('Aktif deneme bulundu.', [
            'has_active_attempt' => true,
            'resume_existing' => true,
            'attempt' => $activeDetail['attempt'] ?? null,
            'warning_message' => $activeDetail['attempt']['warning_message'] ?? null,
            'questions' => $activeQuestions,
            'summary' => $activeDetail['summary'] ?? null,
        ]);
    }

    $created = mock_exam_create_attempt($pdo, $userId, [
        'qualification_id' => $qualificationId,
        'requested_question_count' => $requestedQuestionCount,
        'pool_type' => $poolType,
        'mode' => $mode,
        'source_attempt_id' => $sourceAttemptId,
    ]);

    $createdQuestions = $created['questions'] ?? [];
    if (empty($createdQuestions)) {
        api_error('Bu kriterlere uygun deneme soruları oluşturulamadı.', 422);
    }

    api_success('Yeni deneme oluşturuldu.', [
        'has_active_attempt' => false,
        'resume_existing' => (bool)($created['resume_existing'] ?? false),
        'attempt' => $created['attempt'] ?? null,
        'warning_message' => $created['attempt']['warning_message'] ?? null,
        'questions' => $createdQuestions,
        'summary' => $created['summary'] ?? null,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

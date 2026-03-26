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

    $activeDetail = mock_exam_fetch_active_attempt_detail($pdo, $userId);
    if ($activeDetail) {
        $activeQuestions = $activeDetail['questions'] ?? [];
        if (empty($activeQuestions)) {
            api_error('Bu kriterlere uygun deneme soruları oluşturulamadı.', 422);
        }

        $activeAttempt = $activeDetail['attempt'] ?? [];
        api_success('Aktif deneme bulundu.', [
            'attempt' => $activeAttempt,
            'questions' => $activeQuestions,
            'summary' => $activeDetail['summary'] ?? null,
            'lesson_report' => $activeDetail['lesson_report'] ?? [],
            'strongest_course' => $activeDetail['strongest_course'] ?? null,
            'weakest_course' => $activeDetail['weakest_course'] ?? null,
            'most_blank_course' => $activeDetail['most_blank_course'] ?? null,
            'warning_message' => $activeAttempt['warning_message'] ?? null,
            'mode' => (string)($activeAttempt['mode'] ?? ($payload['mode'] ?? 'standard')),
        ]);
    }

    $created = mock_exam_create_attempt($pdo, $userId, [
        'qualification_id' => (string)($payload['qualification_id'] ?? ''),
        'requested_question_count' => (int)($payload['requested_question_count'] ?? 0),
        'pool_type' => (string)($payload['pool_type'] ?? 'random'),
        'mode' => (string)($payload['mode'] ?? 'standard'),
        'source_attempt_id' => (string)($payload['source_attempt_id'] ?? ''),
    ]);

    $createdQuestions = $created['questions'] ?? [];
    if (empty($createdQuestions)) {
        api_error('Bu kriterlere uygun deneme soruları oluşturulamadı.', 422);
    }

    api_success('Deneme başlatıldı.', [
        'attempt' => $created['attempt'] ?? null,
        'questions' => $createdQuestions,
        'summary' => $created['summary'] ?? null,
        'lesson_report' => $created['lesson_report'] ?? [],
        'strongest_course' => $created['strongest_course'] ?? null,
        'weakest_course' => $created['weakest_course'] ?? null,
        'most_blank_course' => $created['most_blank_course'] ?? null,
        'warning_message' => $created['attempt']['warning_message'] ?? null,
        'mode' => (string)($created['attempt']['mode'] ?? ($payload['mode'] ?? 'standard')),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

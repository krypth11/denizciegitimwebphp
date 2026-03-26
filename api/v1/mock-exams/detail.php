<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $attemptId = api_require_query_param('attempt_id');

    $detail = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    $questions = $detail['questions'] ?? [];
    if (empty($questions)) {
        api_error('Bu denemeye ait soru bulunamadı.', 422);
    }

    api_success('Deneme detayı alındı.', [
        'attempt' => $detail['attempt'] ?? null,
        'questions' => $questions,
        'summary' => $detail['summary'] ?? null,
        'lesson_report' => $detail['lesson_report'] ?? [],
        'strongest_course' => $detail['strongest_course'] ?? null,
        'weakest_course' => $detail['weakest_course'] ?? null,
        'most_blank_course' => $detail['most_blank_course'] ?? null,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

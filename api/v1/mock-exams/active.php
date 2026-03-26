<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $detail = mock_exam_fetch_active_attempt_detail($pdo, $userId);
    if (!$detail) {
        api_success('Aktif deneme yok.', [
            'has_active_attempt' => false,
            'attempt' => null,
            'questions' => [],
            'summary' => null,
            'lesson_report' => [],
        ]);
    }

    $questions = $detail['questions'] ?? [];
    if (empty($questions)) {
        api_error('Bu kriterlere uygun deneme soruları oluşturulamadı.', 422);
    }

    $attempt = $detail['attempt'] ?? null;
    $lessonReport = [];
    if (!empty($attempt['id'])) {
        $lessonReport = mock_exam_build_lesson_report($pdo, (string)$attempt['id']);
    }

    api_success('Aktif deneme detayı alındı.', [
        'has_active_attempt' => true,
        'attempt' => $attempt,
        'questions' => $questions,
        'summary' => $detail['summary'] ?? null,
        'lesson_report' => $lessonReport,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

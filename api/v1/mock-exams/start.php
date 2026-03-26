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

    $qualificationId = trim((string)($payload['qualification_id'] ?? ''));
    $requestedQuestionCount = (int)($payload['requested_question_count'] ?? 0);
    $poolType = strtolower(trim((string)($payload['pool_type'] ?? 'random')));
    $mode = strtolower(trim((string)($payload['mode'] ?? 'standard')));

    if ($qualificationId === '') {
        api_error('qualification_id zorunludur.', 422);
    }

    if (!in_array($poolType, ['random', 'unseen', 'seen', 'wrong'], true)) {
        api_error('pool_type geçersiz. Desteklenen değerler: random, unseen, seen, wrong', 422);
    }

    if (!in_array($mode, ['standard', 'similar', 'wrong_only', 'wrong_blank'], true)) {
        api_error('mode geçersiz.', 422);
    }

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
            'mode' => (string)($activeAttempt['mode'] ?? $mode),
        ]);
    }

    $created = mock_exam_create_attempt($pdo, $userId, [
        'qualification_id' => $qualificationId,
        'requested_question_count' => $requestedQuestionCount,
        'pool_type' => $poolType,
        'mode' => $mode,
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
        'mode' => (string)($created['attempt']['mode'] ?? $mode),
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

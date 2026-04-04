<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.generate-from-attempt');
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

    api_qualification_access_log('requested qualification', [
        'context' => 'mock-exams.generate-from-attempt.payload',
        'requested_qualification_id' => $qualificationId,
        'current_qualification_id' => $currentQualificationId,
    ]);
    $qualificationId = $currentQualificationId;

    $mode = (string)($payload['mode'] ?? 'similar');
    $requestedQuestionCount = (int)($payload['requested_question_count'] ?? ($sourceAttempt['requested_question_count'] ?? 20));
    $poolType = (string)($payload['pool_type'] ?? ($sourceAttempt['pool_type'] ?? 'random'));

    $activeDetail = mock_exam_fetch_active_attempt_detail($pdo, $userId);
    if ($activeDetail) {
        $activeQualificationId = trim((string)(($activeDetail['attempt']['qualification_id'] ?? null) ?: ''));
        if ($activeQualificationId !== '' && $activeQualificationId !== $currentQualificationId) {
            $activeDetail = null;
        }
    }

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
        'source_attempt_id' => $sourceAttemptId,
    ]);

    $createdQuestions = $created['questions'] ?? [];
    if (empty($createdQuestions)) {
        api_error('Bu kriterlere uygun deneme soruları oluşturulamadı.', 422);
    }

    api_qualification_access_log('exam qualifications returned count', [
        'context' => 'mock-exams.generate-from-attempt',
        'count' => 1,
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('exam qualification returned', [
        'context' => 'mock-exams.generate-from-attempt',
        'exam qualification returned' => $currentQualificationId,
    ]);

    api_success('Yeni deneme oluşturuldu.', [
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

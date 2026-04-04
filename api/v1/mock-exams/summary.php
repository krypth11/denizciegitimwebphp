<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.summary');

    $preferences = mock_exam_get_user_exam_preferences($pdo, $userId);
    $activeAttempt = mock_exam_find_active_attempt($pdo, $userId);
    if ($activeAttempt) {
        $activeQualificationId = trim((string)(($activeAttempt['qualification_id'] ?? null) ?: ''));
        if ($activeQualificationId !== '' && $activeQualificationId !== $currentQualificationId) {
            $activeAttempt = null;
        }
    }
    $overview = mock_exam_build_summary_stats($pdo, $userId);
    $recent = mock_exam_fetch_history($pdo, $userId, [
        'page' => 1,
        'per_page' => 5,
        'status' => 'all',
        'sort' => 'newest',
        'qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('exam qualifications returned count', [
        'context' => 'mock-exams.summary',
        'count' => 1,
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_success('Deneme sınavı özet bilgisi alındı.', [
        'preferences' => $preferences,
        'active_attempt' => $activeAttempt,
        'overview_cards' => $overview,
        'recent_attempts' => $recent['items'] ?? [],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

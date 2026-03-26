<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $preferences = mock_exam_get_user_exam_preferences($pdo, $userId);
    $activeAttempt = mock_exam_find_active_attempt($pdo, $userId);
    $overview = mock_exam_build_summary_stats($pdo, $userId);
    $recent = mock_exam_fetch_history($pdo, $userId, ['page' => 1, 'per_page' => 5, 'status' => 'all', 'sort' => 'newest']);

    api_success('Deneme sınavı özet bilgisi alındı.', [
        'preferences' => $preferences,
        'active_attempt' => $activeAttempt,
        'overview_cards' => $overview,
        'recent_attempts' => $recent['items'] ?? [],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

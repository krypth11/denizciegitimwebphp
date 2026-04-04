<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'mock-exams.history');

    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $sort = strtolower(trim((string)($_GET['sort'] ?? 'newest')));
    $qualificationId = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id');
    if ($qualificationId !== '') {
        api_assert_requested_qualification_matches_current($pdo, $auth, $qualificationId, 'mock-exams.history.query');
    }
    $qualificationId = $currentQualificationId;
    $page = api_get_int_query('page', 1, 1, 100000);
    $perPage = api_get_int_query('per_page', 20, 1, 100);

    $result = mock_exam_fetch_history($pdo, $userId, [
        'status' => $status,
        'sort' => $sort,
        'qualification_id' => $qualificationId,
        'page' => $page,
        'per_page' => $perPage,
    ]);

    api_qualification_access_log('exam qualifications returned count', [
        'context' => 'mock-exams.history',
        'count' => 1,
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_success('Deneme geçmişi alındı.', $result);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $sort = strtolower(trim((string)($_GET['sort'] ?? 'newest')));
    $qualificationId = api_validate_optional_id((string)($_GET['qualification_id'] ?? ''), 'qualification_id');
    $page = api_get_int_query('page', 1, 1, 100000);
    $perPage = api_get_int_query('per_page', 20, 1, 100);

    $result = mock_exam_fetch_history($pdo, $userId, [
        'status' => $status,
        'sort' => $sort,
        'qualification_id' => $qualificationId,
        'page' => $page,
        'per_page' => $perPage,
    ]);

    api_success('Deneme geçmişi alındı.', $result);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

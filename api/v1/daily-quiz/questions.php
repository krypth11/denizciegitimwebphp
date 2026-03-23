<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/daily_quiz_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, [
        'options' => ['default' => 20, 'min_range' => 1, 'max_range' => 50],
    ]);

    $questions = dq_fetch_daily_questions($pdo, $userId, (int)$limit);

    api_success('Günlük quiz soruları getirildi.', [
        'questions' => $questions,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

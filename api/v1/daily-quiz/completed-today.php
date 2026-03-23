<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/daily_quiz_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $completedToday = dq_completed_today($pdo, $userId);

    api_success('Bugünkü quiz tamamlanma durumu getirildi.', [
        'completed_today' => $completedToday,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/daily_quiz_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $progress = dq_fetch_today_progress($pdo, $userId);

    api_success('Bugünün daily quiz progress bilgisi getirildi.', [
        'progress' => $progress,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/story_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }

    story_ensure_schema($pdo);
    $stories = story_mobile_list($pdo, $userId);

    api_success('Story listesi alındı.', [
        'items' => $stories,
    ]);
} catch (Throwable $e) {
    story_log('mobile stories index error', ['error' => $e->getMessage()]);
    api_error('Story listesi alınamadı.', 422);
}

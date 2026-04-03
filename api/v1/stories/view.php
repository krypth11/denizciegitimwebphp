<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/story_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }

    $data = api_get_request_data();
    $storyId = trim((string)($data['story_id'] ?? ''));
    if ($storyId === '') {
        api_error('story_id parametresi zorunludur.', 422);
    }

    story_ensure_schema($pdo);
    $result = story_mark_viewed($pdo, $storyId, $userId);

    api_success('Story viewed olarak işaretlendi.', $result);
} catch (Throwable $e) {
    story_log('mobile stories view error', ['error' => $e->getMessage()]);
    api_error($e->getMessage(), 422);
}

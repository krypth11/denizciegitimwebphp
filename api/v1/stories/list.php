<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/story_helper.php';

api_require_method('GET');

try {
    story_log('stories/list endpoint hit', [
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
    ]);

    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if ($userId === '') {
        api_send_json([
            'success' => false,
            'message' => 'Yetkisiz erişim.',
            'stories' => [],
        ], 401);
    }

    story_ensure_schema($pdo);
    $stories = story_list_for_mobile($pdo, $userId);

    story_log('stories/list response prepared', [
        'user_id' => $userId,
        'story_count' => count($stories),
        'base_url' => story_public_base_url(),
    ]);

    api_send_json([
        'success' => true,
        'stories' => $stories,
    ], 200);
} catch (Throwable $e) {
    story_log('stories/list error', [
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ]);

    api_send_json([
        'success' => false,
        'message' => 'Story listesi alınamadı.',
        'stories' => [],
    ], 422);
}

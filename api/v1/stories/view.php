<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/story_helper.php';

api_require_method('POST');

try {
    story_log('stories/view endpoint hit', [
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
    ]);

    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    if ($userId === '') {
        api_send_json([
            'success' => false,
            'message' => 'Yetkisiz erişim.',
        ], 401);
    }

    $data = api_get_request_data();
    $storyId = trim((string)($data['story_id'] ?? ''));
    if ($storyId === '') {
        api_send_json([
            'success' => false,
            'message' => 'story_id parametresi zorunludur.',
        ], 422);
    }

    story_ensure_schema($pdo);
    $result = story_mark_viewed($pdo, $storyId, $userId);

    story_log('stories/view insert result', [
        'user_id' => $userId,
        'story_id' => $storyId,
        'already_viewed' => (bool)($result['already_viewed'] ?? false),
        'is_viewed' => (bool)($result['is_viewed'] ?? false),
    ]);

    api_send_json([
        'success' => true,
        'story_id' => (string)($result['story_id'] ?? $storyId),
        'is_viewed' => (bool)($result['is_viewed'] ?? true),
        'already_viewed' => (bool)($result['already_viewed'] ?? false),
    ], 200);
} catch (Throwable $e) {
    story_log('mobile stories view error', [
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ]);

    api_send_json([
        'success' => false,
        'message' => $e->getMessage(),
    ], 422);
}

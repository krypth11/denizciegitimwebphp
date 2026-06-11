<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/notification_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');

    if ($userId === '' || notification_is_guest_user($pdo, $userId)) {
        api_success('Bildirim kutusu getirildi.', [
            'items' => [],
            'pagination' => [
                'page' => api_get_int_query('page', 1, 1, 100000),
                'limit' => api_get_int_query('limit', 30, 1, 100),
                'has_more' => false,
            ],
            'unread_count' => 0,
        ]);
    }

    $filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
    $page = api_get_int_query('page', 1, 1, 100000);
    $limit = api_get_int_query('limit', 30, 1, 100);

    $result = notification_list_inbox_for_user($pdo, $userId, $filter, $page, $limit);
    api_success('Bildirim kutusu getirildi.', $result);
} catch (Throwable $e) {
    error_log('[notifications.inbox] ' . $e->getMessage());
    api_error('Bildirim kutusu alınamadı.', 500);
}
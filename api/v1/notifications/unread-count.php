<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/notification_helper.php';

api_require_method('GET');

try {
    $auth = api_resolve_auth($pdo);
    if (!$auth) {
        $auth = api_resolve_web_auth($pdo);
    }

    $userId = (string)($auth['user']['id'] ?? '');
    $unreadCount = 0;

    if ($userId !== '' && !notification_is_guest_user($pdo, $userId)) {
        $unreadCount = notification_count_unread_for_user($pdo, $userId);
    }

    $displayCount = '';
    if ($unreadCount > 0) {
        $displayCount = $unreadCount >= 100 ? '99+' : (string)$unreadCount;
    }

    api_success('Okunmamış bildirim sayısı getirildi.', [
        'unread_count' => $unreadCount,
        'display_count' => $displayCount,
    ]);
} catch (Throwable $e) {
    error_log('[notifications.unread-count] ' . $e->getMessage());
    api_error('Okunmamış bildirim sayısı alınamadı.', 500);
}
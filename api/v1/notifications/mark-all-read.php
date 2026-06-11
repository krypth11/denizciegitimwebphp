<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/notification_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');

    if ($userId === '' || notification_is_guest_user($pdo, $userId)) {
        api_error('Bu işlem için erişim yetkiniz yok.', 403);
    }

    $updatedCount = notification_mark_all_read_for_user($pdo, $userId);

    api_success('Tüm bildirimler okundu olarak işaretlendi.', [
        'updated_count' => $updatedCount,
    ]);
} catch (Throwable $e) {
    error_log('[notifications.mark-all-read] ' . $e->getMessage());
    api_error('Bildirimler güncellenemedi.', 500);
}
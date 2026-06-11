<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/notification_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $payload = api_get_request_data();
    $notificationId = trim((string)($payload['notification_id'] ?? ''));

    if ($notificationId === '') {
        api_error('notification_id zorunludur.', 422);
    }

    if ($userId === '' || notification_is_guest_user($pdo, $userId)) {
        api_error('Bu işlem için erişim yetkiniz yok.', 403);
    }

    $detail = notification_get_detail_for_user($pdo, $userId, $notificationId);
    if (!$detail) {
        api_error('Bildirim bulunamadı.', 404);
    }

    $updated = notification_mark_read_for_user($pdo, $userId, $notificationId);

    api_success('Bildirim okundu olarak işaretlendi.', [
        'notification_id' => $notificationId,
        'updated' => $updated,
    ]);
} catch (Throwable $e) {
    error_log('[notifications.mark-read] ' . $e->getMessage());
    api_error('Bildirim okundu bilgisi güncellenemedi.', 500);
}
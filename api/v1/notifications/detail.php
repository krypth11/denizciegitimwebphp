<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/notification_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $notificationId = trim((string)($_GET['id'] ?? $_GET['notification_id'] ?? ''));

    if ($notificationId === '') {
        api_error('id veya notification_id parametresi zorunludur.', 422);
    }

    if ($userId === '' || notification_is_guest_user($pdo, $userId)) {
        api_error('Bu bildirim için erişim yetkiniz yok.', 403);
    }

    $notification = notification_get_detail_for_user($pdo, $userId, $notificationId);
    if (!$notification) {
        api_error('Bildirim bulunamadı.', 404);
    }

    api_success('Bildirim detayı getirildi.', [
        'notification' => $notification,
    ]);
} catch (Throwable $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'Yetkisiz erişim')) {
        api_error('Yetkisiz erişim.', 401);
    }
    error_log('[notifications.detail] ' . $e->getMessage());
    api_error('Bildirim detayı alınamadı.', 500);
}
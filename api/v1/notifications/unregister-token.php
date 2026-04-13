<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/notification_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $payload = api_get_request_data();
    $fcmToken = (string)($payload['fcm_token'] ?? '');

    $updated = notification_unregister_user_token($pdo, $userId, $fcmToken);

    api_success('Push token pasife alındı.', [
        'updated' => $updated,
    ]);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    error_log('[notifications.unregister-token] ' . $e->getMessage());
    api_error('Push token pasife alma sırasında bir hata oluştu.', 500);
} catch (Throwable $e) {
    error_log('[notifications.unregister-token] unexpected: ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

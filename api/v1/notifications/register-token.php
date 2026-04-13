<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/notification_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $payload = api_get_request_data();

    $token = notification_register_user_token($pdo, $userId, $payload);

    api_success('Push token kaydedildi.', [
        'token' => $token,
    ]);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    error_log('[notifications.register-token] ' . $e->getMessage());
    api_error('Push token kaydı sırasında bir hata oluştu.', 500);
} catch (Throwable $e) {
    error_log('[notifications.register-token] unexpected: ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

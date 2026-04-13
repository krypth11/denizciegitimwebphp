<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/notification_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');

    $tokens = notification_list_user_tokens($pdo, $userId);

    api_success('Push token listesi getirildi.', [
        'tokens' => $tokens,
    ]);
} catch (RuntimeException $e) {
    error_log('[notifications.my-tokens] ' . $e->getMessage());
    api_error('Push token listesi alınırken bir hata oluştu.', 500);
} catch (Throwable $e) {
    error_log('[notifications.my-tokens] unexpected: ' . $e->getMessage());
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

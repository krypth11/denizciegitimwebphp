<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);

    // Oturum aktif kaldığı sürece last_activity güncel kalır.
    $_SESSION['last_activity'] = time();

    api_success('Oturum aktif.', [
        'time' => time(),
        'user_id' => (string)($auth['user']['id'] ?? ''),
    ]);
} catch (Throwable $e) {
    api_error('Keep-alive sırasında bir hata oluştu.', 500);
}

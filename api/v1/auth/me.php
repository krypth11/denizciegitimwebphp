<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $user = $auth['user'];

    api_success('Kullanıcı bilgisi alındı.', [
        'user' => api_build_auth_user_payload($pdo, (string)$user['id']),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

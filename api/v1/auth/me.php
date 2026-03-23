<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $user = $auth['user'];

    api_success('Kullanıcı bilgisi alındı.', [
        'user' => [
            'id' => (string)$user['id'],
            'email' => (string)$user['email'],
            'full_name' => (string)($user['full_name'] ?? ''),
            'is_admin' => ((int)($user['is_admin'] ?? 0) === 1),
        ],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    api_revoke_hashed_token($pdo, (string)$auth['token_hash']);

    api_success('Çıkış başarılı.', []);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);

    $items = offline_get_downloadable_qualifications($pdo);

    api_success('Offline indirilebilir yeterlilikler getirildi.', [
        'qualifications' => $items,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

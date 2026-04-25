<?php

require_once __DIR__ . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/app_runtime_settings_helper.php';

api_require_method('GET');

try {
    $settings = app_runtime_settings_get($pdo);

    api_success('Uygulama runtime ayarları getirildi.', [
        'runtime_settings' => app_runtime_settings_normalize($settings),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

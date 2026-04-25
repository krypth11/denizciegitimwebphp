<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth_helper.php';
require_once dirname(__DIR__, 4) . '/includes/app_runtime_settings_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    $settings = app_runtime_settings_get($pdo);
    api_success('Uygulama limitleri getirildi.', [
        'settings' => app_runtime_settings_normalize($settings),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

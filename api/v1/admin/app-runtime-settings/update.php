<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth_helper.php';
require_once dirname(__DIR__, 4) . '/includes/app_runtime_settings_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    $payload = api_get_request_data();
    if (!is_array($payload)) {
        $payload = [];
    }

    $input = [];
    $rules = app_runtime_settings_rules();
    foreach (app_runtime_settings_allowed_keys() as $key) {
        if (array_key_exists($key, $payload)) {
            $rawValue = $payload[$key];
            if (is_string($rawValue)) {
                $rawValue = trim($rawValue);
            }

            if (filter_var($rawValue, FILTER_VALIDATE_INT) === false) {
                api_error($key . ' alanı pozitif/tamsayı olmalıdır.', 422);
            }

            $intValue = (int)$rawValue;
            $min = (int)($rules[$key]['min'] ?? PHP_INT_MIN);
            $max = (int)($rules[$key]['max'] ?? PHP_INT_MAX);
            if ($intValue < $min || $intValue > $max) {
                api_error($key . ' alanı ' . $min . ' ile ' . $max . ' arasında olmalıdır.', 422);
            }

            $input[$key] = $intValue;
        }
    }

    $updated = app_runtime_settings_update($pdo, $input);
    api_success('Uygulama limitleri güncellendi.', [
        'settings' => app_runtime_settings_normalize($updated),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

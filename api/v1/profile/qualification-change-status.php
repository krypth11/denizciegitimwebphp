<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/qualification_change_credit_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    qualification_change_apply_annual_grant($pdo, $userId);
    $status = qualification_change_get_status($pdo, $userId);

    api_success('Yeterlilik değiştirme hakkı durumu getirildi.', $status);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

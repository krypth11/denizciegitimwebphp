<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/referral_helper.php';

api_require_method('GET');
try {
    $auth = api_require_auth($pdo);
    api_success('Referans özeti alındı.', referral_get_user_summary($pdo, (string)$auth['user']['id']));
} catch (Throwable $e) {
    api_error('Referans özeti alınırken hata oluştu.', 500);
}

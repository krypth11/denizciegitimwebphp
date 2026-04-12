<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'usage.summary');

    $summary = usage_limits_get_summary($pdo, $userId, $qualificationId);

    api_success('Kullanım özeti getirildi.', $summary);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

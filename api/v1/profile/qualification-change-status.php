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

    api_success('Yeterlilik değiştirme hakkı durumu getirildi.', [
        'qualification_change_status' => $status,
        'credits' => $status['credits'],
        'can_change' => $status['can_change'],
        'next_grant_at' => $status['next_grant_at'],
        'annual_grant_count' => $status['annual_grant_count'],
        'last_granted_at' => $status['last_granted_at'] ?? null,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

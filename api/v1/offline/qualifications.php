<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'offline.qualifications');

    $items = offline_get_downloadable_qualifications($pdo, $currentQualificationId);

    api_qualification_access_log('offline qualifications returned count', [
        'context' => 'offline.qualifications',
        'count' => count($items),
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('offline qualification returned', [
        'context' => 'offline.qualifications',
        'offline qualification returned' => $currentQualificationId,
    ]);

    api_success('Offline indirilebilir yeterlilikler getirildi.', [
        'qualifications' => $items,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);

    $qualificationId = api_require_query_param('qualification_id', 191);
    $currentQualificationId = api_assert_requested_qualification_matches_current($pdo, $auth, $qualificationId, 'offline.package');

    $pkg = offline_get_qualification_package_data($pdo, $currentQualificationId);
    if (!$pkg) {
        api_error('Yeterlilik bulunamadı.', 404);
    }

    api_qualification_access_log('offline qualifications returned count', [
        'context' => 'offline.package',
        'count' => 1,
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_success('Offline paket getirildi.', [
        'package_version' => $pkg['package_version'],
        'package_generated_at' => $pkg['package_generated_at'],
        'qualification' => $pkg['qualification'],
        'courses' => $pkg['courses'],
        'topics' => $pkg['topics'],
        'questions' => $pkg['questions'],
        'assets' => $pkg['assets'],
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

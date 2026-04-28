<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

api_require_method('GET');
$auth = api_require_auth($pdo);
$currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study_resources.bootstrap');

$qStmt = $pdo->prepare('SELECT * FROM study_resource_qualifications WHERE is_active=1 AND linked_qualification_id=? LIMIT 1');
$qStmt->execute([$currentQualificationId]);
$qualification = $qStmt->fetch(PDO::FETCH_ASSOC);

$courses = [];
if ($qualification) {
    $cStmt = $pdo->prepare('SELECT * FROM study_resource_courses WHERE resource_qualification_id=? AND is_active=1 ORDER BY name ASC');
    $cStmt->execute([(string)$qualification['id']]);
    $courses = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($courses as &$course) {
        $tStmt = $pdo->prepare('SELECT * FROM study_resource_topics WHERE resource_course_id=? AND is_active=1 ORDER BY name ASC');
        $tStmt->execute([(string)$course['id']]);
        $topics = $tStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $course['topics'] = $topics;
        $course['has_topics'] = count($topics) > 0;
    }
    unset($course);
}

$settings = sr_get_settings($pdo);
api_success('OK', [
    'qualification' => $qualification ?: null,
    'courses' => $courses,
    'settings' => [
        'premium_auto_cache_enabled' => ((int)($settings['premium_auto_cache_enabled'] ?? 1) === 1),
        'free_auto_cache_enabled' => ((int)($settings['free_auto_cache_enabled'] ?? 1) === 1),
        'premium_offline_access_enabled' => ((int)($settings['premium_offline_access_enabled'] ?? 1) === 1),
        'free_offline_access_enabled' => ((int)($settings['free_offline_access_enabled'] ?? 1) === 1),
    ],
]);

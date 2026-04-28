<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';

api_require_method('GET');
$auth = api_require_auth($pdo);
$currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study_resources.catalog');

$qStmt = $pdo->prepare('SELECT * FROM study_resource_qualifications WHERE is_active=1 AND linked_qualification_id=? LIMIT 1');
$qStmt->execute([$currentQualificationId]);
$qualification = $qStmt->fetch(PDO::FETCH_ASSOC);
if (!$qualification) {
    api_success('OK', ['qualification' => null, 'courses' => []]);
}

$cStmt = $pdo->prepare('SELECT * FROM study_resource_courses WHERE qualification_id=? AND is_active=1 ORDER BY name ASC');
$cStmt->execute([(string)$qualification['id']]);
$courses = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($courses as &$course) {
    $tStmt = $pdo->prepare('SELECT * FROM study_resource_topics WHERE course_id=? AND is_active=1 ORDER BY name ASC');
    $tStmt->execute([(string)$course['id']]);
    $topics = $tStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $course['topics'] = $topics;
    $course['has_topics'] = count($topics) > 0;
}
unset($course);

api_success('OK', [
    'qualification' => $qualification,
    'courses' => $courses,
]);

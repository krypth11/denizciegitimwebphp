<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

api_require_method('GET');
$auth = api_require_auth($pdo);
$userId = (string)($auth['user']['id'] ?? '');
$currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study_resources.pdfs');
$courseId = trim((string)($_GET['resource_course_id'] ?? ''));
$topicId = trim((string)($_GET['resource_topic_id'] ?? ''));
if ($courseId === '') api_error('resource_course_id zorunludur.', 422);

$q = $pdo->prepare('SELECT id FROM study_resource_qualifications WHERE linked_qualification_id=? AND is_active=1 LIMIT 1');
$q->execute([$currentQualificationId]);
$rqId = (string)($q->fetchColumn() ?: '');
if ($rqId === '') api_success('OK', ['requires_topic' => false, 'pdfs' => []]);

$c = $pdo->prepare('SELECT id FROM study_resource_courses WHERE id=? AND resource_qualification_id=? AND is_active=1 LIMIT 1');
$c->execute([$courseId, $rqId]);
if (!$c->fetchColumn()) api_error('Ders bulunamadı.', 404);

$hasTopicsStmt = $pdo->prepare('SELECT COUNT(*) FROM study_resource_topics WHERE resource_course_id=? AND is_active=1');
$hasTopicsStmt->execute([$courseId]);
$hasTopics = ((int)$hasTopicsStmt->fetchColumn()) > 0;
if ($hasTopics && $topicId === '') api_success('OK', ['requires_topic' => true, 'pdfs' => []]);

$isPremium = false;
if (function_exists('usage_limits_is_user_pro')) $isPremium = usage_limits_is_user_pro($pdo, $userId);

$sql = 'SELECT p.id,p.title,p.file_size_bytes,p.page_count,p.is_premium,p.updated_at,us.is_favorite,us.is_read,us.last_opened_at,us.offline_downloaded_at
        FROM study_resource_pdfs p
        LEFT JOIN study_resource_user_states us ON us.pdf_id=p.id AND us.user_id=?
        WHERE p.is_active=1 AND p.resource_course_id=? AND p.resource_qualification_id=?';
$params = [$userId, $courseId, $rqId];
if ($topicId !== '') { $sql .= ' AND p.resource_topic_id=?'; $params[] = $topicId; }
else { $sql .= ' AND (p.resource_topic_id IS NULL OR p.resource_topic_id="")'; }
$sql .= ' ORDER BY p.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pdfs = [];
foreach ($rows as $r) {
    $locked = ((int)$r['is_premium'] === 1 && !$isPremium);
    $pdfs[] = [
        'id' => (string)$r['id'],
        'title' => (string)$r['title'],
        'file_size_bytes' => (int)($r['file_size_bytes'] ?? 0),
        'file_size_label' => sr_file_size_label($r['file_size_bytes'] ?? 0),
        'page_count' => $r['page_count'] !== null ? (int)$r['page_count'] : null,
        'is_premium' => ((int)$r['is_premium'] === 1),
        'is_locked' => $locked,
        'updated_at' => $r['updated_at'] ?? null,
        'is_favorite' => ((int)($r['is_favorite'] ?? 0) === 1),
        'is_read' => ((int)($r['is_read'] ?? 0) === 1),
        'offline_downloaded_at' => $r['offline_downloaded_at'] ?? null,
        'last_opened_at' => $r['last_opened_at'] ?? null,
    ];
}

api_success('OK', ['requires_topic' => false, 'pdfs' => $pdfs]);

<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

api_require_method('GET');
$auth = api_require_auth($pdo);
$userId = (string)($auth['user']['id'] ?? '');
$currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study_resources.detail');
$pdfId = trim((string)($_GET['pdf_id'] ?? ''));
if ($pdfId === '') api_error('pdf_id zorunludur.', 422);

$stmt = $pdo->prepare('SELECT p.*, q.linked_qualification_id FROM study_resource_pdfs p INNER JOIN study_resource_qualifications q ON q.id=p.resource_qualification_id WHERE p.id=? AND p.is_active=1 LIMIT 1');
$stmt->execute([$pdfId]);
$pdf = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pdf) api_error('PDF bulunamadı.', 404);
if ((string)($pdf['linked_qualification_id'] ?? '') !== $currentQualificationId) api_error('Bu kaynağa erişim yetkiniz yok.', 403);

$isPremium = function_exists('usage_limits_is_user_pro') ? usage_limits_is_user_pro($pdo, $userId) : false;
if ((int)$pdf['is_premium'] === 1 && !$isPremium) api_error('Bu kaynak premium üyelik gerektirir.', 403);

$pdo->prepare('UPDATE study_resource_pdfs SET open_count=COALESCE(open_count,0)+1, updated_at=NOW() WHERE id=?')->execute([$pdfId]);
sr_log_event($pdo, $userId, 'open', $pdfId, null);
$pdo->prepare('INSERT INTO study_resource_user_states (user_id,pdf_id,last_opened_at,created_at,updated_at) VALUES (?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_opened_at=VALUES(last_opened_at), updated_at=NOW()')
    ->execute([$userId, $pdfId, date('Y-m-d H:i:s')]);

$stateStmt = $pdo->prepare('SELECT is_favorite,is_read,offline_downloaded_at,last_opened_at FROM study_resource_user_states WHERE user_id=? AND pdf_id=? LIMIT 1');
$stateStmt->execute([$userId, $pdfId]);
$state = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$settings = sr_get_settings($pdo);

api_success('OK', [
    'pdf' => [
        'id' => $pdfId,
        'title' => (string)$pdf['title'],
        'is_premium' => ((int)$pdf['is_premium'] === 1),
        'file_size_bytes' => (int)($pdf['file_size_bytes'] ?? 0),
        'file_size_label' => sr_file_size_label($pdf['file_size_bytes'] ?? 0),
        'page_count' => $pdf['page_count'] !== null ? (int)$pdf['page_count'] : null,
        'updated_at' => $pdf['updated_at'] ?? null,
        'viewer_url' => '/api/v1/study-resources/view.php?token=' . rawurlencode(sr_generate_view_token($pdfId, $userId, 600)),
        'download_url' => '/api/v1/study-resources/download.php?pdf_id=' . rawurlencode($pdfId),
        'state' => [
            'is_favorite' => ((int)($state['is_favorite'] ?? 0) === 1),
            'is_read' => ((int)($state['is_read'] ?? 0) === 1),
            'offline_downloaded_at' => $state['offline_downloaded_at'] ?? null,
            'last_opened_at' => $state['last_opened_at'] ?? null,
        ],
        'settings' => [
            'premium_auto_cache_enabled' => ((int)($settings['premium_auto_cache_enabled'] ?? 1) === 1),
            'free_auto_cache_enabled' => ((int)($settings['free_auto_cache_enabled'] ?? 1) === 1),
            'premium_offline_access_enabled' => ((int)($settings['premium_offline_access_enabled'] ?? 1) === 1),
            'free_offline_access_enabled' => ((int)($settings['free_offline_access_enabled'] ?? 1) === 1),
        ],
    ],
]);

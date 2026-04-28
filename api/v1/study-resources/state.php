<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

api_require_method('POST');
$auth = api_require_auth($pdo);
$userId = (string)($auth['user']['id'] ?? '');
$data = api_get_request_data();
$pdfId = trim((string)($data['pdf_id'] ?? ''));
if ($pdfId === '') api_error('pdf_id zorunludur.', 422);

$fields = [];
if (array_key_exists('is_favorite', $data)) $fields['is_favorite'] = ((int)$data['is_favorite'] === 1 ? 1 : 0);
if (array_key_exists('is_read', $data)) $fields['is_read'] = ((int)$data['is_read'] === 1 ? 1 : 0);
if (array_key_exists('offline_downloaded_at', $data)) {
    $fields['offline_downloaded_at'] = trim((string)$data['offline_downloaded_at']) ?: null;
}
if (!$fields) api_error('Güncellenecek alan yok.', 422);

$isFavorite = array_key_exists('is_favorite', $fields) ? $fields['is_favorite'] : 0;
$isRead = array_key_exists('is_read', $fields) ? $fields['is_read'] : 0;
$offlineDownloadedAt = array_key_exists('offline_downloaded_at', $fields) ? $fields['offline_downloaded_at'] : null;

$pdo->prepare('INSERT INTO study_resource_user_states (user_id,pdf_id,is_favorite,is_read,offline_downloaded_at,created_at,updated_at)
               VALUES (?,?,?,?,?,NOW(),NOW())
               ON DUPLICATE KEY UPDATE
                   is_favorite=IFNULL(VALUES(is_favorite), is_favorite),
                   is_read=IFNULL(VALUES(is_read), is_read),
                   offline_downloaded_at=IFNULL(VALUES(offline_downloaded_at), offline_downloaded_at),
                   updated_at=NOW()')
    ->execute([$userId, $pdfId, $isFavorite, $isRead, $offlineDownloadedAt]);

$event = 'state_update';
if (array_key_exists('is_favorite', $fields)) $event = 'favorite';
if (array_key_exists('is_read', $fields)) $event = 'read';
if (array_key_exists('offline_downloaded_at', $fields)) $event = 'offline_downloaded';
sr_log_event($pdo, $userId, $event, $pdfId, null);

api_success('Durum güncellendi.');

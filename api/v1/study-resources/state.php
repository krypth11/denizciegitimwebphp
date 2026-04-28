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

$sel = $pdo->prepare('SELECT id FROM study_resource_user_states WHERE pdf_id=? AND user_id=? LIMIT 1');
$sel->execute([$pdfId, $userId]);
$existingId = (string)($sel->fetchColumn() ?: '');

if ($existingId !== '') {
    $parts = [];
    $vals = [];
    foreach ($fields as $k => $v) { $parts[] = $k . '=?'; $vals[] = $v; }
    $vals[] = $existingId;
    $pdo->prepare('UPDATE study_resource_user_states SET ' . implode(',', $parts) . ', updated_at=NOW() WHERE id=?')->execute($vals);
} else {
    $cols = ['id', 'pdf_id', 'user_id'];
    $qs = ['?', '?', '?'];
    $vals = [sr_uuid(), $pdfId, $userId];
    foreach ($fields as $k => $v) { $cols[] = $k; $qs[] = '?'; $vals[] = $v; }
    $cols[] = 'created_at'; $qs[] = 'NOW()';
    $cols[] = 'updated_at'; $qs[] = 'NOW()';
    $pdo->prepare('INSERT INTO study_resource_user_states (' . implode(',', $cols) . ') VALUES (' . implode(',', $qs) . ')')->execute($vals);
}

$event = 'state_update';
if (array_key_exists('is_favorite', $fields)) $event = 'favorite';
if (array_key_exists('is_read', $fields)) $event = 'read';
if (array_key_exists('offline_downloaded_at', $fields)) $event = 'offline_downloaded';
sr_log_event($pdo, $userId, $event, $pdfId, null);

api_success('Durum güncellendi.');

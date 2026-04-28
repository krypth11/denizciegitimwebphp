<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

api_require_method('POST');
$auth = api_require_auth($pdo);
$userId = (string)($auth['user']['id'] ?? '');
$data = api_get_request_data();

$eventType = sr_clean((string)($data['event_type'] ?? ''), 64);
if ($eventType === '') api_error('event_type zorunludur.', 422);
$pdfId = trim((string)($data['pdf_id'] ?? '')) ?: null;
$queryText = sr_clean((string)($data['query_text'] ?? ''), 500);
if ($queryText === '') $queryText = null;

sr_log_event($pdo, $userId, $eventType, $pdfId, $queryText);
api_success('Event kaydedildi.');

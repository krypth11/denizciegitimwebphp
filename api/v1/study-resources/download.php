<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

$auth = api_require_auth($pdo);
$userId = (string)($auth['user']['id'] ?? '');
$currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study_resources.download');
$pdfId = trim((string)($_GET['pdf_id'] ?? ''));
if ($pdfId === '') { http_response_code(422); echo 'pdf_id zorunludur.'; exit; }

$stmt = $pdo->prepare('SELECT p.*, q.linked_qualification_id FROM study_resource_pdfs p INNER JOIN study_resource_qualifications q ON q.id=p.qualification_id WHERE p.id=? AND p.is_active=1 LIMIT 1');
$stmt->execute([$pdfId]);
$pdf = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$pdf) { http_response_code(404); echo 'PDF bulunamadı.'; exit; }
if ((string)($pdf['linked_qualification_id'] ?? '') !== $currentQualificationId) { http_response_code(403); echo 'Erişim yok.'; exit; }
$isPremium = function_exists('usage_limits_is_user_pro') ? usage_limits_is_user_pro($pdo, $userId) : false;
if ((int)$pdf['is_premium'] === 1 && !$isPremium) { http_response_code(403); echo 'Premium gerekli.'; exit; }

$abs = sr_safe_abs_from_rel((string)($pdf['file_path'] ?? ''));
if (!$abs || !is_file($abs)) { http_response_code(404); echo 'Dosya bulunamadı.'; exit; }

if (strtolower((string)pathinfo($abs, PATHINFO_EXTENSION)) !== 'pdf') { http_response_code(404); echo 'Dosya bulunamadı.'; exit; }

$pdo->prepare('UPDATE study_resource_pdfs SET download_count=COALESCE(download_count,0)+1, updated_at=NOW() WHERE id=?')->execute([$pdfId]);
sr_log_event($pdo, $userId, 'download', $pdfId, null);

header_remove('Content-Type');
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . rawurlencode((string)($pdf['original_file_name'] ?: 'document.pdf')) . '"');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;

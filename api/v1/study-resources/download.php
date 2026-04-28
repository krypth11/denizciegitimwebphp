<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

if (ob_get_level() > 0) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$last['type'], $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        api_error('Sunucu hatası.', 500);
    }
});

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study_resources.download');
    $pdfId = trim((string)($_GET['pdf_id'] ?? ''));
    $inline = ((int)($_GET['inline'] ?? 0) === 1);
    if ($pdfId === '') {
        api_error('pdf_id zorunludur.', 422);
    }

    $stmt = $pdo->prepare('SELECT p.*, q.linked_qualification_id FROM study_resource_pdfs p INNER JOIN study_resource_qualifications q ON q.id=p.resource_qualification_id WHERE p.id=? AND p.is_active=1 LIMIT 1');
    $stmt->execute([$pdfId]);
    $pdf = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pdf) {
        api_error('PDF bulunamadı.', 404);
    }
    if ((string)($pdf['linked_qualification_id'] ?? '') !== $currentQualificationId) {
        api_error('Erişim yok.', 403);
    }
    $isPremium = function_exists('usage_limits_is_user_pro') ? usage_limits_is_user_pro($pdo, $userId) : false;
    if ((int)$pdf['is_premium'] === 1 && !$isPremium) {
        api_error('Premium gerekli.', 403);
    }

    $relativePath = trim(str_replace('\\', '/', (string)($pdf['file_path'] ?? '')));
    if (!preg_match('#^study_resources/([a-f0-9\-]{36})\.pdf$#i', $relativePath, $m)) {
        api_error('Dosya bulunamadı.', 404);
    }

    $storedUuid = (string)($m[1] ?? '');
    $expectedRelPath = 'study_resources/' . $storedUuid . '.pdf';
    $abs = sr_upload_root_abs() . '/' . $expectedRelPath;

    if (strtolower((string)pathinfo($abs, PATHINFO_EXTENSION)) !== 'pdf') {
        api_error('Dosya bulunamadı.', 404);
    }

    if (!is_file($abs) || !is_readable($abs)) {
        error_log('[study_resources.download] Missing or unreadable file: ' . $abs);
        api_error('Dosya bulunamadı.', 404);
    }

    if ($inline) {
        $pdo->prepare('UPDATE study_resource_pdfs SET open_count=COALESCE(open_count,0)+1, updated_at=NOW() WHERE id=?')->execute([$pdfId]);
        sr_log_event($pdo, $userId, 'open', $pdfId, null);
    } else {
        $pdo->prepare('UPDATE study_resource_pdfs SET download_count=COALESCE(download_count,0)+1, updated_at=NOW() WHERE id=?')->execute([$pdfId]);
        sr_log_event($pdo, $userId, 'download', $pdfId, null);
    }

    $fileSize = filesize($abs);
    if ($fileSize === false || $fileSize < 0) {
        api_error('Sunucu hatası.', 500);
    }

    if (ob_get_level() > 0) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    header_remove();
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)$fileSize);
    $filename = (string)($pdf['original_file_name'] ?: 'document.pdf');
    $safeFilename = str_replace(['"', "\r", "\n"], '', $filename);
    $dispositionType = $inline ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $dispositionType . '; filename="' . rawurlencode($safeFilename) . '"');
    header('X-Content-Type-Options: nosniff');

    $streamOk = readfile($abs);
    if ($streamOk === false) {
        api_error('Sunucu hatası.', 500);
    }
    exit;
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 100 || $code > 599) {
        $code = 500;
    }

    if (!headers_sent()) {
        if ($code === 401) {
            api_error('Yetkisiz erişim.', 401);
        }
        if ($code === 403) {
            api_error('Erişim yok.', 403);
        }
        if ($code === 404) {
            api_error('Dosya bulunamadı.', 404);
        }
        api_error('Sunucu hatası.', $code === 500 ? 500 : 500);
    }
    exit;
}

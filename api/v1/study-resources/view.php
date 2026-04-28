<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/study_resources_helper.php';

if (ob_get_level() > 0) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    api_require_method('GET');

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        exit;
    }

    $verified = sr_verify_view_token($token);
    if (!is_array($verified)) {
        http_response_code(403);
        exit;
    }
    if (!empty($verified['expired'])) {
        http_response_code(403);
        exit;
    }

    $pdfId = trim((string)($verified['pdf_id'] ?? ''));
    $tokenUserId = trim((string)($verified['user_id'] ?? ''));
    if ($pdfId === '' || $tokenUserId === '') {
        http_response_code(403);
        exit;
    }

    $profileStmt = $pdo->prepare('SELECT current_qualification_id FROM user_profiles WHERE id=? AND COALESCE(is_deleted,0)=0 LIMIT 1');
    $profileStmt->execute([$tokenUserId]);
    $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
    $currentQualificationId = trim((string)($profile['current_qualification_id'] ?? ''));
    if ($currentQualificationId === '') {
        http_response_code(403);
        exit;
    }

    $stmt = $pdo->prepare('SELECT p.*, q.linked_qualification_id FROM study_resource_pdfs p INNER JOIN study_resource_qualifications q ON q.id=p.resource_qualification_id WHERE p.id=? AND p.is_active=1 LIMIT 1');
    $stmt->execute([$pdfId]);
    $pdf = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pdf) {
        http_response_code(404);
        exit;
    }

    if ((string)($pdf['linked_qualification_id'] ?? '') !== $currentQualificationId) {
        http_response_code(403);
        exit;
    }

    $isPremium = function_exists('usage_limits_is_user_pro') ? usage_limits_is_user_pro($pdo, $tokenUserId) : false;
    if ((int)$pdf['is_premium'] === 1 && !$isPremium) {
        http_response_code(403);
        exit;
    }

    $relativePath = trim(str_replace('\\', '/', (string)($pdf['file_path'] ?? '')));
    if (!preg_match('#^study_resources/([a-f0-9\-]{36})\.pdf$#i', $relativePath, $m)) {
        http_response_code(404);
        exit;
    }

    $storedUuid = (string)($m[1] ?? '');
    $expectedRelPath = 'study_resources/' . $storedUuid . '.pdf';
    $abs = sr_upload_root_abs() . '/' . $expectedRelPath;

    if (!is_file($abs) || !is_readable($abs)) {
        http_response_code(404);
        exit;
    }

    $fileSize = filesize($abs);
    if ($fileSize === false || $fileSize < 0) {
        http_response_code(500);
        exit;
    }

    header_remove();
    header('Content-Type: application/pdf');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');

    $rangeHeader = (string)($_SERVER['HTTP_RANGE'] ?? '');
    $start = 0;
    $end = $fileSize - 1;
    if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches)) {
        $rangeStartRaw = $matches[1] ?? '';
        $rangeEndRaw = $matches[2] ?? '';

        if ($rangeStartRaw === '' && $rangeEndRaw === '') {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        if ($rangeStartRaw === '') {
            $suffixLen = (int)$rangeEndRaw;
            if ($suffixLen <= 0) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            $start = max(0, $fileSize - $suffixLen);
            $end = $fileSize - 1;
        } else {
            $start = (int)$rangeStartRaw;
            $end = ($rangeEndRaw === '') ? ($fileSize - 1) : (int)$rangeEndRaw;
        }

        if ($start < 0 || $end < $start || $start >= $fileSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }

        $end = min($end, $fileSize - 1);
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . (string)$length);

    $fp = fopen($abs, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    if ($start > 0) {
        fseek($fp, $start);
    }

    $chunkSize = 1024 * 1024;
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $readLen = (int)min($chunkSize, $remaining);
        $buffer = fread($fp, $readLen);
        if ($buffer === false || $buffer === '') {
            break;
        }
        echo $buffer;
        $remaining -= strlen($buffer);
        flush();
    }

    fclose($fp);
    exit;
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 100 || $code > 599) {
        $code = 500;
    }
    if (!headers_sent()) {
        http_response_code($code);
    }
    exit;
}

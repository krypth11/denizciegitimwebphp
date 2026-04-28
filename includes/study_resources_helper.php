<?php
require_once __DIR__ . '/functions.php';

if (!defined('STUDY_RESOURCES_MAX_FILE_BYTES')) define('STUDY_RESOURCES_MAX_FILE_BYTES', 50 * 1024 * 1024);
if (!defined('STUDY_RESOURCES_UPLOAD_RELATIVE_DIR')) define('STUDY_RESOURCES_UPLOAD_RELATIVE_DIR', 'study_resources');
if (!defined('STUDY_RESOURCES_SHARED_ROOT')) define('STUDY_RESOURCES_SHARED_ROOT', getenv('SHARED_UPLOADS_ROOT') ?: '/home/u2621168/shared_uploads');

function sr_uuid(): string { return generate_uuid(); }
function sr_bool($v): int { return ((int)$v) === 1 ? 1 : 0; }
function sr_clean($v, int $max = 191): string { $t = trim((string)$v); return mb_strlen($t) > $max ? mb_substr($t, 0, $max) : $t; }
function sr_upload_root_abs(): string { return rtrim(str_replace('\\', '/', (string)STUDY_RESOURCES_SHARED_ROOT), '/'); }
function sr_upload_relative_dir(): string { return trim(str_replace('\\', '/', (string)STUDY_RESOURCES_UPLOAD_RELATIVE_DIR), '/'); }
function sr_upload_dir_abs(): string { return sr_upload_root_abs() . '/' . sr_upload_relative_dir(); }
function sr_ensure_upload_dir(): void {
    $dir = sr_upload_dir_abs();
    if (is_file($dir)) throw new RuntimeException('PDF upload dizini bir dosyaya işaret ediyor.');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('PDF upload dizini oluşturulamadı.');
    if (!is_writable($dir)) { @chmod($dir, 0775); clearstatcache(true, $dir); }
    if (!is_writable($dir)) throw new RuntimeException('PDF upload dizini yazılabilir değil.');
}
function sr_pdf_page_count(string $absPath): ?int { $raw = @file_get_contents($absPath); if (!is_string($raw) || $raw === '') return null; preg_match_all('/\/Type\s*\/Page\b/', $raw, $m); $n = count($m[0] ?? []); return $n > 0 ? $n : null; }

function sr_store_pdf_upload(array $file): array
{
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('PDF yüklenemedi.');
    if ((int)($file['size'] ?? 0) > STUDY_RESOURCES_MAX_FILE_BYTES) throw new InvalidArgumentException('PDF boyutu 50MB limitini aşıyor.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('Geçersiz dosya yüklemesi.');
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp));
    if ($mime !== 'application/pdf') throw new InvalidArgumentException('Sadece application/pdf kabul edilir.');
    sr_ensure_upload_dir();
    $stored = sr_uuid() . '.pdf';
    $abs = sr_upload_dir_abs() . '/' . $stored;
    if (!@move_uploaded_file($tmp, $abs)) throw new RuntimeException('Dosya kaydedilemedi.');
    $rel = sr_upload_relative_dir() . '/' . $stored;
    return [
        'original_file_name' => (string)($file['name'] ?? 'document.pdf'),
        'stored_file_name' => $stored,
        'file_path' => $rel,
        'file_url' => '',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => (int)filesize($abs),
        'page_count' => sr_pdf_page_count($abs),
    ];
}

function sr_safe_abs_from_rel(string $rel): ?string
{
    $input = trim(str_replace('\\', '/', $rel));
    if ($input === '' || str_contains($input, '..')) return null;

    $root = realpath(sr_upload_root_abs());
    if ($root === false) return null;

    $relativeDir = sr_upload_relative_dir();
    if (preg_match('#^/[A-Za-z0-9_./\-]+$#', $input) === 1) {
        $candidate = realpath($input);
    } else {
        $normalized = ltrim($input, '/');
        if (!str_starts_with($normalized, $relativeDir . '/')) {
            return null;
        }
        $candidate = realpath(sr_upload_root_abs() . '/' . $normalized);
    }

    if ($candidate === false) return null;

    $candN = str_replace('\\', '/', $candidate);
    $rootN = str_replace('\\', '/', $root);
    return str_starts_with($candN, $rootN . '/') || $candN === $rootN ? $candidate : null;
}

function sr_log_event(PDO $pdo, string $userId, string $eventType, ?string $pdfId = null, ?string $queryText = null): void
{
    $pdo->prepare('INSERT INTO study_resource_user_events (user_id,pdf_id,event_type,query_text,created_at) VALUES (?,?,?,?,NOW())')
        ->execute([$userId, $pdfId, $eventType, $queryText]);
}
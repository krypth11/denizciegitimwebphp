<?php
require_once __DIR__ . '/functions.php';

if (!defined('STUDY_RESOURCES_MAX_FILE_BYTES')) define('STUDY_RESOURCES_MAX_FILE_BYTES', 250 * 1024 * 1024);
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
function sr_pdf_has_magic_header(string $tmpPath): bool {
    $h = @fopen($tmpPath, 'rb');
    if (!$h) return false;
    $bytes = @fread($h, 5);
    @fclose($h);
    return is_string($bytes) && $bytes === '%PDF-';
}

function sr_store_pdf_upload(array $file): array
{
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('PDF yüklenemedi.');
    if ((int)($file['size'] ?? 0) > STUDY_RESOURCES_MAX_FILE_BYTES) throw new InvalidArgumentException('PDF boyutu 250 MB limitini aşıyor.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('Geçersiz dosya yüklemesi.');
    $originalName = (string)($file['name'] ?? 'document.pdf');
    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') throw new InvalidArgumentException('Sadece .pdf uzantılı dosyalar kabul edilir.');
    $mime = strtolower((string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp));
    if ($mime !== 'application/pdf') throw new InvalidArgumentException('Sadece application/pdf kabul edilir.');
    if (!sr_pdf_has_magic_header($tmp)) throw new InvalidArgumentException('Dosya PDF formatında görünmüyor (magic header doğrulaması başarısız).');
    sr_ensure_upload_dir();
    $stored = sr_uuid() . '.pdf';
    $abs = sr_upload_dir_abs() . '/' . $stored;
    if (!@move_uploaded_file($tmp, $abs)) throw new RuntimeException('Dosya kaydedilemedi.');
    $rel = sr_upload_relative_dir() . '/' . $stored;
    return [
        'original_file_name' => $originalName,
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

function sr_file_size_label($bytes): string
{
    $size = (float)$bytes;
    if (!is_finite($size) || $size <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $idx = 0;
    while ($size >= 1024 && $idx < count($units) - 1) {
        $size /= 1024;
        $idx++;
    }

    if ($idx === 0) {
        return (string)round($size) . ' ' . $units[$idx];
    }
    if ($idx === 1) {
        return (string)round($size) . ' ' . $units[$idx];
    }

    return number_format($size, 2, '.', '') . ' ' . $units[$idx];
}

function sr_token_secret(): string
{
    if (defined('STUDY_RESOURCES_VIEW_TOKEN_SECRET') && (string)STUDY_RESOURCES_VIEW_TOKEN_SECRET !== '') {
        return (string)STUDY_RESOURCES_VIEW_TOKEN_SECRET;
    }
    if (defined('JWT_SECRET') && (string)JWT_SECRET !== '') {
        return (string)JWT_SECRET;
    }
    return 'study_resources_view_secret_change_me';
}

function sr_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function sr_b64url_decode(string $data): ?string
{
    $raw = strtr($data, '-_', '+/');
    $pad = strlen($raw) % 4;
    if ($pad > 0) {
        $raw .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($raw, true);
    return is_string($decoded) ? $decoded : null;
}

function sr_generate_view_token(string $pdfId, string $userId, int $ttlSeconds = 600): string
{
    $now = time();
    $payload = [
        'pdf_id' => $pdfId,
        'user_id' => $userId,
        'expires_at' => $now + max(1, $ttlSeconds),
        'iat' => $now,
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        throw new RuntimeException('Token üretilemedi.');
    }

    $payloadEnc = sr_b64url_encode($payloadJson);
    $sig = hash_hmac('sha256', $payloadEnc, sr_token_secret(), true);
    return $payloadEnc . '.' . sr_b64url_encode($sig);
}

function sr_verify_view_token(string $token): ?array
{
    $parts = explode('.', trim($token), 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$payloadEnc, $sigEnc] = $parts;
    if ($payloadEnc === '' || $sigEnc === '') {
        return null;
    }

    $sigBin = sr_b64url_decode($sigEnc);
    if (!is_string($sigBin)) {
        return null;
    }

    $expectedSig = hash_hmac('sha256', $payloadEnc, sr_token_secret(), true);
    if (!hash_equals($expectedSig, $sigBin)) {
        return null;
    }

    $payloadJson = sr_b64url_decode($payloadEnc);
    if (!is_string($payloadJson) || $payloadJson === '') {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    $pdfId = trim((string)($payload['pdf_id'] ?? ''));
    $userId = trim((string)($payload['user_id'] ?? ''));
    $expiresAt = (int)($payload['expires_at'] ?? 0);
    if ($pdfId === '' || $userId === '' || $expiresAt <= 0) {
        return null;
    }

    if ($expiresAt < time()) {
        return ['expired' => true, 'pdf_id' => $pdfId, 'user_id' => $userId, 'expires_at' => $expiresAt];
    }

    return ['expired' => false, 'pdf_id' => $pdfId, 'user_id' => $userId, 'expires_at' => $expiresAt];
}

function sr_settings_defaults(): array
{
    return [
        'premium_auto_cache_enabled' => 1,
        'free_auto_cache_enabled' => 1,
        'premium_offline_access_enabled' => 1,
        'free_offline_access_enabled' => 1,
    ];
}

function sr_normalize_settings(array $row): array
{
    $defaults = sr_settings_defaults();
    $out = [];
    foreach (array_keys($defaults) as $key) {
        $out[$key] = ((int)($row[$key] ?? $defaults[$key]) === 1) ? 1 : 0;
    }
    return $out;
}

function sr_get_settings(PDO $pdo): array
{
    $defaults = sr_settings_defaults();
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'study_resource_settings'");
        $exists = $stmt ? $stmt->fetchColumn() : false;
        if (!$exists) {
            error_log('[study_resources.settings] table not found, using defaults.');
            return $defaults;
        }

        $rowStmt = $pdo->query('SELECT premium_auto_cache_enabled, free_auto_cache_enabled, premium_offline_access_enabled, free_offline_access_enabled FROM study_resource_settings ORDER BY id ASC LIMIT 1');
        $row = $rowStmt ? $rowStmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return $defaults;
        }

        return sr_normalize_settings($row);
    } catch (Throwable $e) {
        error_log('[study_resources.settings] get error=' . $e->getMessage() . ' file=' . $e->getFile() . ' line=' . $e->getLine());
        return $defaults;
    }
}

function sr_update_settings(PDO $pdo, array $input): array
{
    $defaults = sr_settings_defaults();
    $incoming = sr_normalize_settings($input + $defaults);

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'study_resource_settings'");
        $exists = $stmt ? $stmt->fetchColumn() : false;
        if (!$exists) {
            error_log('[study_resources.settings] update skipped, table not found.');
            return $defaults;
        }

        $current = sr_get_settings($pdo);
        $next = [];
        foreach (array_keys($defaults) as $key) {
            $next[$key] = array_key_exists($key, $input) ? $incoming[$key] : (int)$current[$key];
        }

        $idStmt = $pdo->query('SELECT id FROM study_resource_settings ORDER BY id ASC LIMIT 1');
        $id = $idStmt ? (int)$idStmt->fetchColumn() : 0;
        if ($id > 0) {
            $upd = $pdo->prepare('UPDATE study_resource_settings SET premium_auto_cache_enabled=?, free_auto_cache_enabled=?, premium_offline_access_enabled=?, free_offline_access_enabled=?, updated_at=NOW() WHERE id=?');
            $upd->execute([
                $next['premium_auto_cache_enabled'],
                $next['free_auto_cache_enabled'],
                $next['premium_offline_access_enabled'],
                $next['free_offline_access_enabled'],
                $id,
            ]);
        } else {
            $ins = $pdo->prepare('INSERT INTO study_resource_settings (premium_auto_cache_enabled, free_auto_cache_enabled, premium_offline_access_enabled, free_offline_access_enabled, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())');
            $ins->execute([
                $next['premium_auto_cache_enabled'],
                $next['free_auto_cache_enabled'],
                $next['premium_offline_access_enabled'],
                $next['free_offline_access_enabled'],
            ]);
        }

        return sr_get_settings($pdo);
    } catch (Throwable $e) {
        error_log('[study_resources.settings] update error=' . $e->getMessage() . ' file=' . $e->getFile() . ' line=' . $e->getLine());
        return sr_get_settings($pdo);
    }
}
<?php

require_once __DIR__ . '/functions.php';

function upload_project_root(): string
{
    return dirname(__DIR__);
}

function upload_public_prefix(): string
{
    $prefix = trim((string)(defined('UPLOADS_PUBLIC_PREFIX') ? UPLOADS_PUBLIC_PREFIX : (getenv('UPLOADS_PUBLIC_PREFIX') ?: 'uploads')));
    $prefix = trim(str_replace('\\', '/', $prefix), '/');
    return $prefix !== '' ? $prefix : 'uploads';
}

function upload_shared_root_abs(): string
{
    $root = trim((string)(defined('SHARED_UPLOADS_ROOT') ? SHARED_UPLOADS_ROOT : (getenv('SHARED_UPLOADS_ROOT') ?: '')));
    if ($root === '') {
        $root = upload_project_root() . DIRECTORY_SEPARATOR . 'uploads';
    }

    return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
}

function upload_sanitize_relative_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $safe = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }
        $safe[] = $part;
    }

    return implode('/', $safe);
}

function upload_ensure_directory_ready(string $dir): void
{
    clearstatcache(true, $dir);
    if (is_file($dir)) {
        throw new RuntimeException('Upload dizini bir dosyaya işaret ediyor.');
    }

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload dizini oluşturulamadı.');
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0775);
        clearstatcache(true, $dir);
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('Upload dizini yazılabilir değil.');
    }
}

function upload_module_base_paths(string $module): array
{
    $module = upload_sanitize_relative_path($module);
    if ($module === '') {
        throw new InvalidArgumentException('Geçersiz modül adı.');
    }

    $baseAbs = upload_shared_root_abs() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $module);
    $baseRel = upload_public_prefix() . '/' . $module;

    upload_ensure_directory_ready($baseAbs);

    return [
        'module' => $module,
        'base_abs' => $baseAbs,
        'base_rel' => $baseRel,
    ];
}

function upload_build_public_url(string $relativePath): string
{
    $relativePath = upload_sanitize_relative_path($relativePath);
    if ($relativePath === '') {
        return '';
    }

    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        ? 'https'
        : 'http';

    if ($host === '') {
        return rtrim((string)SITE_URL, '/') . '/' . $relativePath;
    }

    return $scheme . '://' . $host . '/' . $relativePath;
}

function upload_extract_relative_path_from_url_or_path(?string $urlOrPath): string
{
    $value = trim((string)$urlOrPath);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        $value = (string)parse_url($value, PHP_URL_PATH);
    }

    return upload_sanitize_relative_path(ltrim(str_replace('\\', '/', $value), '/'));
}

function upload_relative_path_to_abs(string $relativePath): string
{
    $relativePath = upload_extract_relative_path_from_url_or_path($relativePath);
    if ($relativePath === '') {
        return '';
    }

    $prefix = upload_public_prefix();
    $withPrefix = ($relativePath === $prefix) || (strpos($relativePath, $prefix . '/') === 0);
    if (!$withPrefix) {
        return '';
    }

    $tail = upload_sanitize_relative_path(substr($relativePath, strlen($prefix)) ?: '');
    if ($tail === '') {
        return '';
    }

    return upload_shared_root_abs() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $tail);
}

function upload_safe_delete(?string $urlOrPath, string $module): void
{
    $module = upload_sanitize_relative_path($module);
    if ($module === '') {
        return;
    }

    $relativePath = upload_extract_relative_path_from_url_or_path($urlOrPath);
    if ($relativePath === '') {
        return;
    }

    $modulePrefix = upload_public_prefix() . '/' . $module . '/';
    if (strpos($relativePath, $modulePrefix) !== 0) {
        return;
    }

    $abs = upload_relative_path_to_abs($relativePath);
    if ($abs !== '' && is_file($abs)) {
        @unlink($abs);
    }
}

function upload_validate_image_file(array $file, int $maxBytes = 6291456): array
{
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Görsel yüklenemedi.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Geçersiz dosya yüklemesi.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Görsel boyutu sınırı aşıyor.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Sadece JPG, PNG veya WEBP görseller kabul edilir.');
    }

    $dim = @getimagesize($tmp);
    if (!is_array($dim) || (int)($dim[0] ?? 0) < 1 || (int)($dim[1] ?? 0) < 1) {
        throw new RuntimeException('Geçersiz görsel dosyası.');
    }

    return [
        'tmp' => $tmp,
        'size' => $size,
        'mime' => $mime,
        'ext' => $allowed[$mime],
        'width' => (int)$dim[0],
        'height' => (int)$dim[1],
    ];
}

function upload_generate_unique_filename(string $prefix, string $ext): string
{
    $prefix = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower(trim($prefix))) ?: 'file';
    $prefix = trim($prefix, '-');
    if ($prefix === '') {
        $prefix = 'file';
    }

    $uuid = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
    $uuid = str_replace('-', '', $uuid);

    return $prefix . '-' . $uuid . '.' . strtolower($ext);
}

function upload_store_image_file(string $module, string $subDir, array $file, array $options = []): array
{
    $paths = upload_module_base_paths($module);
    $safeSubDir = upload_sanitize_relative_path($subDir);
    $targetAbsDir = $paths['base_abs'] . ($safeSubDir !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeSubDir) : '');
    upload_ensure_directory_ready($targetAbsDir);

    $maxBytes = (int)($options['max_bytes'] ?? 6291456);
    $filenamePrefix = (string)($options['filename_prefix'] ?? 'image');

    $validated = upload_validate_image_file($file, $maxBytes);
    $filename = upload_generate_unique_filename($filenamePrefix, $validated['ext']);
    $targetAbs = $targetAbsDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($validated['tmp'], $targetAbs)) {
        throw new RuntimeException('Görsel dosyası taşınamadı.');
    }

    $relativeDir = $paths['base_rel'] . ($safeSubDir !== '' ? '/' . $safeSubDir : '');
    $relativePath = upload_sanitize_relative_path($relativeDir . '/' . $filename);
    $publicUrl = upload_build_public_url($relativePath);

    return [
        'relative_path' => $relativePath,
        'public_url' => $publicUrl,
        'abs_path' => $targetAbs,
        'mime' => $validated['mime'],
        'size' => $validated['size'],
        'width' => $validated['width'],
        'height' => $validated['height'],
    ];
}
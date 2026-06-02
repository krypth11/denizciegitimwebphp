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

function upload_create_webp_variant(
    string $sourceAbs,
    string $targetAbs,
    int $targetWidth,
    int $targetHeight,
    int $quality = 82
): void {
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('Sunucuda WebP üretimi desteklenmiyor.');
    }

    $dim = @getimagesize($sourceAbs);
    if (!is_array($dim) || empty($dim['mime'])) {
        throw new RuntimeException('Kaynak görsel doğrulanamadı.');
    }

    $mime = (string)$dim['mime'];
    if ($mime === 'image/jpeg') {
        $src = @imagecreatefromjpeg($sourceAbs);
    } elseif ($mime === 'image/png') {
        $src = @imagecreatefrompng($sourceAbs);
    } elseif ($mime === 'image/webp') {
        if (!function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('Sunucuda WebP üretimi desteklenmiyor.');
        }
        $src = @imagecreatefromwebp($sourceAbs);
    } else {
        throw new RuntimeException('Desteklenmeyen kaynak görsel türü.');
    }

    if (!$src) {
        throw new RuntimeException('Kaynak görsel açılamadı.');
    }

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$target) {
        imagedestroy($src);
        throw new RuntimeException('Hedef görsel oluşturulamadı.');
    }

    imagealphablending($target, true);
    imagesavealpha($target, false);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);

    $srcW = (int)imagesx($src);
    $srcH = (int)imagesy($src);
    imagecopyresampled($target, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);

    $dir = dirname($targetAbs);
    upload_ensure_directory_ready($dir);

    $ok = @imagewebp($target, $targetAbs, max(0, min(100, $quality)));
    imagedestroy($target);
    imagedestroy($src);

    if (!$ok) {
        throw new RuntimeException('WebP dosyası kaydedilemedi.');
    }
}

function upload_create_webp_fit_variant(
    string $sourceAbs,
    string $targetAbs,
    int $maxWidth,
    int $maxHeight,
    int $quality = 82
): array {
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('Sunucuda WebP üretimi desteklenmiyor.');
    }

    $dim = @getimagesize($sourceAbs);
    if (!is_array($dim) || empty($dim['mime'])) {
        throw new RuntimeException('Kaynak görsel doğrulanamadı.');
    }

    $srcW = max(1, (int)($dim[0] ?? 0));
    $srcH = max(1, (int)($dim[1] ?? 0));
    $mime = (string)$dim['mime'];

    if ($mime === 'image/jpeg') {
        $src = @imagecreatefromjpeg($sourceAbs);
    } elseif ($mime === 'image/png') {
        $src = @imagecreatefrompng($sourceAbs);
    } elseif ($mime === 'image/webp') {
        if (!function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('Sunucuda WebP üretimi desteklenmiyor.');
        }
        $src = @imagecreatefromwebp($sourceAbs);
    } else {
        throw new RuntimeException('Desteklenmeyen kaynak görsel türü.');
    }

    if (!$src) {
        throw new RuntimeException('Kaynak görsel açılamadı.');
    }

    $scale = min(1.0, $maxWidth / $srcW, $maxHeight / $srcH);
    $targetWidth = max(1, (int)floor($srcW * $scale));
    $targetHeight = max(1, (int)floor($srcH * $scale));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$target) {
        imagedestroy($src);
        throw new RuntimeException('Hedef görsel oluşturulamadı.');
    }

    imagealphablending($target, true);
    imagesavealpha($target, false);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);

    imagecopyresampled($target, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcW, $srcH);

    upload_ensure_directory_ready(dirname($targetAbs));
    $ok = @imagewebp($target, $targetAbs, max(0, min(100, $quality)));

    imagedestroy($target);
    imagedestroy($src);

    if (!$ok) {
        throw new RuntimeException('WebP dosyası kaydedilemedi.');
    }

    return [
        'width' => $targetWidth,
        'height' => $targetHeight,
    ];
}

function upload_store_image_fit_variants(
    string $module,
    string $subDir,
    array $file,
    string $filenamePrefix,
    array $variants
): array {
    $paths = upload_module_base_paths($module);
    $safeSubDir = upload_sanitize_relative_path($subDir);
    $targetAbsDir = $paths['base_abs'] . ($safeSubDir !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeSubDir) : '');
    upload_ensure_directory_ready($targetAbsDir);

    $validated = upload_validate_image_file($file, 6 * 1024 * 1024);
    $uuid = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
    $uuid = str_replace('-', '', (string)$uuid);
    $safePrefix = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower(trim($filenamePrefix))) ?: 'image';
    $safePrefix = trim($safePrefix, '-') ?: 'image';
    $relativeDir = $paths['base_rel'] . ($safeSubDir !== '' ? '/' . $safeSubDir : '');

    $saved = [];
    try {
        foreach ($variants as $name => $spec) {
            $maxWidth = (int)($spec['max_width'] ?? $spec['width'] ?? 0);
            $maxHeight = (int)($spec['max_height'] ?? $spec['height'] ?? 0);
            $quality = (int)($spec['quality'] ?? 82);
            if ($maxWidth < 1 || $maxHeight < 1) {
                throw new RuntimeException('Variant ölçüleri geçersiz.');
            }

            $safeName = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string)$name)) ?: 'variant';
            $filename = $safePrefix . '-' . $uuid . '-' . trim($safeName, '-') . '.webp';
            $targetAbs = $targetAbsDir . DIRECTORY_SEPARATOR . $filename;
            $size = upload_create_webp_fit_variant($validated['tmp'], $targetAbs, $maxWidth, $maxHeight, $quality);
            $relativePath = upload_sanitize_relative_path($relativeDir . '/' . $filename);

            $saved[$name] = [
                'public_url' => upload_build_public_url($relativePath),
                'relative_path' => $relativePath,
                'abs_path' => $targetAbs,
                'width' => $size['width'],
                'height' => $size['height'],
            ];
        }
    } catch (Throwable $e) {
        foreach ($saved as $item) {
            upload_safe_delete((string)($item['relative_path'] ?? ''), $module);
        }
        throw $e;
    }

    return $saved;
}

function upload_store_image_variants(
    string $module,
    string $subDir,
    array $file,
    array $variants
): array {
    $paths = upload_module_base_paths($module);
    $safeSubDir = upload_sanitize_relative_path($subDir);
    $targetAbsDir = $paths['base_abs'] . ($safeSubDir !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safeSubDir) : '');
    upload_ensure_directory_ready($targetAbsDir);

    $validated = upload_validate_image_file($file, 6 * 1024 * 1024);

    $uuid = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
    $uuid = str_replace('-', '', (string)$uuid);

    $saved = [];
    try {
        foreach ($variants as $name => $spec) {
            $width = (int)($spec['width'] ?? 0);
            $height = (int)($spec['height'] ?? 0);
            $quality = (int)($spec['quality'] ?? 82);
            if ($width < 1 || $height < 1) {
                throw new RuntimeException('Variant ölçüleri geçersiz.');
            }

            $filename = 'kart-game-' . $uuid . '-' . preg_replace('/[^a-z0-9\-]+/i', '-', (string)$name) . '.webp';
            $targetAbs = $targetAbsDir . DIRECTORY_SEPARATOR . $filename;

            upload_create_webp_variant($validated['tmp'], $targetAbs, $width, $height, $quality);

            $relativeDir = $paths['base_rel'] . ($safeSubDir !== '' ? '/' . $safeSubDir : '');
            $relativePath = upload_sanitize_relative_path($relativeDir . '/' . $filename);
            $saved[$name] = [
                'public_url' => upload_build_public_url($relativePath),
                'relative_path' => $relativePath,
                'abs_path' => $targetAbs,
            ];
        }
    } catch (Throwable $e) {
        foreach ($saved as $item) {
            upload_safe_delete((string)($item['relative_path'] ?? ''), $module);
            upload_safe_delete((string)($item['public_url'] ?? ''), $module);
        }
        throw $e;
    }

    return $saved;
}
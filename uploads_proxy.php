<?php

function uploads_proxy_forbidden(): void
{
    http_response_code(404);
    exit;
}

function uploads_proxy_clean_path(string $path): string
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

$requested = (string)($_GET['path'] ?? '');
$relative = uploads_proxy_clean_path($requested);

if ($relative === '') {
    uploads_proxy_forbidden();
}

$isStoriesPath = ($relative === 'stories') || (strpos($relative, 'stories/') === 0);
$isKartOyunuPath = ($relative === 'kart-oyunu') || (strpos($relative, 'kart-oyunu/') === 0);

if (!$isStoriesPath && !$isKartOyunuPath) {
    uploads_proxy_forbidden();
}

$root = rtrim(str_replace('\\', '/', (string)(getenv('SHARED_UPLOADS_ROOT') ?: '/home/u2621168/shared_uploads')), '/');
$fullPath = $root . '/' . $relative;

if (!is_file($fullPath) || !is_readable($fullPath)) {
    uploads_proxy_forbidden();
}

$ext = strtolower((string)pathinfo($fullPath, PATHINFO_EXTENSION));
$allowed = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'avif' => 'image/avif',
];

if (!isset($allowed[$ext])) {
    uploads_proxy_forbidden();
}

$mime = $allowed[$ext];
$size = filesize($fullPath);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=604800, immutable');
if ($size !== false) {
    header('Content-Length: ' . (string)$size);
}

readfile($fullPath);
exit;

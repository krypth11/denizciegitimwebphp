<?php

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/response_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function api_require_method(string $method): void
{
    $current = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($current !== strtoupper($method)) {
        api_error('Method not allowed.', 405);
    }
}

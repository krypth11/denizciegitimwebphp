<?php

function api_send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        $json = '{"success":false,"message":"JSON encode hatası."}';
    }
    echo $json;
    exit;
}

function api_success(string $message, array $data = [], int $status = 200): void
{
    api_send_json([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], $status);
}

function api_error(string $message, int $status = 400): void
{
    api_send_json([
        'success' => false,
        'message' => $message,
        'data' => null,
    ], $status);
}

function api_get_request_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                api_error('Geçersiz JSON body.', 400);
            }
            return $decoded;
        } catch (Throwable $e) {
            api_error('Geçersiz JSON body.', 400);
        }
    }

    // PUT/PATCH gibi methodlarda form-urlencoded gövde desteği
    if ($method !== 'GET' && $method !== 'POST') {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $data = [];
            parse_str($raw, $data);
            if (is_array($data) && !empty($data)) {
                return $data;
            }
        }
    }

    return $_POST ?: [];
}

function api_require_query_param(string $key, int $maxLen = 191): string
{
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '') {
        api_error($key . ' parametresi zorunludur.', 422);
    }

    if ($maxLen > 0 && mb_strlen($value) > $maxLen) {
        api_error('Geçersiz ' . $key . '.', 422);
    }

    return $value;
}

function api_get_int_query(string $key, int $default, int $min, int $max): int
{
    $value = filter_var($_GET[$key] ?? $default, FILTER_VALIDATE_INT, [
        'options' => [
            'default' => $default,
            'min_range' => $min,
            'max_range' => $max,
        ],
    ]);

    return (int)$value;
}

function api_validate_optional_id(string $value, string $key, int $maxLen = 191): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if ($maxLen > 0 && mb_strlen($trimmed) > $maxLen) {
        api_error('Geçersiz ' . $key . '.', 422);
    }

    return $trimmed;
}

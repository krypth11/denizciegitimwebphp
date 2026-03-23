<?php

function api_send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
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
    ], $status);
}

function api_get_request_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
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

    return $_POST ?: [];
}

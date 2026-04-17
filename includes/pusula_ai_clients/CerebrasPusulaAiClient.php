<?php

require_once __DIR__ . '/PusulaAiClientInterface.php';

class CerebrasPusulaAiClient implements PusulaAiClientInterface
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function testConnection(): array
    {
        $provider = strtolower(trim((string)($this->settings['provider'] ?? 'cerebras')));
        $model = trim((string)($this->settings['model'] ?? 'llama3.1-8b'));
        $apiKey = trim((string)($this->settings['api_key'] ?? ''));
        if ($apiKey === '') {
            return $this->result(false, 'API key gerekli.', $provider, $model);
        }

        $baseUrl = rtrim((string)($this->settings['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://api.cerebras.ai/v1';
        }

        $endpoint = $baseUrl . '/chat/completions';
        $timeout = max(5, (int)($this->settings['timeout_seconds'] ?? 30));

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'Ping'],
            ],
            'max_tokens' => 1,
            'temperature' => 0,
        ];

        return $this->postJson($endpoint, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], $payload, $timeout, $provider, $model);
    }

    private function postJson(string $url, array $headers, array $payload, int $timeout, string $provider, string $model): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return $this->result(false, 'Bağlantı hatası: ' . $curlError, $provider, $model);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->result(true, 'Bağlantı başarılı.', $provider, $model);
        }

        $decoded = json_decode((string)$raw, true);
        $detail = trim((string)($decoded['error']['message'] ?? $decoded['message'] ?? ''));
        $safeMessage = $detail !== '' ? $detail : 'Sağlayıcı isteği başarısız oldu.';

        return $this->result(false, 'Cerebras test hatası (HTTP ' . $httpCode . '): ' . $safeMessage, $provider, $model);
    }

    private function result(bool $success, string $message, string $provider, string $model): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'provider' => $provider,
            'model' => $model,
        ];
    }
}

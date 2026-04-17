<?php

require_once __DIR__ . '/PusulaAiClientInterface.php';

class GeminiPusulaAiClient implements PusulaAiClientInterface
{
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function testConnection(): array
    {
        $provider = strtolower(trim((string)($this->settings['provider'] ?? 'gemini')));
        $model = trim((string)($this->settings['model'] ?? 'gemini-2.5-flash'));
        $apiKey = trim((string)($this->settings['api_key'] ?? ''));
        if ($apiKey === '') {
            return $this->result(false, 'API key gerekli.', $provider, $model);
        }

        $baseUrl = rtrim((string)($this->settings['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        }

        $endpoint = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $timeout = max(5, (int)($this->settings['timeout_seconds'] ?? 30));

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Ping'],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 1,
            ],
        ];

        return $this->postJson($endpoint, ['Content-Type: application/json'], $payload, $timeout, $provider, $model);
    }

    public function generateChatReply(array $messages, array $options = []): array
    {
        $provider = strtolower(trim((string)($this->settings['provider'] ?? 'gemini')));
        $model = trim((string)($this->settings['model'] ?? 'gemini-2.5-flash'));
        $apiKey = trim((string)($this->settings['api_key'] ?? ''));
        if ($apiKey === '') {
            return $this->chatResult(false, '', 0, 0, null, 'api_key_missing', 'API key gerekli.');
        }

        $baseUrl = rtrim((string)($this->settings['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
        }

        $endpoint = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        $timeout = max(5, (int)($this->settings['timeout_seconds'] ?? 30));
        $temperature = isset($options['temperature']) ? (float)$options['temperature'] : (float)($this->settings['temperature'] ?? 0.3);
        $maxTokens = isset($options['max_tokens']) ? (int)$options['max_tokens'] : (int)($this->settings['max_tokens'] ?? 800);

        $contents = [];
        foreach ($messages as $m) {
            $role = strtolower(trim((string)($m['role'] ?? 'user')));
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $geminiRole = ($role === 'assistant' || $role === 'model') ? 'model' : 'user';
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [
                    ['text' => $content],
                ],
            ];
        }

        if (!$contents) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Merhaba']],
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => max(0, min(1, $temperature)),
                'maxOutputTokens' => max(32, min(4096, $maxTokens)),
            ],
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return $this->chatResult(false, '', 0, 0, ['http_code' => $httpCode, 'curl_error' => $curlError], 'network_error', $curlError);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return $this->chatResult(false, '', 0, 0, ['http_code' => $httpCode, 'raw' => $raw], 'invalid_response', 'Sağlayıcı yanıtı okunamadı.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $err = trim((string)($decoded['error']['message'] ?? $decoded['message'] ?? 'Sağlayıcı isteği başarısız oldu.'));
            return $this->chatResult(false, '', 0, 0, ['http_code' => $httpCode, 'raw' => $decoded], $this->httpToErrorCode($httpCode), $err);
        }

        $reply = '';
        if (!empty($decoded['candidates'][0]['content']['parts']) && is_array($decoded['candidates'][0]['content']['parts'])) {
            foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
                $reply .= (string)($part['text'] ?? '');
            }
        }
        $reply = trim($reply);
        if ($reply === '') {
            return $this->chatResult(false, '', 0, 0, ['http_code' => $httpCode, 'raw' => $decoded], 'empty_reply', 'Boş yanıt alındı.');
        }

        $inputTokens = (int)($decoded['usageMetadata']['promptTokenCount'] ?? 0);
        $outputTokens = (int)($decoded['usageMetadata']['candidatesTokenCount'] ?? 0);

        return $this->chatResult(true, $reply, $inputTokens, $outputTokens, $decoded);
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

        return $this->result(false, 'Gemini test hatası (HTTP ' . $httpCode . '): ' . $safeMessage, $provider, $model);
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

    private function chatResult(bool $success, string $reply, int $inputTokens, int $outputTokens, $raw, string $errorCode = '', string $errorMessage = ''): array
    {
        $result = [
            'success' => $success,
            'reply' => $reply,
            'input_tokens' => max(0, $inputTokens),
            'output_tokens' => max(0, $outputTokens),
            'raw' => $raw,
        ];

        if (!$success) {
            $result['error_code'] = $errorCode !== '' ? $errorCode : 'provider_error';
            $result['error_message'] = $errorMessage !== '' ? $errorMessage : 'Sağlayıcı hatası.';
        }

        return $result;
    }

    private function httpToErrorCode(int $httpCode): string
    {
        if ($httpCode === 401 || $httpCode === 403) {
            return 'auth_error';
        }
        if ($httpCode === 408 || $httpCode === 504) {
            return 'timeout';
        }
        if ($httpCode === 429) {
            return 'quota_error';
        }
        if ($httpCode >= 500) {
            return 'provider_down';
        }

        return 'provider_error';
    }
}

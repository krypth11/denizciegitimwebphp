<?php

require_once __DIR__ . '/functions.php';

function ai_review_supported_models()
{
    return [
        'claude' => [
            'claude-3-7-sonnet-latest',
            'claude-3-5-sonnet-latest',
            'claude-3-5-haiku-latest',
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307',
        ],
        'openai' => [
            'gpt-4.1',
            'gpt-4.1-mini',
            'gpt-4.1-nano',
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-4o-2024-11-20',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo',
            'o1',
            'o1-mini',
            'o3-mini',
        ],
        'gemini' => [
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.0-pro-exp',
            'gemini-2.0-flash-exp',
            'gemini-1.5-pro',
            'gemini-1.5-flash',
            'gemini-1.5-flash-8b',
            'gemini-exp-1206',
        ],
        'groq' => [
            'llama-3.3-70b-versatile',
            'llama-3.1-70b-versatile',
            'llama-3.1-8b-instant',
            'llama-guard-3-8b',
            'mixtral-8x7b-32768',
            'gemma2-9b-it',
            'qwen-2.5-32b',
            'qwen-2.5-coder-32b',
            'deepseek-r1-distill-llama-70b',
        ],
        'cerebras' => [
            'llama3.1-8b',
            'qwen-3-235b-a22b-instruct-2507',
        ],
    ];
}

function ai_review_validate_provider_model($provider, $model)
{
    $supported = ai_review_supported_models();
    if (!isset($supported[$provider])) {
        throw new RuntimeException('Desteklenmeyen AI provider: ' . $provider);
    }

    $model = trim((string)$model);
    if ($model === '') {
        throw new RuntimeException('AI model seçimi boş olamaz.');
    }

    if (!in_array($model, $supported[$provider], true)) {
        if ($provider === 'groq') {
            throw new RuntimeException('Groq için seçilen model desteklenmiyor: ' . $model);
        }
        if ($provider === 'cerebras') {
            throw new RuntimeException('Cerebras için seçilen model desteklenmiyor: ' . $model);
        }
        throw new RuntimeException(strtoupper($provider) . ' için seçilen model desteklenmiyor: ' . $model);
    }
}

function ai_review_effective_max_tokens($provider, $maxTokens)
{
    $maxTokens = max(1, (int)$maxTokens);

    // Review akışı kısa/structured JSON döndürdüğü için Cerebras tarafında güvenli üst sınır uygula.
    if ($provider === 'cerebras') {
        return min(2048, $maxTokens);
    }

    return $maxTokens;
}

function ai_review_safe_excerpt($text, $maxLen = 260)
{
    $text = trim((string)$text);
    $text = preg_replace('/\s+/u', ' ', $text);
    if ($text === '') return '';
    if (mb_strlen($text, 'UTF-8') > $maxLen) {
        return mb_substr($text, 0, $maxLen, 'UTF-8') . '…';
    }
    return $text;
}

function ai_review_extract_error_detail($rawBody, $curlErr = '')
{
    $curlErr = ai_review_safe_excerpt($curlErr, 180);
    if ($curlErr !== '') {
        return $curlErr;
    }

    $rawBody = (string)$rawBody;
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $candidate = '';
        if (!empty($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            $candidate = $decoded['error']['message'];
        } elseif (!empty($decoded['message']) && is_string($decoded['message'])) {
            $candidate = $decoded['message'];
        } elseif (!empty($decoded['error']) && is_string($decoded['error'])) {
            $candidate = $decoded['error'];
        }

        $candidate = ai_review_safe_excerpt($candidate, 240);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return ai_review_safe_excerpt($rawBody, 240);
}

function ai_review_json($success, $message = '', $data = [], $status = 200)
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function ai_review_table_exists(PDO $pdo, $table)
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([DB_NAME, $table]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ai_review_pick_col(array $cols, array $candidates, $required = false)
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $cols, true)) return $candidate;
    }
    if ($required) {
        throw new RuntimeException('Missing required column: ' . implode(', ', $candidates));
    }
    return null;
}

function ai_review_settings(PDO $pdo)
{
    $stmt = $pdo->query('SELECT * FROM admin_settings ORDER BY updated_at DESC, created_at DESC LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $provider = strtolower(trim((string)($row['ai_provider'] ?? 'groq')));
    if (!in_array($provider, ['groq', 'openai', 'claude', 'gemini', 'cerebras'], true)) {
        $provider = 'groq';
    }

    $defaultModelByProvider = [
        'groq' => 'llama-3.3-70b-versatile',
        'openai' => 'gpt-4o',
        'claude' => 'claude-sonnet-4-20250514',
        'gemini' => 'gemini-2.5-flash',
        'cerebras' => 'llama3.1-8b',
    ];

    $model = trim((string)($row['ai_model'] ?? ''));
    if ($model === '') {
        $model = $defaultModelByProvider[$provider] ?? 'llama-3.3-70b-versatile';
    }

    return [
        'provider' => $provider,
        'model' => $model,
        'api_key' => trim((string)($row['api_key'] ?? '')),
        'max_tokens' => max(1, (int)($row['max_tokens'] ?? 1200)),
        'temperature' => min(1, max(0, (float)($row['temperature'] ?? 0.7))),
    ];
}

function ai_review_http_json($url, array $headers, array $payload, $timeout = 90)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [$httpCode, $response, $curlErr];
}

function ai_review_extract_json_text($text)
{
    $text = trim((string)$text);
    if ($text === '') return '{}';

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return '{}';
    }
    return substr($text, $start, $end - $start + 1);
}

function ai_review_prompt(array $q)
{
    $payload = [
        'question_text' => (string)($q['question_text'] ?? ''),
        'option_a' => (string)($q['option_a'] ?? ''),
        'option_b' => (string)($q['option_b'] ?? ''),
        'option_c' => (string)($q['option_c'] ?? ''),
        'option_d' => (string)($q['option_d'] ?? ''),
        'option_e' => (string)($q['option_e'] ?? ''),
        'correct_answer' => (string)($q['correct_answer'] ?? ''),
        'explanation' => (string)($q['explanation'] ?? ''),
        'qualification_name' => (string)($q['qualification_name'] ?? ''),
        'course_name' => (string)($q['course_name'] ?? ''),
    ];

    return "Aşağıdaki denizcilik çoktan seçmeli soruyu ön denetim için değerlendir. Son karar admin verecek.\n"
        . "Kontroller: doğru cevap tutarlılığı, soru netliği, şık kalitesi, açıklama kalitesi, biçimsel sorunlar.\n"
        . "Sadece parse edilebilir JSON döndür.\n"
        . "Şema:\n"
        . "{\n"
        . "  \"ai_status\": \"ok|warning|error\",\n"
        . "  \"issue_types\": [\"...\"],\n"
        . "  \"confidence_score\": 0,\n"
        . "  \"ai_notes\": \"kısa açıklama\",\n"
        . "  \"suggested_fix\": \"kısa öneri\"\n"
        . "}\n\n"
        . "Soru verisi:\n"
        . json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function ai_review_call_ai(array $settings, array $question)
{
    $provider = $settings['provider'];
    $model = $settings['model'];
    $apiKey = $settings['api_key'];
    $maxTokens = ai_review_effective_max_tokens($provider, $settings['max_tokens']);
    $temperature = $settings['temperature'];
    $prompt = ai_review_prompt($question);
    $systemText = 'Sen denizcilik eğitim soruları için kalite kontrol uzmanısın. Sadece JSON döndür.';

    if ($apiKey === '') {
        throw new RuntimeException('AI API key tanımlı değil.');
    }

    ai_review_validate_provider_model($provider, $model);

    $contentText = '';
    if ($provider === 'openai' || $provider === 'groq' || $provider === 'cerebras') {
        $endpoint = $provider === 'openai'
            ? 'https://api.openai.com/v1/chat/completions'
            : ($provider === 'groq'
                ? 'https://api.groq.com/openai/v1/chat/completions'
                : 'https://api.cerebras.ai/v1/chat/completions');

        [$httpCode, $raw, $curlErr] = ai_review_http_json($endpoint, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemText],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'response_format' => ['type' => 'json_object'],
        ]);

        if ($httpCode !== 200 || $curlErr) {
            $detail = ai_review_extract_error_detail($raw, $curlErr);
            $msg = 'AI API hatası (' . $provider . '): HTTP ' . $httpCode;
            if ($detail !== '') $msg .= ' - ' . $detail;
            throw new RuntimeException($msg);
        }

        $decoded = json_decode((string)$raw, true);
        $contentText = (string)($decoded['choices'][0]['message']['content'] ?? '');
        if ($contentText === '') {
            $detail = ai_review_extract_error_detail($raw, '');
            throw new RuntimeException('AI API hatası (' . $provider . '): Yanıt içeriği boş. ' . $detail);
        }
    } elseif ($provider === 'claude') {
        [$httpCode, $raw, $curlErr] = ai_review_http_json('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ], [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'system' => $systemText,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        if ($httpCode !== 200 || $curlErr) {
            $detail = ai_review_extract_error_detail($raw, $curlErr);
            $msg = 'AI API hatası (claude): HTTP ' . $httpCode;
            if ($detail !== '') $msg .= ' - ' . $detail;
            throw new RuntimeException($msg);
        }

        $decoded = json_decode((string)$raw, true);
        $contentText = (string)($decoded['content'][0]['text'] ?? '');
    } else {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
        [$httpCode, $raw, $curlErr] = ai_review_http_json($url, [
            'Content-Type: application/json',
        ], [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $systemText . "\n\n" . $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ]);

        if ($httpCode !== 200 || $curlErr) {
            $detail = ai_review_extract_error_detail($raw, $curlErr);
            $msg = 'AI API hatası (gemini): HTTP ' . $httpCode;
            if ($detail !== '') $msg .= ' - ' . $detail;
            throw new RuntimeException($msg);
        }

        $decoded = json_decode((string)$raw, true);
        $contentText = (string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    $parsed = json_decode(ai_review_extract_json_text($contentText), true);
    if (!is_array($parsed)) $parsed = [];

    $status = strtolower(trim((string)($parsed['ai_status'] ?? 'warning')));
    if (!in_array($status, ['ok', 'warning', 'error'], true)) $status = 'warning';

    $issues = $parsed['issue_types'] ?? [];
    if (!is_array($issues)) {
        $issues = $issues ? [(string)$issues] : [];
    }
    $issues = array_values(array_filter(array_map(static function ($v) {
        return trim((string)$v);
    }, $issues)));

    $confidence = (int)($parsed['confidence_score'] ?? 0);
    $confidence = max(0, min(100, $confidence));

    return [
        'ai_status' => $status,
        'issue_types' => $issues,
        'confidence_score' => $confidence,
        'ai_notes' => trim((string)($parsed['ai_notes'] ?? '')),
        'suggested_fix' => trim((string)($parsed['suggested_fix'] ?? '')),
    ];
}

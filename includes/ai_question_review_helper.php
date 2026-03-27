<?php

require_once __DIR__ . '/functions.php';

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
    if (!in_array($provider, ['groq', 'openai', 'claude', 'gemini'], true)) {
        $provider = 'groq';
    }

    return [
        'provider' => $provider,
        'model' => trim((string)($row['ai_model'] ?? 'llama-3.3-70b-versatile')),
        'api_key' => trim((string)($row['api_key'] ?? '')),
        'max_tokens' => max(256, (int)($row['max_tokens'] ?? 1200)),
        'temperature' => min(1, max(0, (float)($row['temperature'] ?? 0.2))),
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
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
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

    return "Aşağıdaki çoktan seçmeli soruyu bir ön denetçi gibi incele. Son karar adminde olacak.\n"
        . "Kontrol sınıfları:\n"
        . "1) Doğru cevap tutarlılığı\n"
        . "2) Soru netliği\n"
        . "3) Şık kalitesi\n"
        . "4) Açıklama kalitesi\n"
        . "5) Biçimsel sorunlar\n\n"
        . "Sadece parse edilebilir JSON döndür.\n"
        . "Beklenen çıktı:\n"
        . "{\n"
        . "  \"ai_status\": \"ok|warning|error\",\n"
        . "  \"issue_types\": [\"...\"],\n"
        . "  \"confidence_score\": 0,\n"
        . "  \"ai_notes\": \"kısa açıklayıcı not\",\n"
        . "  \"suggested_fix\": \"kısa öneri\"\n"
        . "}\n\n"
        . "Soru verisi:\n"
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function ai_review_call_ai(array $settings, array $question)
{
    $provider = $settings['provider'];
    $model = $settings['model'];
    $apiKey = $settings['api_key'];
    $maxTokens = $settings['max_tokens'];
    $temperature = $settings['temperature'];
    $prompt = ai_review_prompt($question);
    $systemText = 'Sen denizcilik eğitim soruları için kalite kontrol uzmanısın. Sadece JSON döndür.';

    if ($apiKey === '') {
        throw new RuntimeException('AI API key tanımlı değil.');
    }

    $contentText = '';
    if ($provider === 'openai' || $provider === 'groq') {
        $endpoint = $provider === 'openai'
            ? 'https://api.openai.com/v1/chat/completions'
            : 'https://api.groq.com/openai/v1/chat/completions';

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
            throw new RuntimeException('AI API hatası (' . $provider . '): HTTP ' . $httpCode . ' ' . $curlErr);
        }

        $decoded = json_decode((string)$raw, true);
        $contentText = (string)($decoded['choices'][0]['message']['content'] ?? '');
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
            throw new RuntimeException('AI API hatası (claude): HTTP ' . $httpCode . ' ' . $curlErr);
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
            throw new RuntimeException('AI API hatası (gemini): HTTP ' . $httpCode . ' ' . $curlErr);
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

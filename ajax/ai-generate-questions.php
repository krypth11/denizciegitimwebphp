<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();

function normalize_question_text($text) {
    $text = mb_strtolower(trim((string)$text), 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
    return trim($text);
}

function is_similar_question_text($a, $b) {
    if ($a === '' || $b === '') {
        return false;
    }

    if ($a === $b) {
        return true;
    }

    if (str_contains($a, $b) || str_contains($b, $a)) {
        return true;
    }

    similar_text($a, $b, $percent);
    return $percent >= 92;
}

try {
    $course_id = $_POST['course_id'] ?? '';
    $question_type = $_POST['question_type'] ?? '';
    $count = (int)($_POST['question_count'] ?? ($_POST['count'] ?? 5));
    $topic = sanitize_input($_POST['topic'] ?? '');
    $include_option_e = (int)($_POST['include_option_e'] ?? 0) === 1;

    if (empty($course_id) || empty($question_type)) {
        echo json_encode([
            'success' => false,
            'message' => 'Ders ve tip seçimi zorunludur!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($count < 1 || $count > 100) {
        echo json_encode([
            'success' => false,
            'message' => 'Soru sayısı 1-100 arasında olmalıdır!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $type_map = [
        'mixed' => 'Karışık',
        'verbal' => 'Sözel',
        'numerical' => 'Sayısal',
        'karışık' => 'Karışık',
        'sözel' => 'Sözel',
        'sayısal' => 'Sayısal',
    ];

    if (!isset($type_map[$question_type])) {
        echo json_encode([
            'success' => false,
            'message' => 'Geçersiz soru türü!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $type_label = $type_map[$question_type];

    $stmt = $pdo->prepare(
        'SELECT c.name as course_name, c.id as course_id, q.name as qualification_name
         FROM courses c
         LEFT JOIN qualifications q ON c.qualification_id = q.id
         WHERE c.id = ?'
    );
    $stmt->execute([$course_id]);
    $course_info = $stmt->fetch();

    if (!$course_info) {
        echo json_encode([
            'success' => false,
            'message' => 'Ders bulunamadı!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $settings_stmt = $pdo->query('SELECT * FROM admin_settings LIMIT 1');
    $settings = $settings_stmt->fetch();

    if (!$settings || empty($settings['api_key'])) {
        echo json_encode([
            'success' => false,
            'message' => 'AI ayarları yapılmamış! Lütfen Settings sayfasından Groq API Key ekleyin.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api_key = $settings['api_key'];
    $model = $settings['ai_model'] ?? 'llama-3.3-70b-versatile';

    $qualification = $course_info['qualification_name'];
    $course = $course_info['course_name'];

    // Aynı ders için mevcut soruları prompt'a dahil et (prompt şişmesini önlemek için limitli)
    $existing_stmt = $pdo->prepare(
        'SELECT question_text FROM questions
         WHERE course_id = ?
         ORDER BY created_at DESC
         LIMIT 80'
    );
    $existing_stmt->execute([$course_id]);
    $existing_questions_raw = $existing_stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $existing_questions = array_values(array_filter(array_map(static function ($q) {
        return trim((string)$q);
    }, $existing_questions_raw)));

    $existing_questions_prompt = '';
    if (!empty($existing_questions)) {
        $lines = [];
        foreach ($existing_questions as $i => $text) {
            $lines[] = ($i + 1) . ') ' . $text;
        }
        $existing_questions_prompt = "\n\nAYNI DERSE AİT MEVCUT SORULAR (TEKRAR ÜRETME):\n"
            . implode("\n", $lines)
            . "\n\nKURAL: Yukarıdaki sorularla aynı veya çok benzer soru üretme.";
    }

    $prompt = "Sen bir denizcilik eğitim uzmanısın. Aşağıdaki bilgilere göre sınavda çıkabilecek kaliteli çoktan seçmeli sorular üret.

Yeterlilik: {$qualification}
Ders: {$course}
Soru Tipi: {$type_label}
Soru Sayısı: {$count}";

    if (!empty($topic)) {
        $prompt .= "\nKonu: {$topic}";
    }

    $prompt .= "\n\nÖNEMLİ KURALLAR:
1. Her soruda A, B, C, D şıkları ZORUNLU olmalı
2. E şıkkı OPSİYONEL (varsa option_e'ye yaz)
3. Doğru cevap A/B/C/D/E olabilir; doğru cevap E ise option_e boş olamaz
4. " . ($include_option_e ? 'Mümkün olduğunca E şıklı sorular da üret.' : 'E şıkkını sadece doğal ve gerekli olduğunda kullan.') . "
5. Sadece 1 doğru cevap olmalı
3. Sorular Türkçe olmalı
4. Denizcilik terminolojisi kullan
5. Gerçekçi ve eğitici olmalı
6. Her soruya kısa açıklama ekle

ÇIKTI FORMATI (sadece JSON):
{
  \"questions\": [
    {
      \"question_text\": \"Soru metni burada\",
      \"option_a\": \"A şıkkı\",
      \"option_b\": \"B şıkkı\",
      \"option_c\": \"C şıkkı\",
      \"option_d\": \"D şıkkı\",
      \"option_e\": \"E şıkkı (opsiyonel)\",
      \"correct_answer\": \"A\",
      \"explanation\": \"Kısa açıklama\"
    }
  ]
}"
    . $existing_questions_prompt;

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Sen Türkçe denizcilik soruları üreten bir uzmansın. Sadece JSON formatında cevap ver.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000,
            'response_format' => ['type' => 'json_object'],
        ]),
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        echo json_encode([
            'success' => false,
            'message' => 'AI API hatası! HTTP ' . $http_code,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = json_decode($response, true);

    if (!isset($result['choices'][0]['message']['content'])) {
        echo json_encode([
            'success' => false,
            'message' => 'AI yanıt vermedi!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ai_response = json_decode($result['choices'][0]['message']['content'], true);

    if (!isset($ai_response['questions']) || !is_array($ai_response['questions'])) {
        echo json_encode([
            'success' => false,
            'message' => 'AI geçersiz format döndürdü!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw_questions = is_array($ai_response['questions']) ? $ai_response['questions'] : [];
    $raw_generated_count = count($raw_questions);

    $deduplicated_questions = [];
    $seen_batch_texts = [];
    $existing_normalized = array_map('normalize_question_text', $existing_questions);
    $filtered_duplicates = 0;
    $filtered_existing = 0;
    $validation_skipped_count = 0;
    $validation_skipped_reasons = [];
    $validation_skipped_samples = [];

    $add_validation_skip = static function (string $reason, array $question = []) use (&$validation_skipped_count, &$validation_skipped_reasons, &$validation_skipped_samples) {
        $validation_skipped_count++;
        $validation_skipped_reasons[$reason] = (int)($validation_skipped_reasons[$reason] ?? 0) + 1;

        if (count($validation_skipped_samples) >= 8) {
            return;
        }

        $questionText = trim((string)($question['question_text'] ?? ''));
        if ($questionText !== '' && mb_strlen($questionText, 'UTF-8') > 140) {
            $questionText = mb_substr($questionText, 0, 140, 'UTF-8') . '…';
        }

        $validation_skipped_samples[] = [
            'reason' => $reason,
            'question_text' => $questionText,
            'correct_answer' => strtoupper(trim((string)($question['correct_answer'] ?? ''))),
            'has_option_e' => trim((string)($question['option_e'] ?? '')) !== '',
        ];
    };

    foreach ($raw_questions as $q) {
        $question_text = trim((string)($q['question_text'] ?? ''));
        $option_a = trim((string)($q['option_a'] ?? ''));
        $option_b = trim((string)($q['option_b'] ?? ''));
        $option_c = trim((string)($q['option_c'] ?? ''));
        $option_d = trim((string)($q['option_d'] ?? ''));
        $option_e_raw = $q['option_e'] ?? null;
        $option_e = is_string($option_e_raw) ? trim($option_e_raw) : null;
        if ($option_e === '') {
            $option_e = null;
        }
        $correct_answer = strtoupper(trim((string)($q['correct_answer'] ?? '')));

        if ($question_text === '' || $option_a === '' || $option_b === '' || $option_c === '' || $option_d === '') {
            $add_validation_skip('missing_required_fields', $q);
            continue;
        }

        if (!in_array($correct_answer, ['A', 'B', 'C', 'D', 'E'], true)) {
            $add_validation_skip('invalid_correct_answer', $q);
            continue;
        }

        // Eğer AI E seçmiş ama option_e boşsa → bu soruyu direkt discard etmeden önce debug logla
        if ($correct_answer === 'E' && ($option_e === null || $option_e === '')) {
            error_log('AI GENERATED INVALID E QUESTION: ' . json_encode($q));
        }

        if ($correct_answer === 'E') {
            if ($option_e === '') {
                // fallback: E şıkkını diğerlerinden türetmeye çalışma, sadece skip et ama log düş
                $add_validation_skip('correct_answer_e_without_option_e', $q);
                continue;
            }
            if ($option_e === null) {
                // fallback: E şıkkını diğerlerinden türetmeye çalışma, sadece skip et ama log düş
                $add_validation_skip('correct_answer_e_without_option_e', $q);
                continue;
            }
        }

        $normalized = normalize_question_text($question_text);
        if ($normalized === '') {
            $add_validation_skip('empty_normalized_question_text', $q);
            continue;
        }

        $is_existing_duplicate = false;
        foreach ($existing_normalized as $existing_text) {
            if (is_similar_question_text($normalized, $existing_text)) {
                $is_existing_duplicate = true;
                break;
            }
        }

        if ($is_existing_duplicate) {
            $filtered_existing++;
            $add_validation_skip('duplicate_with_existing_question', $q);
            continue;
        }

        $is_batch_duplicate = false;
        foreach ($seen_batch_texts as $seen_text) {
            if (is_similar_question_text($normalized, $seen_text)) {
                $is_batch_duplicate = true;
                break;
            }
        }

        if ($is_batch_duplicate) {
            $filtered_duplicates++;
            $add_validation_skip('duplicate_with_generated_batch', $q);
            continue;
        }

        $seen_batch_texts[] = $normalized;
        $deduplicated_questions[] = array_merge($q, [
            'option_e' => $option_e,
            'course_id' => $course_id,
            'question_type' => $question_type,
            'status' => 'pending',
        ]);
    }

    $deduplicated_count = count($deduplicated_questions);

    echo json_encode([
        'success' => true,
        'message' => $deduplicated_count . ' soru üretildi!',
        'requested_count' => $count,
        'raw_generated_count' => $raw_generated_count,
        'generated_count' => $deduplicated_count,
        'deduplicated_count' => $deduplicated_count,
        'filtered_duplicates' => $filtered_duplicates,
        'filtered_existing' => $filtered_existing,
        'validation_skipped_count' => $validation_skipped_count,
        'validation_skipped_reasons' => $validation_skipped_reasons,
        'validation_skipped_samples' => $validation_skipped_samples,
        'questions' => $deduplicated_questions,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'İşlem sırasında bir sunucu hatası oluştu.',
    ], JSON_UNESCAPED_UNICODE);
}

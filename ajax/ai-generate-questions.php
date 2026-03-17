<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(120);

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();

try {
    $course_id = $_POST['course_id'] ?? '';
    $question_type = $_POST['question_type'] ?? '';
    $count = (int)($_POST['question_count'] ?? ($_POST['count'] ?? 5));
    $topic = sanitize_input($_POST['topic'] ?? '');

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

    $prompt = "Sen bir denizcilik eğitim uzmanısın. Aşağıdaki bilgilere göre sınavda çıkabilecek kaliteli çoktan seçmeli sorular üret.

Yeterlilik: {$qualification}
Ders: {$course}
Soru Tipi: {$type_label}
Soru Sayısı: {$count}";

    if (!empty($topic)) {
        $prompt .= "\nKonu: {$topic}";
    }

    $prompt .= "\n\nÖNEMLİ KURALLAR:
1. Her soru mutlaka 4 şık (A, B, C, D) olmalı
2. Sadece 1 doğru cevap olmalı
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
      \"correct_answer\": \"A\",
      \"explanation\": \"Kısa açıklama\"
    }
  ]
}";

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

    $questions_with_meta = array_map(static function ($q) use ($course_id, $question_type) {
        return array_merge($q, [
            'course_id' => $course_id,
            'question_type' => $question_type,
            'status' => 'pending',
        ]);
    }, $ai_response['questions']);

    echo json_encode([
        'success' => true,
        'message' => count($questions_with_meta) . ' soru üretildi!',
        'questions' => $questions_with_meta,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Hata: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

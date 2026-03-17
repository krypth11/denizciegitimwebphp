<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();

try {
    $questions_json = $_POST['questions'] ?? '';

    if (empty($questions_json)) {
        echo json_encode([
            'success' => false,
            'message' => 'Soru verisi gönderilmedi!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $questions = json_decode($questions_json, true);

    if (!is_array($questions) || empty($questions)) {
        echo json_encode([
            'success' => false,
            'message' => 'Geçersiz soru verisi!',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO questions (
            id, course_id, question_type, question_text,
            option_a, option_b, option_c, option_d,
            correct_answer, explanation, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    $saved_count = 0;

    foreach ($questions as $q) {
        if (empty($q['question_text']) || empty($q['option_a']) ||
            empty($q['option_b']) || empty($q['option_c']) ||
            empty($q['option_d']) || empty($q['correct_answer']) ||
            empty($q['course_id']) || empty($q['question_type'])) {
            continue;
        }

        $id = generate_uuid();

        if ($stmt->execute([
            $id,
            $q['course_id'],
            $q['question_type'],
            $q['question_text'],
            $q['option_a'],
            $q['option_b'],
            $q['option_c'],
            $q['option_d'],
            $q['correct_answer'],
            $q['explanation'] ?? '',
        ])) {
            $saved_count++;
        }
    }

    if ($saved_count > 0) {
        echo json_encode([
            'success' => true,
            'message' => $saved_count . ' soru başarıyla kaydedildi!',
            'count' => $saved_count,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Hiçbir soru kaydedilemedi!',
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Hata: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();

try {
    $questionCols = get_table_columns($pdo, 'questions');
    $hasOptionE = is_array($questionCols) && in_array('option_e', $questionCols, true);

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

    if ($hasOptionE) {
        $stmt = $pdo->prepare(
            'INSERT INTO questions (
                id, course_id, question_type, question_text,
                option_a, option_b, option_c, option_d, option_e,
                correct_answer, explanation, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO questions (
                id, course_id, question_type, question_text,
                option_a, option_b, option_c, option_d,
                correct_answer, explanation, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
    }

    $saved_count = 0;

    $type_map = [
        'mixed' => 'karışık',
        'verbal' => 'sözel',
        'numerical' => 'sayısal',
        'karışık' => 'karışık',
        'sözel' => 'sözel',
        'sayısal' => 'sayısal',
    ];

    foreach ($questions as $q) {
        if (($q['status'] ?? 'pending') !== 'approved') {
            continue;
        }

        if (empty($q['question_text']) || empty($q['option_a']) ||
            empty($q['option_b']) || empty($q['option_c']) ||
            empty($q['option_d']) || empty($q['correct_answer']) ||
            empty($q['course_id']) || empty($q['question_type'])) {
            continue;
        }

        $optionE = trim((string)($q['option_e'] ?? ''));
        $correctAnswer = strtoupper(trim((string)($q['correct_answer'] ?? '')));

        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
            continue;
        }

        if ($correctAnswer === 'E' && $optionE === '') {
            continue;
        }

        $normalized_type = $type_map[$q['question_type']] ?? null;
        if ($normalized_type === null) {
            continue;
        }

        $id = generate_uuid();

        if ($hasOptionE) {
            $ok = $stmt->execute([
                $id,
                $q['course_id'],
                $normalized_type,
                $q['question_text'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                ($optionE !== '' ? $optionE : null),
                $correctAnswer,
                $q['explanation'] ?? '',
            ]);
        } else {
            $ok = $stmt->execute([
                $id,
                $q['course_id'],
                $normalized_type,
                $q['question_text'],
                $q['option_a'],
                $q['option_b'],
                $q['option_c'],
                $q['option_d'],
                $correctAnswer,
                $q['explanation'] ?? '',
            ]);
        }

        if ($ok) {
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
            'message' => 'Hiçbir onaylı soru kaydedilemedi!',
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'İşlem sırasında bir sunucu hatası oluştu.',
    ], JSON_UNESCAPED_UNICODE);
}

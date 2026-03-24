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
    $skipped_count = 0;
    $skipped_reasons = [];
    $skipped_samples = [];
    $debug_received_questions_count = count($questions);
    $debug_e_answer_count = 0;
    $debug_e_with_option_e_count = 0;

    $add_skip = static function (string $reason, array $question = []) use (&$skipped_count, &$skipped_reasons, &$skipped_samples) {
        $skipped_count++;
        $skipped_reasons[$reason] = (int)($skipped_reasons[$reason] ?? 0) + 1;

        if (count($skipped_samples) >= 8) {
            return;
        }

        $questionText = trim((string)($question['question_text'] ?? ''));
        if (mb_strlen($questionText, 'UTF-8') > 140) {
            $questionText = mb_substr($questionText, 0, 140, 'UTF-8') . '…';
        }

        $skipped_samples[] = [
            'reason' => $reason,
            'question_text' => $questionText,
            'correct_answer' => strtoupper(trim((string)($question['correct_answer'] ?? ''))),
            'has_option_e' => trim((string)($question['option_e'] ?? '')) !== '',
        ];
    };

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
            $add_skip('status_not_approved', $q);
            continue;
        }

        if (empty($q['question_text']) || empty($q['option_a']) ||
            empty($q['option_b']) || empty($q['option_c']) ||
            empty($q['option_d']) || empty($q['correct_answer']) ||
            empty($q['course_id']) || empty($q['question_type'])) {
            $add_skip('missing_required_fields', $q);
            continue;
        }

        $optionERaw = $q['option_e'] ?? ($q['optionE'] ?? ($q['e_option'] ?? null));
        if (is_array($optionERaw) || is_object($optionERaw)) {
            $optionERaw = null;
        }
        $optionE = is_string($optionERaw) || is_numeric($optionERaw)
            ? trim((string)$optionERaw)
            : null;
        if ($optionE === '') {
            $optionE = null;
        }

        $correctRaw = $q['correct_answer'] ?? ($q['correctAnswer'] ?? '');
        $correctAnswer = strtoupper(trim((string)$correctRaw));

        if ($correctAnswer === 'E') {
            $debug_e_answer_count++;
        }

        if ($correctAnswer === 'E' && $optionE === null && isset($q['options']) && is_array($q['options'])) {
            $fallbackOptionE = $q['options']['E'] ?? ($q['options']['e'] ?? null);
            if (is_string($fallbackOptionE) || is_numeric($fallbackOptionE)) {
                $fallbackOptionE = trim((string)$fallbackOptionE);
                if ($fallbackOptionE !== '') {
                    $optionE = $fallbackOptionE;
                }
            }
        }

        if ($correctAnswer === 'E' && $optionE !== null) {
            $debug_e_with_option_e_count++;
        }

        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
            $add_skip('invalid_correct_answer', $q);
            continue;
        }

        if ($correctAnswer === 'E' && $optionE === null) {
            $add_skip('correct_answer_e_without_option_e', $q);
            continue;
        }

        if (!$hasOptionE && $correctAnswer === 'E') {
            $add_skip('db_has_no_option_e_column_for_e_answer', $q);
            continue;
        }

        $normalized_type = $type_map[$q['question_type']] ?? null;
        if ($normalized_type === null) {
            $add_skip('invalid_question_type', $q);
            continue;
        }

        $id = generate_uuid();

        try {
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
                    $optionE,
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
            } else {
                $add_skip('db_insert_failed', $q);
            }
        } catch (Throwable $e) {
            $add_skip('db_exception', $q);
            error_log('save-ai-questions item insert error: ' . $e->getMessage());
        }
    }

    if ($saved_count > 0) {
        echo json_encode([
            'success' => true,
            'message' => $saved_count . ' soru başarıyla kaydedildi!' . ($skipped_count > 0 ? ' ' . $skipped_count . ' soru atlandı.' : ''),
            'count' => $saved_count,
            'saved_count' => $saved_count,
            'skipped_count' => $skipped_count,
            'skipped_reasons' => $skipped_reasons,
            'skipped_samples' => $skipped_samples,
            'debug_version' => 'SAVE-AI-E-FIX-1',
            'debug_received_questions_count' => $debug_received_questions_count,
            'debug_e_answer_count' => $debug_e_answer_count,
            'debug_e_with_option_e_count' => $debug_e_with_option_e_count,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Hiçbir onaylı soru kaydedilemedi!',
            'saved_count' => 0,
            'skipped_count' => $skipped_count,
            'skipped_reasons' => $skipped_reasons,
            'skipped_samples' => $skipped_samples,
            'debug_version' => 'SAVE-AI-E-FIX-1',
            'debug_received_questions_count' => $debug_received_questions_count,
            'debug_e_answer_count' => $debug_e_answer_count,
            'debug_e_with_option_e_count' => $debug_e_with_option_e_count,
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'İşlem sırasında bir sunucu hatası oluştu.',
    ], JSON_UNESCAPED_UNICODE);
}

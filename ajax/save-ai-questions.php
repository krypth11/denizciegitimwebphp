<?php
header('Content-Type: application/json; charset=utf-8');

$GLOBALS['__save_ai_response_sent__'] = false;

register_shutdown_function(static function () {
    $last = error_get_last();
    if (!$last) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($last['type'], $fatalTypes, true)) {
        return;
    }

    if (!empty($GLOBALS['__save_ai_response_sent__'])) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'message' => 'Fatal error oluştu, boş response engellendi.',
        'debug_version' => 'SAVE-AI-E-DEBUG-1',
        'exception_message' => $last['message'] ?? 'fatal_error',
        'exception_code' => 0,
    ], JSON_UNESCAPED_UNICODE);
});

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();

try {
    $debug_version = 'SAVE-AI-E-DEBUG-1';

    $questionCols = get_table_columns($pdo, 'questions');
    $dbHasOptionEColumn = is_array($questionCols) && in_array('option_e', $questionCols, true);

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

    if ($dbHasOptionEColumn) {
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
    $row_results = [];
    $insert_exceptions = [];
    $debug_received_questions_count = count($questions);
    $debug_e_answer_count = 0;
    $debug_e_with_option_e_count = 0;
    $debug_e_saved_count = 0;
    $debug_e_skipped_count = 0;

    $short_question_text = static function ($text) {
        $questionText = trim((string)$text);
        if (mb_strlen($questionText, 'UTF-8') > 140) {
            return mb_substr($questionText, 0, 140, 'UTF-8') . '…';
        }
        return $questionText;
    };

    $push_row_result = static function (array $row) use (&$row_results) {
        if (count($row_results) < 10) {
            $row_results[] = $row;
        }
    };

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

    foreach ($questions as $index => $q) {
        $questionShort = $short_question_text($q['question_text'] ?? '');

        $correctRaw = $q['correct_answer'] ?? ($q['correctAnswer'] ?? '');
        $correctAnswer = strtoupper(trim((string)$correctRaw));
        $isEAnswer = ($correctAnswer === 'E');

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
        $rowHasOptionE = ($optionE !== null);

        if ($isEAnswer) {
            $debug_e_answer_count++;
            if ($rowHasOptionE) {
                $debug_e_with_option_e_count++;
            }
        }

        if (($q['status'] ?? 'pending') !== 'approved') {
            $add_skip('status_not_approved', $q);
            if ($isEAnswer) {
                $debug_e_skipped_count++;
            }
            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => $rowHasOptionE,
                'status' => 'skipped',
                'reason' => 'status_not_approved',
                'db_error' => null,
            ]);
            continue;
        }

        if (empty($q['question_text']) || empty($q['option_a']) ||
            empty($q['option_b']) || empty($q['option_c']) ||
            empty($q['option_d']) || empty($q['correct_answer']) ||
            empty($q['course_id']) || empty($q['question_type'])) {
            $add_skip('missing_required_fields', $q);
            if ($isEAnswer) {
                $debug_e_skipped_count++;
            }
            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => $rowHasOptionE,
                'status' => 'skipped',
                'reason' => 'missing_required_fields',
                'db_error' => null,
            ]);
            continue;
        }

        if ($correctAnswer === 'E' && $optionE === null && isset($q['options']) && is_array($q['options'])) {
            $fallbackOptionE = $q['options']['E'] ?? ($q['options']['e'] ?? null);
            if (is_string($fallbackOptionE) || is_numeric($fallbackOptionE)) {
                $fallbackOptionE = trim((string)$fallbackOptionE);
                if ($fallbackOptionE !== '') {
                    $optionE = $fallbackOptionE;
                    $rowHasOptionE = true;
                }
            }
        }

        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
            $add_skip('invalid_correct_answer', $q);
            if ($isEAnswer) {
                $debug_e_skipped_count++;
            }
            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => $rowHasOptionE,
                'status' => 'skipped',
                'reason' => 'invalid_correct_answer',
                'db_error' => null,
            ]);
            continue;
        }

        if ($correctAnswer === 'E' && $optionE === null) {
            $add_skip('correct_answer_e_without_option_e', $q);
            $debug_e_skipped_count++;
            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => false,
                'status' => 'skipped',
                'reason' => 'correct_answer_e_without_option_e',
                'db_error' => null,
            ]);
            continue;
        }

        if (!$dbHasOptionEColumn && $correctAnswer === 'E') {
            $add_skip('db_has_no_option_e_column_for_e_answer', $q);
            $debug_e_skipped_count++;
            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => $rowHasOptionE,
                'status' => 'skipped',
                'reason' => 'db_has_no_option_e_column_for_e_answer',
                'db_error' => null,
            ]);
            continue;
        }

        $normalized_type = $type_map[$q['question_type']] ?? null;
        if ($normalized_type === null) {
            $add_skip('invalid_question_type', $q);
            if ($isEAnswer) {
                $debug_e_skipped_count++;
            }
            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => $rowHasOptionE,
                'status' => 'skipped',
                'reason' => 'invalid_question_type',
                'db_error' => null,
            ]);
            continue;
        }

        $id = generate_uuid();

        try {
            if ($dbHasOptionEColumn) {
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
                if ($isEAnswer) {
                    $debug_e_saved_count++;
                }
                $push_row_result([
                    'index' => $index,
                    'question_text' => $questionShort,
                    'correct_answer' => $correctAnswer,
                    'has_option_e' => $rowHasOptionE,
                    'status' => 'saved',
                    'reason' => null,
                    'db_error' => null,
                ]);
            } else {
                $add_skip('db_insert_failed', $q);
                if ($isEAnswer) {
                    $debug_e_skipped_count++;
                }
                $dbErr = implode(' | ', $stmt->errorInfo() ?: []);
                $push_row_result([
                    'index' => $index,
                    'question_text' => $questionShort,
                    'correct_answer' => $correctAnswer,
                    'has_option_e' => $rowHasOptionE,
                    'status' => 'skipped',
                    'reason' => 'db_insert_failed',
                    'db_error' => $dbErr !== '' ? $dbErr : null,
                ]);
            }
        } catch (Throwable $e) {
            $add_skip('db_exception', $q);
            if ($isEAnswer) {
                $debug_e_skipped_count++;
            }

            $insert_exceptions[] = [
                'index' => $index,
                'message' => $e->getMessage(),
                'code' => (int)$e->getCode(),
            ];

            $push_row_result([
                'index' => $index,
                'question_text' => $questionShort,
                'correct_answer' => $correctAnswer,
                'has_option_e' => $rowHasOptionE,
                'status' => 'exception',
                'reason' => 'db_exception',
                'db_error' => $e->getMessage(),
            ]);

            error_log('save-ai-questions item insert error: ' . $e->getMessage());
        }
    }

    $firstException = $insert_exceptions[0] ?? null;

    if ($saved_count > 0) {
        $GLOBALS['__save_ai_response_sent__'] = true;
        echo json_encode([
            'success' => true,
            'message' => $saved_count . ' soru başarıyla kaydedildi!' . ($skipped_count > 0 ? ' ' . $skipped_count . ' soru atlandı.' : ''),
            'count' => $saved_count,
            'saved_count' => $saved_count,
            'skipped_count' => $skipped_count,
            'skipped_reasons' => $skipped_reasons,
            'skipped_samples' => $skipped_samples,
            'debug_version' => $debug_version,
            'debug_received_questions_count' => $debug_received_questions_count,
            'debug_e_answer_count' => $debug_e_answer_count,
            'debug_e_with_option_e_count' => $debug_e_with_option_e_count,
            'debug_e_saved_count' => $debug_e_saved_count,
            'debug_e_skipped_count' => $debug_e_skipped_count,
            'row_results' => array_slice($row_results, 0, 10),
            'exception_message' => $firstException['message'] ?? null,
            'exception_code' => $firstException['code'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        $GLOBALS['__save_ai_response_sent__'] = true;
        echo json_encode([
            'success' => false,
            'message' => 'Hiçbir onaylı soru kaydedilemedi!',
            'saved_count' => 0,
            'skipped_count' => $skipped_count,
            'skipped_reasons' => $skipped_reasons,
            'skipped_samples' => $skipped_samples,
            'debug_version' => $debug_version,
            'debug_received_questions_count' => $debug_received_questions_count,
            'debug_e_answer_count' => $debug_e_answer_count,
            'debug_e_with_option_e_count' => $debug_e_with_option_e_count,
            'debug_e_saved_count' => $debug_e_saved_count,
            'debug_e_skipped_count' => $debug_e_skipped_count,
            'row_results' => array_slice($row_results, 0, 10),
            'exception_message' => $firstException['message'] ?? null,
            'exception_code' => $firstException['code'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    $GLOBALS['__save_ai_response_sent__'] = true;
    echo json_encode([
        'success' => false,
        'message' => 'İşlem sırasında bir sunucu hatası oluştu.',
        'debug_version' => 'SAVE-AI-E-DEBUG-1',
        'exception_message' => $e->getMessage(),
        'exception_code' => (int)$e->getCode(),
        'debug_received_questions_count' => 0,
        'debug_e_answer_count' => 0,
        'debug_e_saved_count' => 0,
        'debug_e_skipped_count' => 0,
        'row_results' => [],
    ], JSON_UNESCAPED_UNICODE);
}

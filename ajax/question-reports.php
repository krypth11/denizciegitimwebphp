<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$admin = require_admin();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function qr_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function qr_parse_snapshot($raw): array
{
    if (is_array($raw)) return $raw;
    if (!is_string($raw) || trim($raw) === '') return [];

    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function qr_find_snapshot_value(array $snapshot, string $key): ?string
{
    if (array_key_exists($key, $snapshot)) {
        $v = $snapshot[$key];
        if (is_scalar($v) || $v === null) {
            return $v === null ? null : (string)$v;
        }
    }

    if (isset($snapshot['question']) && is_array($snapshot['question']) && array_key_exists($key, $snapshot['question'])) {
        $v = $snapshot['question'][$key];
        if (is_scalar($v) || $v === null) {
            return $v === null ? null : (string)$v;
        }
    }

    return null;
}

function qr_question_id_is_valid(string $id): bool
{
    return $id !== '' && (bool)preg_match('/^[a-zA-Z0-9\-]{8,64}$/', $id);
}

try {
    $questionCols = get_table_columns($pdo, 'questions');
    $hasOptionE = is_array($questionCols) && in_array('option_e', $questionCols, true);
    $hasQuestionType = is_array($questionCols) && in_array('question_type', $questionCols, true);
    $hasStatus = is_array($questionCols) && in_array('status', $questionCols, true);
    $hasIsActive = is_array($questionCols) && in_array('is_active', $questionCols, true);

    if ($action === 'list') {
        $sql = "SELECT
                    qr.id AS report_id,
                    qr.user_id AS reporter_user_id,
                    qr.question_id,
                    qr.report_text,
                    qr.question_snapshot,
                    qr.created_at,
                    qr.updated_at,
                    up.full_name AS reporter_name,
                    up.email AS reporter_email,
                    q.question_text,
                    q.option_a,
                    q.option_b,
                    q.option_c,
                    q.option_d,
                    q.option_e,
                    q.correct_answer,
                    q.explanation,
                    q.course_id,
                    q.question_type
                FROM question_reports qr
                LEFT JOIN user_profiles up ON up.id = qr.user_id
                LEFT JOIN questions q ON q.id = qr.question_id
                ORDER BY qr.created_at DESC
                LIMIT 300";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reports = array_map(static function (array $r): array {
            $snapshot = qr_parse_snapshot($r['question_snapshot'] ?? null);

            $fallbackQuestion = [
                'question_text' => $r['question_text'] ?? null,
                'option_a' => $r['option_a'] ?? null,
                'option_b' => $r['option_b'] ?? null,
                'option_c' => $r['option_c'] ?? null,
                'option_d' => $r['option_d'] ?? null,
                'option_e' => $r['option_e'] ?? null,
                'correct_answer' => $r['correct_answer'] ?? null,
                'explanation' => $r['explanation'] ?? null,
                'course_id' => $r['course_id'] ?? null,
                'question_type' => $r['question_type'] ?? null,
            ];

            $questionData = [];
            foreach (array_keys($fallbackQuestion) as $field) {
                $questionData[$field] = qr_find_snapshot_value($snapshot, $field) ?? $fallbackQuestion[$field];
            }

            return [
                'report_id' => (string)($r['report_id'] ?? ''),
                'question_id' => (string)($r['question_id'] ?? ''),
                'report_text' => (string)($r['report_text'] ?? ''),
                'question_snapshot' => is_string($r['question_snapshot'] ?? null) ? $r['question_snapshot'] : '',
                'created_at' => $r['created_at'] ?? null,
                'updated_at' => $r['updated_at'] ?? null,
                'reporter_user_id' => (string)($r['reporter_user_id'] ?? ''),
                'reporter_name' => (string)($r['reporter_name'] ?? ''),
                'reporter_email' => (string)($r['reporter_email'] ?? ''),
                'question' => $questionData,
            ];
        }, $rows);

        qr_json(true, '', ['reports' => $reports]);
    }

    if ($action === 'delete_report') {
        $reportId = trim((string)($_POST['report_id'] ?? ''));
        if ($reportId === '') {
            qr_json(false, 'report_id zorunludur.', [], 422);
        }

        $stmt = $pdo->prepare('DELETE FROM question_reports WHERE id = ?');
        $stmt->execute([$reportId]);

        qr_json(true, 'Bildirim silindi.');
    }

    if ($action === 'get_question') {
        $questionId = trim((string)($_GET['question_id'] ?? ''));
        if (!qr_question_id_is_valid($questionId)) {
            qr_json(false, 'Geçersiz question_id.', [], 422);
        }

        $selectCols = [
            'id',
            'question_text',
            'option_a',
            'option_b',
            'option_c',
            'option_d',
            'correct_answer',
            'explanation',
        ];
        if ($hasOptionE) {
            $selectCols[] = 'option_e';
        }
        if ($hasQuestionType) {
            $selectCols[] = 'question_type';
        }
        if ($hasStatus) {
            $selectCols[] = 'status';
        }
        if ($hasIsActive) {
            $selectCols[] = 'is_active';
        }

        $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM questions WHERE id = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$questionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            qr_json(false, 'Soru bulunamadı veya silinmiş.', [], 404);
        }

        $question = [
            'id' => (string)($row['id'] ?? ''),
            'question_text' => (string)($row['question_text'] ?? ''),
            'option_a' => (string)($row['option_a'] ?? ''),
            'option_b' => (string)($row['option_b'] ?? ''),
            'option_c' => (string)($row['option_c'] ?? ''),
            'option_d' => (string)($row['option_d'] ?? ''),
            'option_e' => $hasOptionE ? (string)($row['option_e'] ?? '') : '',
            'correct_answer' => (string)($row['correct_answer'] ?? ''),
            'explanation' => (string)($row['explanation'] ?? ''),
        ];

        if ($hasQuestionType) {
            $question['question_type'] = (string)($row['question_type'] ?? '');
        }

        if ($hasStatus) {
            $question['status'] = (string)($row['status'] ?? '');
        } elseif ($hasIsActive) {
            $question['status'] = ((int)($row['is_active'] ?? 1) === 1) ? 'active' : 'inactive';
        }

        qr_json(true, '', [
            'question' => $question,
            'meta' => [
                'has_option_e' => $hasOptionE,
                'has_question_type' => $hasQuestionType,
                'status_mode' => $hasStatus ? 'status' : ($hasIsActive ? 'is_active' : 'none'),
            ],
        ]);
    }

    if ($action === 'update_question') {
        $questionId = trim((string)($_POST['question_id'] ?? ''));
        $questionText = sanitize_input($_POST['question_text'] ?? '');
        $optionA = sanitize_input($_POST['option_a'] ?? '');
        $optionB = sanitize_input($_POST['option_b'] ?? '');
        $optionC = sanitize_input($_POST['option_c'] ?? '');
        $optionD = sanitize_input($_POST['option_d'] ?? '');
        $optionE = sanitize_input($_POST['option_e'] ?? '');
        $correctAnswer = strtoupper(trim((string)($_POST['correct_answer'] ?? '')));
        $explanation = sanitize_input($_POST['explanation'] ?? '');
        $questionType = sanitize_input($_POST['question_type'] ?? '');
        $statusInput = strtolower(trim((string)($_POST['status'] ?? '')));

        if (!qr_question_id_is_valid($questionId)) {
            qr_json(false, 'Geçersiz question_id.', [], 422);
        }

        if ($questionText === '' || $optionA === '' || $optionB === '' || $optionC === '' || $optionD === '' || $correctAnswer === '') {
            qr_json(false, 'Tüm zorunlu alanları doldurun!', [], 422);
        }

        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
            qr_json(false, 'Geçersiz doğru cevap!', [], 422);
        }

        if ($correctAnswer === 'E' && !$hasOptionE) {
            qr_json(false, 'correct_answer E seçildi ancak option_e kolonu bulunamadı.', ['error_code' => 'correct_answer_e_but_option_e_not_supported'], 422);
        }

        if ($correctAnswer === 'E' && $optionE === '') {
            qr_json(false, 'E doğru cevap için Şık E doldurulmalıdır!', [], 422);
        }

        $setParts = [
            'question_text = ?',
            'option_a = ?',
            'option_b = ?',
            'option_c = ?',
            'option_d = ?',
            'correct_answer = ?',
            'explanation = ?',
        ];
        $params = [
            $questionText,
            $optionA,
            $optionB,
            $optionC,
            $optionD,
            $correctAnswer,
            $explanation,
        ];

        if ($hasOptionE) {
            $setParts[] = 'option_e = ?';
            $params[] = ($optionE !== '' ? $optionE : null);
        }

        if ($hasQuestionType) {
            if (!in_array($questionType, ['sayısal', 'sözel', 'karışık'], true)) {
                qr_json(false, 'Geçersiz soru tipi!', [], 422);
            }
            $setParts[] = 'question_type = ?';
            $params[] = $questionType;
        }

        if ($hasStatus) {
            if ($statusInput === '') {
                qr_json(false, 'Durum zorunludur.', [], 422);
            }
            $setParts[] = 'status = ?';
            $params[] = $statusInput;
        } elseif ($hasIsActive) {
            if (!in_array($statusInput, ['active', 'inactive'], true)) {
                qr_json(false, 'Geçersiz durum!', [], 422);
            }
            $setParts[] = 'is_active = ?';
            $params[] = ($statusInput === 'active' ? 1 : 0);
        }

        $params[] = $questionId;
        $sql = 'UPDATE questions SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() < 1) {
            $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE id = ?');
            $existsStmt->execute([$questionId]);
            if ((int)$existsStmt->fetchColumn() < 1) {
                qr_json(false, 'Soru bulunamadı veya silinmiş.', [], 404);
            }
        }

        qr_json(true, 'Soru güncellendi.', ['question_id' => $questionId]);
    }

    qr_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    error_log('question-reports ajax error: ' . $e->getMessage());
    qr_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}

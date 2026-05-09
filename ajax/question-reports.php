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

try {
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

    qr_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    error_log('question-reports ajax error: ' . $e->getMessage());
    qr_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}

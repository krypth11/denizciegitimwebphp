<?php

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/question_export_helper.php';

$user = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function questions_export_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function questions_export_filters_from_request(): array
{
    return [
        'qualification_id' => trim((string)($_REQUEST['qualification_id'] ?? '')),
        'course_id' => trim((string)($_REQUEST['course_id'] ?? '')),
        'topic_id' => trim((string)($_REQUEST['topic_id'] ?? '')),
    ];
}

try {
    if ($action === 'preview_count') {
        $filters = questions_export_filters_from_request();
        if ($filters['qualification_id'] === '') {
            questions_export_json(false, 'Yeterlilik seçimi zorunludur.', [], 422);
        }

        $flags = question_export_get_column_flags($pdo);
        $parts = question_export_build_query_parts($filters, $flags);
        $whereSql = implode(' AND ', $parts['where']);

        $countSql = 'SELECT COUNT(*)' . $parts['join'] . ' WHERE ' . $whereSql;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($parts['params']);
        $totalCount = (int)$countStmt->fetchColumn();

        questions_export_json(true, '', ['total_count' => $totalCount]);
    }

    if ($action === 'download_csv') {
        $filters = questions_export_filters_from_request();
        if ($filters['qualification_id'] === '') {
            questions_export_json(false, 'Yeterlilik seçimi zorunludur.', [], 422);
        }

        $flags = question_export_get_column_flags($pdo);
        $parts = question_export_build_query_parts($filters, $flags);
        $whereSql = implode(' AND ', $parts['where']);

        $qStmt = $pdo->prepare('SELECT name FROM qualifications WHERE id = ? LIMIT 1');
        $qStmt->execute([$filters['qualification_id']]);
        $qualificationName = (string)($qStmt->fetchColumn() ?: 'qualification');
        $qualificationSlug = question_export_slugify($qualificationName);
        $timestamp = date('Y-m-d_H-i');
        $filename = 'questions_export_' . $qualificationSlug . '_' . $timestamp . '.csv';
        $orderSql = $flags['created_at'] ? 'q.created_at DESC, q.id DESC' : 'q.id DESC';

        $csvSql = 'SELECT ' . implode(', ', $parts['select']) . $parts['join'] . ' WHERE ' . $whereSql . ' ORDER BY ' . $orderSql;
        $stmt = $pdo->prepare($csvSql);
        $stmt->execute($parts['params']);

        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        echo "\xEF\xBB\xBF";

        fputcsv($out, [
            'question_id',
            'qualification_id',
            'qualification_name',
            'course_id',
            'course_name',
            'topic_id',
            'topic_name',
            'question_type',
            'question_text',
            'option_a',
            'option_b',
            'option_c',
            'option_d',
            'option_e',
            'correct_answer',
            'explanation',
            'status',
            'is_active',
            'created_at',
            'updated_at',
        ]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                (string)($row['question_id'] ?? ''),
                (string)($row['qualification_id'] ?? ''),
                (string)($row['qualification_name'] ?? ''),
                (string)($row['course_id'] ?? ''),
                (string)($row['course_name'] ?? ''),
                (string)($row['topic_id'] ?? ''),
                (string)($row['topic_name'] ?? ''),
                (string)($row['question_type'] ?? ''),
                (string)($row['question_text'] ?? ''),
                (string)($row['option_a'] ?? ''),
                (string)($row['option_b'] ?? ''),
                (string)($row['option_c'] ?? ''),
                (string)($row['option_d'] ?? ''),
                (string)($row['option_e'] ?? ''),
                (string)($row['correct_answer'] ?? ''),
                (string)($row['explanation'] ?? ''),
                (string)($row['status'] ?? ''),
                (string)($row['is_active'] ?? ''),
                (string)($row['created_at'] ?? ''),
                (string)($row['updated_at'] ?? ''),
            ]);
        }

        fclose($out);
        exit;
    }

    questions_export_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    questions_export_json(false, 'İşlem sırasında sunucu hatası oluştu.', [], 500);
}

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

function questions_export_get_format(): string
{
    $format = strtolower(trim((string)($_REQUEST['format'] ?? 'csv')));
    $available = question_export_get_available_formats();
    return array_key_exists($format, $available) ? $format : 'csv';
}

function questions_export_get_profile(): string
{
    $profile = strtolower(trim((string)($_REQUEST['profile'] ?? 'full_data')));
    $labels = question_export_profile_labels();
    return array_key_exists($profile, $labels) ? $profile : 'full_data';
}

function questions_export_prepare_parts(PDO $pdo, array $filters): array
{
    $flags = question_export_get_column_flags($pdo);
    $parts = question_export_build_query_parts($filters, $flags);
    $whereSql = implode(' AND ', $parts['where']);
    $orderSql = $flags['created_at'] ? 'q.created_at DESC, q.id DESC' : 'q.id DESC';

    $countSql = 'SELECT COUNT(*)' . $parts['join'] . ' WHERE ' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($parts['params']);
    $totalCount = (int)$countStmt->fetchColumn();

    $dataSql = 'SELECT ' . implode(', ', $parts['select']) . $parts['join'] . ' WHERE ' . $whereSql . ' ORDER BY ' . $orderSql;

    return [
        'flags' => $flags,
        'parts' => $parts,
        'total_count' => $totalCount,
        'data_sql' => $dataSql,
    ];
}

function questions_export_get_stream_stmt(PDO $pdo, string $sql, array $params): PDOStatement
{
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    }

    $stmt = $pdo->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
    $stmt->execute($params);
    return $stmt;
}

try {
    if ($action === 'preview_count') {
        $filters = questions_export_filters_from_request();
        if ($filters['qualification_id'] === '') {
            questions_export_json(false, 'Yeterlilik seçimi zorunludur.', [], 422);
        }

        $format = questions_export_get_format();
        $profile = questions_export_get_profile();
        $config = question_export_build_content_config($profile, $_REQUEST);
        $prepared = questions_export_prepare_parts($pdo, $filters);
        $labels = question_export_get_filter_labels($pdo, $filters);

        questions_export_json(true, '', [
            'total_count' => $prepared['total_count'],
            'selected_format' => $format,
            'selected_profile' => $config['profile'],
            'selected_profile_label' => $config['profile_label'],
            'filters' => [
                'qualification_id' => $filters['qualification_id'],
                'course_id' => $filters['course_id'],
                'topic_id' => $filters['topic_id'],
                'qualification_name' => $labels['qualification_name'],
                'course_name' => $labels['course_name'],
                'topic_name' => $labels['topic_name'],
            ],
        ]);
    }

    if ($action === 'download_export' || $action === 'download_csv') {
        $filters = questions_export_filters_from_request();
        if ($filters['qualification_id'] === '') {
            questions_export_json(false, 'Yeterlilik seçimi zorunludur.', [], 422);
        }

        $format = ($action === 'download_csv') ? 'csv' : questions_export_get_format();
        $profile = questions_export_get_profile();

        if ($format === 'md' && !in_array($profile, ['ai_generation', 'ai_analysis'], true)) {
            $profile = 'ai_analysis';
        }

        $config = question_export_build_content_config($profile, $_REQUEST);
        $prepared = questions_export_prepare_parts($pdo, $filters);
        $parts = $prepared['parts'];
        $flags = $prepared['flags'];
        $labels = question_export_get_filter_labels($pdo, $filters);

        $qualificationName = $labels['qualification_name'] !== '' ? $labels['qualification_name'] : 'qualification';
        $filename = question_export_build_filename($format, $profile, $qualificationName);

        $formats = question_export_get_available_formats();
        $mime = $formats[$format]['mime'] ?? 'application/octet-stream';

        set_time_limit(0);
        ignore_user_abort(true);

        question_export_send_headers($mime, $filename);

        $stmt = questions_export_get_stream_stmt($pdo, $prepared['data_sql'], $parts['params']);
        $columns = question_export_build_tabular_columns($config, $flags);

        if ($format === 'csv') {
            question_export_stream_csv($stmt, $columns);
            exit;
        }

        if ($format === 'json') {
            question_export_stream_json($stmt, $config);
            exit;
        }

        if ($format === 'xlsx') {
            question_export_stream_xlsx($stmt, $columns);
            exit;
        }

        if ($format === 'md') {
            question_export_stream_markdown($stmt, $config, $filters, $labels, $prepared['total_count']);
            exit;
        }

        questions_export_json(false, 'Desteklenmeyen export formatı.', [], 422);
    }

    questions_export_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    if (($action === 'download_export' || $action === 'download_csv') && !headers_sent()) {
        questions_export_json(false, $e->getMessage() !== '' ? $e->getMessage() : 'İşlem sırasında sunucu hatası oluştu.', [], 500);
    }
    exit;
}

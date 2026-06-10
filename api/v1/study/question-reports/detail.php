<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';

api_require_method('GET');

function question_report_detail_parse_snapshot($raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function question_report_detail_snapshot_value(array $snapshot, string $key): ?string
{
    if (array_key_exists($key, $snapshot)) {
        $value = $snapshot[$key];
        if (is_scalar($value) || $value === null) {
            return $value === null ? null : (string)$value;
        }
    }

    if (isset($snapshot['question']) && is_array($snapshot['question']) && array_key_exists($key, $snapshot['question'])) {
        $value = $snapshot['question'][$key];
        if (is_scalar($value) || $value === null) {
            return $value === null ? null : (string)$value;
        }
    }

    return null;
}

try {
    $auth = api_require_auth($pdo);
    $userId = trim((string)($auth['user']['id'] ?? ''));
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }
    if (api_is_guest_user($pdo, $userId)) {
        api_error('Misafir kullanıcılar bildirim detayını görüntüleyemez.', 403);
    }

    $reportId = trim((string)($_GET['id'] ?? $_GET['report_id'] ?? ''));
    if ($reportId === '') {
        api_error('id parametresi zorunludur.', 422);
    }

    $reportColumns = get_table_columns($pdo, 'question_reports');
    if (!$reportColumns) {
        api_error('question_reports tablosu bulunamadı.', 500);
    }
    $questionColumns = get_table_columns($pdo, 'questions') ?: [];

    $hasUpdatedAt = in_array('updated_at', $reportColumns, true);
    $hasStatus = in_array('status', $reportColumns, true);
    $hasAdminResponse = in_array('admin_response', $reportColumns, true);
    $hasAdminResponseAt = in_array('admin_response_at', $reportColumns, true);

    $questionFieldList = [
        'question_text',
        'option_a',
        'option_b',
        'option_c',
        'option_d',
        'option_e',
        'correct_answer',
        'explanation',
        'question_image_url',
        'explanation_image_url',
    ];

    $select = [
        'r.id AS report_id',
        'r.question_id AS report_question_id',
        'r.report_text',
        ($hasStatus ? 'r.status' : "'reported'") . ' AS report_status',
        ($hasAdminResponse ? 'r.admin_response' : 'NULL') . ' AS admin_response',
        ($hasAdminResponseAt ? 'r.admin_response_at' : 'NULL') . ' AS admin_response_at',
        'r.created_at',
        ($hasUpdatedAt ? 'r.updated_at' : 'NULL') . ' AS updated_at',
        'r.question_snapshot',
        'q.id AS question_row_id',
    ];

    foreach ($questionFieldList as $field) {
        $select[] = in_array($field, $questionColumns, true)
            ? ('q.' . $field . ' AS ' . $field)
            : ('NULL AS ' . $field);
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM question_reports r'
        . ' LEFT JOIN questions q ON q.id = r.question_id'
        . ' WHERE r.id = ? AND r.user_id = ?'
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reportId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_error('Bildirim bulunamadı.', 404);
    }

    $snapshot = question_report_detail_parse_snapshot($row['question_snapshot'] ?? null);
    $question = [
        'id' => (string)($row['question_row_id'] ?? $row['report_question_id'] ?? ''),
    ];

    foreach ($questionFieldList as $field) {
        $value = $row[$field] ?? null;
        if (($value === null || $value === '') && $snapshot) {
            $value = question_report_detail_snapshot_value($snapshot, $field);
        }
        $question[$field] = $value === null ? null : (string)$value;
    }

    if (trim((string)($question['question_text'] ?? '')) === '') {
        $question['question_text'] = 'Soru bilgisi artık bulunamıyor.';
    }

    $status = study_question_report_status_normalize($row['report_status'] ?? 'reported');

    api_success('Soru bildirimi detayı getirildi.', [
        'report' => [
            'id' => (string)($row['report_id'] ?? ''),
            'question_id' => (string)($row['report_question_id'] ?? ''),
            'status' => $status,
            'status_label' => study_question_report_status_label($status),
            'report_text' => (string)($row['report_text'] ?? ''),
            'admin_response' => $row['admin_response'] !== null ? (string)$row['admin_response'] : null,
            'admin_response_at' => $row['admin_response_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ],
        'question' => $question,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
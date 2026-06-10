<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';

api_require_method('GET');

function question_report_api_parse_snapshot($raw): array
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

function question_report_api_snapshot_value(array $snapshot, string $key): ?string
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
        api_error('Misafir kullanıcılar bildirimlerini görüntüleyemez.', 403);
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
    $hasQuestionText = in_array('question_text', $questionColumns, true);

    $select = [
        'r.id',
        'r.question_id',
        'r.report_text',
        ($hasStatus ? 'r.status' : "'reported'") . ' AS status',
        ($hasAdminResponse ? 'r.admin_response' : 'NULL') . ' AS admin_response',
        ($hasAdminResponseAt ? 'r.admin_response_at' : 'NULL') . ' AS admin_response_at',
        'r.created_at',
        ($hasUpdatedAt ? 'r.updated_at' : 'NULL') . ' AS updated_at',
        ($hasQuestionText ? 'q.question_text' : 'NULL') . ' AS question_text',
        'r.question_snapshot',
    ];

    $orderUpdatedExpr = $hasUpdatedAt ? 'r.updated_at' : 'NULL';
    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM question_reports r'
        . ' LEFT JOIN questions q ON q.id = r.question_id'
        . ' WHERE r.user_id = ?'
        . ' ORDER BY COALESCE(' . $orderUpdatedExpr . ', r.created_at) DESC, r.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $reports = array_map(static function (array $row): array {
        $snapshot = question_report_api_parse_snapshot($row['question_snapshot'] ?? null);
        $questionText = trim((string)($row['question_text'] ?? ''));
        if ($questionText === '') {
            $questionText = trim((string)(question_report_api_snapshot_value($snapshot, 'question_text') ?? ''));
        }
        if ($questionText === '') {
            $questionText = 'Soru bilgisi artık bulunamıyor.';
        }

        $status = study_question_report_status_normalize($row['status'] ?? 'reported');

        return [
            'id' => (string)($row['id'] ?? ''),
            'question_id' => (string)($row['question_id'] ?? ''),
            'question_text' => $questionText,
            'status' => $status,
            'status_label' => study_question_report_status_label($status),
            'report_text' => (string)($row['report_text'] ?? ''),
            'admin_response' => $row['admin_response'] !== null ? (string)$row['admin_response'] : null,
            'admin_response_at' => $row['admin_response_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }, $rows);

    api_success('Bildirdiğiniz sorular getirildi.', [
        'reports' => $reports,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
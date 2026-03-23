<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();

    $questionId = trim((string)($payload['question_id'] ?? ''));
    $reportText = trim((string)($payload['report_text'] ?? ''));
    $questionSnapshot = $payload['question_snapshot'] ?? null;

    if ($questionId === '') {
        api_error('question_id zorunludur.', 422);
    }
    if ($reportText === '') {
        api_error('report_text zorunludur.', 422);
    }

    if (mb_strlen($questionId) > 191) {
        api_error('Geçersiz question_id.', 422);
    }

    if (mb_strlen($reportText) > 5000) {
        api_error('report_text çok uzun.', 422);
    }

    $questionMeta = study_get_question_meta($pdo, $questionId);
    if (!$questionMeta['exists']) {
        api_error('Soru bulunamadı.', 404);
    }

    try {
        $reportId = study_insert_question_report($pdo, $userId, $questionId, $reportText, $questionSnapshot);
    } catch (RuntimeException $e) {
        api_error('question_reports altyapısı hazır değil.', 400);
    }

    api_success('Soru bildirimi kaydedildi.', [
        'report_id' => $reportId,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

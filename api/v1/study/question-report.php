<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once dirname(__DIR__, 3) . '/includes/question_scope_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study.question-report');

    $payload = api_get_request_data();

    $questionId = trim((string)($payload['question_id'] ?? ''));
    $reportText = trim((string)($payload['report_text'] ?? ''));
    $questionSnapshot = $payload['question_snapshot'] ?? null;
    $payloadQualificationId = trim((string)($payload['qualification_id'] ?? ''));
    $payloadCourseId = trim((string)($payload['course_id'] ?? ''));
    $payloadTopicId = array_key_exists('topic_id', $payload)
        ? trim((string)$payload['topic_id'])
        : '';

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

    $questionMeta = study_get_question_meta_with_relations($pdo, $questionId);
    if (!$questionMeta['exists']) {
        api_error('Soru bulunamadı.', 404);
    }

    if ($payloadQualificationId !== '' && $payloadQualificationId !== $currentQualificationId) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'study.question-report.payload_qualification',
            'requested_qualification_id' => $payloadQualificationId,
            'current_qualification_id' => $currentQualificationId,
            'question_id' => $questionId,
        ]);
        api_error('Bu soru için erişim yetkiniz yok.', 403);
    }

    $resolvedScope = question_scope_resolve_accessible_scope(
        $pdo,
        $questionId,
        $currentQualificationId,
        ($payloadCourseId !== '' ? $payloadCourseId : null),
        $payloadTopicId
    );

    if ($resolvedScope === null) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'study.question-report.question_scope',
            'requested_qualification_id' => ($payloadQualificationId !== '' ? $payloadQualificationId : null),
            'requested_course_id' => ($payloadCourseId !== '' ? $payloadCourseId : null),
            'requested_topic_id' => ($payloadTopicId !== '' ? $payloadTopicId : null),
            'current_qualification_id' => $currentQualificationId,
            'question_id' => $questionId,
        ]);
        api_error('Bu soru için erişim yetkiniz yok.', 403);
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

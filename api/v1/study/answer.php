<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once dirname(__DIR__, 3) . '/includes/question_scope_helper.php';

api_require_method('POST');

$questionId = '';
$selectedAnswer = '';
$source = 'study';
$debugStep = 'init';

try {
    $debugStep = 'auth';
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study.answer');

    $debugStep = 'request_payload';
    $payload = api_get_request_data();

    $debugStep = 'input_validation';
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $selectedAnswer = strtoupper(trim((string)($payload['selected_answer'] ?? '')));

    if ($questionId === '') {
        api_error('question_id zorunludur.', 422);
    }

    if (mb_strlen($questionId) > 191) {
        api_error('Geçersiz question_id.', 422);
    }

    if (!in_array($selectedAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
        api_error('selected_answer sadece A/B/C/D/E olabilir.', 422);
    }

    $allowedSources = ['study', 'daily_quiz', 'exam'];
    $source = strtolower(trim((string)($payload['source'] ?? 'study')));
    if (!in_array($source, $allowedSources, true)) {
        api_error('Desteklenmeyen cevap kaynağı.', 422);
    }

    $sessionId = isset($payload['session_id']) ? trim((string)$payload['session_id']) : null;
    if ($sessionId === '') {
        $sessionId = null;
    }

    $debugStep = 'question_meta_fetch';
    try {
        $questionMeta = study_get_question_meta_with_relations($pdo, $questionId);
    } catch (Throwable $metaError) {
        api_send_json([
            'success' => false,
            'message' => 'question meta fetch failed',
            'step' => 'question_meta_fetch_failed',
            'exception_message' => $metaError->getMessage(),
            'debug_file' => $metaError->getFile(),
            'debug_line' => $metaError->getLine(),
            'debug_selected_answer' => $selectedAnswer,
            'debug_question_id' => $questionId,
            'debug_source' => $source,
        ], 500);
    }

    if (!$questionMeta['exists']) {
        api_error('Soru bulunamadı.', 404);
    }

    $payloadQualificationId = trim((string)($payload['qualification_id'] ?? ''));
    $payloadCourseId = trim((string)($payload['course_id'] ?? ''));
    $payloadTopicId = array_key_exists('topic_id', $payload)
        ? trim((string)$payload['topic_id'])
        : '';

    if ($payloadQualificationId !== '' && $payloadQualificationId !== $currentQualificationId) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'study.answer.payload_qualification',
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
            'context' => 'study.answer.question_scope',
            'requested_qualification_id' => ($payloadQualificationId !== '' ? $payloadQualificationId : null),
            'requested_course_id' => ($payloadCourseId !== '' ? $payloadCourseId : null),
            'requested_topic_id' => ($payloadTopicId !== '' ? $payloadTopicId : null),
            'current_qualification_id' => $currentQualificationId,
            'question_id' => $questionId,
        ]);
        api_error('Bu soru için erişim yetkiniz yok.', 403);
    }

    $selectedQualificationId = (string)$resolvedScope['qualification_id'];
    $selectedCourseId = (string)$resolvedScope['course_id'];
    $selectedTopicId = $resolvedScope['topic_id'] ?? null;

    if ($selectedQualificationId !== $currentQualificationId) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'study.answer.resolved_qualification',
            'requested_qualification_id' => $selectedQualificationId,
            'current_qualification_id' => $currentQualificationId,
            'question_id' => $questionId,
        ]);
        api_error('Bu soru için erişim yetkiniz yok.', 403);
    }

    $debugStep = 'option_e_check';
    try {
        $optionE = trim((string)($questionMeta['option_e'] ?? ''));
        if ($selectedAnswer === 'E' && $optionE === '') {
            api_error('Bu soru için E şıkkı bulunmuyor.', 422);
        }
    } catch (Throwable $optionError) {
        api_send_json([
            'success' => false,
            'message' => 'option_e kontrolü sırasında hata oluştu: ' . $optionError->getMessage(),
            'step' => 'option_e_check_failed',
            'exception_message' => $optionError->getMessage(),
            'debug_file' => $optionError->getFile(),
            'debug_line' => $optionError->getLine(),
            'debug_selected_answer' => $selectedAnswer,
            'debug_question_id' => $questionId,
            'debug_source' => $source,
        ], 500);
    }

    $debugStep = 'correctness_compute';
    $computedIsCorrect = false;
    if (!empty($questionMeta['correct_answer'])) {
        $computedIsCorrect = ($selectedAnswer === strtoupper((string)$questionMeta['correct_answer']));
    } elseif (array_key_exists('is_correct', $payload)) {
        $computedIsCorrect = filter_var($payload['is_correct'], FILTER_VALIDATE_BOOLEAN);
    }

    $debugStep = 'progress_upsert';
    try {
        $progress = study_upsert_answer_progress($pdo, $userId, $questionId, $selectedAnswer, $computedIsCorrect);
    } catch (Throwable $progressError) {
        api_send_json([
            'success' => false,
            'message' => $progressError->getMessage(),
            'step' => 'progress_upsert_failed',
            'exception_message' => $progressError->getMessage(),
            'debug_file' => $progressError->getFile(),
            'debug_line' => $progressError->getLine(),
            'debug_selected_answer' => $selectedAnswer,
            'debug_question_id' => $questionId,
            'debug_source' => $source,
        ], 500);
    }

    $wrongScoreUpdateWarning = null;
    $debugStep = 'wrong_score_update';
    try {
        study_update_question_wrong_score($pdo, [
            'user_id' => $userId,
            'question_id' => $questionId,
            'qualification_id' => $selectedQualificationId,
            'course_id' => ($selectedCourseId !== '' ? $selectedCourseId : null),
            'topic_id' => $selectedTopicId,
            'is_correct' => $computedIsCorrect,
        ]);
    } catch (Throwable $wrongScoreError) {
        $wrongScoreUpdateWarning = $wrongScoreError->getMessage();
    }

    // user_progress akışını bozmadan event-level kayıt ekle (tablo/kolon yoksa sessizce geç)
    $eventInsertWarning = null;
    $debugStep = 'event_insert';
    try {
        study_insert_attempt_event($pdo, [
            'user_id' => $userId,
            'question_id' => $questionId,
            'course_id' => ($selectedCourseId !== '' ? $selectedCourseId : null),
            'qualification_id' => $selectedQualificationId,
            'topic_id' => $selectedTopicId,
            'session_id' => $sessionId,
            'source' => $source,
            'selected_answer' => $selectedAnswer,
            'is_correct' => $computedIsCorrect,
        ]);
    } catch (Throwable $eventError) {
        // Event logging best-effort, ana cevap kaydetme akışını bozma.
        $eventInsertWarning = $eventError->getMessage();
    }

    $questionHistory = null;
    $questionHistoryWarning = null;
    $debugStep = 'question_history_build';
    try {
        $questionHistory = study_get_question_answer_history($pdo, $userId, $questionId);
    } catch (Throwable $questionHistoryError) {
        // History üretimi best-effort, ana cevap kaydetme akışını bozma.
        $questionHistoryWarning = $questionHistoryError->getMessage();
    }

    $debugStep = 'response_success';
    api_qualification_access_log('study qualification returned', [
        'context' => 'study.answer',
        'study qualification returned' => $currentQualificationId,
        'question_id' => $questionId,
    ]);

    api_success('Cevap kaydedildi.', [
        'progress' => $progress,
        'question_history' => $questionHistory,
        'question_history_warning' => $questionHistoryWarning,
        'wrong_score_update_warning' => $wrongScoreUpdateWarning,
        'event_insert_warning' => $eventInsertWarning,
    ]);
} catch (Throwable $e) {
    api_send_json([
        'success' => false,
        'message' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine(),
        'debug_selected_answer' => $selectedAnswer,
        'debug_question_id' => $questionId,
        'debug_source' => $source,
        'debug_step' => $debugStep,
    ], 500);
}

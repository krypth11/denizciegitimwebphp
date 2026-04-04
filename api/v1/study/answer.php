<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once dirname(__DIR__) . '/maritime_content_helper.php';

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

    $allowedSources = ['study', 'daily_quiz', 'exam', 'maritime_english', 'maritime-english', 'me', 'me_quiz', 'maritime_english_quiz'];
    $source = strtolower(trim((string)($payload['source'] ?? 'study')));
    if (!in_array($source, $allowedSources, true)) {
        $source = 'study';
    }

    $sessionId = isset($payload['session_id']) ? trim((string)$payload['session_id']) : null;
    if ($sessionId === '') {
        $sessionId = null;
    }

    $payloadScope = strtolower(trim((string)($payload['question_scope'] ?? $payload['question_type'] ?? '')));
    $isMaritimeEnglish = mc_is_maritime_english_source($source)
        || in_array($payloadScope, ['maritime_english', 'maritime-english', 'me'], true);

    if ($isMaritimeEnglish) {
        $debugStep = 'me_question_meta_fetch';
        $questionMeta = mc_get_maritime_english_question_meta($pdo, $questionId);
        if (!$questionMeta['exists']) {
            api_error('Maritime English sorusu bulunamadı.', 404);
        }

        $debugStep = 'me_option_e_check';
        if ($selectedAnswer === 'E' && empty($questionMeta['option_e'])) {
            api_error('Bu Maritime English sorusu için E şıkkı bulunmuyor.', 422);
        }

        $debugStep = 'me_correctness_compute';
        $computedIsCorrect = false;
        if (!empty($questionMeta['correct_answer'])) {
            $computedIsCorrect = ($selectedAnswer === strtoupper((string)$questionMeta['correct_answer']));
        } elseif (array_key_exists('is_correct', $payload)) {
            $computedIsCorrect = filter_var($payload['is_correct'], FILTER_VALIDATE_BOOLEAN);
        }

        $eventInsertWarning = null;
        $debugStep = 'me_event_insert';
        try {
            study_insert_attempt_event($pdo, [
                'user_id' => $userId,
                'question_id' => $questionId,
                'course_id' => null,
                'qualification_id' => $questionMeta['category_id'] ?? null,
                'topic_id' => $questionMeta['topic_id'] ?? null,
                'session_id' => $sessionId,
                'source' => 'maritime_english',
                'selected_answer' => $selectedAnswer,
                'is_correct' => $computedIsCorrect,
            ]);
        } catch (Throwable $eventError) {
            $eventInsertWarning = $eventError->getMessage();
        }

        api_success('Maritime English cevabı kaydedildi.', [
            'source' => 'maritime_english',
            'question_id' => $questionId,
            'selected_answer' => $selectedAnswer,
            'is_correct' => $computedIsCorrect,
            'progress' => null,
            'event_insert_warning' => $eventInsertWarning,
        ]);
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

    $questionQualificationId = trim((string)($questionMeta['qualification_id'] ?? ''));
    if ($questionQualificationId !== '' && $questionQualificationId !== $currentQualificationId) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'study.answer.question_id',
            'requested_qualification_id' => $questionQualificationId,
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

    // user_progress akışını bozmadan event-level kayıt ekle (tablo/kolon yoksa sessizce geç)
    $eventInsertWarning = null;
    $debugStep = 'event_insert';
    try {
        study_insert_attempt_event($pdo, [
            'user_id' => $userId,
            'question_id' => $questionId,
            'course_id' => $questionMeta['course_id'] ?? null,
            'qualification_id' => $questionMeta['qualification_id'] ?? null,
            'topic_id' => $questionMeta['topic_id'] ?? null,
            'session_id' => $sessionId,
            'source' => $source,
            'selected_answer' => $selectedAnswer,
            'is_correct' => $computedIsCorrect,
        ]);
    } catch (Throwable $eventError) {
        // Event logging best-effort, ana cevap kaydetme akışını bozma.
        $eventInsertWarning = $eventError->getMessage();
    }

    $debugStep = 'response_success';
    api_success('Cevap kaydedildi.', [
        'progress' => $progress,
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

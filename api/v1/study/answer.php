<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();

    $questionId = trim((string)($payload['question_id'] ?? ''));
    $selectedAnswer = strtoupper(trim((string)($payload['selected_answer'] ?? '')));

    if ($questionId === '') {
        api_error('question_id zorunludur.', 422);
    }

    if (mb_strlen($questionId) > 191) {
        api_error('Geçersiz question_id.', 422);
    }

    if (!in_array($selectedAnswer, ['A', 'B', 'C', 'D'], true)) {
        api_error('selected_answer sadece A/B/C/D olabilir.', 422);
    }

    $questionMeta = study_get_question_meta_with_relations($pdo, $questionId);
    if (!$questionMeta['exists']) {
        api_error('Soru bulunamadı.', 404);
    }

    $sessionId = isset($payload['session_id']) ? trim((string)$payload['session_id']) : null;
    if ($sessionId === '') {
        $sessionId = null;
    }

    $computedIsCorrect = false;
    if (!empty($questionMeta['correct_answer'])) {
        $computedIsCorrect = ($selectedAnswer === strtoupper((string)$questionMeta['correct_answer']));
    } elseif (array_key_exists('is_correct', $payload)) {
        $computedIsCorrect = filter_var($payload['is_correct'], FILTER_VALIDATE_BOOLEAN);
    }

    $progress = study_upsert_answer_progress($pdo, $userId, $questionId, $selectedAnswer, $computedIsCorrect);

    // user_progress akışını bozmadan event-level kayıt ekle (tablo/kolon yoksa sessizce geç)
    try {
        study_insert_attempt_event($pdo, [
            'user_id' => $userId,
            'question_id' => $questionId,
            'course_id' => $questionMeta['course_id'] ?? null,
            'qualification_id' => $questionMeta['qualification_id'] ?? null,
            'topic_id' => $questionMeta['topic_id'] ?? null,
            'session_id' => $sessionId,
            'source' => 'study',
            'selected_answer' => $selectedAnswer,
            'is_correct' => $computedIsCorrect,
        ]);
    } catch (Throwable $eventError) {
        // Event logging best-effort, ana cevap kaydetme akışını bozma.
    }

    api_success('Cevap kaydedildi.', [
        'progress' => $progress,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

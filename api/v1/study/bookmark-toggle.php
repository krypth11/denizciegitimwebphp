<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $questionId = trim((string)($payload['question_id'] ?? ''));

    if ($questionId === '') {
        api_error('question_id zorunludur.', 422);
    }

    if (mb_strlen($questionId) > 191) {
        api_error('Geçersiz question_id.', 422);
    }

    $questionMeta = study_get_question_meta($pdo, $questionId);
    if (!$questionMeta['exists']) {
        api_error('Soru bulunamadı.', 404);
    }

    $result = study_toggle_bookmark($pdo, $userId, $questionId);

    api_success('Bookmark durumu güncellendi.', $result);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

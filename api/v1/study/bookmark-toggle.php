<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study.bookmark-toggle');

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

    $questionQualificationId = trim((string)($questionMeta['qualification_id'] ?? ''));
    if ($questionQualificationId !== '' && $questionQualificationId !== $currentQualificationId) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'study.bookmark-toggle.question_id',
            'requested_qualification_id' => $questionQualificationId,
            'current_qualification_id' => $currentQualificationId,
            'question_id' => $questionId,
        ]);
        api_error('Bu soru için erişim yetkiniz yok.', 403);
    }

    $result = study_toggle_bookmark($pdo, $userId, $questionId);

    api_qualification_access_log('study qualification returned', [
        'context' => 'study.bookmark-toggle',
        'study qualification returned' => $currentQualificationId,
        'question_id' => $questionId,
    ]);

    api_success('Bookmark durumu güncellendi.', $result);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

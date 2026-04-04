<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study.sessions.create');

    $payload = api_get_request_data();

    if (array_key_exists('qualification_id', $payload)) {
        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            (string)($payload['qualification_id'] ?? ''),
            'study.sessions.create.payload'
        );
    }

    $payload['qualification_id'] = $currentQualificationId;

    $intFields = ['requested_question_count', 'served_question_count', 'correct_count', 'wrong_count', 'duration_seconds'];
    foreach ($intFields as $field) {
        if (array_key_exists($field, $payload)) {
            $payload[$field] = (int)$payload[$field];
            if ($payload[$field] < 0) {
                api_error($field . ' negatif olamaz.', 422);
            }
        }
    }

    $session = study_insert_session($pdo, $userId, $payload);

    api_qualification_access_log('study qualification returned', [
        'context' => 'study.sessions.create',
        'study qualification returned' => $currentQualificationId,
        'session_id' => ($session['id'] ?? null),
    ]);

    api_success('Study session oluşturuldu.', [
        'session' => $session,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

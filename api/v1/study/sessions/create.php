<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();

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

    api_success('Study session oluşturuldu.', [
        'session' => $session,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

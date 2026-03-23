<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $questionIds = $payload['question_ids'] ?? null;

    if (!is_array($questionIds)) {
        api_error('question_ids alanı dizi olmalıdır.', 422);
    }

    if (count($questionIds) > 500) {
        api_error('Tek istekte en fazla 500 soru gönderilebilir.', 422);
    }

    $cleanIds = [];
    foreach ($questionIds as $qid) {
        $id = trim((string)$qid);
        if ($id === '') {
            continue;
        }
        if (mb_strlen($id) > 191) {
            api_error('Geçersiz question_id değeri.', 422);
        }
        $cleanIds[] = $id;
    }

    $progressMap = study_get_progress_map($pdo, $userId, $cleanIds);

    api_success('Progress map getirildi.', [
        'progress_map' => $progressMap,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

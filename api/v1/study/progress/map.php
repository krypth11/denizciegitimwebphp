<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';
require_once dirname(__DIR__, 4) . '/includes/question_scope_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'study.progress.map');

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

    if (!empty($cleanIds)) {
        $accessibleIds = question_scope_filter_accessible_question_ids($pdo, $cleanIds, $currentQualificationId);
        $accessibleSet = array_fill_keys($accessibleIds, true);
        $deniedIds = [];

        foreach (array_values(array_unique($cleanIds)) as $qid) {
            if (!isset($accessibleSet[$qid])) {
                $deniedIds[] = $qid;
            }
        }

        if ($deniedIds) {
            api_qualification_access_log('qualification access rejected', [
                'context' => 'study.progress.map.question_ids',
                'endpoint' => 'api/v1/study/progress/map.php',
                'user_id' => $userId,
                'current_qualification_id' => $currentQualificationId,
                'denied_question_ids' => $deniedIds,
            ]);
            api_error('Bu soru için erişim yetkiniz yok.', 403);
        }
    }

    $progressMap = study_get_progress_map($pdo, $userId, $cleanIds);

    api_success('Progress map getirildi.', [
        'progress_map' => $progressMap,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

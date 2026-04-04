<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/study_helper.php';

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
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $sql = 'SELECT q.id AS id, c.qualification_id AS qualification_id
                FROM questions q
                LEFT JOIN courses c ON q.course_id = c.id
                WHERE q.id IN (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($cleanIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $qid = (string)($row['id'] ?? '');
            $qidQualificationId = trim((string)($row['qualification_id'] ?? ''));
            if ($qid !== '' && $qidQualificationId !== '' && $qidQualificationId !== $currentQualificationId) {
                api_qualification_access_log('qualification access rejected', [
                    'context' => 'study.progress.map.question_ids',
                    'requested_qualification_id' => $qidQualificationId,
                    'current_qualification_id' => $currentQualificationId,
                    'question_id' => $qid,
                ]);
                api_error('Bu soru için erişim yetkiniz yok.', 403);
            }
        }
    }

    $progressMap = study_get_progress_map($pdo, $userId, $cleanIds);

    api_success('Progress map getirildi.', [
        'progress_map' => $progressMap,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

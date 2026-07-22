<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $payload = api_get_request_data();
    $attemptId = trim((string)($payload['attempt_id'] ?? ''));
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $state = strtolower(trim((string)($payload['state'] ?? '')));
    if ($attemptId === '' || $questionId === '' || !in_array($state, ['started', 'ended'], true)) {
        api_error('Geçersiz video oynatma isteği.', 422);
    }

    $attempt = mock_exam_find_attempt_by_id($pdo, $userId, $attemptId);
    if (!$attempt || (string)($attempt['status'] ?? '') !== 'in_progress') {
        api_error('Aktif deneme bulunamadı.', 404);
    }
    $check = $pdo->prepare('SELECT 1 FROM mock_exam_attempt_questions aq INNER JOIN questions q ON q.id = aq.question_id WHERE aq.attempt_id = ? AND aq.question_id = ? AND q.video_solution_id IS NOT NULL LIMIT 1');
    $check->execute([$attemptId, $questionId]);
    if (!$check->fetchColumn()) api_error('Bu soru için video çözümü bulunamadı.', 422);

    if ($state === 'started') {
        $stmt = $pdo->prepare('UPDATE mock_exam_attempts SET video_pause_started_at = COALESCE(video_pause_started_at, NOW()), updated_at = NOW() WHERE id = ? AND user_id = ? AND status = ?');
        $stmt->execute([$attemptId, $userId, 'in_progress']);
    } else {
        $stmt = $pdo->prepare('UPDATE mock_exam_attempts SET video_paused_seconds = video_paused_seconds + IF(video_pause_started_at IS NULL, 0, GREATEST(0, TIMESTAMPDIFF(SECOND, video_pause_started_at, NOW()))), video_pause_started_at = NULL, updated_at = NOW() WHERE id = ? AND user_id = ? AND status = ?');
        $stmt->execute([$attemptId, $userId, 'in_progress']);
    }
    api_success('Video sayaç durumu güncellendi.');
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

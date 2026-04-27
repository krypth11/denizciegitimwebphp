<?php

require_once dirname(__DIR__, 2) . '/api_bootstrap.php';
require_once dirname(__DIR__, 2) . '/auth_helper.php';
require_once dirname(__DIR__, 2) . '/mock_exam_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    if (empty($auth['user']['is_admin'])) {
        api_error('Admin yetkisi gerekli.', 403);
    }

    $payload = api_get_request_data();
    if (!is_array($payload)) {
        $payload = [];
    }

    $qualificationId = trim((string)($payload['qualification_id'] ?? ''));
    if ($qualificationId === '') {
        api_error('qualification_id zorunludur.', 422);
    }

    $qualificationCols = get_table_columns($pdo, 'qualifications');
    if (!$qualificationCols) {
        api_error('qualifications tablosu bulunamadı.', 422);
    }
    $qualificationIdCol = mock_exam_pick($qualificationCols, ['id'], true);
    $qualificationCheckStmt = $pdo->prepare('SELECT 1 FROM `qualifications` WHERE ' . mock_exam_q($qualificationIdCol) . ' = ? LIMIT 1');
    $qualificationCheckStmt->execute([$qualificationId]);
    if (!$qualificationCheckStmt->fetchColumn()) {
        api_error('Yeterlilik bulunamadı.', 404);
    }

    $questionCountRaw = $payload['question_count'] ?? null;
    $passingScoreRaw = $payload['passing_score'] ?? null;
    $durationMinutesRaw = $payload['duration_minutes'] ?? null;
    $isActiveRaw = $payload['is_active'] ?? null;

    if (filter_var($questionCountRaw, FILTER_VALIDATE_INT) === false) {
        api_error('question_count alanı geçersiz.', 422);
    }
    if (!is_numeric($passingScoreRaw)) {
        api_error('passing_score alanı geçersiz.', 422);
    }
    if (filter_var($durationMinutesRaw, FILTER_VALIDATE_INT) === false) {
        api_error('duration_minutes alanı geçersiz.', 422);
    }
    if (!in_array((string)$isActiveRaw, ['0', '1', 0, 1], true)) {
        api_error('is_active alanı 0 veya 1 olmalıdır.', 422);
    }

    $questionCount = (int)$questionCountRaw;
    $passingScore = (float)$passingScoreRaw;
    $durationMinutes = (int)$durationMinutesRaw;
    $isActive = ((int)$isActiveRaw === 1) ? 1 : 0;

    if ($questionCount < 1 || $questionCount > 200) {
        api_error('question_count 1 - 200 arasında olmalıdır.', 422);
    }
    if ($passingScore < 0 || $passingScore > 100) {
        api_error('passing_score 0 - 100 arasında olmalıdır.', 422);
    }
    if ($durationMinutes < 1 || $durationMinutes > 300) {
        api_error('duration_minutes 1 - 300 arasında olmalıdır.', 422);
    }

    $updated = mock_exam_upsert_qualification_exam_settings($pdo, $qualificationId, [
        'question_count' => $questionCount,
        'passing_score' => $passingScore,
        'duration_minutes' => $durationMinutes,
        'is_active' => $isActive,
    ]);

    api_success('Sınav ayarı güncellendi.', [
        'item' => [
            'qualification_id' => $qualificationId,
            'question_count' => (int)($updated['question_count'] ?? 20),
            'passing_score' => (float)($updated['passing_score'] ?? 60),
            'duration_minutes' => (int)($updated['duration_minutes'] ?? 40),
            'is_active' => (int)($updated['is_active'] ?? 1),
        ],
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

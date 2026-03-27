<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ai_question_review_helper.php';

$user = require_admin();

try {
    if (!ai_review_table_exists($pdo, 'question_ai_review_batches') || !ai_review_table_exists($pdo, 'question_ai_reviews')) {
        ai_review_json(false, 'AI review tabloları bulunamadı.', [], 500);
    }

    $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
    $courseId = trim((string)($_POST['course_id'] ?? ''));
    $requestedCount = (int)($_POST['requested_count'] ?? 10);
    if ($requestedCount < 1 || $requestedCount > 500) {
        ai_review_json(false, 'requested_count 1-500 arasında olmalıdır.', [], 422);
    }

    $batchId = generate_uuid();
    $now = date('Y-m-d H:i:s');

    $batchInsert = $pdo->prepare('INSERT INTO question_ai_review_batches
        (id, qualification_id, course_id, requested_count, actual_count, status, error_message, created_by_user_id, started_at, completed_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, 0, ?, NULL, ?, ?, NULL, ?, ?)');
    $batchInsert->execute([
        $batchId,
        $qualificationId !== '' ? $qualificationId : null,
        $courseId !== '' ? $courseId : null,
        $requestedCount,
        'queued',
        $user['user_id'] ?? null,
        $now,
        $now,
        $now,
    ]);

    $pdo->prepare('UPDATE question_ai_review_batches SET status = ?, updated_at = ? WHERE id = ?')->execute(['running', date('Y-m-d H:i:s'), $batchId]);

    $where = ['1=1'];
    $params = [];
    if ($qualificationId !== '') {
        $where[] = 'c.qualification_id = ?';
        $params[] = $qualificationId;
    }
    if ($courseId !== '') {
        $where[] = 'q.course_id = ?';
        $params[] = $courseId;
    }

    $where[] = 'NOT EXISTS (
        SELECT 1 FROM question_ai_reviews rr
        WHERE rr.question_id = q.id AND rr.review_state = "reviewed"
    )';

    $sql = 'SELECT
                q.id AS question_id,
                q.question_text,
                q.option_a,
                q.option_b,
                q.option_c,
                q.option_d,
                q.option_e,
                q.correct_answer,
                q.explanation,
                c.name AS course_name,
                qual.name AS qualification_name
            FROM questions q
            LEFT JOIN courses c ON c.id = q.course_id
            LEFT JOIN qualifications qual ON qual.id = c.qualification_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY RAND()
            LIMIT ' . (int)$requestedCount;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $selectedQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $actualCount = count($selectedQuestions);

    $settings = ai_review_settings($pdo);
    if (($settings['api_key'] ?? '') === '') {
        throw new RuntimeException('AI ayarları eksik: api_key bulunamadı.');
    }

    $reviewInsert = $pdo->prepare('INSERT INTO question_ai_reviews
        (id, batch_id, question_id, ai_status, issue_types, confidence_score, ai_notes, suggested_fix, review_state, admin_action, admin_notes, reviewed_by_user_id, reviewed_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?)');

    foreach ($selectedQuestions as $q) {
        $ai = ai_review_call_ai($settings, $q);

        $reviewInsert->execute([
            generate_uuid(),
            $batchId,
            $q['question_id'],
            $ai['ai_status'],
            json_encode($ai['issue_types'], JSON_UNESCAPED_UNICODE),
            $ai['confidence_score'],
            $ai['ai_notes'],
            $ai['suggested_fix'],
            'pending',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
        ]);
    }

    $pdo->prepare('UPDATE question_ai_review_batches
        SET status = ?, actual_count = ?, completed_at = ?, error_message = NULL, updated_at = ?
        WHERE id = ?')
        ->execute(['completed', $actualCount, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $batchId]);

    ai_review_json(true, 'AI kontrol batch tamamlandı.', [
        'batch_id' => $batchId,
        'requested_count' => $requestedCount,
        'actual_count' => $actualCount,
    ]);
} catch (Throwable $e) {
    $safeMessage = ai_review_safe_excerpt($e->getMessage(), 280);
    if ($safeMessage === '') {
        $safeMessage = 'AI review sırasında beklenmeyen bir hata oluştu.';
    }

    if (!empty($batchId)) {
        try {
            $pdo->prepare('UPDATE question_ai_review_batches SET status = ?, error_message = ?, updated_at = ? WHERE id = ?')
                ->execute(['failed', $safeMessage, date('Y-m-d H:i:s'), $batchId]);
        } catch (Throwable $ignored) {
        }
    }
    ai_review_json(false, 'Batch başlatılamadı: ' . $safeMessage, [], 500);
}

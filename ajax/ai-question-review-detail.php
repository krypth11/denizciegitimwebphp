<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ai_question_review_helper.php';

require_admin();

try {
    $reviewId = trim((string)($_GET['review_id'] ?? ''));
    if ($reviewId === '') {
        ai_review_json(false, 'review_id gerekli.', [], 422);
    }

    $sql = 'SELECT
                r.id,
                r.batch_id,
                r.question_id,
                r.ai_status,
                r.issue_types,
                r.confidence_score,
                r.ai_notes,
                r.suggested_fix,
                r.review_state,
                r.admin_action,
                r.admin_notes,
                r.reviewed_by_user_id,
                r.reviewed_at,
                r.created_at,
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
            FROM question_ai_reviews r
            INNER JOIN questions q ON q.id = r.question_id
            LEFT JOIN courses c ON c.id = q.course_id
            LEFT JOIN qualifications qual ON qual.id = c.qualification_id
            WHERE r.id = ?
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$reviewId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        ai_review_json(false, 'İnceleme kaydı bulunamadı.', [], 404);
    }

    $issueTypes = json_decode((string)($row['issue_types'] ?? '[]'), true);
    if (!is_array($issueTypes)) $issueTypes = [];
    $row['issue_types'] = $issueTypes;

    ai_review_json(true, '', ['review' => $row]);
} catch (Throwable $e) {
    ai_review_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

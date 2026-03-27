<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ai_question_review_helper.php';

require_admin();

try {
    if (!ai_review_table_exists($pdo, 'question_ai_reviews')) {
        ai_review_json(false, 'question_ai_reviews tablosu bulunamadı.', [], 500);
    }

    $aiStatus = trim((string)($_GET['ai_status'] ?? ''));
    $reviewState = trim((string)($_GET['review_state'] ?? ''));
    $qualificationId = trim((string)($_GET['qualification_id'] ?? ''));
    $courseId = trim((string)($_GET['course_id'] ?? ''));

    $where = ['1=1'];
    $params = [];

    if ($aiStatus !== '' && in_array($aiStatus, ['ok', 'warning', 'error'], true)) {
        $where[] = 'r.ai_status = ?';
        $params[] = $aiStatus;
    }
    if ($reviewState !== '' && in_array($reviewState, ['pending', 'reviewed'], true)) {
        $where[] = 'r.review_state = ?';
        $params[] = $reviewState;
    }
    if ($qualificationId !== '') {
        $where[] = 'c.qualification_id = ?';
        $params[] = $qualificationId;
    }
    if ($courseId !== '') {
        $where[] = 'q.course_id = ?';
        $params[] = $courseId;
    }

    $sql = 'SELECT
                r.id,
                r.question_id,
                r.batch_id,
                r.ai_status,
                r.confidence_score,
                r.review_state,
                r.created_at,
                q.question_text,
                c.name AS course_name,
                c.id AS course_id,
                qual.name AS qualification_name,
                qual.id AS qualification_id
            FROM question_ai_reviews r
            INNER JOIN questions q ON q.id = r.question_id
            LEFT JOIN courses c ON c.id = q.course_id
            LEFT JOIN qualifications qual ON qual.id = c.qualification_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $qualifications = $pdo->query('SELECT id, name FROM qualifications ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $courses = $pdo->query('SELECT id, qualification_id, name FROM courses ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

    ai_review_json(true, '', [
        'reviews' => $reviews,
        'qualifications' => $qualifications,
        'courses' => $courses,
    ]);
} catch (Throwable $e) {
    ai_review_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

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
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? 10);
    if (!in_array($perPage, [10, 20, 50], true)) {
        $perPage = 10;
    }

    $offset = ($page - 1) * $perPage;

    $listWhere = ['1=1'];
    $listParams = [];

    if ($aiStatus !== '' && in_array($aiStatus, ['ok', 'warning', 'error'], true)) {
        $listWhere[] = 'r.ai_status = ?';
        $listParams[] = $aiStatus;
    }
    if ($reviewState !== '' && in_array($reviewState, ['pending', 'reviewed'], true)) {
        $listWhere[] = 'r.review_state = ?';
        $listParams[] = $reviewState;
    }
    if ($qualificationId !== '') {
        $listWhere[] = 'c.qualification_id = ?';
        $listParams[] = $qualificationId;
    }
    if ($courseId !== '') {
        $listWhere[] = 'q.course_id = ?';
        $listParams[] = $courseId;
    }

    $totalSql = 'SELECT COUNT(*)
                 FROM question_ai_reviews r
                 INNER JOIN questions q ON q.id = r.question_id
                 LEFT JOIN courses c ON c.id = q.course_id
                 WHERE ' . implode(' AND ', $listWhere);
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($listParams);
    $total = (int)$totalStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
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
            WHERE ' . implode(' AND ', $listWhere) . '
            ORDER BY
                CASE r.ai_status
                    WHEN "error" THEN 1
                    WHEN "warning" THEN 2
                    WHEN "ok" THEN 3
                    ELSE 4
                END ASC,
                r.created_at DESC,
                r.id DESC
            LIMIT ? OFFSET ?';

    $stmt = $pdo->prepare($sql);
    $listExecParams = $listParams;
    $listExecParams[] = $perPage;
    $listExecParams[] = $offset;
    $stmt->execute($listExecParams);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statsWhere = ['1=1'];
    $statsParams = [];
    if ($qualificationId !== '') {
        $statsWhere[] = 'c.qualification_id = ?';
        $statsParams[] = $qualificationId;
    }
    if ($courseId !== '') {
        $statsWhere[] = 'q.course_id = ?';
        $statsParams[] = $courseId;
    }

    $totalQuestionsSql = 'SELECT COUNT(*)
                          FROM questions q
                          LEFT JOIN courses c ON c.id = q.course_id
                          WHERE ' . implode(' AND ', $statsWhere);
    $totalQuestionsStmt = $pdo->prepare($totalQuestionsSql);
    $totalQuestionsStmt->execute($statsParams);
    $totalQuestions = (int)$totalQuestionsStmt->fetchColumn();

    $reviewStatsSql = 'SELECT
                        COUNT(DISTINCT r.question_id) AS reviewed_questions,
                        SUM(CASE WHEN r.ai_status = "error" AND r.review_state = "pending" THEN 1 ELSE 0 END) AS error_count,
                        SUM(CASE WHEN r.ai_status = "warning" AND r.review_state = "pending" THEN 1 ELSE 0 END) AS warning_count,
                        SUM(CASE WHEN r.ai_status = "ok" AND r.review_state = "pending" THEN 1 ELSE 0 END) AS ok_count,
                        SUM(CASE WHEN r.review_state = "reviewed" THEN 1 ELSE 0 END) AS reviewed_closed_count
                      FROM question_ai_reviews r
                      INNER JOIN questions q ON q.id = r.question_id
                      LEFT JOIN courses c ON c.id = q.course_id
                      WHERE ' . implode(' AND ', $statsWhere);
    $reviewStatsStmt = $pdo->prepare($reviewStatsSql);
    $reviewStatsStmt->execute($statsParams);
    $reviewStats = $reviewStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $reviewedQuestions = (int)($reviewStats['reviewed_questions'] ?? 0);
    $unreviewedQuestions = max(0, $totalQuestions - $reviewedQuestions);

    $stats = [
        'total_questions' => $totalQuestions,
        'reviewed_questions' => $reviewedQuestions,
        'unreviewed_questions' => $unreviewedQuestions,
        'error_count' => (int)($reviewStats['error_count'] ?? 0),
        'warning_count' => (int)($reviewStats['warning_count'] ?? 0),
        'ok_count' => (int)($reviewStats['ok_count'] ?? 0),
        'reviewed_closed_count' => (int)($reviewStats['reviewed_closed_count'] ?? 0),
    ];

    $qualWhere = ['1=1'];
    $qualParams = [];
    if ($qualificationId !== '') {
        $qualWhere[] = 'qual.id = ?';
        $qualParams[] = $qualificationId;
    }
    if ($courseId !== '') {
        $qualWhere[] = 'c.id = ?';
        $qualParams[] = $courseId;
    }

    $qualificationStatsSql = 'SELECT
                                qual.id AS qualification_id,
                                qual.name AS qualification_name,
                                COUNT(q.id) AS total_questions,
                                COUNT(DISTINCT r.question_id) AS reviewed_questions
                              FROM qualifications qual
                              LEFT JOIN courses c ON c.qualification_id = qual.id
                              LEFT JOIN questions q ON q.course_id = c.id
                              LEFT JOIN question_ai_reviews r ON r.question_id = q.id
                              WHERE ' . implode(' AND ', $qualWhere) . '
                              GROUP BY qual.id, qual.name
                              HAVING COUNT(q.id) > 0 OR COUNT(DISTINCT r.question_id) > 0
                              ORDER BY qual.name ASC';
    $qualificationStatsStmt = $pdo->prepare($qualificationStatsSql);
    $qualificationStatsStmt->execute($qualParams);
    $qualificationStatsRows = $qualificationStatsStmt->fetchAll(PDO::FETCH_ASSOC);

    $qualificationStats = array_map(static function ($row) {
        $totalQ = (int)($row['total_questions'] ?? 0);
        $reviewedQ = (int)($row['reviewed_questions'] ?? 0);
        return [
            'qualification_id' => $row['qualification_id'],
            'qualification_name' => $row['qualification_name'],
            'total_questions' => $totalQ,
            'reviewed_questions' => $reviewedQ,
            'unreviewed_questions' => max(0, $totalQ - $reviewedQ),
        ];
    }, $qualificationStatsRows);

    $qualifications = $pdo->query('SELECT id, name FROM qualifications ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
    $courses = $pdo->query('SELECT id, qualification_id, name FROM courses ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

    ai_review_json(true, '', [
        'reviews' => $reviews,
        'qualifications' => $qualifications,
        'courses' => $courses,
        'stats' => $stats,
        'qualification_stats' => $qualificationStats,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
        ],
    ]);
} catch (Throwable $e) {
    ai_review_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

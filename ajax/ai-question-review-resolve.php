<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ai_question_review_helper.php';

$user = require_admin();

try {
    $action = trim((string)($_POST['action_type'] ?? ''));
    $now = date('Y-m-d H:i:s');

    if ($action === 'bulk_dismiss_ok') {
        $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
        $courseId = trim((string)($_POST['course_id'] ?? ''));
        $aiStatusFilter = trim((string)($_POST['ai_status'] ?? ''));
        $reviewStateFilter = trim((string)($_POST['review_state'] ?? ''));

        $where = ['r.ai_status = "ok"', 'r.review_state = "pending"'];
        $params = [];

        if ($qualificationId !== '') {
            $where[] = 'c.qualification_id = ?';
            $params[] = $qualificationId;
        }
        if ($courseId !== '') {
            $where[] = 'q.course_id = ?';
            $params[] = $courseId;
        }
        if ($aiStatusFilter !== '') {
            $where[] = 'r.ai_status = ?';
            $params[] = $aiStatusFilter;
        }
        if ($reviewStateFilter !== '') {
            $where[] = 'r.review_state = ?';
            $params[] = $reviewStateFilter;
        }

        $countSql = 'SELECT COUNT(*)
                     FROM question_ai_reviews r
                     INNER JOIN questions q ON q.id = r.question_id
                     LEFT JOIN courses c ON c.id = q.course_id
                     WHERE ' . implode(' AND ', $where);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $targetCount = (int)$countStmt->fetchColumn();

        if ($targetCount < 1) {
            ai_review_json(true, 'Kapatılacak pending+ok kayıt bulunamadı.', ['affected_count' => 0]);
        }

        $pdo->beginTransaction();
        $sql = 'UPDATE question_ai_reviews r
                INNER JOIN questions q ON q.id = r.question_id
                LEFT JOIN courses c ON c.id = q.course_id
                SET r.review_state = ?,
                    r.admin_action = ?,
                    r.reviewed_by_user_id = ?,
                    r.reviewed_at = ?,
                    r.updated_at = ?
                WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare($sql);
        $execParams = array_merge([
            'reviewed',
            'dismissed',
            $user['user_id'] ?? null,
            $now,
            $now,
        ], $params);
        $stmt->execute($execParams);
        $affected = $stmt->rowCount();
        $pdo->commit();

        ai_review_json(true, $affected . ' kayıt sorun yok olarak kapatıldı.', ['affected_count' => $affected]);
    }

    $reviewId = trim((string)($_POST['review_id'] ?? ''));

    if ($reviewId === '' || !in_array($action, ['fixed', 'dismissed'], true)) {
        ai_review_json(false, 'Geçersiz istek.', [], 422);
    }

    $stmt = $pdo->prepare('SELECT id, question_id, review_state FROM question_ai_reviews WHERE id = ? LIMIT 1');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review) {
        ai_review_json(false, 'İnceleme kaydı bulunamadı.', [], 404);
    }

    $pdo->beginTransaction();

    if ($action === 'fixed') {
        $questionText = sanitize_input($_POST['question_text'] ?? '');
        $optionA = sanitize_input($_POST['option_a'] ?? '');
        $optionB = sanitize_input($_POST['option_b'] ?? '');
        $optionC = sanitize_input($_POST['option_c'] ?? '');
        $optionD = sanitize_input($_POST['option_d'] ?? '');
        $optionE = sanitize_input($_POST['option_e'] ?? '');
        $correct = strtoupper(trim((string)($_POST['correct_answer'] ?? '')));
        $explanation = sanitize_input($_POST['explanation'] ?? '');

        if ($questionText === '' || $optionA === '' || $optionB === '' || $optionC === '' || $optionD === '' || !in_array($correct, ['A', 'B', 'C', 'D', 'E'], true)) {
            $pdo->rollBack();
            ai_review_json(false, 'Soru güncelleme alanları geçersiz.', [], 422);
        }
        if ($correct === 'E' && $optionE === '') {
            $pdo->rollBack();
            ai_review_json(false, 'Doğru cevap E ise option_e zorunludur.', [], 422);
        }

        $qCols = get_table_columns($pdo, 'questions');
        $hasOptionE = is_array($qCols) && in_array('option_e', $qCols, true);

        if ($hasOptionE) {
            $qUpdate = $pdo->prepare('UPDATE questions
                SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, option_e = ?, correct_answer = ?, explanation = ?
                WHERE id = ?');
            $qUpdate->execute([
                $questionText,
                $optionA,
                $optionB,
                $optionC,
                $optionD,
                $optionE !== '' ? $optionE : null,
                $correct,
                $explanation,
                $review['question_id'],
            ]);
        } else {
            $qUpdate = $pdo->prepare('UPDATE questions
                SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_answer = ?, explanation = ?
                WHERE id = ?');
            $qUpdate->execute([
                $questionText,
                $optionA,
                $optionB,
                $optionC,
                $optionD,
                $correct,
                $explanation,
                $review['question_id'],
            ]);
        }
    }

    $adminNotes = $action === 'dismissed' ? sanitize_input($_POST['admin_notes'] ?? '') : null;

    $rUpdate = $pdo->prepare('UPDATE question_ai_reviews
        SET review_state = ?, admin_action = ?, admin_notes = ?, reviewed_by_user_id = ?, reviewed_at = ?, updated_at = ?
        WHERE id = ?');
    $rUpdate->execute([
        'reviewed',
        $action,
        $adminNotes,
        $user['user_id'] ?? null,
        $now,
        $now,
        $reviewId,
    ]);

    $pdo->commit();
    ai_review_json(true, 'İnceleme kapatıldı.', ['review_id' => $reviewId, 'admin_action' => $action]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ai_review_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

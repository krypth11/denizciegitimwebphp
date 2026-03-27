<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/ai_question_review_helper.php';

$user = require_admin();

try {
    $reviewId = trim((string)($_POST['review_id'] ?? ''));
    $action = trim((string)($_POST['action_type'] ?? ''));

    if ($reviewId === '' || !in_array($action, ['fixed', 'dismissed'], true)) {
        ai_review_json(false, 'Geçersiz istek.', [], 422);
    }

    $stmt = $pdo->prepare('SELECT id, question_id, review_state FROM question_ai_reviews WHERE id = ? LIMIT 1');
    $stmt->execute([$reviewId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review) {
        ai_review_json(false, 'İnceleme kaydı bulunamadı.', [], 404);
    }

    $now = date('Y-m-d H:i:s');
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

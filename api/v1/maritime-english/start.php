<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once __DIR__ . '/maritime_english_learning_helper.php';
api_require_method('POST');
try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'maritime-english.start');
    $payload = api_get_request_data();
    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE maritime_english_sessions SET status = 'expired', updated_at = NOW() WHERE user_id = ? AND status = 'active' AND expires_at <= NOW()")
        ->execute([$userId]);
    $active = $pdo->prepare("SELECT id FROM maritime_english_sessions WHERE user_id = ? AND status = 'active' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
    $active->execute([$userId]);
    $activeId = $active->fetchColumn();
    if ($activeId) {
        $itemCountStmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM maritime_english_session_items i
             INNER JOIN maritime_english_questions q
               ON q.id = i.question_id AND q.is_active = 1 AND q.deleted_at IS NULL
             INNER JOIN maritime_english_terms t
               ON t.id = i.term_id AND t.is_active = 1 AND t.content_status = \'published\'
             INNER JOIN maritime_english_categories c
               ON c.id = t.category_id AND c.is_active = 1
             WHERE i.session_id = ?'
        );
        $itemCountStmt->execute([(string)$activeId]);
        $sessionStmt = $pdo->prepare(
            'SELECT question_count FROM maritime_english_sessions WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $sessionStmt->execute([(string)$activeId, $userId]);
        $expectedItemCount = (int)$sessionStmt->fetchColumn();
        $actualItemCount = (int)$itemCountStmt->fetchColumn();
        $activePayload = me_session_payload($pdo, (string)$activeId, $userId);

        // Yönetim panelinden oturumda kullanılan soru/kelimeler silinmişse eski
        // oturumu tekrar tekrar açmaya çalışma. Oturumu güvenli biçimde kapatıp
        // güncel içerikten yeni bir oturum oluştur.
        $activeSessionBroken = $actualItemCount !== $expectedItemCount
            || ($activePayload['status'] === 'active' && $activePayload['next_question'] === null);
        if ($activeSessionBroken) {
            $pdo->prepare(
                "UPDATE maritime_english_sessions
                 SET status = 'abandoned', completed_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND user_id = ? AND status = 'active'"
            )->execute([(string)$activeId, $userId]);
            try {
                $data = me_create_session(
                    $pdo,
                    $userId,
                    $qualificationId,
                    $categoryId !== '' ? $categoryId : null
                );
            } catch (Throwable $createError) {
                // Yeni içerik de yoksa bozuk eski oturumun kapatılmasını geri
                // alma. Kullanıcı ana ekranda yeni içerik eklenmesini bekler.
                $pdo->commit();
                $status = (int)$createError->getCode();
                api_error(
                    $status >= 400 && $status < 500
                        ? $createError->getMessage()
                        : 'Maritime English oturumu başlatılamadı.',
                    $status >= 400 && $status < 500 ? $status : 500
                );
            }
        } else {
            $data = $activePayload;
        }
    } else {
        $data = me_create_session(
            $pdo,
            $userId,
            $qualificationId,
            $categoryId !== '' ? $categoryId : null
        );
    }
    $pdo->commit();
    api_send_json(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = (int)$e->getCode();
    api_error($status >= 400 && $status < 500 ? $e->getMessage() : 'Maritime English oturumu başlatılamadı.', $status >= 400 && $status < 500 ? $status : 500);
}

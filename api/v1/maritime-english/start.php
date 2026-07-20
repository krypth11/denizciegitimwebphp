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
    $data = $activeId ? me_session_payload($pdo, (string)$activeId, $userId) : me_create_session($pdo, $userId, $qualificationId, $categoryId !== '' ? $categoryId : null);
    $pdo->commit();
    api_send_json(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = (int)$e->getCode();
    api_error($status >= 400 && $status < 500 ? $e->getMessage() : 'Maritime English oturumu başlatılamadı.', $status >= 400 && $status < 500 ? $status : 500);
}

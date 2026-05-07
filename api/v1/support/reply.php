<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/support_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = trim((string)($auth['user']['id'] ?? ''));
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }
    if (api_is_guest_user($pdo, $userId)) {
        api_error('Misafir kullanıcılar destek talebine yanıt veremez.', 403);
    }

    $payload = api_get_request_data();
    $ticketId = trim((string)($payload['ticket_id'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));

    if ($ticketId === '' || $message === '') {
        api_error('ticket_id ve message zorunludur.', 422);
    }
    if (mb_strlen($message, 'UTF-8') > 5000) {
        api_error('Mesaj en fazla 5000 karakter olabilir.', 422);
    }

    $ticket = support_get_ticket_for_user($pdo, $ticketId, $userId);
    if (!$ticket) {
        api_error('Destek talebi bulunamadı.', 404);
    }

    $status = support_normalize_status((string)($ticket['status'] ?? 'submitted'));
    if ($status === 'completed') {
        api_error('Tamamlanan taleplere mesaj gönderemezsiniz.', 422);
    }

    $hasAdminReply = support_ticket_has_admin_reply($pdo, $ticketId);
    $followupCount = (int)($ticket['user_followup_count'] ?? 0);
    if ($hasAdminReply && $followupCount > 0) {
        api_error('Bu talep için follow-up hakkınız doldu.', 422);
    }

    $pdo->beginTransaction();
    try {
        $messageId = support_add_message($pdo, $ticketId, $userId, 'user', $message);
        if ($hasAdminReply) {
            support_ticket_increment_followup($pdo, $ticketId);
        }
        $pdo->commit();
    } catch (Throwable $inner) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $inner;
    }

    api_success('Mesaj gönderildi.', ['message_id' => $messageId]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

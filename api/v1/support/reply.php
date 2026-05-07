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

    $messages = support_fetch_ticket_messages($pdo, $ticketId);
    $replyMeta = support_calculate_reply_meta($ticket, $messages);
    if (!$replyMeta['can_reply']) {
        api_error((string)($replyMeta['reply_disabled_reason'] ?? 'Bu talep için mesaj gönderemezsiniz.'), 422);
    }

    $pdo->beginTransaction();
    try {
        $messageId = support_add_message($pdo, $ticketId, $userId, 'user', $message);
        support_touch_ticket_updated_at($pdo, $ticketId);
        if ($replyMeta['has_admin_reply']) {
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

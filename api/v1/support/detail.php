<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/support_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = trim((string)($auth['user']['id'] ?? ''));
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }
    if (api_is_guest_user($pdo, $userId)) {
        api_error('Misafir kullanıcılar destek talebi görüntüleyemez.', 403);
    }

    $ticketId = trim((string)($_GET['id'] ?? ''));
    if ($ticketId === '') {
        api_error('id parametresi zorunludur.', 422);
    }

    $ticket = support_get_ticket_for_user($pdo, $ticketId, $userId);
    if (!$ticket) {
        api_error('Destek talebi bulunamadı.', 404);
    }

    $messages = support_fetch_ticket_messages($pdo, $ticketId);

    api_success('Destek talebi detayı getirildi.', [
        'ticket' => [
            'id' => (string)($ticket['id'] ?? ''),
            'subject' => (string)($ticket['subject'] ?? ''),
            'status' => support_normalize_status((string)($ticket['status'] ?? 'submitted')),
            'user_followup_count' => (int)($ticket['user_followup_count'] ?? 0),
            'completed_at' => $ticket['completed_at'] ?? null,
            'created_at' => $ticket['created_at'] ?? null,
        ],
        'messages' => array_map(static function (array $m): array {
            return [
                'id' => (string)($m['id'] ?? ''),
                'ticket_id' => (string)($m['ticket_id'] ?? ''),
                'sender_user_id' => (string)($m['sender_user_id'] ?? ''),
                'sender_type' => (string)($m['sender_type'] ?? ''),
                'message' => (string)($m['message'] ?? ''),
                'created_at' => $m['created_at'] ?? null,
            ];
        }, $messages),
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

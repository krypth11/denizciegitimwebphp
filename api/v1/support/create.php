<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/support_helper.php';
require_once dirname(__DIR__, 3) . '/includes/admin_notification_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = trim((string)($auth['user']['id'] ?? ''));
    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }
    if (api_is_guest_user($pdo, $userId)) {
        api_error('Misafir kullanıcılar destek talebi oluşturamaz.', 403);
    }

    if (support_user_open_ticket_count($pdo, $userId) >= 2) {
        api_error('Aynı anda en fazla 2 açık destek talebiniz olabilir.', 422);
    }

    $payload = api_get_request_data();
    $subject = trim((string)($payload['subject'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));

    if ($subject === '' || $message === '') {
        api_error('subject ve message zorunludur.', 422);
    }
    if (mb_strlen($subject, 'UTF-8') > 191) {
        api_error('Konu en fazla 191 karakter olabilir.', 422);
    }
    if (mb_strlen($message, 'UTF-8') > 5000) {
        api_error('Mesaj en fazla 5000 karakter olabilir.', 422);
    }

    $ticket = support_ticket_schema($pdo);
    $ticketId = generate_uuid();

    $pdo->beginTransaction();
    try {
        $setCols = [$ticket['id'], $ticket['user_id'], $ticket['subject'], $ticket['status'], $ticket['created_at']];
        $setVals = [$ticketId, $userId, $subject, 'submitted', date('Y-m-d H:i:s')];
        if ($ticket['user_followup_count']) {
            $setCols[] = $ticket['user_followup_count'];
            $setVals[] = 0;
        }
        if ($ticket['updated_at']) {
            $setCols[] = $ticket['updated_at'];
            $setVals[] = date('Y-m-d H:i:s');
        }

        $sql = "INSERT INTO `{$ticket['table']}` (" . implode(', ', array_map(static fn($c) => "`{$c}`", $setCols)) . ")"
            . " VALUES (" . implode(', ', array_fill(0, count($setCols), '?')) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($setVals);

        support_add_message($pdo, $ticketId, $userId, 'user', $message);
        $pdo->commit();
        admin_notification_create($pdo, ['event_type'=>'support_ticket','source_type'=>'support','source_id'=>$ticketId,'title'=>'Yeni destek talebi','message'=>$subject,'severity'=>'normal','target_url'=>'/pages/support-tickets.php?ticket_id='.rawurlencode($ticketId)]);
    } catch (Throwable $inner) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $inner;
    }

    api_success('Destek talebi oluşturuldu.', ['ticket_id' => $ticketId]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

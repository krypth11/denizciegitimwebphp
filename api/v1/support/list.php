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

    $ticket = support_ticket_schema($pdo);
    $sql = "SELECT `{$ticket['id']}` AS id, `{$ticket['subject']}` AS subject, `{$ticket['status']}` AS status, "
        . ($ticket['user_followup_count'] ? "COALESCE(`{$ticket['user_followup_count']}`,0) AS user_followup_count, " : '0 AS user_followup_count, ')
        . ($ticket['completed_at'] ? "`{$ticket['completed_at']}` AS completed_at, " : 'NULL AS completed_at, ')
        . "`{$ticket['created_at']}` AS created_at, "
        . ($ticket['updated_at'] ? "`{$ticket['updated_at']}` AS updated_at" : 'NULL AS updated_at')
        . " FROM `{$ticket['table']}` WHERE `{$ticket['user_id']}` = ?"
        . " ORDER BY `{$ticket['created_at']}` DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ticketIds = array_values(array_filter(array_map(static function (array $row): string {
        return trim((string)($row['id'] ?? ''));
    }, $rows), static function (string $id): bool {
        return $id !== '';
    }));
    $messagePreviewsByTicket = support_fetch_ticket_message_previews($pdo, $ticketIds);

    $tickets = array_map(static function (array $r) use ($messagePreviewsByTicket): array {
        $ticketId = (string)($r['id'] ?? '');
        $previewRow = $messagePreviewsByTicket[$ticketId] ?? null;
        $latestMessage = is_array($previewRow) ? ($previewRow['latest_message'] ?? null) : null;
        $firstUserMessage = is_array($previewRow) ? ($previewRow['first_user_message'] ?? null) : null;
        $messagePreview = $latestMessage ?? $firstUserMessage;

        return [
            'id' => $ticketId,
            'subject' => (string)($r['subject'] ?? ''),
            'status' => support_normalize_status((string)($r['status'] ?? 'submitted')),
            'user_followup_count' => (int)($r['user_followup_count'] ?? 0),
            'completed_at' => $r['completed_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'updated_at' => $r['updated_at'] ?? null,
            'message_preview' => support_preview_text($messagePreview, 120),
        ];
    }, $rows);

    api_success('Destek talepleri getirildi.', ['tickets' => $tickets]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

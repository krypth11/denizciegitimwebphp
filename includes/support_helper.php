<?php

function support_ticket_schema(PDO $pdo): array
{
    $columns = get_table_columns($pdo, 'support_tickets');
    if (!$columns) {
        throw new RuntimeException('support_tickets tablosu okunamadı.');
    }

    $pick = static function (array $candidates, bool $required = true) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        if ($required) {
            throw new RuntimeException('Gerekli support_tickets kolonu bulunamadı: ' . implode(', ', $candidates));
        }
        return null;
    };

    return [
        'table' => 'support_tickets',
        'id' => $pick(['id']),
        'user_id' => $pick(['user_id']),
        'subject' => $pick(['subject']),
        'status' => $pick(['status']),
        'user_followup_count' => $pick(['user_followup_count'], false),
        'completed_at' => $pick(['completed_at'], false),
        'created_at' => $pick(['created_at']),
        'updated_at' => $pick(['updated_at'], false),
    ];
}

function support_ticket_message_schema(PDO $pdo): array
{
    $columns = get_table_columns($pdo, 'support_ticket_messages');
    if (!$columns) {
        throw new RuntimeException('support_ticket_messages tablosu okunamadı.');
    }

    $pick = static function (array $candidates, bool $required = true) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        if ($required) {
            throw new RuntimeException('Gerekli support_ticket_messages kolonu bulunamadı: ' . implode(', ', $candidates));
        }
        return null;
    };

    return [
        'table' => 'support_ticket_messages',
        'id' => $pick(['id']),
        'ticket_id' => $pick(['ticket_id']),
        'sender_user_id' => $pick(['sender_user_id', 'user_id']),
        'sender_type' => $pick(['sender_type']),
        'message' => $pick(['message']),
        'created_at' => $pick(['created_at']),
    ];
}

function support_allowed_statuses(): array
{
    return ['submitted', 'in_review', 'answered', 'completed'];
}

function support_open_statuses(): array
{
    return ['submitted', 'in_review', 'answered'];
}

function support_normalize_status(string $status): string
{
    $normalized = strtolower(trim($status));
    return in_array($normalized, support_allowed_statuses(), true) ? $normalized : 'submitted';
}

function support_user_open_ticket_count(PDO $pdo, string $userId): int
{
    $ticket = support_ticket_schema($pdo);
    $placeholders = implode(',', array_fill(0, count(support_open_statuses()), '?'));
    $sql = "SELECT COUNT(*) FROM `{$ticket['table']}` WHERE `{$ticket['user_id']}` = ? AND `{$ticket['status']}` IN ({$placeholders})";
    $params = array_merge([$userId], support_open_statuses());
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function support_get_ticket_for_user(PDO $pdo, string $ticketId, string $userId): ?array
{
    $ticket = support_ticket_schema($pdo);
    $sql = "SELECT * FROM `{$ticket['table']}` WHERE `{$ticket['id']}` = ? AND `{$ticket['user_id']}` = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticketId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function support_ticket_has_admin_reply(PDO $pdo, string $ticketId): bool
{
    $msg = support_ticket_message_schema($pdo);
    $sql = "SELECT COUNT(*) FROM `{$msg['table']}` WHERE `{$msg['ticket_id']}` = ? AND `{$msg['sender_type']}` = 'admin'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticketId]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function support_ticket_increment_followup(PDO $pdo, string $ticketId): void
{
    $ticket = support_ticket_schema($pdo);
    if (!$ticket['user_followup_count']) {
        return;
    }
    $sql = "UPDATE `{$ticket['table']}` SET `{$ticket['user_followup_count']}` = COALESCE(`{$ticket['user_followup_count']}`,0) + 1"
        . ($ticket['updated_at'] ? ", `{$ticket['updated_at']}` = NOW()" : '')
        . " WHERE `{$ticket['id']}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticketId]);
}

function support_fetch_ticket_messages(PDO $pdo, string $ticketId): array
{
    $msg = support_ticket_message_schema($pdo);
    $sql = "SELECT `{$msg['id']}` AS id, `{$msg['ticket_id']}` AS ticket_id, `{$msg['sender_user_id']}` AS sender_user_id, "
        . "`{$msg['sender_type']}` AS sender_type, `{$msg['message']}` AS message, `{$msg['created_at']}` AS created_at"
        . " FROM `{$msg['table']}` WHERE `{$msg['ticket_id']}` = ? ORDER BY `{$msg['created_at']}` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticketId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function support_calculate_reply_meta(array $ticket, array $messages): array
{
    $status = support_normalize_status((string)($ticket['status'] ?? 'submitted'));
    $userMessageCount = 0;
    $lastAdminReplyAt = null;
    $lastMessagePreview = null;
    $latestUserMessagePreview = null;

    foreach ($messages as $message) {
        $senderType = strtolower(trim((string)($message['sender_type'] ?? '')));
        $messageText = trim((string)($message['message'] ?? ''));
        if ($messageText !== '') {
            $lastMessagePreview = $messageText;
            if ($senderType === 'user') {
                $latestUserMessagePreview = $messageText;
            }
        }

        if ($senderType === 'user') {
            $userMessageCount++;
            continue;
        }

        if ($senderType === 'admin') {
            $createdAt = (string)($message['created_at'] ?? '');
            if ($createdAt !== '') {
                $lastAdminReplyAt = $createdAt;
            }
        }
    }

    $canReply = false;
    $followupRemaining = 0;
    $replyDisabledReason = null;

    if ($status === 'completed') {
        $replyDisabledReason = 'Bu destek talebi tamamlandı. Yeni mesaj eklenemez.';
    } else {
        if ($lastAdminReplyAt === null) {
            if ($userMessageCount <= 1) {
                $canReply = true;
                $followupRemaining = 1;
            } else {
                $replyDisabledReason = 'Bu talep için ek mesaj hakkınız kullanılmıştır.';
            }
        } else {
            $postAdminUserMessageCount = 0;
            foreach ($messages as $message) {
                $senderType = strtolower(trim((string)($message['sender_type'] ?? '')));
                if ($senderType !== 'user') {
                    continue;
                }
                $createdAt = (string)($message['created_at'] ?? '');
                if ($createdAt !== '' && $createdAt > $lastAdminReplyAt) {
                    $postAdminUserMessageCount++;
                }
            }

            if ($postAdminUserMessageCount < 1) {
                $canReply = true;
                $followupRemaining = 1;
            } else {
                $replyDisabledReason = 'Bu talep için yanıt hakkınız kullanılmıştır.';
            }
        }
    }

    return [
        'status' => $status,
        'can_reply' => $canReply,
        'reply_disabled_reason' => $replyDisabledReason,
        'followup_remaining' => $followupRemaining,
        'has_admin_reply' => $lastAdminReplyAt !== null,
        'last_admin_reply_at' => $lastAdminReplyAt,
        'user_message_count' => $userMessageCount,
        'message_preview' => $lastMessagePreview,
        'latest_user_message_preview' => $latestUserMessagePreview,
    ];
}

function support_preview_text(?string $text, int $limit = 120): ?string
{
    if ($text === null) {
        return null;
    }
    $plain = trim($text);
    if ($plain === '') {
        return '';
    }
    if (mb_strlen($plain, 'UTF-8') <= $limit) {
        return $plain;
    }
    return mb_substr($plain, 0, $limit, 'UTF-8');
}

function support_fetch_ticket_message_previews(PDO $pdo, array $ticketIds): array
{
    $ticketIds = array_values(array_filter(array_map(static function ($id): string {
        return trim((string)$id);
    }, $ticketIds), static function (string $id): bool {
        return $id !== '';
    }));

    if (!$ticketIds) {
        return [];
    }

    $msg = support_ticket_message_schema($pdo);
    $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
    $sql = "SELECT `{$msg['ticket_id']}` AS ticket_id, `{$msg['sender_type']}` AS sender_type, `{$msg['message']}` AS message, `{$msg['created_at']}` AS created_at"
        . " FROM `{$msg['table']}`"
        . " WHERE `{$msg['ticket_id']}` IN ({$placeholders})"
        . " ORDER BY `{$msg['created_at']}` ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ticketIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $ticketId = (string)($row['ticket_id'] ?? '');
        if ($ticketId === '') {
            continue;
        }
        $message = trim((string)($row['message'] ?? ''));
        if (!isset($result[$ticketId])) {
            $result[$ticketId] = [
                'first_user_message' => null,
                'latest_message' => null,
            ];
        }
        if ($message !== '') {
            $result[$ticketId]['latest_message'] = $message;
        }
        $senderType = strtolower(trim((string)($row['sender_type'] ?? '')));
        if ($senderType === 'user' && $result[$ticketId]['first_user_message'] === null && $message !== '') {
            $result[$ticketId]['first_user_message'] = $message;
        }
    }

    return $result;
}

function support_add_message(PDO $pdo, string $ticketId, string $senderUserId, string $senderType, string $message): string
{
    $msg = support_ticket_message_schema($pdo);
    $id = generate_uuid();
    $sql = "INSERT INTO `{$msg['table']}` (`{$msg['id']}`, `{$msg['ticket_id']}`, `{$msg['sender_user_id']}`, `{$msg['sender_type']}`, `{$msg['message']}`, `{$msg['created_at']}`)"
        . " VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $ticketId, $senderUserId, $senderType, $message]);
    return $id;
}

function support_touch_ticket_updated_at(PDO $pdo, string $ticketId): void
{
    $ticket = support_ticket_schema($pdo);
    if (!$ticket['updated_at']) {
        return;
    }
    $sql = "UPDATE `{$ticket['table']}` SET `{$ticket['updated_at']}` = NOW() WHERE `{$ticket['id']}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticketId]);
}

function support_update_ticket_status(PDO $pdo, string $ticketId, string $status, bool $setCompletedAt = false): void
{
    $ticket = support_ticket_schema($pdo);
    $normalized = support_normalize_status($status);

    $set = ["`{$ticket['status']}` = ?"];
    $params = [$normalized];
    if ($ticket['updated_at']) {
        $set[] = "`{$ticket['updated_at']}` = NOW()";
    }
    if ($ticket['completed_at']) {
        $set[] = "`{$ticket['completed_at']}` = " . ($setCompletedAt ? 'NOW()' : 'NULL');
    }

    $params[] = $ticketId;
    $sql = "UPDATE `{$ticket['table']}` SET " . implode(', ', $set) . " WHERE `{$ticket['id']}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

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

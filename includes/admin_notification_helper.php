<?php

function admin_notification_create(PDO $pdo, array $event): ?string
{
    try {
        $type = trim((string)($event['event_type'] ?? ''));
        $sourceId = trim((string)($event['source_id'] ?? ''));
        $title = trim((string)($event['title'] ?? ''));
        if ($type === '' || $sourceId === '' || $title === '') return null;

        $id = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
        $stmt = $pdo->prepare(
            "INSERT INTO admin_notifications
             (id,event_type,source_type,source_id,title,message,severity,target_url,status,meta_json,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?, 'open', ?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
               title=VALUES(title), message=VALUES(message), severity=VALUES(severity),
               target_url=VALUES(target_url), meta_json=VALUES(meta_json),
               status=IF(status='archived','open',status), updated_at=NOW()"
        );
        $stmt->execute([
            $id,
            mb_substr($type, 0, 80),
            mb_substr(trim((string)($event['source_type'] ?? $type)), 0, 80),
            mb_substr($sourceId, 0, 191),
            mb_substr($title, 0, 191),
            mb_substr(trim((string)($event['message'] ?? '')), 0, 1000),
            in_array(($event['severity'] ?? 'normal'), ['critical','high','normal','low'], true) ? $event['severity'] : 'normal',
            mb_substr(trim((string)($event['target_url'] ?? '/pages/admin-notifications.php')), 0, 500),
            json_encode($event['meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $id;
    } catch (Throwable $e) {
        error_log('[admin_notification_create] ' . $e->getMessage());
        return null;
    }
}

function admin_notification_unread_count(PDO $pdo, string $adminId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM admin_notifications n
         LEFT JOIN admin_notification_reads r ON r.notification_id=n.id AND r.admin_user_id=?
         WHERE n.status='open' AND r.read_at IS NULL"
    );
    $stmt->execute([$adminId]);
    return (int)$stmt->fetchColumn();
}

function admin_notification_mark_read(PDO $pdo, string $notificationId, string $adminId): void
{
    $pdo->prepare(
        'INSERT INTO admin_notification_reads (notification_id,admin_user_id,read_at)
         VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE read_at=NOW()'
    )->execute([$notificationId, $adminId]);
}


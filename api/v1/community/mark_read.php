<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $profile = community_get_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı profili bulunamadı.', 404);
    }

    if ((int)($profile['is_guest'] ?? 0) === 1) {
        api_error('Misafir kullanıcılar okundu bilgisi güncelleyemez.', 403);
    }

    $payload = api_get_request_data();
    $roomId = trim((string)($payload['room_id'] ?? ''));
    $lastReadMessageId = trim((string)($payload['last_read_message_id'] ?? ''));

    if ($roomId === '') {
        api_error('room_id zorunludur.', 422);
    }
    if ($lastReadMessageId === '') {
        api_error('last_read_message_id zorunludur.', 422);
    }

    $room = community_room_schema($pdo);
    $msg = community_message_schema($pdo);
    $read = community_read_schema($pdo);

    $roomStmt = $pdo->prepare("SELECT `{$room['id']}` FROM `{$room['table']}` WHERE `{$room['id']}` = ? AND `{$room['is_active']}` = 1 LIMIT 1");
    $roomStmt->execute([$roomId]);
    if (!$roomStmt->fetchColumn()) {
        api_error('Oda bulunamadı veya aktif değil.', 404);
    }

    $msgStmt = $pdo->prepare("SELECT `{$msg['id']}` FROM `{$msg['table']}` WHERE `{$msg['id']}` = ? AND `{$msg['room_id']}` = ? LIMIT 1");
    $msgStmt->execute([$lastReadMessageId, $roomId]);
    if (!$msgStmt->fetchColumn()) {
        api_error('Mesaj kaydı oda ile eşleşmiyor.', 422);
    }

    $checkStmt = $pdo->prepare("SELECT `{$read['id']}` AS id FROM `{$read['table']}` WHERE `{$read['room_id']}` = ? AND `{$read['user_id']}` = ? LIMIT 1");
    $checkStmt->execute([$roomId, $userId]);
    $existingId = $checkStmt->fetchColumn();

    $now = community_now();
    if ($existingId) {
        $set = ["`{$read['last_read_message_id']}` = ?", "`{$read['last_read_at']}` = ?"];
        $vals = [$lastReadMessageId, $now];
        if ($read['updated_at']) {
            $set[] = "`{$read['updated_at']}` = ?";
            $vals[] = $now;
        }
        $vals[] = $existingId;

        $sql = "UPDATE `{$read['table']}` SET " . implode(', ', $set) . " WHERE `{$read['id']}` = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
    } else {
        $cols = [$read['id'], $read['room_id'], $read['user_id'], $read['last_read_message_id'], $read['last_read_at']];
        $vals = [generate_uuid(), $roomId, $userId, $lastReadMessageId, $now];
        if ($read['created_at']) {
            $cols[] = $read['created_at'];
            $vals[] = $now;
        }
        if ($read['updated_at']) {
            $cols[] = $read['updated_at'];
            $vals[] = $now;
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO `{$read['table']}` ({$quoted}) VALUES ({$holders})");
        $stmt->execute($vals);
    }

    api_success('Oda okundu bilgisi güncellendi.');
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

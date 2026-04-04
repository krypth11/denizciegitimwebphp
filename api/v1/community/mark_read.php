<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $userQualificationId = api_get_current_user_qualification_id($pdo, $auth);
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

    if (!$read['last_read_message_id']) {
        api_error('Okundu şeması uygun değil.', 500);
    }

    $roomStmt = $pdo->prepare("SELECT `{$room['id']}` AS id, `{$room['type']}` AS type"
        . ($room['qualification_id'] ? ", `{$room['qualification_id']}` AS qualification_id" : ', NULL AS qualification_id')
        . " FROM `{$room['table']}` WHERE `{$room['id']}` = ? AND `{$room['is_active']}` = 1 LIMIT 1");
    $roomStmt->execute([$roomId]);
    $roomRow = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$roomRow) {
        api_error('Oda bulunamadı veya aktif değil.', 404);
    }

    if (!community_user_can_access_room([
        'type' => (string)($roomRow['type'] ?? ''),
        'qualification_id' => (string)(($roomRow['qualification_id'] ?? null) ?: ''),
    ], $userQualificationId)) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'community.mark_read.room',
            'requested_qualification_id' => ($roomRow['qualification_id'] ?? null),
            'current_qualification_id' => $userQualificationId,
            'room_id' => $roomId,
        ]);
        api_error('Bu oda için erişim yetkiniz yok.', 403);
    }

    $msgStmt = $pdo->prepare("SELECT `{$msg['id']}` FROM `{$msg['table']}` WHERE `{$msg['id']}` = ? AND `{$msg['room_id']}` = ? LIMIT 1");
    $msgStmt->execute([$lastReadMessageId, $roomId]);
    if (!$msgStmt->fetchColumn()) {
        api_error('Mesaj kaydı oda ile eşleşmiyor.', 422);
    }

    $now = community_now();

    $set = ["`{$read['last_read_message_id']}` = ?", "`{$read['last_read_at']}` = ?"];
    $updateVals = [$lastReadMessageId, $now];
    if ($read['updated_at']) {
        $set[] = "`{$read['updated_at']}` = ?";
        $updateVals[] = $now;
    }
    $updateVals[] = $roomId;
    $updateVals[] = $userId;

    $updateSql = "UPDATE `{$read['table']}` SET " . implode(', ', $set)
        . " WHERE `{$read['room_id']}` = ? AND `{$read['user_id']}` = ?";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($updateVals);

    if ($updateStmt->rowCount() < 1) {
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$read['table']}` WHERE `{$read['room_id']}` = ? AND `{$read['user_id']}` = ?");
        $existsStmt->execute([$roomId, $userId]);
        $alreadyExists = (int)$existsStmt->fetchColumn() > 0;
        if ($alreadyExists) {
            api_success('Oda okundu bilgisi güncellendi.');
        }

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
        try {
            $insertStmt = $pdo->prepare("INSERT INTO `{$read['table']}` ({$quoted}) VALUES ({$holders})");
            $insertStmt->execute($vals);
        } catch (Throwable $insertError) {
            // Olası race condition'da tekrar update dene
            $updateStmt->execute($updateVals);
        }
    }

    api_success('Oda okundu bilgisi güncellendi.');
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

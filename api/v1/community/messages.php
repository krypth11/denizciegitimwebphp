<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $userQualificationId = api_get_current_user_qualification_id($pdo, $auth);

    $roomId = trim((string)($_GET['room_id'] ?? ''));
    if ($roomId === '') {
        api_error('room_id parametresi zorunludur.', 422);
    }

    $room = community_room_schema($pdo);
    $msg = community_message_schema($pdo);
    $profile = community_profile_schema($pdo);

    $roomSql = "SELECT `{$room['id']}` AS id, `{$room['is_active']}` AS is_active, `{$room['type']}` AS type"
        . ($room['qualification_id'] ? ", `{$room['qualification_id']}` AS qualification_id" : ', NULL AS qualification_id')
        . " FROM `{$room['table']}` WHERE `{$room['id']}` = ? LIMIT 1";
    $stmtRoom = $pdo->prepare($roomSql);
    $stmtRoom->execute([$roomId]);
    $roomRow = $stmtRoom->fetch(PDO::FETCH_ASSOC);

    if (!$roomRow) {
        api_error('Oda bulunamadı.', 404);
    }
    if ((int)($roomRow['is_active'] ?? 0) !== 1) {
        api_error('Oda aktif değil.', 422);
    }

    if (!community_user_can_access_room([
        'type' => (string)($roomRow['type'] ?? ''),
        'qualification_id' => (string)(($roomRow['qualification_id'] ?? null) ?: ''),
    ], $userQualificationId)) {
        api_qualification_access_log('qualification access rejected', [
            'context' => 'community.messages.room',
            'requested_qualification_id' => ($roomRow['qualification_id'] ?? null),
            'current_qualification_id' => $userQualificationId,
            'room_id' => $roomId,
        ]);
        api_error('Bu oda için erişim yetkiniz yok.', 403);
    }

    $fullNameExpr = $profile['full_name'] ? "u.`{$profile['full_name']}`" : "''";

    $sql = "SELECT m.`{$msg['id']}` AS id, m.`{$msg['room_id']}` AS room_id, m.`{$msg['user_id']}` AS user_id, m.`{$msg['message_text']}` AS message_text, "
        . "m.`{$msg['is_deleted']}` AS is_deleted, "
        . ($msg['deleted_at'] ? "m.`{$msg['deleted_at']}` AS deleted_at, " : 'NULL AS deleted_at, ')
        . "m.`{$msg['created_at']}` AS created_at, {$fullNameExpr} AS user_full_name, u.`{$profile['email']}` AS user_email "
        . "FROM `{$msg['table']}` m "
        . "LEFT JOIN `{$profile['table']}` u ON u.`{$profile['id']}` = m.`{$msg['user_id']}` "
        . "WHERE m.`{$msg['room_id']}` = ? ORDER BY m.`{$msg['created_at']}` DESC LIMIT 50";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$roomId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = array_reverse($rows);

    $messages = array_map(static function (array $r): array {
        $deleted = (int)($r['is_deleted'] ?? 0) === 1;
        $fullName = trim((string)($r['user_full_name'] ?? ''));
        $email = trim((string)($r['user_email'] ?? ''));
        $emailPrefix = '';
        if ($email !== '') {
            $atPos = strpos($email, '@');
            $emailPrefix = $atPos === false ? $email : substr($email, 0, $atPos);
            $emailPrefix = trim((string)$emailPrefix);
        }

        $displayName = $fullName !== '' ? $fullName : ($emailPrefix !== '' ? $emailPrefix : 'Kullanıcı');

        return [
            'id' => (string)$r['id'],
            'room_id' => (string)$r['room_id'],
            'user_id' => (string)$r['user_id'],
            'user_full_name' => $displayName,
            'user_name' => $displayName,
            'user_display_name' => $displayName,
            'message_text' => $deleted ? '' : (string)($r['message_text'] ?? ''),
            'is_deleted' => $deleted ? 1 : 0,
            'deleted_at' => $r['deleted_at'] ?? null,
            'created_at' => $r['created_at'] ?? null,
        ];
    }, $rows);

    $profileRow = community_get_profile_by_user_id($pdo, $userId);
    $isGuest = $profileRow ? ((int)($profileRow['is_guest'] ?? 0) === 1) : false;

    api_success('Oda mesajları getirildi.', [
        'room_id' => $roomId,
        'messages' => $messages,
        'can_send' => $isGuest ? 0 : 1,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

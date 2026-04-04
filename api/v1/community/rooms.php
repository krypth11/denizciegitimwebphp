<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $userQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'community.rooms');
    api_qualification_access_log('user current qualification', [
        'context' => 'community.rooms',
        'user_id' => $userId,
        'current_qualification_id' => $userQualificationId,
    ]);

    $profile = community_get_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı profili bulunamadı.', 404);
    }

    $isGuest = (int)($profile['is_guest'] ?? 0) === 1;

    community_ensure_general_room($pdo);
    $quals = $pdo->query('SELECT id, name FROM qualifications')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($quals as $q) {
        community_sync_qualification_room($pdo, (string)$q['id'], (string)$q['name'], true);
    }

    $room = community_room_schema($pdo);
    $msg = community_message_schema($pdo);

    $sql = "SELECT r.`{$room['id']}` AS id, r.`{$room['name']}` AS name, "
        . ($room['description'] ? "r.`{$room['description']}` AS description, " : "'' AS description, ")
        . "r.`{$room['type']}` AS type, "
        . ($room['qualification_id'] ? "r.`{$room['qualification_id']}` AS qualification_id, " : "NULL AS qualification_id, ")
        . ($room['sort_order'] ? "r.`{$room['sort_order']}` AS sort_order, " : "0 AS sort_order, ")
        . "r.`{$room['is_active']}` AS is_active "
        . "FROM `{$room['table']}` r WHERE r.`{$room['is_active']}` = 1";

    $rooms = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $enriched = [];
    foreach ($rooms as $r) {
        $roomType = (string)($r['type'] ?? '');
        $roomQualificationId = (string)(($r['qualification_id'] ?? null) ?: '');
        if (!community_user_can_access_room([
            'type' => $roomType,
            'qualification_id' => $roomQualificationId,
        ], $userQualificationId)) {
            continue;
        }

        $roomId = (string)$r['id'];
        $lastSql = "SELECT `{$msg['message_text']}` AS message_text, `{$msg['created_at']}` AS created_at FROM `{$msg['table']}` WHERE `{$msg['room_id']}` = ?";
        $params = [$roomId];
        if ($msg['is_deleted']) {
            $lastSql .= " AND `{$msg['is_deleted']}` = 0";
        }
        $lastSql .= " ORDER BY `{$msg['created_at']}` DESC LIMIT 1";

        $stmtLast = $pdo->prepare($lastSql);
        $stmtLast->execute($params);
        $last = $stmtLast->fetch(PDO::FETCH_ASSOC) ?: null;

        $preview = '';
        $lastAt = null;
        if ($last) {
            $previewText = (string)($last['message_text'] ?? '');
            $preview = mb_strlen($previewText, 'UTF-8') > 80 ? mb_substr($previewText, 0, 80, 'UTF-8') . '…' : $previewText;
            $lastAt = $last['created_at'] ?? null;
        }

        $unread = $isGuest ? 0 : community_get_unread_count($pdo, $roomId, $userId);

        $enriched[] = [
            'id' => $roomId,
            'name' => (string)($r['name'] ?? ''),
            'description' => (string)($r['description'] ?? ''),
            'type' => (string)($r['type'] ?? ''),
            'qualification_id' => ($r['qualification_id'] ?? null) ?: null,
            'is_active' => (int)($r['is_active'] ?? 0) === 1 ? 1 : 0,
            'sort_order' => (int)($r['sort_order'] ?? 0),
            'last_message_preview' => $preview,
            'last_message_at' => $lastAt,
            'unread_count' => (int)$unread,
        ];
    }

    $ordered = community_sort_rooms_for_user($enriched, $userQualificationId !== '' ? $userQualificationId : null);

    $returnedQualificationRooms = [];
    foreach ($ordered as $roomItem) {
        if ((string)($roomItem['type'] ?? '') === 'qualification') {
            $returnedQualificationRooms[] = (string)(($roomItem['qualification_id'] ?? null) ?: '');
        }
    }

    api_qualification_access_log('community qualification room returned', [
        'context' => 'community.rooms',
        'current_qualification_id' => $userQualificationId,
        'community qualification room returned' => $returnedQualificationRooms,
    ]);

    api_qualification_access_log('community rooms returned count', [
        'context' => 'community.rooms',
        'count' => count($ordered),
        'current_qualification_id' => $userQualificationId,
    ]);

    api_success('Topluluk odaları getirildi.', [
        'rooms' => $ordered,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

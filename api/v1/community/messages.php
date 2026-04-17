<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';
require_once dirname(__DIR__, 3) . '/includes/community_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $userQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'community.messages');

    $roomId = trim((string)($_GET['room_id'] ?? ''));
    if ($roomId === '') {
        api_error('room_id parametresi zorunludur.', 422);
    }

    $room = community_room_schema($pdo);
    $msg = community_message_schema($pdo);
    $profile = community_profile_schema($pdo);
    $subscription = community_subscription_schema($pdo);

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

    if ((string)($roomRow['type'] ?? '') === 'qualification') {
        api_qualification_access_log('community qualification room returned', [
            'context' => 'community.messages',
            'current_qualification_id' => $userQualificationId,
            'community qualification room returned' => (string)(($roomRow['qualification_id'] ?? null) ?: ''),
        ]);
    }

    $fullNameExpr = $profile['full_name'] ? "u.`{$profile['full_name']}`" : "''";
    $isGuestExpr = $profile['is_guest'] ? "u.`{$profile['is_guest']}`" : 'NULL';
    $avatarTypeExpr = $profile['avatar_type'] ? "u.`{$profile['avatar_type']}`" : 'NULL';
    $avatarIdExpr = $profile['avatar_id'] ? "u.`{$profile['avatar_id']}`" : 'NULL';
    $profilePhotoUrlExpr = $profile['profile_photo_url'] ? "u.`{$profile['profile_photo_url']}`" : 'NULL';

    $subscriptionJoinSql = '';
    $subscriptionSelectSql = '0 AS subscription_is_pro, NULL AS subscription_expires_at';
    if (!empty($subscription['exists']) && $subscription['user_id'] && $subscription['is_pro']) {
        $orderCols = [];
        $isProCol = "COALESCE(s.`{$subscription['is_pro']}`, 0)";

        if ($subscription['expires_at']) {
            $expiresAtCol = "s.`{$subscription['expires_at']}`";
            $activeExpr = "CASE WHEN {$isProCol} = 1 AND ({$expiresAtCol} IS NULL OR {$expiresAtCol} = '' OR {$expiresAtCol} = '0000-00-00' OR {$expiresAtCol} = '0000-00-00 00:00:00' OR {$expiresAtCol} > NOW()) THEN 1 ELSE 0 END";
            $orderCols[] = $activeExpr . ' DESC';
            $orderCols[] = "CASE WHEN {$isProCol} = 1 THEN {$expiresAtCol} ELSE NULL END DESC";
        } else {
            $orderCols[] = "CASE WHEN {$isProCol} = 1 THEN 1 ELSE 0 END DESC";
        }

        if ($subscription['updated_at']) {
            $orderCols[] = "s.`{$subscription['updated_at']}` DESC";
        }
        if ($subscription['created_at']) {
            $orderCols[] = "s.`{$subscription['created_at']}` DESC";
        }
        if ($subscription['id']) {
            $orderCols[] = "s.`{$subscription['id']}` DESC";
        }
        if (!$orderCols) {
            $orderCols[] = '1 DESC';
        }

        if ($subscription['id']) {
            $subscriptionJoinSql = " LEFT JOIN `{$subscription['table']}` us ON us.`{$subscription['id']}` = ("
                . "SELECT s.`{$subscription['id']}` FROM `{$subscription['table']}` s"
                . " WHERE s.`{$subscription['user_id']}` = m.`{$msg['user_id']}`"
                . ' ORDER BY ' . implode(', ', $orderCols)
                . ' LIMIT 1)';

            $subscriptionSelectSql = "COALESCE(us.`{$subscription['is_pro']}`, 0) AS subscription_is_pro, "
                . ($subscription['expires_at'] ? "us.`{$subscription['expires_at']}` AS subscription_expires_at" : 'NULL AS subscription_expires_at');
        } else {
            $activeExprS = '';
            $activeExprS2 = '';
            if ($subscription['expires_at']) {
                $activeExprS = "CASE WHEN COALESCE(s.`{$subscription['is_pro']}`, 0) = 1"
                    . " AND (s.`{$subscription['expires_at']}` IS NULL"
                    . " OR s.`{$subscription['expires_at']}` = ''"
                    . " OR s.`{$subscription['expires_at']}` = '0000-00-00'"
                    . " OR s.`{$subscription['expires_at']}` = '0000-00-00 00:00:00'"
                    . " OR s.`{$subscription['expires_at']}` > NOW())"
                    . ' THEN 1 ELSE 0 END';
                $activeExprS2 = "CASE WHEN COALESCE(s2.`{$subscription['is_pro']}`, 0) = 1"
                    . " AND (s2.`{$subscription['expires_at']}` IS NULL"
                    . " OR s2.`{$subscription['expires_at']}` = ''"
                    . " OR s2.`{$subscription['expires_at']}` = '0000-00-00'"
                    . " OR s2.`{$subscription['expires_at']}` = '0000-00-00 00:00:00'"
                    . " OR s2.`{$subscription['expires_at']}` > NOW())"
                    . ' THEN 1 ELSE 0 END';
            } else {
                $activeExprS = "CASE WHEN COALESCE(s.`{$subscription['is_pro']}`, 0) = 1 THEN 1 ELSE 0 END";
                $activeExprS2 = "CASE WHEN COALESCE(s2.`{$subscription['is_pro']}`, 0) = 1 THEN 1 ELSE 0 END";
            }

            $comparisonPairs = [
                [$activeExprS2, $activeExprS],
            ];

            if ($subscription['expires_at']) {
                $comparisonPairs[] = [
                    "CASE WHEN COALESCE(s2.`{$subscription['is_pro']}`, 0) = 1 THEN COALESCE(s2.`{$subscription['expires_at']}`, '') ELSE '' END",
                    "CASE WHEN COALESCE(s.`{$subscription['is_pro']}`, 0) = 1 THEN COALESCE(s.`{$subscription['expires_at']}`, '') ELSE '' END",
                ];
            }
            if ($subscription['updated_at']) {
                $comparisonPairs[] = [
                    "COALESCE(s2.`{$subscription['updated_at']}`, '')",
                    "COALESCE(s.`{$subscription['updated_at']}`, '')",
                ];
            }
            if ($subscription['created_at']) {
                $comparisonPairs[] = [
                    "COALESCE(s2.`{$subscription['created_at']}`, '')",
                    "COALESCE(s.`{$subscription['created_at']}`, '')",
                ];
            }

            $betterConditions = [];
            $equalPrefix = [];
            foreach ($comparisonPairs as $pair) {
                [$leftExpr, $rightExpr] = $pair;
                $condition = [];
                if (!empty($equalPrefix)) {
                    $condition[] = '(' . implode(' AND ', $equalPrefix) . ')';
                }
                $condition[] = '(' . $leftExpr . ' > ' . $rightExpr . ')';
                $betterConditions[] = '(' . implode(' AND ', $condition) . ')';
                $equalPrefix[] = '(' . $leftExpr . ' = ' . $rightExpr . ')';
            }

            if (!$betterConditions) {
                $betterConditions[] = '0 = 1';
            }

            $pickedSelect = "SELECT s.`{$subscription['user_id']}` AS user_id, COALESCE(s.`{$subscription['is_pro']}`, 0) AS subscription_is_pro, "
                . ($subscription['expires_at'] ? "s.`{$subscription['expires_at']}` AS subscription_expires_at" : 'NULL AS subscription_expires_at')
                . " FROM `{$subscription['table']}` s"
                . ' WHERE NOT EXISTS ('
                . "SELECT 1 FROM `{$subscription['table']}` s2"
                . " WHERE s2.`{$subscription['user_id']}` = s.`{$subscription['user_id']}`"
                . ' AND (' . implode(' OR ', $betterConditions) . ')'
                . ')';

            $subscriptionJoinSql = ' LEFT JOIN ('
                . 'SELECT picked.user_id, MAX(picked.subscription_is_pro) AS subscription_is_pro, '
                . 'MAX(picked.subscription_expires_at) AS subscription_expires_at '
                . 'FROM (' . $pickedSelect . ') picked '
                . 'GROUP BY picked.user_id'
                . ') us ON us.user_id = m.`' . $msg['user_id'] . '`';

            $subscriptionSelectSql = 'COALESCE(us.subscription_is_pro, 0) AS subscription_is_pro, '
                . 'us.subscription_expires_at AS subscription_expires_at';
        }
    }

    $sql = "SELECT m.`{$msg['id']}` AS id, m.`{$msg['room_id']}` AS room_id, m.`{$msg['user_id']}` AS user_id, m.`{$msg['message_text']}` AS message_text, "
        . "m.`{$msg['is_deleted']}` AS is_deleted, "
        . ($msg['deleted_at'] ? "m.`{$msg['deleted_at']}` AS deleted_at, " : 'NULL AS deleted_at, ')
        . "m.`{$msg['created_at']}` AS created_at, {$fullNameExpr} AS user_full_name, u.`{$profile['email']}` AS user_email, "
        . "{$isGuestExpr} AS user_is_guest_raw, {$avatarTypeExpr} AS user_avatar_type_raw, {$avatarIdExpr} AS user_avatar_id_raw, "
        . "{$profilePhotoUrlExpr} AS user_profile_photo_url_raw, {$subscriptionSelectSql} "
        . "FROM `{$msg['table']}` m "
        . "LEFT JOIN `{$profile['table']}` u ON u.`{$profile['id']}` = m.`{$msg['user_id']}` "
        . $subscriptionJoinSql
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
        $resolvedIsGuest = null;
        if (($r['user_is_guest_raw'] ?? null) !== null) {
            $resolvedIsGuest = ((int)($r['user_is_guest_raw'] ?? 0) === 1);
        }
        if ($resolvedIsGuest === null) {
            $emailLower = strtolower($email);
            $fullNameLower = strtolower($fullName);
            $resolvedIsGuest = ($emailLower !== '' && str_ends_with($emailLower, '@guest.local'))
                || in_array($fullNameLower, ['misafir kullanıcı', 'misafir kullanici', 'guest user'], true);
        }

        $avatarType = api_profile_resolve_avatar_type($r['user_avatar_type_raw'] ?? null);
        $avatarId = api_profile_normalize_avatar_id($r['user_avatar_id_raw'] ?? null);
        $profilePhotoUrl = api_profile_normalize_photo_url($r['user_profile_photo_url_raw'] ?? null);
        $isPremiumActive = usage_limits_is_subscription_active([
            'is_pro' => (int)($r['subscription_is_pro'] ?? 0),
            'expires_at' => $r['subscription_expires_at'] ?? null,
        ]);
        $isPremium = $isPremiumActive ? 1 : 0;

        if (usage_limits_subscription_debug_enabled()) {
            usage_limits_subscription_debug_log('community_message_premium_resolve', [
                'message_user_id' => (string)($r['user_id'] ?? ''),
                'subscription_is_pro' => (int)($r['subscription_is_pro'] ?? 0),
                'subscription_expires_at' => $r['subscription_expires_at'] ?? null,
                'final_is_premium' => $isPremium,
            ]);
        }

        return [
            'id' => (string)$r['id'],
            'room_id' => (string)$r['room_id'],
            'user_id' => (string)$r['user_id'],
            'full_name' => $displayName,
            'is_guest' => $resolvedIsGuest ? 1 : 0,
            'is_premium' => $isPremium,
            'is_pro' => $isPremium,
            'avatar_type' => $avatarType,
            'avatar_id' => $avatarId,
            'profile_photo_url' => $profilePhotoUrl,
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

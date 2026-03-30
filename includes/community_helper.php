<?php

if (!function_exists('community_columns')) {
    function community_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $safeTable = str_replace('`', '', $table);
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $safeTable . '`');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $map = [];
            foreach ($rows as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $map[$field] = $row;
                }
            }
            $cache[$table] = $map;
            return $map;
        } catch (Throwable $e) {
            $cache[$table] = [];
            return [];
        }
    }
}

if (!function_exists('community_pick_col')) {
    function community_pick_col(array $columnsMap, array $candidates, bool $required = true): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($columnsMap[$candidate])) {
                return $candidate;
            }
        }

        if ($required) {
            throw new RuntimeException('Gerekli community kolonu bulunamadı: ' . implode(', ', $candidates));
        }

        return null;
    }
}

if (!function_exists('community_now')) {
    function community_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('community_profile_schema')) {
    function community_profile_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'user_profiles');
        if (!$cols) {
            throw new RuntimeException('user_profiles tablosu okunamadı.');
        }

        return [
            'table' => 'user_profiles',
            'id' => community_pick_col($cols, ['id']),
            'full_name' => community_pick_col($cols, ['full_name', 'name', 'display_name'], false),
            'email' => community_pick_col($cols, ['email']),
            'is_guest' => community_pick_col($cols, ['is_guest', 'guest'], false),
            'current_qualification_id' => community_pick_col($cols, ['current_qualification_id', 'qualification_id'], false),
        ];
    }
}

if (!function_exists('community_room_schema')) {
    function community_room_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'community_rooms');
        if (!$cols) {
            throw new RuntimeException('community_rooms tablosu okunamadı.');
        }

        return [
            'table' => 'community_rooms',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id']),
            'name' => community_pick_col($cols, ['name', 'room_name']),
            'description' => community_pick_col($cols, ['description', 'room_description'], false),
            'type' => community_pick_col($cols, ['type', 'room_type']),
            'qualification_id' => community_pick_col($cols, ['qualification_id'], false),
            'sort_order' => community_pick_col($cols, ['sort_order', 'order_index', 'sort_index'], false),
            'is_active' => community_pick_col($cols, ['is_active', 'active']),
            'created_at' => community_pick_col($cols, ['created_at'], false),
            'updated_at' => community_pick_col($cols, ['updated_at'], false),
        ];
    }
}

if (!function_exists('community_message_schema')) {
    function community_message_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'community_messages');
        if (!$cols) {
            throw new RuntimeException('community_messages tablosu okunamadı.');
        }

        return [
            'table' => 'community_messages',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id']),
            'room_id' => community_pick_col($cols, ['room_id']),
            'user_id' => community_pick_col($cols, ['user_id']),
            'message_text' => community_pick_col($cols, ['message_text', 'content', 'message']),
            'normalized_text' => community_pick_col($cols, ['normalized_text'], false),
            'is_deleted' => community_pick_col($cols, ['is_deleted']),
            'deleted_at' => community_pick_col($cols, ['deleted_at'], false),
            'deleted_by_admin_id' => community_pick_col($cols, ['deleted_by_admin_id'], false),
            'created_at' => community_pick_col($cols, ['created_at']),
        ];
    }
}

if (!function_exists('community_report_schema')) {
    function community_report_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'community_message_reports');
        if (!$cols) {
            throw new RuntimeException('community_message_reports tablosu okunamadı.');
        }

        return [
            'table' => 'community_message_reports',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id']),
            'message_id' => community_pick_col($cols, ['message_id']),
            'reported_by_user_id' => community_pick_col($cols, ['reported_by_user_id', 'reporter_user_id', 'user_id']),
            // Geriye dönük uyumluluk için alias
            'reporter_user_id' => community_pick_col($cols, ['reported_by_user_id', 'reporter_user_id', 'user_id']),
            'message_owner_user_id' => community_pick_col($cols, ['message_owner_user_id', 'target_user_id'], false),
            'reason' => community_pick_col($cols, ['reason', 'report_reason']),
            'status' => community_pick_col($cols, ['status'], false),
            'created_at' => community_pick_col($cols, ['created_at']),
        ];
    }
}

if (!function_exists('community_read_schema')) {
    function community_read_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'community_room_reads');
        if (!$cols) {
            throw new RuntimeException('community_room_reads tablosu okunamadı.');
        }

        return [
            'table' => 'community_room_reads',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id']),
            'room_id' => community_pick_col($cols, ['room_id']),
            'user_id' => community_pick_col($cols, ['user_id']),
            'last_read_message_id' => community_pick_col($cols, ['last_read_message_id'], false),
            'last_read_at' => community_pick_col($cols, ['last_read_at']),
            'updated_at' => community_pick_col($cols, ['updated_at'], false),
            'created_at' => community_pick_col($cols, ['created_at'], false),
        ];
    }
}

if (!function_exists('community_mute_schema')) {
    function community_mute_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'community_user_mutes');
        if (!$cols) {
            throw new RuntimeException('community_user_mutes tablosu okunamadı.');
        }

        return [
            'table' => 'community_user_mutes',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id']),
            'user_id' => community_pick_col($cols, ['user_id']),
            'muted_until' => community_pick_col($cols, ['muted_until']),
            'reason' => community_pick_col($cols, ['reason'], false),
            'is_active' => community_pick_col($cols, ['is_active', 'active'], false),
            'muted_by_admin_id' => community_pick_col($cols, ['muted_by_admin_id'], false),
            'created_at' => community_pick_col($cols, ['created_at'], false),
        ];
    }
}

if (!function_exists('community_blacklist_schema')) {
    function community_blacklist_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'community_blacklist_terms');
        if (!$cols) {
            throw new RuntimeException('community_blacklist_terms tablosu okunamadı.');
        }

        return [
            'table' => 'community_blacklist_terms',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id']),
            'term' => community_pick_col($cols, ['term', 'keyword', 'word']),
            'match_type' => community_pick_col($cols, ['match_type'], false),
            'is_active' => community_pick_col($cols, ['is_active', 'active'], false),
            'created_at' => community_pick_col($cols, ['created_at'], false),
            'updated_at' => community_pick_col($cols, ['updated_at'], false),
        ];
    }
}

if (!function_exists('community_settings_schema')) {
    function community_settings_schema(PDO $pdo): array
    {
        $cols = community_columns($pdo, 'admin_settings');
        if (!$cols) {
            throw new RuntimeException('admin_settings tablosu okunamadı.');
        }

        return [
            'table' => 'admin_settings',
            'cols' => $cols,
            'id' => community_pick_col($cols, ['id', 'uuid'], false),
            'user_id' => community_pick_col($cols, ['user_id', 'admin_user_id'], false),
            'community_rules_text' => community_pick_col($cols, ['community_rules_text'], false),
            'updated_at' => community_pick_col($cols, ['updated_at', 'updated_on'], false),
            'created_at' => community_pick_col($cols, ['created_at', 'created_on'], false),
        ];
    }
}

if (!function_exists('community_detect_guest')) {
    function community_detect_guest(array $profile): bool
    {
        if (array_key_exists('is_guest', $profile) && $profile['is_guest'] !== null) {
            return (int)$profile['is_guest'] === 1;
        }

        $email = strtolower(trim((string)($profile['email'] ?? '')));
        $fullName = strtolower(trim((string)($profile['full_name'] ?? '')));

        if ($email !== '' && str_ends_with($email, '@guest.local')) {
            return true;
        }

        return in_array($fullName, ['misafir kullanıcı', 'misafir kullanici', 'guest user'], true);
    }
}

if (!function_exists('community_get_profile_by_user_id')) {
    function community_get_profile_by_user_id(PDO $pdo, string $userId): ?array
    {
        $schema = community_profile_schema($pdo);
        $select = [
            "`{$schema['id']}` AS id",
            "`{$schema['email']}` AS email",
            $schema['full_name'] ? "`{$schema['full_name']}` AS full_name" : "'' AS full_name",
            $schema['is_guest'] ? "`{$schema['is_guest']}` AS is_guest" : 'NULL AS is_guest',
            $schema['current_qualification_id'] ? "`{$schema['current_qualification_id']}` AS current_qualification_id" : 'NULL AS current_qualification_id',
        ];

        $sql = 'SELECT ' . implode(', ', $select)
            . " FROM `{$schema['table']}` WHERE `{$schema['id']}` = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['is_guest'] = community_detect_guest($row) ? 1 : 0;
        return $row;
    }
}

if (!function_exists('community_message_has_link')) {
    function community_message_has_link(string $message): bool
    {
        $text = mb_strtolower($message, 'UTF-8');

        if (preg_match('/(https?:\/\/|www\.)/iu', $text)) {
            return true;
        }

        return (bool)preg_match('/\b[a-z0-9\-]+(\.[a-z0-9\-]+)+\b/iu', $text);
    }
}

if (!function_exists('community_normalize_message')) {
    function community_normalize_message(string $message): string
    {
        $v = trim(mb_strtolower($message, 'UTF-8'));
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        return $v;
    }
}

if (!function_exists('community_blacklist_match')) {
    function community_blacklist_match(PDO $pdo, string $message): ?array
    {
        $schema = community_blacklist_schema($pdo);
        $isActiveCol = $schema['is_active'];

        $sql = 'SELECT '
            . "`{$schema['term']}` AS term, "
            . ($schema['match_type'] ? "`{$schema['match_type']}` AS match_type" : "'contains' AS match_type")
            . " FROM `{$schema['table']}`";
        $params = [];
        if ($isActiveCol) {
            $sql .= " WHERE `{$isActiveCol}` = ?";
            $params[] = 1;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $normalizedMsg = mb_strtolower($message, 'UTF-8');
        foreach ($rows as $row) {
            $term = trim((string)($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }

            $matchType = strtolower(trim((string)($row['match_type'] ?? 'contains')));
            $needle = mb_strtolower($term, 'UTF-8');

            if ($matchType === 'exact') {
                if ($needle === trim($normalizedMsg)) {
                    return ['term' => $term, 'match_type' => 'exact'];
                }
                continue;
            }

            if (mb_strpos($normalizedMsg, $needle) !== false) {
                return ['term' => $term, 'match_type' => 'contains'];
            }
        }

        return null;
    }
}

if (!function_exists('community_is_user_muted')) {
    function community_is_user_muted(PDO $pdo, string $userId): ?array
    {
        $schema = community_mute_schema($pdo);
        $sql = 'SELECT '
            . ($schema['reason'] ? "`{$schema['reason']}` AS reason, " : "'' AS reason, ")
            . "`{$schema['muted_until']}` AS muted_until"
            . " FROM `{$schema['table']}` WHERE `{$schema['user_id']}` = ?";
        $params = [$userId];

        if ($schema['is_active']) {
            $sql .= " AND `{$schema['is_active']}` = ?";
            $params[] = 1;
        }

        $sql .= " AND `{$schema['muted_until']}` > NOW() ORDER BY `{$schema['muted_until']}` DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('community_check_flood')) {
    function community_check_flood(PDO $pdo, string $userId, int $seconds = 10): bool
    {
        $msg = community_message_schema($pdo);
        $sql = "SELECT COUNT(*) FROM `{$msg['table']}` WHERE `{$msg['user_id']}` = ? AND `{$msg['created_at']}` > DATE_SUB(NOW(), INTERVAL ? SECOND)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $seconds]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('community_check_repeat_message')) {
    function community_check_repeat_message(PDO $pdo, string $userId, string $roomId, string $normalizedMessage, int $seconds = 120): bool
    {
        $msg = community_message_schema($pdo);

        if ($msg['normalized_text']) {
            $sql = "SELECT COUNT(*) FROM `{$msg['table']}` WHERE `{$msg['user_id']}` = ? AND `{$msg['room_id']}` = ? AND `{$msg['normalized_text']}` = ? AND `{$msg['created_at']}` > DATE_SUB(NOW(), INTERVAL ? SECOND)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $roomId, $normalizedMessage, $seconds]);
            return (int)$stmt->fetchColumn() > 0;
        }

        $sql = "SELECT `{$msg['message_text']}` AS message_text FROM `{$msg['table']}` WHERE `{$msg['user_id']}` = ? AND `{$msg['room_id']}` = ? AND `{$msg['created_at']}` > DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY `{$msg['created_at']}` DESC LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $roomId, $seconds]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            if (community_normalize_message((string)($row['message_text'] ?? '')) === $normalizedMessage) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('community_get_unread_count')) {
    function community_get_unread_count(PDO $pdo, string $roomId, string $userId): int
    {
        $msg = community_message_schema($pdo);
        $read = community_read_schema($pdo);

        $sqlRead = "SELECT "
            . ($read['last_read_message_id'] ? "`{$read['last_read_message_id']}` AS last_read_message_id, " : "NULL AS last_read_message_id, ")
            . "`{$read['last_read_at']}` AS last_read_at "
            . "FROM `{$read['table']}` WHERE `{$read['room_id']}` = ? AND `{$read['user_id']}` = ? LIMIT 1";
        $stmtRead = $pdo->prepare($sqlRead);
        $stmtRead->execute([$roomId, $userId]);
        $readRow = $stmtRead->fetch(PDO::FETCH_ASSOC) ?: null;

        $lastReadMessageId = trim((string)($readRow['last_read_message_id'] ?? ''));
        $lastReadAt = $readRow['last_read_at'] ?? null;

        $anchorReadAt = null;
        if ($lastReadMessageId !== '') {
            $anchorSql = "SELECT `{$msg['created_at']}` AS created_at FROM `{$msg['table']}` WHERE `{$msg['id']}` = ? AND `{$msg['room_id']}` = ? LIMIT 1";
            $anchorStmt = $pdo->prepare($anchorSql);
            $anchorStmt->execute([$lastReadMessageId, $roomId]);
            $anchorReadAt = $anchorStmt->fetchColumn() ?: null;
        }

        $sql = "SELECT COUNT(*) FROM `{$msg['table']}` WHERE `{$msg['room_id']}` = ?";
        $params = [$roomId];
        if ($msg['is_deleted']) {
            $sql .= " AND `{$msg['is_deleted']}` = 0";
        }

        if ($anchorReadAt) {
            $sql .= " AND `{$msg['created_at']}` > ?";
            $params[] = $anchorReadAt;
        } elseif ($lastReadAt) {
            $sql .= " AND `{$msg['created_at']}` > ?";
            $params[] = $lastReadAt;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('community_room_priority_bucket')) {
    function community_room_priority_bucket(array $room, ?string $userQualificationId): int
    {
        $type = (string)($room['type'] ?? '');
        $qualificationId = (string)($room['qualification_id'] ?? '');

        if ($type === 'general') {
            return 1;
        }

        if ($type === 'qualification' && $userQualificationId && $qualificationId === $userQualificationId) {
            return 2;
        }

        if ($type === 'custom') {
            return 3;
        }

        if ($type === 'qualification') {
            return 4;
        }

        return 5;
    }
}

if (!function_exists('community_sort_rooms_for_user')) {
    function community_sort_rooms_for_user(array $rooms, ?string $userQualificationId): array
    {
        usort($rooms, static function (array $a, array $b) use ($userQualificationId): int {
            $aBucket = community_room_priority_bucket($a, $userQualificationId);
            $bBucket = community_room_priority_bucket($b, $userQualificationId);
            if ($aBucket !== $bBucket) {
                return $aBucket <=> $bBucket;
            }

            $aOrder = (int)($a['sort_order'] ?? 0);
            $bOrder = (int)($b['sort_order'] ?? 0);
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $rooms;
    }
}

if (!function_exists('community_sync_qualification_room')) {
    function community_sync_qualification_room(PDO $pdo, string $qualificationId, string $qualificationName, bool $isActive = true): void
    {
        $room = community_room_schema($pdo);
        $qualificationId = trim($qualificationId);
        if ($qualificationId === '') {
            return;
        }

        $roomName = trim($qualificationName);
        if ($roomName === '') {
            $roomName = 'Yeterlilik Odası';
        }

        $selectSql = "SELECT `{$room['id']}` AS id FROM `{$room['table']}` WHERE `{$room['type']}` = ?";
        $params = ['qualification'];
        if ($room['qualification_id']) {
            $selectSql .= " AND `{$room['qualification_id']}` = ?";
            $params[] = $qualificationId;
        }
        $selectSql .= ' LIMIT 1';

        $stmt = $pdo->prepare($selectSql);
        $stmt->execute($params);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $set = ["`{$room['name']}` = ?", "`{$room['is_active']}` = ?"];
            $vals = [$roomName, $isActive ? 1 : 0];
            if ($room['updated_at']) {
                $set[] = "`{$room['updated_at']}` = ?";
                $vals[] = community_now();
            }
            $vals[] = $existingId;

            $updateSql = "UPDATE `{$room['table']}` SET " . implode(', ', $set) . " WHERE `{$room['id']}` = ?";
            $up = $pdo->prepare($updateSql);
            $up->execute($vals);
            return;
        }

        $cols = [$room['id'], $room['name'], $room['type'], $room['is_active']];
        $vals = [generate_uuid(), $roomName, 'qualification', $isActive ? 1 : 0];

        if ($room['qualification_id']) {
            $cols[] = $room['qualification_id'];
            $vals[] = $qualificationId;
        }
        if ($room['description']) {
            $cols[] = $room['description'];
            $vals[] = '';
        }
        if ($room['sort_order']) {
            $cols[] = $room['sort_order'];
            $vals[] = 100;
        }
        if ($room['created_at']) {
            $cols[] = $room['created_at'];
            $vals[] = community_now();
        }
        if ($room['updated_at']) {
            $cols[] = $room['updated_at'];
            $vals[] = community_now();
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $ins = $pdo->prepare("INSERT INTO `{$room['table']}` ({$quoted}) VALUES ({$holders})");
        $ins->execute($vals);
    }
}

if (!function_exists('community_ensure_general_room')) {
    function community_ensure_general_room(PDO $pdo): void
    {
        $room = community_room_schema($pdo);
        $stmt = $pdo->prepare("SELECT `{$room['id']}` FROM `{$room['table']}` WHERE `{$room['type']}` = ? LIMIT 1");
        $stmt->execute(['general']);
        if ($stmt->fetchColumn()) {
            return;
        }

        $cols = [$room['id'], $room['name'], $room['type'], $room['is_active']];
        $vals = [generate_uuid(), 'Genel', 'general', 1];

        if ($room['description']) {
            $cols[] = $room['description'];
            $vals[] = 'Genel topluluk odası';
        }
        if ($room['sort_order']) {
            $cols[] = $room['sort_order'];
            $vals[] = 0;
        }
        if ($room['created_at']) {
            $cols[] = $room['created_at'];
            $vals[] = community_now();
        }
        if ($room['updated_at']) {
            $cols[] = $room['updated_at'];
            $vals[] = community_now();
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $ins = $pdo->prepare("INSERT INTO `{$room['table']}` ({$quoted}) VALUES ({$holders})");
        $ins->execute($vals);
    }
}

if (!function_exists('community_get_rules_text')) {
    function community_get_rules_text(PDO $pdo, ?string $userId = null): string
    {
        $schema = community_settings_schema($pdo);
        if (!$schema['community_rules_text']) {
            return '';
        }

        $sql = "SELECT `{$schema['community_rules_text']}` AS rules FROM `{$schema['table']}`";
        $params = [];
        if ($schema['user_id'] && $userId) {
            $sql .= " WHERE `{$schema['user_id']}` = ?";
            $params[] = $userId;
        }
        $sql .= ' ORDER BY ';
        if ($schema['updated_at']) {
            $sql .= "`{$schema['updated_at']}` DESC";
        } elseif ($schema['created_at']) {
            $sql .= "`{$schema['created_at']}` DESC";
        } else {
            $sql .= '1';
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('community_save_rules_text')) {
    function community_save_rules_text(PDO $pdo, string $text, ?string $userId = null): void
    {
        $schema = community_settings_schema($pdo);
        if (!$schema['community_rules_text']) {
            throw new RuntimeException('community_rules_text kolonu bulunamadı.');
        }

        $targetSql = "SELECT " . ($schema['id'] ? "`{$schema['id']}`" : '1') . " AS row_id FROM `{$schema['table']}`";
        $params = [];
        if ($schema['user_id'] && $userId) {
            $targetSql .= " WHERE `{$schema['user_id']}` = ?";
            $params[] = $userId;
        }
        $targetSql .= ' LIMIT 1';

        $stmt = $pdo->prepare($targetSql);
        $stmt->execute($params);
        $rowId = $stmt->fetchColumn();

        if ($rowId) {
            $set = ["`{$schema['community_rules_text']}` = ?"];
            $values = [$text];
            if ($schema['updated_at']) {
                $set[] = "`{$schema['updated_at']}` = ?";
                $values[] = community_now();
            }

            $updateSql = "UPDATE `{$schema['table']}` SET " . implode(', ', $set);
            if ($schema['id']) {
                $updateSql .= " WHERE `{$schema['id']}` = ?";
                $values[] = $rowId;
            } elseif ($schema['user_id'] && $userId) {
                $updateSql .= " WHERE `{$schema['user_id']}` = ?";
                $values[] = $userId;
            }

            $up = $pdo->prepare($updateSql);
            $up->execute($values);
            return;
        }

        $cols = [$schema['community_rules_text']];
        $vals = [$text];

        if ($schema['id']) {
            $extra = strtolower((string)($schema['cols'][$schema['id']]['Extra'] ?? ''));
            if (!str_contains($extra, 'auto_increment')) {
                $cols[] = $schema['id'];
                $vals[] = generate_uuid();
            }
        }

        if ($schema['user_id'] && $userId) {
            $cols[] = $schema['user_id'];
            $vals[] = $userId;
        }
        if ($schema['created_at']) {
            $cols[] = $schema['created_at'];
            $vals[] = community_now();
        }
        if ($schema['updated_at']) {
            $cols[] = $schema['updated_at'];
            $vals[] = community_now();
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $ins = $pdo->prepare("INSERT INTO `{$schema['table']}` ({$quoted}) VALUES ({$holders})");
        $ins->execute($vals);
    }
}

if (!function_exists('community_report_reasons')) {
    function community_report_reasons(): array
    {
        return ['küfür_hakaret', 'spam', 'alakasiz_icerik', 'yanlis_bilgi', 'diger'];
    }
}

<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

function user_lifecycle_pick_column(array $columns, array $candidates, bool $required = false): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    if ($required) {
        throw new RuntimeException('Gerekli lifecycle kolonu bulunamadı: ' . implode(', ', $candidates));
    }

    return null;
}

function user_lifecycle_schema(PDO $pdo): ?array
{
    $columns = get_table_columns($pdo, 'user_lifecycle_events');
    if (empty($columns)) {
        return null;
    }

    return [
        'table' => 'user_lifecycle_events',
        'id' => user_lifecycle_pick_column($columns, ['id'], false),
        'user_id' => user_lifecycle_pick_column($columns, ['user_id'], true),
        'event_type' => user_lifecycle_pick_column($columns, ['event_type', 'event_key', 'type'], false),
        'title' => user_lifecycle_pick_column($columns, ['title', 'event_title', 'name'], false),
        'old_value' => user_lifecycle_pick_column($columns, ['old_value'], false),
        'new_value' => user_lifecycle_pick_column($columns, ['new_value'], false),
        'source' => user_lifecycle_pick_column($columns, ['source'], false),
        'meta_json' => user_lifecycle_pick_column($columns, ['meta_json', 'meta', 'payload_json'], false),
        'created_at' => user_lifecycle_pick_column($columns, ['created_at', 'created_on'], false),
        'updated_at' => user_lifecycle_pick_column($columns, ['updated_at', 'updated_on'], false),
    ];
}

function user_lifecycle_log_event(
    PDO $pdo,
    string $userId,
    string $eventType,
    string $title,
    string $source,
    $oldValue = null,
    $newValue = null,
    ?array $meta = null,
    int $dedupeWindowSeconds = 300
): bool {
    $userId = trim($userId);
    if ($userId === '') {
        return false;
    }

    $schema = user_lifecycle_schema($pdo);
    if (!$schema) {
        return false;
    }

    $eventType = trim($eventType) !== '' ? trim($eventType) : 'event';
    $title = trim($title) !== '' ? trim($title) : $eventType;
    $source = trim($source) !== '' ? trim($source) : 'system';

    try {
        if ($dedupeWindowSeconds > 0 && $schema['event_type']) {
            $where = [
                '`' . $schema['user_id'] . '` = ?',
                '`' . $schema['event_type'] . '` = ?',
            ];
            $params = [$userId, $eventType];

            if ($schema['source']) {
                $where[] = '`' . $schema['source'] . '` = ?';
                $params[] = $source;
            }

            $orderCol = $schema['created_at'] ?: ($schema['id'] ?: $schema['user_id']);
            $select = [];
            if ($schema['created_at']) $select[] = '`' . $schema['created_at'] . '` AS created_at';
            if ($schema['old_value']) $select[] = '`' . $schema['old_value'] . '` AS old_value';
            if ($schema['new_value']) $select[] = '`' . $schema['new_value'] . '` AS new_value';
            if ($schema['meta_json']) $select[] = '`' . $schema['meta_json'] . '` AS meta_json';
            if (!$select) {
                $select[] = '1 AS marker';
            }

            $sqlRecent = 'SELECT ' . implode(', ', $select)
                . ' FROM `' . $schema['table'] . '`'
                . ' WHERE ' . implode(' AND ', $where)
                . ' ORDER BY `' . $orderCol . '` DESC LIMIT 1';
            $stmtRecent = $pdo->prepare($sqlRecent);
            $stmtRecent->execute($params);
            $recent = $stmtRecent->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($recent && !empty($recent['created_at'])) {
                $recentTs = strtotime((string)$recent['created_at']);
                if ($recentTs !== false && (time() - $recentTs) <= $dedupeWindowSeconds) {
                    $sameOld = !isset($recent['old_value']) || (string)$recent['old_value'] === (string)$oldValue;
                    $sameNew = !isset($recent['new_value']) || (string)$recent['new_value'] === (string)$newValue;
                    $sameMeta = true;
                    if (isset($recent['meta_json']) && $meta !== null) {
                        $newMetaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $sameMeta = (string)$recent['meta_json'] === (string)$newMetaJson;
                    }

                    if ($sameOld && $sameNew && $sameMeta) {
                        return false;
                    }
                }
            }
        }

        $columns = ['`' . $schema['user_id'] . '`'];
        $holders = ['?'];
        $values = [$userId];

        if ($schema['id']) {
            $columns[] = '`' . $schema['id'] . '`';
            $holders[] = '?';
            $values[] = generate_uuid();
        }
        if ($schema['event_type']) {
            $columns[] = '`' . $schema['event_type'] . '`';
            $holders[] = '?';
            $values[] = $eventType;
        }
        if ($schema['title']) {
            $columns[] = '`' . $schema['title'] . '`';
            $holders[] = '?';
            $values[] = $title;
        }
        if ($schema['old_value']) {
            $columns[] = '`' . $schema['old_value'] . '`';
            $holders[] = '?';
            $values[] = $oldValue;
        }
        if ($schema['new_value']) {
            $columns[] = '`' . $schema['new_value'] . '`';
            $holders[] = '?';
            $values[] = $newValue;
        }
        if ($schema['source']) {
            $columns[] = '`' . $schema['source'] . '`';
            $holders[] = '?';
            $values[] = $source;
        }
        if ($schema['meta_json']) {
            $columns[] = '`' . $schema['meta_json'] . '`';
            $holders[] = '?';
            $values[] = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        if ($schema['created_at']) {
            $columns[] = '`' . $schema['created_at'] . '`';
            $holders[] = 'NOW()';
        }
        if ($schema['updated_at']) {
            $columns[] = '`' . $schema['updated_at'] . '`';
            $holders[] = 'NOW()';
        }

        $sql = 'INSERT INTO `' . $schema['table'] . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return true;
    } catch (Throwable $e) {
        error_log('[user_lifecycle_log_event] ' . $e->getMessage());
        return false;
    }
}

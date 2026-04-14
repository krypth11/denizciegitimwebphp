<?php

if (!function_exists('notification_q')) {
    function notification_q(string $identifier): string
    {
        return '`' . str_replace('`', '', $identifier) . '`';
    }
}

if (!function_exists('notification_deep_link_pages')) {
    function notification_deep_link_pages(): array
    {
        return [
            ['value' => 'dashboard', 'label' => 'Anasayfa'],
            ['value' => 'study', 'label' => 'Çalışma Alanı'],
            ['value' => 'exam', 'label' => 'Deneme Sınavı'],
            ['value' => 'word_game', 'label' => 'Kelime Oyunu'],
            ['value' => 'offline', 'label' => 'Offline İçerikler'],
            ['value' => 'maritime', 'label' => 'Maritime English'],
            ['value' => 'daily_quiz', 'label' => 'Daily Quiz'],
            ['value' => 'profile', 'label' => 'Profil'],
        ];
    }
}

if (!function_exists('notification_normalize_deep_link')) {
    function notification_normalize_deep_link(?string $deepLink): ?string
    {
        $value = trim((string)$deepLink);
        if ($value === '') {
            return null;
        }

        $allowed = [];
        foreach (notification_deep_link_pages() as $key => $item) {
            if (is_array($item)) {
                $candidate = trim((string)($item['value'] ?? ''));
                if ($candidate !== '') {
                    $allowed[] = $candidate;
                }
                continue;
            }

            if (is_string($key) && $key !== '') {
                $allowed[] = $key;
            }
        }

        $allowed = array_values(array_unique($allowed));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Deep Link değeri geçersiz.');
        }

        return $value;
    }
}

if (!function_exists('notification_pick_column')) {
    function notification_pick_column(array $columns, array $candidates, bool $required = true): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        if ($required) {
            throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
        }

        return null;
    }
}

if (!function_exists('notification_table_columns')) {
    function notification_table_columns(PDO $pdo, string $table): array
    {
        static $cache = [];
        $key = strtolower($table);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cols = get_table_columns($pdo, $table);
        if (empty($cols)) {
            throw new RuntimeException($table . ' tablosu okunamadı.');
        }

        $cache[$key] = $cols;
        return $cols;
    }
}

if (!function_exists('notification_schema')) {
    function notification_schema(PDO $pdo): array
    {
        static $schema = null;
        if ($schema !== null) {
            return $schema;
        }

        $nCols = notification_table_columns($pdo, 'notifications');
        $lCols = notification_table_columns($pdo, 'notification_logs');
        $rCols = notification_table_columns($pdo, 'notification_rules');
        $tCols = notification_table_columns($pdo, 'user_push_tokens');
        $uCols = notification_table_columns($pdo, 'user_profiles');

        $runCols = [];
        try {
            $runCols = get_table_columns($pdo, 'notification_rule_runs');
        } catch (Throwable $e) {
            $runCols = [];
        }

        $apiTokenCols = [];
        try {
            $apiTokenCols = get_table_columns($pdo, 'api_tokens');
        } catch (Throwable $e) {
            $apiTokenCols = [];
        }

        $mockAttemptCols = [];
        try {
            $mockAttemptCols = get_table_columns($pdo, 'mock_exam_attempts');
        } catch (Throwable $e) {
            $mockAttemptCols = [];
        }

        $subCols = [];
        try {
            $subCols = get_table_columns($pdo, 'user_subscription_status');
        } catch (Throwable $e) {
            $subCols = [];
        }

        $qualCols = [];
        try {
            $qualCols = get_table_columns($pdo, 'qualifications');
        } catch (Throwable $e) {
            $qualCols = [];
        }

        $schema = [
            'notifications' => [
                'table' => 'notifications',
                'cols' => $nCols,
                'id' => notification_pick_column($nCols, ['id']),
                'title' => notification_pick_column($nCols, ['title', 'notification_title']),
                'message' => notification_pick_column($nCols, ['message', 'body', 'content']),
                'image_url' => notification_pick_column($nCols, ['image_url', 'image', 'banner_url'], false),
                'deep_link' => notification_pick_column($nCols, ['deep_link', 'deeplink', 'link'], false),
                'payload_json' => notification_pick_column($nCols, ['payload_json', 'payload', 'data_json'], false),
                'channel' => notification_pick_column($nCols, ['channel', 'topic', 'notification_channel'], false),
                'target_type' => notification_pick_column($nCols, ['target_type', 'audience_type'], false),
                'target_value' => notification_pick_column($nCols, ['target_value', 'target_filter_json', 'audience_value', 'target_payload'], false),
                'status' => notification_pick_column($nCols, ['status'], false),
                'schedule_type' => notification_pick_column($nCols, ['schedule_type'], false),
                'scheduled_at' => notification_pick_column($nCols, ['scheduled_at', 'planned_at'], false),
                'sent_at' => notification_pick_column($nCols, ['sent_at'], false),
                'total_target' => notification_pick_column($nCols, ['total_target', 'target_total', 'total_targets'], false),
                'success_count' => notification_pick_column($nCols, ['success_count', 'sent_success', 'delivered_count'], false),
                'failure_count' => notification_pick_column($nCols, ['failure_count', 'sent_fail', 'failed_count'], false),
                'created_by' => notification_pick_column($nCols, ['created_by', 'admin_id', 'user_id'], false),
                'created_at' => notification_pick_column($nCols, ['created_at', 'created_on'], false),
                'updated_at' => notification_pick_column($nCols, ['updated_at', 'updated_on'], false),
            ],
            'logs' => [
                'table' => 'notification_logs',
                'cols' => $lCols,
                'id' => notification_pick_column($lCols, ['id'], false),
                'notification_id' => notification_pick_column($lCols, ['notification_id', 'notif_id']),
                'user_id' => notification_pick_column($lCols, ['user_id'], false),
                'token_id' => notification_pick_column($lCols, ['token_id', 'user_push_token_id'], false),
                'token_masked' => notification_pick_column($lCols, ['token_masked', 'masked_token'], false),
                'platform' => notification_pick_column($lCols, ['platform'], false),
                'status' => notification_pick_column($lCols, ['status', 'send_status'], false),
                'is_success' => notification_pick_column($lCols, ['is_success', 'success'], false),
                'response_code' => notification_pick_column($lCols, ['response_code', 'http_code', 'firebase_code'], false),
                'response_message' => notification_pick_column($lCols, ['response_message', 'response_text', 'error_message'], false),
                'response_body' => notification_pick_column($lCols, ['response_body', 'provider_response', 'meta_json'], false),
                'created_at' => notification_pick_column($lCols, ['created_at', 'logged_at', 'sent_at'], false),
            ],
            'rules' => [
                'table' => 'notification_rules',
                'cols' => $rCols,
                'id' => notification_pick_column($rCols, ['id']),
                'name' => notification_pick_column($rCols, ['name', 'title', 'rule_name'], false),
                'slug' => notification_pick_column($rCols, ['slug', 'code', 'rule_key'], false),
                'description' => notification_pick_column($rCols, ['description', 'summary'], false),
                'config_json' => notification_pick_column($rCols, ['config_json', 'config', 'payload_json'], false),
                'is_active' => notification_pick_column($rCols, ['is_active', 'active', 'enabled'], false),
                'updated_at' => notification_pick_column($rCols, ['updated_at', 'updated_on'], false),
                'created_at' => notification_pick_column($rCols, ['created_at', 'created_on'], false),
            ],
            'tokens' => [
                'table' => 'user_push_tokens',
                'cols' => $tCols,
                'id' => notification_pick_column($tCols, ['id']),
                'user_id' => notification_pick_column($tCols, ['user_id']),
                'platform' => notification_pick_column($tCols, ['platform'], false),
                'fcm_token' => notification_pick_column($tCols, ['fcm_token', 'token']),
                'app_version' => notification_pick_column($tCols, ['app_version'], false),
                'is_active' => notification_pick_column($tCols, ['is_active', 'active'], false),
                'last_seen_at' => notification_pick_column($tCols, ['last_seen_at', 'updated_at'], false),
                'updated_at' => notification_pick_column($tCols, ['updated_at'], false),
                'created_at' => notification_pick_column($tCols, ['created_at'], false),
            ],
            'users' => [
                'table' => 'user_profiles',
                'cols' => $uCols,
                'id' => notification_pick_column($uCols, ['id']),
                'email' => notification_pick_column($uCols, ['email'], false),
                'full_name' => notification_pick_column($uCols, ['full_name', 'name', 'display_name'], false),
                'is_deleted' => notification_pick_column($uCols, ['is_deleted'], false),
                'current_qualification_id' => notification_pick_column($uCols, ['current_qualification_id', 'qualification_id'], false),
                'last_sign_in_at' => notification_pick_column($uCols, ['last_sign_in_at', 'last_login_at'], false),
                'created_at' => notification_pick_column($uCols, ['created_at', 'created_on'], false),
            ],
            'subscription' => [
                'table' => 'user_subscription_status',
                'cols' => $subCols,
                'user_id' => in_array('user_id', $subCols, true) ? 'user_id' : null,
                'is_pro' => in_array('is_pro', $subCols, true) ? 'is_pro' : null,
                'expires_at' => in_array('expires_at', $subCols, true) ? 'expires_at' : null,
                'updated_at' => in_array('updated_at', $subCols, true) ? 'updated_at' : null,
                'created_at' => in_array('created_at', $subCols, true) ? 'created_at' : null,
            ],
            'rule_runs' => [
                'table' => 'notification_rule_runs',
                'cols' => $runCols,
                'id' => in_array('id', $runCols, true) ? 'id' : null,
                'rule_key' => in_array('rule_key', $runCols, true) ? 'rule_key' : null,
                'user_id' => in_array('user_id', $runCols, true) ? 'user_id' : null,
                'run_date' => in_array('run_date', $runCols, true) ? 'run_date' : null,
                'notification_id' => in_array('notification_id', $runCols, true) ? 'notification_id' : null,
                'status' => in_array('status', $runCols, true) ? 'status' : null,
            ],
            'api_tokens' => [
                'table' => 'api_tokens',
                'cols' => $apiTokenCols,
                'user_id' => in_array('user_id', $apiTokenCols, true) ? 'user_id' : null,
                'last_used_at' => in_array('last_used_at', $apiTokenCols, true) ? 'last_used_at' : null,
                'expires_at' => in_array('expires_at', $apiTokenCols, true) ? 'expires_at' : null,
                'revoked_at' => in_array('revoked_at', $apiTokenCols, true) ? 'revoked_at' : null,
            ],
            'mock_exam_attempts' => [
                'table' => 'mock_exam_attempts',
                'cols' => $mockAttemptCols,
                'user_id' => in_array('user_id', $mockAttemptCols, true) ? 'user_id' : null,
                'status' => in_array('status', $mockAttemptCols, true) ? 'status' : null,
                'submitted_at' => in_array('submitted_at', $mockAttemptCols, true) ? 'submitted_at' : null,
                'created_at' => in_array('created_at', $mockAttemptCols, true) ? 'created_at' : null,
            ],
            'qualifications' => [
                'table' => 'qualifications',
                'cols' => $qualCols,
                'id' => in_array('id', $qualCols, true) ? 'id' : null,
                'name' => in_array('name', $qualCols, true) ? 'name' : null,
                'is_active' => in_array('is_active', $qualCols, true) ? 'is_active' : null,
            ],
        ];

        return $schema;
    }
}

if (!function_exists('mask_token')) {
    function mask_token(string $token): string
    {
        $token = trim($token);
        $length = mb_strlen($token, 'UTF-8');
        if ($length <= 12) {
            return mb_substr($token, 0, 4, 'UTF-8') . '...' . mb_substr($token, -4, null, 'UTF-8');
        }
        return mb_substr($token, 0, 8, 'UTF-8') . '...' . mb_substr($token, -8, null, 'UTF-8');
    }
}

if (!function_exists('notification_insert_row')) {
    function notification_insert_row(PDO $pdo, string $table, array $payload): void
    {
        $cols = array_keys($payload);
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $quoted = implode(', ', array_map('notification_q', $cols));

        $sql = 'INSERT INTO ' . notification_q($table) . ' (' . $quoted . ') VALUES (' . $holders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($payload));
    }
}

if (!function_exists('notification_update_row')) {
    function notification_update_row(PDO $pdo, string $table, array $payload, string $whereSql, array $whereParams): void
    {
        if (empty($payload)) {
            return;
        }

        $set = [];
        $vals = [];
        foreach ($payload as $col => $value) {
            $set[] = notification_q($col) . ' = ?';
            $vals[] = $value;
        }
        $vals = array_merge($vals, $whereParams);

        $sql = 'UPDATE ' . notification_q($table) . ' SET ' . implode(', ', $set) . ' WHERE ' . $whereSql;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
    }
}

if (!function_exists('notification_create_or_update')) {
    function notification_create_or_update(PDO $pdo, array $payload, ?string $notificationId = null): string
    {
        $schema = notification_schema($pdo);
        $n = $schema['notifications'];
        $now = date('Y-m-d H:i:s');

        $id = trim((string)$notificationId);
        if ($id === '') {
            $id = generate_uuid();
        }

        $row = [
            $n['id'] => $id,
            $n['title'] => trim((string)($payload['title'] ?? '')),
            $n['message'] => trim((string)($payload['message'] ?? '')),
        ];

        $optionalMap = [
            'image_url' => $n['image_url'],
            'deep_link' => $n['deep_link'],
            'payload_json' => $n['payload_json'],
            'channel' => $n['channel'],
            'target_type' => $n['target_type'],
            'target_value' => $n['target_value'],
            'status' => $n['status'],
            'schedule_type' => $n['schedule_type'],
            'scheduled_at' => $n['scheduled_at'],
            'sent_at' => $n['sent_at'],
            'total_target' => $n['total_target'],
            'success_count' => $n['success_count'],
            'failure_count' => $n['failure_count'],
            'created_by' => $n['created_by'],
        ];

        foreach ($optionalMap as $key => $column) {
            if ($column && array_key_exists($key, $payload)) {
                $row[$column] = $payload[$key];
            }
        }

        if ($n['updated_at']) {
            $row[$n['updated_at']] = $now;
        }

        $stmt = $pdo->prepare('SELECT ' . notification_q($n['id']) . ' FROM ' . notification_q($n['table']) . ' WHERE ' . notification_q($n['id']) . ' = ? LIMIT 1');
        $stmt->execute([$id]);
        $exists = (bool)$stmt->fetchColumn();

        if ($exists) {
            unset($row[$n['id']]);
            notification_update_row($pdo, $n['table'], $row, notification_q($n['id']) . ' = ?', [$id]);
        } else {
            if ($n['created_at']) {
                $row[$n['created_at']] = $now;
            }
            notification_insert_row($pdo, $n['table'], $row);
        }

        return $id;
    }
}

if (!function_exists('notification_fetch_one')) {
    function notification_fetch_one(PDO $pdo, string $notificationId): ?array
    {
        $schema = notification_schema($pdo);
        $n = $schema['notifications'];

        $sql = 'SELECT * FROM ' . notification_q($n['table']) . ' WHERE ' . notification_q($n['id']) . ' = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('resolve_notification_targets')) {
    function resolve_notification_targets(PDO $pdo, string $notificationId): array
    {
        $schema = notification_schema($pdo);
        $n = $schema['notifications'];
        $u = $schema['users'];
        $s = $schema['subscription'];
        $t = $schema['tokens'];

        $notification = notification_fetch_one($pdo, $notificationId);
        if (!$notification) {
            throw new RuntimeException('Bildirim kaydı bulunamadı.');
        }

        $targetType = $n['target_type'] ? (string)($notification[$n['target_type']] ?? 'all_users') : 'all_users';
        $targetValueRaw = $n['target_value'] ? (string)($notification[$n['target_value']] ?? '') : '';
        $targetValue = [];
        if ($targetValueRaw !== '') {
            $decoded = json_decode($targetValueRaw, true);
            if (is_array($decoded)) {
                $targetValue = $decoded;
            }
        }

        $baseWhere = ['1=1'];
        $params = [];
        if ($u['is_deleted']) {
            $baseWhere[] = notification_q($u['is_deleted']) . ' = 0';
        }

        $sql = 'SELECT ' . notification_q($u['id']) . ' AS user_id FROM ' . notification_q($u['table']) . ' WHERE ' . implode(' AND ', $baseWhere);

        if ($targetType === 'single_user') {
            $userId = trim((string)($targetValue['user_id'] ?? ''));
            if ($userId === '') {
                return [];
            }
            $sql .= ' AND ' . notification_q($u['id']) . ' = ?';
            $params[] = $userId;
        } elseif ($targetType === 'qualification') {
            $qualificationId = trim((string)($targetValue['qualification_id'] ?? ''));
            if ($qualificationId === '' || !$u['current_qualification_id']) {
                return [];
            }
            $sql .= ' AND ' . notification_q($u['current_qualification_id']) . ' = ?';
            $params[] = $qualificationId;
        } elseif ($targetType === 'premium_users' || $targetType === 'free_users') {
            if (!$s['user_id'] || !$s['is_pro']) {
                return [];
            }
            $subOrder = $s['updated_at'] ?: ($s['created_at'] ?: $s['user_id']);
            $sql = 'SELECT u.' . notification_q($u['id']) . ' AS user_id
                    FROM ' . notification_q($u['table']) . ' u
                    LEFT JOIN (
                      SELECT s1.' . notification_q($s['user_id']) . ' AS user_id, s1.' . notification_q($s['is_pro']) . ' AS is_pro
                      FROM ' . notification_q($s['table']) . ' s1
                      INNER JOIN (
                        SELECT ' . notification_q($s['user_id']) . ' AS user_id, MAX(' . notification_q($subOrder) . ') AS max_order
                        FROM ' . notification_q($s['table']) . '
                        GROUP BY ' . notification_q($s['user_id']) . '
                      ) sm ON sm.user_id = s1.' . notification_q($s['user_id']) . ' AND sm.max_order = s1.' . notification_q($subOrder) . '
                    ) us ON us.user_id = u.' . notification_q($u['id']) . '
                    WHERE 1=1';
            if ($u['is_deleted']) {
                $sql .= ' AND u.' . notification_q($u['is_deleted']) . ' = 0';
            }
            if ($targetType === 'premium_users') {
                $sql .= ' AND COALESCE(us.is_pro, 0) = 1';
            } else {
                $sql .= ' AND COALESCE(us.is_pro, 0) = 0';
            }
        } elseif ($targetType === 'last_7_days_active' || $targetType === 'last_30_days_passive') {
            $refColumn = $u['last_sign_in_at'] ?: $t['last_seen_at'];
            if (!$refColumn) {
                return [];
            }

            if ($u['last_sign_in_at']) {
                if ($targetType === 'last_7_days_active') {
                    $sql .= ' AND ' . notification_q($u['last_sign_in_at']) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                } else {
                    $sql .= ' AND (' . notification_q($u['last_sign_in_at']) . ' IS NULL OR ' . notification_q($u['last_sign_in_at']) . ' < DATE_SUB(NOW(), INTERVAL 30 DAY))';
                }
            } else {
                $sql = 'SELECT DISTINCT t.' . notification_q($t['user_id']) . ' AS user_id
                        FROM ' . notification_q($t['table']) . ' t
                        WHERE 1=1';
                if ($t['is_active']) {
                    $sql .= ' AND t.' . notification_q($t['is_active']) . ' = 1';
                }
                if ($targetType === 'last_7_days_active') {
                    $sql .= ' AND t.' . notification_q($t['last_seen_at']) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                } else {
                    $sql .= ' AND (t.' . notification_q($t['last_seen_at']) . ' IS NULL OR t.' . notification_q($t['last_seen_at']) . ' < DATE_SUB(NOW(), INTERVAL 30 DAY))';
                }
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_values(array_filter(array_map(static fn($r) => (string)($r['user_id'] ?? ''), $rows)));
    }
}

if (!function_exists('get_active_tokens_for_users')) {
    function get_active_tokens_for_users(PDO $pdo, array $userIds): array
    {
        $schema = notification_schema($pdo);
        $t = $schema['tokens'];

        $userIds = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $userIds))));
        if (empty($userIds)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $select = [
            notification_q($t['id']) . ' AS token_id',
            notification_q($t['user_id']) . ' AS user_id',
            notification_q($t['fcm_token']) . ' AS fcm_token',
        ];

        if ($t['platform']) {
            $select[] = notification_q($t['platform']) . ' AS platform';
        } else {
            $select[] = "'unknown' AS platform";
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . notification_q($t['table'])
            . ' WHERE ' . notification_q($t['user_id']) . ' IN (' . $placeholders . ')';

        if ($t['is_active']) {
            $sql .= ' AND ' . notification_q($t['is_active']) . ' = 1';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($userIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('log_notification_result')) {
    function log_notification_result(PDO $pdo, string $notificationId, array $payload): void
    {
        $schema = notification_schema($pdo);
        $l = $schema['logs'];
        $now = date('Y-m-d H:i:s');

        $row = [];
        if ($l['id']) $row[$l['id']] = generate_uuid();
        $row[$l['notification_id']] = $notificationId;

        $optional = [
            'user_id' => $l['user_id'],
            'token_id' => $l['token_id'],
            'token_masked' => $l['token_masked'],
            'platform' => $l['platform'],
            'status' => $l['status'],
            'is_success' => $l['is_success'],
            'response_code' => $l['response_code'],
            'response_message' => $l['response_message'],
            'response_body' => $l['response_body'],
        ];

        foreach ($optional as $key => $col) {
            if ($col && array_key_exists($key, $payload)) {
                $row[$col] = $payload[$key];
            }
        }

        if ($l['created_at']) {
            $row[$l['created_at']] = $now;
        }

        notification_insert_row($pdo, $l['table'], $row);
    }
}

if (!function_exists('notification_base64url_encode')) {
    function notification_base64url_encode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}

if (!function_exists('notification_load_firebase_service_account')) {
    function notification_load_firebase_service_account(): array
    {
        static $serviceAccount = null;
        if (is_array($serviceAccount)) {
            return $serviceAccount;
        }

        $path = defined('FIREBASE_SERVICE_ACCOUNT_PATH') ? (string)FIREBASE_SERVICE_ACCOUNT_PATH : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Firebase service account dosyası okunamadı.');
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('Firebase service account içeriği okunamadı.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Firebase service account JSON formatı geçersiz.');
        }

        foreach (['project_id', 'client_email', 'private_key', 'token_uri'] as $requiredKey) {
            if (empty($decoded[$requiredKey]) || !is_string($decoded[$requiredKey])) {
                throw new RuntimeException('Firebase service account alanı eksik: ' . $requiredKey);
            }
        }

        $serviceAccount = $decoded;
        return $serviceAccount;
    }
}

if (!function_exists('notification_get_firebase_project_id')) {
    function notification_get_firebase_project_id(): string
    {
        $serviceAccount = notification_load_firebase_service_account();
        return trim((string)$serviceAccount['project_id']);
    }
}

if (!function_exists('notification_create_firebase_jwt')) {
    function notification_create_firebase_jwt(array $serviceAccount): string
    {
        $now = time();
        $scope = defined('FIREBASE_FCM_SCOPE') ? (string)FIREBASE_FCM_SCOPE : 'https://www.googleapis.com/auth/firebase.messaging';

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $claims = [
            'iss' => (string)$serviceAccount['client_email'],
            'scope' => $scope,
            'aud' => (string)$serviceAccount['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            notification_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            notification_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);

        $privateKey = openssl_pkey_get_private((string)$serviceAccount['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Firebase private key yüklenemedi.');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (PHP_VERSION_ID < 80000 && is_resource($privateKey)) {
            openssl_free_key($privateKey);
        }

        if (!$signed) {
            throw new RuntimeException('Firebase JWT imzalanamadı.');
        }

        $segments[] = notification_base64url_encode($signature);
        return implode('.', $segments);
    }
}

if (!function_exists('notification_get_firebase_access_token')) {
    function notification_get_firebase_access_token(): array
    {
        static $cache = null;
        $now = time();
        if (is_array($cache) && !empty($cache['access_token']) && (int)($cache['expires_at'] ?? 0) > ($now + 30)) {
            return $cache;
        }

        $serviceAccount = notification_load_firebase_service_account();
        $jwt = notification_create_firebase_jwt($serviceAccount);

        $ch = curl_init((string)$serviceAccount['token_uri']);
        if ($ch === false) {
            throw new RuntimeException('OAuth2 isteği başlatılamadı.');
        }

        $timeout = defined('FIREBASE_FCM_TIMEOUT') ? (int)FIREBASE_FCM_TIMEOUT : 15;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ], '', '&', PHP_QUERY_RFC3986),
        ]);

        $responseBody = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            throw new RuntimeException('OAuth2 bağlantı hatası: ' . $curlErr);
        }

        if (!is_string($responseBody) || $responseBody === '') {
            throw new RuntimeException('OAuth2 access token yanıtı boş geldi.');
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OAuth2 access token yanıtı çözümlenemedi.');
        }

        if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['access_token'])) {
            $errMsg = trim((string)($decoded['error_description'] ?? $decoded['error'] ?? 'OAuth2 access token alınamadı.'));
            throw new RuntimeException($errMsg !== '' ? $errMsg : 'OAuth2 access token alınamadı.');
        }

        $expiresIn = (int)($decoded['expires_in'] ?? 3600);
        $cache = [
            'access_token' => (string)$decoded['access_token'],
            'expires_at' => $now + max(60, $expiresIn),
        ];

        return $cache;
    }
}

if (!function_exists('notification_extract_fcm_error_codes')) {
    function notification_extract_fcm_error_codes(array $decodedBody): array
    {
        $codes = [];
        $error = $decodedBody['error'] ?? null;
        if (is_array($error)) {
            $status = trim((string)($error['status'] ?? ''));
            if ($status !== '') {
                $codes[] = $status;
            }

            $details = $error['details'] ?? null;
            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (!is_array($detail)) {
                        continue;
                    }
                    $detailCode = trim((string)($detail['errorCode'] ?? ''));
                    if ($detailCode !== '') {
                        $codes[] = $detailCode;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($codes, static fn($c) => $c !== '')));
    }
}

if (!function_exists('notification_disable_push_token')) {
    function notification_disable_push_token(PDO $pdo, array $token): void
    {
        $schema = notification_schema($pdo);
        $t = $schema['tokens'];
        if (!$t['is_active']) {
            return;
        }

        $tokenId = trim((string)($token['token_id'] ?? ''));
        if ($tokenId === '') {
            return;
        }

        $payload = [
            $t['is_active'] => 0,
        ];

        if ($t['updated_at']) {
            $payload[$t['updated_at']] = date('Y-m-d H:i:s');
        }

        notification_update_row($pdo, $t['table'], $payload, notification_q($t['id']) . ' = ?', [$tokenId]);
    }
}

if (!function_exists('notification_build_fcm_data')) {
    function notification_build_fcm_data(array $notification, array $notificationSchema): array
    {
        $payload = [];
        $payloadColumn = $notificationSchema['payload_json'] ?? null;
        if ($payloadColumn && !empty($notification[$payloadColumn])) {
            $decoded = json_decode((string)$notification[$payloadColumn], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $type = isset($payload['type']) ? (string)$payload['type'] : (string)($notification[$notificationSchema['channel'] ?? ''] ?? 'general');
        $notificationDeepLink = '';
        $deepLinkColumn = $notificationSchema['deep_link'] ?? null;
        if ($deepLinkColumn && isset($notification[$deepLinkColumn])) {
            $notificationDeepLink = (string)$notification[$deepLinkColumn];
        }
        $screen = isset($payload['screen'])
            ? (string)$payload['screen']
            : (isset($payload['deep_link']) ? (string)$payload['deep_link'] : $notificationDeepLink);
        $entityId = isset($payload['entity_id']) ? (string)$payload['entity_id'] : (string)($payload['id'] ?? '');

        return [
            'type' => $type,
            'screen' => $screen,
            'entity_id' => $entityId,
        ];
    }
}

if (!function_exists('notification_send_fcm_v1')) {
    function notification_send_fcm_v1(string $projectId, string $accessToken, string $targetToken, string $title, string $message, array $data): array
    {
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
        $requestBody = [
            'message' => [
                'token' => $targetToken,
                'notification' => [
                    'title' => $title,
                    'body' => $message,
                ],
                'data' => [
                    'type' => (string)($data['type'] ?? ''),
                    'screen' => (string)($data['screen'] ?? ''),
                    'entity_id' => (string)($data['entity_id'] ?? ''),
                ],
                'android' => [
                    'priority' => 'high',
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                ],
            ],
        ];

        $jsonBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            throw new RuntimeException('FCM isteği JSON formatına dönüştürülemedi.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('FCM bağlantısı başlatılamadı.');
        }

        $timeout = defined('FIREBASE_FCM_TIMEOUT') ? (int)FIREBASE_FCM_TIMEOUT : 15;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS => $jsonBody,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            return [
                'ok' => false,
                'http_code' => 0,
                'response_message' => 'cURL error: ' . $curlErr,
                'response_body' => null,
                'message_id' => null,
                'error_codes' => [],
            ];
        }

        $decoded = [];
        if (is_string($rawResponse) && trim($rawResponse) !== '') {
            $tmp = json_decode($rawResponse, true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        }

        $isSuccess = $httpCode >= 200 && $httpCode < 300 && !empty($decoded['name']);
        $name = trim((string)($decoded['name'] ?? ''));
        $messageId = '';
        if ($name !== '') {
            $parts = explode('/', $name);
            $messageId = trim((string)end($parts));
        }

        if ($isSuccess) {
            return [
                'ok' => true,
                'http_code' => $httpCode,
                'response_message' => 'FCM accepted',
                'response_body' => is_string($rawResponse) ? $rawResponse : null,
                'message_id' => ($messageId !== '' ? $messageId : $name),
                'error_codes' => [],
            ];
        }

        $errorCodes = notification_extract_fcm_error_codes($decoded);
        $errorMessage = trim((string)($decoded['error']['message'] ?? 'FCM gönderim hatası'));

        return [
            'ok' => false,
            'http_code' => $httpCode,
            'response_message' => $errorMessage !== '' ? $errorMessage : 'FCM gönderim hatası',
            'response_body' => is_string($rawResponse) ? $rawResponse : null,
            'message_id' => null,
            'error_codes' => $errorCodes,
        ];
    }
}

if (!function_exists('send_push_notification')) {
    function send_push_notification(PDO $pdo, string $notificationId): array
    {
        $schema = notification_schema($pdo);
        $n = $schema['notifications'];

        $notification = notification_fetch_one($pdo, $notificationId);
        if (!$notification) {
            throw new RuntimeException('Bildirim kaydı bulunamadı.');
        }

        $targets = resolve_notification_targets($pdo, $notificationId);
        $resolvedTargetCount = count($targets);

        if ($resolvedTargetCount === 0) {
            $targetType = $n['target_type'] ? (string)($notification[$n['target_type']] ?? 'all_users') : 'all_users';
            if ($targetType === 'single_user') {
                throw new InvalidArgumentException('Tek kullanıcı bulunamadı. Lütfen kullanıcı seçimini kontrol edin.');
            }
            throw new InvalidArgumentException('Hedef kullanıcı bulunamadı. Bildirim gönderimi iptal edildi.');
        }

        $tokens = get_active_tokens_for_users($pdo, $targets);
        $fcmData = notification_build_fcm_data($notification, $n);

        $total = count($tokens);
        $success = 0;
        $failed = 0;

        $accessToken = null;
        $projectId = '';
        $initError = null;

        try {
            $projectId = notification_get_firebase_project_id();
            $tokenBundle = notification_get_firebase_access_token();
            $accessToken = (string)($tokenBundle['access_token'] ?? '');
            if ($projectId === '' || $accessToken === '') {
                throw new RuntimeException('Firebase erişim bilgileri eksik.');
            }
        } catch (Throwable $e) {
            $initError = $e;
            error_log('[notification.send_push_notification] Firebase init error: ' . $e->getMessage());
        }

        foreach ($tokens as $token) {
            $tokenValue = trim((string)($token['fcm_token'] ?? ''));
            $tokenMasked = mask_token($tokenValue);

            $isSuccess = false;
            $responseCode = 500;
            $responseMessage = 'Bilinmeyen hata';
            $responseBody = null;

            try {
                if ($initError !== null) {
                    throw new RuntimeException($initError->getMessage());
                }

                if ($tokenValue === '') {
                    throw new RuntimeException('FCM token boş olduğu için gönderim atlandı.');
                }

                $sendResult = notification_send_fcm_v1(
                    $projectId,
                    (string)$accessToken,
                    $tokenValue,
                    trim((string)($notification[$n['title']] ?? '')),
                    trim((string)($notification[$n['message']] ?? '')),
                    $fcmData
                );

                $isSuccess = (bool)($sendResult['ok'] ?? false);
                $responseCode = (int)($sendResult['http_code'] ?? 500);
                $responseBody = $sendResult['response_body'] ?? null;

                if ($isSuccess) {
                    $messageId = trim((string)($sendResult['message_id'] ?? ''));
                    $responseMessage = $messageId !== ''
                        ? 'FCM message accepted (message_id: ' . $messageId . ')'
                        : 'FCM message accepted';
                } else {
                    $responseMessage = (string)($sendResult['response_message'] ?? 'FCM gönderim hatası');

                    $errorCodes = array_map('strtoupper', $sendResult['error_codes'] ?? []);
                    $shouldDisableToken = in_array('UNREGISTERED', $errorCodes, true)
                        || in_array('INVALID_ARGUMENT', $errorCodes, true)
                        || in_array('NOT_FOUND', $errorCodes, true);

                    if ($shouldDisableToken) {
                        notification_disable_push_token($pdo, $token);
                    }
                }
            } catch (Throwable $e) {
                $isSuccess = false;
                $responseCode = ($responseCode > 0 ? $responseCode : 500);
                $responseMessage = $e->getMessage();
                $responseBody = $responseBody ?? null;
            }

            if ($isSuccess) {
                $success++;
            } else {
                $failed++;
            }

            log_notification_result($pdo, $notificationId, [
                'user_id' => (string)($token['user_id'] ?? ''),
                'token_id' => (string)($token['token_id'] ?? ''),
                'token_masked' => $tokenMasked,
                'platform' => (string)($token['platform'] ?? 'unknown'),
                'status' => $isSuccess ? 'sent' : 'failed',
                'is_success' => $isSuccess ? 1 : 0,
                'response_code' => $responseCode,
                'response_message' => $responseMessage,
                'response_body' => is_string($responseBody) ? $responseBody : null,
            ]);
        }

        $update = [];
        if ($n['status']) $update[$n['status']] = 'sent';
        if ($n['sent_at']) $update[$n['sent_at']] = date('Y-m-d H:i:s');
        if ($n['total_target']) $update[$n['total_target']] = $resolvedTargetCount;
        if ($n['success_count']) $update[$n['success_count']] = $success;
        if ($n['failure_count']) $update[$n['failure_count']] = $failed;
        if ($n['updated_at']) $update[$n['updated_at']] = date('Y-m-d H:i:s');

        notification_update_row($pdo, $n['table'], $update, notification_q($n['id']) . ' = ?', [$notificationId]);

        return [
            'notification_id' => $notificationId,
            'resolved_target_count' => $resolvedTargetCount,
            'total_target' => $resolvedTargetCount,
            'token_target_count' => $total,
            'success' => $success,
            'failed' => $failed,
            'mock' => false,
        ];
    }
}

if (!function_exists('notification_rule_aliases')) {
    function notification_rule_aliases(): array
    {
        return [
            'daily_reset' => 'daily_reset',
            'daily_rights_reset' => 'daily_reset',
            'inactive_3_days' => 'inactive_3_days',
            'inactive_7_days_exam' => 'inactive_7_days_exam',
            'no_exam_7_days' => 'inactive_7_days_exam',
            'premium_expiring' => 'premium_expiring',
        ];
    }
}

if (!function_exists('notification_rule_canonical_key')) {
    function notification_rule_canonical_key(?string $key): string
    {
        $value = strtolower(trim((string)$key));
        if ($value === '') {
            return '';
        }

        $aliases = notification_rule_aliases();
        return $aliases[$value] ?? $value;
    }
}

if (!function_exists('notification_rule_definitions')) {
    function notification_rule_definitions(): array
    {
        return [
            'daily_reset' => [
                'name' => 'Günlük haklar yenilendi',
                'description' => 'Kullanıcılara günlük haklarının yenilendiğini hatırlatır.',
                'title' => 'Bugünkü hakların yenilendi',
                'message' => 'Çalışma ve deneme hakların yeniden hazır. Hedefine devam et.',
                'channel' => 'study',
                'deep_link' => 'study',
                'config_defaults' => [
                    'send_hour' => 9,
                    'send_minute' => 0,
                ],
            ],
            'inactive_3_days' => [
                'name' => '3 gündür aktif değil',
                'description' => '3 gündür uygulamaya girmeyen kullanıcıları hedefler.',
                'title' => 'Seni tekrar görmek güzel olur',
                'message' => '3 gündür uygulamaya girmedin. Kaldığın yerden devam et.',
                'channel' => 'general',
                'deep_link' => 'dashboard',
                'config_defaults' => [
                    'days_threshold' => 3,
                    'send_hour' => 19,
                    'send_minute' => 0,
                ],
            ],
            'inactive_7_days_exam' => [
                'name' => '7 gündür deneme çözmedi',
                'description' => 'Son 7 günde deneme tamamlamayan kullanıcıları hedefler.',
                'title' => 'Bugün kısa bir deneme çöz',
                'message' => '7 gündür deneme çözmedin. Ritmini korumak için kısa bir deneme yap.',
                'channel' => 'exam',
                'deep_link' => 'exam',
                'config_defaults' => [
                    'days_threshold' => 7,
                    'send_hour' => 20,
                    'send_minute' => 0,
                ],
            ],
            'premium_expiring' => [
                'name' => 'Premium süresi bitiyor',
                'description' => 'Premium bitişi yaklaşan kullanıcıları bilgilendirir.',
                'title' => 'Premium süren yakında bitiyor',
                'message' => 'Sınırsız erişiminin kesilmemesi için üyeliğini kontrol et.',
                'channel' => 'premium',
                'deep_link' => 'profile',
                'config_defaults' => [
                    'days_before_expiry' => 2,
                    'send_hour' => 12,
                    'send_minute' => 0,
                ],
            ],
        ];
    }
}

if (!function_exists('notification_rule_default_config')) {
    function notification_rule_default_config(string $ruleKey): array
    {
        $canonical = notification_rule_canonical_key($ruleKey);
        $defs = notification_rule_definitions();
        return (array)($defs[$canonical]['config_defaults'] ?? []);
    }
}

if (!function_exists('notification_rule_normalize_config')) {
    function notification_rule_normalize_config(string $ruleKey, array $config): array
    {
        $key = notification_rule_canonical_key($ruleKey);
        $defaults = notification_rule_default_config($key);
        $merged = array_merge($defaults, $config);

        $normHour = static function ($value, int $fallback): int {
            $v = (int)$value;
            if ($v < 0 || $v > 23) {
                return $fallback;
            }
            return $v;
        };
        $normMinute = static function ($value, int $fallback): int {
            $v = (int)$value;
            if ($v < 0 || $v > 59) {
                return $fallback;
            }
            return $v;
        };

        if (array_key_exists('send_hour', $defaults)) {
            $merged['send_hour'] = $normHour($merged['send_hour'] ?? $defaults['send_hour'], (int)$defaults['send_hour']);
        }
        if (array_key_exists('send_minute', $defaults)) {
            $merged['send_minute'] = $normMinute($merged['send_minute'] ?? $defaults['send_minute'], (int)$defaults['send_minute']);
        }

        if ($key === 'inactive_3_days' || $key === 'inactive_7_days_exam') {
            $defaultDays = (int)($defaults['days_threshold'] ?? ($key === 'inactive_3_days' ? 3 : 7));
            $days = (int)($merged['days_threshold'] ?? $defaultDays);
            $merged['days_threshold'] = max(1, min(60, $days));
        }

        if ($key === 'premium_expiring') {
            $defaultDays = (int)($defaults['days_before_expiry'] ?? 2);
            $days = (int)($merged['days_before_expiry'] ?? $defaultDays);
            $merged['days_before_expiry'] = max(0, min(30, $days));
        }

        return $merged;
    }
}

if (!function_exists('notification_rule_config_from_json')) {
    function notification_rule_config_from_json(string $ruleKey, ?string $configJson): array
    {
        $raw = trim((string)$configJson);
        $decoded = [];
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $decoded = $tmp;
            }
        }
        return notification_rule_normalize_config($ruleKey, $decoded);
    }
}

if (!function_exists('notification_rule_config_to_json')) {
    function notification_rule_config_to_json(string $ruleKey, array $config): string
    {
        $normalized = notification_rule_normalize_config($ruleKey, $config);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }
}

if (!function_exists('notification_rule_is_due_now')) {
    function notification_rule_is_due_now(array $ruleConfig, ?DateTimeImmutable $now = null, int $windowMinutes = 5): bool
    {
        $tz = new DateTimeZone('Europe/Istanbul');
        $now = $now ?: new DateTimeImmutable('now', $tz);
        if ($now->getTimezone()->getName() !== $tz->getName()) {
            $now = $now->setTimezone($tz);
        }

        $hour = (int)($ruleConfig['send_hour'] ?? 0);
        $minute = (int)($ruleConfig['send_minute'] ?? 0);
        $slot = $now->setTime($hour, $minute, 0);
        $diff = $now->getTimestamp() - $slot->getTimestamp();
        return $diff >= 0 && $diff < (max(1, $windowMinutes) * 60);
    }
}

if (!function_exists('notification_fetch_active_rules')) {
    function notification_fetch_active_rules(PDO $pdo): array
    {
        $schema = notification_schema($pdo);
        $r = $schema['rules'];
        $defs = notification_rule_definitions();

        $select = [
            notification_q($r['id']) . ' AS id',
            ($r['slug'] ? notification_q($r['slug']) : 'NULL') . ' AS slug',
            ($r['name'] ? notification_q($r['name']) : 'NULL') . ' AS name',
            ($r['description'] ? notification_q($r['description']) : 'NULL') . ' AS description',
            ($r['config_json'] ? notification_q($r['config_json']) : 'NULL') . ' AS config_json',
            ($r['is_active'] ? notification_q($r['is_active']) : '1') . ' AS is_active',
        ];
        $sql = 'SELECT ' . implode(', ', $select)
            . ' FROM ' . notification_q($r['table'])
            . ($r['is_active'] ? ' WHERE ' . notification_q($r['is_active']) . ' = 1' : '');

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];

        foreach ($rows as $row) {
            $canonical = notification_rule_canonical_key((string)($row['slug'] ?? ''));
            if ($canonical === '' && !empty($row['name'])) {
                $nameKey = mb_strtolower(trim((string)$row['name']), 'UTF-8');
                foreach ($defs as $key => $def) {
                    if (mb_strtolower((string)($def['name'] ?? ''), 'UTF-8') === $nameKey) {
                        $canonical = $key;
                        break;
                    }
                }
            }

            if (!isset($defs[$canonical])) {
                continue;
            }

            $result[] = [
                'id' => (string)($row['id'] ?? ''),
                'rule_key' => $canonical,
                'slug' => (string)($row['slug'] ?? ''),
                'name' => (string)($row['name'] ?: ($defs[$canonical]['name'] ?? $canonical)),
                'description' => (string)($row['description'] ?: ($defs[$canonical]['description'] ?? '')),
                'config' => notification_rule_config_from_json($canonical, (string)($row['config_json'] ?? '')),
                'config_json' => (string)($row['config_json'] ?? ''),
                'is_active' => (int)($row['is_active'] ?? 0),
            ];
        }

        return $result;
    }
}

if (!function_exists('notification_rule_run_exists')) {
    function notification_rule_run_exists(PDO $pdo, string $ruleKey, string $userId, string $runDate): bool
    {
        $schema = notification_schema($pdo);
        $rr = $schema['rule_runs'];
        if (!$rr['rule_key'] || !$rr['user_id'] || !$rr['run_date']) {
            throw new RuntimeException('notification_rule_runs tablosu zorunlu kolonları eksik.');
        }

        $sql = 'SELECT 1 FROM ' . notification_q($rr['table'])
            . ' WHERE ' . notification_q($rr['rule_key']) . ' = ?'
            . ' AND ' . notification_q($rr['user_id']) . ' = ?'
            . ' AND ' . notification_q($rr['run_date']) . ' = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ruleKey, $userId, $runDate]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('notification_rule_run_insert')) {
    function notification_rule_run_insert(PDO $pdo, string $ruleKey, string $userId, string $runDate, string $status, ?string $notificationId = null): void
    {
        $schema = notification_schema($pdo);
        $rr = $schema['rule_runs'];
        if (!$rr['rule_key'] || !$rr['user_id'] || !$rr['run_date'] || !$rr['status']) {
            throw new RuntimeException('notification_rule_runs tablosu zorunlu kolonları eksik.');
        }

        $row = [
            $rr['rule_key'] => $ruleKey,
            $rr['user_id'] => $userId,
            $rr['run_date'] => $runDate,
            $rr['status'] => $status,
        ];
        if ($rr['id']) {
            $row[$rr['id']] = generate_uuid();
        }
        if ($rr['notification_id'] && $notificationId !== null) {
            $row[$rr['notification_id']] = $notificationId;
        }

        notification_insert_row($pdo, $rr['table'], $row);
    }
}

if (!function_exists('notification_rule_run_update')) {
    function notification_rule_run_update(PDO $pdo, string $ruleKey, string $userId, string $runDate, string $status, ?string $notificationId = null): void
    {
        $schema = notification_schema($pdo);
        $rr = $schema['rule_runs'];
        if (!$rr['rule_key'] || !$rr['user_id'] || !$rr['run_date'] || !$rr['status']) {
            throw new RuntimeException('notification_rule_runs tablosu zorunlu kolonları eksik.');
        }

        $payload = [
            $rr['status'] => $status,
        ];
        if ($rr['notification_id'] && $notificationId !== null) {
            $payload[$rr['notification_id']] = $notificationId;
        }

        notification_update_row(
            $pdo,
            $rr['table'],
            $payload,
            notification_q($rr['rule_key']) . ' = ? AND ' . notification_q($rr['user_id']) . ' = ? AND ' . notification_q($rr['run_date']) . ' = ?',
            [$ruleKey, $userId, $runDate]
        );
    }
}

if (!function_exists('notification_get_candidate_users_for_rule')) {
    function notification_get_candidate_users_for_rule(PDO $pdo, string $ruleKey, array $config): array
    {
        $schema = notification_schema($pdo);
        $u = $schema['users'];
        $a = $schema['api_tokens'];
        $m = $schema['mock_exam_attempts'];
        $s = $schema['subscription'];

        $baseWhere = '1=1';
        if ($u['is_deleted']) {
            $baseWhere .= ' AND u.' . notification_q($u['is_deleted']) . ' = 0';
        }

        $ruleKey = notification_rule_canonical_key($ruleKey);

        if ($ruleKey === 'daily_reset') {
            $sql = 'SELECT u.' . notification_q($u['id']) . ' AS user_id'
                . ' FROM ' . notification_q($u['table']) . ' u'
                . ' WHERE ' . $baseWhere;
            return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        }

        if ($ruleKey === 'inactive_3_days') {
            $days = max(1, (int)($config['days_threshold'] ?? 3));
            $activityExpr = $u['last_sign_in_at']
                ? ('COALESCE(u.' . notification_q($u['last_sign_in_at']) . ', NOW())')
                : 'NOW()';

            $join = '';
            if ($a['user_id'] && $a['last_used_at']) {
                $join = ' LEFT JOIN ('
                    . 'SELECT ' . notification_q($a['user_id']) . ' AS user_id, MAX(' . notification_q($a['last_used_at']) . ') AS api_last_used_at'
                    . ' FROM ' . notification_q($a['table'])
                    . ' WHERE ' . ($a['revoked_at'] ? (notification_q($a['revoked_at']) . ' IS NULL') : '1=1')
                    . ' AND ' . ($a['expires_at'] ? ('(' . notification_q($a['expires_at']) . ' IS NULL OR ' . notification_q($a['expires_at']) . ' > NOW())') : '1=1')
                    . ' GROUP BY ' . notification_q($a['user_id'])
                    . ') atok ON atok.user_id = u.' . notification_q($u['id']);
                if ($u['last_sign_in_at']) {
                    $activityExpr = 'GREATEST(COALESCE(u.' . notification_q($u['last_sign_in_at']) . ', \'1970-01-01 00:00:00\'), COALESCE(atok.api_last_used_at, \'1970-01-01 00:00:00\'))';
                } else {
                    $activityExpr = 'COALESCE(atok.api_last_used_at, \'1970-01-01 00:00:00\')';
                }
            } elseif ($u['last_sign_in_at']) {
                $activityExpr = 'COALESCE(u.' . notification_q($u['last_sign_in_at']) . ', \'1970-01-01 00:00:00\')';
            } elseif ($u['created_at']) {
                $activityExpr = 'COALESCE(u.' . notification_q($u['created_at']) . ', \'1970-01-01 00:00:00\')';
            }

            $sql = 'SELECT u.' . notification_q($u['id']) . ' AS user_id'
                . ' FROM ' . notification_q($u['table']) . ' u'
                . $join
                . ' WHERE ' . $baseWhere
                . ' AND ' . $activityExpr . ' < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)';

            return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        }

        if ($ruleKey === 'inactive_7_days_exam') {
            $days = max(1, (int)($config['days_threshold'] ?? 7));
            if (!$m['user_id']) {
                return [];
            }

            $attemptTime = $m['submitted_at'] ?: $m['created_at'];
            if (!$attemptTime) {
                return [];
            }

            $statusCond = '1=1';
            if ($m['status']) {
                $statusCond = 'ma.' . notification_q($m['status']) . " = 'completed'";
            }

            $sql = 'SELECT u.' . notification_q($u['id']) . ' AS user_id'
                . ' FROM ' . notification_q($u['table']) . ' u'
                . ' LEFT JOIN ('
                . '   SELECT ma.' . notification_q($m['user_id']) . ' AS user_id, MAX(ma.' . notification_q($attemptTime) . ') AS last_exam_at'
                . '   FROM ' . notification_q($m['table']) . ' ma'
                . '   WHERE ' . $statusCond
                . '   GROUP BY ma.' . notification_q($m['user_id'])
                . ' ) mex ON mex.user_id = u.' . notification_q($u['id'])
                . ' WHERE ' . $baseWhere
                . ' AND (mex.last_exam_at IS NULL OR mex.last_exam_at < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY))';

            return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        }

        if ($ruleKey === 'premium_expiring') {
            $days = max(0, (int)($config['days_before_expiry'] ?? 2));
            if (!$s['user_id'] || !$s['is_pro'] || !$s['expires_at']) {
                return [];
            }

            $subOrder = $s['updated_at'] ?: ($s['created_at'] ?: $s['user_id']);
            $sql = 'SELECT u.' . notification_q($u['id']) . ' AS user_id'
                . ' FROM ' . notification_q($u['table']) . ' u'
                . ' INNER JOIN ('
                . '   SELECT s1.' . notification_q($s['user_id']) . ' AS user_id, s1.' . notification_q($s['is_pro']) . ' AS is_pro, s1.' . notification_q($s['expires_at']) . ' AS expires_at'
                . '   FROM ' . notification_q($s['table']) . ' s1'
                . '   INNER JOIN ('
                . '      SELECT ' . notification_q($s['user_id']) . ' AS user_id, MAX(' . notification_q($subOrder) . ') AS max_order'
                . '      FROM ' . notification_q($s['table'])
                . '      GROUP BY ' . notification_q($s['user_id'])
                . '   ) sm ON sm.user_id = s1.' . notification_q($s['user_id']) . ' AND sm.max_order = s1.' . notification_q($subOrder)
                . ' ) sub ON sub.user_id = u.' . notification_q($u['id'])
                . ' WHERE ' . $baseWhere
                . ' AND COALESCE(sub.is_pro, 0) = 1'
                . ' AND sub.expires_at IS NOT NULL'
                . ' AND DATE(sub.expires_at) = DATE(DATE_ADD(CURDATE(), INTERVAL ' . (int)$days . ' DAY))';

            return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        }

        return [];
    }
}

if (!function_exists('notification_create_automated_notification')) {
    function notification_create_automated_notification(PDO $pdo, string $ruleKey, string $userId): string
    {
        $key = notification_rule_canonical_key($ruleKey);
        $defs = notification_rule_definitions();
        if (!isset($defs[$key])) {
            throw new RuntimeException('Desteklenmeyen kural: ' . $ruleKey);
        }

        $def = $defs[$key];
        $payload = [
            'title' => (string)$def['title'],
            'message' => (string)$def['message'],
            'channel' => (string)$def['channel'],
            'deep_link' => (string)$def['deep_link'],
            'target_type' => 'single_user',
            'target_value' => json_encode([
                'user_id' => $userId,
                'rule_key' => $key,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode([
                'source' => 'rule_engine',
                'rule_key' => $key,
                'user_id' => $userId,
                'screen' => (string)$def['deep_link'],
                'type' => (string)$def['channel'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'queued',
            'schedule_type' => 'now',
            'scheduled_at' => null,
            'created_by' => null,
        ];

        return notification_create_or_update($pdo, $payload);
    }
}

if (!function_exists('notification_mark_automation_notification_status')) {
    function notification_mark_automation_notification_status(PDO $pdo, string $notificationId, array $sendResult): string
    {
        $schema = notification_schema($pdo);
        $n = $schema['notifications'];

        $success = (int)($sendResult['success'] ?? 0);
        $failed = (int)($sendResult['failed'] ?? 0);
        $tokenTargets = (int)($sendResult['token_target_count'] ?? 0);

        $status = 'failed';
        if ($success > 0 && $failed > 0) {
            $status = 'partial';
        } elseif ($success > 0 && $failed === 0) {
            $status = 'sent';
        } elseif ($tokenTargets === 0) {
            $status = 'failed';
        }

        $update = [];
        if ($n['status']) {
            $update[$n['status']] = $status;
        }
        if ($n['updated_at']) {
            $update[$n['updated_at']] = date('Y-m-d H:i:s');
        }
        if (!empty($update)) {
            notification_update_row($pdo, $n['table'], $update, notification_q($n['id']) . ' = ?', [$notificationId]);
        }

        return $status;
    }
}

if (!function_exists('notification_run_rules_engine')) {
    function notification_run_rules_engine(PDO $pdo, array $options = []): array
    {
        date_default_timezone_set('Europe/Istanbul');

        $force = !empty($options['force']);
        $windowMinutes = max(1, (int)($options['window_minutes'] ?? 5));

        $stats = [
            'processed_rules' => 0,
            'due_rules' => 0,
            'candidate_users' => 0,
            'created_notifications' => 0,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

        $rules = notification_fetch_active_rules($pdo);
        foreach ($rules as $rule) {
            $stats['processed_rules']++;

            $ruleKey = (string)$rule['rule_key'];
            $config = (array)($rule['config'] ?? []);
            $isDue = $force ? true : notification_rule_is_due_now($config, $now, $windowMinutes);
            if (!$isDue) {
                continue;
            }
            $stats['due_rules']++;

            $users = notification_get_candidate_users_for_rule($pdo, $ruleKey, $config);
            $users = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $users))));
            $stats['candidate_users'] += count($users);

            foreach ($users as $userId) {
                try {
                    if (notification_rule_run_exists($pdo, $ruleKey, $userId, $today)) {
                        $stats['skipped']++;
                        continue;
                    }

                    notification_rule_run_insert($pdo, $ruleKey, $userId, $today, 'created', null);
                    $notificationId = notification_create_automated_notification($pdo, $ruleKey, $userId);
                    $stats['created_notifications']++;

                    $sendResult = send_push_notification($pdo, $notificationId);
                    $notificationStatus = notification_mark_automation_notification_status($pdo, $notificationId, $sendResult);
                    $runStatus = ($notificationStatus === 'failed') ? 'failed' : 'sent';
                    notification_rule_run_update($pdo, $ruleKey, $userId, $today, $runStatus, $notificationId);

                    if ($runStatus === 'sent') {
                        $stats['sent']++;
                    } else {
                        $stats['failed']++;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    try {
                        if (!notification_rule_run_exists($pdo, $ruleKey, $userId, $today)) {
                            notification_rule_run_insert($pdo, $ruleKey, $userId, $today, 'failed', null);
                        } else {
                            notification_rule_run_update($pdo, $ruleKey, $userId, $today, 'failed', null);
                        }
                    } catch (Throwable $inner) {
                        // no-op
                    }

                    $stats['details'][] = [
                        'rule_key' => $ruleKey,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $stats;
    }
}

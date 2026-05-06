<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('POST');

function rewarded_daily_usage_schema(PDO $pdo): ?array
{
    $cols = get_table_columns($pdo, 'rewarded_ad_daily_usage');
    if (!$cols || !is_array($cols)) {
        return null;
    }

    $pick = static function (array $candidates, bool $required = false) use ($cols): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $cols, true)) {
                return $candidate;
            }
        }
        return $required ? '' : null;
    };

    $schema = [
        'table' => 'rewarded_ad_daily_usage',
        'id' => $pick(['id']),
        'user_id' => $pick(['user_id'], true),
        'type' => $pick(['reward_type', 'type'], true),
        'date' => $pick(['usage_date_tr', 'usage_date', 'date_tr'], true),
        'count' => $pick(['watched_count', 'watch_count'], true),
        'created_at' => $pick(['created_at']),
        'updated_at' => $pick(['updated_at']),
    ];

    if (
        $schema['user_id'] === '' ||
        $schema['type'] === '' ||
        $schema['date'] === '' ||
        $schema['count'] === ''
    ) {
        return null;
    }

    return $schema;
}

function rewarded_usage_get_for_update(PDO $pdo, array $schema, string $userId, string $type, string $today): array
{
    $sql = 'SELECT '
        . ($schema['id'] ? ('`' . $schema['id'] . '` AS id, ') : 'NULL AS id, ')
        . '`' . $schema['count'] . '` AS watched_count '
        . 'FROM `' . $schema['table'] . '` '
        . 'WHERE `' . $schema['user_id'] . '` = ? '
        . 'AND `' . $schema['type'] . '` = ? '
        . 'AND `' . $schema['date'] . '` = ? '
        . 'LIMIT 1 FOR UPDATE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $type, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['id' => null, 'watched_count' => 0];
    }

    return [
        'id' => $row['id'] ?? null,
        'watched_count' => max(0, (int)($row['watched_count'] ?? 0)),
    ];
}

function rewarded_usage_insert_if_missing(PDO $pdo, array $schema, string $userId, string $type, string $today): void
{
    $cols = [
        '`' . $schema['user_id'] . '`',
        '`' . $schema['type'] . '`',
        '`' . $schema['date'] . '`',
        '`' . $schema['count'] . '`',
    ];
    $vals = ['?', '?', '?', '?'];
    $params = [$userId, $type, $today, 0];

    if (!empty($schema['id'])) {
        $cols[] = '`' . $schema['id'] . '`';
        $vals[] = '?';
        $params[] = generate_uuid();
    }
    if (!empty($schema['created_at'])) {
        $cols[] = '`' . $schema['created_at'] . '`';
        $vals[] = 'NOW()';
    }
    if (!empty($schema['updated_at'])) {
        $cols[] = '`' . $schema['updated_at'] . '`';
        $vals[] = 'NOW()';
    }

    try {
        $sql = 'INSERT INTO `' . $schema['table'] . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        // yarış durumunda başka istek eklemiş olabilir
    }
}

function rewarded_usage_increment(PDO $pdo, array $schema, string $userId, string $type, string $today): void
{
    $set = ['`' . $schema['count'] . '` = `' . $schema['count'] . '` + 1'];
    if (!empty($schema['updated_at'])) {
        $set[] = '`' . $schema['updated_at'] . '` = NOW()';
    }

    $sql = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set)
        . ' WHERE `' . $schema['user_id'] . '` = ?'
        . ' AND `' . $schema['type'] . '` = ?'
        . ' AND `' . $schema['date'] . '` = ?'
        . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $type, $today]);
}

function rewarded_daily_usage_read_counts(PDO $pdo, string $userId, string $today): array
{
    $schema = rewarded_daily_usage_schema($pdo);
    if ($schema === null) {
        return ['study' => 0, 'exam' => 0];
    }

    $sql = 'SELECT `' . $schema['type'] . '` AS reward_type, `' . $schema['count'] . '` AS watched_count'
        . ' FROM `' . $schema['table'] . '`'
        . ' WHERE `' . $schema['user_id'] . '` = ? AND `' . $schema['date'] . '` = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $today]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = ['study' => 0, 'exam' => 0];
    foreach ($rows as $row) {
        $type = strtolower(trim((string)($row['reward_type'] ?? '')));
        if (!in_array($type, ['study', 'exam'], true)) {
            continue;
        }
        $out[$type] = max(0, (int)($row['watched_count'] ?? 0));
    }

    return $out;
}

function rewarded_build_summary(PDO $pdo, string $userId, bool $isPro, array $runtime): array
{
    $today = usage_limits_tr_date();
    $counts = rewarded_daily_usage_read_counts($pdo, $userId, $today);

    $studyBonus = max(1, (int)($runtime['rewarded_study_bonus'] ?? 10));
    $examBonus = max(1, (int)($runtime['rewarded_mock_exam_bonus'] ?? 1));
    $studyLimit = max(0, (int)($runtime['rewarded_study_daily_ad_limit'] ?? 3));
    $examLimit = max(0, (int)($runtime['rewarded_mock_exam_daily_ad_limit'] ?? 1));
    $studyWatched = max(0, (int)($counts['study'] ?? 0));
    $examWatched = max(0, (int)($counts['exam'] ?? 0));

    $studyRemaining = $studyLimit <= 0 ? 0 : max(0, $studyLimit - $studyWatched);
    $examRemaining = $examLimit <= 0 ? 0 : max(0, $examLimit - $examWatched);

    return [
        'study_bonus' => $studyBonus,
        'exam_bonus' => $examBonus,
        'study_daily_ad_limit' => $studyLimit,
        'exam_daily_ad_limit' => $examLimit,
        'study_ads_watched_today' => $studyWatched,
        'exam_ads_watched_today' => $examWatched,
        'study_ads_remaining_today' => $studyRemaining,
        'exam_ads_remaining_today' => $examRemaining,
        'study_reward_available' => (!$isPro && $studyLimit > 0 && $studyRemaining > 0),
        'exam_reward_available' => (!$isPro && $examLimit > 0 && $examRemaining > 0),
    ];
}

function rewarded_normalize_platform(array $payload): string
{
    $platform = strtolower(trim((string)($payload['platform'] ?? '')));
    if ($platform === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $platform = strtolower(trim((string)($headers['X-Platform'] ?? $headers['x-platform'] ?? '')));
            if ($platform === '') {
                $platform = strtolower(trim((string)($headers['X-Client-Platform'] ?? $headers['x-client-platform'] ?? '')));
            }
        }
    }

    if ($platform === '' && !empty($_SERVER['HTTP_X_PLATFORM'])) {
        $platform = strtolower(trim((string)$_SERVER['HTTP_X_PLATFORM']));
    }
    if ($platform === '' && !empty($_SERVER['HTTP_X_CLIENT_PLATFORM'])) {
        $platform = strtolower(trim((string)$_SERVER['HTTP_X_CLIENT_PLATFORM']));
    }

    return in_array($platform, ['android', 'ios'], true) ? $platform : 'unknown';
}

function rewarded_log_event(PDO $pdo, array $event): void
{
    $sql = 'INSERT INTO `rewarded_ad_events` '
        . '(`user_id`, `reward_type`, `platform`, `bonus_amount`, `ad_unit_id`, `reward_item_type`, `reward_item_amount`, `ip_address`, `user_agent`, `created_at`) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $event['user_id'],
        $event['reward_type'],
        $event['platform'],
        $event['bonus_amount'],
        $event['ad_unit_id'],
        $event['reward_item_type'],
        $event['reward_item_amount'],
        $event['ip_address'],
        $event['user_agent'],
    ]);
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'rewarded.apply');

    if ($userId === '') {
        api_error('Geçersiz kullanıcı.', 401);
    }

    if (usage_limits_is_user_pro($pdo, $userId)) {
        usage_limits_business_error(
            'PREMIUM_USERS_DO_NOT_USE_REWARDED_ADS',
            'Premium kullanıcılar reklam izlemez.',
            403,
            usage_limits_get_summary($pdo, $userId, $qualificationId)
        );
    }

    $payload = api_get_request_data();
    $type = strtolower(trim((string)($payload['type'] ?? '')));

    if (!in_array($type, ['study', 'exam'], true)) {
        api_error('type alanı study veya exam olmalıdır.', 422);
    }

    $runtime = app_runtime_settings_get($pdo);
    $today = usage_limits_tr_date();
    $platform = rewarded_normalize_platform($payload);
    $adUnitId = trim((string)($payload['ad_unit_id'] ?? ''));
    $rewardItemType = trim((string)($payload['reward_item_type'] ?? ''));
    $rewardItemAmountRaw = $payload['reward_item_amount'] ?? null;
    $rewardItemAmount = is_numeric($rewardItemAmountRaw) ? (int)$rewardItemAmountRaw : null;

    if ($type === 'study') {
        $bonus = max(1, (int)($runtime['rewarded_study_bonus'] ?? 10));
        $dailyLimit = max(0, (int)($runtime['rewarded_study_daily_ad_limit'] ?? 3));
        $featureKey = USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN;
        $limitReachedMessage = 'Bugünkü çalışma reklamı izleme hakkınız doldu.';
    } else {
        $bonus = max(1, (int)($runtime['rewarded_mock_exam_bonus'] ?? 1));
        $dailyLimit = max(0, (int)($runtime['rewarded_mock_exam_daily_ad_limit'] ?? 1));
        $featureKey = USAGE_LIMIT_FEATURE_MOCK_EXAM_START;
        $limitReachedMessage = 'Bugünkü deneme reklamı izleme hakkınız doldu.';
    }

    if ($dailyLimit <= 0) {
        usage_limits_business_error(
            'REWARDED_AD_DISABLED',
            'Reklamla hak kazanma şu anda kapalı.',
            403,
            usage_limits_get_summary($pdo, $userId, $qualificationId)
        );
    }

    $schema = rewarded_daily_usage_schema($pdo);
    if ($schema === null) {
        throw new RuntimeException('rewarded_ad_daily_usage tablosu/kolonları okunamadı.');
    }

    $pdo->beginTransaction();
    try {
        rewarded_usage_insert_if_missing($pdo, $schema, $userId, $type, $today);
        $rewardUsage = rewarded_usage_get_for_update($pdo, $schema, $userId, $type, $today);
        if ((int)($rewardUsage['watched_count'] ?? 0) >= $dailyLimit) {
            $pdo->rollBack();
            usage_limits_business_error(
                'REWARDED_DAILY_LIMIT_REACHED',
                $limitReachedMessage,
                429,
                usage_limits_get_summary($pdo, $userId, $qualificationId)
            );
        }

        $schema = usage_limits_get_daily_counter_schema($pdo);
        $counter = usage_limits_get_or_create_counter($pdo, $userId, $qualificationId, $featureKey, $today);

        $set = [
            usage_limits_q($schema['used_count']) . ' = GREATEST(' . usage_limits_q($schema['used_count']) . ' - ?, 0)',
        ];
        $params = [$bonus];

        if (!empty($schema['updated_at'])) {
            $set[] = usage_limits_q($schema['updated_at']) . ' = NOW()';
        }

        if (!empty($schema['id']) && !empty($counter['id'])) {
            $where = usage_limits_q($schema['id']) . ' = ?';
            $params[] = (string)$counter['id'];
        } else {
            $where = usage_limits_q($schema['user_id']) . ' = ?'
                . ' AND ' . usage_limits_q($schema['qualification_id']) . ' = ?'
                . ' AND ' . usage_limits_q($schema['usage_date_tr']) . ' = ?'
                . ' AND ' . usage_limits_q($schema['feature_key']) . ' = ?';
            $params[] = $userId;
            $params[] = $qualificationId;
            $params[] = $today;
            $params[] = $featureKey;
        }

        $sql = 'UPDATE ' . usage_limits_q($schema['table'])
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $where;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        rewarded_usage_increment($pdo, rewarded_daily_usage_schema($pdo), $userId, $type, $today);

        try {
            rewarded_log_event($pdo, [
                'user_id' => $userId,
                'reward_type' => $type,
                'platform' => $platform,
                'bonus_amount' => $bonus,
                'ad_unit_id' => ($adUnitId !== '' ? $adUnitId : null),
                'reward_item_type' => ($rewardItemType !== '' ? $rewardItemType : null),
                'reward_item_amount' => $rewardItemAmount,
                'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]);
        } catch (Throwable $eventError) {
            error_log('[rewarded.apply] rewarded_ad_events yazılamadı: ' . $eventError->getMessage());
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $summary = usage_limits_get_summary($pdo, $userId, $qualificationId);
    $isPro = (bool)($summary['is_pro'] ?? false);
    $rewarded = rewarded_build_summary($pdo, $userId, $isPro, $runtime);
    $summary['rewarded'] = $rewarded;

    api_success('Rewarded hak uygulandı.', [
        'success' => true,
        'added' => $bonus,
        'type' => $type,
        'rewarded' => $rewarded,
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

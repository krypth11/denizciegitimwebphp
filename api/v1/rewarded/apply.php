<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'rewarded.apply');

    if ($userId === '') {
        api_error('Geçersiz kullanıcı.', 401);
    }

    if (usage_limits_is_user_pro($pdo, $userId)) {
        usage_limits_business_error(
            'PREMIUM_USER_NOT_ELIGIBLE',
            'Premium kullanıcılar rewarded hak akışını kullanamaz.',
            422,
            usage_limits_get_summary($pdo, $userId, $qualificationId)
        );
    }

    $payload = api_get_request_data();
    $type = strtolower(trim((string)($payload['type'] ?? '')));

    if (!in_array($type, ['study', 'exam'], true)) {
        api_error('type alanı study veya exam olmalıdır.', 422);
    }

    $runtime = get_runtime_settings_row();

    if ($type === 'study') {
        $bonus = max(0, (int)($runtime['rewarded_study_bonus'] ?? 10));
        $featureKey = USAGE_LIMIT_FEATURE_STUDY_QUESTION_OPEN;
    } else {
        $bonus = max(0, (int)($runtime['rewarded_mock_exam_bonus'] ?? 1));
        $featureKey = USAGE_LIMIT_FEATURE_MOCK_EXAM_START;
    }

    if ($bonus > 0) {
        $schema = usage_limits_get_daily_counter_schema($pdo);
        $counter = usage_limits_get_or_create_counter($pdo, $userId, $qualificationId, $featureKey, usage_limits_tr_date());

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
            $params[] = usage_limits_tr_date();
            $params[] = $featureKey;
        }

        $sql = 'UPDATE ' . usage_limits_q($schema['table'])
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $where;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    api_success('Rewarded hak uygulandı.', [
        'success' => true,
        'added' => $bonus,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

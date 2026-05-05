<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('GET');

function rewarded_daily_usage_read_counts(PDO $pdo, string $userId, string $today): array
{
    try {
        $cols = get_table_columns($pdo, 'rewarded_ad_daily_usage');
        if (!$cols || !is_array($cols)) {
            return ['study' => 0, 'exam' => 0];
        }

        $userCol = null;
        foreach (['user_id'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $userCol = $candidate;
                break;
            }
        }

        $typeCol = null;
        foreach (['reward_type', 'type'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $typeCol = $candidate;
                break;
            }
        }

        $dateCol = null;
        foreach (['usage_date_tr', 'usage_date', 'date_tr'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $dateCol = $candidate;
                break;
            }
        }

        $countCol = null;
        foreach (['watched_count', 'watch_count'] as $candidate) {
            if (in_array($candidate, $cols, true)) {
                $countCol = $candidate;
                break;
            }
        }

        if (!$userCol || !$typeCol || !$dateCol || !$countCol) {
            return ['study' => 0, 'exam' => 0];
        }

        $sql = 'SELECT `'.$typeCol.'` AS reward_type, `'.$countCol.'` AS watched_count'
            . ' FROM `rewarded_ad_daily_usage`'
            . ' WHERE `'.$userCol.'` = ? AND `'.$dateCol.'` = ?';
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
    } catch (Throwable $e) {
        return ['study' => 0, 'exam' => 0];
    }
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

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'usage.summary');

    $subscription = usage_limits_get_user_subscription_status($pdo, $userId);
    $selfHeal = usage_limits_self_heal_expired_subscription_row($pdo, $userId, $subscription, [
        'source' => 'usage.summary',
    ]);
    if (!empty($selfHeal['applied']) && is_array($selfHeal['after'] ?? null)) {
        $subscription = $selfHeal['after'];
    }
    $subscriptionIsActive = usage_limits_is_subscription_active($subscription);

    $summary = usage_limits_get_summary($pdo, $userId, $qualificationId);
    $runtime = get_runtime_settings_row();
    $computedIsPro = (bool)($summary['is_pro'] ?? false);

    $summary['rewarded'] = rewarded_build_summary($pdo, $userId, $computedIsPro, $runtime);

    usage_limits_subscription_debug_log('usage_summary_computed', [
        'user_id' => $userId,
        'qualification_id' => $qualificationId,
        'subscription_state' => usage_limits_normalize_subscription_row($subscription, $userId),
        'subscription_is_active' => $subscriptionIsActive,
        'summary_is_pro' => $computedIsPro,
        'summary_state' => (string)($summary['state'] ?? ''),
        'study_state' => (string)($summary['study']['state'] ?? ''),
        'mock_exam_state' => (string)($summary['mock_exam']['state'] ?? ''),
    ]);

    if (usage_limits_subscription_debug_enabled()) {
        $summary['debug'] = [
            'subscription_state' => usage_limits_normalize_subscription_row($subscription, $userId),
            'is_pro' => $computedIsPro,
            'is_active' => $subscriptionIsActive,
            'study_state' => (string)($summary['study']['state'] ?? ''),
            'mock_exam_state' => (string)($summary['mock_exam']['state'] ?? ''),
            'qualification_id' => $qualificationId,
            'computed_is_pro' => $computedIsPro,
        ];
    }

    // is_pro ve isPro her ikisi de response'ta olmalı
    $summary['isPro'] = $computedIsPro;
    $summary['is_pro'] = $computedIsPro;

    api_success('Kullanım özeti getirildi.', $summary);
} catch (Throwable $e) {
    usage_limits_log_exception('usage_summary_failed', $e, [
        'endpoint' => 'api/v1/usage/summary.php',
        'user_id' => $userId ?? null,
        'qualification_id' => $qualificationId ?? null,
    ]);

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

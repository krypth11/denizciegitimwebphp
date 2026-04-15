<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'usage.summary');

    $subscription = usage_limits_get_user_subscription_status($pdo, $userId);
    $subscriptionIsActive = usage_limits_is_subscription_active($subscription);

    $summary = usage_limits_get_summary($pdo, $userId, $qualificationId);
    $computedIsPro = (bool)($summary['is_pro'] ?? false);

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

    api_success('Kullanım özeti getirildi.', $summary);
} catch (Throwable $e) {
    usage_limits_log_exception('usage_summary_failed', $e, [
        'endpoint' => 'api/v1/usage/summary.php',
        'user_id' => $userId ?? null,
        'qualification_id' => $qualificationId ?? null,
    ]);

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

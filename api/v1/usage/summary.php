<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'usage.summary');

    $summary = usage_limits_get_summary($pdo, $userId, $qualificationId);

    if (usage_limits_subscription_debug_enabled()) {
        $subscription = usage_limits_get_user_subscription_status($pdo, $userId);
        $summary['debug'] = [
            'subscription_state' => usage_limits_normalize_subscription_row($subscription, $userId),
            'is_pro' => (bool)($summary['is_pro'] ?? false),
            'is_active' => usage_limits_is_subscription_active($subscription),
            'study_state' => (string)($summary['study']['state'] ?? ''),
            'mock_exam_state' => (string)($summary['mock_exam']['state'] ?? ''),
            'qualification_id' => $qualificationId,
        ];
    }

    api_success('Kullanım özeti getirildi.', $summary);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

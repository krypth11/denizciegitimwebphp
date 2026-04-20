<?php

require_once __DIR__ . '/bootstrap.php';

api_require_method('GET');

try {
    pusula_ai_api_require_auth_context($pdo);

    $settings = pusula_ai_api_settings($pdo);
    $featureEnabled = pusula_ai_api_feature_enabled($settings);
    $accessReason = $featureEnabled ? 'allowed' : 'feature_disabled';

    api_success('Pusula Ai konfigürasyonu alındı.', [
        'feature_enabled' => $featureEnabled,
        'access' => [
            'allowed' => $featureEnabled,
            'reason' => $accessReason,
        ],
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
        'premium_only' => pusula_ai_api_requires_premium($settings),
        'internet_required' => pusula_ai_api_requires_internet($settings),
        'moderation_enabled' => ((int)($settings['moderation_enabled'] ?? 0) === 1),
        'daily_limit' => pusula_ai_api_daily_limit($settings),
        'is_active' => $featureEnabled,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

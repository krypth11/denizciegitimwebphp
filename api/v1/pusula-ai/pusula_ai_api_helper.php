<?php

function pusula_ai_api_settings(PDO $pdo): array
{
    if (function_exists('pusula_ai_get_settings')) {
        return pusula_ai_get_settings($pdo);
    }

    $defaults = function_exists('pusula_ai_default_settings')
        ? pusula_ai_default_settings()
        : [];

    if (function_exists('pusula_ai_normalize_settings')) {
        return pusula_ai_normalize_settings($defaults);
    }

    return $defaults;
}

function pusula_ai_api_feature_enabled(array $settings): bool
{
    return ((int)($settings['is_active'] ?? 0) === 1);
}

function pusula_ai_api_requires_premium(array $settings): bool
{
    return ((int)($settings['premium_only'] ?? 0) === 1);
}

function pusula_ai_api_requires_internet(array $settings): bool
{
    return ((int)($settings['internet_required'] ?? 0) === 1);
}

function pusula_ai_api_daily_limit(array $settings): int
{
    $dailyLimit = (int)($settings['daily_limit'] ?? 0);
    return max(0, $dailyLimit);
}

function pusula_ai_api_remaining_limit(PDO $pdo, string $userId, array $settings): int
{
    unset($pdo, $userId);

    // Bu fazda usage log henüz bağlı değil.
    return pusula_ai_api_daily_limit($settings);
}

function pusula_ai_api_quick_actions(): array
{
    return [
        [
            'id' => 'today_plan',
            'label' => 'Bugün ne çalışayım?',
            'prompt' => 'Bugün ne çalışmalıyım?',
            'action_type' => 'chat_prefill',
        ],
        [
            'id' => 'analyze_last_exam',
            'label' => 'Son denememi yorumla',
            'prompt' => 'Son denememi yorumlar mısın?',
            'action_type' => 'chat_prefill',
        ],
        [
            'id' => 'weak_topics',
            'label' => 'En zayıf konum',
            'prompt' => 'En zayıf olduğum konuları göster.',
            'action_type' => 'chat_prefill',
        ],
        [
            'id' => 'one_week_left',
            'label' => '1 hafta kaldı',
            'prompt' => 'Sınava 1 hafta kaldı, bana plan yap.',
            'action_type' => 'chat_prefill',
        ],
        [
            'id' => 'motivate_me',
            'label' => 'Beni motive et',
            'prompt' => 'Beni motive et ve kısa bir çalışma yönü ver.',
            'action_type' => 'chat_prefill',
        ],
    ];
}

function pusula_ai_api_welcome_message(array $settings): string
{
    if (!pusula_ai_api_feature_enabled($settings)) {
        return 'Pusula Ai şu anda aktif değil.';
    }

    return 'Merhaba! Pusula Ai ile çalışmanı birlikte planlayabiliriz.';
}

function pusula_ai_api_build_session_payload(PDO $pdo, string $userId, array $settings, bool $isPremium): array
{
    $featureEnabled = pusula_ai_api_feature_enabled($settings);
    $requiresPremium = pusula_ai_api_requires_premium($settings);
    $requiresInternet = pusula_ai_api_requires_internet($settings);

    $accessAllowed = $featureEnabled;
    if ($accessAllowed && $requiresPremium && !$isPremium) {
        $accessAllowed = false;
    }

    $requiresPremiumForUser = false;
    if ($featureEnabled && $requiresPremium && !$isPremium) {
        $requiresPremiumForUser = true;
    }

    return [
        'feature_enabled' => $featureEnabled,
        'access_allowed' => $accessAllowed,
        'requires_premium' => $requiresPremiumForUser,
        'requires_internet' => $requiresInternet,
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
        'daily_limit' => pusula_ai_api_daily_limit($settings),
        'remaining_limit' => pusula_ai_api_remaining_limit($pdo, $userId, $settings),
        'welcome_message' => pusula_ai_api_welcome_message($settings),
        'quick_actions' => pusula_ai_api_quick_actions(),
    ];
}



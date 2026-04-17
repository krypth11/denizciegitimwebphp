<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

require_once dirname(__DIR__, 3) . '/includes/pusula_ai_config.php';
require_once dirname(__DIR__, 3) . '/includes/pusula_ai_settings_helper.php';
require_once dirname(__DIR__, 3) . '/includes/pusula_ai_provider_factory.php';

require_once __DIR__ . '/pusula_ai_api_helper.php';

function pusula_ai_api_require_auth_context(PDO $pdo): array
{
    $auth = api_require_auth($pdo);
    $userId = trim((string)($auth['user']['id'] ?? ''));

    if ($userId === '') {
        api_error('Yetkisiz erişim.', 401);
    }

    return [
        'auth' => $auth,
        'user_id' => $userId,
    ];
}

function pusula_ai_api_is_user_premium(PDO $pdo, string $userId): bool
{
    if (!function_exists('usage_limits_is_user_pro')) {
        return false;
    }

    return usage_limits_is_user_pro($pdo, $userId);
}

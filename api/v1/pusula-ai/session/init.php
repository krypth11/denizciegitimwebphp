<?php

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');

try {
    $authContext = pusula_ai_api_require_auth_context($pdo);
    $userId = (string)$authContext['user_id'];

    $settings = pusula_ai_api_settings($pdo);
    $isPremium = pusula_ai_api_is_user_premium($pdo, $userId);

    $payload = pusula_ai_api_build_session_payload($pdo, $userId, $settings, $isPremium);
    $message = !empty($payload['feature_enabled'])
        ? 'Pusula Ai oturumu başlatıldı.'
        : 'Pusula Ai şu anda aktif değil.';

    api_success($message, $payload);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

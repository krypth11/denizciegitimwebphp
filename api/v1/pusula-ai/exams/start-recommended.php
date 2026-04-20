<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pusula_ai_exam_helper.php';

api_require_method('POST');

function pusula_ai_exam_api_error(string $errorCode, string $message, int $status = 400): void
{
    api_send_json([
        'success' => false,
        'message' => $message,
        'data' => [
            'error_code' => $errorCode,
        ],
    ], $status);
}

try {
    $authContext = pusula_ai_api_require_auth_context($pdo);
    $auth = is_array($authContext['auth'] ?? null) ? $authContext['auth'] : [];
    $userId = (string)($authContext['user_id'] ?? '');

    api_require_current_user_qualification_id($pdo, $auth, 'pusula-ai.exams.start-recommended');

    $settings = pusula_ai_api_settings($pdo);
    if (!pusula_ai_api_feature_enabled($settings)) {
        pusula_ai_api_send_feature_disabled($settings, 403);
    }

    $isPremium = pusula_ai_api_is_user_premium($pdo, $userId);
    if (pusula_ai_api_requires_premium($settings) && !$isPremium) {
        pusula_ai_exam_api_error('premium_required', 'Bu özellik için premium üyelik gerekli.', 403);
    }

    $payload = api_get_request_data();
    $payload = is_array($payload) ? $payload : [];

    $validation = pusula_ai_validate_exam_payload($payload);
    if (empty($validation['valid'])) {
        $errorCode = (string)($validation['error_code'] ?? 'invalid_payload');
        $status = ($errorCode === 'unsupported_action_type') ? 422 : 422;
        pusula_ai_exam_api_error($errorCode, (string)($validation['message'] ?? 'İstek verisi geçersiz.'), $status);
    }

    $startResult = pusula_ai_start_recommended_exam($pdo, $userId, $payload);
    if (empty($startResult['success'])) {
        $errorCode = (string)($startResult['error_code'] ?? 'cannot_start_exam');
        $status = 422;
        if (in_array($errorCode, ['feature_disabled', 'premium_required'], true)) {
            $status = 403;
        } elseif ($errorCode === 'daily_limit_reached') {
            $status = 429;
        }
        pusula_ai_exam_api_error($errorCode, (string)($startResult['message'] ?? 'Deneme başlatılamadı.'), $status);
    }

    api_success('Önerilen deneme başlatıldı.', [
        'attempt_id' => (string)($startResult['attempt_id'] ?? ''),
        'exam_mode' => (string)($startResult['exam_mode'] ?? 'mixed_review'),
        'question_count' => (int)($startResult['question_count'] ?? 0),
        'title' => (string)($startResult['title'] ?? 'Önerilen Deneme'),
        'started_at' => (string)($startResult['started_at'] ?? date('c')),
        'navigation_target' => 'exam_session',
        'message' => (string)($startResult['message'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('[pusula_ai_exam][fatal] ' . $e->getMessage());
    pusula_ai_exam_api_error('cannot_start_exam', 'İşlem sırasında bir sunucu hatası oluştu.', 500);
}

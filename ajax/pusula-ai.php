<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/pusula_ai_config.php';
require_once '../includes/pusula_ai_settings_helper.php';
require_once '../includes/pusula_ai_provider_factory.php';

require_admin();

function pusula_ai_json_response(bool $success, string $message = '', array $data = [], int $status = 200, array $errors = []): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function pusula_ai_request_payload(): array
{
    return [
        'provider' => $_POST['provider'] ?? '',
        'model' => $_POST['model'] ?? '',
        'api_key' => $_POST['api_key'] ?? '',
        'base_url' => $_POST['base_url'] ?? '',
        'timeout_seconds' => $_POST['timeout_seconds'] ?? '',
        'temperature' => $_POST['temperature'] ?? '',
        'max_tokens' => $_POST['max_tokens'] ?? '',
        'premium_only' => $_POST['premium_only'] ?? 0,
        'internet_required' => $_POST['internet_required'] ?? 0,
        'moderation_enabled' => $_POST['moderation_enabled'] ?? 0,
        'daily_limit' => $_POST['daily_limit'] ?? '',
        'is_active' => $_POST['is_active'] ?? 0,
    ];
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    if ($action === 'get_settings') {
        $settings = pusula_ai_get_settings($pdo);
        $settings['api_key'] = pusula_ai_mask_api_key((string)($settings['api_key'] ?? ''));

        pusula_ai_json_response(true, '', [
            'settings' => $settings,
            'provider_models' => pusula_ai_provider_models(),
        ]);
    }

    if ($action === 'save_settings') {
        $payload = pusula_ai_request_payload();

        try {
            pusula_ai_save_settings($pdo, $payload);
        } catch (InvalidArgumentException $e) {
            $rawErrors = json_decode((string)$e->getMessage(), true);
            $errors = is_array($rawErrors) ? $rawErrors : ['general' => 'Geçersiz ayar verisi.'];
            pusula_ai_json_response(false, 'Lütfen form alanlarını kontrol edin.', [], 422, $errors);
        }

        $savedSettings = pusula_ai_get_settings($pdo);
        $savedSettings['api_key'] = pusula_ai_mask_api_key((string)($savedSettings['api_key'] ?? ''));

        pusula_ai_json_response(true, 'Pusula Ai ayarları kaydedildi.', [
            'settings' => $savedSettings,
        ]);
    }

    if ($action === 'test_connection') {
        $payload = pusula_ai_request_payload();
        $current = pusula_ai_get_settings($pdo);

        $rawProvider = strtolower(trim((string)($payload['provider'] ?? '')));
        $rawModel = trim((string)($payload['model'] ?? ''));
        if ($rawProvider !== '' && $rawModel !== '' && !pusula_ai_is_valid_provider_model_pair($rawProvider, $rawModel)) {
            pusula_ai_json_response(false, 'Bağlantı testi için model sağlayıcı ile uyumlu değil.', [
                'success' => false,
                'message' => 'Seçilen model sağlayıcı ile uyumlu değil.',
                'provider' => $rawProvider,
                'model' => $rawModel,
            ], 422, ['model' => 'provider_mismatch']);
        }

        $normalized = pusula_ai_normalize_settings($payload);

        $incomingApiKey = trim((string)($payload['api_key'] ?? ''));
        if ($incomingApiKey === '' || pusula_ai_is_masked_api_key($incomingApiKey)) {
            $normalized['api_key'] = (string)($current['api_key'] ?? '');
        }

        $errors = pusula_ai_validate_settings($normalized);
        if (!empty($errors)) {
            pusula_ai_json_response(false, 'Bağlantı testi için ayarlar geçerli değil.', [
                'success' => false,
                'message' => 'Bağlantı testi için ayarlar geçerli değil.',
                'provider' => (string)$normalized['provider'],
                'model' => (string)$normalized['model'],
            ], 422, $errors);
        }

        if (trim((string)($normalized['api_key'] ?? '')) === '') {
            pusula_ai_json_response(false, 'Bağlantı testi için API key zorunludur.', [
                'success' => false,
                'message' => 'Bağlantı testi için API key zorunludur.',
                'provider' => (string)$normalized['provider'],
                'model' => (string)$normalized['model'],
            ], 422, ['api_key' => 'required']);
        }

        $client = pusula_ai_make_client($normalized);
        $result = pusula_ai_build_test_connection_result($client->testConnection(), $normalized);

        if (!empty($result['success'])) {
            pusula_ai_json_response(true, $result['message'] !== '' ? $result['message'] : 'Bağlantı başarılı.', $result);
        }

        $safeMessage = $result['message'] !== '' ? $result['message'] : 'Bağlantı testi başarısız oldu.';
        pusula_ai_json_response(false, $safeMessage, $result, 422, ['connection' => 'failed']);
    }

    pusula_ai_json_response(false, 'Geçersiz işlem.', [], 400, ['action' => 'invalid']);
} catch (Throwable $e) {
    pusula_ai_json_response(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500, ['server' => 'error']);
}

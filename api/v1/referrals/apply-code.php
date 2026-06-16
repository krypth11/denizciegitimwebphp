<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/referral_helper.php';

api_require_method('POST');
try {
    $auth = api_require_auth($pdo);
    $payload = api_get_request_data();
    $result = referral_apply_code_to_user($pdo, (string)$auth['user']['id'], (string)($payload['referral_code'] ?? ''), isset($payload['device_hash']) ? (string)$payload['device_hash'] : null, $_SERVER['REMOTE_ADDR'] ?? null);
    api_success('Referans kodu uygulandı.', ['link' => $result]);
} catch (InvalidArgumentException $e) {
    $code = (int)$e->getCode();
    api_error($e->getMessage(), ($code >= 400 && $code < 500) ? $code : 422);
} catch (Throwable $e) {
    api_error('Referans kodu uygulanırken hata oluştu.', 500);
}

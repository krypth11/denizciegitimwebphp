<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/referral_helper.php';

api_require_method('POST');
try {
    $auth = api_require_auth($pdo);
    $payload = api_get_request_data();
    $code = referral_normalize_code($payload['referral_code'] ?? $payload['code'] ?? '');
    if ($code === '') api_error('referral_code zorunludur.', 422);
    if (referral_find_promo_code($pdo, $code)) api_error('Hediye kodları için hediye kodu alanını kullanın.', 422);
    $link = referral_apply_code_to_user($pdo, (string)$auth['user']['id'], $code, isset($payload['device_hash']) ? (string)$payload['device_hash'] : null, $_SERVER['REMOTE_ADDR'] ?? null);
    api_send_json(['success'=>true,'type'=>'referral','message'=>'Referans kodu uygulandı.','data'=>$link]);
} catch (InvalidArgumentException $e) {
    $code = (int)$e->getCode();
    api_error($e->getMessage(), ($code >= 400 && $code < 500) ? $code : 422);
} catch (Throwable $e) {
    api_error('Kod uygulanırken hata oluştu.', 500);
}

<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/referral_helper.php';

api_require_method('POST');
$payload = api_get_request_data();
$code = referral_normalize_code($payload['code'] ?? $payload['referral_code'] ?? '');
if ($code === '') api_error('code zorunludur.', 422);

try {
    $check = referral_check_any_code($pdo, $code, null);
    if (($check['type'] ?? null) === 'referral' && !empty($check['referrer_user_id'])) {
        $check['referrer'] = ['masked_name' => referral_mask_name($pdo, (string)$check['referrer_user_id'])];
    }
    api_success('Kod kontrol edildi.', $check);
} catch (Throwable $e) {
    api_error('Kod kontrol edilemedi.', 500);
}

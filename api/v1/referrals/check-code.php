<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/referral_helper.php';

api_require_method('POST');
$payload = api_get_request_data();
$code = strtoupper(trim((string)($payload['referral_code'] ?? '')));
if ($code === '') api_error('referral_code zorunludur.', 422);

try {
    $stmt = $pdo->prepare("SELECT user_id FROM user_referral_codes WHERE code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$code]);
    $referrerId = $stmt->fetchColumn();
    api_success('Kod kontrol edildi.', [
        'valid' => (bool)$referrerId,
        'referral_code' => $code,
        'referrer' => $referrerId ? ['masked_name' => referral_mask_name($pdo, (string)$referrerId)] : null,
        'message' => $referrerId ? 'Geçerli referans kodu.' : 'Geçersiz referans kodu.',
    ]);
} catch (Throwable $e) {
    api_error('Referans kodu kontrol edilemedi.', 500);
}

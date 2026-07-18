<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

// Güvenli varsayılan: sağlayıcı SSV imzası, transaction kimliği ve kullanıcıya
// bağlı tek kullanımlık nonce yapılandırılmadan hiçbir ödül verilmez.
api_require_auth($pdo);
api_send_json([
    'success' => false,
    'error_code' => 'REWARDED_VERIFICATION_UNAVAILABLE',
    'message' => 'Ödüllü reklam özelliği şu anda kullanılamıyor.',
    'data' => ['reward_granted' => false],
], 503);

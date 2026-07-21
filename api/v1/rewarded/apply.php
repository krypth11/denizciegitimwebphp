<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/admin_notification_helper.php';

api_require_method('POST');

// Güvenli varsayılan: sağlayıcı SSV imzası, transaction kimliği ve kullanıcıya
// bağlı tek kullanımlık nonce yapılandırılmadan hiçbir ödül verilmez.
$rewardAuth = api_require_auth($pdo);
$rewardUserId = (string)($rewardAuth['user']['id'] ?? 'unknown');
admin_notification_create($pdo, ['event_type'=>'rewarded_verification_unavailable','source_type'=>'rewarded_ad','source_id'=>date('Y-m-d-H').':'.$rewardUserId,'title'=>'Reklam ödülü doğrulaması kullanılamıyor','message'=>'Bir kullanıcı ödüllü reklam hakkı istemiş ancak sağlayıcı doğrulaması yapılandırılmamış.','severity'=>'high','target_url'=>'/pages/rewarded-ad-stats.php']);
api_send_json([
    'success' => false,
    'error_code' => 'REWARDED_VERIFICATION_UNAVAILABLE',
    'message' => 'Ödüllü reklam özelliği şu anda kullanılamıyor.',
    'data' => ['reward_granted' => false],
], 503);

<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/referral_helper.php';

header('Content-Type: application/json; charset=utf-8');
$limit = isset($argv[1]) ? max(1, min(1000, (int)$argv[1])) : 200;
try {
    $result = referral_process_pending_rewards($pdo, $limit);
    echo json_encode(['success' => true, 'message' => 'Referral pending rewards processed.', 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

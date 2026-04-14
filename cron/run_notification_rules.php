<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Bu script sadece CLI üzerinden çalıştırılabilir.\n";
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notification_helper.php';

date_default_timezone_set('Europe/Istanbul');

$args = $argv ?? [];
$force = in_array('--force', $args, true);
$windowMinutes = 5;

foreach ($args as $arg) {
    if (str_starts_with((string)$arg, '--window=')) {
        $windowMinutes = max(1, (int)substr((string)$arg, 9));
    }
}

$startedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

echo "=== Notification Rule Runner ===\n";
echo 'Start: ' . $startedAt->format('Y-m-d H:i:s') . " (Europe/Istanbul)\n";
echo 'Mode: ' . ($force ? 'FORCE' : 'NORMAL') . "\n";
echo 'Window: ' . $windowMinutes . " dakika\n\n";

try {
    $stats = notification_run_rules_engine($pdo, [
        'force' => $force,
        'window_minutes' => $windowMinutes,
    ]);

    $endedAt = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));

    echo 'İşlenen kural: ' . (int)($stats['processed_rules'] ?? 0) . "\n";
    echo 'Tetiklenen kural: ' . (int)($stats['due_rules'] ?? 0) . "\n";
    echo 'Aday kullanıcı: ' . (int)($stats['candidate_users'] ?? 0) . "\n";
    echo 'Oluşturulan bildirim: ' . (int)($stats['created_notifications'] ?? 0) . "\n";
    echo 'Gönderilen: ' . (int)($stats['sent'] ?? 0) . "\n";
    echo 'Skip: ' . (int)($stats['skipped'] ?? 0) . "\n";
    echo 'Failed: ' . (int)($stats['failed'] ?? 0) . "\n";

    if (!empty($stats['details']) && is_array($stats['details'])) {
        echo "\nDetaylı hatalar:\n";
        foreach ($stats['details'] as $i => $detail) {
            $ruleKey = (string)($detail['rule_key'] ?? '-');
            $userId = (string)($detail['user_id'] ?? '-');
            $error = (string)($detail['error'] ?? 'Bilinmeyen hata');
            echo sprintf("%d) [%s] user=%s => %s\n", $i + 1, $ruleKey, $userId, $error);
        }
    }

    echo "\nFinish: " . $endedAt->format('Y-m-d H:i:s') . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Runner error: ' . $e->getMessage() . "\n");
    error_log('[cron.run_notification_rules] ' . $e->getMessage());
    exit(1);
}

<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Bu script sadece CLI üzerinden çalıştırılabilir.\n";
    exit;
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/news_helper.php';

date_default_timezone_set('Europe/Istanbul');

echo "=== News Fetch Cron ===\n";
echo 'Start: ' . date('Y-m-d H:i:s') . "\n";

try {
    $summary = news_fetch_all_active_sources($pdo);

    echo 'Aktif kaynak sayısı: ' . (int)($summary['sources_total'] ?? 0) . "\n";
    echo 'Başarılı kaynak: ' . (int)($summary['sources_success'] ?? 0) . "\n";
    echo 'Hatalı kaynak: ' . (int)($summary['sources_failed'] ?? 0) . "\n";
    echo 'Toplam inserted(pending): ' . (int)($summary['inserted'] ?? 0) . "\n";
    echo 'Toplam duplicate skip: ' . (int)($summary['skipped_duplicates'] ?? 0) . "\n";

    $errors = $summary['errors'] ?? [];
    if (is_array($errors) && !empty($errors)) {
        echo "\nKaynak hataları:\n";
        foreach ($errors as $err) {
            $sourceName = (string)($err['source_name'] ?? 'Bilinmeyen kaynak');
            $message = (string)($err['message'] ?? 'Kaynak işlenirken bir hata oluştu.');
            echo '- ' . $sourceName . ': ' . $message . "\n";
        }
    }

    echo 'Finish: ' . date('Y-m-d H:i:s') . "\n";
    exit(0);
} catch (Throwable $e) {
    error_log('[cron.fetch_news] fatal=' . $e->getMessage());
    fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
    exit(1);
}

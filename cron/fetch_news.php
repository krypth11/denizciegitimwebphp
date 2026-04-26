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
    $sources = news_list_sources($pdo);
    $activeSources = array_values(array_filter($sources, static function (array $source): bool {
        return ((int)($source['is_active'] ?? 0) === 1);
    }));

    echo 'Aktif kaynak sayısı: ' . count($activeSources) . "\n";

    $totalFetched = 0;
    $totalInserted = 0;
    $totalErrors = 0;

    foreach ($activeSources as $source) {
        $sourceName = (string)($source['name'] ?? '');
        $sourceId = (string)($source['id'] ?? '');

        try {
            $result = news_fetch_rss_source($pdo, $source);
            $fetched = (int)($result['fetched'] ?? 0);
            $inserted = (int)($result['inserted'] ?? 0);
            $ok = !empty($result['success']);

            $totalFetched += $fetched;
            $totalInserted += $inserted;

            if (!$ok) {
                $totalErrors++;
                $msg = (string)($result['error'] ?? 'Bilinmeyen hata');
                error_log('[cron.fetch_news] source_id=' . $sourceId . ' error=' . $msg);
                echo '[HATA] ' . $sourceName . ' => ' . $msg . "\n";
                continue;
            }

            echo '[OK] ' . $sourceName . ' | fetched=' . $fetched . ' | inserted=' . $inserted . "\n";
        } catch (Throwable $e) {
            $totalErrors++;
            error_log('[cron.fetch_news] source_id=' . $sourceId . ' exception=' . $e->getMessage());
            echo '[EXCEPTION] ' . $sourceName . ' => ' . $e->getMessage() . "\n";
            continue;
        }
    }

    echo "\nToplam fetched: {$totalFetched}\n";
    echo "Toplam inserted(pending): {$totalInserted}\n";
    echo "Toplam error: {$totalErrors}\n";
    echo 'Finish: ' . date('Y-m-d H:i:s') . "\n";
    exit(0);
} catch (Throwable $e) {
    error_log('[cron.fetch_news] fatal=' . $e->getMessage());
    fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
    exit(1);
}

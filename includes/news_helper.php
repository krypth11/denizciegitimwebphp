<?php

require_once __DIR__ . '/functions.php';

function news_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function news_category_labels(): array
{
    return [
        'general' => 'Genel',
        'world' => 'Dünya Denizcilik',
        'turkey' => 'Türkiye / Limanlar',
        'accidents' => 'Kaza / Olay',
        'education' => 'Eğitim / Sertifika',
        'technology' => 'Teknoloji',
        'trade' => 'Ticaret / Navlun',
    ];
}

function news_normalize_category(string $category): string
{
    $value = strtolower(trim($category));
    $labels = news_category_labels();
    return isset($labels[$value]) ? $value : 'general';
}

function news_normalize_region(string $region): string
{
    $value = strtolower(trim($region));
    return in_array($value, ['local', 'global'], true) ? $value : '';
}

function news_clean_text(?string $value, int $maxLen = 5000): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('#<script\b[^>]*>(.*?)</script>#is', ' ', $text) ?? $text;
    $text = preg_replace('#<style\b[^>]*>(.*?)</style>#is', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);

    if ($maxLen > 0 && mb_strlen($text) > $maxLen) {
        $text = mb_substr($text, 0, $maxLen);
    }

    return trim($text);
}

function news_normalize_for_hash(string $value): string
{
    $value = news_clean_text($value, 1000);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function news_source_hash(string $sourceUrl, string $title, string $sourceName = ''): string
{
    $normalizedTitle = news_normalize_for_hash($title);
    $normalizedUrl = news_normalize_for_hash($sourceUrl);
    $normalizedSourceName = news_normalize_for_hash($sourceName);

    $base = $normalizedUrl !== ''
        ? ($normalizedUrl . '|' . $normalizedTitle)
        : ($normalizedTitle . '|' . $normalizedSourceName);

    return hash('sha256', $base);
}

function news_validate_feed_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Geçerli bir RSS URL giriniz.');
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('RSS URL yalnızca http/https olmalıdır.');
    }

    return $url;
}

function news_source_row_with_labels(array $row): array
{
    $labels = news_category_labels();
    $cat = news_normalize_category((string)($row['category'] ?? 'general'));
    $feedUrl = trim((string)($row['feed_url'] ?? $row['rss_url'] ?? ''));
    $row['feed_url'] = $feedUrl;
    $row['rss_url'] = $feedUrl;
    $row['category'] = $cat;
    $row['category_label'] = $labels[$cat] ?? $labels['general'];
    $row['is_active'] = ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0;
    return $row;
}

function news_article_row_with_labels(array $row): array
{
    $labels = news_category_labels();
    $cat = news_normalize_category((string)($row['category'] ?? 'general'));
    $language = strtolower(trim((string)($row['language'] ?? 'tr')));
    if (!in_array($language, ['tr', 'en'], true)) {
        $language = 'tr';
    }
    $row['category'] = $cat;
    $row['category_label'] = $labels[$cat] ?? $labels['general'];
    $row['language'] = $language;
    $row['is_active'] = ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0;
    return $row;
}

function news_list_sources(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM news_sources ORDER BY is_active DESC, name ASC, created_at DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map('news_source_row_with_labels', $rows);
}

function news_get_source(PDO $pdo, string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM news_sources WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? news_source_row_with_labels($row) : null;
}

function news_create_source(PDO $pdo, array $input): array
{
    $name = news_clean_text((string)($input['name'] ?? ''), 191);
    $feedUrlInput = array_key_exists('rss_url', $input) ? (string)$input['rss_url'] : (string)($input['feed_url'] ?? '');
    $feedUrl = news_validate_feed_url($feedUrlInput);
    $category = news_normalize_category((string)($input['category'] ?? 'general'));
    $language = news_clean_text((string)($input['language'] ?? 'tr'), 16);
    $language = $language !== '' ? strtolower($language) : 'tr';
    $isActive = ((int)($input['is_active'] ?? 1) === 1) ? 1 : 0;

    if ($name === '') {
        throw new InvalidArgumentException('Kaynak adı zorunludur.');
    }

    $dup = $pdo->prepare('SELECT id FROM news_sources WHERE feed_url = ? LIMIT 1');
    $dup->execute([$feedUrl]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Bu RSS URL zaten kayıtlı.');
    }

    $id = news_uuid();
    $stmt = $pdo->prepare('INSERT INTO news_sources (id, name, feed_url, category, language, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $name, $feedUrl, $category, $language, $isActive]);

    return (array)news_get_source($pdo, $id);
}

function news_update_source(PDO $pdo, string $id, array $input): array
{
    $source = news_get_source($pdo, $id);
    if (!$source) {
        throw new RuntimeException('Kaynak bulunamadı.');
    }

    $name = array_key_exists('name', $input) ? news_clean_text((string)$input['name'], 191) : (string)$source['name'];
    $currentFeedUrl = (string)($source['feed_url'] ?? $source['rss_url'] ?? '');
    $nextFeedUrlRaw = array_key_exists('rss_url', $input)
        ? (string)$input['rss_url']
        : (array_key_exists('feed_url', $input) ? (string)$input['feed_url'] : $currentFeedUrl);
    $feedUrl = news_validate_feed_url($nextFeedUrlRaw);
    $category = array_key_exists('category', $input) ? news_normalize_category((string)$input['category']) : (string)$source['category'];
    $language = array_key_exists('language', $input) ? news_clean_text((string)$input['language'], 16) : (string)$source['language'];
    $language = $language !== '' ? strtolower($language) : 'tr';
    $isActive = array_key_exists('is_active', $input) ? ((((int)$input['is_active']) === 1) ? 1 : 0) : (int)$source['is_active'];

    if ($name === '') {
        throw new InvalidArgumentException('Kaynak adı zorunludur.');
    }

    $dup = $pdo->prepare('SELECT id FROM news_sources WHERE feed_url = ? AND id <> ? LIMIT 1');
    $dup->execute([$feedUrl, $id]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Bu RSS URL başka bir kaynakta kullanılıyor.');
    }

    $stmt = $pdo->prepare('UPDATE news_sources
        SET name = ?, feed_url = ?, category = ?, language = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?');
    $stmt->execute([$name, $feedUrl, $category, $language, $isActive, $id]);

    return (array)news_get_source($pdo, $id);
}

function news_delete_source(PDO $pdo, string $id): void
{
    $stmt = $pdo->prepare('DELETE FROM news_sources WHERE id = ?');
    $stmt->execute([trim($id)]);
}

function news_parse_datetime(?string $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function news_is_http_image_url(?string $url): string
{
    $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        return '';
    }

    $lower = strtolower($url);
    if (strpos($lower, 'http://') !== 0 && strpos($lower, 'https://') !== 0) {
        return '';
    }

    return $url;
}

function news_extract_first_image_url(?string $html): string
{
    $html = trim((string)$html);
    if ($html === '') {
        return '';
    }

    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (preg_match('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/isu', $html, $matches)) {
        return news_is_http_image_url($matches[2] ?? '');
    }

    if (preg_match('/<img\b[^>]*\bsrc\s*=\s*([^\s>]+)/isu', $html, $matches)) {
        return news_is_http_image_url($matches[1] ?? '');
    }

    return '';
}

function news_extract_rss_items(string $feedXml): array
{
    $feedXml = trim($feedXml);
    if ($feedXml === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($feedXml, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) {
        return [];
    }

    $items = [];

    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $node) {
            $itemImage = news_is_http_image_url((string)($node->image ?? ''));

            $mediaContentUrl = '';
            $mediaThumbnailUrl = '';
            $media = $node->children('http://search.yahoo.com/mrss/');
            if (isset($media->content)) {
                $attr = $media->content->attributes();
                $mediaContentUrl = news_is_http_image_url((string)($attr['url'] ?? ''));
            }
            if (isset($media->thumbnail)) {
                $attr = $media->thumbnail->attributes();
                $mediaThumbnailUrl = news_is_http_image_url((string)($attr['url'] ?? ''));
            }

            $enclosureUrl = '';
            if (isset($node->enclosure)) {
                $attr = $node->enclosure->attributes();
                $enclosureUrl = news_is_http_image_url((string)($attr['url'] ?? ''));
            }

            $descriptionRaw = (string)($node->description ?? '');
            $descriptionImg = news_extract_first_image_url($descriptionRaw);

            $contentEncodedRaw = '';
            $content = $node->children('http://purl.org/rss/1.0/modules/content/');
            if (isset($content->encoded)) {
                $contentEncodedRaw = (string)$content->encoded;
            }
            $contentImg = news_extract_first_image_url($contentEncodedRaw);

            $imageUrl = '';
            foreach ([$itemImage, $mediaContentUrl, $mediaThumbnailUrl, $enclosureUrl, $descriptionImg, $contentImg] as $candidate) {
                if ($candidate !== '') {
                    $imageUrl = $candidate;
                    break;
                }
            }

            $link = (string)($node->link ?? '');
            $title = news_clean_text((string)($node->title ?? ''), 500);
            if ($title === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'link' => trim($link),
                'summary' => news_clean_text($descriptionRaw, 1200),
                'published_at' => news_parse_datetime((string)($node->pubDate ?? '')),
                'image_url' => $imageUrl,
                'category' => news_normalize_category((string)($node->category ?? 'general')),
            ];
        }
    }

    if (isset($xml->entry)) {
        foreach ($xml->entry as $node) {
            $link = '';
            if (isset($node->link)) {
                foreach ($node->link as $ln) {
                    $attr = $ln->attributes();
                    $href = trim((string)($attr['href'] ?? ''));
                    $rel = strtolower(trim((string)($attr['rel'] ?? 'alternate')));
                    if ($href !== '' && ($rel === 'alternate' || $link === '')) {
                        $link = $href;
                    }
                }
            }

            $mediaContentUrl = '';
            $mediaThumbnailUrl = '';
            $media = $node->children('http://search.yahoo.com/mrss/');
            if (isset($media->content)) {
                $attr = $media->content->attributes();
                $mediaContentUrl = news_is_http_image_url((string)($attr['url'] ?? ''));
            }
            if (isset($media->thumbnail)) {
                $attr = $media->thumbnail->attributes();
                $mediaThumbnailUrl = news_is_http_image_url((string)($attr['url'] ?? ''));
            }

            $summaryRaw = (string)($node->summary ?? '');
            $contentRaw = (string)($node->content ?? '');
            $summaryImg = news_extract_first_image_url($summaryRaw);
            $contentImg = news_extract_first_image_url($contentRaw);

            $imageUrl = '';
            foreach ([$mediaContentUrl, $mediaThumbnailUrl, $summaryImg, $contentImg] as $candidate) {
                if ($candidate !== '') {
                    $imageUrl = $candidate;
                    break;
                }
            }

            $title = news_clean_text((string)($node->title ?? ''), 500);
            if ($title === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'link' => $link,
                'summary' => news_clean_text(($summaryRaw !== '' ? $summaryRaw : $contentRaw), 1200),
                'published_at' => news_parse_datetime((string)($node->updated ?? $node->published ?? '')),
                'image_url' => $imageUrl,
                'category' => news_normalize_category((string)($node->category ?? 'general')),
            ];
        }
    }

    return $items;
}

function news_upsert_pending_article(PDO $pdo, array $source, array $item): array
{
    $title = news_clean_text((string)($item['title'] ?? ''), 500);
    $link = trim((string)($item['link'] ?? ''));
    $sourceName = news_clean_text((string)($source['name'] ?? ''), 191);

    if ($title === '') {
        return [
            'inserted' => false,
            'skipped_duplicate' => false,
            'skipped_no_image' => false,
        ];
    }

    $imageUrl = trim((string)($item['image_url'] ?? ''));
    if ($imageUrl === '') {
        return [
            'inserted' => false,
            'skipped_duplicate' => false,
            'skipped_no_image' => true,
        ];
    }

    $hash = news_source_hash($link, $title, $sourceName);
    $exists = $pdo->prepare('SELECT id FROM news_articles WHERE source_hash = ? LIMIT 1');
    $exists->execute([$hash]);
    if ($exists->fetchColumn()) {
        return [
            'inserted' => false,
            'skipped_duplicate' => true,
            'skipped_no_image' => false,
        ];
    }

    $summary = news_clean_text((string)($item['summary'] ?? ''), 1200);
    $category = news_normalize_category((string)($item['category'] ?? $source['category'] ?? 'general'));
    $language = strtolower(trim((string)($source['language'] ?? 'tr')));
    if (!in_array($language, ['tr', 'en'], true)) {
        $language = 'tr';
    }
    $publishedAt = news_parse_datetime((string)($item['published_at'] ?? ''));

    $stmt = $pdo->prepare('INSERT INTO news_articles
        (id, source_id, title, summary, image_url, source_name, source_url, source_hash, category, language, published_at, status, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", 0, NOW(), NOW())');
    $stmt->execute([
        news_uuid(),
        (string)$source['id'],
        $title,
        $summary,
        ($imageUrl !== '' ? $imageUrl : null),
        $sourceName,
        $link,
        $hash,
        $category,
        $language,
        $publishedAt,
    ]);

    return [
        'inserted' => true,
        'skipped_duplicate' => false,
        'skipped_no_image' => false,
    ];
}

function news_fetch_rss_source(PDO $pdo, array $source): array
{
    $feedUrl = (string)($source['feed_url'] ?? $source['rss_url'] ?? '');
    $sourceId = (string)($source['id'] ?? '');

    try {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'user_agent' => 'DenizciEgitimNewsBot/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $xml = @file_get_contents($feedUrl, false, $ctx);
        if (!is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('Feed içeriği alınamadı.');
        }

        $items = news_extract_rss_items($xml);
        $inserted = 0;
        $skippedDuplicates = 0;
        $skippedNoImage = 0;
        foreach ($items as $item) {
            try {
                $upsert = news_upsert_pending_article($pdo, $source, $item);
                if (!empty($upsert['inserted'])) {
                    $inserted++;
                }
                if (!empty($upsert['skipped_duplicate'])) {
                    $skippedDuplicates++;
                }
                if (!empty($upsert['skipped_no_image'])) {
                    $skippedNoImage++;
                }
            } catch (Throwable $e) {
                error_log('[news] item upsert failed: source_id=' . $sourceId . ' error=' . $e->getMessage());
            }
        }

        $upd = $pdo->prepare('UPDATE news_sources SET last_fetched_at = NOW(), updated_at = NOW() WHERE id = ?');
        $upd->execute([$sourceId]);

        return [
            'success' => true,
            'fetched' => count($items),
            'inserted' => $inserted,
            'skipped_duplicates' => $skippedDuplicates,
            'skipped_no_image' => $skippedNoImage,
            'source_id' => $sourceId,
        ];
    } catch (Throwable $e) {
        error_log('[news] feed failed: source_id=' . $sourceId . ' feed_url=' . $feedUrl . ' error=' . $e->getMessage());

        return [
            'success' => false,
            'fetched' => 0,
            'inserted' => 0,
            'skipped_duplicates' => 0,
            'skipped_no_image' => 0,
            'source_id' => $sourceId,
            'error' => $e->getMessage(),
        ];
    }
}

function news_fetch_all_active_sources(PDO $pdo): array
{
    $sources = news_list_sources($pdo);
    $activeSources = array_values(array_filter($sources, static function (array $source): bool {
        return ((int)($source['is_active'] ?? 0) === 1);
    }));

    $summary = [
        'sources_total' => count($activeSources),
        'sources_success' => 0,
        'sources_failed' => 0,
        'inserted' => 0,
        'skipped_duplicates' => 0,
        'skipped_no_image' => 0,
        'errors' => [],
    ];

    foreach ($activeSources as $source) {
        try {
            $result = news_fetch_rss_source($pdo, $source);
            $isSuccess = !empty($result['success']);

            if ($isSuccess) {
                $summary['sources_success']++;
            } else {
                $summary['sources_failed']++;
                $safeMessage = 'Kaynak işlenirken bir hata oluştu.';
                $sourceName = (string)($source['name'] ?? 'Bilinmeyen kaynak');
                $rawError = (string)($result['error'] ?? $safeMessage);
                $summary['errors'][] = [
                    'source_id' => (string)($source['id'] ?? ''),
                    'source_name' => $sourceName,
                    'message' => $safeMessage,
                ];
                error_log('[news] source fetch failed: source_id=' . (string)($source['id'] ?? '') . ' source_name=' . $sourceName . ' error=' . $rawError);
            }

            $summary['inserted'] += (int)($result['inserted'] ?? 0);
            $summary['skipped_duplicates'] += (int)($result['skipped_duplicates'] ?? 0);
            $summary['skipped_no_image'] += (int)($result['skipped_no_image'] ?? 0);
        } catch (Throwable $e) {
            $summary['sources_failed']++;
            $summary['errors'][] = [
                'source_id' => (string)($source['id'] ?? ''),
                'source_name' => (string)($source['name'] ?? 'Bilinmeyen kaynak'),
                'message' => 'Kaynak işlenirken bir hata oluştu.',
            ];
            error_log('[news] source fetch exception: source_id=' . (string)($source['id'] ?? '') . ' error=' . $e->getMessage());
        }
    }

    return $summary;
}

function news_list_admin_articles(PDO $pdo, string $status = 'pending'): array
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $status = 'pending';
    }

    $stmt = $pdo->prepare('SELECT a.*,
            COALESCE(NULLIF(LOWER(a.language), ""), NULLIF(LOWER(s.language), ""), "tr") AS language,
            s.name AS source_feed_name,
            s.feed_url AS source_feed_url
        FROM news_articles a
        LEFT JOIN news_sources s ON s.id = a.source_id
        WHERE a.status = ?
        ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.created_at DESC');
    $stmt->execute([$status]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map('news_article_row_with_labels', $rows);
}

function news_update_article_status(PDO $pdo, string $id, string $status): array
{
    $id = trim($id);
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Geçersiz haber durumu.');
    }

    $isActive = ($status === 'approved') ? 1 : 0;
    $approvedAt = ($status === 'approved') ? 'NOW()' : 'NULL';
    $sql = 'UPDATE news_articles
        SET status = ?, is_active = ?, approved_at = ' . $approvedAt . ', updated_at = NOW()
        WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $isActive, $id]);

    $get = $pdo->prepare('SELECT * FROM news_articles WHERE id = ? LIMIT 1');
    $get->execute([$id]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Haber bulunamadı.');
    }

    return news_article_row_with_labels($row);
}

function news_delete_article(PDO $pdo, string $id): void
{
    $stmt = $pdo->prepare('DELETE FROM news_articles WHERE id = ?');
    $stmt->execute([trim($id)]);
}

function news_bulk_delete_articles(PDO $pdo, array $ids): int
{
    $cleanIds = [];
    foreach ($ids as $id) {
        $value = trim((string)$id);
        if ($value !== '') {
            $cleanIds[] = $value;
        }
    }
    $cleanIds = array_values(array_unique($cleanIds));
    if (!$cleanIds) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $stmt = $pdo->prepare('DELETE FROM news_articles WHERE id IN (' . $placeholders . ')');
    $stmt->execute($cleanIds);
    return (int)$stmt->rowCount();
}

function news_update_article(PDO $pdo, string $id, array $input): array
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Haber ID zorunludur.');
    }

    $get = $pdo->prepare('SELECT * FROM news_articles WHERE id = ? LIMIT 1');
    $get->execute([$id]);
    $current = $get->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        throw new RuntimeException('Haber bulunamadı.');
    }

    $title = array_key_exists('title', $input)
        ? news_clean_text((string)$input['title'], 500)
        : (string)($current['title'] ?? '');
    $summary = array_key_exists('summary', $input)
        ? news_clean_text((string)$input['summary'], 1200)
        : (string)($current['summary'] ?? '');
    $sourceName = array_key_exists('source_name', $input)
        ? news_clean_text((string)$input['source_name'], 191)
        : (string)($current['source_name'] ?? '');
    $category = array_key_exists('category', $input)
        ? news_normalize_category((string)$input['category'])
        : (string)($current['category'] ?? 'general');

    $sourceUrl = array_key_exists('source_url', $input)
        ? trim((string)$input['source_url'])
        : (string)($current['source_url'] ?? '');
    if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Geçerli bir kaynak linki giriniz.');
    }
    $sourceScheme = strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME));
    if (!in_array($sourceScheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('Kaynak linki yalnızca http/https olmalıdır.');
    }

    $imageUrl = array_key_exists('image_url', $input)
        ? trim((string)$input['image_url'])
        : (string)($current['image_url'] ?? '');
    if ($imageUrl !== '') {
        if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Geçerli bir görsel URL giriniz.');
        }
        $imageScheme = strtolower((string)parse_url($imageUrl, PHP_URL_SCHEME));
        if (!in_array($imageScheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Görsel URL yalnızca http/https olmalıdır.');
        }
    }

    $publishedAt = array_key_exists('published_at', $input)
        ? news_parse_datetime((string)$input['published_at'])
        : ($current['published_at'] ?? null);

    if ($title === '') {
        throw new InvalidArgumentException('Haber başlığı zorunludur.');
    }

    $sourceHash = news_source_hash($sourceUrl, $title, $sourceName);
    $dup = $pdo->prepare('SELECT id FROM news_articles WHERE source_hash = ? AND id <> ? LIMIT 1');
    $dup->execute([$sourceHash, $id]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Bu haber zaten mevcut (duplicate).');
    }

    $upd = $pdo->prepare('UPDATE news_articles
        SET title = ?, summary = ?, image_url = ?, source_name = ?, source_url = ?, source_hash = ?, category = ?, published_at = ?, updated_at = NOW()
        WHERE id = ?');
    $upd->execute([
        $title,
        $summary,
        ($imageUrl !== '' ? $imageUrl : null),
        $sourceName,
        $sourceUrl,
        $sourceHash,
        $category,
        $publishedAt,
        $id,
    ]);

    $get->execute([$id]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Haber güncelleme sonrası bulunamadı.');
    }

    return news_article_row_with_labels($row);
}

function news_list_mobile_articles(PDO $pdo, array $filters): array
{
    $page = max(1, (int)($filters['page'] ?? 1));
    $limit = (int)($filters['limit'] ?? 20);
    $limit = max(1, min(50, $limit));
    $category = news_normalize_category((string)($filters['category'] ?? ''));
    $region = news_normalize_region((string)($filters['region'] ?? ''));
    $offset = ($page - 1) * $limit;

    $where = ['status = "approved"', 'is_active = 1'];
    $params = [];
    $articleLanguageExpr = 'COALESCE(NULLIF(LOWER(a.language), ""), NULLIF(LOWER(s.language), ""), "tr")';

    if ($region === 'local') {
        $where[] = $articleLanguageExpr . ' = ?';
        $params[] = 'tr';
    } elseif ($region === 'global') {
        $where[] = $articleLanguageExpr . ' = ?';
        $params[] = 'en';
    }

    if (!empty($filters['category'])) {
        $where[] = 'a.category = ?';
        $params[] = $category;
    }

    $sql = 'SELECT a.id, a.title, a.summary, a.source_name, a.source_url, a.image_url, a.category,
            ' . $articleLanguageExpr . ' AS language,
            a.published_at, a.created_at
        FROM news_articles a
        LEFT JOIN news_sources s ON s.id = a.source_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.published_at DESC, a.created_at DESC
        LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $rows = array_map(function (array $row): array {
        $item = news_article_row_with_labels($row);
        return [
            'id' => (string)$item['id'],
            'title' => (string)$item['title'],
            'summary' => (string)($item['summary'] ?? ''),
            'source_name' => (string)($item['source_name'] ?? ''),
            'source_url' => (string)($item['source_url'] ?? ''),
            'image_url' => (string)($item['image_url'] ?? ''),
            'category' => (string)$item['category'],
            'category_label' => (string)$item['category_label'],
            'language' => (string)$item['language'],
            'published_at' => (string)($item['published_at'] ?? ''),
        ];
    }, $rows);

    return [
        'articles' => $rows,
        'page' => $page,
        'limit' => $limit,
        'has_more' => $hasMore,
    ];
}

function news_get_mobile_article(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT a.id, a.title, a.summary, a.source_name, a.source_url, a.image_url, a.category,
            COALESCE(NULLIF(LOWER(a.language), ""), NULLIF(LOWER(s.language), ""), "tr") AS language,
            a.published_at, a.created_at
        FROM news_articles a
        LEFT JOIN news_sources s ON s.id = a.source_id
        WHERE a.id = ? AND a.status = "approved" AND a.is_active = 1
        LIMIT 1');
    $stmt->execute([trim($id)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $item = news_article_row_with_labels($row);
    return [
        'id' => (string)$item['id'],
        'title' => (string)$item['title'],
        'summary' => (string)($item['summary'] ?? ''),
        'source_name' => (string)($item['source_name'] ?? ''),
        'source_url' => (string)($item['source_url'] ?? ''),
        'image_url' => (string)($item['image_url'] ?? ''),
        'category' => (string)$item['category'],
        'category_label' => (string)$item['category_label'],
        'language' => (string)$item['language'],
        'published_at' => (string)($item['published_at'] ?? ''),
    ];
}

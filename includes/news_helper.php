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

function news_source_hash(string $url, string $title): string
{
    return hash('sha256', mb_strtolower(trim($url) . '|' . trim($title), 'UTF-8'));
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
    $row['category'] = $cat;
    $row['category_label'] = $labels[$cat] ?? $labels['general'];
    $row['is_active'] = ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0;
    return $row;
}

function news_article_row_with_labels(array $row): array
{
    $labels = news_category_labels();
    $cat = news_normalize_category((string)($row['category'] ?? 'general'));
    $row['category'] = $cat;
    $row['category_label'] = $labels[$cat] ?? $labels['general'];
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
    $rssUrl = news_validate_feed_url((string)($input['rss_url'] ?? ''));
    $category = news_normalize_category((string)($input['category'] ?? 'general'));
    $language = news_clean_text((string)($input['language'] ?? 'tr'), 16);
    $language = $language !== '' ? strtolower($language) : 'tr';
    $isActive = ((int)($input['is_active'] ?? 1) === 1) ? 1 : 0;

    if ($name === '') {
        throw new InvalidArgumentException('Kaynak adı zorunludur.');
    }

    $urlHash = hash('sha256', mb_strtolower($rssUrl, 'UTF-8'));
    $dup = $pdo->prepare('SELECT id FROM news_sources WHERE url_hash = ? LIMIT 1');
    $dup->execute([$urlHash]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Bu RSS URL zaten kayıtlı.');
    }

    $id = news_uuid();
    $stmt = $pdo->prepare('INSERT INTO news_sources (id, name, rss_url, url_hash, category, language, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $name, $rssUrl, $urlHash, $category, $language, $isActive]);

    return (array)news_get_source($pdo, $id);
}

function news_update_source(PDO $pdo, string $id, array $input): array
{
    $source = news_get_source($pdo, $id);
    if (!$source) {
        throw new RuntimeException('Kaynak bulunamadı.');
    }

    $name = array_key_exists('name', $input) ? news_clean_text((string)$input['name'], 191) : (string)$source['name'];
    $rssUrl = array_key_exists('rss_url', $input) ? news_validate_feed_url((string)$input['rss_url']) : (string)$source['rss_url'];
    $category = array_key_exists('category', $input) ? news_normalize_category((string)$input['category']) : (string)$source['category'];
    $language = array_key_exists('language', $input) ? news_clean_text((string)$input['language'], 16) : (string)$source['language'];
    $language = $language !== '' ? strtolower($language) : 'tr';
    $isActive = array_key_exists('is_active', $input) ? ((((int)$input['is_active']) === 1) ? 1 : 0) : (int)$source['is_active'];

    if ($name === '') {
        throw new InvalidArgumentException('Kaynak adı zorunludur.');
    }

    $urlHash = hash('sha256', mb_strtolower($rssUrl, 'UTF-8'));
    $dup = $pdo->prepare('SELECT id FROM news_sources WHERE url_hash = ? AND id <> ? LIMIT 1');
    $dup->execute([$urlHash, $id]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Bu RSS URL başka bir kaynakta kullanılıyor.');
    }

    $stmt = $pdo->prepare('UPDATE news_sources
        SET name = ?, rss_url = ?, url_hash = ?, category = ?, language = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?');
    $stmt->execute([$name, $rssUrl, $urlHash, $category, $language, $isActive, $id]);

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
            $mediaUrl = '';
            $media = $node->children('http://search.yahoo.com/mrss/');
            if (isset($media->content)) {
                $attr = $media->content->attributes();
                $mediaUrl = (string)($attr['url'] ?? '');
            }

            $enclosureUrl = '';
            if (isset($node->enclosure)) {
                $attr = $node->enclosure->attributes();
                $enclosureUrl = (string)($attr['url'] ?? '');
            }

            $link = (string)($node->link ?? '');
            $title = news_clean_text((string)($node->title ?? ''), 500);
            if ($title === '' || trim($link) === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'link' => trim($link),
                'summary' => news_clean_text((string)($node->description ?? ''), 1200),
                'published_at' => news_parse_datetime((string)($node->pubDate ?? '')),
                'image_url' => trim($mediaUrl !== '' ? $mediaUrl : $enclosureUrl),
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

            $mediaUrl = '';
            $media = $node->children('http://search.yahoo.com/mrss/');
            if (isset($media->content)) {
                $attr = $media->content->attributes();
                $mediaUrl = (string)($attr['url'] ?? '');
            }

            $title = news_clean_text((string)($node->title ?? ''), 500);
            if ($title === '' || $link === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'link' => $link,
                'summary' => news_clean_text((string)($node->summary ?? $node->content ?? ''), 1200),
                'published_at' => news_parse_datetime((string)($node->updated ?? $node->published ?? '')),
                'image_url' => trim($mediaUrl),
                'category' => news_normalize_category((string)($node->category ?? 'general')),
            ];
        }
    }

    return $items;
}

function news_upsert_pending_article(PDO $pdo, array $source, array $item): bool
{
    $title = news_clean_text((string)($item['title'] ?? ''), 500);
    $link = trim((string)($item['link'] ?? ''));
    if ($title === '' || $link === '') {
        return false;
    }

    $hash = news_source_hash($link, $title);
    $exists = $pdo->prepare('SELECT id FROM news_articles WHERE source_hash = ? LIMIT 1');
    $exists->execute([$hash]);
    if ($exists->fetchColumn()) {
        return false;
    }

    $summary = news_clean_text((string)($item['summary'] ?? ''), 1200);
    $imageUrl = trim((string)($item['image_url'] ?? ''));
    $category = news_normalize_category((string)($item['category'] ?? $source['category'] ?? 'general'));
    $publishedAt = news_parse_datetime((string)($item['published_at'] ?? ''));

    $stmt = $pdo->prepare('INSERT INTO news_articles
        (id, source_id, title, summary, image_url, source_name, source_url, source_hash, category, published_at, status, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", 0, NOW(), NOW())');
    $stmt->execute([
        news_uuid(),
        (string)$source['id'],
        $title,
        $summary,
        ($imageUrl !== '' ? $imageUrl : null),
        news_clean_text((string)($source['name'] ?? ''), 191),
        $link,
        $hash,
        $category,
        $publishedAt,
    ]);

    return true;
}

function news_fetch_rss_source(PDO $pdo, array $source): array
{
    $rssUrl = (string)($source['rss_url'] ?? '');
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

        $xml = @file_get_contents($rssUrl, false, $ctx);
        if (!is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('Feed içeriği alınamadı.');
        }

        $items = news_extract_rss_items($xml);
        $inserted = 0;
        foreach ($items as $item) {
            if (news_upsert_pending_article($pdo, $source, $item)) {
                $inserted++;
            }
        }

        $upd = $pdo->prepare('UPDATE news_sources SET last_fetched_at = NOW(), last_error = NULL, updated_at = NOW() WHERE id = ?');
        $upd->execute([$sourceId]);

        return [
            'success' => true,
            'fetched' => count($items),
            'inserted' => $inserted,
            'source_id' => $sourceId,
        ];
    } catch (Throwable $e) {
        $upd = $pdo->prepare('UPDATE news_sources SET last_error = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([news_clean_text($e->getMessage(), 2000), $sourceId]);

        return [
            'success' => false,
            'fetched' => 0,
            'inserted' => 0,
            'source_id' => $sourceId,
            'error' => $e->getMessage(),
        ];
    }
}

function news_list_admin_articles(PDO $pdo, string $status = 'pending'): array
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $status = 'pending';
    }

    $stmt = $pdo->prepare('SELECT a.*,
            s.name AS source_feed_name,
            s.rss_url AS source_feed_url
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
    $rejectedAt = ($status === 'rejected') ? 'NOW()' : 'NULL';

    $sql = 'UPDATE news_articles
        SET status = ?, is_active = ?, approved_at = ' . $approvedAt . ', rejected_at = ' . $rejectedAt . ', updated_at = NOW()
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

    $sourceHash = news_source_hash($sourceUrl, $title);
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
    $offset = ($page - 1) * $limit;

    $where = ['status = "approved"', 'is_active = 1'];
    $params = [];
    if (!empty($filters['category'])) {
        $where[] = 'category = ?';
        $params[] = $category;
    }

    $sql = 'SELECT id, title, summary, source_name, source_url, image_url, category, published_at, created_at
        FROM news_articles
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY published_at DESC, created_at DESC
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
    $stmt = $pdo->prepare('SELECT id, title, summary, source_name, source_url, image_url, category, published_at, created_at
        FROM news_articles
        WHERE id = ? AND status = "approved" AND is_active = 1
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
        'published_at' => (string)($item['published_at'] ?? ''),
    ];
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/guest_device_quota_helper.php';

api_require_method('POST');

function analytics_text($value, int $max): string
{
    $value = trim((string)$value);
    return mb_substr($value, 0, $max);
}

function analytics_client_ip(): string
{
    // Proxy headers are accepted only from Cloudflare; otherwise REMOTE_ADDR wins.
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_CF_RAY'])) {
        $candidate = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function analytics_country_fallback(string $timezone): array
{
    $code = strtoupper(analytics_text($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '', 2));
    $map = [
        'TR'=>['Türkiye',39.0,35.0], 'DE'=>['Almanya',51.2,10.4], 'NL'=>['Hollanda',52.1,5.3],
        'GB'=>['Birleşik Krallık',55.4,-3.4], 'US'=>['ABD',39.8,-98.6], 'FR'=>['Fransa',46.2,2.2],
        'IT'=>['İtalya',41.9,12.6], 'ES'=>['İspanya',40.5,-3.7], 'GR'=>['Yunanistan',39.1,21.8],
        'CY'=>['Kıbrıs',35.1,33.4], 'RU'=>['Rusya',61.5,105.3], 'UA'=>['Ukrayna',48.4,31.2],
        'AE'=>['BAE',23.4,53.8], 'SA'=>['Suudi Arabistan',23.9,45.1], 'EG'=>['Mısır',26.8,30.8],
        'IN'=>['Hindistan',20.6,79.0], 'CN'=>['Çin',35.9,104.2], 'JP'=>['Japonya',36.2,138.3],
        'SG'=>['Singapur',1.35,103.8], 'AU'=>['Avustralya',-25.3,133.8], 'CA'=>['Kanada',56.1,-106.3],
        'BR'=>['Brezilya',-14.2,-51.9], 'NO'=>['Norveç',60.5,8.5], 'SE'=>['İsveç',60.1,18.6],
        'DK'=>['Danimarka',56.3,9.5], 'BE'=>['Belçika',50.5,4.5], 'PL'=>['Polonya',51.9,19.1],
        'RO'=>['Romanya',45.9,24.9], 'BG'=>['Bulgaristan',42.7,25.5], 'GE'=>['Gürcistan',42.3,43.4],
    ];
    if ($code === '' && $timezone === 'Europe/Istanbul') $code = 'TR';
    $item = $map[$code] ?? null;
    return [$code ?: null, $item[0] ?? null, null, null, $item[1] ?? null, $item[2] ?? null];
}

function analytics_geoip(PDO $pdo, string $ip, string $ipHash, string $timezone): array
{
    $fallback = analytics_country_fallback($timezone);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $fallback;
    }

    try {
        $stmt = $pdo->prepare('SELECT country_code,country_name,region_name,city_name,latitude,longitude,lookup_status
            FROM visitor_analytics_geo_cache WHERE ip_hash=? AND expires_at>NOW() LIMIT 1');
        $stmt->execute([$ipHash]);
        $cached = $stmt->fetch();
        if ($cached) {
            return $cached['lookup_status'] === 'success'
                ? [$cached['country_code'], $cached['country_name'], $cached['region_name'], $cached['city_name'], $cached['latitude'], $cached['longitude']]
                : $fallback;
        }
    } catch (Throwable $e) {
        // The tracking endpoint must remain available before/while the cache migration is deployed.
    }

    if (!function_exists('curl_init')) return $fallback;
    $ch = curl_init('https://ipwho.is/' . rawurlencode($ip));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1200,
        CURLOPT_TIMEOUT_MS => 2500,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'DenizciEgitim-VisitorAnalytics/1.0',
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = is_string($raw) && strlen($raw) <= 65536 && $status === 200 ? json_decode($raw, true) : null;

    $lat = is_array($data) && is_numeric($data['latitude'] ?? null) ? (float)$data['latitude'] : null;
    $lng = is_array($data) && is_numeric($data['longitude'] ?? null) ? (float)$data['longitude'] : null;
    $valid = is_array($data) && ($data['success'] ?? false) === true
        && preg_match('/^[A-Z]{2}$/', strtoupper((string)($data['country_code'] ?? '')))
        && $lat !== null && $lat >= -90 && $lat <= 90 && $lng !== null && $lng >= -180 && $lng <= 180;
    $location = $valid ? [
        strtoupper((string)$data['country_code']), analytics_text($data['country'] ?? '', 100) ?: null,
        analytics_text($data['region'] ?? '', 120) ?: null, analytics_text($data['city'] ?? '', 120) ?: null,
        $lat, $lng,
    ] : $fallback;

    try {
        $cache = $pdo->prepare('INSERT INTO visitor_analytics_geo_cache
            (ip_hash,country_code,country_name,region_name,city_name,latitude,longitude,lookup_status,resolved_at,expires_at)
            VALUES (?,?,?,?,?,?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL ? DAY))
            ON DUPLICATE KEY UPDATE country_code=VALUES(country_code),country_name=VALUES(country_name),region_name=VALUES(region_name),city_name=VALUES(city_name),latitude=VALUES(latitude),longitude=VALUES(longitude),lookup_status=VALUES(lookup_status),resolved_at=NOW(),expires_at=VALUES(expires_at)');
        $cache->execute([$ipHash, $valid ? $location[0] : null, $valid ? $location[1] : null, $valid ? $location[2] : null,
            $valid ? $location[3] : null, $valid ? $location[4] : null, $valid ? $location[5] : null,
            $valid ? 'success' : 'failed', $valid ? 30 : 1]);
    } catch (Throwable $e) {
        // GeoIP caching is an enhancement; a cache write failure must not lose page views.
    }
    return $location;
}

try {
    $p = api_get_request_data();
    $sessionId = strtolower(analytics_text($p['session_id'] ?? '', 36));
    $visitorId = analytics_text($p['visitor_id'] ?? '', 100);
    if (!preg_match('/^[0-9a-f-]{36}$/', $sessionId) || strlen($visitorId) < 16) {
        api_error('Geçersiz analitik oturumu.', 422);
    }

    $path = analytics_text($p['path'] ?? '/', 500) ?: '/';
    if (!str_starts_with($path, '/')) $path = '/';
    $title = analytics_text($p['title'] ?? '', 255) ?: null;
    $timezone = analytics_text($p['timezone'] ?? '', 64);
    $clientIp = analytics_client_ip();
    $ipHash = hash_hmac('sha256', 'analytics-ip:' . $clientIp, guest_device_quota_hmac_key());
    [$countryCode, $countryName, $regionName, $cityName, $lat, $lng] = analytics_geoip($pdo, $clientIp, $ipHash, $timezone);
    $visitorHash = hash_hmac('sha256', 'analytics-visitor:' . $visitorId, guest_device_quota_hmac_key());
    $auth = api_resolve_auth($pdo);
    $userId = $auth['user']['id'] ?? null;

    $referrer = analytics_text($p['referrer'] ?? '', 500);
    $referrerHost = $referrer !== '' ? parse_url($referrer, PHP_URL_HOST) : null;
    $isHeartbeat = !empty($p['heartbeat']);
    $params = [
        $sessionId, $visitorHash, $userId, $ipHash, $path, $path,
        $referrerHost, $countryCode, $countryName, $regionName, $cityName, $lat, $lng,
        analytics_text($p['device_type'] ?? 'unknown', 24), analytics_text($p['browser'] ?? 'Unknown', 48),
        analytics_text($p['os'] ?? 'Unknown', 48), analytics_text($p['language'] ?? '', 24) ?: null,
        $timezone ?: null, analytics_text($p['screen'] ?? '', 24) ?: null,
        analytics_text($p['utm_source'] ?? '', 120) ?: null, analytics_text($p['utm_medium'] ?? '', 120) ?: null,
        analytics_text($p['utm_campaign'] ?? '', 160) ?: null,
        $isHeartbeat ? 0 : 1,
    ];
    $sql = 'INSERT INTO visitor_analytics_sessions
        (session_id,visitor_hash,user_id,ip_hash,first_seen_at,last_seen_at,pageview_count,landing_path,last_path,referrer_host,country_code,country_name,region_name,city_name,latitude,longitude,device_type,browser_name,os_name,language_code,timezone_name,screen_size,utm_source,utm_medium,utm_campaign)
        VALUES (?,?,?,?,NOW(),NOW(),1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE user_id=COALESCE(VALUES(user_id),user_id),last_seen_at=NOW(),last_path=VALUES(last_path),
        country_code=COALESCE(VALUES(country_code),country_code),country_name=COALESCE(VALUES(country_name),country_name),
        region_name=COALESCE(VALUES(region_name),region_name),city_name=COALESCE(VALUES(city_name),city_name),
        latitude=COALESCE(VALUES(latitude),latitude),longitude=COALESCE(VALUES(longitude),longitude),pageview_count=pageview_count+?';
    $pdo->prepare($sql)->execute($params);

    if (!$isHeartbeat) {
        $pdo->prepare('INSERT INTO visitor_analytics_pageviews (session_id,path,page_title,occurred_at) VALUES (?,?,?,NOW())')
            ->execute([$sessionId, $path, $title]);
    }
    api_success('OK', []);
} catch (Throwable $e) {
    api_error('Analitik kaydı oluşturulamadı.', 500);
}

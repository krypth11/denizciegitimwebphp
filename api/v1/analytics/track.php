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

function analytics_location(string $timezone): array
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
    return [$code ?: null, $item[0] ?? null, $item[1] ?? null, $item[2] ?? null];
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
    [$countryCode, $countryName, $lat, $lng] = analytics_location($timezone);
    $ipHash = hash_hmac('sha256', 'analytics-ip:' . analytics_client_ip(), guest_device_quota_hmac_key());
    $visitorHash = hash_hmac('sha256', 'analytics-visitor:' . $visitorId, guest_device_quota_hmac_key());
    $auth = api_resolve_auth($pdo);
    $userId = $auth['user']['id'] ?? null;

    $referrer = analytics_text($p['referrer'] ?? '', 500);
    $referrerHost = $referrer !== '' ? parse_url($referrer, PHP_URL_HOST) : null;
    $isHeartbeat = !empty($p['heartbeat']);
    $params = [
        $sessionId, $visitorHash, $userId, $ipHash, $path, $path,
        $referrerHost, $countryCode, $countryName, $lat, $lng,
        analytics_text($p['device_type'] ?? 'unknown', 24), analytics_text($p['browser'] ?? 'Unknown', 48),
        analytics_text($p['os'] ?? 'Unknown', 48), analytics_text($p['language'] ?? '', 24) ?: null,
        $timezone ?: null, analytics_text($p['screen'] ?? '', 24) ?: null,
        analytics_text($p['utm_source'] ?? '', 120) ?: null, analytics_text($p['utm_medium'] ?? '', 120) ?: null,
        analytics_text($p['utm_campaign'] ?? '', 160) ?: null,
        $isHeartbeat ? 0 : 1,
    ];
    $sql = 'INSERT INTO visitor_analytics_sessions
        (session_id,visitor_hash,user_id,ip_hash,first_seen_at,last_seen_at,pageview_count,landing_path,last_path,referrer_host,country_code,country_name,latitude,longitude,device_type,browser_name,os_name,language_code,timezone_name,screen_size,utm_source,utm_medium,utm_campaign)
        VALUES (?,?,?,?,NOW(),NOW(),1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE user_id=COALESCE(VALUES(user_id),user_id),last_seen_at=NOW(),last_path=VALUES(last_path),pageview_count=pageview_count+?';
    $pdo->prepare($sql)->execute($params);

    if (!$isHeartbeat) {
        $pdo->prepare('INSERT INTO visitor_analytics_pageviews (session_id,path,page_title,occurred_at) VALUES (?,?,?,NOW())')
            ->execute([$sessionId, $path, $title]);
    }
    api_success('OK', []);
} catch (Throwable $e) {
    api_error('Analitik kaydı oluşturulamadı.', 500);
}

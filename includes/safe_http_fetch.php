<?php

function safe_http_allowed_rss_hosts(): array
{
    $raw = trim((string)(getenv('NEWS_RSS_ALLOWED_HOSTS') ?: ''));
    if ($raw === '') return [];
    return array_values(array_unique(array_filter(array_map(static fn($v) => strtolower(trim($v)), explode(',', $raw)))));
}

function safe_http_ip_is_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function safe_http_validate_rss_url(string $url): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') throw new RuntimeException('RSS adresi yalnız HTTPS olabilir.');
    if (!empty($parts['user']) || !empty($parts['pass'])) throw new RuntimeException('RSS adresinde kullanıcı bilgisi kullanılamaz.');
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if ($host === '' || !in_array($host, safe_http_allowed_rss_hosts(), true)) throw new RuntimeException('RSS hostu izin listesinde değil.');
    $port = (int)($parts['port'] ?? 443);
    if ($port !== 443) throw new RuntimeException('RSS adresinde yalnız 443 portu kullanılabilir.');

    $ips = [];
    foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
        $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
        if ($ip !== '') $ips[] = $ip;
    }
    if (!$ips) throw new RuntimeException('RSS hostu çözümlenemedi.');
    foreach ($ips as $ip) if (!safe_http_ip_is_public($ip)) throw new RuntimeException('RSS hostu güvenli olmayan bir IP adresine çözümlendi.');
    return ['url' => $url, 'host' => $host, 'ips' => array_values(array_unique($ips))];
}

function safe_http_fetch_rss(string $url, int $maxBytes = 2097152): string
{
    $target = safe_http_validate_rss_url($url);
    if (!function_exists('curl_init')) throw new RuntimeException('Güvenli RSS istemcisi kullanılamıyor.');
    $body = '';
    $ch = curl_init($target['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml'],
        CURLOPT_RESOLVE => [$target['host'] . ':443:' . $target['ips'][0]],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if ($ok === false || $redirect !== '' || $status < 200 || $status >= 300 || $body === '') throw new RuntimeException('RSS kaynağı güvenli biçimde alınamadı.');
    return $body;
}

<?php

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_admin();

function va_json(array $data): void { echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

try {
    $range = in_array($_GET['range'] ?? '7d', ['today','7d','30d','90d'], true) ? $_GET['range'] : '7d';
    $since = ['today'=>'CURDATE()','7d'=>'DATE_SUB(NOW(), INTERVAL 7 DAY)','30d'=>'DATE_SUB(NOW(), INTERVAL 30 DAY)','90d'=>'DATE_SUB(NOW(), INTERVAL 90 DAY)'][$range];
    $active = $pdo->query("SELECT session_id,user_id,last_path,country_code,country_name,region_name,city_name,latitude,longitude,device_type,browser_name,os_name,last_seen_at,TIMESTAMPDIFF(SECOND,last_seen_at,NOW()) age_seconds FROM visitor_analytics_sessions WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) ORDER BY last_seen_at DESC")->fetchAll();
    $summary = $pdo->query("SELECT COUNT(*) sessions,COUNT(DISTINCT visitor_hash) visitors,COALESCE(SUM(pageview_count),0) pageviews,COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id END) registered_users FROM visitor_analytics_sessions WHERE first_seen_at >= $since")->fetch();
    $summary['active_now'] = count($active);
    $summary['avg_pages'] = ((int)$summary['sessions'] > 0) ? round((int)$summary['pageviews']/(int)$summary['sessions'],1) : 0;
    $trend = $pdo->query("SELECT DATE(first_seen_at) day,COUNT(*) sessions,COUNT(DISTINCT visitor_hash) visitors,SUM(pageview_count) pageviews FROM visitor_analytics_sessions WHERE first_seen_at >= $since GROUP BY DATE(first_seen_at) ORDER BY day")->fetchAll();
    $countries = $pdo->query("SELECT COALESCE(country_name,'Bilinmiyor') label,COUNT(*) value FROM visitor_analytics_sessions WHERE first_seen_at >= $since GROUP BY country_code,country_name ORDER BY value DESC LIMIT 12")->fetchAll();
    $devices = $pdo->query("SELECT device_type label,COUNT(*) value FROM visitor_analytics_sessions WHERE first_seen_at >= $since GROUP BY device_type ORDER BY value DESC")->fetchAll();
    $browsers = $pdo->query("SELECT browser_name label,COUNT(*) value FROM visitor_analytics_sessions WHERE first_seen_at >= $since GROUP BY browser_name ORDER BY value DESC LIMIT 8")->fetchAll();
    $sources = $pdo->query("SELECT COALESCE(NULLIF(utm_source,''),NULLIF(referrer_host,''),'Doğrudan') label,COUNT(*) value FROM visitor_analytics_sessions WHERE first_seen_at >= $since GROUP BY label ORDER BY value DESC LIMIT 10")->fetchAll();
    $pages = $pdo->query("SELECT path label,COUNT(*) views,COUNT(DISTINCT session_id) sessions FROM visitor_analytics_pageviews WHERE occurred_at >= $since GROUP BY path ORDER BY views DESC LIMIT 15")->fetchAll();
    $recent = $pdo->query("SELECT s.session_id,s.user_id,s.country_name,s.device_type,s.browser_name,s.os_name,s.last_path,s.pageview_count,s.first_seen_at,s.last_seen_at,p.full_name,p.email FROM visitor_analytics_sessions s LEFT JOIN user_profiles p ON p.id=s.user_id WHERE s.first_seen_at >= $since ORDER BY s.last_seen_at DESC LIMIT 100")->fetchAll();
    va_json(compact('summary','active','trend','countries','devices','browsers','sources','pages','recent','range'));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Analitik verileri alınamadı. SQL migrationının çalıştırıldığını kontrol edin.'], JSON_UNESCAPED_UNICODE);
}

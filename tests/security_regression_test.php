<?php

function test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

putenv('JWT_SECRET=unit-test-only-secret-value-that-is-long-enough-123456');
putenv('JWT_ISSUER=https://issuer.test.invalid');
putenv('JWT_AUDIENCE=test-audience');
putenv('JWT_EXPIRY_SECONDS=3600');

require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/legal_html_sanitizer.php';
require_once __DIR__ . '/../includes/safe_http_fetch.php';
require_once __DIR__ . '/../api/v1/kart-oyunu/secure_run_helper.php';
require_once __DIR__ . '/../api/v1/usage_limits_helper.php';

$token = create_token('user-test', 'user@example.invalid', false);
test_assert(jwt_decode($token) !== false, 'valid token must verify');
$parts = explode('.', $token);
$tamperedPayload = base64url_encode(json_encode(['iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 100, 'user_id' => 'other']));
test_assert(jwt_decode($parts[0] . '.' . $tamperedPayload . '.' . $parts[2]) === false, 'tampered payload must fail');
test_assert(jwt_decode($parts[0] . '.' . $parts[1] . '.' . str_repeat('A', strlen($parts[2]))) === false, 'bad signature must fail');

$makeToken = static function (array $claims, array $header = ['typ' => 'JWT', 'alg' => 'HS256']): string {
    $h = base64url_encode(json_encode($header));
    $p = base64url_encode(json_encode($claims));
    return $h . '.' . $p . '.' . base64url_encode(hash_hmac('sha256', $h . '.' . $p, JWT_SECRET, true));
};
$base = ['iss' => JWT_ISSUER, 'aud' => JWT_AUDIENCE, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 100, 'user_id' => 'u', 'email' => 'e'];
test_assert(jwt_decode($makeToken(array_merge($base, ['exp' => time() - 1]))) === false, 'expired token must fail');
test_assert(jwt_decode($makeToken(array_merge($base, ['iss' => 'wrong']))) === false, 'wrong issuer must fail');
test_assert(jwt_decode($makeToken(array_merge($base, ['aud' => 'wrong']))) === false, 'wrong audience must fail');
test_assert(jwt_decode($makeToken($base, ['typ' => 'JWT', 'alg' => 'none'])) === false, 'changed algorithm must fail');

putenv('JWT_SECRET=short');
try {
    security_load_jwt_config();
    throw new RuntimeException('short JWT secret must be rejected');
} catch (RuntimeException $expected) {
    test_assert(str_contains($expected->getMessage(), 'JWT_SECRET'), 'short secret error must be generic');
}
putenv('JWT_SECRET=replace-with-a-long-placeholder-value-that-must-fail');
try {
    security_load_jwt_config();
    throw new RuntimeException('placeholder JWT secret must be rejected');
} catch (RuntimeException $expected) {
    test_assert(str_contains($expected->getMessage(), 'JWT_SECRET'), 'placeholder secret must fail closed');
}
putenv('JWT_SECRET');
try {
    security_load_jwt_config();
    throw new RuntimeException('missing JWT config must be rejected');
} catch (RuntimeException $expected) {
    test_assert(str_contains($expected->getMessage(), 'JWT_SECRET'), 'missing config must fail closed');
}

$clean = legal_sanitize_html('<h3>Başlık</h3><p onclick="bad()">Metin <a href="javascript:bad()">bağlantı</a></p><script>bad()</script>');
test_assert(!str_contains(strtolower($clean), 'script'), 'script must be removed');
test_assert(!str_contains(strtolower($clean), 'onclick'), 'event handler must be removed');
test_assert(!str_contains(strtolower($clean), 'javascript:'), 'dangerous URL must be removed');
test_assert(str_contains($clean, '<h3>'), 'safe legal HTML must remain');

putenv('NEWS_RSS_ALLOWED_HOSTS=rss.example.invalid');
foreach (['http://rss.example.invalid/feed', 'https://user:pass@rss.example.invalid/feed', 'https://rss.example.invalid:8443/feed', 'https://not-allowed.invalid/feed'] as $badUrl) {
    try {
        safe_http_validate_rss_url($badUrl);
        throw new RuntimeException('unsafe RSS URL must be rejected');
    } catch (RuntimeException $expected) {
    }
}

$rounds = [
    ['round_id' => 'r1', 'expected_answer' => true],
    ['round_id' => 'r2', 'expected_answer' => false],
];
$score = kg_secure_run_score($rounds, [['round_id' => 'r1', 'answer' => true], ['round_id' => 'r2', 'answer' => true]]);
test_assert($score['correct_count'] === 1 && $score['wrong_count'] === 1, 'server score must use snapshot answers');
try {
    kg_secure_run_score($rounds, [['round_id' => 'r2', 'answer' => false], ['round_id' => 'r1', 'answer' => true]]);
    throw new RuntimeException('reordered answers must be rejected');
} catch (RuntimeException $expected) {
}

$revenueCatPayload = [
    'subscriber' => [
        'entitlements' => [
            'unrelated_feature' => [
                'expires_date' => gmdate('c', time() + 3600),
                'product_identifier' => 'unrelated_product',
            ],
        ],
    ],
];
$strictTruth = usage_limits_extract_revenuecat_truth($revenueCatPayload, 'user-test', 'premium');
test_assert($strictTruth['is_pro'] === false, 'an unrelated entitlement must not grant Premium');
$revenueCatPayload['subscriber']['entitlements']['premium'] = [
    'expires_date' => gmdate('c', time() + 3600),
    'product_identifier' => 'premium_product',
];
$premiumTruth = usage_limits_extract_revenuecat_truth($revenueCatPayload, 'user-test', 'premium');
test_assert($premiumTruth['is_pro'] === true, 'the configured active Premium entitlement must verify');

$syncSource = file_get_contents(__DIR__ . '/../api/v1/subscription/sync.php');
test_assert(is_string($syncSource), 'subscription sync source must be readable');
test_assert(!str_contains($syncSource, "'is_pro' => $" . "effectiveClientIsPro"), 'client Premium fallback must remain removed');
test_assert(str_contains($syncSource, '$rcAppUserIdCandidates = [$authenticatedAppUserId]'), 'RevenueCat lookup must bind to the authenticated user');
test_assert(str_contains($syncSource, "'admin_manual_preserved'"), 'active admin manual Premium must be preserved');
test_assert(str_contains($syncSource, "'is_pro' => false"), 'unverified non-manual Premium must fail closed');

echo "security regression tests passed\n";

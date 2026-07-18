<?php

function security_env_required(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Required security configuration is missing: ' . $name);
    }
    return trim($value);
}

function security_env_int(string $name, int $default, int $min, int $max): int
{
    $raw = getenv($name);
    if ($raw === false || trim((string)$raw) === '') {
        return $default;
    }
    if (!preg_match('/^\d+$/', trim((string)$raw))) {
        throw new RuntimeException('Invalid numeric security configuration: ' . $name);
    }
    $value = (int)$raw;
    if ($value < $min || $value > $max) {
        throw new RuntimeException('Security configuration is outside the allowed range: ' . $name);
    }
    return $value;
}

function security_load_jwt_config(): array
{
    $secret = security_env_required('JWT_SECRET');
    $normalized = strtolower($secret);
    $blockedFragments = ['change-me', 'changeme', 'replace-with', 'example', 'placeholder'];
    $containsPlaceholder = array_filter(
        $blockedFragments,
        static fn(string $fragment): bool => str_contains($normalized, $fragment)
    ) !== [];
    if (strlen($secret) < 32 || $containsPlaceholder) {
        throw new RuntimeException('JWT_SECRET must be a strong random value of at least 32 characters.');
    }

    return [
        'secret' => $secret,
        'issuer' => security_env_required('JWT_ISSUER'),
        'audience' => security_env_required('JWT_AUDIENCE'),
        'expiry' => security_env_int('JWT_EXPIRY_SECONDS', 86400, 300, 604800),
    ];
}

if (!defined('JWT_SECRET')) {
    $jwtConfig = security_load_jwt_config();
    define('JWT_SECRET', $jwtConfig['secret']);
    define('JWT_ISSUER', $jwtConfig['issuer']);
    define('JWT_AUDIENCE', $jwtConfig['audience']);
    define('JWT_EXPIRY', $jwtConfig['expiry']);
    unset($jwtConfig);
}

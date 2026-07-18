<?php

/**
 * Loads server secrets from a PHP file outside the public web root.
 * Real environment variables always take precedence.
 */
function local_config_load(): void
{
    $configuredPath = trim((string)(getenv('APP_CONFIG_FILE') ?: ''));
    $candidates = array_values(array_unique(array_filter([
        $configuredPath,
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'denizci-config.php',
        '/home/u2621168/denizci-config.php',
    ])));

    $configPath = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $configPath = realpath($candidate) ?: $candidate;
            break;
        }
    }

    if ($configPath === null) {
        return;
    }

    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $realConfigPath = realpath($configPath);
    if ($documentRoot !== false && $realConfigPath !== false) {
        $documentRootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($realConfigPath, $documentRootPrefix)) {
            throw new RuntimeException('Private application configuration must be outside the public web root.');
        }
    }

    $values = require $configPath;
    if (!is_array($values)) {
        throw new RuntimeException('Private application configuration must return an array.');
    }

    $allowedKeys = [
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'JWT_SECRET',
        'JWT_ISSUER',
        'JWT_AUDIENCE',
        'JWT_EXPIRY_SECONDS',
        'GUEST_DEVICE_HMAC_KEY',
        'SMTP_HOST',
        'SMTP_PORT',
        'SMTP_USERNAME',
        'SMTP_PASSWORD',
        'SMTP_ENCRYPTION',
        'SMTP_FROM_EMAIL',
        'SMTP_FROM_NAME',
        'NEWS_RSS_ALLOWED_HOSTS',
        'REVENUECAT_SECRET_API_KEY',
        'REVENUECAT_PREMIUM_ENTITLEMENT_ID',
    ];

    foreach ($allowedKeys as $key) {
        $existing = getenv($key);
        if (is_string($existing) && trim($existing) !== '') {
            continue;
        }
        if (!array_key_exists($key, $values)) {
            continue;
        }

        $value = trim((string)$values[$key]);
        if ($value === '' || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('Invalid private application configuration value: ' . $key);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }

    unset($values);
}

local_config_load();

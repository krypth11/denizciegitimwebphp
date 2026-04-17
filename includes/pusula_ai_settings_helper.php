<?php

require_once __DIR__ . '/pusula_ai_config.php';

function pusula_ai_table_columns(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', PUSULA_AI_SETTINGS_TABLE) . '`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cache = [];
    foreach ($rows as $row) {
        if (!empty($row['Field'])) {
            $cache[(string)$row['Field']] = $row;
        }
    }

    return $cache;
}

function pusula_ai_column_exists(PDO $pdo, string $column): bool
{
    $columns = pusula_ai_table_columns($pdo);
    return isset($columns[$column]);
}

function pusula_ai_update_allowed_fields(PDO $pdo): array
{
    $keys = pusula_ai_settings_keys();

    $columns = pusula_ai_table_columns($pdo);
    return array_values(array_filter($keys, static fn($k) => isset($columns[$k])));
}

function pusula_ai_default_base_urls(): array
{
    return [
        'openai' => 'https://api.openai.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta',
        'claude' => 'https://api.anthropic.com/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'cerebras' => 'https://api.cerebras.ai/v1',
    ];
}

function pusula_ai_default_base_url(string $provider): string
{
    $provider = strtolower(trim($provider));
    $map = pusula_ai_default_base_urls();
    return $map[$provider] ?? $map['openai'];
}

function pusula_ai_mask_api_key(string $apiKey): string
{
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        return '';
    }

    $len = strlen($apiKey);
    if ($len <= 6) {
        return str_repeat('*', $len);
    }

    return str_repeat('*', max(0, $len - 4)) . substr($apiKey, -4);
}

function pusula_ai_is_masked_api_key(string $value): bool
{
    $value = trim($value);
    return $value !== '' && preg_match('/^\*+.{0,8}$/', $value) === 1;
}

function pusula_ai_to_bool_int($value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $str = strtolower(trim((string)$value));
    return in_array($str, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function pusula_ai_normalize_settings(array $input): array
{
    $defaults = pusula_ai_default_settings();
    $modelsMap = pusula_ai_provider_models();

    $provider = strtolower(trim((string)($input['provider'] ?? $defaults['provider'])));
    if (!isset($modelsMap[$provider])) {
        $provider = $defaults['provider'];
    }

    $providerModels = $modelsMap[$provider];
    $defaultModel = $providerModels[0] ?? $defaults['model'];

    $model = trim((string)($input['model'] ?? $defaultModel));
    if ($model === '' || !in_array($model, $providerModels, true)) {
        $model = $defaultModel;
    }

    $baseUrl = trim((string)($input['base_url'] ?? ''));
    if ($baseUrl === '') {
        $baseUrl = pusula_ai_default_base_url($provider);
    }

    $timeout = (int)($input['timeout_seconds'] ?? $defaults['timeout_seconds']);
    $temperature = (float)($input['temperature'] ?? $defaults['temperature']);
    $maxTokens = (int)($input['max_tokens'] ?? $defaults['max_tokens']);
    $dailyLimit = (int)($input['daily_limit'] ?? $defaults['daily_limit']);

    $timeout = max(5, min(120, $timeout));
    $temperature = max(0, min(1, $temperature));
    $maxTokens = max(1, min(8192, $maxTokens));
    $dailyLimit = max(0, min(1000000, $dailyLimit));

    return [
        'provider' => $provider,
        'model' => $model,
        'api_key' => trim((string)($input['api_key'] ?? $defaults['api_key'])),
        'base_url' => $baseUrl,
        'timeout_seconds' => $timeout,
        'temperature' => round($temperature, 2),
        'max_tokens' => $maxTokens,
        'premium_only' => pusula_ai_to_bool_int($input['premium_only'] ?? $defaults['premium_only']),
        'internet_required' => pusula_ai_to_bool_int($input['internet_required'] ?? $defaults['internet_required']),
        'moderation_enabled' => pusula_ai_to_bool_int($input['moderation_enabled'] ?? $defaults['moderation_enabled']),
        'daily_limit' => $dailyLimit,
        'is_active' => pusula_ai_to_bool_int($input['is_active'] ?? $defaults['is_active']),
    ];
}

function pusula_ai_is_valid_provider_model_pair(string $provider, string $model): bool
{
    $provider = strtolower(trim($provider));
    $model = trim($model);
    if ($provider === '' || $model === '') {
        return false;
    }

    $modelsMap = pusula_ai_provider_models();
    if (!isset($modelsMap[$provider])) {
        return false;
    }

    return in_array($model, $modelsMap[$provider], true);
}

function pusula_ai_build_test_connection_result(array $result, array $settings): array
{
    return [
        'success' => !empty($result['success']),
        'message' => trim((string)($result['message'] ?? '')),
        'provider' => (string)($settings['provider'] ?? ''),
        'model' => (string)($settings['model'] ?? ''),
    ];
}

function pusula_ai_generate_settings_id(): string
{
    if (function_exists('generate_uuid')) {
        $id = trim((string)generate_uuid());
        if (strlen($id) === 36) {
            return $id;
        }
    }

    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function pusula_ai_validate_settings(array $input): array
{
    $errors = [];
    $modelsMap = pusula_ai_provider_models();

    $provider = strtolower(trim((string)($input['provider'] ?? '')));
    if (!isset($modelsMap[$provider])) {
        $errors['provider'] = 'Geçersiz sağlayıcı seçimi.';
    }

    $model = trim((string)($input['model'] ?? ''));
    if ($model === '') {
        $errors['model'] = 'Model seçimi zorunludur.';
    } elseif (isset($modelsMap[$provider]) && !in_array($model, $modelsMap[$provider], true)) {
        $errors['model'] = 'Seçilen model sağlayıcı ile uyumlu değil.';
    }

    $baseUrl = trim((string)($input['base_url'] ?? ''));
    if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
        $errors['base_url'] = 'Geçerli bir base URL giriniz.';
    }

    $timeout = (int)($input['timeout_seconds'] ?? 0);
    if ($timeout < 5 || $timeout > 120) {
        $errors['timeout_seconds'] = 'Timeout saniye 5 ile 120 arasında olmalıdır.';
    }

    $temperature = (float)($input['temperature'] ?? -1);
    if ($temperature < 0 || $temperature > 1) {
        $errors['temperature'] = 'Temperature 0 ile 1 arasında olmalıdır.';
    }

    $maxTokens = (int)($input['max_tokens'] ?? 0);
    if ($maxTokens < 1 || $maxTokens > 8192) {
        $errors['max_tokens'] = 'Max Tokens 1 ile 8192 arasında olmalıdır.';
    }

    $dailyLimit = (int)($input['daily_limit'] ?? -1);
    if ($dailyLimit < 0 || $dailyLimit > 1000000) {
        $errors['daily_limit'] = 'Daily Limit 0 ile 1000000 arasında olmalıdır.';
    }

    return $errors;
}

function pusula_ai_get_settings(PDO $pdo): array
{
    $defaults = pusula_ai_default_settings();

    try {
        $selectFields = pusula_ai_update_allowed_fields($pdo);
        if (empty($selectFields)) {
            return pusula_ai_normalize_settings($defaults);
        }

        $sql = 'SELECT ' . implode(', ', $selectFields) . ' '
            . 'FROM ' . PUSULA_AI_SETTINGS_TABLE . ' LIMIT 1';
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = null;
    }

    if (!$row) {
        return pusula_ai_normalize_settings($defaults);
    }

    return pusula_ai_normalize_settings(array_merge($defaults, $row));
}

function pusula_ai_save_settings(PDO $pdo, array $payload): void
{
    $current = pusula_ai_get_settings($pdo);
    $normalized = pusula_ai_normalize_settings($payload);

    $rawProvider = strtolower(trim((string)($payload['provider'] ?? '')));
    $rawModel = trim((string)($payload['model'] ?? ''));
    if ($rawProvider !== '' && $rawModel !== '' && !pusula_ai_is_valid_provider_model_pair($rawProvider, $rawModel)) {
        throw new InvalidArgumentException(json_encode([
            'model' => 'Seçilen model sağlayıcı ile uyumlu değil.',
        ], JSON_UNESCAPED_UNICODE));
    }

    $errors = pusula_ai_validate_settings($normalized);

    if (!empty($errors)) {
        throw new InvalidArgumentException(json_encode($errors, JSON_UNESCAPED_UNICODE));
    }

    $incomingApiKey = trim((string)($payload['api_key'] ?? ''));
    if ($incomingApiKey === '' || pusula_ai_is_masked_api_key($incomingApiKey)) {
        $normalized['api_key'] = (string)($current['api_key'] ?? '');
    }

    $hasId = pusula_ai_column_exists($pdo, 'id');
    $hasCreatedAt = pusula_ai_column_exists($pdo, 'created_at');
    $hasUpdatedAt = pusula_ai_column_exists($pdo, 'updated_at');
    $allowedFields = pusula_ai_update_allowed_fields($pdo);

    if (empty($allowedFields)) {
        throw new RuntimeException('Pusula AI ayar kolonları bulunamadı.');
    }

    $selectSql = $hasId
        ? 'SELECT id FROM ' . PUSULA_AI_SETTINGS_TABLE . ' ORDER BY id DESC LIMIT 1'
        : 'SELECT 1 AS row_exists FROM ' . PUSULA_AI_SETTINGS_TABLE . ' LIMIT 1';

    $stmt = $pdo->query($selectSql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $saveFields = [];
    foreach ($allowedFields as $field) {
        $saveFields[$field] = $normalized[$field];
    }

    if ($hasUpdatedAt) {
        $saveFields['updated_at'] = date('Y-m-d H:i:s');
    }

    if ($row) {
        $setParts = [];
        $params = [];
        foreach ($saveFields as $field => $value) {
            $setParts[] = '`' . $field . '` = :' . $field;
            $params[':' . $field] = $value;
        }

        $updateSql = 'UPDATE ' . PUSULA_AI_SETTINGS_TABLE . ' SET ' . implode(', ', $setParts);
        if ($hasId && !empty($row['id'])) {
            $updateSql .= ' WHERE id = :id';
            $params[':id'] = $row['id'];
        } else {
            $updateSql .= ' LIMIT 1';
        }

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);
        return;
    }

    if ($hasCreatedAt) {
        $saveFields['created_at'] = date('Y-m-d H:i:s');
    }

    if ($hasId) {
        $saveFields['id'] = pusula_ai_generate_settings_id();
    }

    $insertFields = array_keys($saveFields);
    $insertSql = 'INSERT INTO ' . PUSULA_AI_SETTINGS_TABLE
        . ' (`' . implode('`, `', $insertFields) . '`)' 
        . ' VALUES (:' . implode(', :', $insertFields) . ')';

    $insertParams = [];
    foreach ($saveFields as $field => $value) {
        $insertParams[':' . $field] = $value;
    }

    $insertStmt = $pdo->prepare($insertSql);
    $now = date('Y-m-d H:i:s');
    if ($hasCreatedAt && empty($saveFields['created_at'])) {
        $insertParams[':created_at'] = $now;
    }
    if ($hasUpdatedAt && empty($saveFields['updated_at'])) {
        $insertParams[':updated_at'] = $now;
    }
    $insertStmt->execute($insertParams);
}

<?php

function notification_required_columns(): array
{
    return [
        'id',
        'user_id',
        'platform',
        'fcm_token',
        'installation_id',
        'device_name',
        'app_version',
        'permission_status',
        'is_active',
        'last_seen_at',
        'created_at',
        'updated_at',
    ];
}

function notification_assert_push_tokens_table_ready(PDO $pdo): void
{
    $columns = get_table_columns($pdo, 'user_push_tokens');
    if (!$columns) {
        throw new RuntimeException('user_push_tokens tablosu okunamadı.');
    }

    $missing = [];
    foreach (notification_required_columns() as $column) {
        if (!in_array($column, $columns, true)) {
            $missing[] = $column;
        }
    }

    if (!empty($missing)) {
        throw new RuntimeException(
            'user_push_tokens tablosu şeması uyumsuz. Eksik kolonlar: ' . implode(', ', $missing)
        );
    }
}

function notification_allowed_platforms(): array
{
    return ['android', 'ios', 'web', 'unknown'];
}

function notification_normalize_platform(?string $platform): string
{
    $normalized = strtolower(trim((string)$platform));
    if ($normalized === '') {
        return 'unknown';
    }

    if (!in_array($normalized, notification_allowed_platforms(), true)) {
        throw new InvalidArgumentException('Geçersiz platform değeri.');
    }

    return $normalized;
}

function notification_normalize_nullable_string($value, int $maxLength = 191): ?string
{
    $trimmed = trim((string)$value);
    if ($trimmed === '') {
        return null;
    }

    if ($maxLength > 0 && mb_strlen($trimmed, 'UTF-8') > $maxLength) {
        throw new InvalidArgumentException('Metin alanı çok uzun.');
    }

    return $trimmed;
}

function notification_normalize_permission_status($value): ?string
{
    $status = notification_normalize_nullable_string($value, 32);
    if ($status === null) {
        return null;
    }

    $status = strtolower($status);
    $allowed = ['authorized', 'denied', 'not_determined', 'provisional', 'ephemeral', 'unknown'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Geçersiz permission_status değeri.');
    }

    return $status;
}

function notification_validate_fcm_token($token): string
{
    $fcmToken = trim((string)$token);
    if ($fcmToken === '') {
        throw new InvalidArgumentException('fcm_token zorunludur.');
    }

    $len = mb_strlen($fcmToken, 'UTF-8');
    if ($len < 10 || $len > 4096) {
        throw new InvalidArgumentException('Geçersiz fcm_token.');
    }

    return $fcmToken;
}

function notification_mask_fcm_token(string $token): string
{
    $token = trim($token);
    $length = mb_strlen($token, 'UTF-8');

    if ($length <= 12) {
        return mb_substr($token, 0, 4, 'UTF-8') . '...' . mb_substr($token, -4, null, 'UTF-8');
    }

    return mb_substr($token, 0, 8, 'UTF-8') . '...' . mb_substr($token, -8, null, 'UTF-8');
}

function notification_fetch_token_by_id(PDO $pdo, string $id): ?array
{
    $sql = 'SELECT id, user_id, platform, fcm_token, installation_id, device_name, app_version,
                   permission_status, is_active, last_seen_at, created_at, updated_at
            FROM user_push_tokens
            WHERE id = ?
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (string)$row['id'],
        'user_id' => (string)$row['user_id'],
        'platform' => (string)$row['platform'],
        'fcm_token_masked' => notification_mask_fcm_token((string)$row['fcm_token']),
        'installation_id' => $row['installation_id'] !== null ? (string)$row['installation_id'] : null,
        'device_name' => $row['device_name'] !== null ? (string)$row['device_name'] : null,
        'app_version' => $row['app_version'] !== null ? (string)$row['app_version'] : null,
        'permission_status' => $row['permission_status'] !== null ? (string)$row['permission_status'] : null,
        'is_active' => ((int)$row['is_active'] === 1),
        'last_seen_at' => $row['last_seen_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function notification_register_user_token(PDO $pdo, string $userId, array $tokenPayload): array
{
    notification_assert_push_tokens_table_ready($pdo);

    $fcmToken = notification_validate_fcm_token($tokenPayload['fcm_token'] ?? null);
    $platform = notification_normalize_platform($tokenPayload['platform'] ?? 'unknown');
    $installationId = notification_normalize_nullable_string($tokenPayload['installation_id'] ?? null, 191);
    $deviceName = notification_normalize_nullable_string($tokenPayload['device_name'] ?? null, 191);
    $appVersion = notification_normalize_nullable_string($tokenPayload['app_version'] ?? null, 64);
    $permissionStatus = notification_normalize_permission_status($tokenPayload['permission_status'] ?? null);

    $findStmt = $pdo->prepare('SELECT id FROM user_push_tokens WHERE fcm_token = ? LIMIT 1');
    $findStmt->execute([$fcmToken]);
    $existingId = $findStmt->fetchColumn();

    if ($existingId) {
        $updateSql = 'UPDATE user_push_tokens
                      SET user_id = ?,
                          platform = ?,
                          installation_id = ?,
                          device_name = ?,
                          app_version = ?,
                          permission_status = ?,
                          is_active = 1,
                          last_seen_at = NOW(),
                          updated_at = NOW()
                      WHERE id = ?';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            $userId,
            $platform,
            $installationId,
            $deviceName,
            $appVersion,
            $permissionStatus,
            (string)$existingId,
        ]);

        $id = (string)$existingId;
    } else {
        $id = generate_uuid();
        $insertSql = 'INSERT INTO user_push_tokens
                      (id, user_id, platform, fcm_token, installation_id, device_name, app_version,
                       permission_status, is_active, last_seen_at, created_at, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())';
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([
            $id,
            $userId,
            $platform,
            $fcmToken,
            $installationId,
            $deviceName,
            $appVersion,
            $permissionStatus,
        ]);
    }

    $saved = notification_fetch_token_by_id($pdo, $id);
    if (!$saved) {
        throw new RuntimeException('Token kaydı doğrulanamadı.');
    }

    return $saved;
}

function notification_unregister_user_token(PDO $pdo, string $userId, string $fcmToken): bool
{
    notification_assert_push_tokens_table_ready($pdo);

    $fcmToken = notification_validate_fcm_token($fcmToken);

    $sql = 'UPDATE user_push_tokens
            SET is_active = 0,
                last_seen_at = NOW(),
                updated_at = NOW()
            WHERE user_id = ? AND fcm_token = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $fcmToken]);

    return $stmt->rowCount() > 0;
}

function notification_list_user_tokens(PDO $pdo, string $userId): array
{
    notification_assert_push_tokens_table_ready($pdo);

    $sql = 'SELECT id, platform, fcm_token, installation_id, app_version, permission_status, is_active, last_seen_at
            FROM user_push_tokens
            WHERE user_id = ?
            ORDER BY last_seen_at DESC, updated_at DESC, created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $tokens = [];
    foreach ($rows as $row) {
        $tokens[] = [
            'id' => (string)$row['id'],
            'fcm_token_masked' => notification_mask_fcm_token((string)$row['fcm_token']),
            'platform' => (string)$row['platform'],
            'installation_id' => $row['installation_id'] !== null ? (string)$row['installation_id'] : null,
            'app_version' => $row['app_version'] !== null ? (string)$row['app_version'] : null,
            'permission_status' => $row['permission_status'] !== null ? (string)$row['permission_status'] : null,
            'is_active' => ((int)$row['is_active'] === 1),
            'last_seen_at' => $row['last_seen_at'] ?? null,
        ];
    }

    return $tokens;
}

<?php

function guest_device_quota_hmac_key(): string
{
    $key = trim((string)(getenv('GUEST_DEVICE_HMAC_KEY') ?: ''));
    if (strlen($key) < 32 || str_contains(strtolower($key), 'replace-with')) {
        throw new RuntimeException('Misafir cihaz kotası yapılandırılmamış.');
    }
    return $key;
}

function guest_device_quota_validate_installation_id($value): string
{
    $installationId = strtolower(trim((string)$value));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $installationId)) {
        throw new InvalidArgumentException('Geçerli bir installation_id zorunludur.');
    }
    return $installationId;
}

function guest_device_quota_hash(string $installationId): string
{
    return hash_hmac('sha256', $installationId, guest_device_quota_hmac_key());
}

function guest_device_quota_bind(PDO $pdo, string $guestUserId, string $installationId): string
{
    $deviceHash = guest_device_quota_hash(guest_device_quota_validate_installation_id($installationId));
    $sql = 'INSERT INTO `guest_device_bindings` '
        . '(`guest_user_id`, `device_hash`, `created_at`, `last_seen_at`) VALUES (?, ?, NOW(), NOW()) '
        . 'ON DUPLICATE KEY UPDATE `device_hash` = VALUES(`device_hash`), `last_seen_at` = NOW()';
    $pdo->prepare($sql)->execute([$guestUserId, $deviceHash]);
    return $deviceHash;
}

function guest_device_quota_hash_for_user(PDO $pdo, string $userId): ?string
{
    if (!api_is_guest_user($pdo, $userId)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT `device_hash` FROM `guest_device_bindings` WHERE `guest_user_id` = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
        throw new RuntimeException('Misafir cihaz bağlantısı bulunamadı.');
    }
    return $value;
}

function guest_device_quota_get_used(
    PDO $pdo,
    string $deviceHash,
    string $qualificationId,
    string $usageDateTr,
    string $featureKey
): int {
    $stmt = $pdo->prepare(
        'SELECT `used_count` FROM `guest_device_daily_usage` '
        . 'WHERE `device_hash` = ? AND `qualification_id` = ? AND `usage_date_tr` = ? AND `feature_key` = ? LIMIT 1'
    );
    $stmt->execute([$deviceHash, $qualificationId, $usageDateTr, $featureKey]);
    return max(0, (int)($stmt->fetchColumn() ?: 0));
}

function guest_device_quota_consume(
    PDO $pdo,
    string $deviceHash,
    string $qualificationId,
    string $usageDateTr,
    string $featureKey,
    int $amount,
    int $dailyLimit
): bool {
    $amount = max(1, $amount);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO `guest_device_daily_usage` '
            . '(`device_hash`, `qualification_id`, `usage_date_tr`, `feature_key`, `used_count`, `created_at`, `updated_at`) '
            . 'VALUES (?, ?, ?, ?, 0, NOW(), NOW())'
        );
        $insert->execute([$deviceHash, $qualificationId, $usageDateTr, $featureKey]);

        $select = $pdo->prepare(
            'SELECT `used_count` FROM `guest_device_daily_usage` '
            . 'WHERE `device_hash` = ? AND `qualification_id` = ? AND `usage_date_tr` = ? AND `feature_key` = ? FOR UPDATE'
        );
        $params = [$deviceHash, $qualificationId, $usageDateTr, $featureKey];
        $select->execute($params);
        $used = (int)$select->fetchColumn();
        if (($used + $amount) > $dailyLimit) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }

        $update = $pdo->prepare(
            'UPDATE `guest_device_daily_usage` SET `used_count` = `used_count` + ?, `updated_at` = NOW() '
            . 'WHERE `device_hash` = ? AND `qualification_id` = ? AND `usage_date_tr` = ? AND `feature_key` = ?'
        );
        $update->execute(array_merge([$amount], $params));
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


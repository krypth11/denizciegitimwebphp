<?php

function auth_rate_limit_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function auth_rate_limit_key(string $scope, string $account = ''): string
{
    return hash('sha256', $scope . "\n" . auth_rate_limit_client_ip() . "\n" . strtolower(trim($account)));
}

function auth_rate_limit_status(PDO $pdo, string $scope, string $keyHash): array
{
    $stmt = $pdo->prepare('SELECT attempt_count, window_started_at, blocked_until FROM auth_rate_limits WHERE scope = ? AND key_hash = ? LIMIT 1');
    $stmt->execute([$scope, $keyHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['blocked' => false, 'retry_after' => 0, 'attempt_count' => 0];
    }
    $blockedUntil = strtotime((string)($row['blocked_until'] ?? '')) ?: 0;
    return [
        'blocked' => $blockedUntil > time(),
        'retry_after' => max(0, $blockedUntil - time()),
        'attempt_count' => max(0, (int)($row['attempt_count'] ?? 0)),
    ];
}

function auth_rate_limit_assert_allowed(PDO $pdo, string $scope, string $account = ''): void
{
    $status = auth_rate_limit_status($pdo, $scope, auth_rate_limit_key($scope, $account));
    if (!empty($status['blocked'])) {
        header('Retry-After: ' . max(1, (int)$status['retry_after']));
        api_error('Çok fazla deneme yapıldı. Lütfen daha sonra tekrar deneyin.', 429);
    }
}

function auth_rate_limit_record(PDO $pdo, string $scope, string $account, int $maxAttempts, int $windowSeconds, int $blockSeconds): void
{
    $keyHash = auth_rate_limit_key($scope, $account);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO auth_rate_limits (scope, key_hash, attempt_count, window_started_at, blocked_until, updated_at) VALUES (?, ?, 0, NOW(), NULL, NOW())')
            ->execute([$scope, $keyHash]);
        $stmt = $pdo->prepare('SELECT attempt_count, window_started_at, blocked_until FROM auth_rate_limits WHERE scope = ? AND key_hash = ? FOR UPDATE');
        $stmt->execute([$scope, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $windowStarted = strtotime((string)($row['window_started_at'] ?? '')) ?: 0;
        $count = (time() - $windowStarted) >= $windowSeconds ? 1 : ((int)($row['attempt_count'] ?? 0) + 1);
        $resetWindow = (time() - $windowStarted) >= $windowSeconds;
        $blocked = $count >= $maxAttempts;
        $sql = 'UPDATE auth_rate_limits SET attempt_count = ?, window_started_at = ' . ($resetWindow ? 'NOW()' : 'window_started_at')
            . ', blocked_until = ' . ($blocked ? '?' : 'blocked_until') . ', updated_at = NOW() WHERE scope = ? AND key_hash = ?';
        $params = [$count];
        if ($blocked) $params[] = date('Y-m-d H:i:s', time() + $blockSeconds);
        $params[] = $scope;
        $params[] = $keyHash;
        $pdo->prepare($sql)->execute($params);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function auth_rate_limit_clear(PDO $pdo, string $scope, string $account): void
{
    $pdo->prepare('DELETE FROM auth_rate_limits WHERE scope = ? AND key_hash = ?')
        ->execute([$scope, auth_rate_limit_key($scope, $account)]);
}

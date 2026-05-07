<?php

require_once __DIR__ . '/functions.php';

function qualification_change_now(): string
{
    return date('Y-m-d H:i:s');
}

function qualification_change_clamp_credits(int $credits): int
{
    if ($credits < 0) return 0;
    if ($credits > 1000) return 1000;
    return $credits;
}

function qualification_change_get_profile_created_at(PDO $pdo, string $userId): ?string
{
    $stmt = $pdo->prepare('SELECT created_at FROM user_profiles WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    $s = trim((string)$v);
    return $s !== '' ? $s : null;
}

function qualification_change_get_or_create_credit(PDO $pdo, string $userId): array
{
    $userId = trim($userId);
    if ($userId === '') {
        throw new InvalidArgumentException('userId boş olamaz.');
    }

    $stmt = $pdo->prepare('SELECT * FROM user_qualification_change_credits WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $now = qualification_change_now();
        $ins = $pdo->prepare('INSERT INTO user_qualification_change_credits (user_id, credits, annual_grant_count, last_granted_at, created_at, updated_at) VALUES (?, 1, 0, NULL, ?, ?)');
        $ins->execute([$userId, $now, $now]);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    $row['credits'] = qualification_change_clamp_credits((int)($row['credits'] ?? 0));
    $row['annual_grant_count'] = max(0, (int)($row['annual_grant_count'] ?? 0));
    return $row;
}

function qualification_change_apply_annual_grant(PDO $pdo, string $userId): array
{
    $credit = qualification_change_get_or_create_credit($pdo, $userId);
    $createdAt = qualification_change_get_profile_created_at($pdo, $userId);
    if (!$createdAt) {
        return $credit;
    }

    $yearsStmt = $pdo->prepare('SELECT TIMESTAMPDIFF(YEAR, ?, NOW())');
    $yearsStmt->execute([$createdAt]);
    $yearsSinceCreated = max(0, (int)$yearsStmt->fetchColumn());
    $annualGrantCount = max(0, (int)($credit['annual_grant_count'] ?? 0));

    if ($yearsSinceCreated > $annualGrantCount) {
        $diff = $yearsSinceCreated - $annualGrantCount;
        $newCredits = qualification_change_clamp_credits((int)($credit['credits'] ?? 0) + $diff);
        $now = qualification_change_now();
        $upd = $pdo->prepare('UPDATE user_qualification_change_credits SET credits = ?, annual_grant_count = ?, last_granted_at = ?, updated_at = ? WHERE user_id = ?');
        $upd->execute([$newCredits, $yearsSinceCreated, $now, $now, $userId]);
        $credit['credits'] = $newCredits;
        $credit['annual_grant_count'] = $yearsSinceCreated;
        $credit['last_granted_at'] = $now;
    }

    return $credit;
}

function qualification_change_get_status(PDO $pdo, string $userId): array
{
    $credit = qualification_change_get_or_create_credit($pdo, $userId);
    $createdAt = qualification_change_get_profile_created_at($pdo, $userId);
    $annualGrantCount = max(0, (int)($credit['annual_grant_count'] ?? 0));
    $nextGrantAt = null;
    if ($createdAt) {
        $nextGrantAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . ($annualGrantCount + 1) . ' years'));
    }

    $credits = qualification_change_clamp_credits((int)($credit['credits'] ?? 0));
    return [
        'credits' => $credits,
        'can_change' => $credits > 0,
        'next_grant_at' => $nextGrantAt,
        'annual_grant_count' => $annualGrantCount,
        'last_granted_at' => $credit['last_granted_at'] ?? null,
    ];
}

function qualification_change_can_change(PDO $pdo, string $userId): bool
{
    $status = qualification_change_get_status($pdo, $userId);
    return (bool)$status['can_change'];
}

function qualification_change_consume(PDO $pdo, string $userId, ?string $oldQualificationId, string $newQualificationId, string $source): array
{
    $credit = qualification_change_get_or_create_credit($pdo, $userId);
    $current = qualification_change_clamp_credits((int)($credit['credits'] ?? 0));
    if ($current <= 0) {
        throw new RuntimeException('Yeterlilik değiştirme hakkı bulunmuyor.');
    }

    $newCredits = $current - 1;
    $now = qualification_change_now();
    $upd = $pdo->prepare('UPDATE user_qualification_change_credits SET credits = ?, updated_at = ? WHERE user_id = ?');
    $upd->execute([$newCredits, $now, $userId]);

    $ins = $pdo->prepare('INSERT INTO user_qualification_change_history (user_id, old_qualification_id, new_qualification_id, source, created_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$userId, $oldQualificationId ?: null, $newQualificationId, $source, $now]);

    return qualification_change_get_status($pdo, $userId);
}

function qualification_change_set_admin_credits(PDO $pdo, string $userId, int $credits, string $adminUserId): array
{
    $credits = qualification_change_clamp_credits($credits);
    $current = qualification_change_get_or_create_credit($pdo, $userId);
    $oldCredits = qualification_change_clamp_credits((int)($current['credits'] ?? 0));
    $now = qualification_change_now();

    $upd = $pdo->prepare('UPDATE user_qualification_change_credits SET credits = ?, updated_at = ? WHERE user_id = ?');
    $upd->execute([$credits, $now, $userId]);

    if (function_exists('user_lifecycle_log_event')) {
        user_lifecycle_log_event(
            $pdo,
            $userId,
            'qualification_change_credit_updated',
            'Yeterlilik değiştirme hakkı güncellendi',
            'admin',
            (string)$oldCredits,
            (string)$credits,
            ['admin_user_id' => $adminUserId],
            0
        );
    }

    return qualification_change_get_status($pdo, $userId);
}

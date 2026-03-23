<?php

require_once dirname(__DIR__, 2) . '/includes/auth.php';

function api_get_all_headers_safe(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        return is_array($headers) ? $headers : [];
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            $headers[$name] = $value;
        }
    }

    return $headers;
}

function api_get_bearer_token(): ?string
{
    $headers = api_get_all_headers_safe();

    $auth = $headers['Authorization']
        ?? $headers['authorization']
        ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null)
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

    if (!is_string($auth) || trim($auth) === '') {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim($auth), $matches)) {
        return null;
    }

    $token = trim((string)($matches[1] ?? ''));
    return $token !== '' ? $token : null;
}

function api_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function api_generate_token_plain(): string
{
    return bin2hex(random_bytes(32));
}

function api_get_user_schema(PDO $pdo): array
{
    $columns = get_table_columns($pdo, 'user_profiles');
    if (!$columns) {
        throw new RuntimeException('user_profiles tablosu okunamadı.');
    }

    $pick = static function (array $candidates, bool $required = true) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        if ($required) {
            throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
        }

        return null;
    };

    return [
        'table' => 'user_profiles',
        'id' => $pick(['id']),
        'email' => $pick(['email']),
        'full_name' => $pick(['full_name', 'name', 'display_name'], false),
        'is_admin' => $pick(['is_admin'], false),
        'is_deleted' => $pick(['is_deleted'], false),
        'last_sign_in_at' => $pick(['last_sign_in_at', 'last_login_at'], false),
        'password' => $pick(['password_hash', 'hashed_password', 'password', 'pass_hash', 'passwd'], false),
    ];
}

function api_ensure_tokens_table(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS api_tokens (
        id CHAR(36) NOT NULL,
        user_id VARCHAR(191) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        name VARCHAR(100) NULL,
        expires_at DATETIME NULL,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_api_tokens_hash (token_hash),
        KEY idx_api_tokens_user_id (user_id),
        KEY idx_api_tokens_revoked_at (revoked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);
}

function api_find_user_by_email(PDO $pdo, string $email): ?array
{
    $schema = api_get_user_schema($pdo);

    $select = [
        "`{$schema['id']}` AS id",
        "`{$schema['email']}` AS email",
        $schema['full_name'] ? "`{$schema['full_name']}` AS full_name" : "'' AS full_name",
        $schema['is_admin'] ? "`{$schema['is_admin']}` AS is_admin" : '0 AS is_admin',
        $schema['is_deleted'] ? "`{$schema['is_deleted']}` AS is_deleted" : '0 AS is_deleted',
        $schema['password'] ? "`{$schema['password']}` AS password_hash" : "'' AS password_hash",
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM `{$schema['table']}` WHERE LOWER(`{$schema['email']}`) = LOWER(?) LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    if (((int)($row['is_deleted'] ?? 0)) === 1) {
        return null;
    }

    return [
        'id' => (string)$row['id'],
        'email' => (string)$row['email'],
        'full_name' => (string)($row['full_name'] ?? ''),
        'is_admin' => ((int)($row['is_admin'] ?? 0) === 1) ? 1 : 0,
        'password_hash' => (string)($row['password_hash'] ?? ''),
    ];
}

function api_find_user_by_id(PDO $pdo, string $userId): ?array
{
    $schema = api_get_user_schema($pdo);

    $select = [
        "`{$schema['id']}` AS id",
        "`{$schema['email']}` AS email",
        $schema['full_name'] ? "`{$schema['full_name']}` AS full_name" : "'' AS full_name",
        $schema['is_admin'] ? "`{$schema['is_admin']}` AS is_admin" : '0 AS is_admin',
        $schema['is_deleted'] ? "`{$schema['is_deleted']}` AS is_deleted" : '0 AS is_deleted',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM `{$schema['table']}` WHERE `{$schema['id']}` = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || ((int)($row['is_deleted'] ?? 0) === 1)) {
        return null;
    }

    return [
        'id' => (string)$row['id'],
        'email' => (string)$row['email'],
        'full_name' => (string)($row['full_name'] ?? ''),
        'is_admin' => ((int)($row['is_admin'] ?? 0) === 1) ? 1 : 0,
    ];
}

function api_update_last_sign_in(PDO $pdo, string $userId): void
{
    $schema = api_get_user_schema($pdo);
    if (!$schema['last_sign_in_at']) {
        return;
    }

    $sql = "UPDATE `{$schema['table']}` SET `{$schema['last_sign_in_at']}` = NOW() WHERE `{$schema['id']}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
}

function api_create_user_token(PDO $pdo, string $userId): string
{
    api_ensure_tokens_table($pdo);

    $token = api_generate_token_plain();
    $hash = api_hash_token($token);

    $sql = 'INSERT INTO api_tokens (id, user_id, token_hash, name, expires_at, created_at, revoked_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW(), NULL)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([generate_uuid(), $userId, $hash, 'mobile']);

    return $token;
}

function api_resolve_auth(PDO $pdo): ?array
{
    api_ensure_tokens_table($pdo);

    $token = api_get_bearer_token();
    if (!$token) {
        return null;
    }

    $hash = api_hash_token($token);

    $sql = 'SELECT user_id FROM api_tokens
            WHERE token_hash = ?
              AND revoked_at IS NULL
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $user = api_find_user_by_id($pdo, (string)$row['user_id']);
    if (!$user) {
        return null;
    }

    $touchStmt = $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token_hash = ?');
    $touchStmt->execute([$hash]);

    return [
        'token_hash' => $hash,
        'user' => $user,
    ];
}

function api_require_auth(PDO $pdo): array
{
    $auth = api_resolve_auth($pdo);
    if (!$auth) {
        api_error('Yetkisiz erişim.', 401);
    }

    return $auth;
}

function api_revoke_hashed_token(PDO $pdo, string $tokenHash): void
{
    api_ensure_tokens_table($pdo);

    $sql = 'UPDATE api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tokenHash]);
}

function api_get_profile_schema(PDO $pdo): array
{
    $columns = get_table_columns($pdo, 'user_profiles');
    if (!$columns) {
        throw new RuntimeException('user_profiles tablosu okunamadı.');
    }

    $pick = static function (array $candidates, bool $required = true) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        if ($required) {
            throw new RuntimeException('Gerekli profil kolonu bulunamadı: ' . implode(', ', $candidates));
        }

        return null;
    };

    return [
        'table' => 'user_profiles',
        'id' => $pick(['id']),
        'email' => $pick(['email']),
        'full_name' => $pick(['full_name', 'name', 'display_name'], false),
        'is_admin' => $pick(['is_admin'], false),
        'current_qualification_id' => $pick(['current_qualification_id', 'qualification_id'], false),
        'target_qualification_id' => $pick(['target_qualification_id'], false),
        'onboarding_completed' => $pick(['onboarding_completed', 'is_onboarding_completed'], false),
        'created_at' => $pick(['created_at', 'created_on'], false),
        'updated_at' => $pick(['updated_at', 'updated_on'], false),
    ];
}

function api_find_profile_by_user_id(PDO $pdo, string $userId): ?array
{
    $schema = api_get_profile_schema($pdo);

    $select = [
        "`{$schema['id']}` AS id",
        "`{$schema['email']}` AS email",
        $schema['full_name'] ? "`{$schema['full_name']}` AS full_name" : "'' AS full_name",
        $schema['is_admin'] ? "`{$schema['is_admin']}` AS is_admin" : '0 AS is_admin',
        $schema['current_qualification_id'] ? "`{$schema['current_qualification_id']}` AS current_qualification_id" : 'NULL AS current_qualification_id',
        $schema['target_qualification_id'] ? "`{$schema['target_qualification_id']}` AS target_qualification_id" : 'NULL AS target_qualification_id',
        $schema['onboarding_completed'] ? "`{$schema['onboarding_completed']}` AS onboarding_completed" : '0 AS onboarding_completed',
        $schema['created_at'] ? "`{$schema['created_at']}` AS created_at" : 'NULL AS created_at',
        $schema['updated_at'] ? "`{$schema['updated_at']}` AS updated_at" : 'NULL AS updated_at',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . " FROM `{$schema['table']}` WHERE `{$schema['id']}` = ? LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $currentName = null;
    $qualId = (string)($row['current_qualification_id'] ?? '');
    if ($qualId !== '') {
        $q = $pdo->prepare('SELECT name FROM qualifications WHERE id = ? LIMIT 1');
        $q->execute([$qualId]);
        $currentName = $q->fetchColumn() ?: null;
    }

    return [
        'id' => (string)$row['id'],
        'email' => (string)($row['email'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? ''),
        'is_admin' => ((int)($row['is_admin'] ?? 0) === 1),
        'current_qualification_id' => $row['current_qualification_id'] ?? null,
        'target_qualification_id' => $row['target_qualification_id'] ?? null,
        'onboarding_completed' => ((int)($row['onboarding_completed'] ?? 0) === 1),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'current_qualification_name' => $currentName,
    ];
}

function api_update_profile_fields(PDO $pdo, string $userId, array $updates): void
{
    if (!$updates) {
        return;
    }

    $schema = api_get_profile_schema($pdo);
    $set = [];
    $values = [];

    foreach ($updates as $col => $val) {
        $set[] = "`{$col}` = ?";
        $values[] = $val;
    }

    if ($schema['updated_at']) {
        $set[] = "`{$schema['updated_at']}` = NOW()";
    }

    $values[] = $userId;
    $sql = "UPDATE `{$schema['table']}` SET " . implode(', ', $set) . " WHERE `{$schema['id']}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function api_qualification_exists(PDO $pdo, string $qualificationId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM qualifications WHERE id = ?');
    $stmt->execute([$qualificationId]);
    return ((int)$stmt->fetchColumn()) > 0;
}

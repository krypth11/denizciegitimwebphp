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
        'is_guest' => $pick(['is_guest', 'guest'], false),
        'is_deleted' => $pick(['is_deleted'], false),
        'password' => $pick(['password_hash', 'hashed_password', 'password', 'pass_hash', 'passwd'], false),
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
        $schema['is_guest'] ? "`{$schema['is_guest']}` AS is_guest" : '0 AS is_guest',
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
        'is_guest' => ((int)($row['is_guest'] ?? 0) === 1),
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

function api_email_exists(PDO $pdo, string $email): bool
{
    $schema = api_get_profile_schema($pdo);
    $sql = 'SELECT COUNT(*) FROM `' . $schema['table'] . '` WHERE LOWER(`' . $schema['email'] . '`) = LOWER(?)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function api_create_user_profile(PDO $pdo, array $input): string
{
    $schema = api_get_profile_schema($pdo);

    $userId = generate_uuid();
    $email = trim(strtolower((string)($input['email'] ?? '')));
    $fullName = trim((string)($input['full_name'] ?? ''));
    $passwordHash = (string)($input['password_hash'] ?? '');
    $isAdmin = !empty($input['is_admin']) ? 1 : 0;
    $isGuest = !empty($input['is_guest']) ? 1 : 0;
    $onboardingCompleted = !empty($input['onboarding_completed']) ? 1 : 0;
    $currentQualificationId = $input['current_qualification_id'] ?? null;
    $targetQualificationId = $input['target_qualification_id'] ?? null;

    $columns = [];
    $holders = [];
    $params = [];

    $addValue = static function (string $column, $value) use (&$columns, &$holders, &$params): void {
        $columns[] = '`' . $column . '`';
        $holders[] = '?';
        $params[] = $value;
    };

    $addNow = static function (string $column) use (&$columns, &$holders): void {
        $columns[] = '`' . $column . '`';
        $holders[] = 'NOW()';
    };

    $addValue($schema['id'], $userId);
    $addValue($schema['email'], $email);

    if ($schema['full_name']) {
        $addValue($schema['full_name'], $fullName);
    }
    if ($schema['password']) {
        $addValue($schema['password'], $passwordHash);
    }
    if ($schema['is_admin']) {
        $addValue($schema['is_admin'], $isAdmin);
    }
    if ($schema['is_guest']) {
        $addValue($schema['is_guest'], $isGuest);
    }
    if ($schema['is_deleted']) {
        $addValue($schema['is_deleted'], 0);
    }
    if ($schema['onboarding_completed']) {
        $addValue($schema['onboarding_completed'], $onboardingCompleted);
    }
    if ($schema['current_qualification_id']) {
        $addValue($schema['current_qualification_id'], $currentQualificationId);
    }
    if ($schema['target_qualification_id']) {
        $addValue($schema['target_qualification_id'], $targetQualificationId);
    }
    if ($schema['created_at']) {
        $addNow($schema['created_at']);
    }
    if ($schema['updated_at']) {
        $addNow($schema['updated_at']);
    }

    $sql = 'INSERT INTO `' . $schema['table'] . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $userId;
}

function api_build_auth_user_payload(PDO $pdo, string $userId): array
{
    $profile = api_find_profile_by_user_id($pdo, $userId);
    if ($profile) {
        return [
            'id' => (string)$profile['id'],
            'email' => (string)$profile['email'],
            'full_name' => (string)($profile['full_name'] ?? ''),
            'is_admin' => (bool)($profile['is_admin'] ?? false),
            'current_qualification_id' => $profile['current_qualification_id'] ?? null,
            'onboarding_completed' => (bool)($profile['onboarding_completed'] ?? false),
            'is_guest' => (bool)($profile['is_guest'] ?? false),
        ];
    }

    $user = api_find_user_by_id($pdo, $userId);
    if (!$user) {
        return [
            'id' => $userId,
            'email' => '',
            'full_name' => '',
            'is_admin' => false,
            'current_qualification_id' => null,
            'onboarding_completed' => false,
            'is_guest' => false,
        ];
    }

    return [
        'id' => (string)$user['id'],
        'email' => (string)$user['email'],
        'full_name' => (string)($user['full_name'] ?? ''),
        'is_admin' => ((int)($user['is_admin'] ?? 0) === 1),
        'current_qualification_id' => null,
        'onboarding_completed' => false,
        'is_guest' => false,
    ];
}

function api_is_duplicate_error(Throwable $e): bool
{
    if ($e instanceof PDOException) {
        $code = (string)($e->errorInfo[0] ?? $e->getCode());
        return $code === '23000';
    }

    return false;
}

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

function api_assert_tokens_table_ready(PDO $pdo): void
{
    $columns = get_table_columns($pdo, 'api_tokens');
    if (!$columns) {
        throw new RuntimeException('api_tokens tablosu eksik, migration çalıştırılmalı');
    }

    $required = ['id', 'user_id', 'token_hash', 'expires_at', 'last_used_at', 'created_at', 'revoked_at'];
    $missing = [];
    foreach ($required as $col) {
        if (!in_array($col, $columns, true)) {
            $missing[] = $col;
        }
    }

    if (!empty($missing)) {
        throw new RuntimeException(
            'api_tokens tablosu şeması uyumsuz. Beklenen kolonlar: '
            . implode(', ', $required)
            . '. Eksikler: '
            . implode(', ', $missing)
        );
    }
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
    api_assert_tokens_table_ready($pdo);

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
    $token = api_get_bearer_token();
    if (!$token) {
        return null;
    }

    // Token tablosu yoksa API bearer auth doğrulanamaz; fallback katmanına bırak.
    try {
        api_assert_tokens_table_ready($pdo);
    } catch (Throwable $e) {
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

function api_resolve_web_auth(PDO $pdo): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionUser = verify_token();
    if (!$sessionUser || empty($sessionUser['user_id'])) {
        return null;
    }

    $user = api_find_user_by_id($pdo, (string)$sessionUser['user_id']);
    if (!$user) {
        return null;
    }

    return [
        'token_hash' => null,
        'user' => $user,
        'source' => 'web_session',
    ];
}

function api_require_auth(PDO $pdo): array
{
    $auth = api_resolve_auth($pdo);
    if (!$auth) {
        $auth = api_resolve_web_auth($pdo);
    }

    if (!$auth) {
        api_error('Yetkisiz erişim.', 401);
    }

    return $auth;
}

function api_revoke_hashed_token(PDO $pdo, string $tokenHash): void
{
    api_assert_tokens_table_ready($pdo);

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
        'email_verified' => $pick(['email_verified'], false),
        'email_verified_at' => $pick(['email_verified_at'], false),
        'pending_email' => $pick(['pending_email'], false),
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
        $schema['email_verified'] ? "`{$schema['email_verified']}` AS email_verified" : '0 AS email_verified',
        $schema['email_verified_at'] ? "`{$schema['email_verified_at']}` AS email_verified_at" : 'NULL AS email_verified_at',
        $schema['pending_email'] ? "`{$schema['pending_email']}` AS pending_email" : 'NULL AS pending_email',
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

    $email = (string)($row['email'] ?? '');
    $fullName = (string)($row['full_name'] ?? '');

    $isGuest = null;
    if ($schema['is_guest']) {
        $isGuest = ((int)($row['is_guest'] ?? 0) === 1);
    }
    if ($isGuest === null) {
        $emailLower = strtolower(trim($email));
        $fullNameLower = strtolower(trim($fullName));

        $isGuestByEmail = ($emailLower !== '' && str_ends_with($emailLower, '@guest.local'));
        $isGuestByName = in_array($fullNameLower, ['misafir kullanıcı', 'misafir kullanici', 'guest user'], true);
        $isGuest = $isGuestByEmail || $isGuestByName;
    }

    return [
        'id' => (string)$row['id'],
        'email' => $email,
        'full_name' => $fullName,
        'is_admin' => ((int)($row['is_admin'] ?? 0) === 1),
        'is_guest' => (bool)$isGuest,
        'current_qualification_id' => $row['current_qualification_id'] ?? null,
        'target_qualification_id' => $row['target_qualification_id'] ?? null,
        'onboarding_completed' => ((int)($row['onboarding_completed'] ?? 0) === 1),
        'email_verified' => ((int)($row['email_verified'] ?? 0) === 1),
        'email_verified_at' => $row['email_verified_at'] ?? null,
        'pending_email' => $row['pending_email'] ?? null,
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
    return api_email_exists_anywhere($pdo, $email, null);
}

function api_email_exists_anywhere(PDO $pdo, string $email, ?string $excludeUserId = null): bool
{
    $schema = api_get_profile_schema($pdo);
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $where = ['LOWER(`' . $schema['email'] . '`) = LOWER(?)'];
    $params = [$email];

    if ($schema['pending_email']) {
        $where[] = 'LOWER(`' . $schema['pending_email'] . '`) = LOWER(?)';
        $params[] = $email;
    }

    $sql = 'SELECT COUNT(*) FROM `' . $schema['table'] . '` WHERE (' . implode(' OR ', $where) . ')';

    if ($excludeUserId !== null && $excludeUserId !== '') {
        $sql .= ' AND `' . $schema['id'] . '` <> ?';
        $params[] = $excludeUserId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ((int)$stmt->fetchColumn()) > 0;
}

function api_find_active_user_by_email(PDO $pdo, string $email): ?array
{
    return api_find_active_real_user_by_email($pdo, $email);
}

function api_find_active_real_user_by_email(PDO $pdo, string $email): ?array
{
    $schema = api_get_profile_schema($pdo);
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $where = ['LOWER(`' . $schema['email'] . '`) = LOWER(?)'];
    $params = [$email];

    // Pending/geçici kullanıcıları aktif hesap sayma
    $where[] = 'LOWER(`' . $schema['email'] . '`) NOT LIKE ?';
    $params[] = '%@pending.local';
    $where[] = 'LOWER(`' . $schema['email'] . '`) NOT LIKE ?';
    $params[] = '%@guest.local';

    if ($schema['is_deleted']) {
        $where[] = '`' . $schema['is_deleted'] . '` = 0';
    }

    $orderBy = '1 DESC';
    if ($schema['email_verified']) {
        $orderBy = '`' . $schema['email_verified'] . '` DESC';
    }

    $sql = 'SELECT `' . $schema['id'] . '` AS id, `' . $schema['email'] . '` AS email'
        . ($schema['email_verified'] ? ', `' . $schema['email_verified'] . '` AS email_verified' : ', 0 AS email_verified')
        . ' FROM `' . $schema['table'] . '`'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $orderBy . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (string)($row['id'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'email_verified' => ((int)($row['email_verified'] ?? 0) === 1),
    ];
}

function api_find_pending_signup_by_email(PDO $pdo, string $email): ?array
{
    $schema = api_get_profile_schema($pdo);
    $email = strtolower(trim($email));
    if ($email === '' || !$schema['pending_email']) {
        return null;
    }

    $where = [
        'LOWER(`' . $schema['pending_email'] . '`) = LOWER(?)',
        'LOWER(`' . $schema['email'] . '`) LIKE ?',
    ];
    $params = [$email, '%@pending.local'];

    if ($schema['email_verified']) {
        $where[] = '`' . $schema['email_verified'] . '` = 0';
    }
    if ($schema['is_deleted']) {
        $where[] = '`' . $schema['is_deleted'] . '` = 0';
    }

    $sql = 'SELECT `' . $schema['id'] . '` AS id, `' . $schema['email'] . '` AS email, `' . $schema['pending_email'] . '` AS pending_email'
        . ' FROM `' . $schema['table'] . '`'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY `' . $schema['id'] . '` DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function api_find_pending_guest_convert_by_email(PDO $pdo, string $email): ?array
{
    $schema = api_get_profile_schema($pdo);
    $email = strtolower(trim($email));
    if ($email === '' || !$schema['pending_email']) {
        return null;
    }

    $where = [
        'LOWER(`' . $schema['pending_email'] . '`) = LOWER(?)',
        'LOWER(`' . $schema['email'] . '`) LIKE ?',
    ];
    $params = [$email, '%@guest.local'];

    if ($schema['email_verified']) {
        $where[] = '`' . $schema['email_verified'] . '` = 0';
    }
    if ($schema['is_deleted']) {
        $where[] = '`' . $schema['is_deleted'] . '` = 0';
    }

    $sql = 'SELECT `' . $schema['id'] . '` AS id, `' . $schema['email'] . '` AS email, `' . $schema['pending_email'] . '` AS pending_email'
        . ' FROM `' . $schema['table'] . '`'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY `' . $schema['id'] . '` DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function api_delete_pending_signup(PDO $pdo, string $userId): void
{
    $schema = api_get_profile_schema($pdo);
    $userId = trim($userId);
    if ($userId === '') {
        return;
    }

    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        $otpSchema = api_get_email_verification_schema($pdo);
        $stmtOtp = $pdo->prepare('DELETE FROM `' . $otpSchema['table'] . '` WHERE `' . $otpSchema['user_id'] . '` = ?');
        $stmtOtp->execute([$userId]);

        // api_tokens şeması hazırsa tokenları da temizle
        try {
            api_assert_tokens_table_ready($pdo);
            $stmtTokens = $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?');
            $stmtTokens->execute([$userId]);
        } catch (Throwable $e) {
            // token tablosu eksikliği cleanup akışını kırmamalı
        }

        $stmtUser = $pdo->prepare('DELETE FROM `' . $schema['table'] . '` WHERE `' . $schema['id'] . '` = ?');
        $stmtUser->execute([$userId]);

        if ($startedTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function api_clear_pending_guest_convert(PDO $pdo, string $userId): void
{
    $schema = api_get_profile_schema($pdo);
    $otpSchema = api_get_email_verification_schema($pdo);
    $userId = trim($userId);
    if ($userId === '' || !$schema['pending_email']) {
        return;
    }

    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        $set = ['`' . $schema['pending_email'] . '` = NULL'];
        if ($schema['email_verified']) {
            $set[] = '`' . $schema['email_verified'] . '` = 0';
        }
        if ($schema['email_verified_at']) {
            $set[] = '`' . $schema['email_verified_at'] . '` = NULL';
        }
        if ($schema['updated_at']) {
            $set[] = '`' . $schema['updated_at'] . '` = NOW()';
        }

        $sqlProfile = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set)
            . ' WHERE `' . $schema['id'] . '` = ?';
        $stmtProfile = $pdo->prepare($sqlProfile);
        $stmtProfile->execute([$userId]);

        $sqlOtp = 'DELETE FROM `' . $otpSchema['table'] . '` WHERE `' . $otpSchema['user_id'] . '` = ? AND `' . $otpSchema['purpose'] . '` = ?';
        $stmtOtp = $pdo->prepare($sqlOtp);
        $stmtOtp->execute([$userId, 'guest_convert']);

        if ($startedTx && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
    if ($schema['email_verified']) {
        $addValue($schema['email_verified'], !empty($input['email_verified']) ? 1 : 0);
    }
    if ($schema['email_verified_at']) {
        if (!empty($input['email_verified_at_now'])) {
            $addNow($schema['email_verified_at']);
        } else {
            $addValue($schema['email_verified_at'], $input['email_verified_at'] ?? null);
        }
    }
    if ($schema['pending_email']) {
        $addValue($schema['pending_email'], $input['pending_email'] ?? null);
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
            'email_verified' => (bool)($profile['email_verified'] ?? false),
            'email_verified_at' => $profile['email_verified_at'] ?? null,
            'pending_email' => $profile['pending_email'] ?? null,
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
            'email_verified' => false,
            'email_verified_at' => null,
            'pending_email' => null,
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
        'email_verified' => false,
        'email_verified_at' => null,
        'pending_email' => null,
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

function api_is_guest_user(PDO $pdo, string $userId): bool
{
    $schema = api_get_profile_schema($pdo);

    if ($schema['is_guest']) {
        $sql = 'SELECT `' . $schema['is_guest'] . '` FROM `' . $schema['table'] . '` WHERE `' . $schema['id'] . '` = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $flag = $stmt->fetchColumn();
        return ((int)$flag) === 1;
    }

    // Fallback: guest email/full_name stratejisi
    $fullNameCol = $schema['full_name'] ? ('`' . $schema['full_name'] . '`') : "''";
    $sql = 'SELECT `' . $schema['email'] . '` AS email, ' . $fullNameCol . ' AS full_name FROM `' . $schema['table'] . '` WHERE `' . $schema['id'] . '` = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $email = strtolower(trim((string)($row['email'] ?? '')));
    $fullName = strtolower(trim((string)($row['full_name'] ?? '')));

    $isGuestByEmail = $email !== '' && str_ends_with($email, '@guest.local');
    $isGuestByName = in_array($fullName, ['misafir kullanıcı', 'misafir kullanici', 'guest user'], true);

    return $isGuestByEmail || $isGuestByName;
}

function api_convert_guest_to_registered(PDO $pdo, string $userId, string $fullName, string $email, string $password): void
{
    $schema = api_get_profile_schema($pdo);

    $updates = [];
    $values = [];

    if ($schema['full_name']) {
        $updates[] = '`' . $schema['full_name'] . '` = ?';
        $values[] = $fullName;
    }

    if ($schema['pending_email']) {
        $updates[] = '`' . $schema['pending_email'] . '` = ?';
        $values[] = strtolower($email);
    } else {
        $updates[] = '`' . $schema['email'] . '` = ?';
        $values[] = strtolower($email);
    }

    if ($schema['password']) {
        $updates[] = '`' . $schema['password'] . '` = ?';
        $values[] = hash_password($password);
    }

    if ($schema['email_verified']) {
        $updates[] = '`' . $schema['email_verified'] . '` = 0';
    }

    if ($schema['email_verified_at']) {
        $updates[] = '`' . $schema['email_verified_at'] . '` = NULL';
    }

    if ($schema['is_deleted']) {
        $updates[] = '`' . $schema['is_deleted'] . '` = 0';
    }

    if ($schema['updated_at']) {
        $updates[] = '`' . $schema['updated_at'] . '` = NOW()';
    }

    if (empty($updates)) {
        throw new RuntimeException('Güncellenecek alan bulunamadı.');
    }

    $sql = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $updates) . ' WHERE `' . $schema['id'] . '` = ?';
    $values[] = $userId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function api_get_email_verification_schema(PDO $pdo): array
{
    $columns = get_table_columns($pdo, 'email_verification_codes');
    if (!$columns) {
        throw new RuntimeException('email_verification_codes tablosu okunamadı.');
    }

    $pick = static function (array $candidates, bool $required = true) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        if ($required) {
            throw new RuntimeException('Gerekli OTP kolonu bulunamadı: ' . implode(', ', $candidates));
        }

        return null;
    };

    return [
        'table' => 'email_verification_codes',
        'id' => $pick(['id']),
        'user_id' => $pick(['user_id']),
        'email' => $pick(['email']),
        'purpose' => $pick(['purpose']),
        'code_hash' => $pick(['code_hash']),
        'expires_at' => $pick(['expires_at']),
        'used_at' => $pick(['used_at']),
        'attempt_count' => $pick(['attempt_count'], false),
        'last_sent_at' => $pick(['last_sent_at'], false),
        'created_at' => $pick(['created_at'], false),
    ];
}

function api_generate_email_otp_code(): string
{
    $len = defined('EMAIL_OTP_LENGTH') ? (int)EMAIL_OTP_LENGTH : 6;
    if ($len < 4) {
        $len = 6;
    }

    $max = (10 ** $len) - 1;
    $code = (string)random_int(0, $max);
    return str_pad($code, $len, '0', STR_PAD_LEFT);
}

function api_hash_email_otp(string $code): string
{
    return password_hash($code, PASSWORD_BCRYPT);
}

function api_get_active_email_otp_record(PDO $pdo, string $userId, string $purpose): ?array
{
    $schema = api_get_email_verification_schema($pdo);
    $orderCol = $schema['last_sent_at'] ?: ($schema['created_at'] ?: $schema['expires_at']);
    $sql = 'SELECT * FROM `' . $schema['table'] . '` '
        . 'WHERE `' . $schema['user_id'] . '` = ? '
        . 'AND `' . $schema['purpose'] . '` = ? '
        . 'AND `' . $schema['used_at'] . '` IS NULL '
        . 'ORDER BY `' . $orderCol . '` DESC '
        . 'LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function api_invalidate_email_otp_codes(PDO $pdo, string $userId, string $purpose): void
{
    $schema = api_get_email_verification_schema($pdo);
    $sql = 'UPDATE `' . $schema['table'] . '` '
        . 'SET `' . $schema['used_at'] . '` = NOW() '
        . 'WHERE `' . $schema['user_id'] . '` = ? '
        . 'AND `' . $schema['purpose'] . '` = ? '
        . 'AND `' . $schema['used_at'] . '` IS NULL';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $purpose]);
}

function api_assert_resend_cooldown(PDO $pdo, string $userId, string $purpose): void
{
    $active = api_get_active_email_otp_record($pdo, $userId, $purpose);
    if (!$active) {
        return;
    }

    $lastSent = (string)($active['last_sent_at'] ?? $active['created_at'] ?? '');
    if ($lastSent === '') {
        return;
    }

    $cooldown = defined('EMAIL_OTP_RESEND_COOLDOWN_SECONDS') ? (int)EMAIL_OTP_RESEND_COOLDOWN_SECONDS : 60;
    $remain = (strtotime($lastSent) + $cooldown) - time();
    if ($remain > 0) {
        api_error('Yeniden gönderme için bekleme gerekli. Lütfen ' . $remain . ' saniye sonra tekrar deneyin.', 429);
    }
}

function api_create_email_otp(PDO $pdo, string $userId, string $email, string $purpose): array
{
    api_assert_resend_cooldown($pdo, $userId, $purpose);
    $code = api_generate_email_otp_code();
    $codeHash = api_hash_email_otp($code);

    api_insert_email_otp_record($pdo, $userId, $email, $purpose, $codeHash, true);

    return [
        'code' => $code,
    ];
}

function api_insert_email_otp_record(
    PDO $pdo,
    string $userId,
    string $email,
    string $purpose,
    string $codeHash,
    bool $invalidateExistingFirst = true
): void {
    if ($invalidateExistingFirst) {
        api_invalidate_email_otp_codes($pdo, $userId, $purpose);
    }

    $schema = api_get_email_verification_schema($pdo);

    $columns = [
        '`' . $schema['id'] . '`',
        '`' . $schema['user_id'] . '`',
        '`' . $schema['email'] . '`',
        '`' . $schema['purpose'] . '`',
        '`' . $schema['code_hash'] . '`',
        '`' . $schema['expires_at'] . '`',
        '`' . $schema['used_at'] . '`',
    ];
    $holders = ['?', '?', '?', '?', '?', 'DATE_ADD(NOW(), INTERVAL ' . (int)(defined('EMAIL_OTP_EXPIRY_SECONDS') ? EMAIL_OTP_EXPIRY_SECONDS : 600) . ' SECOND)', 'NULL'];
    $params = [
        generate_uuid(),
        $userId,
        strtolower(trim($email)),
        $purpose,
        $codeHash,
    ];

    if ($schema['attempt_count']) {
        $columns[] = '`' . $schema['attempt_count'] . '`';
        $holders[] = '0';
    }
    if ($schema['last_sent_at']) {
        $columns[] = '`' . $schema['last_sent_at'] . '`';
        $holders[] = 'NOW()';
    }
    if ($schema['created_at']) {
        $columns[] = '`' . $schema['created_at'] . '`';
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO `' . $schema['table'] . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (Throwable $e) {
        throw new RuntimeException('otp_db_insert_failed: ' . $e->getMessage(), 0, $e);
    }

}

function api_get_user_for_email_purpose(PDO $pdo, string $email, string $purpose): ?array
{
    $schema = api_get_profile_schema($pdo);
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    if ($purpose === 'signup') {
        $pending = api_find_pending_signup_by_email($pdo, $email);
        if (!$pending || empty($pending['id'])) {
            return null;
        }
        return api_find_profile_by_user_id($pdo, (string)$pending['id']);
    }

    if ($purpose === 'guest_convert') {
        $pending = api_find_pending_guest_convert_by_email($pdo, $email);
        if (!$pending || empty($pending['id'])) {
            return null;
        }
        return api_find_profile_by_user_id($pdo, (string)$pending['id']);
    } else {
        $sql = 'SELECT `' . $schema['id'] . '` AS id FROM `' . $schema['table'] . '` '
            . 'WHERE LOWER(`' . $schema['email'] . '`) = LOWER(?) LIMIT 1';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return api_find_profile_by_user_id($pdo, (string)$row['id']);
}

function api_get_latest_active_otp_record_by_email_purpose(PDO $pdo, string $email, string $purpose): ?array
{
    $schema = api_get_email_verification_schema($pdo);
    $orderCol = $schema['last_sent_at'] ?: ($schema['created_at'] ?: $schema['expires_at']);

    $sql = 'SELECT * FROM `' . $schema['table'] . '` '
        . 'WHERE LOWER(`' . $schema['email'] . '`) = LOWER(?) '
        . 'AND `' . $schema['purpose'] . '` = ? '
        . 'AND `' . $schema['used_at'] . '` IS NULL '
        . 'ORDER BY `' . $orderCol . '` DESC '
        . 'LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([strtolower(trim($email)), $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function api_find_latest_active_email_otp(PDO $pdo, string $email, string $purpose): ?array
{
    return api_get_latest_active_otp_record_by_email_purpose($pdo, $email, $purpose);
}

function api_email_verification_apply(PDO $pdo, string $userId, string $purpose): void
{
    $schema = api_get_profile_schema($pdo);
    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Kullanıcı bulunamadı.', 404);
    }

    if ($purpose === 'signup') {
        $pending = strtolower(trim((string)($profile['pending_email'] ?? '')));
        if ($pending === '') {
            api_error('Doğrulanacak bekleyen email bulunamadı.', 422);
        }

        if (api_email_exists_anywhere($pdo, $pending, $userId)) {
            api_error('Bu email zaten kayıtlı.', 409);
        }

        $set = ['`' . $schema['email'] . '` = ?'];
        $values = [$pending];
        if ($schema['pending_email']) {
            $set[] = '`' . $schema['pending_email'] . '` = NULL';
        }
        if ($schema['is_guest']) {
            $set[] = '`' . $schema['is_guest'] . '` = 0';
        }
        if ($schema['email_verified']) {
            $set[] = '`' . $schema['email_verified'] . '` = 1';
        }
        if ($schema['email_verified_at']) {
            $set[] = '`' . $schema['email_verified_at'] . '` = NOW()';
        }
        if ($schema['updated_at']) {
            $set[] = '`' . $schema['updated_at'] . '` = NOW()';
        }

        $sql = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set) . ' WHERE `' . $schema['id'] . '` = ?';
        $values[] = $userId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return;
    }

    if ($purpose === 'guest_convert') {
        $pending = strtolower(trim((string)($profile['pending_email'] ?? '')));
        if ($pending === '') {
            api_error('Doğrulanacak bekleyen email bulunamadı.', 422);
        }

        if (api_email_exists_anywhere($pdo, $pending, $userId)) {
            api_error('Bu email zaten kayıtlı.', 409);
        }

        $set = ['`' . $schema['email'] . '` = ?'];
        $values = [$pending];
        if ($schema['pending_email']) {
            $set[] = '`' . $schema['pending_email'] . '` = NULL';
        }
        if ($schema['is_guest']) {
            $set[] = '`' . $schema['is_guest'] . '` = 0';
        }
        if ($schema['email_verified']) {
            $set[] = '`' . $schema['email_verified'] . '` = 1';
        }
        if ($schema['email_verified_at']) {
            $set[] = '`' . $schema['email_verified_at'] . '` = NOW()';
        }
        if ($schema['updated_at']) {
            $set[] = '`' . $schema['updated_at'] . '` = NOW()';
        }

        $sql = 'UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set) . ' WHERE `' . $schema['id'] . '` = ?';
        $values[] = $userId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return;
    }

    // Gelecekte başka purpose eklenirse temel verified güncellemesi
    $updates = [];
    if ($schema['email_verified']) {
        $updates[$schema['email_verified']] = 1;
    }
    api_update_profile_fields($pdo, $userId, $updates);
    if ($schema['email_verified_at']) {
        $sql = 'UPDATE `' . $schema['table'] . '` SET `' . $schema['email_verified_at'] . '` = NOW() WHERE `' . $schema['id'] . '` = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
    }
}

function api_verify_email_otp(PDO $pdo, string $email, string $purpose, string $code): array
{
    $purpose = strtolower(trim($purpose));
    if (!in_array($purpose, ['signup', 'guest_convert'], true)) {
        api_error('Geçersiz doğrulama amacı.', 422);
    }

    $record = api_find_latest_active_email_otp($pdo, $email, $purpose);
    if (!$record) {
        api_error('Aktif OTP kaydı bulunamadı.', 404);
    }

    $profile = api_find_profile_by_user_id($pdo, (string)($record['user_id'] ?? ''));
    if (!$profile) {
        api_error('Doğrulama kaydı bulunamadı.', 404);
    }

    if (!empty($record['used_at'])) {
        api_error('OTP zaten kullanıldı.', 409);
    }

    $attemptCount = (int)($record['attempt_count'] ?? 0);
    $maxAttempts = defined('EMAIL_OTP_MAX_ATTEMPTS') ? (int)EMAIL_OTP_MAX_ATTEMPTS : 5;
    if ($attemptCount >= $maxAttempts) {
        api_error('Çok fazla yanlış deneme yapıldı.', 429);
    }

    $expiresAt = (string)($record['expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) <= time()) {
        api_error('OTP süresi doldu.', 410);
    }

    $schema = api_get_email_verification_schema($pdo);
    $valid = password_verify($code, (string)$record['code_hash']);

    if (!$valid) {
        if ($schema['attempt_count']) {
            $sql = 'UPDATE `' . $schema['table'] . '` SET `' . $schema['attempt_count'] . '` = COALESCE(`' . $schema['attempt_count'] . '`,0) + 1 WHERE `' . $schema['id'] . '` = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([(string)$record['id']]);
        }
        api_error('Geçersiz OTP.', 422);
    }

    $sqlUse = 'UPDATE `' . $schema['table'] . '` SET `' . $schema['used_at'] . '` = NOW() WHERE `' . $schema['id'] . '` = ?';
    $stmtUse = $pdo->prepare($sqlUse);
    $stmtUse->execute([(string)$record['id']]);

    api_email_verification_apply($pdo, (string)$profile['id'], $purpose);

    return api_build_auth_user_payload($pdo, (string)$profile['id']);
}

function api_get_smtp_config(): array
{
    $mailConfig = [];
    $mailConfigPath = dirname(__DIR__, 2) . '/config/mail.php';
    if (is_file($mailConfigPath)) {
        $loaded = require $mailConfigPath;
        if (is_array($loaded)) {
            $mailConfig = $loaded;
        }
    }

    $mailSecure = strtolower(trim((string)($mailConfig['secure'] ?? $mailConfig['encryption'] ?? '')));

    $config = [
        // config/mail.php varsa öncelik ver (testte çalışan ayarlar garanti edilsin)
        'host' => trim((string)($mailConfig['host'] ?? (defined('SMTP_HOST') ? SMTP_HOST : ''))),
        'port' => (int)($mailConfig['port'] ?? (defined('SMTP_PORT') ? SMTP_PORT : 587)),
        'username' => trim((string)($mailConfig['username'] ?? (defined('SMTP_USERNAME') ? SMTP_USERNAME : ''))),
        'password' => (string)($mailConfig['password'] ?? (defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '')),
        'encryption' => $mailSecure !== ''
            ? $mailSecure
            : strtolower(trim((string)(defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'none'))),
        'from_email' => trim((string)($mailConfig['from_email'] ?? (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : ''))),
        'from_name' => trim((string)($mailConfig['from_name'] ?? (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'System'))),
    ];

    if ((int)$config['port'] <= 0) {
        $config['port'] = 587;
    }
    if (!in_array($config['encryption'], ['tls', 'ssl', 'none', ''], true)) {
        $config['encryption'] = 'none';
    }

    if ($config['host'] === '' || $config['from_email'] === '') {
        throw new RuntimeException('config_missing: SMTP yapılandırması eksik.');
    }

    return $config;
}

function api_smtp_expect($socket, array $allowedCodes): string
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line)) {
            break;
        }
    }

    $code = (int)substr(trim($response), 0, 3);
    if (!in_array($code, $allowedCodes, true)) {
        throw new RuntimeException('SMTP unexpected response: ' . trim($response));
    }

    return $response;
}

function api_smtp_cmd($socket, string $cmd, array $allowedCodes): string
{
    fwrite($socket, $cmd . "\r\n");
    return api_smtp_expect($socket, $allowedCodes);
}

function api_get_smtp_debug_meta(): array
{
    $host = defined('SMTP_HOST') ? trim((string)SMTP_HOST) : '';
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 0;
    $encryption = defined('SMTP_ENCRYPTION') ? strtolower(trim((string)SMTP_ENCRYPTION)) : '';

    $mailConfigPath = dirname(__DIR__, 2) . '/config/mail.php';
    if (is_file($mailConfigPath)) {
        $mailConfig = require $mailConfigPath;
        if (is_array($mailConfig)) {
            if (!empty($mailConfig['host'])) {
                $host = trim((string)$mailConfig['host']);
            }
            if (!empty($mailConfig['port'])) {
                $port = (int)$mailConfig['port'];
            }
            if (!empty($mailConfig['secure'])) {
                $encryption = strtolower(trim((string)$mailConfig['secure']));
            } elseif (!empty($mailConfig['encryption'])) {
                $encryption = strtolower(trim((string)$mailConfig['encryption']));
            }
        }
    }

    if ($port <= 0) {
        $port = 587;
    }

    return [
        'smtp_host' => $host,
        'smtp_port' => $port,
        'smtp_encryption' => ($encryption !== '' ? $encryption : 'none'),
    ];
}

function api_send_email_smtp(string $toEmail, string $subject, string $bodyText): void
{
    $cfg = api_get_smtp_config();
    $transportHost = $cfg['encryption'] === 'ssl' ? ('ssl://' . $cfg['host']) : $cfg['host'];

    $step = 'connect_failed';
    $socket = @stream_socket_client(
        $transportHost . ':' . $cfg['port'],
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        throw new RuntimeException('smtp_connect_failed: ' . ($errstr !== '' ? $errstr : ('errno=' . $errno)));
    }

    try {
        stream_set_timeout($socket, 15);

        $step = 'greeting_failed';
        api_smtp_expect($socket, [220]);

        $step = 'ehlo_failed';
        $ehloResponse = api_smtp_cmd($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        $supportsStartTls = (stripos($ehloResponse, 'STARTTLS') !== false);

        if ($cfg['encryption'] === 'tls') {
            if (!$supportsStartTls) {
                throw new RuntimeException('smtp_starttls_failed: sunucu STARTTLS advertise etmiyor.');
            }

            $step = 'starttls_failed';
            api_smtp_cmd($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('smtp_starttls_failed: TLS negotiation başarısız.');
            }

            $step = 'ehlo_failed';
            api_smtp_cmd($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        if ($cfg['username'] !== '') {
            $step = 'auth_failed';
            api_smtp_cmd($socket, 'AUTH LOGIN', [334]);
            api_smtp_cmd($socket, base64_encode($cfg['username']), [334]);
            api_smtp_cmd($socket, base64_encode($cfg['password']), [235]);
        }

        $step = 'mail_from_failed';
        api_smtp_cmd($socket, 'MAIL FROM:<' . $cfg['from_email'] . '>', [250]);

        $step = 'rcpt_to_failed';
        api_smtp_cmd($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);

        $step = 'data_failed';
        api_smtp_cmd($socket, 'DATA', [354]);

    $headers = [
        'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>',
        'To: <' . $toEmail . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $bodyText);
        $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
        // Dot-stuffing: satır başındaki '.' karakterlerini kaçır
        $normalizedBody = preg_replace('/(^|\r\n)\./', '$1..', $normalizedBody) ?? $normalizedBody;

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody . "\r\n.";
        fwrite($socket, $data . "\r\n");
        api_smtp_expect($socket, [250]);

        $step = 'quit';
        api_smtp_cmd($socket, 'QUIT', [221]);
        fclose($socket);
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            @fclose($socket);
        }

        $prefixMap = [
            'greeting_failed' => 'smtp_greeting_failed: ',
            'ehlo_failed' => 'smtp_ehlo_failed: ',
            'starttls_failed' => 'smtp_starttls_failed: ',
            'auth_failed' => 'smtp_auth_failed: ',
            'mail_from_failed' => 'smtp_mail_from_failed: ',
            'rcpt_to_failed' => 'smtp_rcpt_to_failed: ',
            'data_failed' => 'smtp_data_failed: ',
            'quit' => 'smtp_quit_failed: ',
        ];
        $prefix = $prefixMap[$step] ?? 'smtp_send_failed: ';
        throw new RuntimeException($prefix . $e->getMessage());
    }
}

function api_send_email_otp_mail(string $email, string $code, string $purpose): void
{
    $purposeText = $purpose === 'guest_convert' ? 'hesap tamamlama' : 'kayıt doğrulama';
    $expiryMin = (int)round((defined('EMAIL_OTP_EXPIRY_SECONDS') ? EMAIL_OTP_EXPIRY_SECONDS : 600) / 60);
    $subject = 'Denizci Eğitim - Email Doğrulama Kodu';
    $body = "Merhaba,\r\n\r\n"
        . "{$purposeText} işlemi için doğrulama kodunuz: {$code}\r\n"
        . "Bu kod {$expiryMin} dakika geçerlidir ve tek kullanımlıktır.\r\n"
        . "Kodu siz talep etmediyseniz bu emaili dikkate almayın.\r\n\r\n"
        . "Denizci Eğitim";

    api_send_email_smtp($email, $subject, $body);
}

function api_create_and_send_email_otp(PDO $pdo, string $userId, string $email, string $purpose): void
{
    $otp = api_create_email_otp($pdo, $userId, $email, $purpose);
    api_send_email_otp_mail($email, (string)$otp['code'], $purpose);
}

function api_resend_email_otp(PDO $pdo, string $email, string $purpose): array
{
    $purpose = api_validate_email_verification_purpose($purpose);
    $profile = api_get_user_for_email_purpose($pdo, $email, $purpose);
    if (!$profile) {
        api_error('Doğrulama için kullanıcı bulunamadı.', 404);
    }

    $targetEmail = strtolower(trim((string)$email));
    $userId = (string)$profile['id'];

    // Cooldown pass edilirse önce mail gönder, sonra DB'yi güncelle.
    // Önce DB'ye yaz, sonra mail gönder.
    api_assert_resend_cooldown($pdo, $userId, $purpose);

    $code = api_generate_email_otp_code();
    $codeHash = api_hash_email_otp($code);

    try {
        $pdo->beginTransaction();
        api_insert_email_otp_record($pdo, $userId, $targetEmail, $purpose, $codeHash, true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('otp_db_insert_failed: ' . $e->getMessage(), 0, $e);
    }

    try {
        api_send_email_otp_mail($targetEmail, $code, $purpose);
    } catch (Throwable $e) {
        throw new RuntimeException('smtp_send_failed: ' . $e->getMessage(), 0, $e);
    }

    return [
        'user_id' => $userId,
        'email' => $targetEmail,
        'purpose' => $purpose,
    ];
}

function api_validate_email_verification_purpose(string $purpose): string
{
    $purpose = strtolower(trim($purpose));
    if (!in_array($purpose, ['signup', 'guest_convert'], true)) {
        api_error('Geçersiz doğrulama amacı.', 422);
    }
    return $purpose;
}

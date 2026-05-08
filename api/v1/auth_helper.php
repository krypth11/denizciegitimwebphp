<?php

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/user_lifecycle_helper.php';
require_once dirname(__DIR__, 2) . '/includes/upload_helper.php';

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

function api_get_google_allowed_client_ids(): array
{
    return [
        '655007197479-6eng92bdtifnbh4r7nqipbgt4b2icsfn.apps.googleusercontent.com',
        '655007197479-bojaf78fspimij2fev9onnb9k30v8ifn.apps.googleusercontent.com',
    ];
}

function api_fetch_google_tokeninfo(string $idToken): ?array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $status !== 200) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "Accept: application/json\r\n",
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || trim($body) === '') {
        return null;
    }

    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
    }
    if ($status !== 200) {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function api_verify_google_id_token(string $idToken): ?array
{
    $payload = api_fetch_google_tokeninfo($idToken);
    if (!$payload) {
        return null;
    }

    $aud = trim((string)($payload['aud'] ?? ''));
    if ($aud === '' || !in_array($aud, api_get_google_allowed_client_ids(), true)) {
        return null;
    }

    $sub = trim((string)($payload['sub'] ?? ''));
    if ($sub === '') {
        return null;
    }

    $email = strtolower(trim((string)($payload['email'] ?? '')));
    $emailVerifiedRaw = $payload['email_verified'] ?? null;
    $emailVerified = in_array($emailVerifiedRaw, [true, 1, '1', 'true', 'TRUE'], true);

    return [
        'sub' => $sub,
        'email' => $email,
        'email_verified' => $emailVerified,
        'name' => trim((string)($payload['name'] ?? '')),
        'picture' => trim((string)($payload['picture'] ?? '')),
    ];
}

function api_get_user_auth_provider_schema(PDO $pdo): array
{
    $columns = get_table_columns($pdo, 'user_auth_providers');
    if (!$columns) {
        throw new RuntimeException('user_auth_providers tablosu okunamadı.');
    }

    $pick = static function (array $candidates, bool $required = true) use ($columns): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        if ($required) {
            throw new RuntimeException('Gerekli provider kolonu bulunamadı: ' . implode(', ', $candidates));
        }

        return null;
    };

    return [
        'table' => 'user_auth_providers',
        'id' => $pick(['id']),
        'user_id' => $pick(['user_id']),
        'provider' => $pick(['provider']),
        'provider_user_id' => $pick(['provider_user_id']),
        'provider_email' => $pick(['provider_email', 'email'], false),
        'provider_name' => $pick(['provider_name'], false),
        'provider_avatar' => $pick(['provider_avatar', 'provider_picture'], false),
        'last_login_at' => $pick(['last_login_at'], false),
        'created_at' => $pick(['created_at'], false),
        'updated_at' => $pick(['updated_at'], false),
    ];
}

function api_sql_auth_collation(): string
{
    return 'utf8mb4_unicode_ci';
}

function api_sql_collated_utf8_expr(string $expression): string
{
    $collation = api_sql_auth_collation();
    return 'CONVERT(' . $expression . ' USING utf8mb4) COLLATE ' . $collation;
}

function api_sql_collated_lower_utf8_expr(string $expression): string
{
    return 'LOWER(' . api_sql_collated_utf8_expr($expression) . ')';
}

function api_find_user_id_by_auth_provider(PDO $pdo, string $provider, string $providerUserId): ?string
{
    $schema = api_get_user_auth_provider_schema($pdo);
    $providerExpr = api_sql_collated_utf8_expr('`' . $schema['provider'] . '`');
    $providerParamExpr = api_sql_collated_utf8_expr('?');
    $providerUserIdExpr = api_sql_collated_utf8_expr('`' . $schema['provider_user_id'] . '`');
    $providerUserIdParamExpr = api_sql_collated_utf8_expr('?');

    $sql = 'SELECT `' . $schema['user_id'] . '` AS user_id FROM `' . $schema['table'] . '` '
        . 'WHERE ' . $providerExpr . ' = ' . $providerParamExpr
        . ' AND ' . $providerUserIdExpr . ' = ' . $providerUserIdParamExpr . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$provider, $providerUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? (string)$row['user_id'] : null;
}

function api_find_active_user_id_by_auth_provider(PDO $pdo, string $provider, string $providerUserId): ?string
{
    $providerSchema = api_get_user_auth_provider_schema($pdo);
    $profileSchema = api_get_profile_schema($pdo);
    $joinProviderUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['user_id'] . '`');
    $joinProfileIdExpr = api_sql_collated_utf8_expr('u.`' . $profileSchema['id'] . '`');
    $providerExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['provider'] . '`');
    $providerParamExpr = api_sql_collated_utf8_expr('?');
    $providerUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['provider_user_id'] . '`');
    $providerUserIdParamExpr = api_sql_collated_utf8_expr('?');

    $selectDeleted = $profileSchema['is_deleted']
        ? ('`u`.`' . $profileSchema['is_deleted'] . '` AS is_deleted')
        : '0 AS is_deleted';

    $sql = 'SELECT p.`' . $providerSchema['user_id'] . '` AS user_id, ' . $selectDeleted
        . ' FROM `' . $providerSchema['table'] . '` p'
        . ' INNER JOIN `' . $profileSchema['table'] . '` u'
        . ' ON ' . $joinProviderUserIdExpr . ' = ' . $joinProfileIdExpr
        . ' WHERE ' . $providerExpr . ' = ' . $providerParamExpr
        . ' AND ' . $providerUserIdExpr . ' = ' . $providerUserIdParamExpr
        . ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$provider, $providerUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    if (((int)($row['is_deleted'] ?? 0)) === 1) {
        return null;
    }

    return (string)$row['user_id'];
}

function api_cleanup_deleted_auth_provider_binding(PDO $pdo, string $provider, string $providerUserId): void
{
    $providerSchema = api_get_user_auth_provider_schema($pdo);
    $profileSchema = api_get_profile_schema($pdo);
    $joinProviderUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['user_id'] . '`');
    $joinProfileIdExpr = api_sql_collated_utf8_expr('u.`' . $profileSchema['id'] . '`');
    $providerExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['provider'] . '`');
    $providerParamExpr = api_sql_collated_utf8_expr('?');
    $providerUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['provider_user_id'] . '`');
    $providerUserIdParamExpr = api_sql_collated_utf8_expr('?');

    $selectDeleted = $profileSchema['is_deleted']
        ? ('`u`.`' . $profileSchema['is_deleted'] . '` AS is_deleted')
        : '0 AS is_deleted';

    $findSql = 'SELECT p.`' . $providerSchema['id'] . '` AS provider_row_id, ' . $selectDeleted
        . ' FROM `' . $providerSchema['table'] . '` p'
        . ' INNER JOIN `' . $profileSchema['table'] . '` u'
        . ' ON ' . $joinProviderUserIdExpr . ' = ' . $joinProfileIdExpr
        . ' WHERE ' . $providerExpr . ' = ' . $providerParamExpr
        . ' AND ' . $providerUserIdExpr . ' = ' . $providerUserIdParamExpr
        . ' LIMIT 1';

    $findStmt = $pdo->prepare($findSql);
    $findStmt->execute([$provider, $providerUserId]);
    $row = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    if (((int)($row['is_deleted'] ?? 0)) !== 1) {
        return;
    }

    $deleteSql = 'DELETE FROM `' . $providerSchema['table'] . '` WHERE `' . $providerSchema['id'] . '` = ?';
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute([(string)$row['provider_row_id']]);
}

function api_cleanup_deleted_auth_provider_bindings_for_google(PDO $pdo, string $provider, string $providerUserId, string $providerEmail): void
{
    $providerSchema = api_get_user_auth_provider_schema($pdo);
    $profileSchema = api_get_profile_schema($pdo);
    $joinProviderUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['user_id'] . '`');
    $joinProfileIdExpr = api_sql_collated_utf8_expr('u.`' . $profileSchema['id'] . '`');
    $providerExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['provider'] . '`');
    $providerParamExpr = api_sql_collated_utf8_expr('?');
    $providerUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['provider_user_id'] . '`');
    $providerUserIdParamExpr = api_sql_collated_utf8_expr('?');

    $providerEmail = strtolower(trim($providerEmail));

    $whereParts = [
        '(' . $providerExpr . ' = ' . $providerParamExpr . ' AND ' . $providerUserIdExpr . ' = ' . $providerUserIdParamExpr . ')',
    ];
    $params = [$provider, $providerUserId];

    if ($providerSchema['provider_email'] && $providerEmail !== '') {
        $providerEmailExpr = api_sql_collated_lower_utf8_expr('p.`' . $providerSchema['provider_email'] . '`');
        $providerEmailParamExpr = api_sql_collated_lower_utf8_expr('?');
        $whereParts[] = '(' . $providerExpr . ' = ' . $providerParamExpr . ' AND ' . $providerEmailExpr . ' = ' . $providerEmailParamExpr . ')';
        $params[] = $provider;
        $params[] = $providerEmail;
    }

    $deletedCondition = $profileSchema['is_deleted']
        ? ('u.`' . $profileSchema['is_deleted'] . '` = 1')
        : '0 = 1';

    $sql = 'DELETE p FROM `' . $providerSchema['table'] . '` p'
        . ' INNER JOIN `' . $profileSchema['table'] . '` u'
        . ' ON ' . $joinProviderUserIdExpr . ' = ' . $joinProfileIdExpr
        . ' WHERE (' . implode(' OR ', $whereParts) . ')'
        . ' AND ' . $deletedCondition;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function api_create_user_auth_provider(PDO $pdo, string $userId, string $provider, string $providerUserId, array $meta = []): void
{
    $schema = api_get_user_auth_provider_schema($pdo);

    $columns = [
        '`' . $schema['id'] . '`',
        '`' . $schema['user_id'] . '`',
        '`' . $schema['provider'] . '`',
        '`' . $schema['provider_user_id'] . '`',
    ];
    $holders = ['?', '?', '?', '?'];
    $params = [generate_uuid(), $userId, $provider, $providerUserId];

    if ($schema['provider_email']) {
        $columns[] = '`' . $schema['provider_email'] . '`';
        $holders[] = '?';
        $params[] = trim((string)($meta['provider_email'] ?? ''));
    }
    if ($schema['provider_name']) {
        $columns[] = '`' . $schema['provider_name'] . '`';
        $holders[] = '?';
        $params[] = trim((string)($meta['provider_name'] ?? ''));
    }
    if ($schema['provider_avatar']) {
        $columns[] = '`' . $schema['provider_avatar'] . '`';
        $holders[] = '?';
        $params[] = trim((string)($meta['provider_avatar'] ?? ''));
    }
    if ($schema['last_login_at']) {
        $columns[] = '`' . $schema['last_login_at'] . '`';
        $holders[] = 'NOW()';
    }
    if ($schema['created_at']) {
        $columns[] = '`' . $schema['created_at'] . '`';
        $holders[] = 'NOW()';
    }
    if ($schema['updated_at']) {
        $columns[] = '`' . $schema['updated_at'] . '`';
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO `' . $schema['table'] . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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

function api_qualification_access_log(string $stage, array $context = []): void
{
    $line = '[qualification_access][' . $stage . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log($line !== false ? $line : ('[qualification_access][' . $stage . ']'));
}

function api_get_current_user_qualification_id(PDO $pdo, array $auth): ?string
{
    $user = $auth['user'] ?? $auth;
    $userId = trim((string)($user['id'] ?? ''));

    if ($userId === '') {
        return null;
    }

    return get_current_user_qualification_id($pdo, $userId);
}

function get_current_user_qualification_id(PDO $pdo, string $userId): ?string
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        return null;
    }

    $currentQualificationId = trim((string)($profile['current_qualification_id'] ?? ''));
    return $currentQualificationId !== '' ? $currentQualificationId : null;
}

function api_require_current_user_qualification_id(PDO $pdo, array $auth, string $context = 'unknown'): string
{
    $currentQualificationId = api_get_current_user_qualification_id($pdo, $auth);
    $userId = (string)(($auth['user']['id'] ?? $auth['id']) ?? '');

    api_qualification_access_log('requested user id', [
        'context' => $context,
        'user_id' => $userId,
    ]);

    api_qualification_access_log('user current qualification', [
        'context' => $context,
        'user_id' => $userId,
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_qualification_access_log('current qualification resolved', [
        'context' => $context,
        'requested user id' => $userId,
        'current qualification resolved' => $currentQualificationId,
    ]);

    if ($currentQualificationId === null || trim($currentQualificationId) === '') {
        api_error('Current qualification bulunamadı. Önce yeterlilik seçmelisiniz.', 403);
    }

    return $currentQualificationId;
}

function api_assert_requested_qualification_matches_current(
    PDO $pdo,
    array $auth,
    ?string $requestedQualificationId,
    string $context = 'unknown'
): string {
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, $context);
    $requested = trim((string)$requestedQualificationId);

    api_qualification_access_log('requested qualification', [
        'context' => $context,
        'requested_qualification_id' => ($requested !== '' ? $requested : null),
        'current_qualification_id' => $currentQualificationId,
    ]);

    if ($requested !== '' && $requested !== $currentQualificationId) {
        api_qualification_access_log('qualification access rejected', [
            'context' => $context,
            'requested_qualification_id' => $requested,
            'current_qualification_id' => $currentQualificationId,
        ]);
        api_error('Bu yeterlilik için erişim yetkiniz yok.', 403);
    }

    return $currentQualificationId;
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
        'avatar_type' => $pick(['avatar_type'], false),
        'avatar_id' => $pick(['avatar_id'], false),
        'profile_photo_url' => $pick(['profile_photo_url'], false),
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
        $schema['avatar_type'] ? "`{$schema['avatar_type']}` AS avatar_type" : "'default' AS avatar_type",
        $schema['avatar_id'] ? "`{$schema['avatar_id']}` AS avatar_id" : 'NULL AS avatar_id',
        $schema['profile_photo_url'] ? "`{$schema['profile_photo_url']}` AS profile_photo_url" : 'NULL AS profile_photo_url',
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
        'avatar_type' => api_profile_resolve_avatar_type($row['avatar_type'] ?? null),
        'avatar_id' => api_profile_normalize_avatar_id($row['avatar_id'] ?? null),
        'profile_photo_url' => api_profile_normalize_photo_url($row['profile_photo_url'] ?? null),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'current_qualification_name' => $currentName,
    ];
}

function api_profile_default_avatar_ids(): array
{
    $configured = defined('PROFILE_DEFAULT_AVATAR_IDS') && is_array(PROFILE_DEFAULT_AVATAR_IDS)
        ? PROFILE_DEFAULT_AVATAR_IDS
        : [];

    $normalized = [];
    foreach ($configured as $item) {
        $id = api_profile_normalize_avatar_id($item);
        if ($id !== null) {
            $normalized[$id] = true;
        }
    }

    if (empty($normalized)) {
        for ($i = 1; $i <= 20; $i++) {
            $normalized[sprintf('avatar_%02d', $i)] = true;
        }
    }

    return array_keys($normalized);
}

function api_profile_is_allowed_avatar_id($avatarId): bool
{
    return api_profile_normalize_avatar_id($avatarId) !== null;
}

function api_profile_normalize_avatar_id($avatarId): ?string
{
    if ($avatarId === null) {
        return null;
    }

    $value = strtolower(trim((string)$avatarId));
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^(?:avatar[\s_-]*)?(\d{1,2})$/', $value, $matches)) {
        return null;
    }

    $numericId = (int)$matches[1];
    if ($numericId < 1 || $numericId > 20) {
        return null;
    }

    return sprintf('avatar_%02d', $numericId);
}

function api_profile_resolve_avatar_type($avatarType): string
{
    $value = strtolower(trim((string)$avatarType));
    if ($value === 'uploaded') {
        return 'uploaded';
    }

    return 'default';
}

function api_profile_normalize_photo_url($urlOrPath): ?string
{
    $value = trim((string)$urlOrPath);
    if ($value === '') {
        return null;
    }

    $relative = upload_extract_relative_path_from_url_or_path($value);
    if ($relative === '') {
        return $value;
    }

    return upload_build_public_url($relative);
}

function api_profile_assert_avatar_schema_supported(array $profileSchema): void
{
    if (!$profileSchema['avatar_type'] || !$profileSchema['avatar_id']) {
        api_error('Avatar alanları bu sistemde desteklenmiyor.', 400);
    }
}

function api_profile_assert_photo_schema_supported(array $profileSchema): void
{
    if (!$profileSchema['avatar_type'] || !$profileSchema['profile_photo_url']) {
        api_error('Profil fotoğrafı alanları bu sistemde desteklenmiyor.', 400);
    }
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

        user_lifecycle_log_event(
            $pdo,
            $userId,
            'email_verified',
            'Email doğrulandı',
            'auth.verify_email_otp',
            null,
            $pending,
            ['purpose' => 'signup']
        );

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

        user_lifecycle_log_event(
            $pdo,
            $userId,
            'guest_converted_registered',
            'Misafir hesap kayıtlı hesaba dönüştürüldü',
            'auth.verify_email_otp',
            'guest',
            'registered',
            ['purpose' => 'guest_convert', 'email' => $pending]
        );

        user_lifecycle_log_event(
            $pdo,
            $userId,
            'email_verified',
            'Email doğrulandı',
            'auth.verify_email_otp',
            null,
            $pending,
            ['purpose' => 'guest_convert']
        );

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

function api_send_email_smtp(string $toEmail, string $subject, string $bodyText, ?string $bodyHtml = null): void
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
        ];

        $normalizedBodyText = str_replace(["\r\n", "\r"], "\n", $bodyText);
        $normalizedBodyText = str_replace("\n", "\r\n", $normalizedBodyText);

        $normalizedBodyHtml = null;
        if ($bodyHtml !== null && trim($bodyHtml) !== '') {
            $normalizedBodyHtml = str_replace(["\r\n", "\r"], "\n", $bodyHtml);
            $normalizedBodyHtml = str_replace("\n", "\r\n", $normalizedBodyHtml);
        }

        if ($normalizedBodyHtml !== null) {
            $boundary = '=_DenizciEgitim_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

            $messageBody = ''
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $normalizedBodyText . "\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $normalizedBodyHtml . "\r\n"
                . '--' . $boundary . '--';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $messageBody = $normalizedBodyText;
        }

        // Dot-stuffing: satır başındaki '.' karakterlerini kaçır
        $messageBody = preg_replace('/(^|\r\n)\./', '$1..', $messageBody) ?? $messageBody;

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $messageBody . "\r\n.";
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

function api_build_otp_email_template(string $code, string $purpose): array
{
    $normalizedPurpose = strtolower(trim($purpose));
    $expiryMin = (int)round((defined('EMAIL_OTP_EXPIRY_SECONDS') ? EMAIL_OTP_EXPIRY_SECONDS : 600) / 60);
    if ($expiryMin <= 0) {
        $expiryMin = 10;
    }

    $subject = 'Denizci Eğitim – Hesap Doğrulama Kodu';
    $title = 'Hesabınızı doğrulayın';
    $description = 'Denizci Eğitim hesabınızı aktifleştirmek için aşağıdaki kodu kullanın.';

    if ($normalizedPurpose === 'guest_convert') {
        $subject = 'Denizci Eğitim – Hesap Tamamlama Kodu';
        $title = 'Hesabınızı tamamlayın';
        $description = 'Misafir hesabınızı kalıcı hesaba dönüştürmek için aşağıdaki kodu kullanın.';
    } elseif ($normalizedPurpose === 'password_reset') {
        $subject = 'Denizci Eğitim – Şifre Sıfırlama Kodu';
        $title = 'Şifrenizi sıfırlayın';
        $description = 'Şifrenizi yenilemek için aşağıdaki doğrulama kodunu kullanın.';
    }

    $safeCode = htmlspecialchars(trim($code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $text = $title . "\r\n\r\n"
        . $description . "\r\n\r\n"
        . 'Doğrulama kodunuz: ' . trim($code) . "\r\n"
        . 'Bu kod ' . $expiryMin . " dakika geçerlidir ve tek kullanımlıktır.\r\n"
        . 'Bu işlemi siz talep etmediyseniz bu e-postayı dikkate almayın.\r\n\r\n'
        . "Denizci Eğitim\r\n"
        . 'Denizcilik sınavlarına hazırlık platformu';

    $html = '<!doctype html>'
        . '<html lang="tr">'
        . '<head>'
        . '  <meta charset="UTF-8">'
        . '  <meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '  <title>' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
        . '</head>'
        . '<body style="margin:0;padding:0;background:#f1f6ff;background-image:linear-gradient(180deg,#f3f8ff 0%,#e8f1ff 100%);font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
        . '  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f1f6ff;background-image:linear-gradient(180deg,#f3f8ff 0%,#e8f1ff 100%);padding:28px 12px;">'
        . '    <tr>'
        . '      <td align="center">'
        . '        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:620px;background:#ffffff;border-radius:16px;border:1px solid #dbe7ff;box-shadow:0 12px 28px rgba(15,23,42,0.08);overflow:hidden;">'
        . '          <tr>'
        . '            <td align="center" style="padding:28px 24px 12px 24px;">'
        . '              '
        . '              <img src="https://denizciegitim.com/images/logo-dark.png" alt="Denizci Eğitim" width="160" style="display:block; margin:0 auto 16px auto;" />'
        . '            </td>'
        . '          </tr>'
        . '          <tr>'
        . '            <td style="padding:8px 28px 0 28px;text-align:center;">'
        . '              <h1 style="margin:0;font-size:24px;line-height:1.35;color:#0b3a80;font-weight:700;">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
        . '            </td>'
        . '          </tr>'
        . '          <tr>'
        . '            <td style="padding:12px 28px 0 28px;text-align:center;">'
        . '              <p style="margin:0;font-size:15px;line-height:1.65;color:#334155;">' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '            </td>'
        . '          </tr>'
        . '          <tr>'
        . '            <td align="center" style="padding:22px 28px 0 28px;">'
        . '              <div style="display:inline-block;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px 28px;font-size:36px;line-height:1.2;font-weight:800;letter-spacing:8px;color:#0b3a80;">' . $safeCode . '</div>'
        . '            </td>'
        . '          </tr>'
        . '          <tr>'
        . '            <td style="padding:18px 28px 0 28px;text-align:center;">'
        . '              <p style="margin:0;font-size:14px;line-height:1.7;color:#1e3a8a;font-weight:600;">Bu kod ' . $expiryMin . ' dakika geçerlidir ve tek kullanımlıktır.</p>'
        . '            </td>'
        . '          </tr>'
        . '          <tr>'
        . '            <td style="padding:10px 28px 0 28px;text-align:center;">'
        . '              <p style="margin:0;font-size:13px;line-height:1.7;color:#64748b;">Bu işlemi siz talep etmediyseniz bu e-postayı dikkate almayın.</p>'
        . '            </td>'
        . '          </tr>'
        . '          <tr>'
        . '            <td style="padding:22px 28px 28px 28px;text-align:center;">'
        . '              <p style="margin:0;font-size:14px;line-height:1.6;color:#0f172a;font-weight:700;">Denizci Eğitim</p>'
        . '              <p style="margin:2px 0 0 0;font-size:12px;line-height:1.6;color:#64748b;">Denizcilik sınavlarına hazırlık platformu</p>'
        . '            </td>'
        . '          </tr>'
        . '        </table>'
        . '      </td>'
        . '    </tr>'
        . '  </table>'
        . '</body>'
        . '</html>';

    return [
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
    ];
}

function api_send_email_otp_mail(string $email, string $code, string $purpose): void
{
    $template = api_build_otp_email_template($code, $purpose);
    api_send_email_smtp(
        $email,
        (string)$template['subject'],
        (string)$template['text'],
        (string)$template['html']
    );
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
    if (!in_array($purpose, ['signup', 'guest_convert', 'password_reset'], true)) {
        api_error('Geçersiz doğrulama amacı.', 422);
    }
    return $purpose;
}

function api_find_password_reset_user_by_email(PDO $pdo, string $email): ?array
{
    return api_find_active_real_user_by_email($pdo, $email);
}

function api_increment_email_otp_attempt(PDO $pdo, string $recordId): void
{
    $schema = api_get_email_verification_schema($pdo);
    if (!$schema['attempt_count']) {
        return;
    }

    $sql = 'UPDATE `' . $schema['table'] . '` '
        . 'SET `' . $schema['attempt_count'] . '` = COALESCE(`' . $schema['attempt_count'] . '`, 0) + 1 '
        . 'WHERE `' . $schema['id'] . '` = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$recordId]);
}

function api_validate_password_reset_otp(PDO $pdo, string $email, string $code): array
{
    $purpose = 'password_reset';
    $record = api_find_latest_active_email_otp($pdo, $email, $purpose);
    if (!$record) {
        api_error('Aktif şifre sıfırlama kodu bulunamadı.', 404);
    }

    if (!empty($record['used_at'])) {
        api_error('Kod daha önce kullanılmış.', 422);
    }

    $attemptCount = (int)($record['attempt_count'] ?? 0);
    $maxAttempts = defined('EMAIL_OTP_MAX_ATTEMPTS') ? (int)EMAIL_OTP_MAX_ATTEMPTS : 5;
    if ($attemptCount >= $maxAttempts) {
        api_error('Çok fazla yanlış deneme yapıldı.', 429);
    }

    $expiresAt = (string)($record['expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) <= time()) {
        api_error('Kodun süresi doldu.', 422);
    }

    $valid = password_verify($code, (string)($record['code_hash'] ?? ''));
    if (!$valid) {
        api_increment_email_otp_attempt($pdo, (string)$record['id']);
        api_error('Geçersiz kod.', 422);
    }

    return $record;
}

function api_request_password_reset_otp(PDO $pdo, string $email): array
{
    $normalizedEmail = strtolower(trim($email));
    $user = api_find_password_reset_user_by_email($pdo, $normalizedEmail);
    if (!$user || empty($user['id'])) {
        api_error('Bu e-posta adresiyle kayıtlı kullanıcı bulunamadı.', 404);
    }

    $code = api_generate_email_otp_code();
    $codeHash = api_hash_email_otp($code);

    try {
        $pdo->beginTransaction();
        api_insert_email_otp_record($pdo, (string)$user['id'], $normalizedEmail, 'password_reset', $codeHash, true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('otp_db_insert_failed: ' . $e->getMessage(), 0, $e);
    }

    api_send_email_otp_mail($normalizedEmail, $code, 'password_reset');

    return [
        'success' => true,
        'email' => $normalizedEmail,
    ];
}

function api_verify_password_reset_otp(PDO $pdo, string $email, string $code): array
{
    api_validate_password_reset_otp($pdo, $email, $code);

    return [
        'success' => true,
        'reset_allowed' => true,
    ];
}

function api_complete_password_reset(PDO $pdo, string $email, string $code, string $password): array
{
    $normalizedEmail = strtolower(trim($email));
    $user = api_find_password_reset_user_by_email($pdo, $normalizedEmail);
    if (!$user || empty($user['id'])) {
        api_error('Bu e-posta adresiyle kayıtlı kullanıcı bulunamadı.', 404);
    }

    $record = api_validate_password_reset_otp($pdo, $normalizedEmail, $code);
    if ((string)($record['user_id'] ?? '') !== (string)$user['id']) {
        api_error('Kod doğrulaması başarısız.', 422);
    }

    $passwordHash = hash_password($password);
    if (!is_string($passwordHash) || trim($passwordHash) === '') {
        api_error('Şifre güncellenemedi.', 500);
    }

    $profileSchema = api_get_profile_schema($pdo);
    if (!$profileSchema['password']) {
        api_error('Şifre alanı sistemde bulunamadı.', 500);
    }

    $otpSchema = api_get_email_verification_schema($pdo);

    try {
        $pdo->beginTransaction();

        $set = ['`' . $profileSchema['password'] . '` = ?'];
        $params = [$passwordHash];
        if ($profileSchema['updated_at']) {
            $set[] = '`' . $profileSchema['updated_at'] . '` = NOW()';
        }
        $params[] = (string)$user['id'];

        $sqlPassword = 'UPDATE `' . $profileSchema['table'] . '` SET ' . implode(', ', $set)
            . ' WHERE `' . $profileSchema['id'] . '` = ?';
        $stmtPassword = $pdo->prepare($sqlPassword);
        $stmtPassword->execute($params);

        $sqlUse = 'UPDATE `' . $otpSchema['table'] . '` '
            . 'SET `' . $otpSchema['used_at'] . '` = NOW() '
            . 'WHERE `' . $otpSchema['id'] . '` = ? AND `' . $otpSchema['used_at'] . '` IS NULL';
        $stmtUse = $pdo->prepare($sqlUse);
        $stmtUse->execute([(string)$record['id']]);

        $token = api_create_user_token($pdo, (string)$user['id']);
        api_update_last_sign_in($pdo, (string)$user['id']);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, (string)$user['id']),
    ];
}

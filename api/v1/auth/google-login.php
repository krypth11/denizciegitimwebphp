<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$requestId = bin2hex(random_bytes(8));

$googleLog = static function (string $stage, array $context = []) use ($requestId): void {
    $payload = [
        'request_id' => $requestId,
        'stage' => $stage,
        'context' => $context,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[google-login] ' . ($encoded !== false ? $encoded : ('{"request_id":"' . $requestId . '","stage":"' . $stage . '"}')));
};

try {
    $payload = api_get_request_data();
    $idToken = trim((string)($payload['id_token'] ?? ''));

    if ($idToken === '') {
        api_error('id_token zorunludur.', 422);
    }

    $googleLog('token_verify', ['step' => 'start']);
    $google = api_verify_google_id_token($idToken);
    if (!$google) {
        $googleLog('token_verify', ['result' => 'invalid']);
        api_error('Geçersiz Google id_token.', 401);
    }

    $googleSub = trim((string)($google['sub'] ?? ''));
    $googleEmail = strtolower(trim((string)($google['email'] ?? '')));
    $emailVerified = !empty($google['email_verified']);
    $googleName = trim((string)($google['name'] ?? ''));
    $googlePicture = trim((string)($google['picture'] ?? ''));

    if ($googleSub === '' || $googleEmail === '' || !$emailVerified) {
        $googleLog('token_verify', ['result' => 'email_not_verified_or_missing']);
        api_error('Google hesabı email doğrulaması gerekli.', 401);
    }

    $provider = 'google';
    $providerSchema = api_get_user_auth_provider_schema($pdo);
    $lockKey = 'google_login_' . md5($googleSub);
    $lockAcquired = false;

    try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lockStmt->execute([$lockKey]);
        $lockAcquired = ((int)$lockStmt->fetchColumn()) === 1;

        if (!$lockAcquired) {
            $googleLog('response_error', ['reason' => 'lock_timeout']);
            api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
        }

        $touchProviderLastLogin = static function (string $userId) use ($pdo, $providerSchema, $provider, $googleSub): void {
            if (!$providerSchema['last_login_at']) {
                return;
            }

            $userIdExpr = api_sql_collated_utf8_expr('`' . $providerSchema['user_id'] . '`');
            $userIdParamExpr = api_sql_collated_utf8_expr('?');
            $providerExpr = api_sql_collated_utf8_expr('`' . $providerSchema['provider'] . '`');
            $providerParamExpr = api_sql_collated_utf8_expr('?');
            $providerUserIdExpr = api_sql_collated_utf8_expr('`' . $providerSchema['provider_user_id'] . '`');
            $providerUserIdParamExpr = api_sql_collated_utf8_expr('?');

            $sql = 'UPDATE `' . $providerSchema['table'] . '` SET `'
                . $providerSchema['last_login_at'] . '` = NOW() WHERE '
                . $userIdExpr . ' = ' . $userIdParamExpr
                . ' AND ' . $providerExpr . ' = ' . $providerParamExpr
                . ' AND ' . $providerUserIdExpr . ' = ' . $providerUserIdParamExpr
                . ' LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $provider, $googleSub]);
        };

        $googleLog('cleanup_deleted', ['mode' => 'provider_sub_only']);
        api_cleanup_deleted_auth_provider_binding($pdo, $provider, $googleSub);

        $googleLog('provider_lookup', ['step' => 'initial']);
        $resolvedUserId = api_find_active_user_id_by_auth_provider($pdo, $provider, $googleSub);

        if (!$resolvedUserId) {
            $googleLog('email_lookup', ['step' => 'initial']);
            $activeUserByEmail = api_find_active_user_by_email($pdo, $googleEmail);
            if ($activeUserByEmail) {
                $resolvedUserId = (string)($activeUserByEmail['id'] ?? '');
            }
        }

        $newUserId = null;

        if (!$resolvedUserId) {
            $googleLog('user_create', ['step' => 'start']);
            try {
                $newUserId = api_create_user_profile($pdo, [
                    'full_name' => $googleName,
                    'email' => $googleEmail,
                    'password_hash' => api_generate_unusable_password_hash(),
                    'is_admin' => 0,
                    'is_guest' => 0,
                    'email_verified' => 1,
                    'email_verified_at_now' => true,
                ]);
                $resolvedUserId = $newUserId;
            } catch (Throwable $e) {
                if (!api_is_duplicate_error($e)) {
                    throw $e;
                }

                $googleLog('duplicate_retry', ['branch' => 'user_create_duplicate']);
                $activeUserByEmailDup = api_find_active_user_by_email($pdo, $googleEmail);
                if ($activeUserByEmailDup) {
                    $resolvedUserId = (string)($activeUserByEmailDup['id'] ?? '');
                }
            }
        }

        if ($resolvedUserId === '') {
            throw new RuntimeException('İşlem sırasında bir sunucu hatası oluştu.', 500);
        }

        $passwordHashRepaired = api_repair_missing_password_hash_if_empty($pdo, $resolvedUserId);
        if ($passwordHashRepaired) {
            $googleLog('repaired_missing_password_hash', [
                'user_id' => $resolvedUserId,
                'provider' => $provider,
            ]);
        }

        $googleLog('provider_bind', ['step' => 'start']);
        try {
            api_create_user_auth_provider($pdo, $resolvedUserId, $provider, $googleSub, [
                'provider_email' => $googleEmail,
                'provider_name' => $googleName,
                'provider_avatar' => $googlePicture,
            ]);
        } catch (Throwable $e) {
            if (!api_is_duplicate_error($e)) {
                throw $e;
            }

            $googleLog('duplicate_retry', ['branch' => 'provider_bind_duplicate']);
            $boundUserId = api_find_active_user_id_by_auth_provider($pdo, $provider, $googleSub);
            if ($boundUserId) {
                $resolvedUserId = $boundUserId;
            }
        }

        $profileSchema = api_get_profile_schema($pdo);
        $profile = api_find_profile_by_user_id($pdo, $resolvedUserId);
        if ($profile) {
            $updates = [];
            if ($profileSchema['email_verified'] && empty($profile['email_verified'])) {
                $updates[$profileSchema['email_verified']] = 1;
            }
            if ($profileSchema['email_verified_at'] && empty($profile['email_verified_at'])) {
                $updates[$profileSchema['email_verified_at']] = date('Y-m-d H:i:s');
            }
            if ($profileSchema['full_name'] && trim((string)($profile['full_name'] ?? '')) === '' && $googleName !== '') {
                $updates[$profileSchema['full_name']] = $googleName;
            }
            if (!empty($updates)) {
                api_update_profile_fields($pdo, $resolvedUserId, $updates);
            }
        }

        $touchProviderLastLogin($resolvedUserId);
        $token = api_create_user_token($pdo, $resolvedUserId);
        api_update_last_sign_in($pdo, $resolvedUserId);

        $googleLog('response_success', ['user_id' => $resolvedUserId]);
        api_success('Giriş başarılı.', [
            'token' => $token,
            'user' => api_build_auth_user_payload($pdo, $resolvedUserId),
        ]);
    } finally {
        if (isset($lockAcquired) && $lockAcquired) {
            try {
                $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $unlockStmt->execute([$lockKey]);
            } catch (Throwable $unlockError) {
                $googleLog('response_error', ['reason' => 'release_lock_failed', 'error' => $unlockError->getMessage()]);
            }
        }
    }
} catch (Throwable $e) {
    $googleLog('response_error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    if ($debug) {
        api_error('Google login debug hatası.', 500, [
            'request_id' => $requestId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    }

    $status = (int)$e->getCode();
    if ($status >= 400 && $status < 500) {
        api_error($e->getMessage(), $status);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

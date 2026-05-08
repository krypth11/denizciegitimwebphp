<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('POST');

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

try {
    $payload = api_get_request_data();
    $idToken = trim((string)($payload['id_token'] ?? ''));

    if ($idToken === '') {
        api_error('id_token zorunludur.', 422);
    }

    $google = api_verify_google_id_token($idToken);
    if (!$google) {
        api_error('Geçersiz Google id_token.', 401);
    }

    $googleSub = trim((string)($google['sub'] ?? ''));
    $googleEmail = strtolower(trim((string)($google['email'] ?? '')));
    $emailVerified = !empty($google['email_verified']);
    $googleName = trim((string)($google['name'] ?? ''));
    $googlePicture = trim((string)($google['picture'] ?? ''));

    if ($googleSub === '' || $googleEmail === '' || !$emailVerified) {
        api_error('Google hesabı email doğrulaması gerekli.', 401);
    }

    $provider = 'google';
    $providerSchema = api_get_user_auth_provider_schema($pdo);

    api_cleanup_deleted_auth_provider_bindings_for_google($pdo, $provider, $googleSub, $googleEmail);

    $touchProviderLastLogin = static function (string $userId) use ($pdo, $providerSchema, $provider, $googleSub): void {
        if (!$providerSchema['last_login_at']) {
            return;
        }

        $sql = 'UPDATE `' . $providerSchema['table'] . '` SET `'
            . $providerSchema['last_login_at'] . '` = NOW() WHERE `'
            . $providerSchema['user_id'] . '` = ? AND `'
            . $providerSchema['provider'] . '` = ? AND `'
            . $providerSchema['provider_user_id'] . '` = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $provider, $googleSub]);
    };

    $existingUserId = api_find_active_user_id_by_auth_provider($pdo, $provider, $googleSub);
    if ($existingUserId) {
        $touchProviderLastLogin($existingUserId);
        $token = api_create_user_token($pdo, $existingUserId);
        api_update_last_sign_in($pdo, $existingUserId);

        api_success('Giriş başarılı.', [
            'token' => $token,
            'user' => api_build_auth_user_payload($pdo, $existingUserId),
        ]);
    }

    try {
        $pdo->beginTransaction();

        // yarış durumu için transaction içinde provider tekrar kontrolü
        api_cleanup_deleted_auth_provider_bindings_for_google($pdo, $provider, $googleSub, $googleEmail);
        $existingUserIdTx = api_find_active_user_id_by_auth_provider($pdo, $provider, $googleSub);
        if ($existingUserIdTx) {
            $touchProviderLastLogin($existingUserIdTx);
            $token = api_create_user_token($pdo, $existingUserIdTx);
            api_update_last_sign_in($pdo, $existingUserIdTx);
            $pdo->commit();

            api_success('Giriş başarılı.', [
                'token' => $token,
                'user' => api_build_auth_user_payload($pdo, $existingUserIdTx),
            ]);
        }

        $activeUserByEmailTx = api_find_active_user_by_email($pdo, $googleEmail);
        if ($activeUserByEmailTx) {
            $existingUserIdByEmail = (string)($activeUserByEmailTx['id'] ?? '');
            if ($existingUserIdByEmail === '') {
                throw new RuntimeException('İşlem sırasında bir sunucu hatası oluştu.', 500);
            }

            // Mevcut kullanıcıyı Google provider ile eşleştir
            api_create_user_auth_provider($pdo, $existingUserIdByEmail, $provider, $googleSub, [
                'provider_email' => $googleEmail,
                'provider_name' => $googleName,
                'provider_avatar' => $googlePicture,
            ]);

            // Profili doğrulanmış hale getir / gerekirse isim güncelle
            $profileSchema = api_get_profile_schema($pdo);
            $profile = api_find_profile_by_user_id($pdo, $existingUserIdByEmail);
            if (!$profile) {
                throw new RuntimeException('İşlem sırasında bir sunucu hatası oluştu.', 500);
            }

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
                api_update_profile_fields($pdo, $existingUserIdByEmail, $updates);
            }

            $touchProviderLastLogin($existingUserIdByEmail);
            $token = api_create_user_token($pdo, $existingUserIdByEmail);
            api_update_last_sign_in($pdo, $existingUserIdByEmail);
            $pdo->commit();

            api_success('Giriş başarılı.', [
                'token' => $token,
                'user' => api_build_auth_user_payload($pdo, $existingUserIdByEmail),
            ]);
        }

        $newUserId = api_create_user_profile($pdo, [
            'full_name' => $googleName,
            'email' => $googleEmail,
            'password_hash' => '',
            'is_admin' => 0,
            'is_guest' => 0,
            'email_verified' => 1,
            'email_verified_at_now' => true,
            // onboarding_completed verilmez, mevcut sistem defaultu kullanılır
        ]);

        api_create_user_auth_provider($pdo, $newUserId, $provider, $googleSub, [
            'provider_email' => $googleEmail,
            'provider_name' => $googleName,
            'provider_avatar' => $googlePicture,
        ]);

        $token = api_create_user_token($pdo, $newUserId);
        api_update_last_sign_in($pdo, $newUserId);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[google-login][tx] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        $status = (int)$e->getCode();
        if ($status >= 400 && $status < 500) {
            api_error($e->getMessage(), $status);
        }

        if (api_is_duplicate_error($e)) {
            api_cleanup_deleted_auth_provider_bindings_for_google($pdo, $provider, $googleSub, $googleEmail);
            $boundUserId = api_find_active_user_id_by_auth_provider($pdo, $provider, $googleSub);
            if ($boundUserId) {
                $touchProviderLastLogin($boundUserId);
                $token = api_create_user_token($pdo, $boundUserId);
                api_update_last_sign_in($pdo, $boundUserId);

                api_success('Giriş başarılı.', [
                    'token' => $token,
                    'user' => api_build_auth_user_payload($pdo, $boundUserId),
                ]);
            }

            $activeUserByEmail = api_find_active_user_by_email($pdo, $googleEmail);
            if ($activeUserByEmail) {
                $activeUserIdByEmail = (string)($activeUserByEmail['id'] ?? '');
                if ($activeUserIdByEmail !== '') {
                    try {
                        $pdo->beginTransaction();
                        api_cleanup_deleted_auth_provider_bindings_for_google($pdo, $provider, $googleSub, $googleEmail);

                        $boundUserIdTx = api_find_active_user_id_by_auth_provider($pdo, $provider, $googleSub);
                        if (!$boundUserIdTx) {
                            api_create_user_auth_provider($pdo, $activeUserIdByEmail, $provider, $googleSub, [
                                'provider_email' => $googleEmail,
                                'provider_name' => $googleName,
                                'provider_avatar' => $googlePicture,
                            ]);
                        } else {
                            $activeUserIdByEmail = $boundUserIdTx;
                        }

                        $touchProviderLastLogin($activeUserIdByEmail);
                        $token = api_create_user_token($pdo, $activeUserIdByEmail);
                        api_update_last_sign_in($pdo, $activeUserIdByEmail);
                        $pdo->commit();

                        api_success('Giriş başarılı.', [
                            'token' => $token,
                            'user' => api_build_auth_user_payload($pdo, $activeUserIdByEmail),
                        ]);
                    } catch (Throwable $rebindError) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        error_log('[google-login][rebind] ' . $rebindError->getMessage() . ' @ ' . $rebindError->getFile() . ':' . $rebindError->getLine());
                    }
                }
            }

            api_error('Bu e-posta zaten kullanılıyor. Lütfen tekrar deneyin.', 409);
        }

        throw $e;
    }

    api_success('Giriş başarılı.', [
        'token' => $token,
        'user' => api_build_auth_user_payload($pdo, $newUserId),
    ]);
} catch (Throwable $e) {
    error_log('[google-login] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if ($debug) {
        api_error('Google login debug hatası.', 500, [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
        ]);
    }

    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

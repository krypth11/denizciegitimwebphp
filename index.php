<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

function auth_log($message, array $context = [])
{
    if (!empty($context)) {
        error_log('[AUTH][LOGIN] ' . $message . ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        return;
    }
    error_log('[AUTH][LOGIN] ' . $message);
}

function mask_email_for_log($email)
{
    $email = (string)$email;
    if (!str_contains($email, '@')) {
        return 'invalid-email-format';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localMasked = substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2));
    return $localMasked . '@' . $domain;
}

if (is_authenticated_session()) {
    redirect_to('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_log('Form submit alındı', [
        'session_status' => session_status(),
        'has_email_field' => array_key_exists('email', $_POST),
        'has_password_field' => array_key_exists('password', $_POST),
    ]);

    $emailRaw = $_POST['email'] ?? '';
    $passwordRaw = $_POST['password'] ?? '';
    $email = trim((string)$emailRaw);
    $password = (string)$passwordRaw;

    auth_log('POST alanları okundu', [
        'email_length_raw' => strlen((string)$emailRaw),
        'email_length_trimmed' => strlen($email),
        'email_masked' => mask_email_for_log($email),
        'password_length' => strlen($password),
    ]);

    $now = time();
    $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        $wait = $lockedUntil - $now;
        auth_log('Rate limit aktif, login geçici kilitli', ['wait_seconds' => $wait]);
        $error = 'Email veya şifre hatalı.';
    }

    if (!isset($error)) {
        if (empty($email) || empty($password)) {
            auth_log('Validasyon başarısız: boş email veya password');
            $error = 'Email veya şifre hatalı.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            auth_log('Validasyon başarısız: email formatı geçersiz', [
                'email_masked' => mask_email_for_log($email),
            ]);
            $error = 'Email veya şifre hatalı.';
        } else {
            $upCols = get_table_columns($pdo, 'user_profiles');
            $passwordColumn = null;
            if (in_array('password_hash', $upCols, true)) {
                $passwordColumn = 'password_hash';
            } else {
                foreach (['hashed_password', 'password', 'pass_hash', 'passwd'] as $candidate) {
                    if (in_array($candidate, $upCols, true)) {
                        $passwordColumn = $candidate;
                        break;
                    }
                }
            }

            if (!$passwordColumn) {
                auth_log('Login kırılma nedeni: user_profiles içinde şifre kolonu bulunamadı', [
                    'available_columns' => $upCols,
                ]);
                $error = 'Email veya şifre hatalı.';
            } else {
                $sql = 'SELECT id, email, is_admin, ' . $passwordColumn . ' AS password_hash FROM user_profiles WHERE LOWER(email) = LOWER(?)';
                if (in_array('is_deleted', $upCols, true)) {
                    $sql .= ' AND is_deleted = 0';
                }
                $sql .= ' LIMIT 1';

                auth_log('Kullanıcı sorgusu hazırlanıyor', [
                    'email_masked' => mask_email_for_log($email),
                    'password_column' => $passwordColumn,
                    'has_is_deleted' => in_array('is_deleted', $upCols, true),
                ]);

                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                } catch (Throwable $e) {
                    auth_log('Login kırılma nedeni: user_profiles sorgusu hata verdi', [
                        'error' => $e->getMessage(),
                    ]);
                    $user = false;
                }
            }

            $isValidLogin = false;
            $isAdmin = false;
            if ($user) {
                auth_log('Kullanıcı bulundu', [
                    'user_id' => $user['id'] ?? null,
                    'email_masked' => mask_email_for_log($user['email'] ?? ''),
                    'is_admin' => (int)($user['is_admin'] ?? 0),
                    'has_password_hash' => !empty($user['password_hash']),
                    'password_hash_length' => strlen((string)($user['password_hash'] ?? '')),
                ]);

                $hashRaw = (string)($user['password_hash'] ?? '');
                $hash = trim($hashRaw);
                if ($hashRaw !== $hash) {
                    auth_log('password_hash trimlendi', [
                        'raw_length' => strlen($hashRaw),
                        'trimmed_length' => strlen($hash),
                    ]);
                }

                $isAdmin = ((int)($user['is_admin'] ?? 0) === 1);

                if (!$isAdmin) {
                    auth_log('Login reddedildi: admin değil', [
                        'user_id' => $user['id'] ?? null,
                    ]);
                } elseif ($hash === '') {
                    auth_log('Login reddedildi: password_hash boş', [
                        'user_id' => $user['id'] ?? null,
                    ]);
                } else {
                    $verifyResult = verify_password($password, $hash);
                    auth_log('password_verify sonucu', [
                        'user_id' => $user['id'] ?? null,
                        'result' => $verifyResult,
                    ]);

                    if ($verifyResult) {
                        $isValidLogin = true;
                    } else {
                        auth_log('Login reddedildi: password_verify false', [
                            'user_id' => $user['id'] ?? null,
                        ]);
                    }
                }
            } else {
                auth_log('Login reddedildi: kullanıcı bulunamadı', [
                    'email_masked' => mask_email_for_log($email),
                ]);
            }

            if (!$isValidLogin) {
                $_SESSION['login_attempt_count'] = (int)($_SESSION['login_attempt_count'] ?? 0) + 1;
                $_SESSION['login_last_attempt'] = $now;

                if ($_SESSION['login_attempt_count'] >= 5) {
                    $_SESSION['login_locked_until'] = $now + 300;
                    $_SESSION['login_attempt_count'] = 0;
                }

                $error = 'Email veya şifre hatalı.';
            } else {
                $sessionRegenerated = session_regenerate_id(true);
                auth_log('session_regenerate_id çağrıldı', [
                    'result' => $sessionRegenerated,
                    'session_status' => session_status(),
                ]);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['is_admin'] = 1;
                $_SESSION['last_activity'] = $now;
                $_SESSION['user_agent'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

                auth_log('Session alanları yazıldı', [
                    'has_user_id' => isset($_SESSION['user_id']),
                    'has_email' => isset($_SESSION['email']),
                    'has_is_admin' => isset($_SESSION['is_admin']),
                ]);

                unset($_SESSION['login_attempt_count'], $_SESSION['login_last_attempt'], $_SESSION['login_locked_until']);

                $token = create_token($user['id'], $user['email'], true);
                set_auth_cookie($token);

                $pdo->prepare('UPDATE user_profiles SET last_sign_in_at = NOW() WHERE id = ?')->execute([$user['id']]);
                auth_log('Redirect öncesi session kontrol', [
                    'session_id_exists' => session_id() !== '',
                    'has_user_id' => isset($_SESSION['user_id']),
                    'has_email' => isset($_SESSION['email']),
                    'has_is_admin' => isset($_SESSION['is_admin']),
                ]);
                redirect_to('/dashboard.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş - <?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #B3D9E8 0%, #FFFFFF 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 40px;
            max-width: 450px;
            width: 100%;
        }
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 30px;
            background: #5B9BD5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">DE</div>
        <h3 class="text-center mb-4">Admin Panel Girişi</h3>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Şifre</label>
                <input type="password" class="form-control" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Giriş Yap</button>
        </form>
    </div>
</body>
</html>

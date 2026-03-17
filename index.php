<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (is_authenticated_session()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $now = time();
    $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > $now) {
        $wait = $lockedUntil - $now;
        $error = 'Çok fazla başarısız deneme. Lütfen ' . $wait . ' saniye sonra tekrar deneyin.';
    }

    if (!isset($error)) {
        if (empty($email) || empty($password)) {
            $error = 'Email ve şifre gerekli!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Geçerli bir email adresi girin.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, email, is_admin, password_hash
                 FROM user_profiles
                 WHERE email = ? AND is_deleted = 0
                 LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            $isValidLogin = false;
            $isAdmin = false;
            if ($user) {
                $hash = $user['password_hash'] ?? '';
                $isAdmin = ((int)($user['is_admin'] ?? 0) === 1);

                if ($isAdmin && verify_password($password, $hash)) {
                    $isValidLogin = true;
                }
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
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['is_admin'] = 1;
                $_SESSION['last_activity'] = $now;
                $_SESSION['user_agent'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

                unset($_SESSION['login_attempt_count'], $_SESSION['login_last_attempt'], $_SESSION['login_locked_until']);

                $token = create_token($user['id'], $user['email'], true);
                set_auth_cookie($token);

                $pdo->prepare('UPDATE user_profiles SET last_sign_in_at = NOW() WHERE id = ?')->execute([$user['id']]);

                header('Location: dashboard.php');
                exit;
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

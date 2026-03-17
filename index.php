<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (verify_token()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email ve şifre gerekli!';
    } else {
        $stmt = $pdo->prepare(
            "SELECT up.id, up.email, up.is_admin, au.user_id as admin_check
             FROM user_profiles up
             LEFT JOIN admin_users au ON up.id = au.user_id
             WHERE up.email = ? AND up.is_deleted = 0
             LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Kullanıcı bulunamadı!';
        } else {
            $is_admin = ((int)$user['is_admin'] === 1 || !empty($user['admin_check']));

            if (!$is_admin) {
                $error = 'Admin yetkisi gerekli!';
            } else {
                // Geçici şifre kontrolü: herhangi bir şifre kabul
                $token = create_token($user['id'], $user['email'], true);

                setcookie('auth_token', $token, [
                    'expires' => time() + JWT_EXPIRY,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['is_admin'] = true;

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

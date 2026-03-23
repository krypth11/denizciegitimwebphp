<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$authUser = require_admin();

function users_response($success, $message = '', $data = [], $status = 200, $errors = [])
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function users_pick_column(array $columns, array $candidates, $required = true)
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) return $candidate;
    }
    if ($required) throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    return null;
}

function users_schema(PDO $pdo)
{
    $cols = get_table_columns($pdo, 'user_profiles');
    if (empty($cols)) {
        throw new RuntimeException('user_profiles tablosu okunamadı.');
    }

    return [
        'table' => 'user_profiles',
        'id' => users_pick_column($cols, ['id']),
        'full_name' => users_pick_column($cols, ['full_name', 'name', 'display_name'], false),
        'email' => users_pick_column($cols, ['email']),
        'is_admin' => users_pick_column($cols, ['is_admin']),
        'password' => users_pick_column($cols, ['password_hash', 'hashed_password', 'password', 'pass_hash', 'passwd']),
        'created_at' => users_pick_column($cols, ['created_at', 'created_on'], false),
        'updated_at' => users_pick_column($cols, ['updated_at', 'updated_on'], false),
        'last_sign_in_at' => users_pick_column($cols, ['last_sign_in_at', 'last_login_at'], false),
        'is_deleted' => users_pick_column($cols, ['is_deleted'], false),
        'deleted_at' => users_pick_column($cols, ['deleted_at'], false),
    ];
}

function users_bool($value)
{
    return in_array((string)$value, ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

function users_now()
{
    return date('Y-m-d H:i:s');
}

function users_select_clause(array $schema)
{
    $select = [
        "`{$schema['id']}` AS id",
        "`{$schema['email']}` AS email",
        "`{$schema['is_admin']}` AS is_admin",
    ];

    $select[] = $schema['full_name'] ? "`{$schema['full_name']}` AS full_name" : "'' AS full_name";
    $select[] = $schema['created_at'] ? "`{$schema['created_at']}` AS created_at" : 'NULL AS created_at';
    $select[] = $schema['updated_at'] ? "`{$schema['updated_at']}` AS updated_at" : 'NULL AS updated_at';
    $select[] = $schema['last_sign_in_at'] ? "`{$schema['last_sign_in_at']}` AS last_sign_in_at" : 'NULL AS last_sign_in_at';
    $select[] = $schema['is_deleted'] ? "`{$schema['is_deleted']}` AS is_deleted" : '0 AS is_deleted';

    return implode(', ', $select);
}

function users_admin_count(PDO $pdo, array $schema)
{
    $sql = "SELECT COUNT(*) FROM `{$schema['table']}` WHERE `{$schema['is_admin']}` = 1";
    if ($schema['is_deleted']) {
        $sql .= " AND `{$schema['is_deleted']}` = 0";
    }
    return (int)$pdo->query($sql)->fetchColumn();
}

function users_find_by_id(PDO $pdo, array $schema, $id)
{
    $sql = 'SELECT ' . users_select_clause($schema) . " FROM `{$schema['table']}` WHERE `{$schema['id']}` = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $schema = users_schema($pdo);
    $currentUserId = (string)($authUser['user_id'] ?? ($_SESSION['user_id'] ?? ''));

    switch ($action) {
        case 'list': {
            $search = trim((string)($_GET['search'] ?? ''));
            $role = trim((string)($_GET['role'] ?? 'all'));
            $status = trim((string)($_GET['status'] ?? 'all'));

            $where = ['1=1'];
            $params = [];

            if ($search !== '') {
                $fullNameSql = $schema['full_name'] ? "`{$schema['full_name']}`" : "''";
                $where[] = "({$fullNameSql} LIKE ? OR `{$schema['email']}` LIKE ?)";
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }

            if ($role === 'admin') {
                $where[] = "`{$schema['is_admin']}` = 1";
            } elseif ($role === 'user') {
                $where[] = "`{$schema['is_admin']}` = 0";
            }

            if ($schema['is_deleted']) {
                if ($status === 'active') {
                    $where[] = "`{$schema['is_deleted']}` = 0";
                } elseif ($status === 'passive') {
                    $where[] = "`{$schema['is_deleted']}` = 1";
                }
            }

            $orderCol = $schema['created_at'] ?: $schema['id'];
            $sql = 'SELECT ' . users_select_clause($schema)
                . " FROM `{$schema['table']}`"
                . ' WHERE ' . implode(' AND ', $where)
                . " ORDER BY `{$orderCol}` DESC LIMIT 500";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $normalized = array_map(function ($u) {
                return [
                    'id' => (string)($u['id'] ?? ''),
                    'full_name' => (string)($u['full_name'] ?? ''),
                    'email' => (string)($u['email'] ?? ''),
                    'is_admin' => ((int)($u['is_admin'] ?? 0) === 1) ? 1 : 0,
                    'is_deleted' => ((int)($u['is_deleted'] ?? 0) === 1) ? 1 : 0,
                    'created_at' => $u['created_at'] ?? null,
                    'updated_at' => $u['updated_at'] ?? null,
                    'last_sign_in_at' => $u['last_sign_in_at'] ?? null,
                ];
            }, $users);

            users_response(true, '', ['users' => $normalized, 'current_user_id' => $currentUserId]);
            break;
        }

        case 'get': {
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            }

            $user = users_find_by_id($pdo, $schema, $id);
            if (!$user) {
                users_response(false, 'Kullanıcı bulunamadı.', [], 404);
            }

            users_response(true, '', ['user' => [
                'id' => (string)$user['id'],
                'full_name' => (string)($user['full_name'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'is_admin' => ((int)($user['is_admin'] ?? 0) === 1) ? 1 : 0,
                'is_deleted' => ((int)($user['is_deleted'] ?? 0) === 1) ? 1 : 0,
            ]]);
            break;
        }

        case 'add': {
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            $isAdmin = users_bool($_POST['is_admin'] ?? 0);

            if ($fullName === '') users_response(false, 'Ad Soyad zorunludur.', [], 422, ['full_name' => 'required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) users_response(false, 'Geçerli bir email giriniz.', [], 422, ['email' => 'invalid']);
            if (mb_strlen($password) < 6) users_response(false, 'Şifre en az 6 karakter olmalıdır.', [], 422, ['password' => 'min_6']);
            if ($password !== $passwordConfirm) users_response(false, 'Şifre tekrarı eşleşmiyor.', [], 422, ['password_confirm' => 'mismatch']);

            $checkSql = "SELECT COUNT(*) FROM `{$schema['table']}` WHERE LOWER(`{$schema['email']}`) = LOWER(?)";
            $checkParams = [$email];
            if ($schema['is_deleted']) {
                $checkSql .= " AND `{$schema['is_deleted']}` = 0";
            }
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute($checkParams);
            if ((int)$stmt->fetchColumn() > 0) {
                users_response(false, 'Bu email adresi zaten kayıtlı.', [], 422, ['email' => 'duplicate']);
            }

            $payload = [
                $schema['email'] => $email,
                $schema['is_admin'] => $isAdmin,
                $schema['password'] => hash_password($password),
            ];

            if ($schema['full_name']) $payload[$schema['full_name']] = $fullName;
            if ($schema['is_deleted']) $payload[$schema['is_deleted']] = 0;
            if ($schema['created_at']) $payload[$schema['created_at']] = users_now();
            if ($schema['updated_at']) $payload[$schema['updated_at']] = users_now();

            $metaStmt = $pdo->query("SHOW COLUMNS FROM `{$schema['table']}`");
            $metaRows = $metaStmt->fetchAll(PDO::FETCH_ASSOC);
            $idMeta = null;
            foreach ($metaRows as $mr) {
                if (($mr['Field'] ?? '') === $schema['id']) {
                    $idMeta = $mr;
                    break;
                }
            }
            $isAuto = $idMeta && str_contains(strtolower((string)($idMeta['Extra'] ?? '')), 'auto_increment');
            if (!$isAuto) {
                $payload[$schema['id']] = generate_uuid();
            }

            $cols = array_keys($payload);
            $holders = implode(', ', array_fill(0, count($cols), '?'));
            $quoted = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $sql = "INSERT INTO `{$schema['table']}` ({$quoted}) VALUES ({$holders})";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($payload));

            users_response(true, 'Kullanıcı eklendi.');
            break;
        }

        case 'update': {
            $id = trim((string)($_POST['id'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            $isAdmin = users_bool($_POST['is_admin'] ?? 0);

            if ($id === '') users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            if ($fullName === '') users_response(false, 'Ad Soyad zorunludur.', [], 422, ['full_name' => 'required']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) users_response(false, 'Geçerli bir email giriniz.', [], 422, ['email' => 'invalid']);

            $target = users_find_by_id($pdo, $schema, $id);
            if (!$target) users_response(false, 'Kullanıcı bulunamadı.', [], 404);

            $checkSql = "SELECT COUNT(*) FROM `{$schema['table']}` WHERE LOWER(`{$schema['email']}`) = LOWER(?) AND `{$schema['id']}` <> ?";
            $checkParams = [$email, $id];
            if ($schema['is_deleted']) {
                $checkSql .= " AND `{$schema['is_deleted']}` = 0";
            }
            $stmt = $pdo->prepare($checkSql);
            $stmt->execute($checkParams);
            if ((int)$stmt->fetchColumn() > 0) {
                users_response(false, 'Bu email başka bir kullanıcıda kayıtlı.', [], 422, ['email' => 'duplicate']);
            }

            $wasAdmin = ((int)($target['is_admin'] ?? 0) === 1);
            if ($wasAdmin && $isAdmin === 0) {
                $adminCount = users_admin_count($pdo, $schema);
                if ($adminCount <= 1) {
                    users_response(false, 'Sistemde en az bir admin kalmalıdır.', [], 422, ['is_admin' => 'last_admin']);
                }
                if ((string)$id === (string)$currentUserId) {
                    users_response(false, 'Kendi admin yetkinizi buradan kaldıramazsınız.', [], 422, ['is_admin' => 'self_downgrade']);
                }
            }

            $updates = [
                $schema['email'] => $email,
                $schema['is_admin'] => $isAdmin,
            ];
            if ($schema['full_name']) $updates[$schema['full_name']] = $fullName;
            if ($schema['updated_at']) $updates[$schema['updated_at']] = users_now();

            if ($password !== '' || $passwordConfirm !== '') {
                if (mb_strlen($password) < 6) users_response(false, 'Yeni şifre en az 6 karakter olmalıdır.', [], 422, ['password' => 'min_6']);
                if ($password !== $passwordConfirm) users_response(false, 'Şifre tekrarı eşleşmiyor.', [], 422, ['password_confirm' => 'mismatch']);
                $updates[$schema['password']] = hash_password($password);
            }

            $set = [];
            $vals = [];
            foreach ($updates as $col => $val) {
                $set[] = "`{$col}` = ?";
                $vals[] = $val;
            }
            $vals[] = $id;

            $sql = "UPDATE `{$schema['table']}` SET " . implode(', ', $set) . " WHERE `{$schema['id']}` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($vals);

            users_response(true, 'Kullanıcı güncellendi.');
            break;
        }

        case 'delete': {
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') users_response(false, 'ID gerekli.', [], 422, ['id' => 'required']);
            if ((string)$id === (string)$currentUserId) {
                users_response(false, 'Kendi hesabınızı silemezsiniz.', [], 422, ['id' => 'self_delete']);
            }

            $target = users_find_by_id($pdo, $schema, $id);
            if (!$target) users_response(false, 'Kullanıcı bulunamadı.', [], 404);

            $isTargetAdmin = ((int)($target['is_admin'] ?? 0) === 1);
            if ($isTargetAdmin) {
                $adminCount = users_admin_count($pdo, $schema);
                if ($adminCount <= 1) {
                    users_response(false, 'Son admin kullanıcı silinemez.', [], 422, ['id' => 'last_admin']);
                }
            }

            if ($schema['is_deleted']) {
                $updates = [
                    "`{$schema['is_deleted']}` = 1",
                ];
                $vals = [];

                if ($schema['updated_at']) {
                    $updates[] = "`{$schema['updated_at']}` = ?";
                    $vals[] = users_now();
                }
                if ($schema['deleted_at']) {
                    $updates[] = "`{$schema['deleted_at']}` = ?";
                    $vals[] = users_now();
                }

                $vals[] = $id;
                $sql = "UPDATE `{$schema['table']}` SET " . implode(', ', $updates) . " WHERE `{$schema['id']}` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($vals);
            } else {
                $stmt = $pdo->prepare("DELETE FROM `{$schema['table']}` WHERE `{$schema['id']}` = ?");
                $stmt->execute([$id]);
            }

            users_response(true, 'Kullanıcı silindi.');
            break;
        }

        default:
            users_response(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    users_response(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

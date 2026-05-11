<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/kart_game_helper.php';

try {
    require_admin();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function kg_lb_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function kg_lb_parse_non_negative_int($value, string $field, ?int $min = 0, ?int $max = null): array
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return ['ok' => false, 'message' => $field . ' zorunludur.'];
    }
    if (!preg_match('/^\d+$/', $raw)) {
        return ['ok' => false, 'message' => $field . ' geçersizdir.'];
    }

    if (strlen($raw) > 19 || (strlen($raw) === 19 && strcmp($raw, '9223372036854775807') > 0)) {
        return ['ok' => false, 'message' => $field . ' bigint sınırını aşıyor.'];
    }

    $num = (int)$raw;
    if ($min !== null && $num < $min) {
        return ['ok' => false, 'message' => $field . ' minimum ' . $min . ' olmalıdır.'];
    }
    if ($max !== null && $num > $max) {
        return ['ok' => false, 'message' => $field . ' maksimum ' . $max . ' olmalıdır.'];
    }

    return ['ok' => true, 'value' => $num];
}

function kg_lb_resolve_level_from_xp(PDO $pdo, int $totalXp): int
{
    $stmt = $pdo->prepare('SELECT level_number FROM kart_game_level_config WHERE required_total_xp <= ? ORDER BY required_total_xp DESC LIMIT 1');
    $stmt->execute([$totalXp]);
    $level = (int)$stmt->fetchColumn();
    return $level > 0 ? $level : 1;
}

function kg_lb_validate_payload(array $payload, bool $isCreate = true): array
{
    $errors = [];
    $data = [];

    if ($isCreate) {
        $userId = trim((string)($payload['user_id'] ?? ''));
        $categoryId = trim((string)($payload['category_id'] ?? ''));
        if ($userId === '') $errors['user_id'] = 'Kullanıcı zorunludur.';
        if ($categoryId === '') $errors['category_id'] = 'Kategori zorunludur.';
        $data['user_id'] = $userId;
        $data['category_id'] = $categoryId;
    }

    $map = [
        'total_xp' => ['label' => 'Total XP', 'min' => 0],
        'current_level' => ['label' => 'Current Level', 'min' => 1],
        'total_correct' => ['label' => 'Total Correct', 'min' => 0],
        'total_wrong' => ['label' => 'Total Wrong', 'min' => 0],
        'best_combo' => ['label' => 'Best Combo', 'min' => 0],
        'best_score' => ['label' => 'Best Score', 'min' => 0],
        'total_games' => ['label' => 'Total Games', 'min' => 0],
    ];

    foreach ($map as $key => $rule) {
        $parsed = kg_lb_parse_non_negative_int($payload[$key] ?? null, $rule['label'], $rule['min']);
        if (!($parsed['ok'] ?? false)) {
            $errors[$key] = (string)$parsed['message'];
            continue;
        }
        $data[$key] = (int)$parsed['value'];
    }

    return ['valid' => empty($errors), 'errors' => $errors, 'data' => $data];
}

function kg_lb_profile_schema(PDO $pdo): array
{
    $columns = function_exists('get_table_columns') ? get_table_columns($pdo, 'user_profiles') : [];
    $columns = is_array($columns) ? $columns : [];

    $pick = static function (array $cands, bool $required = true) use ($columns): ?string {
        foreach ($cands as $c) {
            if (in_array($c, $columns, true)) return $c;
        }
        if ($required) throw new RuntimeException('Beklenen user_profiles kolonu bulunamadı.');
        return null;
    };

    return [
        'id' => $pick(['id']),
        'full_name' => $pick(['full_name', 'name', 'display_name'], false),
        'email' => $pick(['email'], false),
    ];
}

try {
    switch ($action) {
        case 'list':
            $categories = kg_list_categories_for_mapping($pdo);
            $categoryId = trim((string)($_GET['category_id'] ?? ''));
            $items = [];

            if ($categoryId !== '') {
                if (!kg_get_category($pdo, $categoryId)) {
                    kg_lb_json(false, 'Geçerli kategori zorunludur.', [], 422);
                }

                $profile = kg_lb_profile_schema($pdo);
                $nameExpr = $profile['full_name'] ? 'NULLIF(TRIM(u.`' . $profile['full_name'] . '`), \'\')' : 'NULL';
                $emailExpr = $profile['email'] ? 'u.`' . $profile['email'] . '`' : '\'\'';
                $sql = 'SELECT p.*, COALESCE(' . $nameExpr . ', ' . $emailExpr . ', p.user_id) AS username '
                    . 'FROM kart_game_user_progress p '
                    . 'LEFT JOIN user_profiles u ON u.`' . $profile['id'] . '` = p.user_id '
                    . 'WHERE p.category_id = ? '
                    . 'ORDER BY p.total_xp DESC, p.best_score DESC, p.best_combo DESC LIMIT 200';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$categoryId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($items as $idx => &$it) {
                    $it['rank'] = $idx + 1;
                    $it['current_level'] = kg_lb_resolve_level_from_xp($pdo, (int)$it['total_xp']);
                }
                unset($it);
            }

            kg_lb_json(true, '', ['categories' => $categories, 'items' => $items]);
            break;

        case 'options':
            $profile = kg_lb_profile_schema($pdo);
            $fullNameExpr = $profile['full_name'] ? 'COALESCE(NULLIF(TRIM(`' . $profile['full_name'] . '`), \'\'), \'\')' : '\'\'';
            $emailExpr = $profile['email'] ? 'COALESCE(`' . $profile['email'] . '`, \'\')' : '\'\'';

            $usersSql = 'SELECT `' . $profile['id'] . '` AS id, ' . $fullNameExpr . ' AS full_name, ' . $emailExpr . ' AS email FROM user_profiles ORDER BY ' . $fullNameExpr . ' ASC, ' . $emailExpr . ' ASC LIMIT 5000';
            $users = $pdo->query($usersSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $categories = [];
            $catStmt = $pdo->query('SELECT id, title, slug FROM kart_game_categories ORDER BY title ASC');
            $catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($catRows as $c) {
                $categories[] = ['id' => $c['id'], 'name' => $c['title'], 'slug' => $c['slug']];
            }

            kg_lb_json(true, '', ['users' => $users, 'categories' => $categories]);
            break;

        case 'get':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') kg_lb_json(false, 'ID zorunludur.', [], 422);
            $stmt = $pdo->prepare('SELECT * FROM kart_game_user_progress WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) kg_lb_json(false, 'Kayıt bulunamadı.', [], 404);
            $item['current_level'] = kg_lb_resolve_level_from_xp($pdo, (int)$item['total_xp']);
            kg_lb_json(true, '', ['item' => $item]);
            break;

        case 'create':
            $v = kg_lb_validate_payload($_POST, true);
            if (!$v['valid']) {
                kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            }

            $d = $v['data'];

            if (!kg_get_category($pdo, $d['category_id'])) {
                kg_lb_json(false, 'Geçerli kategori zorunludur.', ['errors' => ['category_id' => 'Geçerli kategori zorunludur.']], 422);
            }

            $existsUser = $pdo->prepare('SELECT COUNT(*) FROM user_profiles WHERE id = ?');
            $existsUser->execute([$d['user_id']]);
            if ((int)$existsUser->fetchColumn() < 1) {
                kg_lb_json(false, 'Geçerli kullanıcı zorunludur.', ['errors' => ['user_id' => 'Geçerli kullanıcı zorunludur.']], 422);
            }

            $dup = $pdo->prepare('SELECT id FROM kart_game_user_progress WHERE user_id = ? AND category_id = ? LIMIT 1');
            $dup->execute([$d['user_id'], $d['category_id']]);
            if ($dup->fetchColumn()) {
                kg_lb_json(false, 'Bu kullanıcı için bu kategoride zaten leaderboard kaydı var.', ['errors' => ['user_id' => 'Mükerrer kayıt.']], 422);
            }

            $currentLevel = kg_lb_resolve_level_from_xp($pdo, (int)$d['total_xp']);
            $id = generate_uuid();
            $stmt = $pdo->prepare('INSERT INTO kart_game_user_progress (id, user_id, category_id, total_xp, current_level, total_correct, total_wrong, best_combo, best_score, total_games, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([
                $id,
                $d['user_id'],
                $d['category_id'],
                $d['total_xp'],
                $currentLevel,
                $d['total_correct'],
                $d['total_wrong'],
                $d['best_combo'],
                $d['best_score'],
                $d['total_games'],
            ]);

            kg_lb_json(true, 'Leaderboard kaydı eklendi.');
            break;

        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lb_json(false, 'ID zorunludur.', [], 422);

            $v = kg_lb_validate_payload($_POST, false);
            if (!$v['valid']) {
                kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            }
            $d = $v['data'];

            $stmt = $pdo->prepare('SELECT id FROM kart_game_user_progress WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                kg_lb_json(false, 'Kayıt bulunamadı.', [], 404);
            }

            $currentLevel = kg_lb_resolve_level_from_xp($pdo, (int)$d['total_xp']);
            $upd = $pdo->prepare('UPDATE kart_game_user_progress SET total_xp = ?, current_level = ?, total_correct = ?, total_wrong = ?, best_combo = ?, best_score = ?, total_games = ?, updated_at = NOW() WHERE id = ?');
            $upd->execute([
                $d['total_xp'],
                $currentLevel,
                $d['total_correct'],
                $d['total_wrong'],
                $d['best_combo'],
                $d['best_score'],
                $d['total_games'],
                $id,
            ]);

            kg_lb_json(true, 'Leaderboard kaydı güncellendi.');
            break;

        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lb_json(false, 'ID zorunludur.', [], 422);
            $del = $pdo->prepare('DELETE FROM kart_game_user_progress WHERE id = ?');
            $del->execute([$id]);
            if ($del->rowCount() < 1) {
                kg_lb_json(false, 'Kayıt bulunamadı.', [], 404);
            }
            kg_lb_json(true, 'Leaderboard kaydı silindi.');
            break;

        default:
            kg_lb_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    kg_lb_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

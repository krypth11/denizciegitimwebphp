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
    if ($raw === '') return ['ok' => false, 'message' => $field . ' zorunludur.'];
    if (!preg_match('/^\d+$/', $raw)) return ['ok' => false, 'message' => $field . ' geçersizdir.'];
    if (strlen($raw) > 19 || (strlen($raw) === 19 && strcmp($raw, '9223372036854775807') > 0)) {
        return ['ok' => false, 'message' => $field . ' bigint sınırını aşıyor.'];
    }
    $num = (int)$raw;
    if ($min !== null && $num < $min) return ['ok' => false, 'message' => $field . ' minimum ' . $min . ' olmalıdır.'];
    if ($max !== null && $num > $max) return ['ok' => false, 'message' => $field . ' maksimum ' . $max . ' olmalıdır.'];
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
        foreach ($cands as $c) if (in_array($c, $columns, true)) return $c;
        if ($required) throw new RuntimeException('Beklenen user_profiles kolonu bulunamadı.');
        return null;
    };

    return [
        'id' => $pick(['id']),
        'full_name' => $pick(['full_name', 'name', 'display_name'], false),
        'email' => $pick(['email'], false),
    ];
}

function kg_lb_validate_season_payload(PDO $pdo, array $payload): array
{
    $errors = [];
    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $seasonId = trim((string)($payload['season_id'] ?? ''));
    $title = trim((string)($payload['title'] ?? ''));
    $resetAt = trim((string)($payload['reset_at'] ?? ''));
    $isActiveRaw = (string)($payload['is_active'] ?? '');

    if ($categoryId === '' || !kg_get_category($pdo, $categoryId)) $errors['category_id'] = 'Geçerli kategori zorunludur.';
    if ($title === '') $errors['title'] = 'Sezon adı zorunludur.';
    if ($resetAt === '' || strtotime($resetAt) === false) $errors['reset_at'] = 'Geçerli bir sıfırlanma tarihi giriniz.';
    if (!in_array($isActiveRaw, ['0', '1'], true)) $errors['is_active'] = 'is_active 0 veya 1 olmalıdır.';

    if ($seasonId !== '') {
        $stmt = $pdo->prepare('SELECT id, category_id FROM kart_game_leaderboard_seasons WHERE id = ? LIMIT 1');
        $stmt->execute([$seasonId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $errors['season_id'] = 'Sezon kaydı bulunamadı.';
        } elseif ($categoryId !== '' && (string)$row['category_id'] !== $categoryId) {
            $errors['season_id'] = 'Sezon kategori ile eşleşmiyor.';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'category_id' => $categoryId,
            'season_id' => $seasonId,
            'title' => $title,
            'reset_at' => $resetAt,
            'is_active' => ($isActiveRaw === '1' ? 1 : 0),
        ],
    ];
}

function kg_lb_validate_reward_payload(PDO $pdo, array $payload, bool $isUpdate = false): array
{
    $errors = [];
    $id = trim((string)($payload['id'] ?? ''));
    $seasonId = trim((string)($payload['season_id'] ?? ''));
    $rankStart = filter_var($payload['rank_start'] ?? null, FILTER_VALIDATE_INT);
    $rankEnd = filter_var($payload['rank_end'] ?? null, FILTER_VALIDATE_INT);
    $rewardTitle = trim((string)($payload['reward_title'] ?? ''));
    $rewardDescription = trim((string)($payload['reward_description'] ?? ''));
    $isActiveRaw = (string)($payload['is_active'] ?? '');
    $sortOrder = filter_var($payload['sort_order'] ?? null, FILTER_VALIDATE_INT);

    if ($isUpdate && $id === '') $errors['id'] = 'ID zorunludur.';
    if ($seasonId === '') $errors['season_id'] = 'Sezon zorunludur.';
    if ($rankStart === false || $rankStart < 1) $errors['rank_start'] = 'rank_start minimum 1 olmalıdır.';
    if ($rankEnd === false || $rankEnd < 1) $errors['rank_end'] = 'rank_end minimum 1 olmalıdır.';
    if ($rankStart !== false && $rankEnd !== false && $rankEnd < $rankStart) $errors['rank_end'] = 'rank_end, rank_start değerinden küçük olamaz.';
    if ($rewardTitle === '') $errors['reward_title'] = 'Ödül başlığı zorunludur.';
    if (!in_array($isActiveRaw, ['0', '1'], true)) $errors['is_active'] = 'is_active 0 veya 1 olmalıdır.';
    if ($sortOrder === false || $sortOrder < 0) $errors['sort_order'] = 'sort_order minimum 0 olmalıdır.';

    if ($seasonId !== '') {
        $stmt = $pdo->prepare('SELECT id FROM kart_game_leaderboard_seasons WHERE id = ? LIMIT 1');
        $stmt->execute([$seasonId]);
        if (!$stmt->fetchColumn()) $errors['season_id'] = 'Sezon kaydı bulunamadı.';
    }

    if ($isUpdate && $id !== '') {
        $stmt = $pdo->prepare('SELECT id, season_id FROM kart_game_leaderboard_rewards WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $errors['id'] = 'Ödül kaydı bulunamadı.';
        } elseif ($seasonId !== '' && (string)$existing['season_id'] !== $seasonId) {
            $errors['season_id'] = 'Ödül sezonu değiştirilemez.';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'id' => $id,
            'season_id' => $seasonId,
            'rank_start' => (int)$rankStart,
            'rank_end' => (int)$rankEnd,
            'reward_title' => $rewardTitle,
            'reward_description' => $rewardDescription,
            'is_active' => ($isActiveRaw === '1' ? 1 : 0),
            'sort_order' => (int)$sortOrder,
        ],
    ];
}

function kg_lb_check_reward_overlap(PDO $pdo, string $seasonId, int $rankStart, int $rankEnd, ?string $excludeId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM kart_game_leaderboard_rewards WHERE season_id = ? AND is_active = 1 AND NOT (rank_end < ? OR rank_start > ?)';
    $params = [$seasonId, $rankStart, $rankEnd];
    if ($excludeId !== null && $excludeId !== '') {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ((int)$stmt->fetchColumn()) > 0;
}

try {
    switch ($action) {
        case 'list':
            $categories = kg_list_categories_for_mapping($pdo);
            $categoryId = trim((string)($_GET['category_id'] ?? ''));
            $items = [];
            if ($categoryId !== '') {
                if (!kg_get_category($pdo, $categoryId)) kg_lb_json(false, 'Geçerli kategori zorunludur.', [], 422);
                $profile = kg_lb_profile_schema($pdo);
                $nameExpr = $profile['full_name'] ? 'NULLIF(TRIM(u.`' . $profile['full_name'] . '`), \'\')' : 'NULL';
                $emailExpr = $profile['email'] ? 'u.`' . $profile['email'] . '`' : '\'\'';
                $sql = 'SELECT p.*, COALESCE(' . $nameExpr . ', ' . $emailExpr . ', p.user_id) AS username FROM kart_game_user_progress p LEFT JOIN user_profiles u ON u.`' . $profile['id'] . '` = p.user_id WHERE p.category_id = ? ORDER BY p.total_xp DESC, p.best_score DESC, p.best_combo DESC LIMIT 200';
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
            $catRows = $pdo->query('SELECT id, title, slug FROM kart_game_categories ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $categories = [];
            foreach ($catRows as $c) $categories[] = ['id' => $c['id'], 'name' => $c['title'], 'slug' => $c['slug']];
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
            if (!$v['valid']) kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            $d = $v['data'];
            if (!kg_get_category($pdo, $d['category_id'])) kg_lb_json(false, 'Geçerli kategori zorunludur.', ['errors' => ['category_id' => 'Geçerli kategori zorunludur.']], 422);
            $existsUser = $pdo->prepare('SELECT COUNT(*) FROM user_profiles WHERE id = ?');
            $existsUser->execute([$d['user_id']]);
            if ((int)$existsUser->fetchColumn() < 1) kg_lb_json(false, 'Geçerli kullanıcı zorunludur.', ['errors' => ['user_id' => 'Geçerli kullanıcı zorunludur.']], 422);
            $dup = $pdo->prepare('SELECT id FROM kart_game_user_progress WHERE user_id = ? AND category_id = ? LIMIT 1');
            $dup->execute([$d['user_id'], $d['category_id']]);
            if ($dup->fetchColumn()) kg_lb_json(false, 'Bu kullanıcı için bu kategoride zaten leaderboard kaydı var.', ['errors' => ['user_id' => 'Mükerrer kayıt.']], 422);
            $currentLevel = kg_lb_resolve_level_from_xp($pdo, (int)$d['total_xp']);
            $id = generate_uuid();
            $stmt = $pdo->prepare('INSERT INTO kart_game_user_progress (id, user_id, category_id, total_xp, current_level, total_correct, total_wrong, best_combo, best_score, total_games, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$id, $d['user_id'], $d['category_id'], $d['total_xp'], $currentLevel, $d['total_correct'], $d['total_wrong'], $d['best_combo'], $d['best_score'], $d['total_games']]);
            kg_lb_json(true, 'Leaderboard kaydı eklendi.');
            break;

        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lb_json(false, 'ID zorunludur.', [], 422);
            $v = kg_lb_validate_payload($_POST, false);
            if (!$v['valid']) kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            $d = $v['data'];
            $stmt = $pdo->prepare('SELECT id FROM kart_game_user_progress WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) kg_lb_json(false, 'Kayıt bulunamadı.', [], 404);
            $currentLevel = kg_lb_resolve_level_from_xp($pdo, (int)$d['total_xp']);
            $upd = $pdo->prepare('UPDATE kart_game_user_progress SET total_xp = ?, current_level = ?, total_correct = ?, total_wrong = ?, best_combo = ?, best_score = ?, total_games = ?, updated_at = NOW() WHERE id = ?');
            $upd->execute([$d['total_xp'], $currentLevel, $d['total_correct'], $d['total_wrong'], $d['best_combo'], $d['best_score'], $d['total_games'], $id]);
            kg_lb_json(true, 'Leaderboard kaydı güncellendi.');
            break;

        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lb_json(false, 'ID zorunludur.', [], 422);
            $del = $pdo->prepare('DELETE FROM kart_game_user_progress WHERE id = ?');
            $del->execute([$id]);
            if ($del->rowCount() < 1) kg_lb_json(false, 'Kayıt bulunamadı.', [], 404);
            kg_lb_json(true, 'Leaderboard kaydı silindi.');
            break;

        case 'get_rewards_config':
            $categoryId = trim((string)($_GET['category_id'] ?? ''));
            if ($categoryId === '' || !kg_get_category($pdo, $categoryId)) kg_lb_json(false, 'Geçerli kategori zorunludur.', [], 422);
            $season = kg_get_active_leaderboard_season($pdo, $categoryId);
            $rewards = [];
            if ($season) {
                $stmt = $pdo->prepare('SELECT id, season_id, rank_start, rank_end, reward_title, reward_description, is_active, sort_order FROM kart_game_leaderboard_rewards WHERE season_id = ? ORDER BY rank_start ASC, rank_end ASC, sort_order ASC');
                $stmt->execute([(string)$season['id']]);
                $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            kg_lb_json(true, '', ['season' => $season ?: null, 'rewards' => $rewards]);
            break;

        case 'save_season':
            $v = kg_lb_validate_season_payload($pdo, $_POST);
            if (!$v['valid']) kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            $d = $v['data'];
            $pdo->beginTransaction();
            try {
                if ($d['is_active'] === 1) {
                    $stmt = $pdo->prepare('UPDATE kart_game_leaderboard_seasons SET is_active = 0, updated_at = NOW() WHERE category_id = ?');
                    $stmt->execute([$d['category_id']]);
                }
                if ($d['season_id'] !== '') {
                    $stmt = $pdo->prepare('UPDATE kart_game_leaderboard_seasons SET title = ?, reset_at = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                    $stmt->execute([$d['title'], $d['reset_at'], $d['is_active'], $d['season_id']]);
                    $seasonId = $d['season_id'];
                } else {
                    $seasonId = generate_uuid();
                    $stmt = $pdo->prepare('INSERT INTO kart_game_leaderboard_seasons (id, category_id, title, reset_at, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
                    $stmt->execute([$seasonId, $d['category_id'], $d['title'], $d['reset_at'], $d['is_active']]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $stmt = $pdo->prepare('SELECT id, category_id, title, reset_at, is_active FROM kart_game_leaderboard_seasons WHERE id = ? LIMIT 1');
            $stmt->execute([$seasonId]);
            kg_lb_json(true, 'Sezon kaydedildi.', ['season' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null]);
            break;

        case 'create_reward':
            $v = kg_lb_validate_reward_payload($pdo, $_POST, false);
            if (!$v['valid']) kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            $d = $v['data'];
            if ($d['is_active'] === 1 && kg_lb_check_reward_overlap($pdo, $d['season_id'], $d['rank_start'], $d['rank_end'], null)) {
                kg_lb_json(false, 'Sıra aralığı aktif bir ödülle çakışıyor.', ['errors' => ['rank_start' => 'Sıra aralığı çakışıyor.']], 422);
            }
            $id = generate_uuid();
            $stmt = $pdo->prepare('INSERT INTO kart_game_leaderboard_rewards (id, season_id, rank_start, rank_end, reward_title, reward_description, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([$id, $d['season_id'], $d['rank_start'], $d['rank_end'], $d['reward_title'], $d['reward_description'], $d['is_active'], $d['sort_order']]);
            kg_lb_json(true, 'Ödül kaydı eklendi.');
            break;

        case 'update_reward':
            $v = kg_lb_validate_reward_payload($pdo, $_POST, true);
            if (!$v['valid']) kg_lb_json(false, 'Doğrulama başarısız.', ['errors' => $v['errors']], 422);
            $d = $v['data'];
            if ($d['is_active'] === 1 && kg_lb_check_reward_overlap($pdo, $d['season_id'], $d['rank_start'], $d['rank_end'], $d['id'])) {
                kg_lb_json(false, 'Sıra aralığı aktif bir ödülle çakışıyor.', ['errors' => ['rank_start' => 'Sıra aralığı çakışıyor.']], 422);
            }
            $stmt = $pdo->prepare('UPDATE kart_game_leaderboard_rewards SET rank_start = ?, rank_end = ?, reward_title = ?, reward_description = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$d['rank_start'], $d['rank_end'], $d['reward_title'], $d['reward_description'], $d['is_active'], $d['sort_order'], $d['id']]);
            kg_lb_json(true, 'Ödül kaydı güncellendi.');
            break;

        case 'delete_reward':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lb_json(false, 'ID zorunludur.', [], 422);
            $stmt = $pdo->prepare('DELETE FROM kart_game_leaderboard_rewards WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() < 1) kg_lb_json(false, 'Ödül kaydı bulunamadı.', [], 404);
            kg_lb_json(true, 'Ödül kaydı silindi.');
            break;

        default:
            kg_lb_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    kg_lb_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

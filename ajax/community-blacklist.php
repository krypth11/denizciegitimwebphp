<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/community_helper.php';

require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function cb_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $bl = community_blacklist_schema($pdo);

    if ($action === 'list') {
        $sql = "SELECT `{$bl['id']}` AS id, `{$bl['term']}` AS term, "
            . ($bl['match_type'] ? "`{$bl['match_type']}` AS match_type, " : "'contains' AS match_type, ")
            . ($bl['is_active'] ? "`{$bl['is_active']}` AS is_active, " : "1 AS is_active, ")
            . ($bl['created_at'] ? "`{$bl['created_at']}` AS created_at" : 'NULL AS created_at')
            . " FROM `{$bl['table']}` ORDER BY `{$bl['term']}` ASC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        cb_json(true, '', ['terms' => $rows]);
    }

    if ($action === 'get') {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') cb_json(false, 'ID gerekli.', [], 422);

        $sql = "SELECT `{$bl['id']}` AS id, `{$bl['term']}` AS term, "
            . ($bl['match_type'] ? "`{$bl['match_type']}` AS match_type, " : "'contains' AS match_type, ")
            . ($bl['is_active'] ? "`{$bl['is_active']}` AS is_active" : '1 AS is_active')
            . " FROM `{$bl['table']}` WHERE `{$bl['id']}` = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) cb_json(false, 'Kayıt bulunamadı.', [], 404);
        cb_json(true, '', ['term' => $row]);
    }

    if ($action === 'add') {
        $term = sanitize_input($_POST['term'] ?? '');
        $matchType = strtolower(trim((string)($_POST['match_type'] ?? 'contains')));
        $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($term === '') cb_json(false, 'Kelime zorunludur.', [], 422);
        if (!in_array($matchType, ['contains', 'exact'], true)) $matchType = 'contains';

        $cols = [$bl['id'], $bl['term']];
        $vals = [generate_uuid(), $term];
        if ($bl['match_type']) {
            $cols[] = $bl['match_type'];
            $vals[] = $matchType;
        }
        if ($bl['is_active']) {
            $cols[] = $bl['is_active'];
            $vals[] = $isActive;
        }
        if ($bl['created_at']) {
            $cols[] = $bl['created_at'];
            $vals[] = community_now();
        }
        if ($bl['updated_at']) {
            $cols[] = $bl['updated_at'];
            $vals[] = community_now();
        }

        $quoted = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $holders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $pdo->prepare("INSERT INTO `{$bl['table']}` ({$quoted}) VALUES ({$holders})");
        $stmt->execute($vals);
        cb_json(true, 'Blacklist kelime eklendi.');
    }

    if ($action === 'update') {
        $id = trim((string)($_POST['id'] ?? ''));
        $term = sanitize_input($_POST['term'] ?? '');
        $matchType = strtolower(trim((string)($_POST['match_type'] ?? 'contains')));
        $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

        if ($id === '' || $term === '') cb_json(false, 'ID ve kelime zorunludur.', [], 422);
        if (!in_array($matchType, ['contains', 'exact'], true)) $matchType = 'contains';

        $set = ["`{$bl['term']}` = ?"];
        $vals = [$term];
        if ($bl['match_type']) {
            $set[] = "`{$bl['match_type']}` = ?";
            $vals[] = $matchType;
        }
        if ($bl['is_active']) {
            $set[] = "`{$bl['is_active']}` = ?";
            $vals[] = $isActive;
        }
        if ($bl['updated_at']) {
            $set[] = "`{$bl['updated_at']}` = ?";
            $vals[] = community_now();
        }
        $vals[] = $id;

        $stmt = $pdo->prepare("UPDATE `{$bl['table']}` SET " . implode(', ', $set) . " WHERE `{$bl['id']}` = ?");
        $stmt->execute($vals);
        cb_json(true, 'Blacklist kelime güncellendi.');
    }

    if ($action === 'delete') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') cb_json(false, 'ID gerekli.', [], 422);
        $stmt = $pdo->prepare("DELETE FROM `{$bl['table']}` WHERE `{$bl['id']}` = ?");
        $stmt->execute([$id]);
        cb_json(true, 'Blacklist kelime silindi.');
    }

    cb_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    cb_json(false, 'İşlem sırasında bir hata oluştu.', [], 500);
}

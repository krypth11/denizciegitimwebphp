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

try {
    if ($action !== 'list') {
        kg_lb_json(false, 'Geçersiz işlem.', [], 400);
    }

    $categories = kg_list_categories_for_mapping($pdo);
    $categoryId = trim((string)($_GET['category_id'] ?? ''));
    $items = [];
    if ($categoryId !== '') {
        if (!kg_get_category($pdo, $categoryId)) {
            kg_lb_json(false, 'Geçerli kategori zorunludur.', [], 422);
        }

        $items = kg_get_leaderboard($pdo, $categoryId, 200);
        foreach ($items as &$it) {
            $progress = kg_get_user_progress($pdo, (string)$it['user_id'], $categoryId) ?: [];
            $it['total_games'] = (int)($progress['total_games'] ?? 0);
            $it['category_id'] = $categoryId;
        }
        unset($it);
    }

    kg_lb_json(true, '', ['categories' => $categories, 'items' => $items]);
} catch (Throwable $e) {
    kg_lb_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

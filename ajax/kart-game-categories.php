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

function kg_cat_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'list':
            $items = kg_list_categories($pdo, [
                'search' => (string)($_GET['search'] ?? ''),
                'is_active' => (string)($_GET['is_active'] ?? ''),
            ]);
            kg_cat_json(true, '', ['items' => $items]);
            break;

        case 'get':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                kg_cat_json(false, 'ID zorunludur.', [], 422);
            }
            $item = kg_get_category($pdo, $id);
            if (!$item) {
                kg_cat_json(false, 'Kayıt bulunamadı.', [], 404);
            }
            $counts = kg_category_relation_counts($pdo, $id);
            kg_cat_json(true, '', ['item' => $item, 'relation_counts' => $counts]);
            break;

        case 'create':
            $res = kg_create_category($pdo, [
                'title' => $_POST['title'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'is_active' => $_POST['is_active'] ?? 0,
                'sort_order' => $_POST['sort_order'] ?? 0,
            ]);
            if (!($res['success'] ?? false)) {
                kg_cat_json(false, (string)($res['message'] ?? 'Kayıt başarısız.'), ['errors' => $res['errors'] ?? []], 422);
            }
            kg_cat_json(true, 'Başlık eklendi.', ['item' => $res['item'] ?? null]);
            break;

        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                kg_cat_json(false, 'ID zorunludur.', [], 422);
            }
            $res = kg_update_category($pdo, $id, [
                'title' => $_POST['title'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'is_active' => $_POST['is_active'] ?? 0,
                'sort_order' => $_POST['sort_order'] ?? 0,
            ]);
            if (!($res['success'] ?? false)) {
                $status = isset($res['errors']['id']) ? 404 : 422;
                kg_cat_json(false, (string)($res['message'] ?? 'Güncelleme başarısız.'), ['errors' => $res['errors'] ?? []], $status);
            }
            kg_cat_json(true, 'Başlık güncellendi.', ['item' => $res['item'] ?? null]);
            break;

        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                kg_cat_json(false, 'ID zorunludur.', [], 422);
            }
            $item = kg_get_category($pdo, $id);
            if (!$item) {
                kg_cat_json(false, 'Kayıt bulunamadı.', [], 404);
            }

            $deleted = kg_delete_category($pdo, $id);
            if (!$deleted) {
                kg_cat_json(false, 'Silme başarısız.', [], 500);
            }
            kg_cat_json(true, 'Başlık silindi.');
            break;

        default:
            kg_cat_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    kg_cat_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

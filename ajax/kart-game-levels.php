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

function kg_lvl_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'list':
            kg_lvl_json(true, '', ['items' => kg_list_level_configs($pdo)]);
            break;

        case 'get':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') kg_lvl_json(false, 'ID zorunludur.', [], 422);
            $item = kg_get_level_config($pdo, $id);
            if (!$item) kg_lvl_json(false, 'Kayıt bulunamadı.', [], 404);
            kg_lvl_json(true, '', ['item' => $item]);
            break;

        case 'create':
            $res = kg_create_level_config($pdo, [
                'level_number' => $_POST['level_number'] ?? null,
                'required_total_xp' => $_POST['required_total_xp'] ?? null,
            ]);
            if (!($res['success'] ?? false)) {
                kg_lvl_json(false, (string)($res['message'] ?? 'Kayıt başarısız.'), ['errors' => $res['errors'] ?? []], 422);
            }
            kg_lvl_json(true, 'Level kaydı eklendi.', ['item' => $res['item'] ?? null]);
            break;

        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lvl_json(false, 'ID zorunludur.', [], 422);
            $res = kg_update_level_config($pdo, $id, [
                'level_number' => $_POST['level_number'] ?? null,
                'required_total_xp' => $_POST['required_total_xp'] ?? null,
            ]);
            if (!($res['success'] ?? false)) {
                $status = isset($res['errors']['id']) ? 404 : 422;
                kg_lvl_json(false, (string)($res['message'] ?? 'Güncelleme başarısız.'), ['errors' => $res['errors'] ?? []], $status);
            }
            kg_lvl_json(true, 'Level kaydı güncellendi.', ['item' => $res['item'] ?? null]);
            break;

        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') kg_lvl_json(false, 'ID zorunludur.', [], 422);
            if (!kg_get_level_config($pdo, $id)) kg_lvl_json(false, 'Kayıt bulunamadı.', [], 404);
            if (!kg_delete_level_config($pdo, $id)) kg_lvl_json(false, 'Silme başarısız.', [], 500);
            kg_lvl_json(true, 'Level kaydı silindi.');
            break;

        default:
            kg_lvl_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    kg_lvl_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

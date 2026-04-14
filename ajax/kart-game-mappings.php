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

function kg_map_json(bool $success, string $message = '', array $data = [], int $status = 200): void
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
            $categories = kg_list_categories_for_mapping($pdo);
            $qualifications = kg_list_qualifications($pdo);
            $mapping = kg_category_qualification_map($pdo);

            kg_map_json(true, '', [
                'categories' => $categories,
                'qualifications' => $qualifications,
                'mapping' => $mapping,
            ]);
            break;

        case 'save':
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            if ($categoryId === '') {
                kg_map_json(false, 'Kategori zorunludur.', [], 422);
            }

            $category = kg_get_category($pdo, $categoryId);
            if (!$category) {
                kg_map_json(false, 'Kategori bulunamadı.', [], 404);
            }

            $qualificationIds = $_POST['qualification_ids'] ?? [];
            if (!is_array($qualificationIds)) {
                $qualificationIds = [];
            }

            kg_save_category_qualifications($pdo, $categoryId, $qualificationIds);
            kg_map_json(true, 'Eşleştirmeler güncellendi.');
            break;

        default:
            kg_map_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    kg_map_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

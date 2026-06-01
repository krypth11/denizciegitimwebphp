<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/word_game_question_helper.php';
try { require_admin(); } catch (Throwable $e) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE); exit; }
function wg_map_json(bool $success, string $message = '', array $data = [], int $status = 200): void { http_response_code($status); echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
try {
    if ($action === 'list') {
        wg_map_json(true, '', ['categories'=>word_game_list_categories_for_mapping($pdo),'qualifications'=>word_game_list_qualifications($pdo),'mapping'=>word_game_category_qualification_map($pdo)]);
    }
    if ($action === 'save') {
        $categoryId = trim((string)($_POST['category_id'] ?? ''));
        if ($categoryId === '') wg_map_json(false, 'Kategori zorunludur.', [], 422);
        if (!word_game_get_category($pdo, $categoryId)) wg_map_json(false, 'Kategori bulunamadı.', [], 404);
        $ids = $_POST['qualification_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        word_game_save_category_qualifications($pdo, $categoryId, $ids);
        wg_map_json(true, 'Eşleştirmeler güncellendi.');
    }
    wg_map_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    wg_map_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

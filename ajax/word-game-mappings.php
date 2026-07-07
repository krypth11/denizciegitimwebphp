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
        $res = word_game_save_category_qualifications($pdo, $categoryId, $ids);
        if (!($res['success'] ?? false)) {
            wg_map_json(false, $res['message'] ?? 'Eşleştirme kaydı reddedildi.', [
                'code' => $res['code'] ?? 'mapping_rejected',
                'affected_question_count' => (int)($res['affected_question_count'] ?? 0),
                'blocked_qualification_ids' => $res['blocked_qualification_ids'] ?? [],
                'blocked_qualification_names' => $res['blocked_qualification_names'] ?? [],
                'errors' => $res['errors'] ?? [],
            ], 422);
        }
        wg_map_json(true, 'Eşleştirmeler güncellendi.', [
            'stats' => $res['stats'] ?? word_game_get_category_stats($pdo, $categoryId),
            'mapping' => word_game_category_qualification_map($pdo),
        ]);
    }
    wg_map_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    wg_map_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

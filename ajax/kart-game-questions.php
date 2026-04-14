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

function kg_q_json(bool $success, string $message = '', array $data = [], int $status = 200): void
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
        case 'category_options':
            $rows = kg_list_categories_for_mapping($pdo);
            kg_q_json(true, '', ['categories' => $rows]);
            break;

        case 'list':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
            $res = kg_list_questions($pdo, [
                'category_id' => (string)($_GET['category_id'] ?? ''),
                'is_active' => (string)($_GET['is_active'] ?? ''),
                'search' => (string)($_GET['search'] ?? ''),
            ], $page, $perPage);
            kg_q_json(true, '', $res);
            break;

        case 'get':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                kg_q_json(false, 'ID zorunludur.', [], 422);
            }
            $item = kg_get_question($pdo, $id);
            if (!$item) {
                kg_q_json(false, 'Kayıt bulunamadı.', [], 404);
            }
            kg_q_json(true, '', ['item' => $item]);
            break;

        case 'create':
            $res = kg_create_question($pdo, [
                'category_id' => $_POST['category_id'] ?? '',
                'question_text' => $_POST['question_text'] ?? '',
                'correct_answer' => $_POST['correct_answer'] ?? '',
                'is_active' => $_POST['is_active'] ?? 0,
                'sort_order' => $_POST['sort_order'] ?? 0,
            ]);
            if (!($res['success'] ?? false)) {
                kg_q_json(false, (string)($res['message'] ?? 'Kayıt başarısız.'), ['errors' => $res['errors'] ?? []], 422);
            }
            kg_q_json(true, 'Soru eklendi.', ['item' => $res['item'] ?? null]);
            break;

        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                kg_q_json(false, 'ID zorunludur.', [], 422);
            }
            $res = kg_update_question($pdo, $id, [
                'category_id' => $_POST['category_id'] ?? '',
                'question_text' => $_POST['question_text'] ?? '',
                'correct_answer' => $_POST['correct_answer'] ?? '',
                'is_active' => $_POST['is_active'] ?? 0,
                'sort_order' => $_POST['sort_order'] ?? 0,
            ]);
            if (!($res['success'] ?? false)) {
                $status = isset($res['errors']['id']) ? 404 : 422;
                kg_q_json(false, (string)($res['message'] ?? 'Güncelleme başarısız.'), ['errors' => $res['errors'] ?? []], $status);
            }
            kg_q_json(true, 'Soru güncellendi.', ['item' => $res['item'] ?? null]);
            break;

        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                kg_q_json(false, 'ID zorunludur.', [], 422);
            }
            $deleted = kg_delete_question($pdo, $id);
            if (!$deleted) {
                kg_q_json(false, 'Kayıt bulunamadı.', [], 404);
            }
            kg_q_json(true, 'Soru silindi.');
            break;

        default:
            kg_q_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    kg_q_json(false, $e->getMessage() ?: 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

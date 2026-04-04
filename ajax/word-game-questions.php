<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/word_game_question_helper.php';

try {
    $user = require_admin();
} catch (Throwable $e) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Bu işlem için yetkiniz bulunmuyor.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function word_game_json(bool $success, string $message = '', array $data = [], int $status = 200): void
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
    switch ($action) {
        case 'list_questions':
            $filters = [
                'qualification_id' => trim((string)($_GET['qualification_id'] ?? '')),
                'is_active' => isset($_GET['is_active']) ? (string)$_GET['is_active'] : '',
                'search' => trim((string)($_GET['search'] ?? '')),
            ];

            $rows = word_game_list($pdo, $filters);
            word_game_json(true, '', [
                'questions' => $rows,
                'total_count' => count($rows),
            ]);
            break;

        case 'get_question':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                word_game_json(false, 'ID gerekli.', [], 422);
            }

            $item = word_game_get($pdo, $id);
            if (!$item) {
                word_game_json(false, 'Kayıt bulunamadı.', [], 404);
            }

            word_game_json(true, '', ['question' => $item]);
            break;

        case 'create_question':
            $result = word_game_create($pdo, [
                'qualification_id' => $_POST['qualification_id'] ?? '',
                'question_text' => $_POST['question_text'] ?? '',
                'answer_text' => $_POST['answer_text'] ?? '',
                'order_index' => $_POST['order_index'] ?? 0,
                'notes' => $_POST['notes'] ?? '',
                'is_active' => $_POST['is_active'] ?? 0,
            ]);

            if (!($result['success'] ?? false)) {
                word_game_json(false, $result['message'] ?? 'Kayıt eklenemedi.', [
                    'errors' => $result['errors'] ?? [],
                ], 422);
            }

            word_game_json(true, 'Kelime oyunu sorusu başarıyla eklendi.', [
                'id' => $result['id'] ?? null,
                'question' => $result['item'] ?? null,
            ]);
            break;

        case 'update_question':
            $id = trim((string)($_POST['id'] ?? ''));
            $result = word_game_update($pdo, $id, [
                'qualification_id' => $_POST['qualification_id'] ?? '',
                'question_text' => $_POST['question_text'] ?? '',
                'answer_text' => $_POST['answer_text'] ?? '',
                'order_index' => $_POST['order_index'] ?? 0,
                'notes' => $_POST['notes'] ?? '',
                'is_active' => $_POST['is_active'] ?? 0,
            ]);

            if (!($result['success'] ?? false)) {
                $message = $result['message'] ?? 'Güncelleme başarısız.';
                $status = isset($result['errors']['id']) ? 404 : 422;
                word_game_json(false, $message, [
                    'errors' => $result['errors'] ?? [],
                ], $status);
            }

            word_game_json(true, 'Kelime oyunu sorusu güncellendi.', [
                'question' => $result['item'] ?? null,
            ]);
            break;

        case 'delete_question':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                word_game_json(false, 'ID gerekli.', [], 422);
            }

            if (!word_game_get($pdo, $id)) {
                word_game_json(false, 'Kayıt bulunamadı.', [], 404);
            }

            word_game_delete($pdo, $id);
            word_game_json(true, 'Kelime oyunu sorusu silindi.');
            break;

        case 'toggle_active':
            $id = trim((string)($_POST['id'] ?? ''));
            $isActiveRaw = $_POST['is_active'] ?? null;

            if ($id === '' || $isActiveRaw === null) {
                word_game_json(false, 'ID ve durum bilgisi zorunludur.', [], 422);
            }

            if (!word_game_get($pdo, $id)) {
                word_game_json(false, 'Kayıt bulunamadı.', [], 404);
            }

            $isActive = in_array((string)$isActiveRaw, ['1', 'true', 'on'], true);
            word_game_toggle_active($pdo, $id, $isActive);
            word_game_json(true, $isActive ? 'Kayıt aktif yapıldı.' : 'Kayıt pasif yapıldı.');
            break;

        default:
            word_game_json(false, 'Geçersiz işlem.', [], 400);
            break;
    }
} catch (Throwable $e) {
    word_game_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

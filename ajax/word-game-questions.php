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
                'category_id' => trim((string)($_GET['category_id'] ?? '')),
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
                'category_id' => $_POST['category_id'] ?? '',
                'qualification_id' => $_POST['qualification_id'] ?? '',
                'question_text' => $_POST['question_text'] ?? '',
                'question_text_en' => $_POST['question_text_en'] ?? '',
                'answer_text' => $_POST['answer_text'] ?? '',
                'answer_text_en' => $_POST['answer_text_en'] ?? '',
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
                'category_id' => $_POST['category_id'] ?? '',
                'qualification_id' => $_POST['qualification_id'] ?? '',
                'question_text' => $_POST['question_text'] ?? '',
                'question_text_en' => $_POST['question_text_en'] ?? '',
                'answer_text' => $_POST['answer_text'] ?? '',
                'answer_text_en' => $_POST['answer_text_en'] ?? '',
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

        case 'parse_bulk_pattern':
            $pattern = (string)($_POST['pattern'] ?? '');
            $parsed = word_game_parse_bulk_pattern_text($pattern);
            word_game_json(true, '', $parsed);
            break;

        case 'create_bulk_questions':
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            if ($categoryId === '') {
                word_game_json(false, 'Başlık seçimi zorunludur.', [], 422);
            }

            $itemsRaw = $_POST['items'] ?? '[]';
            if (is_string($itemsRaw)) {
                $items = json_decode($itemsRaw, true);
            } else {
                $items = $itemsRaw;
            }
            if (!is_array($items)) {
                word_game_json(false, 'Geçersiz kayıt listesi.', [], 422);
            }

            $created = [];
            $errors = [];
            foreach ($items as $idx => $item) {
                $record = is_array($item) ? $item : [];
                $result = word_game_create($pdo, [
                    'category_id' => $categoryId,
                    'question_text' => $record['tr_question'] ?? '',
                    'question_text_en' => $record['en_question'] ?? '',
                    'answer_text' => $record['tr_answer'] ?? '',
                    'answer_text_en' => $record['en_answer'] ?? '',
                    'notes' => $record['note'] ?? '',
                    'is_active' => 1,
                    'order_index' => 0,
                ]);

                if (!($result['success'] ?? false)) {
                    $errors[] = [
                        'index' => $idx,
                        'message' => $result['message'] ?? 'Kayıt oluşturulamadı.',
                        'errors' => $result['errors'] ?? [],
                    ];
                    continue;
                }
                $created[] = $result['item'] ?? null;
            }

            word_game_json(true, 'Toplu kayıt işlemi tamamlandı.', [
                'created_count' => count($created),
                'error_count' => count($errors),
                'created' => $created,
                'errors' => $errors,
            ]);
            break;

        default:
            word_game_json(false, 'Geçersiz işlem.', [], 400);
            break;
    }
} catch (Throwable $e) {
    word_game_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

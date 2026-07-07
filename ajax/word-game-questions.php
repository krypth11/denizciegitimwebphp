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

            $allowedPerPage = [10, 25, 50, 100, 500];
            $page = (int)($_GET['page'] ?? 1);
            if ($page < 1) {
                $page = 1;
            }
            $perPage = (int)($_GET['per_page'] ?? 25);
            if (!in_array($perPage, $allowedPerPage, true)) {
                $perPage = 25;
            }
            $offset = ($page - 1) * $perPage;

            $where = ['1=1'];
            $params = [];

            $categoryId = $filters['category_id'];
            if ($categoryId !== '') {
                $where[] = 'wq.category_id = :category_id';
                $params[':category_id'] = $categoryId;
            }

            $qualificationId = $filters['qualification_id'];
            if ($qualificationId !== '') {
                $where[] = 'wq.qualification_id = :qualification_id';
                $params[':qualification_id'] = $qualificationId;
            }

            if (isset($filters['is_active']) && $filters['is_active'] !== '') {
                $where[] = 'wq.is_active = :is_active';
                $params[':is_active'] = ((int)$filters['is_active'] === 1 ? 1 : 0);
            }

            $search = trim((string)$filters['search']);
            if ($search !== '') {
                $where[] = '(wq.question_text LIKE :search_q OR wq.question_text_en LIKE :search_q_en OR wq.answer_text LIKE :search_a OR wq.answer_text_en LIKE :search_a_en OR wq.answer_normalized LIKE :search_norm)';
                $like = '%' . $search . '%';
                $params[':search_q'] = $like;
                $params[':search_q_en'] = $like;
                $params[':search_a'] = $like;
                $params[':search_a_en'] = $like;
                $params[':search_norm'] = $like;
            }

            $whereSql = implode(' AND ', $where);

            $countSql = 'SELECT COUNT(*)
                         FROM word_game_questions wq
                         LEFT JOIN qualifications q ON q.id = wq.qualification_id
                         LEFT JOIN word_game_categories c ON c.id = wq.category_id
                         WHERE ' . $whereSql;
            $countStmt = $pdo->prepare($countSql);
            foreach ($params as $name => $value) {
                $countStmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $countStmt->execute();
            $totalCount = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalCount / $perPage));

            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $perPage;
            }

            $dataSql = 'SELECT wq.*, q.name AS qualification_name, c.name AS category_name
                        FROM word_game_questions wq
                        LEFT JOIN qualifications q ON q.id = wq.qualification_id
                        LEFT JOIN word_game_categories c ON c.id = wq.category_id
                        WHERE ' . $whereSql . '
                        ORDER BY c.order_index ASC, c.name ASC, wq.order_index ASC, wq.created_at DESC
                        LIMIT :limit OFFSET :offset';
            $dataStmt = $pdo->prepare($dataSql);
            foreach ($params as $name => $value) {
                $dataStmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $dataStmt->execute();
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            word_game_json(true, '', [
                'questions' => $rows,
            ] + [
                'total_count' => $totalCount,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_count' => $totalCount,
                    'total_pages' => $totalPages,
                ],
            ]);
            break;

        case 'get_category_qualifications':
            $categoryId = trim((string)($_GET['category_id'] ?? $_POST['category_id'] ?? ''));
            if ($categoryId === '') {
                word_game_json(false, 'Başlık seçimi zorunludur.', [], 422);
            }
            $category = word_game_get_category($pdo, $categoryId);
            if (!$category) {
                word_game_json(false, 'Başlık bulunamadı.', [], 404);
            }
            word_game_json(true, '', [
                'category' => $category,
                'qualifications' => word_game_list_category_qualifications($pdo, $categoryId),
                'category_stats' => word_game_get_category_stats($pdo, $categoryId),
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

        case 'bulk_delete':
            $idsRaw = $_POST['ids'] ?? [];
            if (is_string($idsRaw)) {
                $decoded = json_decode($idsRaw, true);
                $ids = is_array($decoded) ? $decoded : [];
            } elseif (is_array($idsRaw)) {
                $ids = $idsRaw;
            } else {
                $ids = [];
            }

            if (empty($ids)) {
                word_game_json(false, 'Silinecek ID listesi boş olamaz.', [], 422);
            }

            if (count($ids) > 500) {
                word_game_json(false, 'Tek seferde en fazla 500 kayıt silinebilir.', [], 422);
            }

            $normalizedIds = [];
            foreach ($ids as $idItem) {
                if (!is_string($idItem)) {
                    word_game_json(false, 'ID listesi sadece string değerler içermelidir.', [], 422);
                }
                $idItem = trim($idItem);
                if ($idItem === '') {
                    word_game_json(false, 'Boş ID değeri gönderilemez.', [], 422);
                }
                $normalizedIds[] = $idItem;
            }

            $normalizedIds = array_values(array_unique($normalizedIds));
            if (empty($normalizedIds)) {
                word_game_json(false, 'Geçerli ID bulunamadı.', [], 422);
            }

            $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
            $sql = "DELETE FROM word_game_questions WHERE id IN ($placeholders)";

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($normalizedIds);
                $deletedCount = (int)$stmt->rowCount();
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            word_game_json(true, 'Seçili kayıtlar silindi.', [
                'deleted_count' => $deletedCount,
            ]);
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
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
            $pattern = (string)($_POST['pattern'] ?? '');
            $parsed = word_game_parse_bulk_pattern_text($pattern);
            $records = [];
            foreach (($parsed['items'] ?? []) as $item) {
                if (isset($item['record']) && is_array($item['record'])) {
                    $records[] = $item['record'];
                }
            }
            $validation = word_game_validate_bulk_questions($pdo, $categoryId, $qualificationId, $records);
            if (!empty($parsed['errors']) && empty($records)) {
                $validation['errors'] = $parsed['errors'];
            }
            word_game_json(true, '', $validation);
            break;

        case 'create_bulk_questions':
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));

            $itemsRaw = $_POST['items'] ?? '[]';
            if (is_string($itemsRaw)) {
                $items = json_decode($itemsRaw, true);
            } else {
                $items = $itemsRaw;
            }
            if (!is_array($items)) {
                word_game_json(false, 'Geçersiz kayıt listesi.', [], 422);
            }
            $requestedCount = count($items);
            $validation = word_game_validate_bulk_questions($pdo, $categoryId, $qualificationId, $items);
            if (!($validation['valid'] ?? false)) {
                word_game_json(false, 'Toplu kayıt doğrulamasında hatalar var. Hiçbir kayıt eklenmedi.', [
                    'requested_count' => $requestedCount,
                    'created_count' => 0,
                    'error_count' => (int)($validation['invalid_count'] ?? 0),
                    'validation' => $validation,
                ], 422);
            }

            $createdIds = [];
            $pdo->beginTransaction();
            try {
                $nextOrder = word_game_next_order_index($pdo, $categoryId, $qualificationId);
                foreach (($validation['normalized_records'] ?? []) as $idx => $validatedData) {
                    $validatedData['order_index'] = $nextOrder + (int)$idx;
                    $inserted = word_game_insert_validated_question($pdo, $validatedData);
                    $createdIds[] = $inserted['id'];
                }
                if (count($createdIds) !== $requestedCount) {
                    throw new RuntimeException('Bulk created count mismatch.');
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (word_game_is_duplicate_exception($e)) {
                    word_game_json(false, 'Bu yeterlilik altında aynı cevap zaten mevcut.', [
                        'requested_count' => $requestedCount,
                        'created_count' => 0,
                        'created_ids' => [],
                        'error_count' => $requestedCount,
                    ], 422);
                }
                throw $e;
            }

            word_game_json(true, 'Toplu kayıt başarıyla tamamlandı.', [
                'requested_count' => $requestedCount,
                'created_count' => count($createdIds),
                'created_ids' => $createdIds,
                'error_count' => 0,
            ]);
            break;

        default:
            word_game_json(false, 'Geçersiz işlem.', [], 400);
            break;
    }
} catch (Throwable $e) {
    if (word_game_is_duplicate_exception($e)) {
        word_game_json(false, 'Bu yeterlilik altında aynı cevap zaten mevcut.', [], 422);
    }
    word_game_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

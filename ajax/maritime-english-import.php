<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/maritime_english_import_helper.php';
try { require_admin(); } catch (Throwable $e) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']); exit; }
function me_admin_json(bool $success, string $message = '', array $data = [], int $status = 200): void {
    http_response_code($status); echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
    if ($action === 'categories') {
        $rows = $pdo->query('SELECT id, name FROM maritime_english_categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $qualifications = $pdo->query('SELECT id, name FROM qualifications WHERE is_active = 1 ORDER BY order_index, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        me_admin_json(true, '', ['categories' => $rows, 'qualifications' => $qualifications]);
    }
    if ($action === 'list') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 20)));
        $categoryId = trim((string)($_GET['category_id'] ?? ''));
        $questionType = trim((string)($_GET['question_type'] ?? ''));
        $isActive = trim((string)($_GET['is_active'] ?? ''));
        $search = trim((string)($_GET['search'] ?? ''));
        $allowedTypes = ['context_meaning', 'fill_blank', 'translation', 'dialogue', 'word_order', 'wrong_usage', 'matching'];
        if ($questionType !== '' && !in_array($questionType, $allowedTypes, true)) {
            me_admin_json(false, 'Geçersiz soru tipi.', [], 422);
        }
        if ($isActive !== '' && !in_array($isActive, ['0', '1'], true)) {
            me_admin_json(false, 'Geçersiz durum filtresi.', [], 422);
        }

        $where = ['q.deleted_at IS NULL'];
        $params = [];
        if ($categoryId !== '') { $where[] = 't.category_id = ?'; $params[] = $categoryId; }
        if ($questionType !== '') { $where[] = 'q.question_type = ?'; $params[] = $questionType; }
        if ($isActive !== '') { $where[] = 'q.is_active = ?'; $params[] = (int)$isActive; }
        if ($search !== '') {
            $where[] = '(q.prompt LIKE ? OR t.term_en LIKE ? OR t.term_tr LIKE ? OR q.options_json LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $fromSql = ' FROM maritime_english_questions q
                     INNER JOIN maritime_english_terms t ON t.id = q.term_id
                     INNER JOIN maritime_english_categories c ON c.id = t.category_id
                     LEFT JOIN qualifications qu ON qu.id = t.qualification_id ';
        $countStmt = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT q.id, q.question_type, q.prompt, q.options_json, q.correct_option_key,
                       q.difficulty, q.is_active, q.sort_order, q.created_at,
                       t.term_en, t.term_tr, c.name AS category_name, qu.name AS qualification_name'
             . $fromSql . $whereSql
             . ' ORDER BY q.created_at DESC, c.sort_order ASC, t.term_en ASC, q.sort_order ASC LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map(static function (array $row): array {
            $options = json_decode((string)$row['options_json'], true);
            $row['options'] = is_array($options) ? $options : [];
            unset($row['options_json']);
            $row['is_active'] = (int)$row['is_active'];
            $row['sort_order'] = (int)$row['sort_order'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        me_admin_json(true, '', [
            'items' => $items,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
        ]);
    }
    if ($action === 'bulk_delete') {
        $decodedIds = json_decode((string)($_POST['ids'] ?? ''), true);
        if (!is_array($decodedIds)) me_admin_json(false, 'Silinecek soru listesi geçersiz.', [], 422);
        $uniqueIds = [];
        foreach ($decodedIds as $rawId) {
            $id = trim((string)$rawId);
            if ($id === '' || strlen($id) > 64 || !preg_match('/^[a-zA-Z0-9-]+$/', $id)) {
                me_admin_json(false, 'Geçersiz soru kimliği gönderildi.', [], 422);
            }
            $uniqueIds[$id] = $id;
        }
        $ids = array_values($uniqueIds);
        if (!$ids) me_admin_json(false, 'En az bir soru seçmelisiniz.', [], 422);
        if (count($ids) > 500) me_admin_json(false, 'Tek işlemde en fazla 500 soru silinebilir.', [], 422);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE maritime_english_questions
                               SET is_active = 0, deleted_at = NOW(), updated_at = NOW()
                               WHERE id IN ($placeholders) AND deleted_at IS NULL");
        $stmt->execute($ids);
        $deletedCount = $stmt->rowCount();
        $pdo->commit();
        me_admin_json(true, $deletedCount . ' soru silindi.', ['deleted_count' => $deletedCount]);
    }
    if ($action === 'parse') {
        $result = me_import_parse((string)($_POST['content'] ?? ''));
        me_admin_json((bool)$result['success'], $result['success'] ? 'Metin başarıyla analiz edildi.' : 'Metin doğrulanamadı.', $result, $result['success'] ? 200 : 422);
    }
    if ($action === 'import') {
        $decoded = json_decode((string)($_POST['parsed_json'] ?? ''), true);
        if (!is_array($decoded)) me_admin_json(false, 'Geçersiz önizleme verisi.', [], 422);
        $pdo->beginTransaction();
        $saved = me_import_save($pdo, trim((string)($_POST['category_id'] ?? '')), trim((string)($_POST['qualification_id'] ?? '')), $decoded);
        $pdo->commit();
        me_admin_json(true, 'İçerik başarıyla kaydedildi.', $saved);
    }
    me_admin_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    me_admin_json(false, $e instanceof InvalidArgumentException ? $e->getMessage() : 'İçerik kaydedilemedi.', [], $e instanceof InvalidArgumentException ? 422 : 500);
}

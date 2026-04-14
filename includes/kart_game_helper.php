<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/upload_helper.php';

function kg_slugify(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $map = [
        'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g',
        'ı' => 'i', 'İ' => 'i', 'I' => 'i',
        'ö' => 'o', 'Ö' => 'o',
        'ş' => 's', 'Ş' => 's',
        'ü' => 'u', 'Ü' => 'u',
    ];
    $value = strtr($value, $map);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value;
}

function kg_ensure_slug_unique(PDO $pdo, string $slug, ?string $excludeId = null): string
{
    $base = trim($slug);
    if ($base === '') {
        $base = 'kart-oyunu-' . date('YmdHis');
    }

    $candidate = $base;
    $i = 1;

    while (true) {
        $sql = 'SELECT id FROM kart_game_categories WHERE slug = ?';
        $params = [$candidate];
        if ($excludeId !== null && $excludeId !== '') {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $exists = (string)$stmt->fetchColumn();
        if ($exists === '') {
            return $candidate;
        }

        $i++;
        $candidate = $base . '-' . $i;
    }
}

function kg_list_categories(PDO $pdo, array $filters): array
{
    $where = ['1=1'];
    $params = [];

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(c.title LIKE ? OR c.slug LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $isActive = (string)($filters['is_active'] ?? '');
    if ($isActive === '0' || $isActive === '1') {
        $where[] = 'c.is_active = ?';
        $params[] = (int)$isActive;
    }

    $sql = 'SELECT c.*, '
        . '(SELECT COUNT(*) FROM kart_game_category_qualifications m WHERE m.category_id = c.id) AS qualification_count, '
        . '(SELECT COUNT(*) FROM kart_game_questions q WHERE q.category_id = c.id) AS question_count '
        . 'FROM kart_game_categories c '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY COALESCE(c.sort_order, 0) ASC, c.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function kg_get_category(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM kart_game_categories WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function kg_create_category(PDO $pdo, array $payload): array
{
    $title = trim((string)($payload['title'] ?? ''));
    $slugRaw = trim((string)($payload['slug'] ?? ''));
    $sortOrder = filter_var($payload['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    $isActive = in_array((string)($payload['is_active'] ?? '0'), ['1', 'true', 'on'], true) ? 1 : 0;

    if ($title === '') {
        return ['success' => false, 'message' => 'Başlık zorunludur.', 'errors' => ['title' => 'Başlık zorunludur.']];
    }

    $slug = kg_slugify($slugRaw !== '' ? $slugRaw : $title);
    $slug = kg_ensure_slug_unique($pdo, $slug);

    $id = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO kart_game_categories (id, title, slug, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $title, $slug, $isActive, ($sortOrder === false ? 0 : (int)$sortOrder)]);

    return ['success' => true, 'id' => $id, 'item' => kg_get_category($pdo, $id)];
}

function kg_update_category(PDO $pdo, string $id, array $payload): array
{
    if (!kg_get_category($pdo, $id)) {
        return ['success' => false, 'message' => 'Kayıt bulunamadı.', 'errors' => ['id' => 'Kayıt bulunamadı.']];
    }

    $title = trim((string)($payload['title'] ?? ''));
    $slugRaw = trim((string)($payload['slug'] ?? ''));
    $sortOrder = filter_var($payload['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    $isActive = in_array((string)($payload['is_active'] ?? '0'), ['1', 'true', 'on'], true) ? 1 : 0;

    if ($title === '') {
        return ['success' => false, 'message' => 'Başlık zorunludur.', 'errors' => ['title' => 'Başlık zorunludur.']];
    }

    $slug = kg_slugify($slugRaw !== '' ? $slugRaw : $title);
    $slug = kg_ensure_slug_unique($pdo, $slug, $id);

    $stmt = $pdo->prepare('UPDATE kart_game_categories SET title = ?, slug = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$title, $slug, $isActive, ($sortOrder === false ? 0 : (int)$sortOrder), $id]);

    return ['success' => true, 'item' => kg_get_category($pdo, $id)];
}

function kg_category_relation_counts(PDO $pdo, string $categoryId): array
{
    $stmt1 = $pdo->prepare('SELECT COUNT(*) FROM kart_game_category_qualifications WHERE category_id = ?');
    $stmt1->execute([$categoryId]);
    $mappingCount = (int)$stmt1->fetchColumn();

    $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM kart_game_questions WHERE category_id = ?');
    $stmt2->execute([$categoryId]);
    $questionCount = (int)$stmt2->fetchColumn();

    return [
        'mapping_count' => $mappingCount,
        'question_count' => $questionCount,
    ];
}

function kg_delete_category(PDO $pdo, string $categoryId): bool
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM kart_game_category_qualifications WHERE category_id = ?')->execute([$categoryId]);

        $qStmt = $pdo->prepare('SELECT image_path, image_url FROM kart_game_questions WHERE category_id = ?');
        $qStmt->execute([$categoryId]);
        $images = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $pdo->prepare('DELETE FROM kart_game_questions WHERE category_id = ?')->execute([$categoryId]);
        $deleted = $pdo->prepare('DELETE FROM kart_game_categories WHERE id = ?');
        $deleted->execute([$categoryId]);

        foreach ($images as $img) {
            upload_safe_delete((string)($img['image_path'] ?? ''), 'kart-oyunu');
            upload_safe_delete((string)($img['image_url'] ?? ''), 'kart-oyunu');
        }

        $pdo->commit();
        return $deleted->rowCount() > 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kg_list_categories_for_mapping(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, title, slug, is_active, sort_order FROM kart_game_categories ORDER BY COALESCE(sort_order,0) ASC, title ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function kg_list_qualifications(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name FROM qualifications ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function kg_category_qualification_map(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT category_id, qualification_id FROM kart_game_category_qualifications');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $cid = (string)($row['category_id'] ?? '');
        $qid = (string)($row['qualification_id'] ?? '');
        if ($cid === '' || $qid === '') {
            continue;
        }
        $map[$cid][] = $qid;
    }
    return $map;
}

function kg_save_category_qualifications(PDO $pdo, string $categoryId, array $qualificationIds): void
{
    $clean = [];
    foreach ($qualificationIds as $qid) {
        $qid = trim((string)$qid);
        if ($qid !== '') {
            $clean[$qid] = true;
        }
    }
    $ids = array_keys($clean);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM kart_game_category_qualifications WHERE category_id = ?')->execute([$categoryId]);

        if (!empty($ids)) {
            $insert = $pdo->prepare('INSERT INTO kart_game_category_qualifications (id, category_id, qualification_id, created_at) VALUES (?, ?, ?, NOW())');
            foreach ($ids as $qid) {
                $insert->execute([generate_uuid(), $categoryId, $qid]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kg_list_questions(PDO $pdo, array $filters, int $page = 1, int $perPage = 20): array
{
    $where = ['1=1'];
    $params = [];

    $categoryId = trim((string)($filters['category_id'] ?? ''));
    if ($categoryId !== '') {
        $where[] = 'q.category_id = ?';
        $params[] = $categoryId;
    }

    $isActive = (string)($filters['is_active'] ?? '');
    if ($isActive === '0' || $isActive === '1') {
        $where[] = 'q.is_active = ?';
        $params[] = (int)$isActive;
    }

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(q.question_text LIKE ? OR q.correct_answer LIKE ? OR c.title LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $countSql = 'SELECT COUNT(*) FROM kart_game_questions q LEFT JOIN kart_game_categories c ON c.id = q.category_id WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT q.*, c.title AS category_title '
        . 'FROM kart_game_questions q '
        . 'LEFT JOIN kart_game_categories c ON c.id = q.category_id '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY COALESCE(q.sort_order,0) ASC, q.created_at DESC '
        . 'LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'items' => $rows,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ],
    ];
}

function kg_get_question(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT q.*, c.title AS category_title FROM kart_game_questions q LEFT JOIN kart_game_categories c ON c.id = q.category_id WHERE q.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function kg_store_question_image(array $file): array
{
    $stored = upload_store_image_file('kart-oyunu', 'images', $file, [
        'max_bytes' => 6 * 1024 * 1024,
        'filename_prefix' => 'kart-game',
    ]);

    $dim = @getimagesize($stored['abs_path']);
    if (!is_array($dim)) {
        upload_safe_delete($stored['relative_path'], 'kart-oyunu');
        throw new RuntimeException('Kaydedilen görsel doğrulanamadı.');
    }

    $width = (int)($dim[0] ?? 0);
    $height = (int)($dim[1] ?? 0);
    if ($width < 320 || $height < 400) {
        upload_safe_delete($stored['relative_path'], 'kart-oyunu');
        throw new RuntimeException('Crop sonucu görsel çok küçük. Minimum 320x400 olmalı.');
    }

    $ratio = $width / max(1, $height);
    if (abs($ratio - (4 / 5)) > 0.06) {
        upload_safe_delete($stored['relative_path'], 'kart-oyunu');
        throw new RuntimeException('Görsel oranı 4:5 olmalıdır.');
    }

    return [
        'image_path' => $stored['relative_path'],
        'image_url' => $stored['public_url'],
    ];
}

function kg_validate_question_input(PDO $pdo, array $payload, bool $imageRequired = true): array
{
    $errors = [];

    $categoryId = trim((string)($payload['category_id'] ?? ''));
    $questionText = trim((string)($payload['question_text'] ?? ''));
    $correctAnswer = trim((string)($payload['correct_answer'] ?? ''));
    $sortOrder = filter_var($payload['sort_order'] ?? 0, FILTER_VALIDATE_INT);
    $isActive = in_array((string)($payload['is_active'] ?? '0'), ['1', 'true', 'on'], true) ? 1 : 0;

    if ($categoryId === '') {
        $errors['category_id'] = 'Başlık seçimi zorunludur.';
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM kart_game_categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        if ((int)$stmt->fetchColumn() < 1) {
            $errors['category_id'] = 'Geçerli bir başlık seçiniz.';
        }
    }

    if ($questionText === '') {
        $errors['question_text'] = 'Soru metni zorunludur.';
    }
    if ($correctAnswer === '') {
        $errors['correct_answer'] = 'Doğru cevap zorunludur.';
    }

    if ($imageRequired && (!isset($_FILES['image']) || (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors['image'] = 'Görsel zorunludur.';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'category_id' => $categoryId,
            'question_text' => $questionText,
            'correct_answer' => $correctAnswer,
            'sort_order' => ($sortOrder === false ? 0 : (int)$sortOrder),
            'is_active' => $isActive,
        ],
    ];
}

function kg_create_question(PDO $pdo, array $payload): array
{
    $validation = kg_validate_question_input($pdo, $payload, true);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => 'Form doğrulaması başarısız.', 'errors' => $validation['errors']];
    }

    $image = kg_store_question_image($_FILES['image']);
    try {
        $id = generate_uuid();
        $d = $validation['data'];

        $stmt = $pdo->prepare('INSERT INTO kart_game_questions (id, category_id, question_text, correct_answer, image_url, image_path, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([
            $id,
            $d['category_id'],
            $d['question_text'],
            $d['correct_answer'],
            $image['image_url'],
            $image['image_path'],
            $d['is_active'],
            $d['sort_order'],
        ]);

        return ['success' => true, 'id' => $id, 'item' => kg_get_question($pdo, $id)];
    } catch (Throwable $e) {
        upload_safe_delete($image['image_path'] ?? '', 'kart-oyunu');
        throw $e;
    }
}

function kg_update_question(PDO $pdo, string $id, array $payload): array
{
    $existing = kg_get_question($pdo, $id);
    if (!$existing) {
        return ['success' => false, 'message' => 'Kayıt bulunamadı.', 'errors' => ['id' => 'Kayıt bulunamadı.']];
    }

    $hasNewImage = isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $validation = kg_validate_question_input($pdo, $payload, false);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => 'Form doğrulaması başarısız.', 'errors' => $validation['errors']];
    }

    $newImage = null;
    if ($hasNewImage) {
        $newImage = kg_store_question_image($_FILES['image']);
    }

    try {
        $d = $validation['data'];
        $stmt = $pdo->prepare('UPDATE kart_game_questions SET category_id = ?, question_text = ?, correct_answer = ?, image_url = ?, image_path = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([
            $d['category_id'],
            $d['question_text'],
            $d['correct_answer'],
            $newImage['image_url'] ?? (string)$existing['image_url'],
            $newImage['image_path'] ?? (string)$existing['image_path'],
            $d['is_active'],
            $d['sort_order'],
            $id,
        ]);

        if ($newImage) {
            upload_safe_delete((string)($existing['image_path'] ?? ''), 'kart-oyunu');
            upload_safe_delete((string)($existing['image_url'] ?? ''), 'kart-oyunu');
        }

        return ['success' => true, 'item' => kg_get_question($pdo, $id)];
    } catch (Throwable $e) {
        if ($newImage) {
            upload_safe_delete($newImage['image_path'], 'kart-oyunu');
        }
        throw $e;
    }
}

function kg_delete_question(PDO $pdo, string $id): bool
{
    $existing = kg_get_question($pdo, $id);
    if (!$existing) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM kart_game_questions WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        upload_safe_delete((string)($existing['image_path'] ?? ''), 'kart-oyunu');
        upload_safe_delete((string)($existing['image_url'] ?? ''), 'kart-oyunu');
        return true;
    }

    return false;
}

function kg_get_active_questions_for_qualification(PDO $pdo, string $qualificationId): array
{
    $qualificationId = trim($qualificationId);
    if ($qualificationId === '') {
        return [];
    }

    $sql = 'SELECT q.id, q.category_id, c.title AS category_name, q.question_text, q.correct_answer, q.image_url, q.image_path '
        . 'FROM kart_game_questions q '
        . 'INNER JOIN kart_game_categories c ON c.id = q.category_id '
        . 'INNER JOIN kart_game_category_qualifications m ON m.category_id = c.id '
        . 'WHERE m.qualification_id = ? AND c.is_active = 1 AND q.is_active = 1 '
        . 'ORDER BY COALESCE(c.sort_order,0) ASC, COALESCE(q.sort_order,0) ASC, q.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qualificationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['image_url'] = upload_build_public_url((string)($row['image_path'] ?? $row['image_url'] ?? ''));
    }
    unset($row);

    return $rows;
}

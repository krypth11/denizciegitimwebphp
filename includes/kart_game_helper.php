<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/upload_helper.php';
require_once __DIR__ . '/app_runtime_settings_helper.php';

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

    $xpInput = [
        'correct_xp' => $payload['correct_xp'] ?? kg_default_xp_rule_payload()['correct_xp'],
        'wrong_penalty' => $payload['wrong_penalty'] ?? kg_default_xp_rule_payload()['wrong_penalty'],
        'combo_bonus_every' => $payload['combo_bonus_every'] ?? kg_default_xp_rule_payload()['combo_bonus_every'],
        'combo_bonus_xp' => $payload['combo_bonus_xp'] ?? kg_default_xp_rule_payload()['combo_bonus_xp'],
        'perfect_game_bonus' => $payload['perfect_game_bonus'] ?? kg_default_xp_rule_payload()['perfect_game_bonus'],
        'endless_multiplier' => $payload['endless_multiplier'] ?? kg_default_xp_rule_payload()['endless_multiplier'],
    ];
    $xpValidation = kg_validate_xp_rules_input($xpInput);
    if (!$xpValidation['valid']) {
        return ['success' => false, 'message' => 'XP kural doğrulaması başarısız.', 'errors' => $xpValidation['errors']];
    }

    $id = generate_uuid();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO kart_game_categories (id, title, slug, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$id, $title, $slug, $isActive, ($sortOrder === false ? 0 : (int)$sortOrder)]);
        kg_upsert_xp_rule($pdo, $id, $xpValidation['data']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

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

    $xpInput = [
        'correct_xp' => $payload['correct_xp'] ?? null,
        'wrong_penalty' => $payload['wrong_penalty'] ?? null,
        'combo_bonus_every' => $payload['combo_bonus_every'] ?? null,
        'combo_bonus_xp' => $payload['combo_bonus_xp'] ?? null,
        'perfect_game_bonus' => $payload['perfect_game_bonus'] ?? null,
        'endless_multiplier' => $payload['endless_multiplier'] ?? null,
    ];
    $xpValidation = kg_validate_xp_rules_input($xpInput);
    if (!$xpValidation['valid']) {
        return ['success' => false, 'message' => 'XP kural doğrulaması başarısız.', 'errors' => $xpValidation['errors']];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE kart_game_categories SET title = ?, slug = ?, is_active = ?, sort_order = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$title, $slug, $isActive, ($sortOrder === false ? 0 : (int)$sortOrder), $id]);
        kg_upsert_xp_rule($pdo, $id, $xpValidation['data']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

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

function kg_table_columns(PDO $pdo, string $table): array
{
    $cols = function_exists('get_table_columns') ? get_table_columns($pdo, $table) : [];
    return is_array($cols) ? $cols : [];
}

function kg_pick_column(array $columns, array $candidates, bool $required = true): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    if ($required) {
        throw new RuntimeException('Beklenen kolon bulunamadı: ' . implode(', ', $candidates));
    }
    return null;
}

function kg_profile_schema(PDO $pdo): array
{
    static $schema = null;
    if (is_array($schema)) {
        return $schema;
    }

    $columns = kg_table_columns($pdo, 'user_profiles');
    $schema = [
        'table' => 'user_profiles',
        'id' => kg_pick_column($columns, ['id']),
        'email' => kg_pick_column($columns, ['email'], false),
        'full_name' => kg_pick_column($columns, ['full_name', 'name', 'display_name'], false),
        'avatar_id' => kg_pick_column($columns, ['avatar_id'], false),
        'profile_photo_url' => kg_pick_column($columns, ['profile_photo_url'], false),
    ];

    return $schema;
}

function kg_default_xp_rule_payload(): array
{
    return [
        'correct_xp' => 10,
        'wrong_penalty' => 0,
        'combo_bonus_every' => 5,
        'combo_bonus_xp' => 20,
        'perfect_game_bonus' => 100,
        'endless_multiplier' => 1.00,
    ];
}

function kg_validate_xp_rules_input(array $payload): array
{
    $errors = [];

    $correctXp = filter_var($payload['correct_xp'] ?? null, FILTER_VALIDATE_INT);
    $wrongPenalty = filter_var($payload['wrong_penalty'] ?? null, FILTER_VALIDATE_INT);
    $comboEvery = filter_var($payload['combo_bonus_every'] ?? null, FILTER_VALIDATE_INT);
    $comboXp = filter_var($payload['combo_bonus_xp'] ?? null, FILTER_VALIDATE_INT);
    $perfectBonus = filter_var($payload['perfect_game_bonus'] ?? null, FILTER_VALIDATE_INT);
    $endlessMultiplier = filter_var($payload['endless_multiplier'] ?? null, FILTER_VALIDATE_FLOAT);

    if ($correctXp === false || $correctXp < 0 || $correctXp > 10000) $errors['correct_xp'] = 'correct_xp 0-10000 olmalıdır.';
    if ($wrongPenalty === false || $wrongPenalty < 0 || $wrongPenalty > 10000) $errors['wrong_penalty'] = 'wrong_penalty 0-10000 olmalıdır.';
    if ($comboEvery === false || $comboEvery < 1 || $comboEvery > 1000) $errors['combo_bonus_every'] = 'combo_bonus_every 1-1000 olmalıdır.';
    if ($comboXp === false || $comboXp < 0 || $comboXp > 10000) $errors['combo_bonus_xp'] = 'combo_bonus_xp 0-10000 olmalıdır.';
    if ($perfectBonus === false || $perfectBonus < 0 || $perfectBonus > 100000) $errors['perfect_game_bonus'] = 'perfect_game_bonus 0-100000 olmalıdır.';
    if ($endlessMultiplier === false || $endlessMultiplier < 0.1 || $endlessMultiplier > 100) $errors['endless_multiplier'] = 'endless_multiplier 0.1-100 aralığında olmalıdır.';

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'correct_xp' => (int)$correctXp,
            'wrong_penalty' => (int)$wrongPenalty,
            'combo_bonus_every' => (int)$comboEvery,
            'combo_bonus_xp' => (int)$comboXp,
            'perfect_game_bonus' => (int)$perfectBonus,
            'endless_multiplier' => round((float)$endlessMultiplier, 4),
        ],
    ];
}

function kg_get_xp_rule(PDO $pdo, string $categoryId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM kart_game_xp_rules WHERE category_id = ? LIMIT 1');
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function kg_upsert_xp_rule(PDO $pdo, string $categoryId, array $ruleData): void
{
    $existing = kg_get_xp_rule($pdo, $categoryId);
    if ($existing) {
        $stmt = $pdo->prepare('UPDATE kart_game_xp_rules SET correct_xp=?, wrong_penalty=?, combo_bonus_every=?, combo_bonus_xp=?, perfect_game_bonus=?, endless_multiplier=?, updated_at=NOW() WHERE category_id=?');
        $stmt->execute([
            $ruleData['correct_xp'],
            $ruleData['wrong_penalty'],
            $ruleData['combo_bonus_every'],
            $ruleData['combo_bonus_xp'],
            $ruleData['perfect_game_bonus'],
            $ruleData['endless_multiplier'],
            $categoryId,
        ]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO kart_game_xp_rules (id, category_id, correct_xp, wrong_penalty, combo_bonus_every, combo_bonus_xp, perfect_game_bonus, endless_multiplier, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([
        generate_uuid(),
        $categoryId,
        $ruleData['correct_xp'],
        $ruleData['wrong_penalty'],
        $ruleData['combo_bonus_every'],
        $ruleData['combo_bonus_xp'],
        $ruleData['perfect_game_bonus'],
        $ruleData['endless_multiplier'],
    ]);
}

function kg_create_default_xp_rule(PDO $pdo, string $categoryId): void
{
    $defaults = kg_default_xp_rule_payload();
    kg_upsert_xp_rule($pdo, $categoryId, $defaults);
}

function kg_list_level_configs(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM kart_game_level_config ORDER BY level_number ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function kg_get_level_config(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM kart_game_level_config WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function kg_validate_level_config_input(PDO $pdo, array $payload, ?string $excludeId = null): array
{
    $errors = [];
    $levelNumber = filter_var($payload['level_number'] ?? null, FILTER_VALIDATE_INT);
    $requiredTotalXp = filter_var($payload['required_total_xp'] ?? null, FILTER_VALIDATE_INT);

    if ($levelNumber === false || $levelNumber < 1 || $levelNumber > 1000000) {
        $errors['level_number'] = 'level_number 1+ olmalıdır.';
    }
    if ($requiredTotalXp === false || $requiredTotalXp < 0 || $requiredTotalXp > 2000000000) {
        $errors['required_total_xp'] = 'required_total_xp 0+ olmalıdır.';
    }

    if (empty($errors)) {
        $sql = 'SELECT id FROM kart_game_level_config WHERE level_number = ?';
        $params = [(int)$levelNumber];
        if ($excludeId !== null && $excludeId !== '') {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            $errors['level_number'] = 'level_number benzersiz olmalıdır.';
        }

        $stmt1 = $pdo->prepare('SELECT level_number FROM kart_game_level_config WHERE required_total_xp >= ?' . (($excludeId !== null && $excludeId !== '') ? ' AND id <> ?' : '') . ' ORDER BY required_total_xp ASC LIMIT 1');
        $stmt2 = $pdo->prepare('SELECT level_number FROM kart_game_level_config WHERE required_total_xp <= ?' . (($excludeId !== null && $excludeId !== '') ? ' AND id <> ?' : '') . ' ORDER BY required_total_xp DESC LIMIT 1');
        $p1 = [(int)$requiredTotalXp];
        $p2 = [(int)$requiredTotalXp];
        if ($excludeId !== null && $excludeId !== '') {
            $p1[] = $excludeId;
            $p2[] = $excludeId;
        }
        $stmt1->execute($p1);
        $stmt2->execute($p2);
        $nextLevel = (int)$stmt1->fetchColumn();
        $prevLevel = (int)$stmt2->fetchColumn();

        if ($nextLevel > 0 && $nextLevel < (int)$levelNumber) {
            $errors['required_total_xp'] = 'required_total_xp ascending kuralını bozuyor.';
        }
        if ($prevLevel > 0 && $prevLevel > (int)$levelNumber) {
            $errors['required_total_xp'] = 'required_total_xp ascending kuralını bozuyor.';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'level_number' => (int)$levelNumber,
            'required_total_xp' => (int)$requiredTotalXp,
        ],
    ];
}

function kg_create_level_config(PDO $pdo, array $payload): array
{
    $v = kg_validate_level_config_input($pdo, $payload, null);
    if (!$v['valid']) return ['success' => false, 'errors' => $v['errors'], 'message' => 'Doğrulama başarısız.'];
    $id = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO kart_game_level_config (id, level_number, required_total_xp, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    $stmt->execute([$id, $v['data']['level_number'], $v['data']['required_total_xp']]);
    return ['success' => true, 'item' => kg_get_level_config($pdo, $id)];
}

function kg_update_level_config(PDO $pdo, string $id, array $payload): array
{
    if (!kg_get_level_config($pdo, $id)) return ['success' => false, 'errors' => ['id' => 'Kayıt bulunamadı.'], 'message' => 'Kayıt bulunamadı.'];
    $v = kg_validate_level_config_input($pdo, $payload, $id);
    if (!$v['valid']) return ['success' => false, 'errors' => $v['errors'], 'message' => 'Doğrulama başarısız.'];
    $stmt = $pdo->prepare('UPDATE kart_game_level_config SET level_number=?, required_total_xp=?, updated_at=NOW() WHERE id=?');
    $stmt->execute([$v['data']['level_number'], $v['data']['required_total_xp'], $id]);
    return ['success' => true, 'item' => kg_get_level_config($pdo, $id)];
}

function kg_delete_level_config(PDO $pdo, string $id): bool
{
    $stmt = $pdo->prepare('DELETE FROM kart_game_level_config WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function kg_calculate_earned_xp(array $runInput, array $xpRule): int
{
    $correctCount = (int)($runInput['correct_count'] ?? 0);
    $wrongCount = (int)($runInput['wrong_count'] ?? 0);
    $maxCombo = (int)($runInput['max_combo'] ?? 0);
    $gameMode = strtolower(trim((string)($runInput['game_mode'] ?? 'normal')));

    $earned = ($correctCount * (int)$xpRule['correct_xp']) - ($wrongCount * (int)$xpRule['wrong_penalty']);
    $comboEvery = max(1, (int)$xpRule['combo_bonus_every']);
    $comboSteps = intdiv(max(0, $maxCombo), $comboEvery);
    $earned += ($comboSteps * (int)$xpRule['combo_bonus_xp']);

    if ($wrongCount === 0) {
        $earned += (int)$xpRule['perfect_game_bonus'];
    }

    if ($gameMode === 'endless') {
        $earned = (int)floor($earned * (float)$xpRule['endless_multiplier']);
    }

    return max(0, $earned);
}

function kg_resolve_level_from_total_xp(PDO $pdo, int $totalXp): array
{
    $stmt = $pdo->prepare('SELECT level_number, required_total_xp FROM kart_game_level_config WHERE required_total_xp <= ? ORDER BY required_total_xp DESC LIMIT 1');
    $stmt->execute([$totalXp]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['level_number' => 1, 'required_total_xp' => 0];

    $nextStmt = $pdo->prepare('SELECT level_number, required_total_xp FROM kart_game_level_config WHERE required_total_xp > ? ORDER BY required_total_xp ASC LIMIT 1');
    $nextStmt->execute([$totalXp]);
    $next = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'current_level' => (int)($current['level_number'] ?? 1),
        'current_level_xp' => (int)($current['required_total_xp'] ?? 0),
        'next_level_xp' => $next ? (int)$next['required_total_xp'] : null,
    ];
}

function kg_get_user_progress(PDO $pdo, string $userId, string $categoryId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM kart_game_user_progress WHERE user_id = ? AND category_id = ? LIMIT 1');
    $stmt->execute([$userId, $categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function kg_update_user_progress_after_run(PDO $pdo, string $userId, string $categoryId, array $runInput, int $earnedXp): array
{
    $existing = kg_get_user_progress($pdo, $userId, $categoryId);
    $totalXp = (int)($existing['total_xp'] ?? 0) + $earnedXp;
    $totalCorrect = (int)($existing['total_correct'] ?? 0) + (int)$runInput['correct_count'];
    $totalWrong = (int)($existing['total_wrong'] ?? 0) + (int)$runInput['wrong_count'];
    $bestCombo = max((int)($existing['best_combo'] ?? 0), (int)$runInput['max_combo']);
    $bestScore = max((int)($existing['best_score'] ?? 0), (int)$runInput['score']);
    $totalGames = (int)($existing['total_games'] ?? 0) + 1;
    $levelInfo = kg_resolve_level_from_total_xp($pdo, $totalXp);
    $currentLevel = (int)($levelInfo['current_level'] ?? 1);
    $progressCols = kg_table_columns($pdo, 'kart_game_user_progress');
    $hasCurrentLevel = in_array('current_level', $progressCols, true);

    if ($existing) {
        if ($hasCurrentLevel) {
            $stmt = $pdo->prepare('UPDATE kart_game_user_progress SET total_xp=?, current_level=?, total_correct=?, total_wrong=?, best_combo=?, best_score=?, total_games=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$totalXp, $currentLevel, $totalCorrect, $totalWrong, $bestCombo, $bestScore, $totalGames, $existing['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE kart_game_user_progress SET total_xp=?, total_correct=?, total_wrong=?, best_combo=?, best_score=?, total_games=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$totalXp, $totalCorrect, $totalWrong, $bestCombo, $bestScore, $totalGames, $existing['id']]);
        }
    } else {
        if ($hasCurrentLevel) {
            $stmt = $pdo->prepare('INSERT INTO kart_game_user_progress (id, user_id, category_id, total_xp, current_level, total_correct, total_wrong, best_combo, best_score, total_games, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([generate_uuid(), $userId, $categoryId, $totalXp, $currentLevel, $totalCorrect, $totalWrong, $bestCombo, $bestScore, $totalGames]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO kart_game_user_progress (id, user_id, category_id, total_xp, total_correct, total_wrong, best_combo, best_score, total_games, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
            $stmt->execute([generate_uuid(), $userId, $categoryId, $totalXp, $totalCorrect, $totalWrong, $bestCombo, $bestScore, $totalGames]);
        }
    }

    return [
        'total_xp' => $totalXp,
        'current_level' => $currentLevel,
        'total_correct' => $totalCorrect,
        'total_wrong' => $totalWrong,
        'best_combo' => $bestCombo,
        'best_score' => $bestScore,
        'total_games' => $totalGames,
    ];
}

function kg_save_run(PDO $pdo, string $userId, string $categoryId, array $runInput, int $earnedXp, int $totalXpAfter, int $levelAfter): void
{
    $stmt = $pdo->prepare('INSERT INTO kart_game_runs (id, user_id, category_id, game_mode, score, total_questions, correct_count, wrong_count, max_combo, duration_seconds, earned_xp, total_xp_after, level_after, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        generate_uuid(),
        $userId,
        $categoryId,
        (string)$runInput['game_mode'],
        (int)$runInput['score'],
        (int)$runInput['total_questions'],
        (int)$runInput['correct_count'],
        (int)$runInput['wrong_count'],
        (int)$runInput['max_combo'],
        (int)$runInput['duration_seconds'],
        $earnedXp,
        $totalXpAfter,
        $levelAfter,
    ]);
}

function kg_get_progress_summary(PDO $pdo, string $userId, string $categoryId): array
{
    $progress = kg_get_user_progress($pdo, $userId, $categoryId) ?: [
        'total_xp' => 0,
        'best_score' => 0,
        'best_combo' => 0,
        'total_games' => 0,
    ];

    $level = kg_resolve_level_from_total_xp($pdo, (int)$progress['total_xp']);
    $nextXp = $level['next_level_xp'];
    $currentXpFloor = $level['current_level_xp'];
    $percent = $nextXp === null
        ? 100
        : (int)max(0, min(100, floor((((int)$progress['total_xp'] - $currentXpFloor) / max(1, ($nextXp - $currentXpFloor))) * 100)));

    return [
        'total_xp' => (int)$progress['total_xp'],
        'current_level' => $level['current_level'],
        'current_level_xp' => $currentXpFloor,
        'next_level_xp' => $nextXp,
        'progress_percent' => $percent,
        'best_score' => (int)$progress['best_score'],
        'best_combo' => (int)$progress['best_combo'],
        'total_games' => (int)$progress['total_games'],
    ];
}

function kg_today(): string
{
    return date('Y-m-d');
}

function kg_get_daily_attempt_limit(PDO $pdo): int
{
    $settings = app_runtime_settings_get($pdo);
    return app_runtime_settings_int($settings, 'kart_game_daily_attempt_limit', 5);
}

function kg_get_daily_attempt_status(PDO $pdo, string $userId, string $categoryId): array
{
    $limit = max(0, kg_get_daily_attempt_limit($pdo));
    $used = 0;
    $today = kg_today();

    $stmt = $pdo->prepare(
        'SELECT attempts_used FROM kart_game_daily_attempts WHERE user_id = ? AND category_id = ? AND play_date = ? LIMIT 1'
    );
    $stmt->execute([$userId, $categoryId, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        $used = max(0, (int)($row['attempts_used'] ?? 0));
    }

    $remaining = max(0, $limit - $used);

    return [
        'limit' => $limit,
        'used' => $used,
        'remaining' => $remaining,
        'date' => $today,
        'is_available' => ($limit > 0 && $remaining > 0),
    ];
}

function kg_increment_daily_attempt(PDO $pdo, string $userId, string $categoryId): array
{
    $limit = max(0, kg_get_daily_attempt_limit($pdo));
    $today = kg_today();

    if ($limit <= 0) {
        return [
            'success' => false,
            'error_code' => 'DAILY_ATTEMPT_LIMIT_REACHED',
            'status' => kg_get_daily_attempt_status($pdo, $userId, $categoryId),
        ];
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        $select = $pdo->prepare(
            'SELECT id, attempts_used FROM kart_game_daily_attempts WHERE user_id = ? AND category_id = ? AND play_date = ? LIMIT 1 FOR UPDATE'
        );
        $select->execute([$userId, $categoryId, $today]);
        $row = $select->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            $newUsed = 1;
            if ($newUsed > $limit) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'error_code' => 'DAILY_ATTEMPT_LIMIT_REACHED',
                    'status' => kg_get_daily_attempt_status($pdo, $userId, $categoryId),
                ];
            }

            $insert = $pdo->prepare(
                'INSERT INTO kart_game_daily_attempts (id, user_id, category_id, play_date, attempts_used, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $insert->execute([generate_uuid(), $userId, $categoryId, $today, $newUsed]);
        } else {
            $used = max(0, (int)($row['attempts_used'] ?? 0));
            if ($used >= $limit) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'error_code' => 'DAILY_ATTEMPT_LIMIT_REACHED',
                    'status' => [
                        'limit' => $limit,
                        'used' => $used,
                        'remaining' => 0,
                        'date' => $today,
                        'is_available' => false,
                    ],
                ];
            }

            $update = $pdo->prepare('UPDATE kart_game_daily_attempts SET attempts_used = attempts_used + 1, updated_at = NOW() WHERE id = ?');
            $update->execute([(string)$row['id']]);
        }

        $statusStmt = $pdo->prepare(
            'SELECT attempts_used FROM kart_game_daily_attempts WHERE user_id = ? AND category_id = ? AND play_date = ? LIMIT 1'
        );
        $statusStmt->execute([$userId, $categoryId, $today]);
        $usedNow = (int)($statusStmt->fetchColumn() ?: 0);
        $status = [
            'limit' => $limit,
            'used' => max(0, $usedNow),
            'remaining' => max(0, $limit - $usedNow),
            'date' => $today,
            'is_available' => (($limit - $usedNow) > 0),
        ];

        if ($ownTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'status' => $status,
        ];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function kg_get_leaderboard(PDO $pdo, string $categoryId, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $profile = kg_profile_schema($pdo);

    $nameExpr = $profile['full_name'] ? 'NULLIF(TRIM(u.`' . $profile['full_name'] . '`), \'\')' : 'NULL';
    $emailExpr = $profile['email'] ? 'u.`' . $profile['email'] . '`' : '\'\'';
    $avatarExpr = $profile['profile_photo_url'] ? 'COALESCE(NULLIF(TRIM(u.`' . $profile['profile_photo_url'] . '`), \'\'), \'\')' : '\'\'';

    $sql = 'SELECT p.user_id, p.total_xp, p.best_score, p.best_combo, '
        . 'COALESCE(' . $nameExpr . ', ' . $emailExpr . ', p.user_id) AS username, '
        . $avatarExpr . ' AS avatar '
        . 'FROM kart_game_user_progress p '
        . 'LEFT JOIN `' . $profile['table'] . '` u ON u.`' . $profile['id'] . '` = p.user_id '
        . 'WHERE p.category_id = ? '
        . 'ORDER BY p.total_xp DESC, p.best_score DESC, p.best_combo DESC '
        . 'LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categoryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $idx => &$row) {
        $row['rank'] = $idx + 1;
        $lvl = kg_resolve_level_from_total_xp($pdo, (int)$row['total_xp']);
        $row['current_level'] = $lvl['current_level'];
        $row['avatar'] = upload_build_public_url((string)($row['avatar'] ?? ''));
    }
    unset($row);

    return $rows;
}

function kg_get_leaderboard_entry(PDO $pdo, string $categoryId, string $userId): ?array
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    $profile = kg_profile_schema($pdo);
    $nameExpr = $profile['full_name'] ? 'NULLIF(TRIM(u.`' . $profile['full_name'] . '`), \'\')' : 'NULL';
    $emailExpr = $profile['email'] ? 'u.`' . $profile['email'] . '`' : '\'\'';
    $avatarExpr = $profile['profile_photo_url'] ? 'COALESCE(NULLIF(TRIM(u.`' . $profile['profile_photo_url'] . '`), \'\'), \'\')' : '\'\'';

    $sql = 'SELECT p.user_id, p.total_xp, p.best_score, p.best_combo, '
        . 'COALESCE(' . $nameExpr . ', ' . $emailExpr . ', p.user_id) AS username, '
        . $avatarExpr . ' AS avatar '
        . 'FROM kart_game_user_progress p '
        . 'LEFT JOIN `' . $profile['table'] . '` u ON u.`' . $profile['id'] . '` = p.user_id '
        . 'WHERE p.category_id = ? AND p.user_id = ? '
        . 'LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categoryId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $rankStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM kart_game_user_progress p '
        . 'WHERE p.category_id = ? AND ('
        . 'p.total_xp > ? '
        . 'OR (p.total_xp = ? AND p.best_score > ?) '
        . 'OR (p.total_xp = ? AND p.best_score = ? AND p.best_combo > ?))'
    );
    $rankStmt->execute([
        $categoryId,
        (int)$row['total_xp'],
        (int)$row['total_xp'],
        (int)$row['best_score'],
        (int)$row['total_xp'],
        (int)$row['best_score'],
        (int)$row['best_combo'],
    ]);

    $higherCount = (int)$rankStmt->fetchColumn();
    $row['rank'] = $higherCount + 1;

    $lvl = kg_resolve_level_from_total_xp($pdo, (int)$row['total_xp']);
    $row['current_level'] = $lvl['current_level'];
    $row['avatar'] = upload_build_public_url((string)($row['avatar'] ?? ''));

    return $row;
}

function kg_get_active_leaderboard_season(PDO $pdo, string $categoryId): ?array
{
    $categoryId = trim($categoryId);
    if ($categoryId === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, category_id, title, reset_at, is_active '
        . 'FROM kart_game_leaderboard_seasons '
        . 'WHERE category_id = ? AND is_active = 1 '
        . 'ORDER BY reset_at ASC, created_at DESC '
        . 'LIMIT 1'
    );
    $stmt->execute([$categoryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function kg_get_leaderboard_rewards(PDO $pdo, string $seasonId): array
{
    $seasonId = trim($seasonId);
    if ($seasonId === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, season_id, rank_start, rank_end, reward_title, reward_description, is_active, sort_order '
        . 'FROM kart_game_leaderboard_rewards '
        . 'WHERE season_id = ? AND is_active = 1 '
        . 'ORDER BY rank_start ASC, rank_end ASC, sort_order ASC'
    );
    $stmt->execute([$seasonId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}








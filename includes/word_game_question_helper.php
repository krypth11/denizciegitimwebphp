<?php

require_once __DIR__ . '/functions.php';

function word_game_slugify(string $value): string
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
    return trim($value, '-');
}

function word_game_normalize_answer(string $answer): string
{
    $value = trim($answer);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = strtr($value, [
        'ç' => 'c', 'Ç' => 'C',
        'ğ' => 'g', 'Ğ' => 'G',
        'ı' => 'i', 'I' => 'I', 'İ' => 'I', 'i' => 'i',
        'ö' => 'o', 'Ö' => 'O',
        'ş' => 's', 'Ş' => 'S',
        'ü' => 'u', 'Ü' => 'U',
    ]);
    $value = mb_strtoupper($value, 'UTF-8');
    $value = preg_replace('/[^A-Z]+/u', '', $value) ?? '';

    return trim($value);
}

function word_game_answer_length(string $normalized): int
{
    return $normalized === '' ? 0 : mb_strlen($normalized, 'UTF-8');
}

function word_game_default_settings(): array
{
    return [
        'target_score' => 10000,
        'points_per_char' => 100,
        'min_questions' => 8,
        'max_questions' => 14,
        'duration_seconds' => 400,
        'allowed_lengths' => range(3, 24),
    ];
}

function word_game_parse_allowed_lengths_json(?string $json): array
{
    $default = word_game_default_settings()['allowed_lengths'];
    if ($json === null || trim($json) === '') {
        return $default;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $default;
    }

    $clean = [];
    foreach ($decoded as $len) {
        $len = (int)$len;
        if ($len >= 1 && $len <= 64) {
            $clean[$len] = true;
        }
    }
    $values = array_keys($clean);
    sort($values, SORT_NUMERIC);
    return !empty($values) ? $values : $default;
}

function word_game_get_settings(PDO $pdo): array
{
    $defaults = word_game_default_settings();
    $stmt = $pdo->prepare('SELECT * FROM word_game_settings WHERE id = 1 LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'target_score' => max(1, (int)($row['target_score'] ?? $defaults['target_score'])),
        'points_per_char' => max(1, (int)($row['points_per_char'] ?? $defaults['points_per_char'])),
        'min_questions' => max(1, (int)($row['min_questions'] ?? $defaults['min_questions'])),
        'max_questions' => max(1, (int)($row['max_questions'] ?? $defaults['max_questions'])),
        'duration_seconds' => max(30, (int)($row['duration_seconds'] ?? $defaults['duration_seconds'])),
        'allowed_lengths' => word_game_parse_allowed_lengths_json($row['allowed_lengths_json'] ?? null),
    ];
}

function word_game_settings_update(PDO $pdo, array $payload): array
{
    $targetScore = (int)($payload['target_score'] ?? 0);
    $pointsPerChar = (int)($payload['points_per_char'] ?? 0);
    $minQuestions = (int)($payload['min_questions'] ?? 0);
    $maxQuestions = (int)($payload['max_questions'] ?? 0);
    $durationSeconds = (int)($payload['duration_seconds'] ?? 0);
    $allowedLengthsRaw = $payload['allowed_lengths'] ?? [];
    if (!is_array($allowedLengthsRaw)) {
        $allowedLengthsRaw = [];
    }

    $errors = [];
    if ($targetScore <= 0) $errors['target_score'] = 'Toplam hedef puan 0’dan büyük olmalıdır.';
    if ($pointsPerChar <= 0) $errors['points_per_char'] = 'Harf başı puan 0’dan büyük olmalıdır.';
    if ($targetScore > 0 && $pointsPerChar > 0 && $targetScore % $pointsPerChar !== 0) {
        $errors['target_score'] = 'Toplam hedef puan, harf başı puana tam bölünmelidir.';
    }
    if ($minQuestions < 1) $errors['min_questions'] = 'Minimum soru sayısı en az 1 olmalıdır.';
    if ($maxQuestions < $minQuestions) $errors['max_questions'] = 'Maksimum soru sayısı minimumdan küçük olamaz.';

    $allowed = [];
    foreach ($allowedLengthsRaw as $len) {
        $len = (int)$len;
        if ($len >= 1 && $len <= 64) {
            $allowed[$len] = true;
        }
    }
    $allowed = array_keys($allowed);
    sort($allowed, SORT_NUMERIC);
    if (empty($allowed)) {
        $errors['allowed_lengths'] = 'En az bir karakter uzunluğu seçilmelidir.';
    }

    if (!empty($errors)) {
        return ['success' => false, 'message' => 'Lütfen ayar hatalarını düzeltin.', 'errors' => $errors];
    }

    $allowedJson = json_encode($allowed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sql = 'INSERT INTO word_game_settings (id, target_score, points_per_char, min_questions, max_questions, duration_seconds, allowed_lengths_json, updated_at)
            VALUES (1, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              target_score = VALUES(target_score),
              points_per_char = VALUES(points_per_char),
              min_questions = VALUES(min_questions),
              max_questions = VALUES(max_questions),
              duration_seconds = VALUES(duration_seconds),
              allowed_lengths_json = VALUES(allowed_lengths_json),
              updated_at = NOW()';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$targetScore, $pointsPerChar, $minQuestions, $maxQuestions, $durationSeconds, $allowedJson]);

    return ['success' => true, 'settings' => word_game_get_settings($pdo)];
}

function word_game_category_ensure_slug_unique(PDO $pdo, string $slug, ?string $excludeId = null): string
{
    $base = trim($slug);
    if ($base === '') {
        $base = 'kelime-oyunu-' . date('YmdHis');
    }
    $candidate = $base;
    $i = 1;
    while (true) {
        $sql = 'SELECT id FROM word_game_categories WHERE slug = ?';
        $params = [$candidate];
        if ($excludeId !== null && $excludeId !== '') {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
        $candidate = $base . '-' . (++$i);
    }
}

function word_game_list_categories(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(c.name LIKE ? OR c.slug LIKE ? OR c.description LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $isActive = (string)($filters['is_active'] ?? '');
    if ($isActive === '0' || $isActive === '1') {
        $where[] = 'c.is_active = ?';
        $params[] = (int)$isActive;
    }

    $sql = 'SELECT c.*,
            (SELECT COUNT(*) FROM word_game_category_qualifications m WHERE m.category_id = c.id) AS qualification_count,
            (SELECT COUNT(*) FROM word_game_questions q WHERE q.category_id = c.id) AS question_count
            FROM word_game_categories c
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY COALESCE(c.order_index,0) ASC, c.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function word_game_get_category(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM word_game_categories WHERE id = ? LIMIT 1');
    $stmt->execute([trim($id)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function word_game_create_category(PDO $pdo, array $payload): array
{
    $name = trim((string)($payload['name'] ?? ''));
    $slugRaw = trim((string)($payload['slug'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $orderIndex = filter_var($payload['order_index'] ?? 0, FILTER_VALIDATE_INT);
    $isActive = in_array((string)($payload['is_active'] ?? '0'), ['1', 'true', 'on'], true) ? 1 : 0;

    if ($name === '') {
        return ['success' => false, 'message' => 'Başlık zorunludur.', 'errors' => ['name' => 'Başlık zorunludur.']];
    }
    $slug = word_game_category_ensure_slug_unique($pdo, word_game_slugify($slugRaw !== '' ? $slugRaw : $name));
    $id = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO word_game_categories (id,name,slug,description,is_active,order_index,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([$id, $name, $slug, ($description !== '' ? $description : null), $isActive, ($orderIndex === false ? 0 : (int)$orderIndex)]);
    return ['success' => true, 'item' => word_game_get_category($pdo, $id)];
}

function word_game_update_category(PDO $pdo, string $id, array $payload): array
{
    if (!word_game_get_category($pdo, $id)) {
        return ['success' => false, 'message' => 'Kayıt bulunamadı.', 'errors' => ['id' => 'Kayıt bulunamadı.']];
    }
    $name = trim((string)($payload['name'] ?? ''));
    $slugRaw = trim((string)($payload['slug'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $orderIndex = filter_var($payload['order_index'] ?? 0, FILTER_VALIDATE_INT);
    $isActive = in_array((string)($payload['is_active'] ?? '0'), ['1', 'true', 'on'], true) ? 1 : 0;
    if ($name === '') {
        return ['success' => false, 'message' => 'Başlık zorunludur.', 'errors' => ['name' => 'Başlık zorunludur.']];
    }
    $slug = word_game_category_ensure_slug_unique($pdo, word_game_slugify($slugRaw !== '' ? $slugRaw : $name), $id);
    $stmt = $pdo->prepare('UPDATE word_game_categories SET name=?, slug=?, description=?, is_active=?, order_index=?, updated_at=NOW() WHERE id=?');
    $stmt->execute([$name, $slug, ($description !== '' ? $description : null), $isActive, ($orderIndex === false ? 0 : (int)$orderIndex), $id]);
    return ['success' => true, 'item' => word_game_get_category($pdo, $id)];
}

function word_game_delete_category(PDO $pdo, string $categoryId): bool
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM word_game_category_qualifications WHERE category_id = ?')->execute([$categoryId]);
        $pdo->prepare('DELETE FROM word_game_questions WHERE category_id = ?')->execute([$categoryId]);
        $stmt = $pdo->prepare('DELETE FROM word_game_categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        $pdo->commit();
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function word_game_list_categories_for_mapping(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id,name,slug,is_active,order_index FROM word_game_categories ORDER BY COALESCE(order_index,0), name');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function word_game_list_qualifications(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id,name FROM qualifications ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function word_game_category_qualification_map(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT category_id, qualification_id FROM word_game_category_qualifications');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $cid = (string)($row['category_id'] ?? '');
        $qid = (string)($row['qualification_id'] ?? '');
        if ($cid !== '' && $qid !== '') {
            $map[$cid][] = $qid;
        }
    }
    return $map;
}

function word_game_save_category_qualifications(PDO $pdo, string $categoryId, array $qualificationIds): void
{
    $ids = [];
    foreach ($qualificationIds as $qid) {
        $qid = trim((string)$qid);
        if ($qid !== '') $ids[$qid] = true;
    }
    $ids = array_keys($ids);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM word_game_category_qualifications WHERE category_id = ?')->execute([$categoryId]);
        if (!empty($ids)) {
            $insert = $pdo->prepare('INSERT INTO word_game_category_qualifications (id,category_id,qualification_id,created_at) VALUES (?,?,?,NOW())');
            foreach ($ids as $qid) {
                $insert->execute([generate_uuid(), $categoryId, $qid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function word_game_first_category_qualification_id(PDO $pdo, string $categoryId): ?string
{
    $stmt = $pdo->prepare('SELECT qualification_id FROM word_game_category_qualifications WHERE category_id = ? ORDER BY created_at ASC LIMIT 1');
    $stmt->execute([$categoryId]);
    $val = trim((string)$stmt->fetchColumn());
    return $val !== '' ? $val : null;
}

function word_game_validate_question(PDO $pdo, array $data, ?string $ignoreId = null): array
{
    $errors = [];

    $categoryId = trim((string)($data['category_id'] ?? ''));
    $qualificationId = trim((string)($data['qualification_id'] ?? ''));
    $questionTextRaw = trim((string)($data['question_text'] ?? ''));
    $questionTextEnRaw = trim((string)($data['question_text_en'] ?? ''));
    $answerTextRaw = trim((string)($data['answer_text'] ?? ''));
    $answerTextEnRaw = trim((string)($data['answer_text_en'] ?? ''));
    $notesRaw = trim((string)($data['notes'] ?? ''));

    $questionText = sanitize_input($questionTextRaw);
    $questionTextEn = sanitize_input($questionTextEnRaw);
    $answerText = sanitize_input($answerTextRaw);
    $answerTextEn = sanitize_input($answerTextEnRaw);
    $notes = sanitize_input($notesRaw);

    $normalized = word_game_normalize_answer($answerTextRaw);
    $answerLength = word_game_answer_length($normalized);
    $normalizedEn = word_game_normalize_answer($answerTextEnRaw);
    $answerLengthEn = word_game_answer_length($normalizedEn);

    $orderIndex = filter_var($data['order_index'] ?? 0, FILTER_VALIDATE_INT);
    if ($orderIndex === false) $orderIndex = 0;
    $isActive = in_array((string)($data['is_active'] ?? 0), ['1', 'true', 'on'], true) ? 1 : 0;

    if ($categoryId === '') $errors['category_id'] = 'Başlık seçimi zorunludur.';
    if ($questionTextRaw === '') $errors['question_text'] = 'Türkçe soru metni zorunludur.';
    if ($answerTextRaw === '') $errors['answer_text'] = 'Türkçe doğru cevap zorunludur.';
    if ($normalized === '') $errors['answer_text'] = 'Türkçe cevap normalize edilemedi. Sadece harflerden oluşmalı.';
    if ($answerLength < 1) $errors['answer_length'] = 'Türkçe cevap uzunluğu en az 1 olmalıdır.';
    if ($answerTextEnRaw !== '' && $normalizedEn === '') $errors['answer_text_en'] = 'İngilizce cevap normalize edilemedi.';

    if ($categoryId !== '') {
        $cat = word_game_get_category($pdo, $categoryId);
        if (!$cat) $errors['category_id'] = 'Seçilen başlık bulunamadı.';
    }

    if ($qualificationId === '' && $categoryId !== '') {
        $fallbackQid = word_game_first_category_qualification_id($pdo, $categoryId);
        if ($fallbackQid !== null) {
            $qualificationId = $fallbackQid;
        }
    }

    if ($qualificationId !== '') {
        $qStmt = $pdo->prepare('SELECT COUNT(*) FROM qualifications WHERE id = ?');
        $qStmt->execute([$qualificationId]);
        if ((int)$qStmt->fetchColumn() < 1) {
            $errors['qualification_id'] = 'Geçerli bir yeterlilik seçiniz.';
        }
    }

    if ($categoryId !== '' && $normalized !== '') {
        $sql = 'SELECT id FROM word_game_questions WHERE category_id = ? AND answer_normalized = ?';
        $params = [$categoryId, $normalized];
        if ($ignoreId !== null && trim($ignoreId) !== '') {
            $sql .= ' AND id <> ?';
            $params[] = trim($ignoreId);
        }
        $sql .= ' LIMIT 1';
        $dup = $pdo->prepare($sql);
        $dup->execute($params);
        if ($dup->fetchColumn()) {
            $errors['answer_text'] = 'Aynı başlık altında bu cevap zaten mevcut.';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'category_id' => $categoryId,
            'qualification_id' => $qualificationId !== '' ? $qualificationId : null,
            'question_text' => $questionText,
            'question_text_en' => $questionTextEn !== '' ? $questionTextEn : null,
            'answer_text' => $answerText,
            'answer_normalized' => $normalized,
            'answer_length' => $answerLength,
            'answer_text_en' => $answerTextEn !== '' ? $answerTextEn : null,
            'answer_normalized_en' => $normalizedEn !== '' ? $normalizedEn : null,
            'answer_length_en' => $answerLengthEn > 0 ? $answerLengthEn : null,
            'is_active' => $isActive,
            'order_index' => (int)$orderIndex,
            'notes' => $notes !== '' ? $notes : null,
        ],
    ];
}

function word_game_list(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    $categoryId = trim((string)($filters['category_id'] ?? ''));
    if ($categoryId !== '') {
        $where[] = 'wq.category_id = ?';
        $params[] = $categoryId;
    }
    $qualificationId = trim((string)($filters['qualification_id'] ?? ''));
    if ($qualificationId !== '') {
        $where[] = 'wq.qualification_id = ?';
        $params[] = $qualificationId;
    }
    if (isset($filters['is_active']) && $filters['is_active'] !== '') {
        $where[] = 'wq.is_active = ?';
        $params[] = ((int)$filters['is_active'] === 1 ? 1 : 0);
    }
    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(wq.question_text LIKE ? OR wq.question_text_en LIKE ? OR wq.answer_text LIKE ? OR wq.answer_text_en LIKE ? OR wq.answer_normalized LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $sql = 'SELECT wq.*, q.name AS qualification_name, c.name AS category_name
            FROM word_game_questions wq
            LEFT JOIN qualifications q ON q.id = wq.qualification_id
            LEFT JOIN word_game_categories c ON c.id = wq.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.order_index ASC, c.name ASC, wq.order_index ASC, wq.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function word_game_get(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM word_game_questions WHERE id = ? LIMIT 1');
    $stmt->execute([trim($id)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function word_game_create(PDO $pdo, array $data): array
{
    $validation = word_game_validate_question($pdo, $data, null);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => 'Lütfen formdaki hataları düzeltin.', 'errors' => $validation['errors']];
    }
    $p = $validation['data'];
    $id = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO word_game_questions (
        id, qualification_id, category_id, question_text, question_text_en,
        answer_text, answer_normalized, answer_length,
        answer_text_en, answer_normalized_en, answer_length_en,
        is_active, order_index, notes, created_at, updated_at
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([
        $id, $p['qualification_id'], $p['category_id'], $p['question_text'], $p['question_text_en'],
        $p['answer_text'], $p['answer_normalized'], $p['answer_length'],
        $p['answer_text_en'], $p['answer_normalized_en'], $p['answer_length_en'],
        $p['is_active'], $p['order_index'], $p['notes'],
    ]);
    return ['success' => true, 'id' => $id, 'item' => word_game_get($pdo, $id)];
}

function word_game_update(PDO $pdo, string $id, array $data): array
{
    $id = trim($id);
    if ($id === '') {
        return ['success' => false, 'message' => 'ID bilgisi gerekli.', 'errors' => ['id' => 'ID zorunludur.']];
    }
    if (!word_game_get($pdo, $id)) {
        return ['success' => false, 'message' => 'Kayıt bulunamadı.', 'errors' => ['id' => 'Kayıt mevcut değil.']];
    }

    $validation = word_game_validate_question($pdo, $data, $id);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => 'Lütfen formdaki hataları düzeltin.', 'errors' => $validation['errors']];
    }

    $p = $validation['data'];
    $stmt = $pdo->prepare('UPDATE word_game_questions SET
        qualification_id=?, category_id=?, question_text=?, question_text_en=?,
        answer_text=?, answer_normalized=?, answer_length=?,
        answer_text_en=?, answer_normalized_en=?, answer_length_en=?,
        is_active=?, order_index=?, notes=?, updated_at=NOW()
        WHERE id=?');
    $stmt->execute([
        $p['qualification_id'], $p['category_id'], $p['question_text'], $p['question_text_en'],
        $p['answer_text'], $p['answer_normalized'], $p['answer_length'],
        $p['answer_text_en'], $p['answer_normalized_en'], $p['answer_length_en'],
        $p['is_active'], $p['order_index'], $p['notes'], $id,
    ]);
    return ['success' => true, 'item' => word_game_get($pdo, $id)];
}

function word_game_delete(PDO $pdo, string $id): void
{
    $stmt = $pdo->prepare('DELETE FROM word_game_questions WHERE id = ?');
    $stmt->execute([trim($id)]);
}

function word_game_toggle_active(PDO $pdo, string $id, bool $isActive): void
{
    $stmt = $pdo->prepare('UPDATE word_game_questions SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$isActive ? 1 : 0, trim($id)]);
}

function word_game_parse_bulk_pattern_text(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
        return ['items' => [], 'errors' => ['empty' => 'Pattern metni boş olamaz.']];
    }

    $lines = explode("\n", $text);
    $records = [];
    $current = null;

    $flush = static function () use (&$records, &$current): void {
        if ($current !== null) {
            $records[] = $current;
        }
        $current = null;
    };

    foreach ($lines as $lineNo => $lineRaw) {
        $line = trim($lineRaw);
        if ($line === '') continue;
        if (preg_match('/^###\s+(.+)$/u', $line, $m)) {
            $flush();
            $current = ['title' => trim($m[1]), 'tr_question' => '', 'en_question' => '', 'tr_answer' => '', 'en_answer' => '', 'note' => '', '_line' => $lineNo + 1];
            continue;
        }
        if ($current === null) continue;

        if (str_starts_with($line, 'TR_SORU:')) $current['tr_question'] = trim(substr($line, strlen('TR_SORU:')));
        elseif (str_starts_with($line, 'EN_QUESTION:')) $current['en_question'] = trim(substr($line, strlen('EN_QUESTION:')));
        elseif (str_starts_with($line, 'TR_CEVAP:')) $current['tr_answer'] = trim(substr($line, strlen('TR_CEVAP:')));
        elseif (str_starts_with($line, 'EN_ANSWER:')) $current['en_answer'] = trim(substr($line, strlen('EN_ANSWER:')));
        elseif (str_starts_with($line, 'NOTE:')) $current['note'] = trim(substr($line, strlen('NOTE:')));
    }
    $flush();

    $items = [];
    $errors = [];
    foreach ($records as $idx => $r) {
        $rowErrors = [];
        if (trim((string)$r['tr_question']) === '') $rowErrors[] = 'TR_SORU zorunludur.';
        if (trim((string)$r['tr_answer']) === '') $rowErrors[] = 'TR_CEVAP zorunludur.';
        $items[] = ['index' => $idx, 'line' => $r['_line'], 'record' => $r, 'errors' => $rowErrors, 'valid' => empty($rowErrors)];
    }

    if (empty($items)) {
        $errors['empty'] = 'Pattern içinde kayıt bulunamadı. Her kayıt ### WORD ile başlamalı.';
    }

    return ['items' => $items, 'errors' => $errors];
}

<?php

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
    if ($normalized === '') {
        return 0;
    }

    return mb_strlen($normalized, 'UTF-8');
}

function word_game_validate_question(array $data): array
{
    $errors = [];

    $qualificationId = trim((string)($data['qualification_id'] ?? ''));
    $questionTextRaw = trim((string)($data['question_text'] ?? ''));
    $answerTextRaw = trim((string)($data['answer_text'] ?? ''));
    $notesRaw = trim((string)($data['notes'] ?? ''));

    $questionText = function_exists('sanitize_input') ? sanitize_input($questionTextRaw) : $questionTextRaw;
    $answerText = function_exists('sanitize_input') ? sanitize_input($answerTextRaw) : $answerTextRaw;
    $notes = function_exists('sanitize_input') ? sanitize_input($notesRaw) : $notesRaw;

    $normalized = word_game_normalize_answer($answerTextRaw);
    $answerLength = word_game_answer_length($normalized);

    $orderIndex = filter_var($data['order_index'] ?? 0, FILTER_VALIDATE_INT);
    if ($orderIndex === false) {
        $orderIndex = 0;
    }

    $isActiveRaw = $data['is_active'] ?? 0;
    $isActive = in_array((string)$isActiveRaw, ['1', 'true', 'on'], true) ? 1 : 0;

    if ($qualificationId === '') {
        $errors['qualification_id'] = 'Yeterlilik seçimi zorunludur.';
    }

    if ($questionTextRaw === '') {
        $errors['question_text'] = 'Soru metni zorunludur.';
    }

    if ($answerTextRaw === '') {
        $errors['answer_text'] = 'Doğru cevap zorunludur.';
    }

    if ($normalized === '') {
        $errors['answer_text'] = 'Cevap oyun sistemine uygun değil. Sadece harflerden oluşan bir cevap girin.';
    }

    if ($answerLength < 4 || $answerLength > 10) {
        $errors['answer_length'] = 'Normalize cevap uzunluğu 4 ile 10 karakter arasında olmalıdır.';
    }

    if ($normalized !== '' && !preg_match('/^[A-Z]+$/', $normalized)) {
        $errors['answer_text'] = 'Cevap yalnızca harflerden oluşmalıdır.';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => [
            'qualification_id' => $qualificationId,
            'question_text' => $questionText,
            'answer_text' => $answerText,
            'answer_normalized' => $normalized,
            'answer_length' => $answerLength,
            'is_active' => $isActive,
            'order_index' => (int)$orderIndex,
            'notes' => $notes,
        ],
    ];
}

function word_game_list(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];

    $qualificationId = trim((string)($filters['qualification_id'] ?? ''));
    if ($qualificationId !== '') {
        $where[] = 'wq.qualification_id = ?';
        $params[] = $qualificationId;
    }

    if (isset($filters['is_active']) && $filters['is_active'] !== '') {
        $isActive = (int)$filters['is_active'] === 1 ? 1 : 0;
        $where[] = 'wq.is_active = ?';
        $params[] = $isActive;
    }

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(wq.question_text LIKE ? OR wq.answer_text LIKE ? OR wq.answer_normalized LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = 'SELECT wq.*, q.name AS qualification_name
            FROM word_game_questions wq
            LEFT JOIN qualifications q ON q.id = wq.qualification_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY q.name ASC, wq.order_index ASC, wq.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function word_game_get(PDO $pdo, string $id): ?array
{
    $questionId = trim($id);
    if ($questionId === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM word_game_questions WHERE id = ? LIMIT 1');
    $stmt->execute([$questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_create(PDO $pdo, array $data): array
{
    $validation = word_game_validate_question($data);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'message' => 'Lütfen formdaki hataları düzeltin.',
            'errors' => $validation['errors'],
        ];
    }

    $payload = $validation['data'];

    $qualificationExists = $pdo->prepare('SELECT COUNT(*) FROM qualifications WHERE id = ?');
    $qualificationExists->execute([$payload['qualification_id']]);
    if ((int)$qualificationExists->fetchColumn() < 1) {
        return [
            'success' => false,
            'message' => 'Seçilen yeterlilik bulunamadı.',
            'errors' => ['qualification_id' => 'Geçerli bir yeterlilik seçiniz.'],
        ];
    }

    $dupStmt = $pdo->prepare('SELECT id FROM word_game_questions WHERE qualification_id = ? AND answer_normalized = ? LIMIT 1');
    $dupStmt->execute([$payload['qualification_id'], $payload['answer_normalized']]);
    if ($dupStmt->fetchColumn()) {
        return [
            'success' => false,
            'message' => 'Aynı yeterlilik altında bu cevap zaten mevcut.',
            'errors' => ['answer_text' => 'Bu normalize cevap aynı yeterlilikte tekrar edemez.'],
        ];
    }

    $id = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
    $stmt = $pdo->prepare(
        'INSERT INTO word_game_questions (
            id, qualification_id, question_text, answer_text, answer_normalized, answer_length,
            is_active, order_index, notes, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );

    $stmt->execute([
        $id,
        $payload['qualification_id'],
        $payload['question_text'],
        $payload['answer_text'],
        $payload['answer_normalized'],
        $payload['answer_length'],
        $payload['is_active'],
        $payload['order_index'],
        $payload['notes'] !== '' ? $payload['notes'] : null,
    ]);

    return [
        'success' => true,
        'id' => $id,
        'item' => word_game_get($pdo, $id),
    ];
}

function word_game_update(PDO $pdo, string $id, array $data): array
{
    $questionId = trim($id);
    if ($questionId === '') {
        return [
            'success' => false,
            'message' => 'ID bilgisi gerekli.',
            'errors' => ['id' => 'ID zorunludur.'],
        ];
    }

    if (!word_game_get($pdo, $questionId)) {
        return [
            'success' => false,
            'message' => 'Kayıt bulunamadı.',
            'errors' => ['id' => 'Kayıt mevcut değil.'],
        ];
    }

    $validation = word_game_validate_question($data);
    if (!$validation['valid']) {
        return [
            'success' => false,
            'message' => 'Lütfen formdaki hataları düzeltin.',
            'errors' => $validation['errors'],
        ];
    }

    $payload = $validation['data'];

    $qualificationExists = $pdo->prepare('SELECT COUNT(*) FROM qualifications WHERE id = ?');
    $qualificationExists->execute([$payload['qualification_id']]);
    if ((int)$qualificationExists->fetchColumn() < 1) {
        return [
            'success' => false,
            'message' => 'Seçilen yeterlilik bulunamadı.',
            'errors' => ['qualification_id' => 'Geçerli bir yeterlilik seçiniz.'],
        ];
    }

    $dupStmt = $pdo->prepare('SELECT id FROM word_game_questions WHERE qualification_id = ? AND answer_normalized = ? AND id <> ? LIMIT 1');
    $dupStmt->execute([$payload['qualification_id'], $payload['answer_normalized'], $questionId]);
    if ($dupStmt->fetchColumn()) {
        return [
            'success' => false,
            'message' => 'Aynı yeterlilik altında bu cevap zaten mevcut.',
            'errors' => ['answer_text' => 'Bu normalize cevap aynı yeterlilikte tekrar edemez.'],
        ];
    }

    $stmt = $pdo->prepare(
        'UPDATE word_game_questions SET
            qualification_id = ?,
            question_text = ?,
            answer_text = ?,
            answer_normalized = ?,
            answer_length = ?,
            is_active = ?,
            order_index = ?,
            notes = ?,
            updated_at = NOW()
         WHERE id = ?'
    );

    $stmt->execute([
        $payload['qualification_id'],
        $payload['question_text'],
        $payload['answer_text'],
        $payload['answer_normalized'],
        $payload['answer_length'],
        $payload['is_active'],
        $payload['order_index'],
        $payload['notes'] !== '' ? $payload['notes'] : null,
        $questionId,
    ]);

    return [
        'success' => true,
        'item' => word_game_get($pdo, $questionId),
    ];
}

function word_game_delete(PDO $pdo, string $id): void
{
    $questionId = trim($id);
    if ($questionId === '') {
        throw new InvalidArgumentException('ID zorunludur.');
    }

    $stmt = $pdo->prepare('DELETE FROM word_game_questions WHERE id = ?');
    $stmt->execute([$questionId]);
}

function word_game_toggle_active(PDO $pdo, string $id, bool $isActive): void
{
    $questionId = trim($id);
    if ($questionId === '') {
        throw new InvalidArgumentException('ID zorunludur.');
    }

    $stmt = $pdo->prepare('UPDATE word_game_questions SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$isActive ? 1 : 0, $questionId]);
}

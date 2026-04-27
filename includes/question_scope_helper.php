<?php

require_once __DIR__ . '/functions.php';

function question_scope_normalize_topic_id($topicId): string
{
    if ($topicId === null) {
        return '';
    }

    $normalized = trim((string)$topicId);
    return $normalized;
}

function question_scope_has_links_table(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cols = get_table_columns($pdo, 'question_scope_links');
    $required = ['id', 'question_id', 'qualification_id', 'course_id', 'topic_id', 'is_primary'];
    if (!$cols) {
        $cache = false;
        return false;
    }

    foreach ($required as $col) {
        if (!in_array($col, $cols, true)) {
            $cache = false;
            return false;
        }
    }

    $cache = true;
    return true;
}

function question_scope_validate_scope(PDO $pdo, string $qualificationId, string $courseId, ?string $topicId): void
{
    $qualificationId = trim($qualificationId);
    $courseId = trim($courseId);
    $topicId = question_scope_normalize_topic_id($topicId);

    if ($qualificationId === '' || $courseId === '') {
        throw new RuntimeException('Yeterlilik ve ders zorunludur.');
    }

    $courseStmt = $pdo->prepare('SELECT id, qualification_id FROM courses WHERE id = ? LIMIT 1');
    $courseStmt->execute([$courseId]);
    $course = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        throw new RuntimeException('Ders bulunamadı.');
    }

    if ((string)($course['qualification_id'] ?? '') !== $qualificationId) {
        throw new RuntimeException('Seçilen ders, seçilen yeterliliğe ait değil.');
    }

    if ($topicId !== '') {
        $topicStmt = $pdo->prepare('SELECT id, course_id FROM topics WHERE id = ? LIMIT 1');
        $topicStmt->execute([$topicId]);
        $topic = $topicStmt->fetch(PDO::FETCH_ASSOC);
        if (!$topic) {
            throw new RuntimeException('Konu bulunamadı.');
        }
        if ((string)($topic['course_id'] ?? '') !== $courseId) {
            throw new RuntimeException('Seçilen konu bu derse ait değil.');
        }
    }
}

function question_scope_ensure_primary(PDO $pdo, string $questionId): void
{
    if (!question_scope_has_links_table($pdo)) {
        return;
    }

    $questionId = trim($questionId);
    if ($questionId === '') {
        return;
    }

    $rowsStmt = $pdo->prepare('SELECT id, is_primary FROM question_scope_links WHERE question_id = ? ORDER BY is_primary DESC, id ASC');
    $rowsStmt->execute([$questionId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return;
    }

    $firstPrimaryId = null;
    foreach ($rows as $row) {
        if ((int)($row['is_primary'] ?? 0) === 1) {
            $firstPrimaryId = (string)$row['id'];
            break;
        }
    }

    if ($firstPrimaryId === null) {
        $firstPrimaryId = (string)$rows[0]['id'];
    }

    $resetStmt = $pdo->prepare('UPDATE question_scope_links SET is_primary = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE question_id = ?');
    $resetStmt->execute([$firstPrimaryId, $questionId]);
}

function question_scope_list_for_question(PDO $pdo, string $questionId): array
{
    if (!question_scope_has_links_table($pdo)) {
        return [];
    }

    $questionId = trim($questionId);
    if ($questionId === '') {
        return [];
    }

    $sql = 'SELECT qsl.id, qsl.question_id, qsl.qualification_id, qsl.course_id, qsl.topic_id, qsl.is_primary,
                   q.name AS qualification_name,
                   c.name AS course_name,
                   t.name AS topic_name
            FROM question_scope_links qsl
            LEFT JOIN qualifications q ON q.id = qsl.qualification_id
            LEFT JOIN courses c ON c.id = qsl.course_id
            LEFT JOIN topics t ON t.id = NULLIF(qsl.topic_id, \'\')
            WHERE qsl.question_id = ?
            ORDER BY qsl.is_primary DESC, qsl.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$questionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['topic_id'] = question_scope_normalize_topic_id($row['topic_id'] ?? '');
        $row['topic_name'] = ($row['topic_id'] === '') ? null : ($row['topic_name'] ?? null);
        $row['is_primary'] = ((int)($row['is_primary'] ?? 0) === 1) ? 1 : 0;
    }
    unset($row);

    return $rows;
}

function question_scope_add(PDO $pdo, string $questionId, string $qualificationId, string $courseId, ?string $topicId, int $isPrimary = 0): array
{
    if (!question_scope_has_links_table($pdo)) {
        throw new RuntimeException('question_scope_links tablosu bulunamadı.');
    }

    $questionId = trim($questionId);
    $qualificationId = trim($qualificationId);
    $courseId = trim($courseId);
    $topicId = question_scope_normalize_topic_id($topicId);
    $isPrimary = ($isPrimary === 1) ? 1 : 0;

    if ($questionId === '') {
        throw new RuntimeException('question_id zorunludur.');
    }

    $questionStmt = $pdo->prepare('SELECT id FROM questions WHERE id = ? LIMIT 1');
    $questionStmt->execute([$questionId]);
    if (!$questionStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Soru bulunamadı.');
    }

    question_scope_validate_scope($pdo, $qualificationId, $courseId, $topicId);

    $dupStmt = $pdo->prepare('SELECT id FROM question_scope_links WHERE question_id = ? AND qualification_id = ? AND course_id = ? AND topic_id = ? LIMIT 1');
    $dupStmt->execute([$questionId, $qualificationId, $courseId, $topicId]);
    if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Bu kapsam zaten ekli.');
    }

    if ($isPrimary === 1) {
        $pdo->prepare('UPDATE question_scope_links SET is_primary = 0 WHERE question_id = ?')->execute([$questionId]);
    }

    $id = generate_uuid();
    $insertStmt = $pdo->prepare('INSERT INTO question_scope_links (id, question_id, qualification_id, course_id, topic_id, is_primary) VALUES (?, ?, ?, ?, ?, ?)');
    $insertStmt->execute([$id, $questionId, $qualificationId, $courseId, $topicId, $isPrimary]);

    question_scope_ensure_primary($pdo, $questionId);

    $rows = question_scope_list_for_question($pdo, $questionId);
    foreach ($rows as $row) {
        if ((string)$row['id'] === $id) {
            return $row;
        }
    }

    return [
        'id' => $id,
        'question_id' => $questionId,
        'qualification_id' => $qualificationId,
        'course_id' => $courseId,
        'topic_id' => $topicId,
        'is_primary' => $isPrimary,
    ];
}

function question_scope_delete(PDO $pdo, string $scopeId): void
{
    if (!question_scope_has_links_table($pdo)) {
        return;
    }

    $scopeId = trim($scopeId);
    if ($scopeId === '') {
        throw new RuntimeException('scope_id zorunludur.');
    }

    $stmt = $pdo->prepare('SELECT id, question_id, is_primary FROM question_scope_links WHERE id = ? LIMIT 1');
    $stmt->execute([$scopeId]);
    $scope = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$scope) {
        throw new RuntimeException('Kapsam bulunamadı.');
    }

    if ((int)($scope['is_primary'] ?? 0) === 1) {
        throw new RuntimeException('Primary kapsam silinemez. Önce başka bir kapsamı primary yapın.');
    }

    $deleteStmt = $pdo->prepare('DELETE FROM question_scope_links WHERE id = ?');
    $deleteStmt->execute([$scopeId]);

    question_scope_ensure_primary($pdo, (string)$scope['question_id']);
}

function question_scope_replace_primary_from_question(PDO $pdo, string $questionId): void
{
    if (!question_scope_has_links_table($pdo)) {
        return;
    }

    $questionId = trim($questionId);
    if ($questionId === '') {
        return;
    }

    $qStmt = $pdo->prepare('SELECT q.id, q.course_id, q.topic_id, c.qualification_id
                            FROM questions q
                            LEFT JOIN courses c ON c.id = q.course_id
                            WHERE q.id = ?
                            LIMIT 1');
    $qStmt->execute([$questionId]);
    $question = $qStmt->fetch(PDO::FETCH_ASSOC);
    if (!$question) {
        return;
    }

    $qualificationId = trim((string)($question['qualification_id'] ?? ''));
    $courseId = trim((string)($question['course_id'] ?? ''));
    $topicId = question_scope_normalize_topic_id($question['topic_id'] ?? '');

    if ($qualificationId === '' || $courseId === '') {
        return;
    }

    question_scope_validate_scope($pdo, $qualificationId, $courseId, $topicId);

    $matchStmt = $pdo->prepare('SELECT id FROM question_scope_links WHERE question_id = ? AND qualification_id = ? AND course_id = ? AND topic_id = ? LIMIT 1');
    $matchStmt->execute([$questionId, $qualificationId, $courseId, $topicId]);
    $matched = $matchStmt->fetch(PDO::FETCH_ASSOC);

    if ($matched) {
        $matchedId = (string)($matched['id'] ?? '');
        $setStmt = $pdo->prepare('UPDATE question_scope_links SET is_primary = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE question_id = ?');
        $setStmt->execute([$matchedId, $questionId]);
    } else {
        $primaryStmt = $pdo->prepare('SELECT id FROM question_scope_links WHERE question_id = ? AND is_primary = 1 LIMIT 1');
        $primaryStmt->execute([$questionId]);
        $primary = $primaryStmt->fetch(PDO::FETCH_ASSOC);

        if ($primary) {
            $updatePrimary = $pdo->prepare('UPDATE question_scope_links SET qualification_id = ?, course_id = ?, topic_id = ?, is_primary = 1 WHERE id = ?');
            $updatePrimary->execute([$qualificationId, $courseId, $topicId, (string)$primary['id']]);
            $pdo->prepare('UPDATE question_scope_links SET is_primary = 0 WHERE question_id = ? AND id <> ?')->execute([$questionId, (string)$primary['id']]);
        } else {
            question_scope_add($pdo, $questionId, $qualificationId, $courseId, $topicId, 1);
        }
    }

    question_scope_ensure_primary($pdo, $questionId);
}

<?php

function me_uuid(): string
{
    return function_exists('generate_uuid') ? generate_uuid() : sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

function me_decode_options(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $options = [];
    foreach ($decoded as $key => $value) {
        $key = strtoupper(trim((string)$key));
        $value = trim((string)$value);
        if ($key !== '' && $value !== '') $options[$key] = $value;
    }
    return $options;
}

function me_public_item(array $row): array
{
    return [
        'session_item_id' => (string)$row['id'],
        'position' => (int)$row['position'],
        'question_type' => (string)$row['question_type'],
        'prompt' => (string)$row['prompt_snapshot'],
        'options' => me_decode_options((string)$row['options_snapshot_json']),
        'term' => (string)($row['term_en'] ?? ''),
        'category' => (string)($row['category_name'] ?? ''),
    ];
}

function me_load_session(PDO $pdo, string $sessionId, string $userId, bool $lock = false): ?array
{
    $sql = 'SELECT * FROM maritime_english_sessions WHERE id = ? AND user_id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$sessionId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function me_load_session_items(PDO $pdo, string $sessionId): array
{
    $stmt = $pdo->prepare(
        'SELECT i.*, t.term_en, t.term_tr, c.name AS category_name
         FROM maritime_english_session_items i
         INNER JOIN maritime_english_terms t ON t.id = i.term_id
         INNER JOIN maritime_english_categories c ON c.id = t.category_id
         WHERE i.session_id = ? ORDER BY i.position ASC'
    );
    $stmt->execute([$sessionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function me_learning_bucket(array $row): string
{
    if (empty($row['learning_state'])) return 'new';
    $state = (string)$row['learning_state'];
    if (in_array($state, ['learning', 'relearning'], true)) return 'learning';
    if ($state === 'review' || $state === 'mastered') return 'review';
    return 'new';
}

function me_select_terms(PDO $pdo, string $userId, string $qualificationId, ?string $categoryId = null): array
{
    $categorySql = $categoryId !== null && $categoryId !== '' ? ' AND t.category_id = ?' : '';
    $stmt = $pdo->prepare(
        "SELECT t.id, t.term_en, t.term_tr, t.short_explanation, c.name AS category_name,
                ut.learning_state, ut.next_review_at, ut.wrong_count, ut.correct_count
         FROM maritime_english_terms t
         INNER JOIN maritime_english_categories c ON c.id = t.category_id AND c.is_active = 1
         LEFT JOIN maritime_english_user_terms ut ON ut.term_id = t.id AND ut.user_id = ?
         WHERE t.is_active = 1 AND t.content_status = 'published'
           AND (t.qualification_id IS NULL OR t.qualification_id = ?)' . $categorySql . '
           AND (SELECT COUNT(*) FROM maritime_english_questions q WHERE q.term_id = t.id AND q.is_active = 1) >= 2
         ORDER BY
           CASE WHEN ut.next_review_at IS NOT NULL AND ut.next_review_at <= NOW() THEN 0 ELSE 1 END,
           COALESCE(ut.wrong_count, 0) DESC,
           RAND()"
    );
    $params = [$userId, $qualificationId];
    if ($categorySql !== '') $params[] = $categoryId;
    $stmt->execute($params);
    $buckets = ['new' => [], 'learning' => [], 'review' => []];
    foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $buckets[me_learning_bucket($row)][] = $row;
    }

    $selected = [];
    $used = [];
    $take = static function (array $rows, int $limit) use (&$selected, &$used): void {
        foreach ($rows as $row) {
            if (count(array_filter($selected, static fn($x) => ($x['_take_group'] ?? '') === ($row['_take_group'] ?? ''))) >= $limit) break;
            $id = (string)$row['id'];
            if (isset($used[$id])) continue;
            $used[$id] = true;
            $selected[] = $row;
        }
    };

    foreach ([['new', 3], ['learning', 2], ['review', 2]] as [$name, $limit]) {
        $rows = array_map(static function ($row) use ($name) { $row['_take_group'] = $name; return $row; }, $buckets[$name]);
        $take($rows, $limit);
    }
    foreach (array_merge($buckets['review'], $buckets['learning'], $buckets['new']) as $row) {
        if (count($selected) >= 7) break;
        if (!isset($used[(string)$row['id']])) {
            $used[(string)$row['id']] = true;
            $selected[] = $row;
        }
    }
    return $selected;
}

function me_create_session(PDO $pdo, string $userId, string $qualificationId, ?string $categoryId = null): array
{
    if ($categoryId !== null && $categoryId !== '') {
        $categoryStmt = $pdo->prepare('SELECT id FROM maritime_english_categories WHERE id = ? AND is_active = 1');
        $categoryStmt->execute([$categoryId]);
        if (!$categoryStmt->fetchColumn()) throw new RuntimeException('Seçilen kategori bulunamadı.', 404);
    }
    $terms = me_select_terms($pdo, $userId, $qualificationId, $categoryId);
    if (count($terms) < 5) {
        $scope = $categoryId ? 'Bu kategoride' : 'Oturum başlatmak için';
        throw new RuntimeException($scope . ' en az 5 yayımlanmış kelime ve her kelimeye ait en az 2 soru gereklidir.', 422);
    }

    $questionPools = [];
    $qStmt = $pdo->prepare(
        'SELECT id, term_id, question_type, prompt, options_json, correct_option_key
         FROM maritime_english_questions WHERE term_id = ? AND is_active = 1 ORDER BY RAND()'
    );
    foreach ($terms as $term) {
        $qStmt->execute([(string)$term['id']]);
        $questionPools[(string)$term['id']] = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $ordered = [];
    $lastTermId = '';
    while (count($ordered) < 12) {
        $progress = false;
        foreach ($terms as $term) {
            $termId = (string)$term['id'];
            if ($termId === $lastTermId || empty($questionPools[$termId])) continue;
            $question = array_shift($questionPools[$termId]);
            $question['_term'] = $term;
            $ordered[] = $question;
            $lastTermId = $termId;
            $progress = true;
            if (count($ordered) >= 12) break;
        }
        if (!$progress) break;
    }
    if (count($ordered) < 12) throw new RuntimeException('12 soruluk oturum için yeterli aktif soru bulunamadı.', 422);

    $sessionId = me_uuid();
    $pdo->prepare(
        "INSERT INTO maritime_english_sessions
         (id, user_id, qualification_id, status, question_count, expires_at, started_at, created_at, updated_at)
         VALUES (?, ?, ?, 'active', 12, DATE_ADD(NOW(), INTERVAL 2 HOUR), NOW(), NOW(), NOW())"
    )->execute([$sessionId, $userId, $qualificationId]);

    $insert = $pdo->prepare(
        'INSERT INTO maritime_english_session_items
         (id, session_id, term_id, question_id, position, prompt_snapshot, options_snapshot_json, correct_option_key, question_type, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    foreach ($ordered as $index => $question) {
        $insert->execute([
            me_uuid(), $sessionId, (string)$question['term_id'], (string)$question['id'], $index + 1,
            (string)$question['prompt'], (string)$question['options_json'], strtoupper((string)$question['correct_option_key']),
            (string)$question['question_type'],
        ]);
    }
    return me_session_payload($pdo, $sessionId, $userId);
}

function me_session_payload(PDO $pdo, string $sessionId, string $userId): array
{
    $session = me_load_session($pdo, $sessionId, $userId);
    if (!$session) throw new RuntimeException('Oturum bulunamadı.', 404);
    $items = me_load_session_items($pdo, $sessionId);
    $next = null;
    foreach ($items as $item) if ($item['answered_at'] === null) { $next = me_public_item($item); break; }
    return [
        'session_id' => $sessionId,
        'status' => (string)$session['status'],
        'question_count' => (int)$session['question_count'],
        'answered_count' => (int)$session['answered_count'],
        'correct_count' => (int)$session['correct_count'],
        'wrong_count' => (int)$session['wrong_count'],
        'next_question' => $next,
        'started_at' => (string)$session['started_at'],
        'expires_at' => (string)$session['expires_at'],
    ];
}

function me_update_term_progress(PDO $pdo, string $userId, string $termId, bool $correct): void
{
    $stmt = $pdo->prepare('SELECT * FROM maritime_english_user_terms WHERE user_id = ? AND term_id = ? FOR UPDATE');
    $stmt->execute([$userId, $termId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $consecutive = $correct ? ((int)($row['consecutive_correct'] ?? 0) + 1) : 0;
    if (!$correct) { $state = 'relearning'; $days = 1; }
    elseif ($consecutive >= 4) { $state = 'mastered'; $days = 30; }
    elseif ($consecutive === 3) { $state = 'review'; $days = 7; }
    elseif ($consecutive === 2) { $state = 'review'; $days = 3; }
    else { $state = 'learning'; $days = 1; }

    $sql = "INSERT INTO maritime_english_user_terms
            (user_id, term_id, learning_state, correct_count, wrong_count, consecutive_correct, last_seen_at, next_review_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL {$days} DAY), NOW(), NOW())
            ON DUPLICATE KEY UPDATE learning_state = VALUES(learning_state),
              correct_count = correct_count + VALUES(correct_count), wrong_count = wrong_count + VALUES(wrong_count),
              consecutive_correct = VALUES(consecutive_correct), last_seen_at = NOW(), next_review_at = VALUES(next_review_at), updated_at = NOW()";
    $pdo->prepare($sql)->execute([$userId, $termId, $state, $correct ? 1 : 0, $correct ? 0 : 1, $consecutive]);
}

function me_result_payload(PDO $pdo, string $sessionId, string $userId): array
{
    $session = me_load_session($pdo, $sessionId, $userId);
    if (!$session) throw new RuntimeException('Oturum bulunamadı.', 404);
    $stmt = $pdo->prepare(
        'SELECT t.term_en, t.term_tr, ut.learning_state, SUM(i.is_correct = 1) AS correct_count,
                SUM(i.is_correct = 0) AS wrong_count
         FROM maritime_english_session_items i
         INNER JOIN maritime_english_terms t ON t.id = i.term_id
         LEFT JOIN maritime_english_user_terms ut ON ut.user_id = ? AND ut.term_id = t.id
         WHERE i.session_id = ? GROUP BY t.id, t.term_en, t.term_tr, ut.learning_state ORDER BY MIN(i.position)'
    );
    $stmt->execute([$userId, $sessionId]);
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total = (int)$session['question_count'];
    $correct = (int)$session['correct_count'];
    $answered = (int)$session['answered_count'];
    return [
        'session_id' => $sessionId, 'status' => (string)$session['status'], 'total' => $total,
        'answered' => $answered, 'unanswered' => max(0, $total - $answered),
        'correct' => $correct, 'wrong' => (int)$session['wrong_count'],
        'success_percent' => $answered > 0 ? round(($correct / $answered) * 100, 1) : 0,
        'terms' => array_map(static fn($r) => [
            'term_en' => (string)$r['term_en'], 'term_tr' => (string)$r['term_tr'],
            'state' => (string)($r['learning_state'] ?? 'learning'),
            'correct' => (int)$r['correct_count'], 'wrong' => (int)$r['wrong_count'],
        ], $terms),
    ];
}

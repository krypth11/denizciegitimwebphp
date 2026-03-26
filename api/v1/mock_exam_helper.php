<?php

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/study_helper.php';

function mock_exam_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function mock_exam_pick(array $columns, array $candidates, bool $required = false): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    if ($required) {
        throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    }
    return null;
}

function mock_exam_normalize_pool_type(string $poolType): string
{
    $v = strtolower(trim($poolType));
    return in_array($v, ['random', 'unseen', 'seen'], true) ? $v : 'random';
}

function mock_exam_normalize_mode(string $mode): string
{
    $v = strtolower(trim($mode));
    return in_array($v, ['standard', 'similar', 'wrong_only', 'wrong_blank'], true) ? $v : 'standard';
}

function mock_exam_validate_question_count(int $count): int
{
    if ($count < 1 || $count > 100) {
        throw new RuntimeException('requested_question_count 1-100 aralığında olmalıdır.');
    }
    return $count;
}

function mock_exam_get_attempt_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'mock_exam_attempts');
    if (!$cols) {
        throw new RuntimeException('mock_exam_attempts tablosu okunamadı.');
    }
    return [
        'table' => 'mock_exam_attempts',
        'id' => mock_exam_pick($cols, ['id'], true),
        'user_id' => mock_exam_pick($cols, ['user_id'], true),
        'qualification_id' => mock_exam_pick($cols, ['qualification_id'], true),
        'mode' => mock_exam_pick($cols, ['mode'], false),
        'pool_type' => mock_exam_pick($cols, ['pool_type'], false),
        'requested_question_count' => mock_exam_pick($cols, ['requested_question_count'], false),
        'actual_question_count' => mock_exam_pick($cols, ['actual_question_count'], false),
        'duration_seconds_limit' => mock_exam_pick($cols, ['duration_seconds_limit'], false),
        'elapsed_seconds' => mock_exam_pick($cols, ['elapsed_seconds'], false),
        'status' => mock_exam_pick($cols, ['status'], false),
        'warning_message' => mock_exam_pick($cols, ['warning_message'], false),
        'source_attempt_id' => mock_exam_pick($cols, ['source_attempt_id'], false),
        'started_at' => mock_exam_pick($cols, ['started_at'], false),
        'submitted_at' => mock_exam_pick($cols, ['submitted_at'], false),
        'abandoned_at' => mock_exam_pick($cols, ['abandoned_at'], false),
        'created_at' => mock_exam_pick($cols, ['created_at'], false),
        'updated_at' => mock_exam_pick($cols, ['updated_at'], false),
        'correct_count' => mock_exam_pick($cols, ['correct_count'], false),
        'wrong_count' => mock_exam_pick($cols, ['wrong_count'], false),
        'blank_count' => mock_exam_pick($cols, ['blank_count'], false),
        'success_rate' => mock_exam_pick($cols, ['success_rate'], false),
    ];
}

function mock_exam_get_attempt_question_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'mock_exam_attempt_questions');
    if (!$cols) {
        throw new RuntimeException('mock_exam_attempt_questions tablosu okunamadı.');
    }
    return [
        'table' => 'mock_exam_attempt_questions',
        'id' => mock_exam_pick($cols, ['id'], true),
        'attempt_id' => mock_exam_pick($cols, ['attempt_id'], true),
        'question_id' => mock_exam_pick($cols, ['question_id'], true),
        'course_id' => mock_exam_pick($cols, ['course_id'], false),
        'course_name' => mock_exam_pick($cols, ['course_name'], false),
        'topic_id' => mock_exam_pick($cols, ['topic_id'], false),
        'order_index' => mock_exam_pick($cols, ['order_index'], false),
        'question_type' => mock_exam_pick($cols, ['question_type'], false),
        'question_text' => mock_exam_pick($cols, ['question_text'], false),
        'option_a' => mock_exam_pick($cols, ['option_a'], false),
        'option_b' => mock_exam_pick($cols, ['option_b'], false),
        'option_c' => mock_exam_pick($cols, ['option_c'], false),
        'option_d' => mock_exam_pick($cols, ['option_d'], false),
        'option_e' => mock_exam_pick($cols, ['option_e'], false),
        'correct_answer' => mock_exam_pick($cols, ['correct_answer'], false),
        'explanation' => mock_exam_pick($cols, ['explanation'], false),
        'selected_answer' => mock_exam_pick($cols, ['selected_answer'], false),
        'is_flagged' => mock_exam_pick($cols, ['is_flagged'], false),
        'created_at' => mock_exam_pick($cols, ['created_at'], false),
        'updated_at' => mock_exam_pick($cols, ['updated_at'], false),
    ];
}

function mock_exam_get_user_exam_preferences(PDO $pdo, string $userId): array
{
    $cols = get_table_columns($pdo, 'user_exam_preferences');
    if (!$cols) {
        return ['last_pool_type' => 'random', 'last_question_count' => 20];
    }
    $userCol = mock_exam_pick($cols, ['user_id'], true);
    $poolCol = mock_exam_pick($cols, ['last_pool_type', 'pool_type'], false);
    $countCol = mock_exam_pick($cols, ['last_question_count', 'question_count'], false);

    $sql = 'SELECT '
        . ($poolCol ? mock_exam_q($poolCol) : "'random'") . ' AS last_pool_type, '
        . ($countCol ? mock_exam_q($countCol) : '20') . ' AS last_question_count '
        . 'FROM `user_exam_preferences` WHERE ' . mock_exam_q($userCol) . ' = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'last_pool_type' => mock_exam_normalize_pool_type((string)($row['last_pool_type'] ?? 'random')),
        'last_question_count' => max(1, min(100, (int)($row['last_question_count'] ?? 20))),
    ];
}

function mock_exam_save_user_exam_preferences(PDO $pdo, string $userId, string $poolType, int $questionCount): void
{
    $poolType = mock_exam_normalize_pool_type($poolType);
    $questionCount = mock_exam_validate_question_count($questionCount);

    $cols = get_table_columns($pdo, 'user_exam_preferences');
    if (!$cols) {
        return;
    }
    $idCol = mock_exam_pick($cols, ['id'], false);
    $userCol = mock_exam_pick($cols, ['user_id'], true);
    $poolCol = mock_exam_pick($cols, ['last_pool_type', 'pool_type'], false);
    $countCol = mock_exam_pick($cols, ['last_question_count', 'question_count'], false);
    $updatedCol = mock_exam_pick($cols, ['updated_at'], false);
    $createdCol = mock_exam_pick($cols, ['created_at'], false);

    $check = $pdo->prepare('SELECT COUNT(*) FROM `user_exam_preferences` WHERE ' . mock_exam_q($userCol) . ' = ?');
    $check->execute([$userId]);
    $exists = ((int)$check->fetchColumn()) > 0;

    if ($exists) {
        $set = [];
        $params = [];
        if ($poolCol) {
            $set[] = mock_exam_q($poolCol) . ' = ?';
            $params[] = $poolType;
        }
        if ($countCol) {
            $set[] = mock_exam_q($countCol) . ' = ?';
            $params[] = $questionCount;
        }
        if ($updatedCol) {
            $set[] = mock_exam_q($updatedCol) . ' = NOW()';
        }
        if ($set) {
            $params[] = $userId;
            $sql = 'UPDATE `user_exam_preferences` SET ' . implode(', ', $set) . ' WHERE ' . mock_exam_q($userCol) . ' = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        return;
    }

    $insCols = [mock_exam_q($userCol)];
    $holders = ['?'];
    $params = [$userId];
    if ($idCol) {
        $insCols[] = mock_exam_q($idCol);
        $holders[] = '?';
        $params[] = generate_uuid();
    }
    if ($poolCol) {
        $insCols[] = mock_exam_q($poolCol);
        $holders[] = '?';
        $params[] = $poolType;
    }
    if ($countCol) {
        $insCols[] = mock_exam_q($countCol);
        $holders[] = '?';
        $params[] = $questionCount;
    }
    if ($createdCol) {
        $insCols[] = mock_exam_q($createdCol);
        $holders[] = 'NOW()';
    }
    if ($updatedCol) {
        $insCols[] = mock_exam_q($updatedCol);
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO `user_exam_preferences` (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function mock_exam_format_attempt(array $row): array
{
    return [
        'id' => (string)($row['id'] ?? ''),
        'qualification_id' => $row['qualification_id'] ?? null,
        'qualification_name' => $row['qualification_name'] ?? null,
        'mode' => (string)($row['mode'] ?? 'standard'),
        'pool_type' => (string)($row['pool_type'] ?? 'random'),
        'requested_question_count' => (int)($row['requested_question_count'] ?? 0),
        'actual_question_count' => (int)($row['actual_question_count'] ?? 0),
        'duration_seconds_limit' => (int)($row['duration_seconds_limit'] ?? 2400),
        'elapsed_seconds' => (int)($row['elapsed_seconds'] ?? 0),
        'status' => (string)($row['status'] ?? 'in_progress'),
        'warning_message' => $row['warning_message'] ?? null,
        'started_at' => $row['started_at'] ?? null,
        'submitted_at' => $row['submitted_at'] ?? null,
        'abandoned_at' => $row['abandoned_at'] ?? null,
    ];
}

function mock_exam_find_active_attempt(PDO $pdo, string $userId): ?array
{
    $s = mock_exam_get_attempt_schema($pdo);
    if (!$s['status']) {
        return null;
    }
    $sql = 'SELECT a.*, q.name AS qualification_name FROM `' . $s['table'] . '` a '
        . 'LEFT JOIN qualifications q ON a.' . mock_exam_q($s['qualification_id']) . ' = q.id '
        . 'WHERE a.' . mock_exam_q($s['user_id']) . ' = ? AND a.' . mock_exam_q($s['status']) . " = 'in_progress' "
        . 'ORDER BY ' . ($s['started_at'] ? ('a.' . mock_exam_q($s['started_at'])) : ('a.' . mock_exam_q($s['id']))) . ' DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? mock_exam_format_attempt($row) : null;
}

function mock_exam_fetch_qualification_courses(PDO $pdo, string $qualificationId): array
{
    $stmt = $pdo->prepare('SELECT id, name FROM courses WHERE qualification_id = ? ORDER BY order_index ASC, name ASC');
    $stmt->execute([$qualificationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mock_exam_fetch_candidate_questions(PDO $pdo, string $qualificationId): array
{
    $sql = 'SELECT q.id, q.course_id, q.topic_id, q.question_type, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.option_e, q.correct_answer, q.explanation, c.name AS course_name '
        . 'FROM questions q INNER JOIN courses c ON q.course_id = c.id '
        . 'WHERE c.qualification_id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qualificationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mock_exam_fetch_seen_question_ids(PDO $pdo, string $userId, array $questionIds): array
{
    if (!$questionIds) {
        return [];
    }
    $up = study_get_user_progress_schema($pdo);
    if (empty($up['user_id']) || empty($up['question_id'])) {
        return [];
    }
    $conds = [];
    if (!empty($up['is_answered'])) {
        $conds[] = 'COALESCE(' . mock_exam_q($up['is_answered']) . ',0) = 1';
    }
    if (!empty($up['total_answer_count'])) {
        $conds[] = 'COALESCE(' . mock_exam_q($up['total_answer_count']) . ',0) > 0';
    }
    if (!$conds) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($questionIds), '?'));
    $sql = 'SELECT ' . mock_exam_q($up['question_id']) . ' AS question_id FROM ' . mock_exam_q($up['table'])
        . ' WHERE ' . mock_exam_q($up['user_id']) . ' = ? AND ' . mock_exam_q($up['question_id']) . ' IN (' . $ph . ') AND (' . implode(' OR ', $conds) . ')';
    $params = array_merge([$userId], $questionIds);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['question_id']] = true;
    }
    return $out;
}

function mock_exam_calculate_pool_counts(PDO $pdo, string $userId, string $qualificationId): array
{
    $candidates = mock_exam_fetch_candidate_questions($pdo, $qualificationId);
    $ids = array_values(array_map(static fn(array $r): string => (string)$r['id'], $candidates));
    $seen = mock_exam_fetch_seen_question_ids($pdo, $userId, $ids);
    $seenCount = 0;
    foreach ($ids as $id) {
        if (isset($seen[$id])) {
            $seenCount++;
        }
    }
    $total = count($ids);
    return [
        'total' => $total,
        'seen' => $seenCount,
        'unseen' => max(0, $total - $seenCount),
    ];
}

function mock_exam_balanced_pick(array $questions, int $requested, array $deprioritizedIds = []): array
{
    if ($requested <= 0 || !$questions) {
        return [];
    }
    $byCourse = [];
    foreach ($questions as $q) {
        $cid = (string)($q['course_id'] ?? '__none__');
        $byCourse[$cid][] = $q;
    }
    foreach ($byCourse as $cid => $rows) {
        shuffle($rows);
        if (!empty($deprioritizedIds)) {
            usort($rows, static function (array $a, array $b) use ($deprioritizedIds): int {
                $pa = isset($deprioritizedIds[(string)$a['id']]) ? 1 : 0;
                $pb = isset($deprioritizedIds[(string)$b['id']]) ? 1 : 0;
                return $pa <=> $pb;
            });
        }
        $byCourse[$cid] = $rows;
    }

    $selected = [];
    while (count($selected) < $requested) {
        $progress = false;
        foreach ($byCourse as $cid => $rows) {
            if (count($selected) >= $requested) {
                break;
            }
            if (empty($rows)) {
                continue;
            }
            $selected[] = array_shift($rows);
            $byCourse[$cid] = $rows;
            $progress = true;
        }
        if (!$progress) {
            break;
        }
    }
    shuffle($selected);
    return $selected;
}

function mock_exam_get_source_attempt_question_map(PDO $pdo, string $userId, string $sourceAttemptId): array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $aq = mock_exam_get_attempt_question_schema($pdo);
    $sql = 'SELECT aq.' . mock_exam_q($aq['question_id']) . ' AS question_id, '
        . ($aq['selected_answer'] ? ('aq.' . mock_exam_q($aq['selected_answer'])) : 'NULL') . ' AS selected_answer, '
        . ($aq['correct_answer'] ? ('aq.' . mock_exam_q($aq['correct_answer'])) : 'NULL') . ' AS correct_answer, '
        . ($aq['course_id'] ? ('aq.' . mock_exam_q($aq['course_id'])) : 'NULL') . ' AS course_id '
        . 'FROM `' . $aq['table'] . '` aq INNER JOIN `' . $a['table'] . '` a ON aq.' . mock_exam_q($aq['attempt_id']) . ' = a.' . mock_exam_q($a['id'])
        . ' WHERE a.' . mock_exam_q($a['user_id']) . ' = ? AND a.' . mock_exam_q($a['id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $sourceAttemptId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $r) {
        $qid = (string)($r['question_id'] ?? '');
        if ($qid !== '') {
            $map[$qid] = $r;
        }
    }
    return $map;
}

function mock_exam_build_question_set(PDO $pdo, string $userId, string $qualificationId, int $requestedCount, string $poolType, string $mode = 'standard', ?string $sourceAttemptId = null): array
{
    $requestedCount = mock_exam_validate_question_count($requestedCount);
    $poolType = mock_exam_normalize_pool_type($poolType);
    $mode = mock_exam_normalize_mode($mode);

    $candidates = mock_exam_fetch_candidate_questions($pdo, $qualificationId);
    if (!$candidates) {
        throw new RuntimeException('Seçilen yeterliliğe bağlı soru bulunamadı.');
    }

    $sourceMap = [];
    $sourceQuestionIds = [];
    if ($sourceAttemptId) {
        $sourceMap = mock_exam_get_source_attempt_question_map($pdo, $userId, $sourceAttemptId);
        $sourceQuestionIds = array_fill_keys(array_keys($sourceMap), true);
    }

    if (in_array($mode, ['wrong_only', 'wrong_blank'], true)) {
        if (!$sourceAttemptId || !$sourceMap) {
            throw new RuntimeException('wrong practice için geçerli source_attempt_id zorunludur.');
        }
        $filteredIds = [];
        foreach ($sourceMap as $qid => $r) {
            $sel = strtoupper(trim((string)($r['selected_answer'] ?? '')));
            $cor = strtoupper(trim((string)($r['correct_answer'] ?? '')));
            $isBlank = $sel === '';
            $isWrong = (!$isBlank && $cor !== '' && $sel !== $cor);
            if ($mode === 'wrong_only' && $isWrong) {
                $filteredIds[$qid] = true;
            }
            if ($mode === 'wrong_blank' && ($isWrong || $isBlank)) {
                $filteredIds[$qid] = true;
            }
        }
        $candidates = array_values(array_filter($candidates, static fn(array $q): bool => isset($filteredIds[(string)$q['id']])));
        if (!$candidates) {
            throw new RuntimeException('Bu mod için uygun soru bulunamadı.');
        }
    }

    $warning = null;

    if ($mode === 'similar' && !empty($sourceMap)) {
        $targetByCourse = [];
        foreach ($sourceMap as $qid => $row) {
            $cid = (string)($row['course_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $targetByCourse[$cid] = ($targetByCourse[$cid] ?? 0) + 1;
        }

        if (!empty($targetByCourse)) {
            $coursePools = [];
            foreach ($candidates as $q) {
                $cid = (string)($q['course_id'] ?? '');
                if ($cid === '') {
                    continue;
                }
                $coursePools[$cid][] = $q;
            }

            $scaledTargets = [];
            $sourceTotal = array_sum($targetByCourse);
            if ($sourceTotal > 0) {
                $remainders = [];
                $allocated = 0;
                foreach ($targetByCourse as $cid => $cnt) {
                    $raw = ($cnt / $sourceTotal) * $requestedCount;
                    $base = (int)floor($raw);
                    $scaledTargets[$cid] = $base;
                    $remainders[$cid] = $raw - $base;
                    $allocated += $base;
                }
                while ($allocated < $requestedCount) {
                    arsort($remainders);
                    $pickedCid = key($remainders);
                    if ($pickedCid === null) {
                        break;
                    }
                    $scaledTargets[$pickedCid] = ($scaledTargets[$pickedCid] ?? 0) + 1;
                    $remainders[$pickedCid] = 0;
                    $allocated++;
                }
            }

            $similarPicked = [];
            foreach ($scaledTargets as $cid => $targetCnt) {
                $pool = $coursePools[$cid] ?? [];
                if (!$pool || $targetCnt <= 0) {
                    continue;
                }
                $part = mock_exam_balanced_pick($pool, $targetCnt, $sourceQuestionIds);
                $similarPicked = array_merge($similarPicked, $part);
            }

            if (count($similarPicked) < $requestedCount) {
                $pickedIds = array_fill_keys(array_map(static fn(array $q): string => (string)$q['id'], $similarPicked), true);
                $fallbackPool = array_values(array_filter($candidates, static fn(array $q): bool => !isset($pickedIds[(string)$q['id']])));
                $extra = mock_exam_balanced_pick($fallbackPool, $requestedCount - count($similarPicked), $sourceQuestionIds);
                $similarPicked = array_merge($similarPicked, $extra);
            }

            if (!empty($similarPicked)) {
                $candidates = $similarPicked;
            }
        }
    }
    $ids = array_values(array_map(static fn(array $r): string => (string)$r['id'], $candidates));
    $seenMap = mock_exam_fetch_seen_question_ids($pdo, $userId, $ids);

    $seenPool = [];
    $unseenPool = [];
    foreach ($candidates as $q) {
        $qid = (string)$q['id'];
        if (isset($seenMap[$qid])) {
            $seenPool[] = $q;
        } else {
            $unseenPool[] = $q;
        }
    }

    $picked = [];
    if ($poolType === 'seen') {
        $picked = mock_exam_balanced_pick($seenPool, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
    } elseif ($poolType === 'unseen') {
        $picked = mock_exam_balanced_pick($unseenPool, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
        if (count($picked) < $requestedCount) {
            $remainingNeed = $requestedCount - count($picked);
            $pickedIds = array_fill_keys(array_map(static fn(array $q): string => (string)$q['id'], $picked), true);
            $fallbackPool = array_values(array_filter($candidates, static fn(array $q): bool => !isset($pickedIds[(string)$q['id']])));
            $extra = mock_exam_balanced_pick($fallbackPool, $remainingNeed, $mode === 'similar' ? $sourceQuestionIds : []);
            $picked = array_merge($picked, $extra);
            if (count($unseenPool) === 0) {
                $warning = 'Tüm soruları çözdünüz';
            } else {
                $pickedUnseen = min($requestedCount, count($unseenPool));
                $fallbackCount = max(0, $requestedCount - $pickedUnseen);
                $warning = $requestedCount . ' sorudan ' . $pickedUnseen . ' tanesi çözülmemiş havuzdan seçildi. Kalan ' . $fallbackCount . ' soru rastgele tamamlandı.';
            }
        }
    } else {
        $picked = mock_exam_balanced_pick($candidates, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
    }

    if (!$picked) {
        throw new RuntimeException('Seçili havuzda yeterli soru bulunamadı.');
    }

    shuffle($picked);
    $out = [];
    $idx = 1;
    foreach ($picked as $q) {
        $q['order_index'] = $idx++;
        $out[] = $q;
    }

    return [
        'questions' => $out,
        'warning_message' => $warning,
        'pool_counts' => [
            'total' => count($candidates),
            'seen' => count($seenPool),
            'unseen' => count($unseenPool),
        ],
    ];
}

function mock_exam_fetch_attempt_questions(PDO $pdo, string $attemptId, bool $withResultFields = false): array
{
    $aq = mock_exam_get_attempt_question_schema($pdo);
    $sql = 'SELECT * FROM `' . $aq['table'] . '` WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? ORDER BY ' . ($aq['order_index'] ? mock_exam_q($aq['order_index']) : mock_exam_q($aq['id'])) . ' ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$attemptId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $selected = $aq['selected_answer'] ? ($r[$aq['selected_answer']] ?? null) : null;
        $correct = $aq['correct_answer'] ? ($r[$aq['correct_answer']] ?? null) : null;
        $isAnswered = trim((string)$selected) !== '';
        $isCorrect = ($isAnswered && trim((string)$correct) !== '') ? (strtoupper((string)$selected) === strtoupper((string)$correct)) : null;
        $item = [
            'id' => (string)$r[$aq['id']],
            'question_id' => (string)$r[$aq['question_id']],
            'course_id' => $aq['course_id'] ? ($r[$aq['course_id']] ?? null) : null,
            'course_name' => $aq['course_name'] ? ($r[$aq['course_name']] ?? null) : null,
            'topic_id' => $aq['topic_id'] ? ($r[$aq['topic_id']] ?? null) : null,
            'order_index' => $aq['order_index'] ? (int)($r[$aq['order_index']] ?? 0) : 0,
            'question_type' => $aq['question_type'] ? ($r[$aq['question_type']] ?? null) : null,
            'question_text' => $aq['question_text'] ? ($r[$aq['question_text']] ?? null) : null,
            'option_a' => $aq['option_a'] ? ($r[$aq['option_a']] ?? null) : null,
            'option_b' => $aq['option_b'] ? ($r[$aq['option_b']] ?? null) : null,
            'option_c' => $aq['option_c'] ? ($r[$aq['option_c']] ?? null) : null,
            'option_d' => $aq['option_d'] ? ($r[$aq['option_d']] ?? null) : null,
            'option_e' => $aq['option_e'] ? ($r[$aq['option_e']] ?? null) : null,
            'selected_answer' => $selected,
            'is_flagged' => $aq['is_flagged'] ? ((int)($r[$aq['is_flagged']] ?? 0) === 1) : false,
            'is_answered' => $isAnswered,
            'is_correct' => $withResultFields ? $isCorrect : null,
            'is_blank' => !$isAnswered,
        ];
        if ($withResultFields) {
            $item['correct_answer'] = $correct;
            $item['explanation'] = $aq['explanation'] ? ($r[$aq['explanation']] ?? null) : null;
        }
        $out[] = $item;
    }
    return $out;
}

function mock_exam_compute_summary_from_questions(array $questions): array
{
    $correct = 0;
    $wrong = 0;
    $blank = 0;
    foreach ($questions as $q) {
        if (!empty($q['is_blank'])) {
            $blank++;
        } elseif (!empty($q['is_correct'])) {
            $correct++;
        } else {
            $wrong++;
        }
    }
    $answered = $correct + $wrong;
    $total = $answered + $blank;
    $successRate = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
    return [
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'blank_count' => $blank,
        'answered_count' => $answered,
        'success_rate' => $successRate,
    ];
}

function mock_exam_create_attempt(PDO $pdo, string $userId, array $payload): array
{
    $qualificationId = trim((string)($payload['qualification_id'] ?? ''));
    if ($qualificationId === '') {
        throw new RuntimeException('qualification_id zorunludur.');
    }
    $requested = mock_exam_validate_question_count((int)($payload['requested_question_count'] ?? 0));
    $poolType = mock_exam_normalize_pool_type((string)($payload['pool_type'] ?? 'random'));
    $mode = mock_exam_normalize_mode((string)($payload['mode'] ?? 'standard'));
    $sourceAttemptId = trim((string)($payload['source_attempt_id'] ?? ''));
    if ($sourceAttemptId === '') {
        $sourceAttemptId = null;
    }

    $active = mock_exam_find_active_attempt($pdo, $userId);
    if ($active) {
        return ['resume_existing' => true, 'attempt' => $active, 'questions' => []];
    }

    $set = mock_exam_build_question_set($pdo, $userId, $qualificationId, $requested, $poolType, $mode, $sourceAttemptId);
    $questions = $set['questions'];
    $warning = $set['warning_message'];

    $a = mock_exam_get_attempt_schema($pdo);
    $aq = mock_exam_get_attempt_question_schema($pdo);
    $attemptId = generate_uuid();

    $pdo->beginTransaction();
    try {
        $cols = [mock_exam_q($a['id']), mock_exam_q($a['user_id']), mock_exam_q($a['qualification_id'])];
        $vals = ['?', '?', '?'];
        $params = [$attemptId, $userId, $qualificationId];

        foreach ([
            'mode' => $mode,
            'pool_type' => $poolType,
            'requested_question_count' => $requested,
            'actual_question_count' => count($questions),
            'duration_seconds_limit' => 2400,
            'elapsed_seconds' => 0,
            'status' => 'in_progress',
            'warning_message' => $warning,
            'source_attempt_id' => $sourceAttemptId,
        ] as $key => $value) {
            if (!empty($a[$key])) {
                $cols[] = mock_exam_q($a[$key]);
                $vals[] = '?';
                $params[] = $value;
            }
        }
        foreach (['started_at', 'created_at', 'updated_at'] as $dtCol) {
            if (!empty($a[$dtCol])) {
                $cols[] = mock_exam_q($a[$dtCol]);
                $vals[] = 'NOW()';
            }
        }

        $sqlInsAttempt = 'INSERT INTO `' . $a['table'] . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        $stmtA = $pdo->prepare($sqlInsAttempt);
        $stmtA->execute($params);

        foreach ($questions as $q) {
            $ic = [mock_exam_q($aq['id']), mock_exam_q($aq['attempt_id']), mock_exam_q($aq['question_id'])];
            $iv = ['?', '?', '?'];
            $ip = [generate_uuid(), $attemptId, $q['id']];

            $map = [
                'course_id' => $q['course_id'] ?? null,
                'course_name' => $q['course_name'] ?? null,
                'topic_id' => $q['topic_id'] ?? null,
                'order_index' => (int)($q['order_index'] ?? 0),
                'question_type' => $q['question_type'] ?? null,
                'question_text' => $q['question_text'] ?? null,
                'option_a' => $q['option_a'] ?? null,
                'option_b' => $q['option_b'] ?? null,
                'option_c' => $q['option_c'] ?? null,
                'option_d' => $q['option_d'] ?? null,
                'option_e' => $q['option_e'] ?? null,
                'correct_answer' => $q['correct_answer'] ?? null,
                'explanation' => $q['explanation'] ?? null,
                'selected_answer' => null,
                'is_flagged' => 0,
            ];

            foreach ($map as $field => $value) {
                if (!empty($aq[$field])) {
                    $ic[] = mock_exam_q($aq[$field]);
                    $iv[] = '?';
                    $ip[] = $value;
                }
            }
            foreach (['created_at', 'updated_at'] as $dtCol) {
                if (!empty($aq[$dtCol])) {
                    $ic[] = mock_exam_q($aq[$dtCol]);
                    $iv[] = 'NOW()';
                }
            }

            $sqlInsQ = 'INSERT INTO `' . $aq['table'] . '` (' . implode(', ', $ic) . ') VALUES (' . implode(', ', $iv) . ')';
            $stmtQ = $pdo->prepare($sqlInsQ);
            $stmtQ->execute($ip);
        }

        mock_exam_save_user_exam_preferences($pdo, $userId, $poolType, $requested);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
}

function mock_exam_fetch_attempt_detail(PDO $pdo, string $userId, string $attemptId): array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $sql = 'SELECT a.*, q.name AS qualification_name FROM `' . $a['table'] . '` a '
        . 'LEFT JOIN qualifications q ON a.' . mock_exam_q($a['qualification_id']) . ' = q.id '
        . 'WHERE a.' . mock_exam_q($a['id']) . ' = ? AND a.' . mock_exam_q($a['user_id']) . ' = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$attemptId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Deneme bulunamadı.');
    }
    $attempt = mock_exam_format_attempt($row);
    $withResult = in_array($attempt['status'], ['completed', 'abandoned'], true);
    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, $withResult);
    $summary = mock_exam_compute_summary_from_questions($questions);
    return ['attempt' => $attempt, 'questions' => $questions, 'summary' => $summary, 'resume_existing' => false];
}

function mock_exam_assert_attempt_in_progress(PDO $pdo, string $userId, string $attemptId): array
{
    $detail = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    if (($detail['attempt']['status'] ?? '') !== 'in_progress') {
        throw new RuntimeException('Deneme aktif durumda değil.');
    }
    return $detail;
}

function mock_exam_save_answer(PDO $pdo, string $userId, string $attemptId, string $questionId, ?string $selectedAnswer): array
{
    $selected = strtoupper(trim((string)$selectedAnswer));
    if ($selected === '') {
        $selected = null;
    }
    if ($selected !== null && !in_array($selected, ['A', 'B', 'C', 'D', 'E'], true)) {
        throw new RuntimeException('selected_answer geçersiz.');
    }

    $aq = mock_exam_get_attempt_question_schema($pdo);
    $detail = mock_exam_assert_attempt_in_progress($pdo, $userId, $attemptId);

    $set = [];
    $params = [];
    if ($aq['selected_answer']) {
        $set[] = mock_exam_q($aq['selected_answer']) . ' = ?';
        $params[] = $selected;
    }
    if ($aq['updated_at']) {
        $set[] = mock_exam_q($aq['updated_at']) . ' = NOW()';
    }
    $params[] = $attemptId;
    $params[] = $questionId;

    $sql = 'UPDATE `' . $aq['table'] . '` SET ' . implode(', ', $set)
        . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, false);
    $found = null;
    foreach ($questions as $q) {
        if ((string)$q['question_id'] === $questionId) {
            $found = $q;
            break;
        }
    }
    return [
        'attempt' => $detail['attempt'],
        'question' => $found,
        'answer_saved' => true,
    ];
}

function mock_exam_toggle_flag(PDO $pdo, string $userId, string $attemptId, string $questionId, bool $isFlagged): array
{
    $aq = mock_exam_get_attempt_question_schema($pdo);
    $detail = mock_exam_assert_attempt_in_progress($pdo, $userId, $attemptId);

    if (!$aq['is_flagged']) {
        throw new RuntimeException('is_flagged kolonu bulunamadı.');
    }

    $set = [mock_exam_q($aq['is_flagged']) . ' = ?'];
    $params = [$isFlagged ? 1 : 0];
    if ($aq['updated_at']) {
        $set[] = mock_exam_q($aq['updated_at']) . ' = NOW()';
    }
    $params[] = $attemptId;
    $params[] = $questionId;

    $sql = 'UPDATE `' . $aq['table'] . '` SET ' . implode(', ', $set)
        . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'attempt' => $detail['attempt'],
        'question_id' => $questionId,
        'is_flagged' => $isFlagged,
    ];
}

function mock_exam_write_events_and_progress(PDO $pdo, string $userId, string $attemptId, array $questions, ?string $qualificationId = null): void
{
    foreach ($questions as $q) {
        $selected = strtoupper(trim((string)($q['selected_answer'] ?? '')));
        if ($selected === '') {
            continue;
        }
        $isCorrect = !empty($q['is_correct']);

        study_insert_attempt_event($pdo, [
            'user_id' => $userId,
            'question_id' => (string)$q['question_id'],
            'course_id' => $q['course_id'] ?? null,
            'qualification_id' => $qualificationId,
            'topic_id' => $q['topic_id'] ?? null,
            'session_id' => $attemptId,
            'source' => 'mock_exam',
            'selected_answer' => $selected,
            'is_correct' => $isCorrect,
        ]);

        study_upsert_answer_progress($pdo, $userId, (string)$q['question_id'], $selected, $isCorrect);
    }
}

function mock_exam_submit(PDO $pdo, string $userId, string $attemptId, int $elapsedSeconds): array
{
    $elapsedSeconds = max(0, $elapsedSeconds);
    $a = mock_exam_get_attempt_schema($pdo);
    $detail = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    $status = (string)($detail['attempt']['status'] ?? '');
    if ($status === 'completed') {
        $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, true);
        return [
            'already_submitted' => true,
            'attempt' => $detail['attempt'],
            'summary' => mock_exam_compute_summary_from_questions($questions),
            'questions' => $questions,
        ];
    }
    if ($status !== 'in_progress') {
        throw new RuntimeException('Bu deneme submit edilemez.');
    }

    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, true);
    $summary = mock_exam_compute_summary_from_questions($questions);

    $pdo->beginTransaction();
    try {
        $set = [];
        $params = [];
        if ($a['status']) {
            $set[] = mock_exam_q($a['status']) . " = 'completed'";
        }
        if ($a['submitted_at']) {
            $set[] = mock_exam_q($a['submitted_at']) . ' = NOW()';
        }
        if ($a['elapsed_seconds']) {
            $set[] = mock_exam_q($a['elapsed_seconds']) . ' = ?';
            $params[] = $elapsedSeconds;
        }
        foreach (['correct_count', 'wrong_count', 'blank_count', 'success_rate'] as $k) {
            if (!empty($a[$k])) {
                $set[] = mock_exam_q($a[$k]) . ' = ?';
                $params[] = $summary[$k];
            }
        }
        if ($a['updated_at']) {
            $set[] = mock_exam_q($a['updated_at']) . ' = NOW()';
        }
        $params[] = $attemptId;
        $params[] = $userId;

        $sql = 'UPDATE `' . $a['table'] . '` SET ' . implode(', ', $set)
            . ' WHERE ' . mock_exam_q($a['id']) . ' = ? AND ' . mock_exam_q($a['user_id']) . ' = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        mock_exam_write_events_and_progress($pdo, $userId, $attemptId, $questions, $detail['attempt']['qualification_id'] ?? null);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $latest = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    return [
        'attempt' => $latest['attempt'],
        'summary' => mock_exam_compute_summary_from_questions(mock_exam_fetch_attempt_questions($pdo, $attemptId, true)),
        'questions' => mock_exam_fetch_attempt_questions($pdo, $attemptId, true),
    ];
}

function mock_exam_abandon(PDO $pdo, string $userId, string $attemptId, int $elapsedSeconds): array
{
    $elapsedSeconds = max(0, $elapsedSeconds);
    $a = mock_exam_get_attempt_schema($pdo);
    $detail = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    $status = (string)($detail['attempt']['status'] ?? '');
    if ($status === 'abandoned') {
        return ['attempt' => $detail['attempt'], 'already_abandoned' => true];
    }
    if ($status !== 'in_progress') {
        throw new RuntimeException('Bu deneme abandon edilemez.');
    }

    $set = [];
    $params = [];
    if ($a['status']) {
        $set[] = mock_exam_q($a['status']) . " = 'abandoned'";
    }
    if ($a['abandoned_at']) {
        $set[] = mock_exam_q($a['abandoned_at']) . ' = NOW()';
    }
    if ($a['elapsed_seconds']) {
        $set[] = mock_exam_q($a['elapsed_seconds']) . ' = ?';
        $params[] = $elapsedSeconds;
    }
    if ($a['updated_at']) {
        $set[] = mock_exam_q($a['updated_at']) . ' = NOW()';
    }
    $params[] = $attemptId;
    $params[] = $userId;
    $sql = 'UPDATE `' . $a['table'] . '` SET ' . implode(', ', $set)
        . ' WHERE ' . mock_exam_q($a['id']) . ' = ? AND ' . mock_exam_q($a['user_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
}

function mock_exam_fetch_history(PDO $pdo, string $userId, array $filters): array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $status = strtolower(trim((string)($filters['status'] ?? 'all')));
    $sort = strtolower(trim((string)($filters['sort'] ?? 'newest')));
    $qualificationId = trim((string)($filters['qualification_id'] ?? ''));
    $page = max(1, (int)($filters['page'] ?? 1));
    $perPage = max(1, min(100, (int)($filters['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

    $where = ['a.' . mock_exam_q($a['user_id']) . ' = ?'];
    $params = [$userId];
    if ($qualificationId !== '') {
        $where[] = 'a.' . mock_exam_q($a['qualification_id']) . ' = ?';
        $params[] = $qualificationId;
    }
    if ($status === 'completed' || $status === 'abandoned') {
        $where[] = 'a.' . mock_exam_q($a['status']) . ' = ?';
        $params[] = $status;
    }

    $orderBy = 'a.' . ($a['created_at'] ? mock_exam_q($a['created_at']) : mock_exam_q($a['id'])) . ' DESC';
    if ($sort === 'oldest') {
        $orderBy = 'a.' . ($a['created_at'] ? mock_exam_q($a['created_at']) : mock_exam_q($a['id'])) . ' ASC';
    } elseif ($sort === 'best' && $a['success_rate']) {
        $orderBy = 'a.' . mock_exam_q($a['success_rate']) . ' DESC, ' . ($a['created_at'] ? ('a.' . mock_exam_q($a['created_at']) . ' DESC') : ('a.' . mock_exam_q($a['id']) . ' DESC'));
    }

    $sqlCount = 'SELECT COUNT(*) FROM `' . $a['table'] . '` a WHERE ' . implode(' AND ', $where);
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $sql = 'SELECT a.*, q.name AS qualification_name FROM `' . $a['table'] . '` a '
        . 'LEFT JOIN qualifications q ON a.' . mock_exam_q($a['qualification_id']) . ' = q.id '
        . 'WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $orderBy
        . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $row) {
        $attempt = mock_exam_format_attempt($row);
        $summary = [
            'correct_count' => (int)($row['correct_count'] ?? 0),
            'wrong_count' => (int)($row['wrong_count'] ?? 0),
            'blank_count' => (int)($row['blank_count'] ?? 0),
            'answered_count' => ((int)($row['correct_count'] ?? 0) + (int)($row['wrong_count'] ?? 0)),
            'success_rate' => isset($row['success_rate']) ? (float)$row['success_rate'] : 0.0,
        ];
        $items[] = ['attempt' => $attempt, 'summary' => $summary];
    }

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 1,
        ],
        'filters' => [
            'qualification_id' => $qualificationId,
            'status' => $status,
            'sort' => $sort,
            'page' => $page,
            'per_page' => $perPage,
        ],
    ];
}

function mock_exam_build_lesson_report(PDO $pdo, string $attemptId): array
{
    $attemptSchema = mock_exam_get_attempt_schema($pdo);
    $attemptStmt = $pdo->prepare('SELECT a.' . mock_exam_q($attemptSchema['qualification_id']) . ' AS qualification_id, q.name AS qualification_name FROM `' . $attemptSchema['table'] . '` a LEFT JOIN qualifications q ON a.' . mock_exam_q($attemptSchema['qualification_id']) . ' = q.id WHERE a.' . mock_exam_q($attemptSchema['id']) . ' = ? LIMIT 1');
    $attemptStmt->execute([$attemptId]);
    $attemptRow = $attemptStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, true);
    $agg = [];
    foreach ($questions as $q) {
        $cid = (string)($q['course_id'] ?? '__none__');
        if (!isset($agg[$cid])) {
            $agg[$cid] = [
                'qualification_id' => $attemptRow['qualification_id'] ?? null,
                'qualification_name' => $attemptRow['qualification_name'] ?? null,
                'course_id' => $q['course_id'] ?? null,
                'course_name' => $q['course_name'] ?? null,
                'total_questions' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'blank_count' => 0,
                'success_rate' => 0.0,
            ];
        }
        $agg[$cid]['total_questions']++;
        if (!empty($q['is_blank'])) {
            $agg[$cid]['blank_count']++;
        } elseif (!empty($q['is_correct'])) {
            $agg[$cid]['correct_count']++;
        } else {
            $agg[$cid]['wrong_count']++;
        }
    }
    foreach ($agg as $cid => $r) {
        $agg[$cid]['success_rate'] = $r['total_questions'] > 0 ? round(($r['correct_count'] / $r['total_questions']) * 100, 2) : 0.0;
    }
    return array_values($agg);
}

function mock_exam_build_summary_stats(PDO $pdo, string $userId): array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $sql = 'SELECT '
        . 'COUNT(*) AS total_attempts, '
        . 'SUM(CASE WHEN ' . mock_exam_q($a['status']) . " = 'completed' THEN 1 ELSE 0 END) AS completed_attempts, "
        . 'SUM(CASE WHEN ' . mock_exam_q($a['status']) . " = 'abandoned' THEN 1 ELSE 0 END) AS abandoned_attempts, "
        . ($a['success_rate'] ? ('AVG(CASE WHEN ' . mock_exam_q($a['status']) . " = 'completed' THEN " . mock_exam_q($a['success_rate']) . ' END)') : 'NULL') . ' AS average_success_rate, '
        . ($a['success_rate'] ? ('MAX(CASE WHEN ' . mock_exam_q($a['status']) . " = 'completed' THEN " . mock_exam_q($a['success_rate']) . ' END)') : 'NULL') . ' AS best_success_rate '
        . 'FROM `' . $a['table'] . '` WHERE ' . mock_exam_q($a['user_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $lastSql = 'SELECT ' . ($a['success_rate'] ? mock_exam_q($a['success_rate']) : 'NULL') . ' AS success_rate FROM `' . $a['table'] . '` '
        . 'WHERE ' . mock_exam_q($a['user_id']) . ' = ? AND ' . mock_exam_q($a['status']) . " = 'completed' "
        . 'ORDER BY ' . ($a['submitted_at'] ? mock_exam_q($a['submitted_at']) : mock_exam_q($a['id'])) . ' DESC LIMIT 1';
    $lastStmt = $pdo->prepare($lastSql);
    $lastStmt->execute([$userId]);
    $lastRate = $lastStmt->fetchColumn();

    return [
        'total_attempts' => (int)($row['total_attempts'] ?? 0),
        'completed_attempts' => (int)($row['completed_attempts'] ?? 0),
        'abandoned_attempts' => (int)($row['abandoned_attempts'] ?? 0),
        'average_success_rate' => $row['average_success_rate'] !== null ? round((float)$row['average_success_rate'], 2) : 0.0,
        'best_success_rate' => $row['best_success_rate'] !== null ? round((float)$row['best_success_rate'], 2) : 0.0,
        'last_success_rate' => $lastRate !== false && $lastRate !== null ? round((float)$lastRate, 2) : 0.0,
    ];
}

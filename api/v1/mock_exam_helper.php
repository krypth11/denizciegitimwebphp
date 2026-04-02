<?php

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/study_helper.php';

function mock_exam_debug_log(string $stage, array $context = []): void
{
    $line = '[mock_exam][' . $stage . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log($line !== false ? $line : ('[mock_exam][' . $stage . ']'));
}

function mock_exam_decode_json_payload($value): ?array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

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
    return in_array($v, ['random', 'unseen', 'seen', 'wrong'], true) ? $v : 'random';
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
        'source' => mock_exam_pick($cols, ['source'], false),
        'summary_json' => mock_exam_pick($cols, ['summary_json'], false),
        'detail_json' => mock_exam_pick($cols, ['detail_json'], false),
        'lesson_report_json' => mock_exam_pick($cols, ['lesson_report_json'], false),
        'questions_json' => mock_exam_pick($cols, ['questions_json'], false),
        'fingerprint' => mock_exam_pick($cols, ['fingerprint', 'sync_fingerprint', 'offline_fingerprint', 'payload_fingerprint'], false),
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
        'is_correct' => mock_exam_pick($cols, ['is_correct'], false),
        'answered_at' => mock_exam_pick($cols, ['answered_at'], false),
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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
    $correct = (int)($row['correct_count'] ?? 0);
    $wrong = (int)($row['wrong_count'] ?? 0);
    $blank = (int)($row['blank_count'] ?? 0);
    $actual = (int)($row['actual_question_count'] ?? 0);
    $answered = max(0, $correct + $wrong);
    $successRate = isset($row['success_rate'])
        ? (float)$row['success_rate']
        : ($actual > 0 ? round(($correct / $actual) * 100, 2) : 0.0);

    return [
        'id' => (string)($row['id'] ?? ''),
        'remote_attempt_id' => (string)($row['id'] ?? ''),
        'source_attempt_id' => $row['source_attempt_id'] ?? null,
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
        'completed_at' => $row['submitted_at'] ?? null,
        'abandoned_at' => $row['abandoned_at'] ?? null,
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'blank_count' => $blank,
        'success_rate' => $successRate,
        'answered_count' => $answered,
        'duration_seconds' => (int)($row['elapsed_seconds'] ?? 0),
    ];
}

function mock_exam_fetch_qualification_courses(PDO $pdo, string $qualificationId): array
{
    $stmt = $pdo->prepare('SELECT id, name FROM courses WHERE qualification_id = ? ORDER BY order_index ASC, name ASC');
    $stmt->execute([$qualificationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mock_exam_fetch_candidate_questions(PDO $pdo, string $qualificationId): array
{
    $sql = 'SELECT q.id, q.course_id, q.question_type, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.option_e, q.correct_answer, q.explanation, c.name AS course_name '
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

    $seen = [];
    $ph = implode(',', array_fill(0, count($questionIds), '?'));

    // 1) user_progress tablosu (kalıcı çözüm geçmişi)
    try {
        $up = study_get_user_progress_schema($pdo);
        if (!empty($up['user_id']) && !empty($up['question_id'])) {
            $conds = [];
            if (!empty($up['is_answered'])) {
                $conds[] = 'COALESCE(up.' . mock_exam_q($up['is_answered']) . ',0) = 1';
            }
            if (!empty($up['total_answer_count'])) {
                $conds[] = 'COALESCE(up.' . mock_exam_q($up['total_answer_count']) . ',0) > 0';
            }
            if ($conds) {
                $sql = 'SELECT DISTINCT up.' . mock_exam_q($up['question_id']) . ' AS question_id '
                    . 'FROM ' . mock_exam_q($up['table']) . ' up '
                    . 'WHERE up.' . mock_exam_q($up['user_id']) . ' = ? '
                    . 'AND up.' . mock_exam_q($up['question_id']) . ' IN (' . $ph . ') '
                    . 'AND (' . implode(' OR ', $conds) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$userId], $questionIds));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $r) {
                    $qid = (string)($r['question_id'] ?? '');
                    if ($qid !== '') {
                        $seen[$qid] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // user_progress yoksa fallback ile devam
    }

    // 2) fallback: sadece tamamlanmış denemelerde seçilmiş sorular
    try {
        $a = mock_exam_get_attempt_schema($pdo);
        $aq = mock_exam_get_attempt_question_schema($pdo);
        if (!empty($a['status']) && !empty($aq['selected_answer'])) {
            $sql = 'SELECT DISTINCT aq.' . mock_exam_q($aq['question_id']) . ' AS question_id '
                . 'FROM ' . mock_exam_q($aq['table']) . ' aq '
                . 'INNER JOIN ' . mock_exam_q($a['table']) . ' a ON aq.' . mock_exam_q($aq['attempt_id']) . ' = a.' . mock_exam_q($a['id']) . ' '
                . 'WHERE a.' . mock_exam_q($a['user_id']) . ' = ? '
                . "AND a." . mock_exam_q($a['status']) . " = 'completed' "
                . 'AND aq.' . mock_exam_q($aq['question_id']) . ' IN (' . $ph . ') '
                . "AND TRIM(COALESCE(aq." . mock_exam_q($aq['selected_answer']) . ", '')) <> ''";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$userId], $questionIds));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $qid = (string)($r['question_id'] ?? '');
                if ($qid !== '') {
                    $seen[$qid] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // fallback da yoksa mevcut seen döner
    }

    return $seen;
}

function mock_exam_calculate_pool_counts(PDO $pdo, string $userId, string $qualificationId): array
{
    $candidates = mock_exam_fetch_candidate_questions($pdo, $qualificationId);
    $ids = array_values(array_map(static fn(array $r): string => (string)$r['id'], $candidates));
    $seen = mock_exam_fetch_seen_question_ids($pdo, $userId, $ids);
    $wrongIds = mock_exam_fetch_wrong_question_ids($pdo, $userId, $qualificationId);
    $wrongMap = array_fill_keys($wrongIds, true);
    $seenCount = 0;
    $wrongCount = 0;
    foreach ($ids as $id) {
        if (isset($seen[$id])) {
            $seenCount++;
        }
        if (isset($wrongMap[$id])) {
            $wrongCount++;
        }
    }
    $total = count($ids);
    return [
        'total' => $total,
        'seen' => $seenCount,
        'unseen' => max(0, $total - $seenCount),
        'wrong' => $wrongCount,
    ];
}

function mock_exam_fetch_wrong_question_ids(PDO $pdo, string $userId, string $qualificationId): array
{
    $candidates = mock_exam_fetch_candidate_questions($pdo, $qualificationId);
    if (!$candidates) {
        return [];
    }

    $candidateIds = array_values(array_unique(array_map(static fn(array $r): string => (string)$r['id'], $candidates)));
    if (empty($candidateIds)) {
        return [];
    }

    $wrong = [];
    $ph = implode(',', array_fill(0, count($candidateIds), '?'));

    // 1) user_progress öncelikli
    try {
        $up = study_get_user_progress_schema($pdo);
        if (!empty($up['user_id']) && !empty($up['question_id'])) {
            $conds = [];
            if (!empty($up['wrong_answer_count'])) {
                $conds[] = 'COALESCE(up.' . mock_exam_q($up['wrong_answer_count']) . ',0) > 0';
            }
            if (!empty($up['is_correct']) && !empty($up['total_answer_count'])) {
                $conds[] = '(COALESCE(up.' . mock_exam_q($up['total_answer_count']) . ',0) > 0 AND COALESCE(up.' . mock_exam_q($up['is_correct']) . ',1) = 0)';
            }

            if ($conds) {
                $sql = 'SELECT DISTINCT up.' . mock_exam_q($up['question_id']) . ' AS question_id '
                    . 'FROM ' . mock_exam_q($up['table']) . ' up '
                    . 'WHERE up.' . mock_exam_q($up['user_id']) . ' = ? '
                    . 'AND up.' . mock_exam_q($up['question_id']) . ' IN (' . $ph . ') '
                    . 'AND (' . implode(' OR ', $conds) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$userId], $candidateIds));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $r) {
                    $qid = (string)($r['question_id'] ?? '');
                    if ($qid !== '') {
                        $wrong[$qid] = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // user_progress yoksa fallback ile devam
    }

    // 2) fallback: tamamlanmış mock exam yanlışları
    try {
        $a = mock_exam_get_attempt_schema($pdo);
        $aq = mock_exam_get_attempt_question_schema($pdo);
        if (!empty($a['status']) && !empty($aq['selected_answer']) && !empty($aq['correct_answer'])) {
            $sql = 'SELECT DISTINCT aq.' . mock_exam_q($aq['question_id']) . ' AS question_id '
                . 'FROM ' . mock_exam_q($aq['table']) . ' aq '
                . 'INNER JOIN ' . mock_exam_q($a['table']) . ' a ON aq.' . mock_exam_q($aq['attempt_id']) . ' = a.' . mock_exam_q($a['id']) . ' '
                . 'WHERE a.' . mock_exam_q($a['user_id']) . ' = ? '
                . "AND a." . mock_exam_q($a['status']) . " = 'completed' "
                . 'AND aq.' . mock_exam_q($aq['question_id']) . ' IN (' . $ph . ') '
                . "AND TRIM(COALESCE(aq." . mock_exam_q($aq['selected_answer']) . ", '')) <> '' "
                . "AND UPPER(TRIM(COALESCE(aq." . mock_exam_q($aq['selected_answer']) . ", ''))) <> UPPER(TRIM(COALESCE(aq." . mock_exam_q($aq['correct_answer']) . ", '')))";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$userId], $candidateIds));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $qid = (string)($r['question_id'] ?? '');
                if ($qid !== '') {
                    $wrong[$qid] = true;
                }
            }
        }
    } catch (Throwable $e) {
        // sessiz fallback
    }

    return array_keys($wrong);
}

function mock_exam_assert_questions_not_empty(array $questions): void
{
    if (count($questions) < 1) {
        throw new RuntimeException('Bu kriterlere uygun deneme soruları oluşturulamadı.');
    }
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
            if (!$rows) {
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
        . 'FROM ' . mock_exam_q($aq['table']) . ' aq '
        . 'INNER JOIN ' . mock_exam_q($a['table']) . ' a ON aq.' . mock_exam_q($aq['attempt_id']) . ' = a.' . mock_exam_q($a['id']) . ' '
        . 'WHERE a.' . mock_exam_q($a['user_id']) . ' = ? AND a.' . mock_exam_q($a['id']) . ' = ?';
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

    if ($mode === 'similar' && !empty($sourceMap)) {
        $targetByCourse = [];
        foreach ($sourceMap as $row) {
            $cid = (string)($row['course_id'] ?? '');
            if ($cid !== '') {
                $targetByCourse[$cid] = ($targetByCourse[$cid] ?? 0) + 1;
            }
        }
        if ($targetByCourse) {
            $coursePools = [];
            foreach ($candidates as $q) {
                $cid = (string)($q['course_id'] ?? '');
                if ($cid !== '') {
                    $coursePools[$cid][] = $q;
                }
            }

            $similarPicked = [];
            $sourceTotal = array_sum($targetByCourse);
            foreach ($targetByCourse as $cid => $cnt) {
                $targetCnt = max(1, (int)round(($cnt / max(1, $sourceTotal)) * $requestedCount));
                $part = mock_exam_balanced_pick($coursePools[$cid] ?? [], $targetCnt, $sourceQuestionIds);
                $similarPicked = array_merge($similarPicked, $part);
            }

            if (count($similarPicked) < $requestedCount) {
                $pickedIds = array_fill_keys(array_map(static fn(array $q): string => (string)$q['id'], $similarPicked), true);
                $fallbackPool = array_values(array_filter($candidates, static fn(array $q): bool => !isset($pickedIds[(string)$q['id']])));
                $similarPicked = array_merge($similarPicked, mock_exam_balanced_pick($fallbackPool, $requestedCount - count($similarPicked), $sourceQuestionIds));
            }

            if ($similarPicked) {
                $candidates = array_values(array_slice($similarPicked, 0, $requestedCount));
            }
        }
    }

    $ids = array_values(array_map(static fn(array $r): string => (string)$r['id'], $candidates));
    $seenMap = mock_exam_fetch_seen_question_ids($pdo, $userId, $ids);
    $seenPool = [];
    $unseenPool = [];
    $wrongMap = array_fill_keys(mock_exam_fetch_wrong_question_ids($pdo, $userId, $qualificationId), true);
    $wrongPool = [];
    foreach ($candidates as $q) {
        $qid = (string)$q['id'];
        if (isset($seenMap[$qid])) {
            $seenPool[] = $q;
        } else {
            $unseenPool[] = $q;
        }
        if (isset($wrongMap[$qid])) {
            $wrongPool[] = $q;
        }
    }

    $warning = null;
    if ($poolType === 'wrong') {
        if (count($wrongPool) < 1) {
            throw new RuntimeException('Yanlış yaptığınız soru yok');
        }
        $picked = mock_exam_balanced_pick($wrongPool, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
        if (count($picked) < $requestedCount) {
            $remainingNeed = $requestedCount - count($picked);
            $pickedIds = array_fill_keys(array_map(static fn(array $q): string => (string)$q['id'], $picked), true);
            $fallbackPool = array_values(array_filter($candidates, static fn(array $q): bool => !isset($pickedIds[(string)$q['id']])));
            $picked = array_merge($picked, mock_exam_balanced_pick($fallbackPool, $remainingNeed, $mode === 'similar' ? $sourceQuestionIds : []));
            $warning = 'Yanlış yaptığınız ' . count($wrongPool) . ' soru bulundu. Kalan ' . $remainingNeed . ' soru rastgele tamamlandı.';
        }
    } elseif ($poolType === 'seen') {
        $picked = mock_exam_balanced_pick($seenPool, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
    } elseif ($poolType === 'unseen') {
        $picked = mock_exam_balanced_pick($unseenPool, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
        if (count($picked) < $requestedCount) {
            $remainingNeed = $requestedCount - count($picked);
            $pickedIds = array_fill_keys(array_map(static fn(array $q): string => (string)$q['id'], $picked), true);
            $fallbackPool = array_values(array_filter($candidates, static fn(array $q): bool => !isset($pickedIds[(string)$q['id']])));
            $picked = array_merge($picked, mock_exam_balanced_pick($fallbackPool, $remainingNeed, $mode === 'similar' ? $sourceQuestionIds : []));
            $warning = (count($unseenPool) === 0)
                ? 'Tüm soruları çözdünüz'
                : ($requestedCount . ' sorudan ' . min($requestedCount, count($unseenPool)) . ' tanesi çözülmemiş havuzdan seçildi. Kalan kısım rastgele tamamlandı.');
        }
    } else {
        $picked = mock_exam_balanced_pick($candidates, $requestedCount, $mode === 'similar' ? $sourceQuestionIds : []);
    }

    if (!$picked) {
        throw new RuntimeException('Bu kriterlere uygun deneme soruları oluşturulamadı.');
    }

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
            'wrong' => count($wrongPool),
        ],
    ];
}

function mock_exam_fetch_attempt_questions(PDO $pdo, string $attemptId, bool $withResultFields = false): array
{
    $aq = mock_exam_get_attempt_question_schema($pdo);
    $orderCol = $aq['order_index'] ?: $aq['id'];
    $sql = 'SELECT * FROM ' . mock_exam_q($aq['table']) . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? ORDER BY ' . mock_exam_q($orderCol) . ' ASC';
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
        ];
        if ($withResultFields) {
            $item['correct_answer'] = $correct;
            $item['explanation'] = $aq['explanation'] ? ($r[$aq['explanation']] ?? null) : null;
            $item['image_url'] = null;
            $item['is_correct'] = $isCorrect;
            $item['is_blank'] = !$isAnswered;
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
        'total_questions' => $total,
        'answered_count' => $answered,
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'blank_count' => $blank,
        'success_rate' => $successRate,
        'elapsed_seconds' => 0,
        'duration_seconds_limit' => 2400,
        'status' => 'in_progress',
    ];
}

function mock_exam_build_summary_from_questions(array $questions, array $attempt): array
{
    $status = (string)($attempt['status'] ?? 'in_progress');

    $correct = 0;
    $wrong = 0;
    $blank = 0;
    foreach ($questions as $q) {
        $selected = strtoupper(trim((string)($q['selected_answer'] ?? '')));
        if ($selected === '') {
            $blank++;
            continue;
        }

        if (!empty($q['is_correct'])) {
            $correct++;
        } else {
            $wrong++;
        }
    }

    $flaggedCount = 0;
    foreach ($questions as $q) {
        if (!empty($q['is_flagged'])) {
            $flaggedCount++;
        }
    }

    $total = $correct + $wrong + $blank;
    $successRate = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;

    return [
        'total_questions' => $total,
        'answered_count' => $correct + $wrong,
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'blank_count' => $blank,
        'flagged_count' => $flaggedCount,
        'success_rate' => $successRate,
        'elapsed_seconds' => (int)($attempt['elapsed_seconds'] ?? 0),
        'duration_seconds_limit' => (int)($attempt['duration_seconds_limit'] ?? 2400),
        'status' => $status,
    ];
}

function mock_exam_pick_course_insights(array $lessonReport): array
{
    $buildLabel = static function (?array $row): string {
        if (!$row) {
            return '';
        }
        $qualification = trim((string)($row['qualification_name'] ?? ''));
        $course = trim((string)($row['course_name'] ?? ''));
        if ($qualification !== '' && $course !== '') {
            return $qualification . ' • ' . $course;
        }
        if ($qualification !== '') {
            return $qualification;
        }
        return $course;
    };

    if (!$lessonReport) {
        return [
            'strongest_course' => '',
            'weakest_course' => '',
            'most_blank_course' => '',
        ];
    }

    $strongest = null;
    $weakest = null;
    $mostBlank = null;

    foreach ($lessonReport as $row) {
        if ($strongest === null || (float)$row['success_rate'] > (float)$strongest['success_rate']) {
            $strongest = $row;
        }
        if ($weakest === null || (float)$row['success_rate'] < (float)$weakest['success_rate']) {
            $weakest = $row;
        }
        if ($mostBlank === null || (int)$row['blank_count'] > (int)$mostBlank['blank_count']) {
            $mostBlank = $row;
        }
    }

    return [
        'strongest_course' => $buildLabel($strongest),
        'weakest_course' => $buildLabel($weakest),
        'most_blank_course' => $buildLabel($mostBlank),
    ];
}

function mock_exam_standardize_summary(array $attempt, array $questions = []): array
{
    return mock_exam_build_summary_from_questions($questions, $attempt);
}

function mock_exam_history_item_from_attempt(array $attempt): array
{
    $correct = (int)($attempt['correct_count'] ?? 0);
    $wrong = (int)($attempt['wrong_count'] ?? 0);
    $blank = (int)($attempt['blank_count'] ?? 0);
    $total = $correct + $wrong + $blank;
    $successRate = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;

    return [
        'id' => (string)($attempt['id'] ?? ''),
        'remote_attempt_id' => (string)($attempt['id'] ?? ''),
        'source_attempt_id' => $attempt['source_attempt_id'] ?? null,
        'qualification_id' => $attempt['qualification_id'] ?? null,
        'qualification_name' => $attempt['qualification_name'] ?? null,
        'requested_question_count' => (int)($attempt['requested_question_count'] ?? 0),
        'actual_question_count' => (int)($attempt['actual_question_count'] ?? 0),
        'elapsed_seconds' => (int)($attempt['elapsed_seconds'] ?? 0),
        'status' => (string)($attempt['status'] ?? 'in_progress'),
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'blank_count' => $blank,
        'success_rate' => $successRate,
        'duration_seconds' => (int)($attempt['elapsed_seconds'] ?? 0),
        'started_at' => $attempt['started_at'] ?? null,
        'submitted_at' => $attempt['submitted_at'] ?? null,
        'completed_at' => $attempt['submitted_at'] ?? null,
        'abandoned_at' => $attempt['abandoned_at'] ?? null,
        'warning_message' => $attempt['warning_message'] ?? null,
    ];
}

function mock_exam_calculate_attempt_aggregates(PDO $pdo, string $attemptId): array
{
    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, true);
    return mock_exam_compute_summary_from_questions($questions);
}

function mock_exam_find_active_attempt(PDO $pdo, string $userId): ?array
{
    $s = mock_exam_get_attempt_schema($pdo);
    if (!$s['status']) {
        return null;
    }
    $order = $s['started_at'] ? ('a.' . mock_exam_q($s['started_at'])) : ('a.' . mock_exam_q($s['id']));
    $sql = 'SELECT a.*, q.name AS qualification_name FROM ' . mock_exam_q($s['table']) . ' a '
        . 'LEFT JOIN qualifications q ON a.' . mock_exam_q($s['qualification_id']) . ' = q.id '
        . 'WHERE a.' . mock_exam_q($s['user_id']) . ' = ? AND a.' . mock_exam_q($s['status']) . " = 'in_progress' "
        . 'ORDER BY ' . $order . ' DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? mock_exam_format_attempt($row) : null;
}

function mock_exam_find_attempt_by_id(PDO $pdo, string $userId, string $attemptId): ?array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $sql = 'SELECT a.*, q.name AS qualification_name FROM ' . mock_exam_q($a['table']) . ' a '
        . 'LEFT JOIN qualifications q ON a.' . mock_exam_q($a['qualification_id']) . ' = q.id '
        . 'WHERE a.' . mock_exam_q($a['id']) . ' = ? AND a.' . mock_exam_q($a['user_id']) . ' = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$attemptId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? mock_exam_format_attempt($row) : null;
}

function mock_exam_fetch_attempt_by_id(PDO $pdo, string $userId, string $attemptId): ?array
{
    return mock_exam_find_attempt_by_id($pdo, $userId, $attemptId);
}

function mock_exam_fetch_attempt_detail(PDO $pdo, string $userId, string $attemptId): array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $sql = 'SELECT a.*, q.name AS qualification_name FROM ' . mock_exam_q($a['table']) . ' a '
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

    $detailJson = null;
    if (!empty($a['detail_json'])) {
        $detailJson = mock_exam_decode_json_payload($row[$a['detail_json']] ?? null);
    }
    $summaryJson = null;
    if (!empty($a['summary_json'])) {
        $summaryJson = mock_exam_decode_json_payload($row[$a['summary_json']] ?? null);
    }
    $lessonReportJson = null;
    if (!empty($a['lesson_report_json'])) {
        $lessonReportJson = mock_exam_decode_json_payload($row[$a['lesson_report_json']] ?? null);
    }
    $questionsJson = null;
    if (!empty($a['questions_json'])) {
        $questionsJson = mock_exam_decode_json_payload($row[$a['questions_json']] ?? null);
    }

    if (empty($questions) && is_array($detailJson) && !empty($detailJson['questions']) && is_array($detailJson['questions'])) {
        $questions = $detailJson['questions'];
    }
    if (empty($questions) && is_array($questionsJson) && !empty($questionsJson)) {
        $questions = $questionsJson;
    }

    $summary = mock_exam_standardize_summary($attempt, $questions);
    if ((int)($summary['total_questions'] ?? 0) === 0 && is_array($summaryJson)) {
        $summary = array_merge($summary, $summaryJson);
    }
    if ((int)($summary['total_questions'] ?? 0) === 0 && is_array($detailJson) && !empty($detailJson['summary']) && is_array($detailJson['summary'])) {
        $summary = array_merge($summary, $detailJson['summary']);
    }

    $lessonReport = mock_exam_build_lesson_report($pdo, $attemptId);
    if (empty($lessonReport) && is_array($lessonReportJson) && !empty($lessonReportJson)) {
        $lessonReport = $lessonReportJson;
    }
    if (empty($lessonReport) && is_array($detailJson) && !empty($detailJson['lesson_report']) && is_array($detailJson['lesson_report'])) {
        $lessonReport = $detailJson['lesson_report'];
    }

    mock_exam_debug_log('detail.response', [
        'attempt_id' => $attemptId,
        'question_count' => is_array($questions) ? count($questions) : 0,
        'lesson_report_count' => is_array($lessonReport) ? count($lessonReport) : 0,
    ]);

    $insights = mock_exam_pick_course_insights($lessonReport);
    return [
        'attempt' => $attempt,
        'questions' => $questions,
        'summary' => $summary,
        'lesson_report' => $lessonReport,
        'statistics' => $summary,
        'strongest_course' => $insights['strongest_course'],
        'weakest_course' => $insights['weakest_course'],
        'most_blank_course' => $insights['most_blank_course'],
        'resume_existing' => false,
    ];
}

function mock_exam_fetch_active_attempt_detail(PDO $pdo, string $userId): ?array
{
    $active = mock_exam_find_active_attempt($pdo, $userId);
    if (!$active) {
        return null;
    }
    return mock_exam_fetch_attempt_detail($pdo, $userId, (string)$active['id']);
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
    $sourceAttemptId = ($sourceAttemptId === '') ? null : $sourceAttemptId;

    $activeDetail = mock_exam_fetch_active_attempt_detail($pdo, $userId);
    if ($activeDetail) {
        mock_exam_assert_questions_not_empty($activeDetail['questions'] ?? []);
        $activeDetail['resume_existing'] = true;
        return $activeDetail;
    }

    $set = mock_exam_build_question_set($pdo, $userId, $qualificationId, $requested, $poolType, $mode, $sourceAttemptId);
    $questions = $set['questions'];
    mock_exam_assert_questions_not_empty($questions);
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
        ] as $k => $v) {
            if (!empty($a[$k])) {
                $cols[] = mock_exam_q($a[$k]);
                $vals[] = '?';
                $params[] = $v;
            }
        }
        foreach (['started_at', 'created_at', 'updated_at'] as $dt) {
            if (!empty($a[$dt])) {
                $cols[] = mock_exam_q($a[$dt]);
                $vals[] = 'NOW()';
            }
        }
        $stmtA = $pdo->prepare('INSERT INTO ' . mock_exam_q($a['table']) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')');
        $stmtA->execute($params);

        $insertedCount = 0;
        foreach ($questions as $q) {
            $ic = [mock_exam_q($aq['id']), mock_exam_q($aq['attempt_id']), mock_exam_q($aq['question_id'])];
            $iv = ['?', '?', '?'];
            $ip = [generate_uuid(), $attemptId, $q['id']];
            $map = [
                'course_id' => $q['course_id'] ?? null,
                'course_name' => $q['course_name'] ?? null,
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
            foreach ($map as $k => $v) {
                if (!empty($aq[$k])) {
                    $ic[] = mock_exam_q($aq[$k]);
                    $iv[] = '?';
                    $ip[] = $v;
                }
            }
            foreach (['created_at', 'updated_at'] as $dt) {
                if (!empty($aq[$dt])) {
                    $ic[] = mock_exam_q($aq[$dt]);
                    $iv[] = 'NOW()';
                }
            }
            $stmtQ = $pdo->prepare('INSERT INTO ' . mock_exam_q($aq['table']) . ' (' . implode(', ', $ic) . ') VALUES (' . implode(', ', $iv) . ')');
            $stmtQ->execute($ip);
            $insertedCount++;
        }

        if ($insertedCount !== count($questions)) {
            throw new RuntimeException('Bu kriterlere uygun deneme soruları oluşturulamadı.');
        }

        mock_exam_save_user_exam_preferences($pdo, $userId, $poolType, $requested);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $detail = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    mock_exam_assert_questions_not_empty($detail['questions'] ?? []);
    return $detail;
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

    $existingSql = 'SELECT '
        . ($aq['selected_answer'] ? (mock_exam_q($aq['selected_answer']) . ' AS selected_answer') : 'NULL AS selected_answer')
        . ($aq['correct_answer'] ? (', ' . mock_exam_q($aq['correct_answer']) . ' AS correct_answer') : ', NULL AS correct_answer')
        . ' FROM ' . mock_exam_q($aq['table'])
        . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ? LIMIT 1';
    $existingStmt = $pdo->prepare($existingSql);
    $existingStmt->execute([$attemptId, $questionId]);
    $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingRow) {
        throw new RuntimeException('Soru bu denemeye ait değil.');
    }

    $existingSelectedRaw = $existingRow['selected_answer'] ?? null;
    $existingSelected = strtoupper(trim((string)$existingSelectedRaw));
    if ($existingSelected === '') {
        $existingSelected = null;
    }

    if ($existingSelected === $selected) {
        $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, false);
        $answeredCount = 0;
        foreach ($questions as $q) {
            if (!empty($q['is_answered'])) {
                $answeredCount++;
            }
        }
        return [
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'selected_answer' => $selected,
            'is_answered' => $selected !== null,
            'answered_count' => $answeredCount,
        ];
    }

    $set = [];
    $params = [];
    if ($aq['selected_answer']) {
        $set[] = mock_exam_q($aq['selected_answer']) . ' = ?';
        $params[] = $selected;
    }
    if ($aq['is_correct']) {
        $correctAnswer = strtoupper(trim((string)($existingRow['correct_answer'] ?? '')));
        $isCorrect = ($selected !== null && $correctAnswer !== '' && strtoupper((string)$selected) === $correctAnswer) ? 1 : 0;
        $set[] = mock_exam_q($aq['is_correct']) . ' = ?';
        $params[] = $isCorrect;
    }
    if ($aq['answered_at']) {
        $set[] = mock_exam_q($aq['answered_at']) . ' = ' . ($selected === null ? 'NULL' : 'NOW()');
    }
    if ($aq['updated_at']) {
        $set[] = mock_exam_q($aq['updated_at']) . ' = NOW()';
    }
    $params[] = $attemptId;
    $params[] = $questionId;
    $sql = 'UPDATE ' . mock_exam_q($aq['table']) . ' SET ' . implode(', ', $set)
        . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() < 1) {
        $verifyStmt = $pdo->prepare($existingSql);
        $verifyStmt->execute([$attemptId, $questionId]);
        $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$verifyRow) {
            throw new RuntimeException('Soru bu denemeye ait değil.');
        }
        $verifySelected = strtoupper(trim((string)($verifyRow['selected_answer'] ?? '')));
        if ($verifySelected === '') {
            $verifySelected = null;
        }
        if ($verifySelected !== $selected) {
            throw new RuntimeException('Cevap kaydedilemedi.');
        }
    }

    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, false);
    $answeredCount = 0;
    foreach ($questions as $q) {
        if (!empty($q['is_answered'])) {
            $answeredCount++;
        }
    }
    return [
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'selected_answer' => $selected,
        'is_answered' => $selected !== null,
        'answered_count' => $answeredCount,
    ];
}

function mock_exam_toggle_flag(PDO $pdo, string $userId, string $attemptId, string $questionId, bool $isFlagged): array
{
    $aq = mock_exam_get_attempt_question_schema($pdo);
    $detail = mock_exam_assert_attempt_in_progress($pdo, $userId, $attemptId);

    if (!$aq['is_flagged']) {
        throw new RuntimeException('is_flagged kolonu bulunamadı.');
    }

    $existingSql = 'SELECT ' . mock_exam_q($aq['is_flagged']) . ' AS is_flagged FROM ' . mock_exam_q($aq['table'])
        . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ? LIMIT 1';
    $existingStmt = $pdo->prepare($existingSql);
    $existingStmt->execute([$attemptId, $questionId]);
    $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingRow) {
        throw new RuntimeException('Soru bu denemeye ait değil.');
    }

    $existingFlag = ((int)($existingRow['is_flagged'] ?? 0) === 1);
    if ($existingFlag === $isFlagged) {
        return ['attempt_id' => $attemptId, 'question_id' => $questionId, 'is_flagged' => $isFlagged];
    }

    $set = [mock_exam_q($aq['is_flagged']) . ' = ?'];
    $params = [$isFlagged ? 1 : 0];
    if ($aq['updated_at']) {
        $set[] = mock_exam_q($aq['updated_at']) . ' = NOW()';
    }
    $params[] = $attemptId;
    $params[] = $questionId;
    $sql = 'UPDATE ' . mock_exam_q($aq['table']) . ' SET ' . implode(', ', $set)
        . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() < 1) {
        $verifyStmt = $pdo->prepare($existingSql);
        $verifyStmt->execute([$attemptId, $questionId]);
        $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$verifyRow) {
            throw new RuntimeException('Soru bu denemeye ait değil.');
        }
        $verifyFlag = ((int)($verifyRow['is_flagged'] ?? 0) === 1);
        if ($verifyFlag !== $isFlagged) {
            throw new RuntimeException('İşaret durumu kaydedilemedi.');
        }
    }

    return ['attempt_id' => $attemptId, 'question_id' => $questionId, 'is_flagged' => $isFlagged];
}

function mock_exam_write_events_and_progress(PDO $pdo, string $userId, string $attemptId, array $questions, ?string $qualificationId = null): void
{
    foreach ($questions as $q) {
        $selected = strtoupper(trim((string)($q['selected_answer'] ?? '')));
        if ($selected === '') {
            continue;
        }
        $correct = strtoupper(trim((string)($q['correct_answer'] ?? '')));
        $isCorrect = ($correct !== '' && $selected === $correct);

        study_insert_attempt_event($pdo, [
            'user_id' => $userId,
            'question_id' => (string)$q['question_id'],
            'course_id' => $q['course_id'] ?? null,
            'qualification_id' => $qualificationId,
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
        $lessonReport = mock_exam_build_lesson_report($pdo, $attemptId);
        $insights = mock_exam_pick_course_insights($lessonReport);
        return [
            'already_submitted' => true,
            'attempt' => $detail['attempt'],
            'summary' => mock_exam_standardize_summary($detail['attempt'], $questions),
            'lesson_report' => $lessonReport,
            'questions' => $questions,
            'strongest_course' => $insights['strongest_course'],
            'weakest_course' => $insights['weakest_course'],
            'most_blank_course' => $insights['most_blank_course'],
        ];
    }
    if ($status !== 'in_progress') {
        throw new RuntimeException('Bu deneme submit edilemez.');
    }

    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, true);
    $summary = mock_exam_compute_summary_from_questions($questions);

    $pdo->beginTransaction();
    try {
        $aq = mock_exam_get_attempt_question_schema($pdo);
        if ($aq['is_correct']) {
            foreach ($questions as $q) {
                $selected = strtoupper(trim((string)($q['selected_answer'] ?? '')));
                $correct = strtoupper(trim((string)($q['correct_answer'] ?? '')));
                $isCorrect = ($selected !== '' && $correct !== '' && $selected === $correct) ? 1 : 0;
                $setQ = [mock_exam_q($aq['is_correct']) . ' = ?'];
                $paramsQ = [$isCorrect];
                if ($aq['answered_at'] && $selected !== '') {
                    $setQ[] = mock_exam_q($aq['answered_at']) . ' = COALESCE(' . mock_exam_q($aq['answered_at']) . ', NOW())';
                }
                if ($aq['updated_at']) {
                    $setQ[] = mock_exam_q($aq['updated_at']) . ' = NOW()';
                }
                $paramsQ[] = $attemptId;
                $paramsQ[] = (string)$q['question_id'];
                $sqlQ = 'UPDATE ' . mock_exam_q($aq['table']) . ' SET ' . implode(', ', $setQ)
                    . ' WHERE ' . mock_exam_q($aq['attempt_id']) . ' = ? AND ' . mock_exam_q($aq['question_id']) . ' = ?';
                $stmtQ = $pdo->prepare($sqlQ);
                $stmtQ->execute($paramsQ);
            }
        }

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
        $sql = 'UPDATE ' . mock_exam_q($a['table']) . ' SET ' . implode(', ', $set)
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
        'summary' => $latest['summary'],
        'lesson_report' => $latest['lesson_report'] ?? [],
        'questions' => $latest['questions'] ?? [],
        'strongest_course' => $latest['strongest_course'] ?? '',
        'weakest_course' => $latest['weakest_course'] ?? '',
        'most_blank_course' => $latest['most_blank_course'] ?? '',
    ];
}

function mock_exam_abandon(PDO $pdo, string $userId, string $attemptId, int $elapsedSeconds): array
{
    $elapsedSeconds = max(0, $elapsedSeconds);
    $a = mock_exam_get_attempt_schema($pdo);
    $detail = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    $status = (string)($detail['attempt']['status'] ?? '');
    if ($status === 'abandoned') {
        return [
            'attempt' => $detail['attempt'],
            'summary' => mock_exam_standardize_summary($detail['attempt'], $detail['questions'] ?? []),
            'already_abandoned' => true,
        ];
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
    $sql = 'UPDATE ' . mock_exam_q($a['table']) . ' SET ' . implode(', ', $set)
        . ' WHERE ' . mock_exam_q($a['id']) . ' = ? AND ' . mock_exam_q($a['user_id']) . ' = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $latest = mock_exam_fetch_attempt_detail($pdo, $userId, $attemptId);
    return ['attempt' => $latest['attempt'], 'summary' => mock_exam_standardize_summary($latest['attempt'], $latest['questions'] ?? [])];
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

    $orderTimeCol = $a['submitted_at'] ?: ($a['created_at'] ?: $a['id']);
    $orderBy = 'a.' . mock_exam_q($orderTimeCol) . ' DESC';
    if ($sort === 'oldest') {
        $orderBy = 'a.' . mock_exam_q($orderTimeCol) . ' ASC';
    } elseif ($sort === 'best' && $a['success_rate']) {
        $orderBy = 'a.' . mock_exam_q($a['success_rate']) . ' DESC, a.' . mock_exam_q($orderTimeCol) . ' DESC';
    }

    $sqlCount = 'SELECT COUNT(*) FROM ' . mock_exam_q($a['table']) . ' a WHERE ' . implode(' AND ', $where);
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $sql = 'SELECT a.*, q.name AS qualification_name FROM ' . mock_exam_q($a['table']) . ' a '
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
        $status = (string)($attempt['status'] ?? '');
        if (in_array($status, ['completed', 'abandoned'], true)) {
            $agg = mock_exam_calculate_attempt_aggregates($pdo, (string)$attempt['id']);
            if ((int)($agg['total_questions'] ?? 0) > 0) {
                $attempt['correct_count'] = (int)($agg['correct_count'] ?? 0);
                $attempt['wrong_count'] = (int)($agg['wrong_count'] ?? 0);
                $attempt['blank_count'] = (int)($agg['blank_count'] ?? 0);
                $attempt['success_rate'] = (float)($agg['success_rate'] ?? 0.0);
            }
        }
        $items[] = mock_exam_history_item_from_attempt($attempt);
    }

    mock_exam_debug_log('history.response', [
        'user_id' => $userId,
        'attempt_ids' => array_values(array_map(static fn(array $i): string => (string)($i['id'] ?? ''), $items)),
        'total' => $total,
    ]);

    return [
        'items' => $items,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'has_more' => ($offset + count($items)) < $total,
    ];
}

function mock_exam_build_lesson_report(PDO $pdo, string $attemptId): array
{
    $a = mock_exam_get_attempt_schema($pdo);
    $stmt = $pdo->prepare('SELECT a.' . mock_exam_q($a['qualification_id']) . ' AS qualification_id, q.name AS qualification_name FROM ' . mock_exam_q($a['table']) . ' a LEFT JOIN qualifications q ON a.' . mock_exam_q($a['qualification_id']) . ' = q.id WHERE a.' . mock_exam_q($a['id']) . ' = ? LIMIT 1');
    $stmt->execute([$attemptId]);
    $attemptRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $questions = mock_exam_fetch_attempt_questions($pdo, $attemptId, true);
    $courseLookupIds = [];
    foreach ($questions as $q) {
        $cid = trim((string)($q['course_id'] ?? ''));
        $cname = trim((string)($q['course_name'] ?? ''));
        if ($cid !== '' && $cname === '') {
            $courseLookupIds[$cid] = true;
        }
    }

    $courseNameById = [];
    if (!empty($courseLookupIds)) {
        $ids = array_keys($courseLookupIds);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $cStmt = $pdo->prepare('SELECT id, name FROM courses WHERE id IN (' . $ph . ')');
        $cStmt->execute($ids);
        $cRows = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($cRows as $cr) {
            $courseNameById[(string)$cr['id']] = (string)($cr['name'] ?? '');
        }
    }

    $agg = [];
    foreach ($questions as $q) {
        $cid = (string)($q['course_id'] ?? '__none__');
        $resolvedCourseName = trim((string)($q['course_name'] ?? ''));
        if ($resolvedCourseName === '') {
            $resolvedCourseName = (string)($courseNameById[(string)($q['course_id'] ?? '')] ?? '');
        }
        if (!isset($agg[$cid])) {
            $agg[$cid] = [
                'qualification_id' => $attemptRow['qualification_id'] ?? null,
                'qualification_name' => $attemptRow['qualification_name'] ?? null,
                'course_id' => $q['course_id'] ?? null,
                'course_name' => ($resolvedCourseName !== '' ? $resolvedCourseName : null),
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
    $orderCol = $a['submitted_at'] ?: ($a['created_at'] ?: $a['id']);
    $sql = 'SELECT a.' . mock_exam_q($a['id']) . ' AS id, a.' . mock_exam_q($a['status']) . ' AS status '
        . 'FROM ' . mock_exam_q($a['table']) . ' a '
        . 'WHERE a.' . mock_exam_q($a['user_id']) . ' = ? '
        . 'ORDER BY a.' . mock_exam_q($orderCol) . ' DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalAttempts = count($rows);
    $completedAttempts = 0;
    $abandonedAttempts = 0;
    $completedRates = [];

    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        if ($status === 'completed') {
            $completedAttempts++;
            $agg = mock_exam_calculate_attempt_aggregates($pdo, (string)$row['id']);
            $completedRates[] = (float)($agg['success_rate'] ?? 0.0);
        } elseif ($status === 'abandoned') {
            $abandonedAttempts++;
        }
    }

    $avgRate = 0.0;
    $bestRate = 0.0;
    $lastRate = 0.0;
    if (!empty($completedRates)) {
        $avgRate = round(array_sum($completedRates) / count($completedRates), 2);
        $bestRate = round(max($completedRates), 2);
        $lastRate = round((float)$completedRates[0], 2);
    }

    return [
        'total_attempts' => $totalAttempts,
        'completed_attempts' => $completedAttempts,
        'abandoned_attempts' => $abandonedAttempts,
        'average_success_rate' => $avgRate,
        'best_success_rate' => $bestRate,
        'last_success_rate' => $lastRate,
    ];
}

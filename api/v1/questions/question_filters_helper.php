<?php

function questions_normalize_pool_type(string $rawPoolType): ?string
{
    $value = strtolower(trim($rawPoolType));
    if ($value === '') {
        return 'all';
    }

    $map = [
        'all' => 'all',
        'all_questions' => 'all',
        'all-questions' => 'all',
        'tum_sorular' => 'all',
        'tum-sorular' => 'all',
        'unanswered' => 'unanswered',
        'answered' => 'answered',
        'most_wrong' => 'most_wrong',
        'bookmarked' => 'bookmarked',
    ];

    return $map[$value] ?? null;
}

function questions_normalize_source_type(string $rawSourceType): ?string
{
    $value = questions_normalize_question_type_token($rawSourceType);
    if ($value === '') {
        return '';
    }

    if (in_array($value, ['all', 'tumu', 'tum', 'hepsi'], true)) {
        return '';
    }

    if ($value === 'gasm') {
        return 'gasm';
    }

    if (in_array($value, ['scenario', 'senaryo'], true)) {
        return 'scenario';
    }

    return null;
}

function questions_normalize_question_type_token(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === '') {
        return '';
    }

    return strtr($v, [
        'ç' => 'c',
        'ğ' => 'g',
        'ı' => 'i',
        'ö' => 'o',
        'ş' => 's',
        'ü' => 'u',
    ]);
}

function questions_resolve_question_type_candidates(string $rawQuestionType): array
{
    $trimmed = trim($rawQuestionType);
    if ($trimmed === '') {
        return [];
    }

    $normalized = questions_normalize_question_type_token($trimmed);
    if (in_array($normalized, ['tumu', 'tumuu', 'tum', 'all', 'hepsi'], true)) {
        return [];
    }

    if ($normalized === 'sayisal') {
        return ['numeric', 'numerical', 'sayisal', 'sayısal'];
    }

    if ($normalized === 'sozel') {
        return ['verbal', 'sozel', 'sözel'];
    }

    return [$trimmed, strtolower($trimmed)];
}

function questions_has_scope_links_table(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cols = get_table_columns($pdo, 'question_scope_links');
    $required = ['question_id', 'qualification_id', 'course_id', 'topic_id'];
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

/**
 * @param array<int,string> $rawTopicIds
 * @return array<int,string>
 */
function questions_normalize_topic_ids(array $rawTopicIds): array
{
    $normalized = [];
    foreach ($rawTopicIds as $rawId) {
        $id = trim((string)$rawId);
        if ($id === '') {
            continue;
        }

        $id = api_validate_optional_id($id, 'topic_ids', 191);
        if ($id === '') {
            continue;
        }

        if (!in_array($id, $normalized, true)) {
            $normalized[] = $id;
        }
    }

    if (count($normalized) > 50) {
        api_error('topic_ids en fazla 50 öğe içerebilir.', 422);
    }

    return $normalized;
}

/**
 * @return array{where: array<int,string>, params: array<int,mixed>, normalized_question_type: string, normalized_source_type: string, requested_qualification_id: string}
 */
function build_question_filters(PDO $pdo, array $params): array
{
    $auth = $params['auth'] ?? null;
    $currentQualificationId = trim((string)($params['current_qualification_id'] ?? ''));
    $questionColumns = $params['question_columns'] ?? [];
    $qualificationId = trim((string)($params['qualification_id'] ?? ''));
    $courseId = trim((string)($params['course_id'] ?? ''));
    $topicId = trim((string)($params['topic_id'] ?? ''));
    $topicIds = questions_normalize_topic_ids((array)($params['topic_ids'] ?? []));
    $questionType = trim((string)($params['question_type'] ?? ''));
    $sourceType = (string)($params['source_type'] ?? '');
    $questionAlias = trim((string)($params['question_alias'] ?? 'q'));
    $courseGuardContext = (string)($params['course_guard_context'] ?? 'questions.filter.course_guard');
    $topicGuardContext = (string)($params['topic_guard_context'] ?? 'questions.filter.topic_guard');
    $qualificationGuardContext = (string)($params['qualification_guard_context'] ?? 'questions.filter.qualification_guard');

    if (!is_array($auth) || $currentQualificationId === '' || !is_array($questionColumns) || $questionAlias === '') {
        api_error('Soru filtreleri oluşturulamadı.', 500);
    }

    $hasQ = static fn(string $col): bool => in_array($col, $questionColumns, true);
    $qc = static fn(string $col): string => $questionAlias . '.`' . str_replace('`', '', $col) . '`';

    if ($questionType !== '' && mb_strlen($questionType) > 50) {
        api_error('Geçersiz question_type.', 422);
    }

    $normalizedSourceType = questions_normalize_source_type($sourceType);
    if ($normalizedSourceType === null) {
        api_error('Geçersiz source_type.', 422);
    }

    if ($qualificationId !== '') {
        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            $qualificationId,
            $qualificationGuardContext
        );
    }

    if ($courseId !== '') {
        $courseGuardStmt = $pdo->prepare('SELECT qualification_id FROM courses WHERE id = ? LIMIT 1');
        $courseGuardStmt->execute([$courseId]);
        $courseGuardRow = $courseGuardStmt->fetch(PDO::FETCH_ASSOC);
        if (!$courseGuardRow) {
            api_error('Kurs bulunamadı.', 404);
        }

        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            (string)($courseGuardRow['qualification_id'] ?? ''),
            $courseGuardContext
        );
    }

    if ($topicIds) {
        $topicPlaceholders = implode(', ', array_fill(0, count($topicIds), '?'));
        $topicGuardStmt = $pdo->prepare(
            'SELECT t.id, c.qualification_id
             FROM topics t
             INNER JOIN courses c ON t.course_id = c.id
             WHERE t.id IN (' . $topicPlaceholders . ')'
        );
        $topicGuardStmt->execute($topicIds);
        $topicGuardRows = $topicGuardStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $foundTopicIds = [];
        foreach ($topicGuardRows as $topicGuardRow) {
            $guardTopicId = (string)($topicGuardRow['id'] ?? '');
            if ($guardTopicId === '') {
                continue;
            }
            $foundTopicIds[] = $guardTopicId;
            api_assert_requested_qualification_matches_current(
                $pdo,
                $auth,
                (string)($topicGuardRow['qualification_id'] ?? ''),
                $topicGuardContext
            );
        }

        $missingTopicIds = array_values(array_diff($topicIds, $foundTopicIds));
        if ($missingTopicIds) {
            api_error('Konu bulunamadı.', 404);
        }
    } elseif ($topicId !== '') {
        $topicGuardStmt = $pdo->prepare(
            'SELECT c.qualification_id
             FROM topics t
             INNER JOIN courses c ON t.course_id = c.id
             WHERE t.id = ?
             LIMIT 1'
        );
        $topicGuardStmt->execute([$topicId]);
        $topicGuardRow = $topicGuardStmt->fetch(PDO::FETCH_ASSOC);
        if (!$topicGuardRow) {
            api_error('Konu bulunamadı.', 404);
        }

        api_assert_requested_qualification_matches_current(
            $pdo,
            $auth,
            (string)($topicGuardRow['qualification_id'] ?? ''),
            $topicGuardContext
        );
    }

    $where = [];
    $queryParams = [];
    $effectiveQualificationId = $qualificationId !== '' ? $qualificationId : $currentQualificationId;
    $scopeLinksAvailable = questions_has_scope_links_table($pdo);

    if ($scopeLinksAvailable && $hasQ('course_id')) {
        $scopeClauses = ['qsl.question_id = ' . $qc('id'), 'qsl.qualification_id = ?'];
        $scopeParams = [$effectiveQualificationId];
        if ($courseId !== '') {
            $scopeClauses[] = 'qsl.course_id = ?';
            $scopeParams[] = $courseId;
        }
        if ($topicIds) {
            $topicPlaceholders = implode(', ', array_fill(0, count($topicIds), '?'));
            $scopeClauses[] = 'qsl.topic_id IN (' . $topicPlaceholders . ')';
            array_push($scopeParams, ...$topicIds);
        } elseif ($topicId !== '') {
            $scopeClauses[] = 'qsl.topic_id = ?';
            $scopeParams[] = $topicId;
        }

        $fallbackClauses = [
            $qc('course_id') . ' IN (SELECT id FROM courses WHERE qualification_id = ?)',
        ];
        $fallbackParams = [$effectiveQualificationId];
        if ($courseId !== '') {
            $fallbackClauses[] = $qc('course_id') . ' = ?';
            $fallbackParams[] = $courseId;
        }
        if ($topicIds && $hasQ('topic_id')) {
            $topicPlaceholders = implode(', ', array_fill(0, count($topicIds), '?'));
            $fallbackClauses[] = $qc('topic_id') . ' IN (' . $topicPlaceholders . ')';
            array_push($fallbackParams, ...$topicIds);
        } elseif ($topicId !== '' && $hasQ('topic_id')) {
            $fallbackClauses[] = $qc('topic_id') . ' = ?';
            $fallbackParams[] = $topicId;
        }

        $where[] = '((EXISTS (SELECT 1 FROM question_scope_links qsl WHERE ' . implode(' AND ', $scopeClauses) . ')) OR (' . implode(' AND ', $fallbackClauses) . '))';
        $queryParams = array_merge($queryParams, $scopeParams, $fallbackParams);
    } else {
        if ($hasQ('qualification_id')) {
            $where[] = $qc('qualification_id') . ' = ?';
            $queryParams[] = $effectiveQualificationId;
        } elseif ($hasQ('course_id')) {
            $where[] = $qc('course_id') . ' IN (SELECT id FROM courses WHERE qualification_id = ?)';
            $queryParams[] = $effectiveQualificationId;
        } else {
            api_error('Qualification guard için gerekli kolonlar bulunamadı.', 500);
        }

        if ($courseId !== '' && $hasQ('course_id')) {
            $where[] = $qc('course_id') . ' = ?';
            $queryParams[] = $courseId;
        }

        if ($topicIds && $hasQ('topic_id')) {
            $topicPlaceholders = implode(', ', array_fill(0, count($topicIds), '?'));
            $where[] = $qc('topic_id') . ' IN (' . $topicPlaceholders . ')';
            array_push($queryParams, ...$topicIds);
        } elseif ($topicId !== '' && $hasQ('topic_id')) {
            $where[] = $qc('topic_id') . ' = ?';
            $queryParams[] = $topicId;
        }
    }

    if ($questionType !== '' && $hasQ('question_type')) {
        $questionTypeCandidates = questions_resolve_question_type_candidates($questionType);
        if ($questionTypeCandidates) {
            $normalizedCandidates = [];
            foreach ($questionTypeCandidates as $candidate) {
                $candidate = strtolower(trim((string)$candidate));
                if ($candidate === '') {
                    continue;
                }
                if (!in_array($candidate, $normalizedCandidates, true)) {
                    $normalizedCandidates[] = $candidate;
                }
            }

            if ($normalizedCandidates) {
                if (count($normalizedCandidates) === 1) {
                    $where[] = 'LOWER(TRIM(' . $qc('question_type') . ')) = ?';
                    $queryParams[] = $normalizedCandidates[0];
                } else {
                    $placeholders = implode(', ', array_fill(0, count($normalizedCandidates), '?'));
                    $where[] = 'LOWER(TRIM(' . $qc('question_type') . ')) IN (' . $placeholders . ')';
                    array_push($queryParams, ...$normalizedCandidates);
                }
            }
        }
    }

    if ($normalizedSourceType !== '') {
        if (!$hasQ('source_type')) {
            api_error('Soru tipi filtresi şu anda kullanılamıyor.', 500);
        }

        $where[] = 'LOWER(TRIM(' . $qc('source_type') . ')) = ?';
        $queryParams[] = $normalizedSourceType;
    }

    return [
        'where' => $where,
        'params' => $queryParams,
        'normalized_question_type' => questions_normalize_question_type_token($questionType),
        'normalized_source_type' => $normalizedSourceType,
        'requested_qualification_id' => $effectiveQualificationId,
        'scope_links_available' => $scopeLinksAvailable,
    ];
}

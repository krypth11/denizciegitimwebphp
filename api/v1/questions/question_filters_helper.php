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

/**
 * @return array{where: array<int,string>, params: array<int,mixed>, normalized_question_type: string, requested_qualification_id: string}
 */
function build_question_filters(PDO $pdo, array $params): array
{
    $auth = $params['auth'] ?? null;
    $currentQualificationId = trim((string)($params['current_qualification_id'] ?? ''));
    $questionColumns = $params['question_columns'] ?? [];
    $qualificationId = trim((string)($params['qualification_id'] ?? ''));
    $courseId = trim((string)($params['course_id'] ?? ''));
    $topicId = trim((string)($params['topic_id'] ?? ''));
    $questionType = trim((string)($params['question_type'] ?? ''));
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

    if ($topicId !== '') {
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

    if ($topicId !== '' && $hasQ('topic_id')) {
        $where[] = $qc('topic_id') . ' = ?';
        $queryParams[] = $topicId;
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

    return [
        'where' => $where,
        'params' => $queryParams,
        'normalized_question_type' => questions_normalize_question_type_token($questionType),
        'requested_qualification_id' => $effectiveQualificationId,
    ];
}

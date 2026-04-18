<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/tools/pusula_ai_tools_helper.php';
require_once dirname(__DIR__, 2) . '/mock_exam_helper.php';
require_once dirname(__DIR__, 4) . '/includes/pusula_ai_knowledge_helper.php';

function pusula_ai_exam_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function pusula_ai_exam_pick(array $columns, array $candidates, bool $required = false): ?string
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

function pusula_ai_resolve_exam_mode(array $payload): string
{
    $mode = strtolower(trim((string)($payload['exam_mode'] ?? '')));
    $allowed = [
        'weak_topics',
        'last_exam_mistakes',
        'mixed_review',
        'motivation_warmup',
        'one_week_focus',
    ];

    return in_array($mode, $allowed, true) ? $mode : 'mixed_review';
}

function pusula_ai_resolve_exam_question_count(array $payload, array $knowledgeBase): int
{
    $rawPayloadCount = $payload['question_count'] ?? null;
    $payloadCount = (is_numeric($rawPayloadCount) ? (int)$rawPayloadCount : 0);
    if ($payloadCount > 0) {
        return max(1, min(100, $payloadCount));
    }

    $kbCount = (int)($knowledgeBase['action_exam_default_question_count'] ?? 20);
    return max(1, min(100, $kbCount));
}

function pusula_ai_validate_exam_payload(array $payload): array
{
    $type = strtolower(trim((string)($payload['type'] ?? '')));
    if ($type === '' || $type !== 'recommended_exam') {
        return [
            'valid' => false,
            'error_code' => 'unsupported_action_type',
            'message' => 'Bu aksiyon tipi şu an desteklenmiyor.',
        ];
    }

    $rawMode = trim((string)($payload['exam_mode'] ?? ''));
    if ($rawMode !== '') {
        $resolvedMode = pusula_ai_resolve_exam_mode($payload);
        if (strtolower($rawMode) !== $resolvedMode) {
            return [
                'valid' => false,
                'error_code' => 'invalid_payload',
                'message' => 'exam_mode alanı geçersiz.',
            ];
        }
    }

    if (array_key_exists('question_count', $payload) && $payload['question_count'] !== null && $payload['question_count'] !== '' && !is_numeric($payload['question_count'])) {
        return [
            'valid' => false,
            'error_code' => 'invalid_payload',
            'message' => 'question_count alanı geçersiz.',
        ];
    }

    return [
        'valid' => true,
        'payload' => [
            'type' => 'recommended_exam',
            'title' => trim((string)($payload['title'] ?? '')),
            'exam_mode' => pusula_ai_resolve_exam_mode($payload),
            'question_count' => (int)($payload['question_count'] ?? 0),
            'reason' => trim((string)($payload['reason'] ?? '')),
        ],
    ];
}

function pusula_ai_exam_get_last_attempt_wrong_course_ids(PDO $pdo, string $userId): array
{
    try {
        $lastExam = pusula_ai_tool_get_last_exam_summary($pdo, $userId);
        $attemptId = trim((string)($lastExam['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return [];
        }

        $aqCols = get_table_columns($pdo, 'mock_exam_attempt_questions');
        if (!$aqCols) {
            return [];
        }

        $attemptCol = pusula_ai_exam_pick($aqCols, ['attempt_id'], true);
        $courseCol = pusula_ai_exam_pick($aqCols, ['course_id'], false);
        $selectedCol = pusula_ai_exam_pick($aqCols, ['selected_answer'], false);
        $correctCol = pusula_ai_exam_pick($aqCols, ['correct_answer'], false);
        if (!$courseCol || !$selectedCol || !$correctCol) {
            return [];
        }

        $sql = 'SELECT DISTINCT ' . pusula_ai_exam_q($courseCol) . ' AS course_id '
            . 'FROM `mock_exam_attempt_questions` '
            . 'WHERE ' . pusula_ai_exam_q($attemptCol) . ' = :attempt_id '
            . 'AND ' . pusula_ai_exam_q($courseCol) . ' IS NOT NULL '
            . "AND TRIM(COALESCE(" . pusula_ai_exam_q($selectedCol) . ", '')) <> '' "
            . 'AND UPPER(TRIM(COALESCE(' . pusula_ai_exam_q($selectedCol) . ", ''))) <> UPPER(TRIM(COALESCE(" . pusula_ai_exam_q($correctCol) . ", ''))) "
            . 'LIMIT 6';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':attempt_id' => $attemptId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['course_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    } catch (Throwable $e) {
        return [];
    }
}

function pusula_ai_build_exam_blueprint(PDO $pdo, string $userId, array $payload): array
{
    $qualificationId = get_current_user_qualification_id($pdo, $userId);
    if (!is_string($qualificationId) || trim($qualificationId) === '') {
        return [];
    }

    $knowledgeBase = function_exists('pusula_ai_get_knowledge')
        ? pusula_ai_get_knowledge($pdo)
        : pusula_ai_knowledge_defaults();

    $poolMode = pusula_ai_resolve_exam_mode($payload);
    $questionCount = pusula_ai_resolve_exam_question_count($payload, is_array($knowledgeBase) ? $knowledgeBase : []);

    $weakTopics = pusula_ai_tool_get_weak_topics($pdo, $userId, 6);
    $lastExam = pusula_ai_tool_get_last_exam_summary($pdo, $userId);
    $targetTopicIds = [];
    foreach ($weakTopics as $topic) {
        $topicId = trim((string)($topic['topic_id'] ?? ''));
        if ($topicId !== '') {
            $targetTopicIds[$topicId] = true;
        }
    }
    $targetTopicIds = array_values(array_keys($targetTopicIds));

    $targetCourseIds = [];
    if ($poolMode === 'last_exam_mistakes') {
        $targetCourseIds = pusula_ai_exam_get_last_attempt_wrong_course_ids($pdo, $userId);
    }

    $hasLastExam = is_array($lastExam) && ((int)($lastExam['question_count'] ?? 0) > 0 || (int)($lastExam['wrong_count'] ?? 0) > 0);

    if ($poolMode === 'last_exam_mistakes' && !$hasLastExam) {
        if (!empty($targetTopicIds)) {
            $poolMode = 'weak_topics';
        } else {
            $poolMode = 'mixed_review';
        }
    }

    if ($poolMode === 'weak_topics' && empty($targetTopicIds)) {
        $poolMode = 'mixed_review';
    }

    $difficulty = 'mixed';
    if ($poolMode === 'motivation_warmup') {
        $difficulty = 'easy';
        $questionCount = max(1, min(20, $questionCount));
    } elseif ($poolMode === 'one_week_focus') {
        $difficulty = 'focused';
    }

    return [
        'qualification_id' => (string)$qualificationId,
        'question_count' => $questionCount,
        'pool_mode' => $poolMode,
        'target_topic_ids' => $targetTopicIds,
        'target_course_ids' => $targetCourseIds,
        'difficulty' => $difficulty,
        'source_reason' => trim((string)($payload['reason'] ?? '')),
    ];
}

function pusula_ai_exam_mode_title(string $mode): string
{
    $titles = [
        'weak_topics' => 'Zayıf Alanlara Odaklı Deneme',
        'last_exam_mistakes' => 'Son Deneme Hatalarına Odaklı Deneme',
        'mixed_review' => 'Karma Tekrar Denemesi',
        'motivation_warmup' => 'Isınma Denemesi',
        'one_week_focus' => '1 Haftalık Odağa Göre Deneme',
    ];

    return $titles[$mode] ?? 'Önerilen Deneme';
}

function pusula_ai_exam_map_blueprint_to_mock_payload(PDO $pdo, string $userId, array $blueprint): array
{
    $mode = (string)($blueprint['pool_mode'] ?? 'mixed_review');
    $questionCount = max(1, min(100, (int)($blueprint['question_count'] ?? 20)));

    $mockPayload = [
        'qualification_id' => (string)($blueprint['qualification_id'] ?? ''),
        'requested_question_count' => $questionCount,
        'pool_type' => 'random',
        'mode' => 'standard',
    ];

    switch ($mode) {
        case 'weak_topics':
            $mockPayload['pool_type'] = 'wrong';
            break;
        case 'last_exam_mistakes':
            $lastExam = pusula_ai_tool_get_last_exam_summary($pdo, $userId);
            $sourceAttemptId = trim((string)($lastExam['attempt_id'] ?? ''));
            if ($sourceAttemptId !== '') {
                $mockPayload['mode'] = 'wrong_only';
                $mockPayload['source_attempt_id'] = $sourceAttemptId;
            } else {
                $mockPayload['pool_type'] = 'wrong';
            }
            break;
        case 'one_week_focus':
            $mockPayload['pool_type'] = 'wrong';
            break;
        case 'motivation_warmup':
            $mockPayload['requested_question_count'] = max(1, min(20, $questionCount));
            $mockPayload['pool_type'] = 'random';
            break;
        case 'mixed_review':
        default:
            $mockPayload['pool_type'] = 'random';
            break;
    }

    return $mockPayload;
}

function pusula_ai_start_recommended_exam(PDO $pdo, string $userId, array $payload): array
{
    $validation = pusula_ai_validate_exam_payload($payload);
    if (empty($validation['valid'])) {
        return [
            'success' => false,
            'error_code' => (string)($validation['error_code'] ?? 'invalid_payload'),
            'message' => (string)($validation['message'] ?? 'İstek verisi geçersiz.'),
        ];
    }

    $normalizedPayload = is_array($validation['payload'] ?? null) ? $validation['payload'] : [];
    $blueprint = pusula_ai_build_exam_blueprint($pdo, $userId, $normalizedPayload);
    if (!$blueprint) {
        return [
            'success' => false,
            'error_code' => 'cannot_build_exam',
            'message' => 'Uygun deneme planı oluşturulamadı.',
        ];
    }

    $mockPayload = pusula_ai_exam_map_blueprint_to_mock_payload($pdo, $userId, $blueprint);

    try {
        $fallbackMessage = null;
        try {
            $created = mock_exam_create_attempt($pdo, $userId, $mockPayload);
        } catch (Throwable $firstStartError) {
            if ((string)($blueprint['pool_mode'] ?? 'mixed_review') !== 'mixed_review') {
                $fallbackBlueprint = $blueprint;
                $fallbackBlueprint['pool_mode'] = 'mixed_review';
                $fallbackBlueprint['source_reason'] = 'insufficient_mode_data_fallback';
                $fallbackPayload = pusula_ai_exam_map_blueprint_to_mock_payload($pdo, $userId, $fallbackBlueprint);
                $created = mock_exam_create_attempt($pdo, $userId, $fallbackPayload);
                $blueprint = $fallbackBlueprint;
                $fallbackMessage = 'Yeterli kişisel veri bulunamadığı için karma tekrar denemesi başlatıldı.';
            } else {
                throw $firstStartError;
            }
        }

        $attempt = is_array($created['attempt'] ?? null) ? $created['attempt'] : null;
        if (!$attempt || trim((string)($attempt['id'] ?? '')) === '') {
            return [
                'success' => false,
                'error_code' => 'cannot_start_exam',
                'message' => 'Deneme başlatılamadı.',
            ];
        }

        $effectiveMode = (string)($blueprint['pool_mode'] ?? 'mixed_review');
        return [
            'success' => true,
            'attempt_id' => (string)$attempt['id'],
            'exam_mode' => $effectiveMode,
            'question_count' => (int)($attempt['actual_question_count'] ?? $attempt['requested_question_count'] ?? $blueprint['question_count'] ?? 0),
            'title' => pusula_ai_exam_mode_title($effectiveMode),
            'started_at' => (string)($attempt['started_at'] ?? date('c')),
            'navigation_target' => 'exam_session',
            'attempt' => $attempt,
            'blueprint' => $blueprint,
            'message' => $fallbackMessage,
        ];
    } catch (Throwable $e) {
        error_log('[pusula_ai_exam][start] ' . $e->getMessage());
        return [
            'success' => false,
            'error_code' => 'cannot_start_exam',
            'message' => 'Deneme başlatılamadı.',
        ];
    }
}

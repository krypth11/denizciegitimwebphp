<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('POST');

/**
 * Offline sync request contract (official)
 *
 * POST body:
 * {
 *   "device_id": "optional-device-id",
 *   "events": [
 *     {
 *       "client_event_id": "uuid-or-stable-id",
 *       "type": "study.answer_upsert|study.bookmark_set|study.session_summary|mock_exam.completed|exam_attempt_completed",
 *       "payload": { ... },
 *       "created_at": "2026-01-01T10:00:00Z"
 *     }
 *   ]
 * }
 *
 * Backward compatibility:
 * - If event.type is empty, event.event_type is accepted.
 * - exam_attempt_completed alias'ı mock_exam.completed'e normalize edilir.
 *
 * Response contract (stable):
 * - processed_event_ids: string[]
 * - duplicate_event_ids: string[]
 * - failed_events: [{ client_event_id, type, message }]
 * - remote_attempt_map: { [local_attempt_id]: remote_attempt_id }
 */

function offline_sync_debug_log(string $stage, array $context = []): void
{
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $safeContext[$key] = $value;
        } elseif (is_bool($value) || is_null($value) || is_numeric($value)) {
            $safeContext[$key] = $value;
        } else {
            $safeContext[$key] = (string)$value;
        }
    }

    $line = '[offline_sync][' . $stage . '] ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log($line !== false ? $line : ('[offline_sync][' . $stage . ']'));
}

function offline_sync_normalize_source(?string $source): string
{
    $value = strtolower(trim((string)$source));
    $allowed = ['study', 'daily_quiz', 'exam', 'maritime_english', 'maritime-english', 'me', 'me_quiz', 'maritime_english_quiz', 'offline_sync'];
    if (!in_array($value, $allowed, true)) {
        return 'offline_sync';
    }
    return $value;
}

function offline_sync_normalize_event_type(string $type): string
{
    $v = strtolower(trim($type));
    $aliases = [
        'answer_upsert' => 'study.answer_upsert',
        'study_answer_upsert' => 'study.answer_upsert',
        'study.answer' => 'study.answer_upsert',
        'bookmark_set' => 'study.bookmark_set',
        'study_bookmark_set' => 'study.bookmark_set',
        'session_summary' => 'study.session_summary',
        'study_session_summary' => 'study.session_summary',
        'mock_exam_completed' => 'mock_exam.completed',
        'mock_exam_complete' => 'mock_exam.completed',
        'mock_exam.submit' => 'mock_exam.completed',
        'exam_attempt_completed' => 'mock_exam.completed',
    ];

    return $aliases[$v] ?? $v;
}

function offline_sync_is_study_type(string $type): bool
{
    return in_array($type, ['study.answer_upsert', 'study.bookmark_set', 'study.session_summary'], true);
}

function offline_sync_build_dashboard_statistics_snapshot(PDO $pdo, string $userId): array
{
    $sql = 'SELECT '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 1 THEN 1 ELSE 0 END), 0) AS total_correct, '
        . 'COALESCE(SUM(CASE WHEN `is_correct` = 0 THEN 1 ELSE 0 END), 0) AS total_wrong '
        . 'FROM `question_attempt_events` WHERE `user_id` = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalCorrect = (int)($row['total_correct'] ?? 0);
    $totalWrong = (int)($row['total_wrong'] ?? 0);
    $totalSolved = $totalCorrect + $totalWrong;

    $durationSeconds = 0;
    try {
        $ssCols = get_table_columns($pdo, 'study_sessions');
        if (!empty($ssCols) && in_array('user_id', $ssCols, true)) {
            $durationCol = in_array('duration_seconds', $ssCols, true) ? 'duration_seconds' : null;
            $sqlDuration = 'SELECT ' . ($durationCol ? ('COALESCE(SUM(`' . $durationCol . '`),0)') : '0') . ' AS total_duration '
                . 'FROM `study_sessions` WHERE `user_id` = ?';
            $stmtDuration = $pdo->prepare($sqlDuration);
            $stmtDuration->execute([$userId]);
            $durationSeconds = (int)$stmtDuration->fetchColumn();
        }
    } catch (Throwable $e) {
        $durationSeconds = 0;
    }

    return [
        'total_solved' => $totalSolved,
        'total_correct' => $totalCorrect,
        'total_wrong' => $totalWrong,
        'total_study_duration_seconds' => $durationSeconds,
        'is_remote_reflected' => true,
    ];
}

function offline_sync_mock_exam_reflection_status(PDO $pdo, string $userId, string $remoteAttemptId): array
{
    $summary = mock_exam_build_summary_stats($pdo, $userId);
    $history = mock_exam_fetch_history($pdo, $userId, [
        'status' => 'all',
        'sort' => 'newest',
        'page' => 1,
        'per_page' => 200,
    ]);

    $inHistory = false;
    foreach (($history['items'] ?? []) as $row) {
        if ((string)($row['id'] ?? '') === $remoteAttemptId) {
            $inHistory = true;
            break;
        }
    }

    return [
        'in_summary' => ((int)($summary['total_attempts'] ?? 0) >= 1),
        'in_history' => $inHistory,
        'summary_total_attempts' => (int)($summary['total_attempts'] ?? 0),
    ];
}

function offline_sync_extract_event_type(array $event): array
{
    $rawType = trim((string)($event['type'] ?? ''));
    $rawEventType = trim((string)($event['event_type'] ?? ''));
    $usedFallback = false;

    if ($rawType === '' && $rawEventType !== '') {
        $rawType = $rawEventType;
        $usedFallback = true;
    }

    return [
        'type_raw' => $rawType,
        'normalized_type' => offline_sync_normalize_event_type($rawType),
        'used_event_type_fallback' => $usedFallback,
    ];
}

function offline_sync_decode_json_payload($value): ?array
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

function offline_sync_normalize_optional_answer($value): ?string
{
    $v = strtoupper(trim((string)$value));
    if ($v === '') {
        return null;
    }
    if (!in_array($v, ['A', 'B', 'C', 'D', 'E'], true)) {
        return null;
    }
    return $v;
}

function offline_sync_normalize_datetime_utc(?string $raw): ?string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', $ts);
}

function offline_sync_extract_questions_from_payload(array $payload): array
{
    $questionsJson = $payload['questions_json'] ?? null;
    $questions = is_array($questionsJson) ? $questionsJson : offline_sync_decode_json_payload($questionsJson);
    if (is_array($questions) && $questions) {
        return $questions;
    }

    $detailJson = $payload['detail_json'] ?? null;
    $detail = is_array($detailJson) ? $detailJson : offline_sync_decode_json_payload($detailJson);
    if (is_array($detail) && !empty($detail['questions']) && is_array($detail['questions'])) {
        return $detail['questions'];
    }

    if (!empty($payload['questions']) && is_array($payload['questions'])) {
        return $payload['questions'];
    }

    return [];
}

function offline_sync_build_lesson_report_from_questions(array $questions, string $qualificationId): array
{
    $agg = [];
    foreach ($questions as $q) {
        if (!is_array($q)) {
            continue;
        }

        $courseId = (string)($q['course_id'] ?? '__none__');
        $courseName = trim((string)($q['course_name'] ?? ''));
        if (!isset($agg[$courseId])) {
            $agg[$courseId] = [
                'qualification_id' => $qualificationId,
                'qualification_name' => null,
                'course_id' => ($courseId === '__none__' ? null : $courseId),
                'course_name' => ($courseName !== '' ? $courseName : null),
                'total_questions' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'blank_count' => 0,
                'success_rate' => 0.0,
            ];
        }

        $selected = offline_sync_normalize_optional_answer($q['selected_answer'] ?? null);
        $correct = offline_sync_normalize_optional_answer($q['correct_answer'] ?? null);

        $agg[$courseId]['total_questions']++;
        if ($selected === null) {
            $agg[$courseId]['blank_count']++;
        } elseif ($correct !== null && $selected === $correct) {
            $agg[$courseId]['correct_count']++;
        } else {
            $agg[$courseId]['wrong_count']++;
        }
    }

    foreach ($agg as $k => $item) {
        $total = (int)$item['total_questions'];
        $agg[$k]['success_rate'] = $total > 0
            ? round(((int)$item['correct_count'] / $total) * 100, 2)
            : 0.0;
    }

    return array_values($agg);
}

function offline_sync_receipt_extract_remote_attempt_id(?array $receipt): ?string
{
    if (!$receipt) {
        return null;
    }
    $response = offline_sync_decode_json_payload($receipt['response_json'] ?? null);
    if (!$response) {
        return null;
    }
    $id = trim((string)($response['remote_attempt_id'] ?? ''));
    return $id !== '' ? $id : null;
}

function offline_sync_mock_attempt_schema(PDO $pdo): array
{
    $base = mock_exam_get_attempt_schema($pdo);
    $cols = get_table_columns($pdo, $base['table']);

    $pick = static function (array $candidates) use ($cols): ?string {
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) {
                return $c;
            }
        }
        return null;
    };

    $base['source'] = $pick(['source']);
    $base['summary_json'] = $pick(['summary_json']);
    $base['detail_json'] = $pick(['detail_json']);
    $base['lesson_report_json'] = $pick(['lesson_report_json']);
    $base['questions_json'] = $pick(['questions_json']);
    $base['fingerprint'] = $pick(['fingerprint', 'sync_fingerprint', 'offline_fingerprint', 'payload_fingerprint']);

    return $base;
}

function offline_sync_mock_exam_fingerprint(array $payload): string
{
    $data = [
        'user_scope' => 'offline_sync_mock_exam',
        'qualification_id' => (string)($payload['qualification_id'] ?? ''),
        'started_at' => (string)($payload['started_at'] ?? ''),
        'completed_at' => (string)($payload['completed_at'] ?? ''),
        'duration_seconds' => (int)($payload['duration_seconds'] ?? 0),
        'total_questions' => (int)($payload['total_questions'] ?? 0),
        'answered_questions' => (int)($payload['answered_questions'] ?? 0),
        'correct_count' => (int)($payload['correct_count'] ?? 0),
        'wrong_count' => (int)($payload['wrong_count'] ?? 0),
        'blank_count' => (int)($payload['blank_count'] ?? 0),
        'success_rate' => (float)($payload['success_rate'] ?? 0),
        'local_attempt_id' => (string)($payload['local_attempt_id'] ?? ''),
        'questions_json' => $payload['questions_json'] ?? null,
    ];

    return sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function offline_sync_mock_exam_find_duplicate(PDO $pdo, string $userId, array $schema, string $sourceAttemptId, string $fingerprint, array $payload): ?string
{
    if ($schema['source_attempt_id'] && $sourceAttemptId !== '') {
        $sql = 'SELECT ' . mock_exam_q($schema['id']) . ' AS id FROM ' . mock_exam_q($schema['table'])
            . ' WHERE ' . mock_exam_q($schema['user_id']) . ' = ? AND ' . mock_exam_q($schema['source_attempt_id']) . ' = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $sourceAttemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) {
            return (string)$row['id'];
        }
    }

    if ($schema['fingerprint']) {
        $sql = 'SELECT ' . mock_exam_q($schema['id']) . ' AS id FROM ' . mock_exam_q($schema['table'])
            . ' WHERE ' . mock_exam_q($schema['user_id']) . ' = ? AND ' . mock_exam_q($schema['fingerprint']) . ' = ? LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['id'])) {
            return (string)$row['id'];
        }
    }

    $qualificationId = trim((string)($payload['qualification_id'] ?? ''));
    $startedAt = offline_sync_normalize_datetime_utc((string)($payload['started_at'] ?? ''));
    $completedAt = offline_sync_normalize_datetime_utc((string)($payload['completed_at'] ?? ''));

    $where = [mock_exam_q($schema['user_id']) . ' = ?'];
    $params = [$userId];

    if ($qualificationId !== '') {
        $where[] = mock_exam_q($schema['qualification_id']) . ' = ?';
        $params[] = $qualificationId;
    }
    if ($startedAt !== null && $schema['started_at']) {
        $where[] = mock_exam_q($schema['started_at']) . ' = ?';
        $params[] = $startedAt;
    }
    if ($completedAt !== null && $schema['submitted_at']) {
        $where[] = mock_exam_q($schema['submitted_at']) . ' = ?';
        $params[] = $completedAt;
    }
    if ($schema['actual_question_count']) {
        $where[] = mock_exam_q($schema['actual_question_count']) . ' = ?';
        $params[] = (int)($payload['total_questions'] ?? 0);
    }
    if ($schema['correct_count']) {
        $where[] = mock_exam_q($schema['correct_count']) . ' = ?';
        $params[] = (int)($payload['correct_count'] ?? 0);
    }
    if ($schema['wrong_count']) {
        $where[] = mock_exam_q($schema['wrong_count']) . ' = ?';
        $params[] = (int)($payload['wrong_count'] ?? 0);
    }
    if ($schema['blank_count']) {
        $where[] = mock_exam_q($schema['blank_count']) . ' = ?';
        $params[] = (int)($payload['blank_count'] ?? 0);
    }

    $sql = 'SELECT ' . mock_exam_q($schema['id']) . ' AS id FROM ' . mock_exam_q($schema['table'])
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . mock_exam_q($schema['id']) . ' DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && !empty($row['id'])) ? (string)$row['id'] : null;
}

function offline_sync_handle_study_answer_upsert(PDO $pdo, string $userId, array $payload): array
{
    $questionId = trim((string)($payload['question_id'] ?? ''));
    $selectedAnswer = offline_sync_normalize_optional_answer($payload['selected_answer'] ?? null);
    $source = offline_sync_normalize_source($payload['source'] ?? 'study');
    $sessionId = trim((string)($payload['session_id'] ?? ''));
    $sessionId = $sessionId !== '' ? $sessionId : null;

    if ($questionId === '') {
        throw new RuntimeException('study.answer_upsert.question_id zorunludur.');
    }

    if ($selectedAnswer === null) {
        return [
            'type' => 'study.answer_upsert',
            'question_id' => $questionId,
            'skipped' => true,
            'reason' => 'blank_answer_not_counted_as_solved',
        ];
    }

    $meta = study_get_question_meta_with_relations($pdo, $questionId);
    if (!$meta['exists']) {
        throw new RuntimeException('Soru bulunamadı.');
    }
    if ($selectedAnswer === 'E' && empty($meta['option_e'])) {
        throw new RuntimeException('Bu soru için E şıkkı bulunmuyor.');
    }

    $isCorrect = false;
    if (!empty($meta['correct_answer'])) {
        $isCorrect = ($selectedAnswer === strtoupper((string)$meta['correct_answer']));
    }

    $progress = study_upsert_answer_progress($pdo, $userId, $questionId, $selectedAnswer, $isCorrect);
    study_insert_attempt_event($pdo, [
        'user_id' => $userId,
        'question_id' => $questionId,
        'course_id' => $meta['course_id'] ?? null,
        'qualification_id' => $meta['qualification_id'] ?? null,
        'topic_id' => $meta['topic_id'] ?? null,
        'session_id' => $sessionId,
        'source' => $source,
        'selected_answer' => $selectedAnswer,
        'is_correct' => $isCorrect,
    ]);

    return [
        'type' => 'study.answer_upsert',
        'question_id' => $questionId,
        'selected_answer' => $selectedAnswer,
        'is_correct' => $isCorrect,
        'progress' => $progress,
    ];
}

function offline_sync_handle_study_bookmark_set(PDO $pdo, string $userId, array $payload): array
{
    $questionId = trim((string)($payload['question_id'] ?? ''));
    if ($questionId === '') {
        throw new RuntimeException('study.bookmark_set.question_id zorunludur.');
    }
    $isBookmarked = filter_var($payload['is_bookmarked'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($isBookmarked === null) {
        throw new RuntimeException('study.bookmark_set.is_bookmarked boolean olmalıdır.');
    }

    $meta = study_get_question_meta($pdo, $questionId);
    if (!$meta['exists']) {
        throw new RuntimeException('Soru bulunamadı.');
    }

    $bookmark = study_set_bookmark_state($pdo, $userId, $questionId, (bool)$isBookmarked);
    return [
        'type' => 'study.bookmark_set',
        'bookmark' => $bookmark,
    ];
}

function offline_sync_handle_study_session_summary(PDO $pdo, string $userId, array $payload): array
{
    $correct = max(0, (int)($payload['correct_count'] ?? 0));
    $wrong = max(0, (int)($payload['wrong_count'] ?? 0));
    $blank = max(0, (int)($payload['blank_count'] ?? 0));
    $solved = $correct + $wrong; // blank solved'a dahil edilmez

    $sessionPayload = [
        'course_id' => $payload['course_id'] ?? null,
        'qualification_id' => $payload['qualification_id'] ?? null,
        'question_type' => $payload['question_type'] ?? null,
        'pool_type' => $payload['pool_type'] ?? null,
        'requested_question_count' => (int)($payload['requested_question_count'] ?? ($solved + $blank)),
        'served_question_count' => (int)($payload['served_question_count'] ?? ($solved + $blank)),
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'duration_seconds' => max(0, (int)($payload['duration_seconds'] ?? 0)),
    ];

    $session = study_insert_session($pdo, $userId, $sessionPayload);
    return [
        'type' => 'study.session_summary',
        'session' => $session,
        'solved_count' => $solved,
        'blank_count' => $blank,
    ];
}

function offline_sync_handle_mock_exam_completed(PDO $pdo, string $userId, array $payload): array
{
    $attemptSchema = offline_sync_mock_attempt_schema($pdo);
    $questionSchema = mock_exam_get_attempt_question_schema($pdo);

    $localAttemptId = trim((string)($payload['local_attempt_id'] ?? ''));
    $qualificationId = trim((string)($payload['qualification_id'] ?? ''));
    $startedAtRaw = trim((string)($payload['started_at'] ?? ''));
    $completedAtRaw = trim((string)($payload['completed_at'] ?? ''));
    $startedAt = offline_sync_normalize_datetime_utc($startedAtRaw);
    $completedAt = offline_sync_normalize_datetime_utc($completedAtRaw);
    $durationSeconds = max(0, (int)($payload['duration_seconds'] ?? 0));
    $totalQuestions = max(0, (int)($payload['total_questions'] ?? 0));
    $answeredQuestions = max(0, (int)($payload['answered_questions'] ?? 0));
    $correctCount = max(0, (int)($payload['correct_count'] ?? 0));
    $wrongCount = max(0, (int)($payload['wrong_count'] ?? 0));
    $blankCount = max(0, (int)($payload['blank_count'] ?? 0));
    $successRate = (float)($payload['success_rate'] ?? 0);
    $source = trim((string)($payload['source'] ?? 'mock_exam'));

    if ($localAttemptId === '') {
        throw new RuntimeException('mock_exam.completed.local_attempt_id zorunludur.');
    }
    if ($qualificationId === '') {
        throw new RuntimeException('mock_exam.completed.qualification_id zorunludur.');
    }

    offline_sync_debug_log('mock_exam.completed.timestamps.incoming', [
        'local_attempt_id' => $localAttemptId,
        'started_at' => $startedAtRaw,
        'completed_at' => $completedAtRaw,
    ]);

    offline_sync_debug_log('mock_exam.completed.timestamps.normalized', [
        'local_attempt_id' => $localAttemptId,
        'started_at_utc' => $startedAt,
        'completed_at_utc' => $completedAt,
    ]);

    $summaryJson = $payload['summary_json'] ?? null;
    $detailJson = $payload['detail_json'] ?? null;
    $lessonReportJson = $payload['lesson_report_json'] ?? null;
    $questions = offline_sync_extract_questions_from_payload($payload);

    if (!$questions && $totalQuestions > 0) {
        throw new RuntimeException('mock_exam.completed.questions payload zorunludur.');
    }

    if (!is_array($summaryJson)) {
        $summaryJson = [
            'total_questions' => $totalQuestions,
            'answered_questions' => $answeredQuestions,
            'correct_count' => $correctCount,
            'wrong_count' => $wrongCount,
            'blank_count' => $blankCount,
            'success_rate' => $successRate,
            'duration_seconds' => $durationSeconds,
            'completed_at' => $completedAt,
        ];
    }

    if (!is_array($lessonReportJson) || empty($lessonReportJson)) {
        $lessonReportJson = offline_sync_build_lesson_report_from_questions($questions, $qualificationId);
    }

    if (!is_array($detailJson)) {
        $detailJson = [
            'summary' => $summaryJson,
            'questions' => $questions,
            'lesson_report' => $lessonReportJson,
        ];
    }

    $questionsJson = $questions;

    $fingerprint = offline_sync_mock_exam_fingerprint($payload);
    $duplicateId = offline_sync_mock_exam_find_duplicate($pdo, $userId, $attemptSchema, $localAttemptId, $fingerprint, $payload);
    if ($duplicateId) {
        offline_sync_debug_log('mock_exam.completed.duplicate', [
            'local_attempt_id' => $localAttemptId,
            'remote_attempt_id' => $duplicateId,
            'source_attempt_id' => $localAttemptId,
            'started_at_utc' => $startedAt,
            'completed_at_utc' => $completedAt,
        ]);

        return [
            'duplicate' => true,
            'remote_attempt_id' => $duplicateId,
            'local_attempt_id' => $localAttemptId,
            'type' => 'mock_exam.completed',
        ];
    }

    $attemptId = generate_uuid();
    $cols = [mock_exam_q($attemptSchema['id']), mock_exam_q($attemptSchema['user_id']), mock_exam_q($attemptSchema['qualification_id'])];
    $holders = ['?', '?', '?'];
    $params = [$attemptId, $userId, $qualificationId];

    $pairs = [
        'mode' => 'standard',
        'pool_type' => 'random',
        'requested_question_count' => $totalQuestions,
        'actual_question_count' => $totalQuestions,
        'duration_seconds_limit' => max($durationSeconds, 1),
        'elapsed_seconds' => $durationSeconds,
        'status' => 'completed',
        'warning_message' => null,
        'source_attempt_id' => $localAttemptId,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'blank_count' => $blankCount,
        'success_rate' => $successRate,
    ];

    foreach ($pairs as $key => $value) {
        if (!empty($attemptSchema[$key])) {
            $cols[] = mock_exam_q($attemptSchema[$key]);
            $holders[] = '?';
            $params[] = $value;
        }
    }

    if (!empty($attemptSchema['source'])) {
        $cols[] = mock_exam_q($attemptSchema['source']);
        $holders[] = '?';
        $params[] = ($source !== '' ? $source : 'mock_exam');
    }

    if (!empty($attemptSchema['summary_json'])) {
        $cols[] = mock_exam_q($attemptSchema['summary_json']);
        $holders[] = '?';
        $params[] = json_encode($summaryJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!empty($attemptSchema['detail_json'])) {
        $cols[] = mock_exam_q($attemptSchema['detail_json']);
        $holders[] = '?';
        $params[] = json_encode($detailJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!empty($attemptSchema['lesson_report_json'])) {
        $cols[] = mock_exam_q($attemptSchema['lesson_report_json']);
        $holders[] = '?';
        $params[] = json_encode($lessonReportJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!empty($attemptSchema['questions_json'])) {
        $cols[] = mock_exam_q($attemptSchema['questions_json']);
        $holders[] = '?';
        $params[] = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!empty($attemptSchema['fingerprint'])) {
        $cols[] = mock_exam_q($attemptSchema['fingerprint']);
        $holders[] = '?';
        $params[] = $fingerprint;
    }

    if (!empty($attemptSchema['started_at']) && $startedAt !== null) {
        $cols[] = mock_exam_q($attemptSchema['started_at']);
        $holders[] = '?';
        $params[] = $startedAt;
    }
    if (!empty($attemptSchema['submitted_at']) && $completedAt !== null) {
        $cols[] = mock_exam_q($attemptSchema['submitted_at']);
        $holders[] = '?';
        $params[] = $completedAt;
    }
    if (!empty($attemptSchema['created_at'])) {
        $cols[] = mock_exam_q($attemptSchema['created_at']);
        $holders[] = 'NOW()';
    }
    if (!empty($attemptSchema['updated_at'])) {
        $cols[] = mock_exam_q($attemptSchema['updated_at']);
        $holders[] = 'NOW()';
    }

    $sql = 'INSERT INTO ' . mock_exam_q($attemptSchema['table'])
        . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $eventQuestions = [];
    $order = 1;
    foreach ($questions as $questionRow) {
        if (!is_array($questionRow)) {
            continue;
        }

        $questionId = trim((string)($questionRow['question_id'] ?? $questionRow['id'] ?? ''));
        if ($questionId === '') {
            continue;
        }

        $selected = offline_sync_normalize_optional_answer($questionRow['selected_answer'] ?? null);
        $correct = offline_sync_normalize_optional_answer($questionRow['correct_answer'] ?? null);
        $isCorrect = ($selected !== null && $correct !== null && $selected === $correct) ? 1 : 0;

        $qCols = [mock_exam_q($questionSchema['id']), mock_exam_q($questionSchema['attempt_id']), mock_exam_q($questionSchema['question_id'])];
        $qVals = ['?', '?', '?'];
        $qParams = [generate_uuid(), $attemptId, $questionId];

        $qMap = [
            'course_id' => $questionRow['course_id'] ?? null,
            'course_name' => $questionRow['course_name'] ?? null,
            'order_index' => (int)($questionRow['order_index'] ?? $order),
            'question_type' => $questionRow['question_type'] ?? null,
            'question_text' => $questionRow['question_text'] ?? null,
            'option_a' => $questionRow['option_a'] ?? null,
            'option_b' => $questionRow['option_b'] ?? null,
            'option_c' => $questionRow['option_c'] ?? null,
            'option_d' => $questionRow['option_d'] ?? null,
            'option_e' => $questionRow['option_e'] ?? null,
            'correct_answer' => $correct,
            'explanation' => $questionRow['explanation'] ?? null,
            'selected_answer' => $selected,
            'is_correct' => $isCorrect,
            'is_flagged' => !empty($questionRow['is_flagged']) ? 1 : 0,
        ];

        foreach ($qMap as $key => $val) {
            if (!empty($questionSchema[$key])) {
                $qCols[] = mock_exam_q($questionSchema[$key]);
                $qVals[] = '?';
                $qParams[] = $val;
            }
        }

        if (!empty($questionSchema['answered_at'])) {
            if ($selected !== null) {
                $answeredAt = offline_sync_normalize_datetime_utc((string)($questionRow['answered_at'] ?? $completedAt));
                if ($answeredAt !== null) {
                    $qCols[] = mock_exam_q($questionSchema['answered_at']);
                    $qVals[] = '?';
                    $qParams[] = $answeredAt;
                }
            }
        }
        if (!empty($questionSchema['created_at'])) {
            $qCols[] = mock_exam_q($questionSchema['created_at']);
            $qVals[] = 'NOW()';
        }
        if (!empty($questionSchema['updated_at'])) {
            $qCols[] = mock_exam_q($questionSchema['updated_at']);
            $qVals[] = 'NOW()';
        }

        $qSql = 'INSERT INTO ' . mock_exam_q($questionSchema['table'])
            . ' (' . implode(', ', $qCols) . ') VALUES (' . implode(', ', $qVals) . ')';
        $qStmt = $pdo->prepare($qSql);
        $qStmt->execute($qParams);

        $eventQuestions[] = [
            'question_id' => $questionId,
            'course_id' => $questionRow['course_id'] ?? null,
            'selected_answer' => $selected,
            'correct_answer' => $correct,
        ];

        $order++;
    }

    offline_sync_debug_log('mock_exam.completed.inserted', [
        'local_attempt_id' => $localAttemptId,
        'remote_attempt_id' => $attemptId,
        'source_attempt_id' => $localAttemptId,
        'started_at_utc' => $startedAt,
        'completed_at_utc' => $completedAt,
        'question_rows_inserted' => count($eventQuestions),
    ]);

    offline_sync_debug_log('mock_exam.lesson_report.inserted', [
        'remote_attempt_id' => $attemptId,
        'lesson_report_rows' => is_array($lessonReportJson) ? count($lessonReportJson) : 0,
    ]);

    if ($eventQuestions) {
        mock_exam_write_events_and_progress($pdo, $userId, $attemptId, $eventQuestions, $qualificationId);
    }

    return [
        'type' => 'mock_exam.completed',
        'duplicate' => false,
        'remote_attempt_id' => $attemptId,
        'local_attempt_id' => $localAttemptId,
        'answered_questions' => $answeredQuestions,
        'total_questions' => $totalQuestions,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'blank_count' => $blankCount,
        'success_rate' => $successRate,
    ];
}

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');

    $body = api_get_request_data();
    $deviceId = trim((string)($body['device_id'] ?? ''));
    $events = $body['events'] ?? null;

    if (!is_array($events)) {
        api_error('events alanı dizi olmalıdır.', 422);
    }

    $maxEvents = 200;
    if (count($events) > $maxEvents) {
        api_error('Tek seferde en fazla ' . $maxEvents . ' event gönderilebilir.', 422);
    }

    $processedCount = 0;
    $duplicateCount = 0;
    $failedCount = 0;
    $processedEventIds = [];
    $duplicateEventIds = [];
    $failedEvents = [];

    $processedEvents = [];
    $duplicateEvents = [];

    $remoteAttemptMap = [];

    foreach ($events as $event) {
        $clientEventIdForFailure = '';
        $normalizedTypeForFailure = '';

        try {
            if (!is_array($event)) {
                throw new RuntimeException('Geçersiz event formatı.');
            }

            $clientEventId = trim((string)($event['client_event_id'] ?? ''));
            $typeMeta = offline_sync_extract_event_type($event);
            $typeRaw = (string)$typeMeta['type_raw'];
            $type = (string)$typeMeta['normalized_type'];
            $createdAt = trim((string)($event['created_at'] ?? ''));
            $payload = $event['payload'] ?? null;

            $clientEventIdForFailure = $clientEventId;
            $normalizedTypeForFailure = $type;

            offline_sync_debug_log('event.received', [
                'client_event_id' => $clientEventId,
                'incoming_type' => (string)($event['type'] ?? ''),
                'incoming_event_type' => (string)($event['event_type'] ?? ''),
                'normalized_type' => $type,
                'used_event_type_fallback' => !empty($typeMeta['used_event_type_fallback']),
            ]);

            if ($clientEventId === '') {
                throw new RuntimeException('client_event_id zorunludur.');
            }
            if ($type === '') {
                throw new RuntimeException('type zorunludur.');
            }
            if (!array_key_exists('payload', $event) || !is_array($payload)) {
                throw new RuntimeException('payload zorunludur ve object/dizi olmalıdır.');
            }
            if ($createdAt === '') {
                throw new RuntimeException('created_at zorunludur.');
            }

            $existingReceipt = offline_sync_get_receipt($pdo, $userId, $clientEventId);
            if ($existingReceipt) {
                $duplicateCount++;
                $duplicateEventIds[] = $clientEventId;

                offline_sync_debug_log('event.duplicate', [
                    'client_event_id' => $clientEventId,
                    'normalized_type' => $type,
                    'status' => (string)($existingReceipt['status'] ?? 'duplicate'),
                ]);

                $duplicateEventItem = [
                    'client_event_id' => $clientEventId,
                    'type' => $type,
                    'is_remote_reflected' => true,
                ];

                $existingRemoteAttemptId = offline_sync_receipt_extract_remote_attempt_id($existingReceipt);
                if ($existingRemoteAttemptId !== null) {
                    $localAttemptId = trim((string)($payload['local_attempt_id'] ?? ''));
                    if ($localAttemptId !== '') {
                        $remoteAttemptMap[$localAttemptId] = $existingRemoteAttemptId;
                    }
                    $duplicateEventItem['remote_attempt_id'] = $existingRemoteAttemptId;

                    $reflection = offline_sync_mock_exam_reflection_status($pdo, $userId, $existingRemoteAttemptId);
                    $duplicateEventItem['reflection'] = $reflection;

                    offline_sync_debug_log('mock_exam.summary_history.updated', [
                        'client_event_id' => $clientEventId,
                        'remote_attempt_id' => $existingRemoteAttemptId,
                        'summary_total_attempts' => (int)$reflection['summary_total_attempts'],
                        'in_history' => !empty($reflection['in_history']),
                    ]);
                }

                $duplicateEvents[] = $duplicateEventItem;
                continue;
            }

            $pdo->beginTransaction();
            try {
                $resultPayload = ['status' => 'ok'];
                $receiptStatus = 'processed';

                if ($type === 'study.answer_upsert') {
                    $resultPayload = offline_sync_handle_study_answer_upsert($pdo, $userId, $payload);
                } elseif ($type === 'study.bookmark_set') {
                    $resultPayload = offline_sync_handle_study_bookmark_set($pdo, $userId, $payload);
                } elseif ($type === 'study.session_summary') {
                    $resultPayload = offline_sync_handle_study_session_summary($pdo, $userId, $payload);
                } elseif ($type === 'mock_exam.completed') {
                    $resultPayload = offline_sync_handle_mock_exam_completed($pdo, $userId, $payload);
                    $localAttemptId = trim((string)($payload['local_attempt_id'] ?? ''));
                    $remoteAttemptId = trim((string)($resultPayload['remote_attempt_id'] ?? ''));
                    if ($localAttemptId !== '' && $remoteAttemptId !== '') {
                        $remoteAttemptMap[$localAttemptId] = $remoteAttemptId;
                        offline_sync_debug_log('mock_exam.remote_attempt_map.updated', [
                            'client_event_id' => $clientEventId,
                            'local_attempt_id' => $localAttemptId,
                            'remote_attempt_id' => $remoteAttemptId,
                        ]);
                    }
                    if (!empty($resultPayload['duplicate'])) {
                        $receiptStatus = 'duplicate';
                    }
                } else {
                    throw new RuntimeException('Desteklenmeyen event type: ' . $typeRaw);
                }

                offline_sync_write_receipt(
                    $pdo,
                    $userId,
                    $clientEventId,
                    $type,
                    $deviceId !== '' ? $deviceId : null,
                    $event,
                    $resultPayload,
                    $receiptStatus
                );

                $pdo->commit();

                if ($receiptStatus === 'duplicate') {
                    $duplicateCount++;
                    $duplicateEventIds[] = $clientEventId;
                    $duplicateEvents[] = [
                        'client_event_id' => $clientEventId,
                        'type' => $type,
                        'is_remote_reflected' => true,
                        'remote_attempt_id' => ($type === 'mock_exam.completed' ? (string)($resultPayload['remote_attempt_id'] ?? '') : null),
                    ];
                    offline_sync_debug_log('event.duplicate', [
                        'client_event_id' => $clientEventId,
                        'normalized_type' => $type,
                        'status' => 'duplicate',
                    ]);
                } else {
                    $processedCount++;
                    $processedEventIds[] = $clientEventId;

                    $processedItem = [
                        'client_event_id' => $clientEventId,
                        'type' => $type,
                        'is_remote_reflected' => true,
                    ];

                    if (offline_sync_is_study_type($type)) {
                        offline_sync_debug_log('study.replay.processed', [
                            'client_event_id' => $clientEventId,
                            'normalized_type' => $type,
                        ]);
                    }

                    if ($type === 'mock_exam.completed') {
                        $remoteAttemptId = trim((string)($resultPayload['remote_attempt_id'] ?? ''));
                        if ($remoteAttemptId !== '') {
                            $processedItem['remote_attempt_id'] = $remoteAttemptId;
                            offline_sync_debug_log('mock_exam.replay.processed', [
                                'client_event_id' => $clientEventId,
                                'remote_attempt_id' => $remoteAttemptId,
                            ]);

                            $reflection = offline_sync_mock_exam_reflection_status($pdo, $userId, $remoteAttemptId);
                            $processedItem['reflection'] = $reflection;

                            offline_sync_debug_log('mock_exam.summary_history.updated', [
                                'client_event_id' => $clientEventId,
                                'remote_attempt_id' => $remoteAttemptId,
                                'summary_total_attempts' => (int)$reflection['summary_total_attempts'],
                                'in_history' => !empty($reflection['in_history']),
                            ]);
                        }
                    }

                    $processedEvents[] = $processedItem;

                    offline_sync_debug_log('event.processed', [
                        'client_event_id' => $clientEventId,
                        'normalized_type' => $type,
                        'status' => 'processed',
                    ]);
                }
            } catch (Throwable $processingError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $processingError;
            }
        } catch (Throwable $eventError) {
            $failedCount++;
            $failedType = $normalizedTypeForFailure !== ''
                ? $normalizedTypeForFailure
                : offline_sync_normalize_event_type(trim((string)($event['type'] ?? $event['event_type'] ?? '')));
            $failedClientEventId = $clientEventIdForFailure !== ''
                ? $clientEventIdForFailure
                : (string)($event['client_event_id'] ?? '');

            offline_sync_debug_log('event.failed', [
                'client_event_id' => $failedClientEventId,
                'normalized_type' => $failedType,
                'message' => $eventError->getMessage(),
            ]);

            $failedEvents[] = [
                'client_event_id' => $failedClientEventId,
                'type' => $failedType,
                'message' => $eventError->getMessage(),
            ];
        }
    }

    $remoteStatistics = offline_sync_build_dashboard_statistics_snapshot($pdo, $userId);

    api_success('Offline sync tamamlandı.', [
        'processed_event_ids' => $processedEventIds,
        'duplicate_event_ids' => $duplicateEventIds,
        'failed_events' => $failedEvents,
        'processed_events' => $processedEvents,
        'duplicate_events' => $duplicateEvents,
        'remote_attempt_map' => $remoteAttemptMap,
        'remote_statistics' => $remoteStatistics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

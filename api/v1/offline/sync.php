<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/study_helper.php';
require_once __DIR__ . '/offline_helper.php';

api_require_method('POST');

function offline_sync_normalize_source(?string $source): string
{
    $value = strtolower(trim((string)$source));
    $allowed = ['study', 'daily_quiz', 'exam', 'maritime_english', 'maritime-english', 'me', 'me_quiz', 'maritime_english_quiz', 'offline_sync'];
    if (!in_array($value, $allowed, true)) {
        return 'offline_sync';
    }
    return $value;
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

    foreach ($events as $event) {
        try {
            if (!is_array($event)) {
                throw new RuntimeException('Geçersiz event formatı.');
            }

            $clientEventId = trim((string)($event['client_event_id'] ?? ''));
            $type = trim((string)($event['type'] ?? ''));
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if ($clientEventId === '') {
                throw new RuntimeException('client_event_id zorunludur.');
            }
            if ($type === '') {
                throw new RuntimeException('type zorunludur.');
            }

            if (offline_sync_receipt_exists($pdo, $userId, $clientEventId)) {
                $duplicateCount++;
                $duplicateEventIds[] = $clientEventId;
                continue;
            }

            $resultPayload = ['status' => 'ok'];

            if ($type === 'answer_upsert') {
                $questionId = trim((string)($payload['question_id'] ?? ''));
                $selectedAnswer = strtoupper(trim((string)($payload['selected_answer'] ?? '')));
                $source = offline_sync_normalize_source($payload['source'] ?? 'offline_sync');
                $sessionId = trim((string)($payload['session_id'] ?? ''));
                $sessionId = $sessionId !== '' ? $sessionId : null;

                if ($questionId === '') {
                    throw new RuntimeException('answer_upsert.question_id zorunludur.');
                }
                if (!in_array($selectedAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
                    throw new RuntimeException('answer_upsert.selected_answer A/B/C/D/E olmalıdır.');
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
                // best effort
                try {
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
                } catch (Throwable $ignored) {
                }

                $resultPayload = [
                    'type' => 'answer_upsert',
                    'question_id' => $questionId,
                    'selected_answer' => $selectedAnswer,
                    'is_correct' => $isCorrect,
                    'progress' => $progress,
                ];
            } elseif ($type === 'bookmark_set') {
                $questionId = trim((string)($payload['question_id'] ?? ''));
                if ($questionId === '') {
                    throw new RuntimeException('bookmark_set.question_id zorunludur.');
                }

                $isBookmarked = filter_var($payload['is_bookmarked'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isBookmarked === null) {
                    throw new RuntimeException('bookmark_set.is_bookmarked boolean olmalıdır.');
                }

                $meta = study_get_question_meta($pdo, $questionId);
                if (!$meta['exists']) {
                    throw new RuntimeException('Soru bulunamadı.');
                }

                $bookmarkResult = study_set_bookmark_state($pdo, $userId, $questionId, (bool)$isBookmarked);
                $resultPayload = [
                    'type' => 'bookmark_set',
                    'bookmark' => $bookmarkResult,
                ];
            } elseif ($type === 'session_summary') {
                $sessionPayload = [
                    'course_id' => $payload['course_id'] ?? null,
                    'qualification_id' => $payload['qualification_id'] ?? null,
                    'question_type' => $payload['question_type'] ?? null,
                    'pool_type' => $payload['pool_type'] ?? null,
                    'requested_question_count' => (int)($payload['requested_question_count'] ?? 0),
                    'served_question_count' => (int)($payload['served_question_count'] ?? 0),
                    'correct_count' => (int)($payload['correct_count'] ?? 0),
                    'wrong_count' => (int)($payload['wrong_count'] ?? 0),
                    'duration_seconds' => (int)($payload['duration_seconds'] ?? 0),
                ];

                $session = study_insert_session($pdo, $userId, $sessionPayload);
                $resultPayload = [
                    'type' => 'session_summary',
                    'session' => $session,
                ];
            } else {
                throw new RuntimeException('Desteklenmeyen event type: ' . $type);
            }

            offline_sync_write_receipt($pdo, $userId, $clientEventId, $type, $deviceId !== '' ? $deviceId : null, $event, $resultPayload);

            $processedCount++;
            $processedEventIds[] = $clientEventId;
        } catch (Throwable $eventError) {
            $failedCount++;
            $failedEvents[] = [
                'client_event_id' => (string)($event['client_event_id'] ?? ''),
                'type' => (string)($event['type'] ?? ''),
                'message' => $eventError->getMessage(),
            ];
        }
    }

    api_success('Offline sync tamamlandı.', [
        'processed_count' => $processedCount,
        'duplicate_count' => $duplicateCount,
        'failed_count' => $failedCount,
        'processed_event_ids' => $processedEventIds,
        'duplicate_event_ids' => $duplicateEventIds,
        'failed_events' => $failedEvents,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/word_game_helper.php';

api_require_method('POST');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $payload = api_get_request_data();
    $requestedCategoryId = trim((string)($payload['category_id'] ?? ''));

    $qualificationId = word_game_get_current_qualification_id($pdo, $userId);
    $qualificationName = null;
    if ($qualificationId !== null && $qualificationId !== '') {
        try {
            $qStmt = $pdo->prepare('SELECT name FROM qualifications WHERE id = ? LIMIT 1');
            $qStmt->execute([$qualificationId]);
            $qualificationName = $qStmt->fetchColumn() ?: null;
        } catch (Throwable $ignored) {
            $qualificationName = null;
        }
    }

    word_game_debug_log('word game start current qualification', [
        'user_id' => $userId,
        'qualification_id' => $qualificationId,
        'qualification_name' => $qualificationName,
    ]);

    if (!$qualificationId) {
        api_send_json([
            'success' => false,
            'message' => 'Current qualification bulunamadı. Önce yeterlilik seçmelisiniz.',
            'data' => null,
        ], 403);
    }

    $usedCategoryId = $requestedCategoryId;
    $autoCategoryId = null;

    try {
        if ($requestedCategoryId !== '') {
            $catStmt = $pdo->prepare('SELECT id, is_active FROM word_game_categories WHERE id = ? LIMIT 1');
            $catStmt->execute([$requestedCategoryId]);
            $cat = $catStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cat || (int)($cat['is_active'] ?? 0) !== 1) {
                api_send_json(['success'=>false,'message'=>'Kategori aktif değil veya bulunamadı.','data'=>null],422);
            }

            $mapStmt = $pdo->prepare('SELECT COUNT(*) FROM word_game_category_qualifications WHERE category_id = ? AND qualification_id = ?');
            $mapStmt->execute([$requestedCategoryId, $qualificationId]);
            if ((int)$mapStmt->fetchColumn() < 1) {
                api_send_json(['success'=>false,'message'=>'Bu kategori için yeterlilik yetkiniz yok.','data'=>null],403);
            }

            $questions = word_game_pick_questions_for_category($pdo, $userId, $qualificationId, $requestedCategoryId);
        } else {
            $autoCategoryId = word_game_find_best_category_for_qualification($pdo, $qualificationId);
            if ($autoCategoryId === null || $autoCategoryId === '') {
                api_send_json([
                    'success' => false,
                    'message' => 'Bu yeterlilik için aktif kelime oyunu başlığı veya uygun soru bulunamadı.',
                    'data' => null,
                ], 422);
            }

            $usedCategoryId = $autoCategoryId;
            $questions = word_game_pick_questions_for_category($pdo, $userId, $qualificationId, $autoCategoryId);
        }
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        if (str_starts_with($message, 'WORD_GAME_INSUFFICIENT_QUESTIONS|')) {
            $json = substr($message, strlen('WORD_GAME_INSUFFICIENT_QUESTIONS|'));
            $details = json_decode($json, true);
            if (!is_array($details)) {
                $details = [];
            }

            api_send_json([
                'success' => false,
                'message' => 'Soru havuzu yetersiz. Gerekli dağılım sağlanamadı.',
                'data' => [
                    'qualification_id' => $qualificationId,
                    'missing_lengths' => $details,
                ],
            ], 422);
        }

        api_send_json([
            'success' => false,
            'message' => $message === '' ? 'Bu ayarlara uygun kelime oyunu oluşturulamadı.' : $message,
            'data' => null,
        ], 422);
    }

    $created = word_game_session_create($pdo, $userId, $qualificationId, $questions);
    $selectedTotalChars = array_sum(array_map(static fn(array $q): int => (int)($q['answer_length'] ?? 0), $questions));
    $settings = word_game_get_runtime_settings($pdo);
    $targetChars = (int)floor(max(1, (int)($settings['target_score'] ?? 10000)) / max(1, (int)($settings['points_per_char'] ?? 100)));

    $selectedDistribution = [];
    foreach (($questions ?? []) as $q) {
        $len = (int)($q['answer_length'] ?? 0);
        if ($len <= 0) {
            continue;
        }
        $key = (string)$len;
        $selectedDistribution[$key] = (int)($selectedDistribution[$key] ?? 0) + 1;
    }
    ksort($selectedDistribution, SORT_NUMERIC);

    word_game_debug_log('word game start selected distribution result', [
        'user_id' => $userId,
        'requested_category_id' => $requestedCategoryId,
        'auto_selected_category_id' => $autoCategoryId,
        'qualification_id' => $qualificationId,
        'used_category_id' => $usedCategoryId,
        'selected_distribution' => $selectedDistribution,
        'selected_question_count' => count($questions),
        'selected_total_chars' => $selectedTotalChars,
        'target_chars' => $targetChars,
    ]);

    api_send_json([
        'success' => true,
        'data' => [
            'session_id' => (string)$created['session_id'],
            'qualification_id' => (string)$created['qualification_id'],
            'category_id' => (string)$usedCategoryId,
            'duration_seconds' => (int)$created['duration_seconds'],
            'questions' => array_values($created['questions'] ?? []),
        ],
    ]);
} catch (Throwable $e) {
    word_game_debug_log('SQL error', [
        'endpoint' => 'word-game/start',
        'error_class' => get_class($e),
    ]);

    api_send_json(word_game_build_error_response('Word game başlatılamadı.', $e), 422);
}

<?php

require_once __DIR__ . '/word_game_question_helper.php';

function word_game_debug_log(string $stage, array $context = []): void
{
    $line = '[word_game][' . $stage . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log($line !== false ? $line : ('[word_game][' . $stage . ']'));
}

function word_game_get_current_qualification_id(PDO $pdo, string $userId): ?string
{
    $userId = trim($userId);
    if ($userId === '') {
        word_game_debug_log('current qualification resolved', ['user_id' => null, 'qualification_id' => null]);
        return null;
    }

    $qualificationId = null;
    if (function_exists('get_current_user_qualification_id')) {
        $qualificationId = get_current_user_qualification_id($pdo, $userId);
    } elseif (function_exists('api_find_profile_by_user_id')) {
        $profile = api_find_profile_by_user_id($pdo, $userId);
        $qualificationId = trim((string)($profile['current_qualification_id'] ?? ''));
    }

    $qualificationId = trim((string)$qualificationId);
    $resolved = $qualificationId !== '' ? $qualificationId : null;

    word_game_debug_log('current qualification resolved', [
        'user_id' => $userId,
        'qualification_id' => $resolved,
    ]);

    return $resolved;
}

function word_game_required_distribution(): array
{
    return [4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 2, 9 => 2, 10 => 2];
}

function word_game_pick_questions(PDO $pdo, string $qualificationId): array
{
    $qualificationId = trim($qualificationId);
    if ($qualificationId === '') {
        throw new InvalidArgumentException('qualification_id zorunludur.');
    }

    $distribution = word_game_required_distribution();
    $insufficient = [];
    $selected = [];
    $selectedCountByLength = [];

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM word_game_questions
         WHERE qualification_id = ?
           AND is_active = 1
           AND answer_length = ?'
    );

    foreach ($distribution as $answerLength => $requiredCount) {
        $countStmt->execute([$qualificationId, (int)$answerLength]);
        $available = (int)$countStmt->fetchColumn();

        if ($available < $requiredCount) {
            $insufficient[] = [
                'answer_length' => (int)$answerLength,
                'required' => (int)$requiredCount,
                'available' => $available,
                'missing' => (int)($requiredCount - $available),
            ];
        }
    }

    if (!empty($insufficient)) {
        throw new RuntimeException('WORD_GAME_INSUFFICIENT_QUESTIONS|' . json_encode($insufficient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    foreach ($distribution as $answerLength => $requiredCount) {
        $sql = sprintf(
            'SELECT id, qualification_id, question_text, answer_length
             FROM word_game_questions
             WHERE qualification_id = ?
               AND is_active = 1
               AND answer_length = ?
             ORDER BY RAND()
             LIMIT %d',
            (int)$requiredCount
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$qualificationId, (int)$answerLength]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $selectedCountByLength[(string)$answerLength] = count($rows);

        foreach ($rows as $row) {
            $selected[] = [
                'id' => (string)$row['id'],
                'qualification_id' => (string)$row['qualification_id'],
                'question_text' => (string)$row['question_text'],
                'answer_length' => (int)$row['answer_length'],
            ];
        }
    }

    if (count($selected) !== 10) {
        throw new RuntimeException('Soru seçiminde beklenmeyen bir hata oluştu.');
    }

    shuffle($selected);
    word_game_debug_log('selected question counts by length', [
        'qualification_id' => $qualificationId,
        'selected_counts' => $selectedCountByLength,
        'total' => count($selected),
    ]);

    return $selected;
}

function word_game_question_max_score(int $answerLength): int
{
    return max(0, $answerLength * 10);
}

function word_game_session_create(PDO $pdo, string $userId, string $qualificationId, array $questions): array
{
    $userId = trim($userId);
    $qualificationId = trim($qualificationId);

    if ($userId === '' || $qualificationId === '') {
        throw new InvalidArgumentException('user_id ve qualification_id zorunludur.');
    }

    if (count($questions) !== 10) {
        throw new InvalidArgumentException('Oyun için toplam 10 soru gereklidir.');
    }

    $sessionId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
    $durationSeconds = 400;

    $pdo->beginTransaction();
    try {
        $sessionStmt = $pdo->prepare(
            'INSERT INTO word_game_sessions (
                id, user_id, qualification_id, status,
                duration_seconds, remaining_seconds,
                completed_questions, correct_questions, wrong_questions,
                total_letters_taken, total_score,
                started_at, finished_at, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?,
                0, 0, 0,
                0, 0,
                NOW(), NULL, NOW(), NOW()
            )'
        );
        $sessionStmt->execute([
            $sessionId,
            $userId,
            $qualificationId,
            'active',
            $durationSeconds,
            $durationSeconds,
        ]);

        $questionStmt = $pdo->prepare(
            'INSERT INTO word_game_session_questions (
                id, session_id, question_id, question_order,
                max_score, earned_score,
                letters_taken_count, revealed_indexes_json,
                wrong_attempt_count, is_correct, is_completed,
                completed_at, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, 0,
                0, ?,
                0, 0, 0,
                NULL, NOW(), NOW()
            )'
        );

        $createdQuestions = [];
        foreach (array_values($questions) as $idx => $question) {
            $sessionQuestionId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
            $answerLength = (int)($question['answer_length'] ?? 0);
            $maxScore = word_game_question_max_score($answerLength);
            $questionOrder = $idx + 1;

            $questionStmt->execute([
                $sessionQuestionId,
                $sessionId,
                (string)$question['id'],
                $questionOrder,
                $maxScore,
                '[]',
            ]);

            $createdQuestions[] = [
                'session_question_id' => $sessionQuestionId,
                'question_order' => $questionOrder,
                'question_text' => (string)($question['question_text'] ?? ''),
                'answer_length' => $answerLength,
                'max_score' => $maxScore,
            ];
        }

        $pdo->commit();

        word_game_debug_log('session created', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'qualification_id' => $qualificationId,
            'question_count' => count($createdQuestions),
        ]);

        return [
            'session_id' => $sessionId,
            'qualification_id' => $qualificationId,
            'duration_seconds' => $durationSeconds,
            'questions' => $createdQuestions,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function word_game_find_session(PDO $pdo, string $sessionId, string $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM word_game_sessions WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([trim($sessionId), trim($userId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_find_session_question(PDO $pdo, string $sessionQuestionId, string $sessionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            sq.*,
            q.answer_normalized AS source_answer_normalized,
            q.answer_text AS source_answer_text,
            q.answer_length AS source_answer_length,
            q.question_text AS source_question_text
         FROM word_game_session_questions sq
         INNER JOIN word_game_questions q ON q.id = sq.question_id
         WHERE sq.id = ?
           AND sq.session_id = ?
         LIMIT 1'
    );
    $stmt->execute([trim($sessionQuestionId), trim($sessionId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_reveal_letter(PDO $pdo, array $sessionQuestion): array
{
    $sessionQuestionId = (string)($sessionQuestion['id'] ?? '');
    $answerRaw = (string)($sessionQuestion['source_answer_normalized'] ?? $sessionQuestion['source_answer_text'] ?? '');
    $answer = word_game_normalize_answer($answerRaw);
    $answerLength = (int)($sessionQuestion['source_answer_length'] ?? mb_strlen($answer, 'UTF-8'));
    $maxScore = (int)($sessionQuestion['max_score'] ?? word_game_question_max_score($answerLength));

    if ($sessionQuestionId === '' || $answerLength <= 0 || $answer === '') {
        throw new RuntimeException('Soru verisi okunamadı.');
    }

    $revealedIndexes = json_decode((string)($sessionQuestion['revealed_indexes_json'] ?? '[]'), true);
    if (!is_array($revealedIndexes)) {
        $revealedIndexes = [];
    }
    $revealedIndexes = array_values(array_unique(array_map('intval', $revealedIndexes)));

    $availableIndexes = [];
    for ($i = 1; $i <= $answerLength; $i++) {
        if (!in_array($i, $revealedIndexes, true)) {
            $availableIndexes[] = $i;
        }
    }

    if (empty($availableIndexes)) {
        throw new RuntimeException('Açılacak harf kalmadı.');
    }

    $pickedPosition = random_int(0, count($availableIndexes) - 1);
    $revealedIndex = (int)$availableIndexes[$pickedPosition];
    $revealedIndexes[] = $revealedIndex;
    sort($revealedIndexes, SORT_NUMERIC);

    $lettersTakenCount = (int)($sessionQuestion['letters_taken_count'] ?? 0) + 1;
    $remainingScore = max(0, $maxScore - ($lettersTakenCount * 10));
    $revealedLetter = mb_substr($answer, $revealedIndex - 1, 1, 'UTF-8');

    $updateStmt = $pdo->prepare(
        'UPDATE word_game_session_questions
         SET revealed_indexes_json = ?,
             letters_taken_count = ?,
             earned_score = ?,
             updated_at = NOW()
         WHERE id = ?'
    );
    $updateStmt->execute([
        json_encode($revealedIndexes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $lettersTakenCount,
        $remainingScore,
        $sessionQuestionId,
    ]);

    word_game_debug_log('reveal letter index', [
        'session_question_id' => $sessionQuestionId,
        'revealed_index' => $revealedIndex,
        'letters_taken_count' => $lettersTakenCount,
    ]);

    return [
        'revealed_index' => $revealedIndex,
        'revealed_letter' => $revealedLetter,
        'letters_taken_count' => $lettersTakenCount,
        'remaining_question_score' => $remainingScore,
        'revealed_indexes' => $revealedIndexes,
    ];
}

function word_game_check_answer(PDO $pdo, array $sessionQuestion, string $submittedAnswer): array
{
    $sessionQuestionId = (string)($sessionQuestion['id'] ?? '');
    $answerRaw = (string)($sessionQuestion['source_answer_normalized'] ?? $sessionQuestion['source_answer_text'] ?? '');
    $correctAnswer = word_game_normalize_answer($answerRaw);
    $submittedNormalized = word_game_normalize_answer($submittedAnswer);
    $lettersTakenCount = (int)($sessionQuestion['letters_taken_count'] ?? 0);
    $sourceLength = (int)($sessionQuestion['source_answer_length'] ?? mb_strlen($correctAnswer, 'UTF-8'));
    $maxScore = (int)($sessionQuestion['max_score'] ?? word_game_question_max_score($sourceLength));
    $wrongAttemptCount = (int)($sessionQuestion['wrong_attempt_count'] ?? 0);

    if ($sessionQuestionId === '' || $correctAnswer === '') {
        throw new RuntimeException('Soru verisi okunamadı.');
    }

    $isCorrect = ($submittedNormalized !== '' && $submittedNormalized === $correctAnswer);

    if ($isCorrect) {
        $earnedScore = max(0, $maxScore - ($lettersTakenCount * 10));

        $stmt = $pdo->prepare(
            'UPDATE word_game_session_questions
             SET is_correct = 1,
                 is_completed = 1,
                 completed_at = NOW(),
                 earned_score = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$earnedScore, $sessionQuestionId]);

        $result = [
            'is_correct' => true,
            'earned_score' => $earnedScore,
            'wrong_attempt_count' => $wrongAttemptCount,
            'remaining_attempts' => max(0, 1 - $wrongAttemptCount),
            'question_completed' => true,
        ];

        word_game_debug_log('check answer result', [
            'session_question_id' => $sessionQuestionId,
            'is_correct' => true,
            'earned_score' => $earnedScore,
            'wrong_attempt_count' => $wrongAttemptCount,
        ]);

        return $result;
    }

    $wrongAttemptCount++;
    $isCompleted = $wrongAttemptCount >= 2;
    $remainingAttempts = max(0, 2 - $wrongAttemptCount);

    if ($isCompleted) {
        $stmt = $pdo->prepare(
            'UPDATE word_game_session_questions
             SET wrong_attempt_count = ?,
                 is_correct = 0,
                 is_completed = 1,
                 completed_at = NOW(),
                 earned_score = 0,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$wrongAttemptCount, $sessionQuestionId]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE word_game_session_questions
             SET wrong_attempt_count = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$wrongAttemptCount, $sessionQuestionId]);
    }

    $result = [
        'is_correct' => false,
        'wrong_attempt_count' => $wrongAttemptCount,
        'remaining_attempts' => $remainingAttempts,
        'question_completed' => $isCompleted,
    ];

    word_game_debug_log('check answer result', [
        'session_question_id' => $sessionQuestionId,
        'is_correct' => false,
        'wrong_attempt_count' => $wrongAttemptCount,
        'question_completed' => $isCompleted,
    ]);

    return $result;
}

function word_game_refresh_session_totals(PDO $pdo, string $sessionId): void
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END), 0) AS completed_questions,
            COALESCE(SUM(CASE WHEN is_completed = 1 AND is_correct = 1 THEN 1 ELSE 0 END), 0) AS correct_questions,
            COALESCE(SUM(CASE WHEN is_completed = 1 AND is_correct = 0 THEN 1 ELSE 0 END), 0) AS wrong_questions,
            COALESCE(SUM(COALESCE(letters_taken_count, 0)), 0) AS total_letters_taken,
            COALESCE(SUM(COALESCE(earned_score, 0)), 0) AS total_score
         FROM word_game_session_questions
         WHERE session_id = ?'
    );
    $stmt->execute([$sessionId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $update = $pdo->prepare(
        'UPDATE word_game_sessions
         SET completed_questions = ?,
             correct_questions = ?,
             wrong_questions = ?,
             total_letters_taken = ?,
             total_score = ?,
             updated_at = NOW()
         WHERE id = ?'
    );
    $update->execute([
        (int)($totals['completed_questions'] ?? 0),
        (int)($totals['correct_questions'] ?? 0),
        (int)($totals['wrong_questions'] ?? 0),
        (int)($totals['total_letters_taken'] ?? 0),
        (int)($totals['total_score'] ?? 0),
        $sessionId,
    ]);
}

function word_game_finish_session(PDO $pdo, string $sessionId, string $userId, int $remainingSeconds, string $status): array
{
    $sessionId = trim($sessionId);
    $userId = trim($userId);
    $status = strtolower(trim($status));

    if (!in_array($status, ['completed', 'abandoned', 'timeout'], true)) {
        throw new InvalidArgumentException('status geçersiz.');
    }

    $remainingSeconds = max(0, min(400, $remainingSeconds));
    word_game_refresh_session_totals($pdo, $sessionId);

    $session = word_game_find_session($pdo, $sessionId, $userId);
    if (!$session) {
        throw new RuntimeException('Oturum bulunamadı.');
    }

    $totalScore = (int)($session['total_score'] ?? 0);
    if ($status === 'abandoned') {
        $totalScore = 0;
    }

    $stmt = $pdo->prepare(
        'UPDATE word_game_sessions
         SET status = ?,
             remaining_seconds = ?,
             total_score = ?,
             finished_at = NOW(),
             updated_at = NOW()
         WHERE id = ?
           AND user_id = ?'
    );
    $stmt->execute([$status, $remainingSeconds, $totalScore, $sessionId, $userId]);

    $updated = word_game_find_session($pdo, $sessionId, $userId);
    if (!$updated) {
        throw new RuntimeException('Oturum sonucu okunamadı.');
    }

    $result = [
        'session_id' => (string)$updated['id'],
        'status' => (string)$updated['status'],
        'total_score' => (int)$updated['total_score'],
        'remaining_seconds' => (int)$updated['remaining_seconds'],
        'correct_questions' => (int)$updated['correct_questions'],
        'wrong_questions' => (int)$updated['wrong_questions'],
        'total_letters_taken' => (int)$updated['total_letters_taken'],
        'finished_at' => (string)($updated['finished_at'] ?? ''),
    ];

    word_game_debug_log('finish session result', $result);

    return $result;
}

function word_game_get_leaderboard(PDO $pdo, string $qualificationId, int $limit = 50): array
{
    $qualificationId = trim($qualificationId);
    $limit = max(1, min(100, (int)$limit));

    $sql = sprintf(
        'SELECT id, user_id, total_score, remaining_seconds, finished_at
         FROM word_game_sessions
         WHERE qualification_id = ?
           AND status IN (\'completed\', \'timeout\')
           AND finished_at IS NOT NULL
         ORDER BY total_score DESC, remaining_seconds DESC, finished_at ASC
         LIMIT %d',
        $limit
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qualificationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $idx => $row) {
        $userId = (string)($row['user_id'] ?? '');
        $displayName = $userId;

        if (function_exists('api_find_profile_by_user_id')) {
            $profile = api_find_profile_by_user_id($pdo, $userId);
            if ($profile) {
                $displayName = trim((string)($profile['full_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = trim((string)($profile['email'] ?? $userId));
                }
            }
        }

        $items[] = [
            'rank' => $idx + 1,
            'user_id' => $userId,
            'display_name' => $displayName,
            'total_score' => (int)($row['total_score'] ?? 0),
            'remaining_seconds' => (int)($row['remaining_seconds'] ?? 0),
            'finished_at' => (string)($row['finished_at'] ?? ''),
        ];
    }

    word_game_debug_log('leaderboard count', [
        'qualification_id' => $qualificationId,
        'count' => count($items),
    ]);

    return $items;
}

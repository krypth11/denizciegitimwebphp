<?php

function kg_secure_run_question_limit(string $mode): int
{
    return match ($mode) {
        'quick', 'daily' => 10,
        'long' => 20,
        'endless' => 50,
        default => 0,
    };
}

function kg_secure_run_create(PDO $pdo, string $userId, string $qualificationId, string $categoryId, string $mode): array
{
    $rows = array_values(array_filter(
        kg_get_active_questions_for_qualification($pdo, $qualificationId),
        static fn(array $row): bool => (string)($row['category_id'] ?? '') === $categoryId
    ));
    if (count($rows) < 2) {
        throw new RuntimeException('Bu kategori için güvenli oyun turu oluşturacak yeterli soru yok.', 422);
    }

    shuffle($rows);
    $rows = array_slice($rows, 0, min(count($rows), kg_secure_run_question_limit($mode)));
    $rounds = [];
    foreach ($rows as $index => $real) {
        $alternatives = array_values(array_filter($rows, static fn(array $q): bool => (string)$q['id'] !== (string)$real['id']));
        $isTrue = random_int(0, 1) === 1;
        $claim = $isTrue ? $real : $alternatives[random_int(0, count($alternatives) - 1)];
        $rounds[] = [
            'round_id' => bin2hex(random_bytes(12)),
            'question_id' => (string)$real['id'],
            'expected_answer' => $isTrue,
            'claim_text' => (string)($claim['question_text'] ?? ''),
            'correct_text' => (string)($real['question_text'] ?? ''),
            'question' => [
                'id' => (string)$real['id'],
                'category_id' => (string)$real['category_id'],
                'category_name' => (string)($real['category_name'] ?? ''),
                'question_text' => (string)($real['question_text'] ?? ''),
                'correct_answer' => '',
                'image_url' => (string)($real['image_large_url'] ?? $real['image_url'] ?? ''),
                'image_large_url' => (string)($real['image_large_url'] ?? ''),
                'image_thumb_url' => (string)($real['image_thumb_url'] ?? ''),
            ],
        ];
    }

    $runId = generate_uuid();
    $stmt = $pdo->prepare('INSERT INTO kart_game_secure_runs (id, user_id, qualification_id, category_id, game_mode, rounds_json, answers_json, status, started_at, expires_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW(), NOW())');
    $stmt->execute([$runId, $userId, $qualificationId, $categoryId, $mode, json_encode($rounds, JSON_UNESCAPED_UNICODE), '[]', 'active']);

    $clientRounds = array_map(static function (array $round): array {
        return [
            'round_id' => $round['round_id'],
            'claim_text' => $round['claim_text'],
            'question' => $round['question'],
        ];
    }, $rounds);

    return ['run_id' => $runId, 'rounds' => $clientRounds, 'expires_in_seconds' => 1800];
}

function kg_secure_run_load_json_array(array $run, string $field): array
{
    $decoded = json_decode((string)($run[$field] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Oyun turu verisi geçersiz.', 500);
    }
    return array_values($decoded);
}

function kg_secure_run_score(array $rounds, array $answers): array
{
    if (count($answers) !== count($rounds)) {
        throw new RuntimeException('Cevap seti sunucudaki tur ile uyuşmuyor.', 422);
    }
    $correct = 0;
    $wrong = 0;
    $combo = 0;
    $maxCombo = 0;
    foreach ($rounds as $index => $round) {
        $answer = $answers[$index] ?? null;
        if (!is_array($answer) || !hash_equals((string)$round['round_id'], (string)($answer['round_id'] ?? '')) || !is_bool($answer['answer'] ?? null)) {
            throw new RuntimeException('Geçersiz veya sırası değiştirilmiş cevap.', 422);
        }
        if ($answer['answer'] === (bool)$round['expected_answer']) {
            $correct++;
            $combo++;
            $maxCombo = max($maxCombo, $combo);
        } else {
            $wrong++;
            $combo = 0;
        }
    }
    return [
        'score' => ($correct * 100) + ($maxCombo * 20),
        'total_questions' => count($rounds),
        'correct_count' => $correct,
        'wrong_count' => $wrong,
        'max_combo' => $maxCombo,
    ];
}

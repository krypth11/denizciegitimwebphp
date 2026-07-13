<?php

require_once __DIR__ . '/word_game_question_helper.php';

function word_game_debug_log(string $stage, array $context = []): void
{
    $blockedKeys = [
        'answer_text' => true,
        'answer_normalized' => true,
        'submitted_answer' => true,
        'answer_text_debug' => true,
        'correct_answer' => true,
        'answer_template' => true,
        'answer_pattern' => true,
        'solution' => true,
        'revealed_letter' => true,
        'revealed_letter_normalized' => true,
        'raw_response_body' => true,
        'response_body' => true,
    ];

    $sanitize = static function ($value) use (&$sanitize, $blockedKeys) {
        if (!is_array($value)) {
            return $value;
        }

        $safe = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : (string)$key;
            if (isset($blockedKeys[$keyString])) {
                continue;
            }
            $safe[$key] = $sanitize($item);
        }

        return $safe;
    };

    $line = '[word_game][' . $stage . '] ' . json_encode($sanitize($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log($line !== false ? $line : ('[word_game][' . $stage . ']'));
}

function word_game_table_columns(PDO $pdo, string $table): array
{
    $table = trim($table);
    if ($table === '') {
        throw new RuntimeException('WORD_GAME_SCHEMA|Geçersiz tablo adı.');
    }

    $columns = function_exists('get_table_columns') ? get_table_columns($pdo, $table) : [];
    if (!is_array($columns) || empty($columns)) {
        throw new RuntimeException('WORD_GAME_SCHEMA|`' . $table . '` tablosu bulunamadı veya kolonları okunamadı.');
    }

    return $columns;
}

function word_game_pick_column(array $columns, array $candidates, bool $required = true): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    if ($required) {
        throw new RuntimeException(
            'WORD_GAME_SCHEMA|Beklenen kolon bulunamadı. Adaylar: ' . implode(', ', $candidates)
        );
    }

    return null;
}

function word_game_is_debug_enabled(): bool
{
    $environment = strtolower(trim((string)(getenv('APP_ENV') ?: getenv('ENV') ?: 'production')));
    if (in_array($environment, ['prod', 'production'], true)) {
        return false;
    }

    $flag = getenv('APP_DEBUG');
    return $flag !== false && in_array(strtolower(trim((string)$flag)), ['1', 'true', 'yes', 'on'], true);
}

function word_game_build_error_response(string $message, Throwable $e): array
{
    return [
        'success' => false,
        'message' => $message,
        'data' => null,
    ];
}

function word_game_session_initial_remaining_seconds(): int
{
    return 400;
}

function word_game_session_schema(PDO $pdo): array
{
    static $schema = null;
    if (is_array($schema)) {
        return $schema;
    }

    $columns = word_game_table_columns($pdo, 'word_game_sessions');

    $schema = [
        'table' => 'word_game_sessions',
        'id' => word_game_pick_column($columns, ['id']),
        'user_id' => word_game_pick_column($columns, ['user_id']),
        'qualification_id' => word_game_pick_column($columns, ['qualification_id']),
        'total_score' => word_game_pick_column($columns, ['total_score']),
        'remaining_seconds' => word_game_pick_column($columns, ['remaining_seconds', 'duration_seconds']),
        'total_questions' => word_game_pick_column($columns, ['total_questions']),
        'completed_questions' => word_game_pick_column($columns, ['completed_questions']),
        'correct_questions' => word_game_pick_column($columns, ['correct_questions']),
        'wrong_questions' => word_game_pick_column($columns, ['wrong_questions']),
        'total_letters_taken' => word_game_pick_column($columns, ['total_letters_taken']),
        'status' => word_game_pick_column($columns, ['status']),
        'started_at' => word_game_pick_column($columns, ['started_at']),
        'finished_at' => word_game_pick_column($columns, ['finished_at']),
        'created_at' => word_game_pick_column($columns, ['created_at'], false),
        'updated_at' => word_game_pick_column($columns, ['updated_at'], false),
    ];

    return $schema;
}

function word_game_questions_schema(PDO $pdo): array
{
    static $schema = null;
    if (is_array($schema)) {
        return $schema;
    }

    $columns = word_game_table_columns($pdo, 'word_game_questions');

    $schema = [
        'table' => 'word_game_questions',
        'id' => word_game_pick_column($columns, ['id']),
        'qualification_id' => word_game_pick_column($columns, ['qualification_id'], false),
        'category_id' => word_game_pick_column($columns, ['category_id'], false),
        'question_text' => word_game_pick_column($columns, ['question_text']),
        'answer_text' => word_game_pick_column($columns, ['answer_text']),
        'answer_normalized' => word_game_pick_column($columns, ['answer_normalized']),
        'answer_length' => word_game_pick_column($columns, ['answer_length']),
        'is_active' => word_game_pick_column($columns, ['is_active']),
    ];

    return $schema;
}

function word_game_session_questions_schema(PDO $pdo): array
{
    static $schema = null;
    if (is_array($schema)) {
        return $schema;
    }

    $columns = word_game_table_columns($pdo, 'word_game_session_questions');

    $schema = [
        'table' => 'word_game_session_questions',
        'id' => word_game_pick_column($columns, ['id']),
        'session_id' => word_game_pick_column($columns, ['session_id']),
        'word_game_question_id' => word_game_pick_column($columns, ['word_game_question_id', 'question_id']),
        'question_order' => word_game_pick_column($columns, ['question_order']),
        'question_text' => word_game_pick_column($columns, ['question_text']),
        'answer_text' => word_game_pick_column($columns, ['answer_text']),
        'answer_normalized' => word_game_pick_column($columns, ['answer_normalized']),
        'answer_length' => word_game_pick_column($columns, ['answer_length']),
        'max_score' => word_game_pick_column($columns, ['max_score']),
        'letters_taken_count' => word_game_pick_column($columns, ['letters_taken_count']),
        'wrong_attempt_count' => word_game_pick_column($columns, ['wrong_attempt_count']),
        'earned_score' => word_game_pick_column($columns, ['earned_score']),
        'is_correct' => word_game_pick_column($columns, ['is_correct']),
        'is_completed' => word_game_pick_column($columns, ['is_completed']),
        'revealed_indexes_json' => word_game_pick_column($columns, ['revealed_indexes_json']),
        'submitted_answer' => word_game_pick_column($columns, ['submitted_answer']),
        'completed_at' => word_game_pick_column($columns, ['completed_at']),
        'created_at' => word_game_pick_column($columns, ['created_at'], false),
        'updated_at' => word_game_pick_column($columns, ['updated_at'], false),
    ];

    return $schema;
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

    $categoryIds = word_game_get_accessible_category_ids_for_qualification($pdo, $qualificationId);
    if (empty($categoryIds)) {
        throw new RuntimeException('Bu yeterlilik için aktif kelime oyunu başlığı bulunamadı.');
    }

    return word_game_pick_questions_from_category_pool($pdo, '', $qualificationId, $categoryIds);
}

function word_game_build_word_break_indexes(string $answerText): array
{
    $breaks = [];
    $logicalIndex = 0;
    $inWord = false;
    $length = mb_strlen($answerText, 'UTF-8');

    for ($i = 0; $i < $length; $i++) {
        $char = mb_substr($answerText, $i, 1, 'UTF-8');
        $normalized = word_game_normalize_answer($char);
        if ($normalized === '') {
            if ($inWord && $logicalIndex > 0) {
                $breaks[$logicalIndex] = true;
            }
            $inWord = false;
            continue;
        }

        $logicalIndex += max(1, mb_strlen($normalized, 'UTF-8'));
        $inWord = true;
    }

    unset($breaks[$logicalIndex]);
    $indexes = array_keys($breaks);
    sort($indexes, SORT_NUMERIC);

    return array_values(array_map('intval', $indexes));
}

function word_game_build_public_session_question(array $sessionQuestion): array
{
    return [
        'session_question_id' => (string)($sessionQuestion['session_question_id'] ?? $sessionQuestion['id'] ?? ''),
        'question_order' => (int)($sessionQuestion['question_order'] ?? 0),
        'question_text' => (string)($sessionQuestion['question_text'] ?? ''),
        'answer_length' => (int)($sessionQuestion['answer_length'] ?? 0),
        'max_score' => (int)($sessionQuestion['max_score'] ?? 0),
        'word_break_indexes' => array_values(array_map('intval', $sessionQuestion['word_break_indexes'] ?? word_game_build_word_break_indexes((string)($sessionQuestion['answer_text'] ?? '')))),
    ];
}

function word_game_question_max_score(int $answerLength, int $pointsPerChar = 10): int
{
    return max(0, $answerLength * max(1, $pointsPerChar));
}

function word_game_get_runtime_settings(PDO $pdo): array
{
    if (function_exists('word_game_get_settings')) {
        return word_game_get_settings($pdo);
    }

    return [
        'target_score' => 10000,
        'points_per_char' => 100,
        'min_questions' => 8,
        'max_questions' => 14,
        'duration_seconds' => 400,
        'allowed_lengths' => range(3, 24),
    ];
}

function word_game_get_allowed_lengths_from_settings(PDO $pdo): array
{
    $settings = word_game_get_runtime_settings($pdo);
    $allowed = array_values(array_unique(array_filter(
        array_map('intval', (array)($settings['allowed_lengths'] ?? [])),
        static fn(int $length): bool => $length > 0
    )));
    sort($allowed, SORT_NUMERIC);

    return $allowed;
}

function word_game_find_best_category_for_qualification(PDO $pdo, string $qualificationId): ?string
{
    $qualificationId = trim($qualificationId);
    if ($qualificationId === '') {
        return null;
    }

    foreach (word_game_get_accessible_category_ids_for_qualification($pdo, $qualificationId) as $categoryId) {
        try {
            word_game_pick_questions_for_category($pdo, '', $qualificationId, $categoryId);
            return $categoryId;
        } catch (Throwable $ignored) {
            word_game_debug_log('category candidate skipped', [
                'qualification_id' => $qualificationId,
                'category_id' => $categoryId,
                'error_class' => get_class($ignored),
            ]);
        }
    }

    return null;
}

function word_game_sort_pool_for_selection(array $pool): array
{
    foreach ($pool as &$row) {
        $row['_selection_random'] = random_int(0, PHP_INT_MAX);
    }
    unset($row);

    usort($pool, static function (array $a, array $b): int {
        $aSeen = isset($a['seen_count']) ? (int)$a['seen_count'] : 0;
        $bSeen = isset($b['seen_count']) ? (int)$b['seen_count'] : 0;
        if ($aSeen !== $bSeen) {
            return $aSeen <=> $bSeen;
        }

        $aLastSeen = (string)($a['last_seen_at'] ?? '');
        $bLastSeen = (string)($b['last_seen_at'] ?? '');
        if ($aLastSeen !== $bLastSeen) {
            if ($aLastSeen === '') {
                return -1;
            }
            if ($bLastSeen === '') {
                return 1;
            }
            return strcmp($aLastSeen, $bLastSeen);
        }

        return ((int)($a['_selection_random'] ?? 0)) <=> ((int)($b['_selection_random'] ?? 0));
    });

    foreach ($pool as &$row) {
        unset($row['_selection_random']);
    }
    unset($row);

    return $pool;
}

function word_game_find_exact_question_subset(array $pool, int $targetChars, int $minQ, int $maxQ, bool $alreadySorted = false): array
{
    $pool = array_values($alreadySorted ? $pool : word_game_sort_pool_for_selection($pool));
    if (empty($pool) || $targetChars <= 0 || $minQ > $maxQ) {
        return [];
    }

    // dp[soruSayisi][karakterToplami] = seçilen havuz indeksleri
    $dp = [0 => [0 => []]];
    foreach ($pool as $index => $row) {
        $length = max(0, (int)($row['answer_length'] ?? 0));
        if ($length <= 0 || $length > $targetChars) {
            continue;
        }

        for ($count = $maxQ - 1; $count >= 0; $count--) {
            if (!isset($dp[$count])) {
                continue;
            }
            $sums = array_keys($dp[$count]);
            rsort($sums, SORT_NUMERIC);
            foreach ($sums as $sum) {
                $newSum = (int)$sum + $length;
                $newCount = $count + 1;
                if ($newSum > $targetChars || isset($dp[$newCount][$newSum])) {
                    continue;
                }
                $path = $dp[$count][$sum];
                $path[] = $index;
                $dp[$newCount][$newSum] = $path;
            }
        }
    }

    for ($count = $minQ; $count <= $maxQ; $count++) {
        if (!isset($dp[$count][$targetChars])) {
            continue;
        }
        return array_values(array_map(
            static fn(int $index): array => $pool[$index],
            $dp[$count][$targetChars]
        ));
    }

    return [];
}

function word_game_get_accessible_category_ids_for_qualification(PDO $pdo, string $qualificationId): array
{
    $qualificationId = trim($qualificationId);
    if ($qualificationId === '') {
        return [];
    }

    $stmt = $pdo->prepare('SELECT c.id
                           FROM word_game_categories c
                           INNER JOIN word_game_category_qualifications cq ON cq.category_id = c.id
                           WHERE cq.qualification_id = ? AND c.is_active = 1
                           ORDER BY COALESCE(c.order_index, 0) ASC, c.name ASC');
    $stmt->execute([$qualificationId]);

    $ids = [];
    foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    return array_keys($ids);
}

function word_game_deduplicate_question_pool(array $pool): array
{
    $seenAnswers = [];
    $result = [];
    foreach ($pool as $row) {
        $normalized = trim((string)($row['answer_normalized'] ?? ''));
        if ($normalized === '' || isset($seenAnswers[$normalized])) {
            continue;
        }
        $seenAnswers[$normalized] = true;
        $result[] = $row;
    }
    return $result;
}

function word_game_pick_questions_from_category_pool(PDO $pdo, string $userId, string $qualificationId, array $categoryIds): array
{
    $qualificationId = trim($qualificationId);
    $userId = trim($userId);
    $categoryIds = array_values(array_unique(array_filter(array_map(
        static fn($id): string => trim((string)$id),
        $categoryIds
    ), static fn(string $id): bool => $id !== '')));

    if ($qualificationId === '' || empty($categoryIds)) {
        throw new RuntimeException('Bu yeterlilik için aktif kelime oyunu başlığı bulunamadı.');
    }

    $qSchema = word_game_questions_schema($pdo);
    if (empty($qSchema['category_id'])) {
        throw new RuntimeException('WORD_GAME_SCHEMA|category_id kolonu bulunamadı.');
    }

    $settings = word_game_get_runtime_settings($pdo);
    $pointsPerChar = max(1, (int)($settings['points_per_char'] ?? 100));
    $targetChars = (int)floor(max(1, (int)($settings['target_score'] ?? 10000)) / $pointsPerChar);
    $minQ = max(1, (int)($settings['min_questions'] ?? 8));
    $maxQ = max($minQ, (int)($settings['max_questions'] ?? 14));
    $allowed = word_game_get_allowed_lengths_from_settings($pdo);
    if (empty($allowed)) {
        throw new RuntimeException('Bu ayarlara uygun kelime oyunu oluşturulamadı.');
    }

    $categoryPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $lengthPlaceholders = implode(',', array_fill(0, count($allowed), '?'));
    $sql = 'SELECT q.`' . $qSchema['id'] . '` AS id,
                   q.`' . $qSchema['category_id'] . '` AS category_id,
                   q.`' . $qSchema['question_text'] . '` AS question_text,
                   q.`' . $qSchema['answer_text'] . '` AS answer_text,
                   q.`' . $qSchema['answer_normalized'] . '` AS answer_normalized,
                   q.`' . $qSchema['answer_length'] . '` AS answer_length,
                   COALESCE(h.seen_count, 0) AS seen_count,
                   h.last_seen_at,
                   COALESCE(c.order_index, 0) AS category_order,
                   COALESCE(q.order_index, 0) AS question_order
            FROM `' . $qSchema['table'] . '` q
            INNER JOIN word_game_categories c ON c.id = q.`' . $qSchema['category_id'] . '` AND c.is_active = 1
            LEFT JOIN word_game_user_question_history h
              ON h.user_id = ? AND h.word_game_question_id = q.`' . $qSchema['id'] . '`
            WHERE q.`' . $qSchema['category_id'] . '` IN (' . $categoryPlaceholders . ')
              AND q.`' . $qSchema['is_active'] . '` = 1
              AND q.`' . $qSchema['answer_length'] . '` IN (' . $lengthPlaceholders . ')
            ORDER BY COALESCE(h.seen_count, 0) ASC,
                     CASE WHEN h.last_seen_at IS NULL THEN 0 ELSE 1 END ASC,
                     h.last_seen_at ASC,
                     COALESCE(c.order_index, 0) ASC,
                     COALESCE(q.order_index, 0) ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId], $categoryIds, $allowed));
    $pool = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Aynı cevap birden fazla başlığa girilmiş olsa bile tek oturumda yalnızca bir kez kullanılabilir.
    $pool = word_game_sort_pool_for_selection($pool);
    $pool = word_game_deduplicate_question_pool($pool);
    $best = word_game_find_exact_question_subset($pool, $targetChars, $minQ, $maxQ, true);

    if (empty($best)) {
        word_game_debug_log('question pool selection failed', [
            'qualification_id' => $qualificationId,
            'category_ids' => $categoryIds,
            'target_chars' => $targetChars,
            'min_questions' => $minQ,
            'max_questions' => $maxQ,
            'allowed_lengths' => $allowed,
            'pool_size' => count($pool),
        ]);
        throw new RuntimeException('Bu yeterlilik için kelime oyunu oluşturulamadı. Başlık eşleştirmesi, aktif sorular ve karakter uzunluğu ayarlarını kontrol edin.');
    }

    word_game_debug_log('question pool selection success', [
        'qualification_id' => $qualificationId,
        'category_ids' => $categoryIds,
        'selected_question_count' => count($best),
        'selected_total_chars' => array_sum(array_map(static fn(array $row): int => (int)($row['answer_length'] ?? 0), $best)),
        'target_chars' => $targetChars,
    ]);

    return array_map(static function (array $row) use ($qualificationId): array {
        return [
            'id' => (string)$row['id'],
            'qualification_id' => $qualificationId,
            'category_id' => (string)($row['category_id'] ?? ''),
            'question_text' => (string)$row['question_text'],
            'answer_text' => (string)$row['answer_text'],
            'answer_normalized' => (string)$row['answer_normalized'],
            'answer_length' => (int)$row['answer_length'],
        ];
    }, $best);
}

function word_game_pick_questions_for_category(PDO $pdo, string $userId, string $qualificationId, string $categoryId): array
{
    $categoryId = trim($categoryId);
    if ($categoryId === '') {
        throw new InvalidArgumentException('category_id zorunludur.');
    }

    return word_game_pick_questions_from_category_pool($pdo, $userId, $qualificationId, [$categoryId]);
}

function word_game_calculate_server_remaining_seconds(array $session, ?int $durationSeconds = null): int
{
    $duration = $durationSeconds ?? (int)($session['remaining_seconds'] ?? word_game_session_initial_remaining_seconds());
    $duration = max(0, $duration);
    $startedAt = strtotime((string)($session['started_at'] ?? '')) ?: time();
    $elapsed = max(0, time() - $startedAt);

    return max(0, $duration - $elapsed);
}

function word_game_abandon_active_sessions_for_user_qualification(PDO $pdo, string $userId, string $qualificationId): void
{
    $schema = word_game_session_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT `' . $schema['id'] . '` AS id, `' . $schema['remaining_seconds'] . '` AS remaining_seconds, `' . $schema['started_at'] . '` AS started_at
         FROM `' . $schema['table'] . '`
         WHERE `' . $schema['user_id'] . '` = ?
           AND `' . $schema['qualification_id'] . '` = ?
           AND `' . $schema['status'] . '` = \'active\'
         FOR UPDATE'
    );
    $stmt->execute([$userId, $qualificationId]);
    $activeSessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($activeSessions)) {
        return;
    }

    $set = [
        '`' . $schema['status'] . '` = \'abandoned\'',
        '`' . $schema['total_score'] . '` = 0',
        '`' . $schema['remaining_seconds'] . '` = ?',
        '`' . $schema['finished_at'] . '` = NOW()',
    ];
    if ($schema['updated_at']) {
        $set[] = '`' . $schema['updated_at'] . '` = NOW()';
    }
    $update = $pdo->prepare('UPDATE `' . $schema['table'] . '` SET ' . implode(', ', $set) . ' WHERE `' . $schema['id'] . '` = ?');
    foreach ($activeSessions as $session) {
        $update->execute([word_game_calculate_server_remaining_seconds($session), (string)$session['id']]);
    }

    word_game_debug_log('active sessions abandoned before new game', [
        'user_id' => $userId,
        'qualification_id' => $qualificationId,
        'session_count' => count($activeSessions),
    ]);
}

function word_game_mark_session_question_seen_if_first_interaction(PDO $pdo, array $lockedSessionQuestion, string $userId): void
{
    $questionId = trim((string)($lockedSessionQuestion['word_game_question_id'] ?? ''));
    if ($questionId === '' || trim($userId) === '') {
        return;
    }

    $revealed = json_decode((string)($lockedSessionQuestion['revealed_indexes_json'] ?? '[]'), true);
    $alreadyInteracted = (int)($lockedSessionQuestion['letters_taken_count'] ?? 0) > 0
        || (int)($lockedSessionQuestion['wrong_attempt_count'] ?? 0) > 0
        || (int)($lockedSessionQuestion['is_completed'] ?? 0) === 1
        || trim((string)($lockedSessionQuestion['submitted_answer'] ?? '')) !== ''
        || (is_array($revealed) && count($revealed) > 0);
    if ($alreadyInteracted) {
        return;
    }

    $hist = $pdo->prepare('INSERT INTO word_game_user_question_history (id, user_id, word_game_question_id, seen_count, last_seen_at, created_at, updated_at)
                           VALUES (?, ?, ?, 1, NOW(), NOW(), NOW())
                           ON DUPLICATE KEY UPDATE seen_count = seen_count + 1, last_seen_at = NOW(), updated_at = NOW()');
    $hist->execute([function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16)), $userId, $questionId]);
}

function word_game_session_create(PDO $pdo, string $userId, string $qualificationId, array $questions): array
{
    $userId = trim($userId);
    $qualificationId = trim($qualificationId);

    if ($userId === '' || $qualificationId === '') {
        throw new InvalidArgumentException('user_id ve qualification_id zorunludur.');
    }

    $settings = word_game_get_runtime_settings($pdo);
    $minQ = max(1, (int)($settings['min_questions'] ?? 8));
    $maxQ = max($minQ, (int)($settings['max_questions'] ?? 14));
    if (count($questions) < $minQ || count($questions) > $maxQ) {
        throw new InvalidArgumentException('Oyun için soru sayısı ayarlara uygun değil.');
    }

    $sessionId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
    $remainingSeconds = max(30, (int)($settings['duration_seconds'] ?? 400));
    $pointsPerChar = max(1, (int)($settings['points_per_char'] ?? 100));
    $schema = word_game_session_schema($pdo);

    $sqSchema = word_game_session_questions_schema($pdo);

    $pdo->beginTransaction();
    try {
        word_game_abandon_active_sessions_for_user_qualification($pdo, $userId, $qualificationId);

        $payload = [
            'id' => $sessionId,
            'user_id' => $userId,
            'qualification_id' => $qualificationId,
            'total_score' => 0,
            'remaining_seconds' => $remainingSeconds,
            'total_questions' => count($questions),
            'completed_questions' => 0,
            'correct_questions' => 0,
            'wrong_questions' => 0,
            'total_letters_taken' => 0,
            'status' => 'active',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
        ];

        word_game_debug_log('session insert payload', $payload);
        word_game_debug_log('remaining_seconds set to 400', ['remaining_seconds' => $remainingSeconds]);

        $columns = [
            $schema['id'],
            $schema['user_id'],
            $schema['qualification_id'],
            $schema['total_score'],
            $schema['remaining_seconds'],
            $schema['total_questions'],
            $schema['completed_questions'],
            $schema['correct_questions'],
            $schema['wrong_questions'],
            $schema['total_letters_taken'],
            $schema['status'],
            $schema['started_at'],
            $schema['finished_at'],
        ];

        if ($schema['created_at']) {
            $columns[] = $schema['created_at'];
        }
        if ($schema['updated_at']) {
            $columns[] = $schema['updated_at'];
        }

        $colSql = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
        $values = [
            $payload['id'],
            $payload['user_id'],
            $payload['qualification_id'],
            $payload['total_score'],
            $payload['remaining_seconds'],
            $payload['total_questions'],
            $payload['completed_questions'],
            $payload['correct_questions'],
            $payload['wrong_questions'],
            $payload['total_letters_taken'],
            $payload['status'],
            $payload['started_at'],
            $payload['finished_at'],
        ];

        $placeholdersArr = array_fill(0, count($values), '?');
        if ($schema['created_at']) {
            $placeholdersArr[] = 'NOW()';
        }
        if ($schema['updated_at']) {
            $placeholdersArr[] = 'NOW()';
        }
        $placeholders = implode(', ', $placeholdersArr);

        $stmt = $pdo->prepare('INSERT INTO `' . $schema['table'] . '` (' . $colSql . ') VALUES (' . $placeholders . ')');
        $stmt->execute($values);

        $createdQuestions = [];
        foreach (array_values($questions) as $idx => $question) {
            $sessionQuestionId = function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
            $answerLength = (int)($question['answer_length'] ?? 0);
            $maxScore = word_game_question_max_score($answerLength, $pointsPerChar);
            $questionOrder = $idx + 1;
            $snapshotAnswerText = (string)($question['answer_text'] ?? '');
            $snapshotAnswerNormalized = word_game_normalize_answer((string)($question['answer_normalized'] ?? $question['answer_text'] ?? ''));

            word_game_debug_log('session question snapshot prepared', [
                'session_id' => $sessionId,
                'question_id' => (string)($question['id'] ?? ''),
                'question_order' => $questionOrder,
                'answer_length' => $answerLength,
            ]);

            $snapshot = [
                $sqSchema['id'] => $sessionQuestionId,
                $sqSchema['session_id'] => $sessionId,
                $sqSchema['word_game_question_id'] => (string)$question['id'],
                $sqSchema['question_order'] => $questionOrder,
                $sqSchema['question_text'] => (string)($question['question_text'] ?? ''),
                $sqSchema['answer_text'] => $snapshotAnswerText,
                $sqSchema['answer_normalized'] => $snapshotAnswerNormalized,
                $sqSchema['answer_length'] => $answerLength,
                $sqSchema['max_score'] => $maxScore,
                $sqSchema['letters_taken_count'] => 0,
                $sqSchema['wrong_attempt_count'] => 0,
                $sqSchema['earned_score'] => 0,
                $sqSchema['is_correct'] => 0,
                $sqSchema['is_completed'] => 0,
                $sqSchema['revealed_indexes_json'] => '[]',
                $sqSchema['submitted_answer'] => null,
                $sqSchema['completed_at'] => null,
            ];

            $columnsSq = array_keys($snapshot);
            $valuesSq = array_values($snapshot);
            $placeholdersSq = implode(', ', array_fill(0, count($valuesSq), '?'));

            if ($sqSchema['created_at']) {
                $columnsSq[] = $sqSchema['created_at'];
                $placeholdersSq .= ', NOW()';
            }
            if ($sqSchema['updated_at']) {
                $columnsSq[] = $sqSchema['updated_at'];
                $placeholdersSq .= ', NOW()';
            }

            $insertSqSql = 'INSERT INTO `' . $sqSchema['table'] . '` ('
                . implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columnsSq))
                . ') VALUES (' . $placeholdersSq . ')';
            $insertSqStmt = $pdo->prepare($insertSqSql);
            $insertSqStmt->execute($valuesSq);

            $createdQuestions[] = word_game_build_public_session_question([
                'session_question_id' => $sessionQuestionId,
                'question_order' => $questionOrder,
                'question_text' => (string)($question['question_text'] ?? ''),
                'answer_length' => $answerLength,
                'max_score' => $maxScore,
                'answer_text' => $snapshotAnswerText,
            ]);
        }

        $pdo->commit();

        word_game_debug_log('session created', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'qualification_id' => $qualificationId,
            'question_count' => count($createdQuestions),
        ]);

        word_game_debug_log('final question order with answer_length', [
            'session_id' => $sessionId,
            'items' => array_values(array_map(
                static fn(array $q): array => [
                    'question_order' => (int)($q['question_order'] ?? 0),
                    'answer_length' => (int)($q['answer_length'] ?? 0),
                    'session_question_id' => (string)($q['session_question_id'] ?? ''),
                ],
                $createdQuestions
            )),
        ]);

        return [
            'session_id' => $sessionId,
            'qualification_id' => $qualificationId,
            'duration_seconds' => $remainingSeconds,
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
    $schema = word_game_session_schema($pdo);

    $selectKeys = [
        'id', 'user_id', 'qualification_id', 'total_score', 'remaining_seconds',
        'total_questions', 'completed_questions', 'correct_questions', 'wrong_questions',
        'total_letters_taken', 'status', 'started_at', 'finished_at',
    ];
    $selectSql = implode(', ', array_map(static fn(string $key): string => '`' . $schema[$key] . '` AS `' . $key . '`', $selectKeys));

    $stmt = $pdo->prepare(
        'SELECT ' . $selectSql
        . ' FROM `' . $schema['table'] . '`'
        . ' WHERE `' . $schema['id'] . '` = ? AND `' . $schema['user_id'] . '` = ? LIMIT 1'
    );
    $stmt->execute([trim($sessionId), trim($userId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_find_session_for_update(PDO $pdo, string $sessionId, string $userId): ?array
{
    $schema = word_game_session_schema($pdo);

    $selectKeys = [
        'id', 'user_id', 'qualification_id', 'total_score', 'remaining_seconds',
        'total_questions', 'completed_questions', 'correct_questions', 'wrong_questions',
        'total_letters_taken', 'status', 'started_at', 'finished_at',
    ];
    $selectSql = implode(', ', array_map(static fn(string $key): string => '`' . $schema[$key] . '` AS `' . $key . '`', $selectKeys));

    $stmt = $pdo->prepare(
        'SELECT ' . $selectSql
        . ' FROM `' . $schema['table'] . '`'
        . ' WHERE `' . $schema['id'] . '` = ? AND `' . $schema['user_id'] . '` = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([trim($sessionId), trim($userId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_find_session_question(PDO $pdo, string $sessionQuestionId, string $sessionId): ?array
{
    $sqSchema = word_game_session_questions_schema($pdo);

    $select = [
        '`' . $sqSchema['id'] . '` AS id',
        '`' . $sqSchema['session_id'] . '` AS session_id',
        '`' . $sqSchema['word_game_question_id'] . '` AS word_game_question_id',
        '`' . $sqSchema['question_order'] . '` AS question_order',
        '`' . $sqSchema['question_text'] . '` AS question_text',
        '`' . $sqSchema['answer_text'] . '` AS answer_text',
        '`' . $sqSchema['answer_normalized'] . '` AS answer_normalized',
        '`' . $sqSchema['answer_length'] . '` AS answer_length',
        '`' . $sqSchema['max_score'] . '` AS max_score',
        '`' . $sqSchema['letters_taken_count'] . '` AS letters_taken_count',
        '`' . $sqSchema['wrong_attempt_count'] . '` AS wrong_attempt_count',
        '`' . $sqSchema['earned_score'] . '` AS earned_score',
        '`' . $sqSchema['is_correct'] . '` AS is_correct',
        '`' . $sqSchema['is_completed'] . '` AS is_completed',
        '`' . $sqSchema['revealed_indexes_json'] . '` AS revealed_indexes_json',
        '`' . $sqSchema['submitted_answer'] . '` AS submitted_answer',
        '`' . $sqSchema['completed_at'] . '` AS completed_at',
    ];

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . '
         FROM `' . $sqSchema['table'] . '`
         WHERE `' . $sqSchema['id'] . '` = ?
           AND `' . $sqSchema['session_id'] . '` = ?
         LIMIT 1'
    );
    $stmt->execute([trim($sessionQuestionId), trim($sessionId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_find_session_question_for_update(PDO $pdo, string $sessionQuestionId, string $sessionId): ?array
{
    $sqSchema = word_game_session_questions_schema($pdo);

    $select = [
        '`' . $sqSchema['id'] . '` AS id',
        '`' . $sqSchema['session_id'] . '` AS session_id',
        '`' . $sqSchema['word_game_question_id'] . '` AS word_game_question_id',
        '`' . $sqSchema['question_order'] . '` AS question_order',
        '`' . $sqSchema['question_text'] . '` AS question_text',
        '`' . $sqSchema['answer_text'] . '` AS answer_text',
        '`' . $sqSchema['answer_normalized'] . '` AS answer_normalized',
        '`' . $sqSchema['answer_length'] . '` AS answer_length',
        '`' . $sqSchema['max_score'] . '` AS max_score',
        '`' . $sqSchema['letters_taken_count'] . '` AS letters_taken_count',
        '`' . $sqSchema['wrong_attempt_count'] . '` AS wrong_attempt_count',
        '`' . $sqSchema['earned_score'] . '` AS earned_score',
        '`' . $sqSchema['is_correct'] . '` AS is_correct',
        '`' . $sqSchema['is_completed'] . '` AS is_completed',
        '`' . $sqSchema['revealed_indexes_json'] . '` AS revealed_indexes_json',
        '`' . $sqSchema['submitted_answer'] . '` AS submitted_answer',
        '`' . $sqSchema['completed_at'] . '` AS completed_at',
    ];

    $stmt = $pdo->prepare(
        'SELECT ' . implode(', ', $select) . '
         FROM `' . $sqSchema['table'] . '`
         WHERE `' . $sqSchema['id'] . '` = ?
           AND `' . $sqSchema['session_id'] . '` = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([trim($sessionQuestionId), trim($sessionId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function word_game_build_answer_text_logical_map(string $answerText, string $answerNormalized): array
{
    $map = [];
    $logicalIndex = 0;
    $textLength = mb_strlen($answerText, 'UTF-8');

    for ($i = 0; $i < $textLength; $i++) {
        $char = mb_substr($answerText, $i, 1, 'UTF-8');
        $normalizedChar = word_game_normalize_answer($char);
        if ($normalizedChar === '') {
            continue;
        }

        $normalizedCharLength = mb_strlen($normalizedChar, 'UTF-8');
        for ($j = 0; $j < $normalizedCharLength; $j++) {
            $map[$logicalIndex++] = $char;
        }
    }

    $normalizedLength = mb_strlen($answerNormalized, 'UTF-8');
    if (count($map) > $normalizedLength) {
        $map = array_slice($map, 0, $normalizedLength, true);
    }

    return array_values($map);
}

function word_game_normalize_revealed_indexes(array $revealedIndexes, int $answerLength): array
{
    $normalized = array_values(array_unique(array_map('intval', $revealedIndexes)));
    sort($normalized, SORT_NUMERIC);

    return array_values(array_filter(
        $normalized,
        static fn(int $index): bool => $index >= 0 && $index < $answerLength
    ));
}

function word_game_reveal_letter(PDO $pdo, array $sessionQuestion): array
{
    $sqSchema = word_game_session_questions_schema($pdo);
    $sessionQuestionId = (string)($sessionQuestion['id'] ?? '');
    $answerText = (string)($sessionQuestion['answer_text'] ?? '');
    $answerRaw = (string)($sessionQuestion['answer_normalized'] ?? $answerText);
    $answerNormalized = word_game_normalize_answer($answerRaw);
    $answerLength = (int)($sessionQuestion['answer_length'] ?? mb_strlen($answerNormalized, 'UTF-8'));
    $settings = word_game_get_runtime_settings($pdo);
    $pointsPerChar = max(1, (int)($settings['points_per_char'] ?? 100));
    $maxScore = (int)($sessionQuestion['max_score'] ?? word_game_question_max_score($answerLength, $pointsPerChar));

    if ($sessionQuestionId === '' || $answerLength <= 0 || $answerNormalized === '') {
        throw new RuntimeException('Soru verisi okunamadı.');
    }

    if ((int)($sessionQuestion['is_completed'] ?? 0) === 1) {
        throw new RuntimeException('Tamamlanan soruda harf açılamaz.');
    }

    $revealedIndexesJsonBefore = (string)($sessionQuestion['revealed_indexes_json'] ?? '[]');
    $revealedIndexes = json_decode($revealedIndexesJsonBefore, true);
    if (!is_array($revealedIndexes)) {
        $revealedIndexes = [];
    }
    $revealedIndexes = word_game_normalize_revealed_indexes($revealedIndexes, $answerLength);

    $answerTextLogicalMap = word_game_build_answer_text_logical_map($answerText, $answerNormalized);
    if (count($answerTextLogicalMap) !== $answerLength) {
        throw new RuntimeException('Cevap harf eşlemesi oluşturulamadı.');
    }

    $availableIndexes = [];
    for ($i = 0; $i < $answerLength; $i++) {
        if (!in_array($i, $revealedIndexes, true)) {
            $availableIndexes[] = $i;
        }
    }

    if (empty($availableIndexes)) {
        throw new RuntimeException('Açılacak harf kalmadı.');
    }

    $pickedPosition = random_int(0, count($availableIndexes) - 1);
    $revealedLogicalIndex = (int)$availableIndexes[$pickedPosition];
    $revealedIndexes[] = $revealedLogicalIndex;
    sort($revealedIndexes, SORT_NUMERIC);

    $lettersTakenCount = (int)($sessionQuestion['letters_taken_count'] ?? 0) + 1;
    $remainingScore = max(0, $maxScore - ($lettersTakenCount * $pointsPerChar));
    $revealedLetter = (string)($answerTextLogicalMap[$revealedLogicalIndex] ?? '');
    $revealedLetterNormalized = word_game_normalize_answer($revealedLetter);

    if ($revealedLetter === '') {
        throw new RuntimeException('Açılan harf üretilemedi.');
    }

    $revealedIndexesJsonAfter = json_encode($revealedIndexes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $updateParts = [
        '`' . $sqSchema['revealed_indexes_json'] . '` = ?',
        '`' . $sqSchema['letters_taken_count'] . '` = ?',
        '`' . $sqSchema['earned_score'] . '` = ?',
    ];
    if ($sqSchema['updated_at']) {
        $updateParts[] = '`' . $sqSchema['updated_at'] . '` = NOW()';
    }

    $updateStmt = $pdo->prepare(
        'UPDATE `' . $sqSchema['table'] . '`
         SET ' . implode(', ', $updateParts) . '
         WHERE `' . $sqSchema['id'] . '` = ?'
    );
    $updateStmt->execute([
        $revealedIndexesJsonAfter,
        $lettersTakenCount,
        $remainingScore,
        $sessionQuestionId,
    ]);

    word_game_debug_log('reveal letter index', [
        'transaction_started' => $pdo->inTransaction(),
        'locked_session_question_id' => $sessionQuestionId,
        'session_question_id' => $sessionQuestionId,
        'answer_text' => $answerText,
        'answer_normalized' => $answerNormalized,
        'revealed_logical_index' => $revealedLogicalIndex,
        'revealed_letter' => $revealedLetter,
        'revealed_letter_normalized' => $revealedLetterNormalized,
        'revealed_index' => $revealedLogicalIndex,
        'revealed_index_legacy_1_based' => $revealedLogicalIndex + 1,
        'revealed_indexes_json_before' => $revealedIndexesJsonBefore,
        'revealed_indexes_json_after' => $revealedIndexesJsonAfter,
        'letters_taken_count' => $lettersTakenCount,
        'remaining_question_score' => $remainingScore,
    ]);

    return [
        'revealed_index' => $revealedLogicalIndex,
        'revealed_logical_index' => $revealedLogicalIndex,
        'revealed_index_legacy_1_based' => $revealedLogicalIndex + 1,
        'revealed_letter' => $revealedLetter,
        'revealed_letter_normalized' => $revealedLetterNormalized,
        'letters_taken_count' => $lettersTakenCount,
        'remaining_question_score' => $remainingScore,
        'revealed_indexes' => $revealedIndexes,
    ];
}

function word_game_check_answer(PDO $pdo, array $sessionQuestion, string $submittedAnswer): array
{
    $sqSchema = word_game_session_questions_schema($pdo);
    $sessionQuestionId = (string)($sessionQuestion['id'] ?? '');
    $answerRaw = (string)($sessionQuestion['answer_normalized'] ?? $sessionQuestion['answer_text'] ?? '');
    $correctAnswer = word_game_normalize_answer($answerRaw);
    $submittedNormalized = word_game_normalize_answer($submittedAnswer);
    $lettersTakenCount = (int)($sessionQuestion['letters_taken_count'] ?? 0);
    $sourceLength = (int)($sessionQuestion['answer_length'] ?? mb_strlen($correctAnswer, 'UTF-8'));
    $settings = word_game_get_runtime_settings($pdo);
    $pointsPerChar = max(1, (int)($settings['points_per_char'] ?? 100));
    $maxScore = (int)($sessionQuestion['max_score'] ?? word_game_question_max_score($sourceLength, $pointsPerChar));
    $wrongAttemptCount = (int)($sessionQuestion['wrong_attempt_count'] ?? 0);

    if ($sessionQuestionId === '' || $correctAnswer === '') {
        throw new RuntimeException('Soru verisi okunamadı.');
    }

    $isCorrect = ($submittedNormalized !== '' && $submittedNormalized === $correctAnswer);

    if ($isCorrect) {
        $earnedScore = max(0, $maxScore - ($lettersTakenCount * $pointsPerChar));

        $set = [
            '`' . $sqSchema['is_correct'] . '` = 1',
            '`' . $sqSchema['is_completed'] . '` = 1',
            '`' . $sqSchema['completed_at'] . '` = NOW()',
            '`' . $sqSchema['earned_score'] . '` = ?',
            '`' . $sqSchema['submitted_answer'] . '` = ?',
        ];
        if ($sqSchema['updated_at']) {
            $set[] = '`' . $sqSchema['updated_at'] . '` = NOW()';
        }
        $stmt = $pdo->prepare(
            'UPDATE `' . $sqSchema['table'] . '`
             SET ' . implode(', ', $set) . '
             WHERE `' . $sqSchema['id'] . '` = ?'
        );
        $stmt->execute([$earnedScore, $submittedAnswer, $sessionQuestionId]);

        $result = [
            'is_correct' => true,
            'earned_score' => $earnedScore,
            'wrong_attempt_count' => $wrongAttemptCount,
            'remaining_attempts' => max(0, 1 - $wrongAttemptCount),
            'question_completed' => true,
            'answer_reveal' => (string)($sessionQuestion['answer_text'] ?? ''),
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
        $set = [
            '`' . $sqSchema['wrong_attempt_count'] . '` = ?',
            '`' . $sqSchema['is_correct'] . '` = 0',
            '`' . $sqSchema['is_completed'] . '` = 1',
            '`' . $sqSchema['completed_at'] . '` = NOW()',
            '`' . $sqSchema['earned_score'] . '` = 0',
            '`' . $sqSchema['submitted_answer'] . '` = ?',
        ];
        if ($sqSchema['updated_at']) {
            $set[] = '`' . $sqSchema['updated_at'] . '` = NOW()';
        }
        $stmt = $pdo->prepare(
            'UPDATE `' . $sqSchema['table'] . '`
             SET ' . implode(', ', $set) . '
             WHERE `' . $sqSchema['id'] . '` = ?'
        );
        $stmt->execute([$wrongAttemptCount, $submittedAnswer, $sessionQuestionId]);
    } else {
        $set = [
            '`' . $sqSchema['wrong_attempt_count'] . '` = ?',
            '`' . $sqSchema['submitted_answer'] . '` = ?',
        ];
        if ($sqSchema['updated_at']) {
            $set[] = '`' . $sqSchema['updated_at'] . '` = NOW()';
        }
        $stmt = $pdo->prepare(
            'UPDATE `' . $sqSchema['table'] . '`
             SET ' . implode(', ', $set) . '
             WHERE `' . $sqSchema['id'] . '` = ?'
        );
        $stmt->execute([$wrongAttemptCount, $submittedAnswer, $sessionQuestionId]);
    }

    $result = [
        'is_correct' => false,
        'wrong_attempt_count' => $wrongAttemptCount,
        'remaining_attempts' => $remainingAttempts,
        'question_completed' => $isCompleted,
    ];
    if ($isCompleted) {
        $result['answer_reveal'] = (string)($sessionQuestion['answer_text'] ?? '');
    }

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

    $sqSchema = word_game_session_questions_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN `' . $sqSchema['is_completed'] . '` = 1 THEN 1 ELSE 0 END), 0) AS completed_questions,
            COALESCE(SUM(CASE WHEN `' . $sqSchema['is_completed'] . '` = 1 AND `' . $sqSchema['is_correct'] . '` = 1 THEN 1 ELSE 0 END), 0) AS correct_questions,
            COALESCE(SUM(CASE WHEN `' . $sqSchema['is_completed'] . '` = 1 AND `' . $sqSchema['is_correct'] . '` = 0 THEN 1 ELSE 0 END), 0) AS wrong_questions,
            COALESCE(SUM(COALESCE(`' . $sqSchema['letters_taken_count'] . '`, 0)), 0) AS total_letters_taken,
            COALESCE(SUM(COALESCE(`' . $sqSchema['earned_score'] . '`, 0)), 0) AS total_score
         FROM `' . $sqSchema['table'] . '`
         WHERE `' . $sqSchema['session_id'] . '` = ?'
    );
    $stmt->execute([$sessionId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $schema = word_game_session_schema($pdo);
    $update = $pdo->prepare(
        'UPDATE `' . $schema['table'] . '`
         SET `' . $schema['completed_questions'] . '` = ?,
             `' . $schema['correct_questions'] . '` = ?,
             `' . $schema['wrong_questions'] . '` = ?,
             `' . $schema['total_letters_taken'] . '` = ?,
             `' . $schema['total_score'] . '` = ?
         WHERE `' . $schema['id'] . '` = ?'
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

function word_game_finish_session(PDO $pdo, string $sessionId, string $userId, string $status): array
{
    $sessionId = trim($sessionId);
    $userId = trim($userId);
    $status = strtolower(trim($status));

    if (!in_array($status, ['completed', 'abandoned', 'timeout'], true)) {
        throw new InvalidArgumentException('status geçersiz.');
    }

    $schema = word_game_session_schema($pdo);
    $pdo->beginTransaction();
    try {
        $session = word_game_find_session_for_update($pdo, $sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Oturum bulunamadı.');
        }
        if ((string)($session['status'] ?? '') !== 'active') {
            throw new RuntimeException('Bu oturum zaten tamamlanmış.');
        }

        word_game_refresh_session_totals($pdo, $sessionId);
        $session = word_game_find_session_for_update($pdo, $sessionId, $userId);
        if (!$session) {
            throw new RuntimeException('Oturum bulunamadı.');
        }

        $initialRemaining = max(0, (int)($session['remaining_seconds'] ?? word_game_session_initial_remaining_seconds()));
        $timeStmt = $pdo->prepare('SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, `' . $schema['started_at'] . '`, NOW())) FROM `' . $schema['table'] . '` WHERE `' . $schema['id'] . '` = ? LIMIT 1');
        $timeStmt->execute([$initialRemaining, $sessionId]);
        $serverRemaining = max(0, (int)$timeStmt->fetchColumn());

        $completedQuestions = (int)($session['completed_questions'] ?? 0);
        $totalQuestions = (int)($session['total_questions'] ?? 0);
        $finalStatus = 'abandoned';
        if ($serverRemaining <= 0) {
            $finalStatus = 'timeout';
        } elseif ($status === 'abandoned') {
            $finalStatus = 'abandoned';
        } elseif ($status === 'completed' && $totalQuestions > 0 && $completedQuestions >= $totalQuestions) {
            $finalStatus = 'completed';
        }

        $totalScore = ($finalStatus === 'abandoned') ? 0 : (int)($session['total_score'] ?? 0);
        $set = [
            '`' . $schema['status'] . '` = ?',
            '`' . $schema['remaining_seconds'] . '` = ?',
            '`' . $schema['total_score'] . '` = ?',
            '`' . $schema['finished_at'] . '` = NOW()',
        ];
        if ($schema['updated_at']) {
            $set[] = '`' . $schema['updated_at'] . '` = NOW()';
        }

        $stmt = $pdo->prepare(
            'UPDATE `' . $schema['table'] . '`
             SET ' . implode(', ', $set) . '
             WHERE `' . $schema['id'] . '` = ?
               AND `' . $schema['user_id'] . '` = ?'
        );
        $stmt->execute([$finalStatus, $serverRemaining, $totalScore, $sessionId, $userId]);

        $updated = word_game_find_session_for_update($pdo, $sessionId, $userId);
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

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    word_game_debug_log('finish session result', [
        'session_id' => $sessionId,
        'user_id' => $userId,
        'status' => $result['status'],
        'total_score' => $result['total_score'],
        'remaining_seconds' => $result['remaining_seconds'],
    ]);

    return $result;
}

function word_game_get_leaderboard(PDO $pdo, string $qualificationId, int $limit = 50): array
{
    $qualificationId = trim($qualificationId);
    $limit = max(1, min(100, (int)$limit));
    $schema = word_game_session_schema($pdo);

    $sql = 'SELECT s.`' . $schema['id'] . '` AS id,
                   s.`' . $schema['user_id'] . '` AS user_id,
                   s.`' . $schema['total_score'] . '` AS total_score,
                   s.`' . $schema['remaining_seconds'] . '` AS remaining_seconds,
                   s.`' . $schema['finished_at'] . '` AS finished_at,
                   COALESCE(NULLIF(TRIM(u.full_name), \'\'), NULLIF(TRIM(u.email), \'\'), s.`' . $schema['user_id'] . '`) AS display_name
            FROM `' . $schema['table'] . '` s
            LEFT JOIN user_profiles u ON u.id = s.`' . $schema['user_id'] . '`
            WHERE s.`' . $schema['qualification_id'] . '` = ?
              AND s.`' . $schema['status'] . '` IN (\'completed\', \'timeout\')
              AND s.`' . $schema['finished_at'] . '` IS NOT NULL
            ORDER BY s.`' . $schema['total_score'] . '` DESC,
                     s.`' . $schema['remaining_seconds'] . '` DESC,
                     s.`' . $schema['finished_at'] . '` ASC
            LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$qualificationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $idx => $row) {
        $userId = (string)($row['user_id'] ?? '');
        $items[] = [
            'rank' => $idx + 1,
            'user_id' => $userId,
            'display_name' => (string)($row['display_name'] ?? $userId),
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

if (!function_exists('word_game_normalize_answer')) {
    function word_game_normalize_answer(string $answer): string
    {
        $value = trim($answer);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = strtr($value, [
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'I' => 'I', 'İ' => 'I', 'i' => 'i',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
        ]);
        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^A-Z]+/u', '', $value) ?? '';

        return trim($value);
    }
}

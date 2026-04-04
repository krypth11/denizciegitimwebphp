<?php

require_once __DIR__ . '/word_game_question_helper.php';

if (!defined('WORD_GAME_FORCE_DEBUG')) {
    define('WORD_GAME_FORCE_DEBUG', true);
}

function word_game_debug_log(string $stage, array $context = []): void
{
    $line = '[word_game][' . $stage . '] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
    if (defined('WORD_GAME_FORCE_DEBUG') && WORD_GAME_FORCE_DEBUG === true) {
        return true;
    }

    $flags = [
        getenv('APP_DEBUG'),
        getenv('DEBUG'),
        ini_get('display_errors'),
    ];

    foreach ($flags as $flag) {
        if ($flag === null || $flag === false) {
            continue;
        }

        $normalized = strtolower(trim((string)$flag));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
    }

    return false;
}

function word_game_build_error_response(string $message, Throwable $e): array
{
    $errorMessage = (string)$e->getMessage();

    $firstTrace = $e->getTrace()[0] ?? [];
    $traceHint = '';
    if (is_array($firstTrace) && !empty($firstTrace)) {
        $traceHint = trim((string)(
            ($firstTrace['class'] ?? '')
            . ($firstTrace['type'] ?? '')
            . ($firstTrace['function'] ?? '')
        ));
    }

    $response = [
        'success' => false,
        'message' => $message,
        'error' => $errorMessage,
        'data' => null,
    ];

    if (word_game_is_debug_enabled()) {
        $response['debug'] = [
            'error' => $errorMessage,
            'message' => $errorMessage,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace_hint' => $traceHint,
        ];
    }

    return $response;
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
        'qualification_id' => word_game_pick_column($columns, ['qualification_id']),
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

    $qSchema = word_game_questions_schema($pdo);
    $distribution = word_game_required_distribution();
    $insufficient = [];
    $selected = [];
    $selectedCountByLength = [];
    $pickIndex = 0;

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM `' . $qSchema['table'] . '`
         WHERE `' . $qSchema['qualification_id'] . '` = ?
           AND `' . $qSchema['is_active'] . '` = 1
           AND `' . $qSchema['answer_length'] . '` = ?'
    );

    foreach ($distribution as $answerLength => $requiredCount) {
        $countStmt->execute([$qualificationId, (int)$answerLength]);
        $available = (int)$countStmt->fetchColumn();

        word_game_debug_log('word game length pool count', [
            'qualification_id' => $qualificationId,
            'answer_length' => (int)$answerLength,
            'required_count' => (int)$requiredCount,
            'available_count' => $available,
        ]);

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
        $finalLimit = (int)$requiredCount;
        $sql = 'SELECT `' . $qSchema['id'] . '` AS id,
                       `' . $qSchema['qualification_id'] . '` AS qualification_id,
                       `' . $qSchema['question_text'] . '` AS question_text,
                       `' . $qSchema['answer_text'] . '` AS answer_text,
                       `' . $qSchema['answer_normalized'] . '` AS answer_normalized,
                       `' . $qSchema['answer_length'] . '` AS answer_length
                FROM `' . $qSchema['table'] . '`
                WHERE `' . $qSchema['qualification_id'] . '` = ?
                  AND `' . $qSchema['is_active'] . '` = 1
                  AND `' . $qSchema['answer_length'] . '` = ?
                ORDER BY RAND()
                LIMIT ' . $finalLimit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$qualificationId, (int)$answerLength]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $selectedCountByLength[(string)$answerLength] = count($rows);

        word_game_debug_log('word game length selection result', [
            'qualification_id' => $qualificationId,
            'answer_length' => (int)$answerLength,
            'required_count' => (int)$requiredCount,
            'selected_count' => count($rows),
            'final_sql_limit' => $finalLimit,
        ]);

        word_game_debug_log('selected question ids by length', [
            'qualification_id' => $qualificationId,
            'answer_length' => (int)$answerLength,
            'question_ids' => array_values(array_map(
                static fn(array $r): string => (string)($r['id'] ?? ''),
                $rows
            )),
        ]);

        foreach ($rows as $row) {
            $selected[] = [
                'id' => (string)$row['id'],
                'qualification_id' => (string)$row['qualification_id'],
                'question_text' => (string)$row['question_text'],
                'answer_text' => (string)$row['answer_text'],
                'answer_normalized' => (string)$row['answer_normalized'],
                'answer_length' => (int)$row['answer_length'],
                '_pick_index' => $pickIndex++,
            ];
        }
    }

    if (count($selected) !== 10) {
        $details = [
            'expected_total' => 10,
            'actual_total' => count($selected),
            'selected_counts_by_length' => $selectedCountByLength,
        ];
        throw new RuntimeException(
            'WORD_GAME_SELECTION_MISMATCH|' . json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    word_game_debug_log('selected questions before sort', [
        'qualification_id' => $qualificationId,
        'items' => array_values(array_map(
            static fn(array $q): array => [
                'id' => (string)($q['id'] ?? ''),
                'answer_length' => (int)($q['answer_length'] ?? 0),
                'pick_index' => (int)($q['_pick_index'] ?? 0),
            ],
            $selected
        )),
    ]);

    usort($selected, static function (array $a, array $b): int {
        $lenCmp = ((int)($a['answer_length'] ?? 0)) <=> ((int)($b['answer_length'] ?? 0));
        if ($lenCmp !== 0) {
            return $lenCmp;
        }

        return ((int)($a['_pick_index'] ?? 0)) <=> ((int)($b['_pick_index'] ?? 0));
    });

    word_game_debug_log('selected questions after sort', [
        'qualification_id' => $qualificationId,
        'items' => array_values(array_map(
            static fn(array $q): array => [
                'id' => (string)($q['id'] ?? ''),
                'answer_length' => (int)($q['answer_length'] ?? 0),
                'pick_index' => (int)($q['_pick_index'] ?? 0),
            ],
            $selected
        )),
    ]);

    foreach ($selected as &$selectedQuestion) {
        unset($selectedQuestion['_pick_index']);
    }
    unset($selectedQuestion);

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
    $remainingSeconds = word_game_session_initial_remaining_seconds();
    $schema = word_game_session_schema($pdo);

    $sqSchema = word_game_session_questions_schema($pdo);

    $pdo->beginTransaction();
    try {
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
            $maxScore = word_game_question_max_score($answerLength);
            $questionOrder = $idx + 1;

            $snapshot = [
                $sqSchema['id'] => $sessionQuestionId,
                $sqSchema['session_id'] => $sessionId,
                $sqSchema['word_game_question_id'] => (string)$question['id'],
                $sqSchema['question_order'] => $questionOrder,
                $sqSchema['question_text'] => (string)($question['question_text'] ?? ''),
                $sqSchema['answer_text'] => (string)($question['answer_text'] ?? ''),
                $sqSchema['answer_normalized'] => word_game_normalize_answer((string)($question['answer_normalized'] ?? $question['answer_text'] ?? '')),
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

function word_game_reveal_letter(PDO $pdo, array $sessionQuestion): array
{
    $sqSchema = word_game_session_questions_schema($pdo);
    $sessionQuestionId = (string)($sessionQuestion['id'] ?? '');
    $answerRaw = (string)($sessionQuestion['answer_normalized'] ?? $sessionQuestion['answer_text'] ?? '');
    $answer = word_game_normalize_answer($answerRaw);
    $answerLength = (int)($sessionQuestion['answer_length'] ?? mb_strlen($answer, 'UTF-8'));
    $maxScore = (int)($sessionQuestion['max_score'] ?? word_game_question_max_score($answerLength));

    if ($sessionQuestionId === '' || $answerLength <= 0 || $answer === '') {
        throw new RuntimeException('Soru verisi okunamadı.');
    }

    if ((int)($sessionQuestion['is_completed'] ?? 0) === 1) {
        throw new RuntimeException('Tamamlanan soruda harf açılamaz.');
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
    $sqSchema = word_game_session_questions_schema($pdo);
    $sessionQuestionId = (string)($sessionQuestion['id'] ?? '');
    $answerRaw = (string)($sessionQuestion['answer_normalized'] ?? $sessionQuestion['answer_text'] ?? '');
    $correctAnswer = word_game_normalize_answer($answerRaw);
    $submittedNormalized = word_game_normalize_answer($submittedAnswer);
    $lettersTakenCount = (int)($sessionQuestion['letters_taken_count'] ?? 0);
    $sourceLength = (int)($sessionQuestion['answer_length'] ?? mb_strlen($correctAnswer, 'UTF-8'));
    $maxScore = (int)($sessionQuestion['max_score'] ?? word_game_question_max_score($sourceLength));
    $wrongAttemptCount = (int)($sessionQuestion['wrong_attempt_count'] ?? 0);

    if ($sessionQuestionId === '' || $correctAnswer === '') {
        throw new RuntimeException('Soru verisi okunamadı.');
    }

    $isCorrect = ($submittedNormalized !== '' && $submittedNormalized === $correctAnswer);

    if ($isCorrect) {
        $earnedScore = max(0, $maxScore - ($lettersTakenCount * 10));

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

function word_game_finish_session(PDO $pdo, string $sessionId, string $userId, int $remainingSeconds, string $status): array
{
    $sessionId = trim($sessionId);
    $userId = trim($userId);
    $status = strtolower(trim($status));

    if (!in_array($status, ['completed', 'abandoned', 'timeout'], true)) {
        throw new InvalidArgumentException('status geçersiz.');
    }

    $remainingSeconds = max(0, min(word_game_session_initial_remaining_seconds(), $remainingSeconds));
    word_game_refresh_session_totals($pdo, $sessionId);

    $session = word_game_find_session($pdo, $sessionId, $userId);
    if (!$session) {
        throw new RuntimeException('Oturum bulunamadı.');
    }

    $totalScore = (int)($session['total_score'] ?? 0);
    if ($status === 'abandoned') {
        $totalScore = 0;
    }

    $schema = word_game_session_schema($pdo);
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
    $schema = word_game_session_schema($pdo);

    $sql = 'SELECT `' . $schema['id'] . '` AS id,
                   `' . $schema['user_id'] . '` AS user_id,
                   `' . $schema['total_score'] . '` AS total_score,
                   `' . $schema['remaining_seconds'] . '` AS remaining_seconds,
                   `' . $schema['finished_at'] . '` AS finished_at
            FROM `' . $schema['table'] . '`
            WHERE `' . $schema['qualification_id'] . '` = ?
              AND `' . $schema['status'] . '` IN (\'completed\', \'timeout\')
              AND `' . $schema['finished_at'] . '` IS NOT NULL
            ORDER BY `' . $schema['total_score'] . '` DESC,
                     `' . $schema['remaining_seconds'] . '` DESC,
                     `' . $schema['finished_at'] . '` ASC
            LIMIT ' . $limit;
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

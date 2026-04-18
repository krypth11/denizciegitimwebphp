<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/pusula_ai_knowledge_helper.php';

if (!defined('PUSULA_AI_CHAT_MAX_MESSAGE_LEN')) {
    define('PUSULA_AI_CHAT_MAX_MESSAGE_LEN', 1500);
}

function pusula_ai_chat_modes(): array
{
    return ['normal', 'hoca', 'kısa', 'motivasyon'];
}

function pusula_ai_chat_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function pusula_ai_chat_pick(array $columns, array $candidates, bool $required = false): ?string
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

function pusula_ai_chat_normalize_mode($mode): string
{
    $value = mb_strtolower(trim((string)$mode), 'UTF-8');
    return in_array($value, pusula_ai_chat_modes(), true) ? $value : 'normal';
}

function pusula_ai_chat_validate_request(array $payload): array
{
    $message = trim((string)($payload['message'] ?? ''));
    if ($message === '') {
        return ['valid' => false, 'error' => 'Mesaj geçersiz.'];
    }

    if (mb_strlen($message, 'UTF-8') > PUSULA_AI_CHAT_MAX_MESSAGE_LEN) {
        return ['valid' => false, 'error' => 'Mesaj çok uzun.'];
    }

    $conversationId = api_validate_optional_id((string)($payload['conversation_id'] ?? ''), 'conversation_id', 191);
    $mode = pusula_ai_chat_normalize_mode($payload['mode'] ?? 'normal');

    return [
        'valid' => true,
        'message' => $message,
        'conversation_id' => $conversationId,
        'mode' => $mode,
    ];
}

function pusula_ai_chat_rejection_text(?string $reason = null): string
{
    $templates = [
        'default' => [
            'Pusula Ai şu anda denizcilik eğitimi, sınav hazırlığı ve uygulama içi konularda yardımcı olabilir.',
            'Bu alanda yardımcı olamıyorum. Denizcilik eğitimi, çalışma planı ve sınav hazırlığı konularında destek verebilirim.',
        ],
        'safety' => [
            'Bu içerik için yardımcı olamam. Denizcilik eğitimi, sınav hazırlığı ve uygulama kullanımı konularında destek olabilirim.',
            'Bu isteğe yanıt veremem. İstersen denizcilik eğitimi ve çalışma planı tarafında yardımcı olayım.',
        ],
        'out_of_scope' => [
            'Pusula Ai şu anda denizcilik eğitimi, sınav hazırlığı ve uygulama içi konularda yardımcı olabilir.',
            'Bu konu kapsam dışında. Denizcilik eğitimi, deneme analizi ve çalışma planı konularında destek verebilirim.',
        ],
    ];

    $bucket = in_array((string)$reason, ['küfür', 'hakaret', 'siyaset', 'cinsellik', 'illegal', 'spam'], true)
        ? 'safety'
        : ((string)$reason === 'out_of_scope' ? 'out_of_scope' : 'default');

    $choices = $templates[$bucket] ?? $templates['default'];
    $index = (int)(abs(crc32((string)$reason)) % max(1, count($choices)));

    return (string)($choices[$index] ?? $templates['default'][0]);
}

function pusula_ai_chat_is_spam_text(string $text): bool
{
    $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if ($normalized === '') {
        return false;
    }

    if (preg_match('/(.)\1{7,}/u', $normalized) === 1) {
        return true;
    }

    $words = preg_split('/\s+/u', $normalized) ?: [];
    if (count($words) >= 6) {
        $counts = array_count_values(array_map(static fn($w) => mb_strtolower($w, 'UTF-8'), $words));
        foreach ($counts as $count) {
            if ($count >= 5) {
                return true;
            }
        }
    }

    return false;
}

function pusula_ai_chat_contains_any(string $text, array $terms): bool
{
    foreach ($terms as $term) {
        if ($term === '') {
            continue;
        }
        if (mb_strpos($text, $term, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

function pusula_ai_chat_detect_intent(string $message): string
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '') {
        return 'casual_followup';
    }

    $intentMap = [
        'greeting' => [
            'merhaba', 'selam', 'selamlar', 'günaydın', 'iyi akşamlar', 'iyi günler', 'nasılsın', 'hoş bulduk'
        ],
        'study_plan' => [
            'bugün ne çalışayım', 'bugün ne çalışmalıyım', 'çalışma planı', 'plan yap', 'program yap', 'nasıl çalışayım', 'çalışma programı'
        ],
        'weakness_analysis' => [
            'eksiğim ne', 'eksiklerim ne', 'hangi konuda eksiğim', 'zayıf konularım', 'zayıf yönlerim', 'eksik alanlarım', 'nerede eksik'
        ],
        'motivation' => [
            'moralim bozuk', 'iyi değilim', 'motivasyon', 'beni motive et', 'çok geride kaldım', 'yetişemiyorum', 'kaygılıyım', 'stresliyim'
        ],
        'exam_review' => [
            'son denemem kötü geçti', 'son denememi yorumla', 'denememi yorumla', 'deneme analizi', 'yanlışlarımı yorumla', 'netlerim düştü'
        ],
        'explanation' => [
            'anlat', 'açıkla', 'nedir', 'nasıl çalışır', 'örnek ver', 'colreg anlat', 'konu anlat'
        ],
        'quick_help' => [
            'yardım', 'kısaca', 'hızlıca', 'ne yapayım', 'nasıl başlayayım', 'başlayalım', 'hazırım', 'teşekkür ederim', 'sağ ol'
        ],
    ];

    foreach ($intentMap as $intent => $terms) {
        if (pusula_ai_chat_contains_any($text, $terms)) {
            return $intent;
        }
    }

    if (preg_match('/\b(colreg|seyir|gmdss|çatışma|vardiya|deneme|sınav|çalışma)\b/u', $text) === 1) {
        return 'explanation';
    }

    return 'casual_followup';
}

function detectIntent(string $message): string
{
    return pusula_ai_chat_detect_intent($message);
}

function pusula_ai_chat_user_wants_detailed_reply(string $message): bool
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '') {
        return false;
    }

    $detailTerms = [
        'detaylı', 'detay ver', 'ayrıntılı', 'uzun anlat', 'adım adım', 'analiz et', 'yorumla', 'karşılaştır', 'hepsini anlat'
    ];
    if (pusula_ai_chat_contains_any($text, $detailTerms)) {
        return true;
    }

    return mb_strlen($text, 'UTF-8') >= 120;
}

function pusula_ai_chat_detect_hard_block_reason(string $text): ?string
{
    $blocked = [
        'küfür' => ['amk', 'aq', 'orospu', 'piç', 'siktir', 'göt', 'ibne', 'yarak', 'küfür et'],
        'hakaret' => ['aptal', 'gerizekalı', 'salak', 'mal', 'şerefsiz', 'haysiyetsiz', 'hakaret et'],
        'siyaset' => ['seçim', 'cumhurbaşkanı', 'milletvekili', 'parti', 'iktidar', 'muhalefet', 'oy ver'],
        'cinsellik' => ['seks', 'porno', 'çıplak', 'erotik', 'yetişkin içerik'],
        'illegal' => ['hack', 'şifre kır', 'dolandır', 'uyuşturucu', 'sahte belge', 'silah yapımı'],
    ];

    foreach ($blocked as $reason => $terms) {
        if (pusula_ai_chat_contains_any($text, $terms)) {
            return $reason;
        }
    }

    if (pusula_ai_chat_is_spam_text($text)) {
        return 'spam';
    }

    return null;
}

function pusula_ai_chat_detect_greeting_intent(string $text): ?string
{
    $greetingTerms = [
        'merhaba', 'selam', 'selamlar', 'iyi akşamlar', 'günaydın', 'iyi günler', 'nasılsın'
    ];
    if (pusula_ai_chat_contains_any($text, $greetingTerms)) {
        return 'greeting';
    }

    $onboardingTerms = [
        'ne yapabiliyorsun', 'bana nasıl yardımcı olursun', 'nasıl yardımcı olursun',
        'başlayalım', 'hazırım', 'nasıl çalışıyoruz', 'yardımcı olur musun'
    ];
    if (pusula_ai_chat_contains_any($text, $onboardingTerms)) {
        return 'onboarding';
    }

    return null;
}

function pusula_ai_chat_detect_education_intent(string $text): ?string
{
    $intentMap = [
        'study_plan' => [
            'bugün ne çalışmalıyım', 'çalışma planı', 'bana plan yap', 'plan yap', 'çalışma önerisi', 'program yap', 'nasıl çalışayım'
        ],
        'weakness_analysis' => [
            'eksik alanlarım', 'eksik alanım', 'eksiklerim', 'zayıf konularım', 'zayıf yönlerim', 'hangi konuda eksiğim'
        ],
        'exam_review' => [
            'son denememi yorumla', 'denememi yorumla', 'deneme yorumu', 'deneme analizi', 'yanlışlarımı yorumla'
        ],
        'motivation' => [
            'beni motive et', 'motivasyon', 'sınav kaygısı', 'moralim bozuk', 'motive et'
        ],
        'topic_explanation' => [
            'konu anlat', 'konu anlatımı', 'konuyu açıkla', 'anlatır mısın'
        ],
        'app_help' => [
            'uygulamayı nasıl kullanırım', 'uygulama kullanımı', 'uygulamada', 'pusula ai', 'nasıl kullanılır'
        ],
        'education_general' => [
            'çalışma', 'ders', 'sınav', 'deneme', 'soru', 'performans', 'eksik', 'tekrar', 'konu',
            'deniz', 'gemi', 'gemi adamı', 'colreg', 'seyir', 'ehliyet', 'kurs', 'yeterlilik'
        ],
    ];

    foreach ($intentMap as $intent => $terms) {
        if (pusula_ai_chat_contains_any($text, $terms)) {
            return $intent;
        }
    }

    return null;
}

function pusula_ai_chat_is_clear_off_topic(string $text): bool
{
    $offTopicTerms = [
        'coin öner', 'bitcoin', 'kripto', 'seçimde kim kazanır', 'maç tahmini', 'maç sonucu', 'iddaa',
        'sevgili tavsiyesi', 'kız tavlama', 'burç yorumu', 'sağlık teşhisi', 'teşhis koy', 'gündem'
    ];

    return pusula_ai_chat_contains_any($text, $offTopicTerms);
}

function pusula_ai_chat_moderate_message(string $message): array
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '') {
        return ['allowed' => false, 'reason' => 'empty', 'reply' => pusula_ai_chat_rejection_text('out_of_scope')];
    }

    $hardBlockReason = pusula_ai_chat_detect_hard_block_reason($text);
    if ($hardBlockReason !== null) {
        return ['allowed' => false, 'reason' => $hardBlockReason, 'reply' => pusula_ai_chat_rejection_text($hardBlockReason)];
    }

    if (pusula_ai_chat_is_clear_off_topic($text)) {
        return ['allowed' => false, 'reason' => 'out_of_scope', 'reply' => pusula_ai_chat_rejection_text('out_of_scope')];
    }

    $intent = pusula_ai_chat_detect_intent($text);

    if ($intent === 'casual_followup') {
        $greetingIntent = pusula_ai_chat_detect_greeting_intent($text);
        if ($greetingIntent !== null) {
            return ['allowed' => true, 'reason' => 'allowed_greeting', 'intent' => 'greeting', 'reply' => ''];
        }

        $educationIntent = pusula_ai_chat_detect_education_intent($text);
        if ($educationIntent !== null) {
            $normalizedIntentMap = [
                'topic_explanation' => 'explanation',
                'education_general' => 'quick_help',
                'app_help' => 'quick_help',
            ];
            $normalizedIntent = $normalizedIntentMap[$educationIntent] ?? $educationIntent;
            return ['allowed' => true, 'reason' => 'allowed_education', 'intent' => $normalizedIntent, 'reply' => ''];
        }
    }

    // Gri alan: hard-block veya net kapsam dışı değilse modele gönder.
    return ['allowed' => true, 'reason' => 'gray_allowed', 'intent' => $intent, 'reply' => ''];
}

function pusula_ai_chat_today_window(): array
{
    $tz = new DateTimeZone('Europe/Istanbul');
    $start = new DateTimeImmutable('now', $tz);
    $start = $start->setTime(0, 0, 0);
    $end = $start->modify('+1 day');

    return [
        'date' => $start->format('Y-m-d'),
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
    ];
}

function pusula_ai_chat_count_today_success(PDO $pdo, string $userId): int
{
    $cols = get_table_columns($pdo, PUSULA_AI_USAGE_LOGS_TABLE);
    if (!$cols) {
        return 0;
    }

    $userCol = pusula_ai_chat_pick($cols, ['user_id'], true);
    $requestTypeCol = pusula_ai_chat_pick($cols, ['request_type'], true);
    $successCol = pusula_ai_chat_pick($cols, ['success'], false);
    $usageDateCol = pusula_ai_chat_pick($cols, ['usage_date_tr'], false);
    $createdAtCol = pusula_ai_chat_pick($cols, ['created_at'], false);

    $where = [
        pusula_ai_chat_q($userCol) . ' = :user_id',
        pusula_ai_chat_q($requestTypeCol) . ' = :request_type',
    ];

    $params = [
        ':user_id' => $userId,
        ':request_type' => 'chat_send',
    ];

    if ($successCol) {
        $where[] = pusula_ai_chat_q($successCol) . ' = 1';
    }

    $today = pusula_ai_chat_today_window();
    if ($usageDateCol) {
        $where[] = pusula_ai_chat_q($usageDateCol) . ' = :usage_date';
        $params[':usage_date'] = $today['date'];
    } elseif ($createdAtCol) {
        $where[] = pusula_ai_chat_q($createdAtCol) . ' >= :day_start';
        $where[] = pusula_ai_chat_q($createdAtCol) . ' < :day_end';
        $params[':day_start'] = $today['start'];
        $params[':day_end'] = $today['end'];
    }

    $sql = 'SELECT COUNT(*) FROM ' . pusula_ai_chat_q(PUSULA_AI_USAGE_LOGS_TABLE)
        . ' WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function pusula_ai_chat_create_title(string $message): string
{
    $title = trim($message);
    if (mb_strlen($title, 'UTF-8') > 60) {
        $title = mb_substr($title, 0, 57, 'UTF-8') . '...';
    }
    return $title !== '' ? $title : 'Yeni sohbet';
}

function pusula_ai_chat_get_conversation_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, PUSULA_AI_CONVERSATIONS_TABLE);
    if (!$cols) {
        throw new RuntimeException('Sohbet tablosu okunamadı.');
    }

    return [
        'table' => PUSULA_AI_CONVERSATIONS_TABLE,
        'id' => pusula_ai_chat_pick($cols, ['id'], true),
        'user_id' => pusula_ai_chat_pick($cols, ['user_id'], true),
        'mode' => pusula_ai_chat_pick($cols, ['mode'], false),
        'title' => pusula_ai_chat_pick($cols, ['title'], false),
        'last_message_at' => pusula_ai_chat_pick($cols, ['last_message_at'], false),
        'created_at' => pusula_ai_chat_pick($cols, ['created_at'], false),
        'updated_at' => pusula_ai_chat_pick($cols, ['updated_at'], false),
    ];
}

function pusula_ai_chat_resolve_conversation(PDO $pdo, string $userId, string $conversationId, string $mode, string $firstMessage): string
{
    $schema = pusula_ai_chat_get_conversation_schema($pdo);

    if ($conversationId !== '') {
        $sql = 'SELECT ' . pusula_ai_chat_q($schema['id']) . ' AS id FROM ' . pusula_ai_chat_q($schema['table'])
            . ' WHERE ' . pusula_ai_chat_q($schema['id']) . ' = :id AND ' . pusula_ai_chat_q($schema['user_id']) . ' = :user_id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $conversationId,
            ':user_id' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            api_error('Geçersiz conversation_id.', 403);
        }

        $updates = [];
        $params = [
            ':id' => $conversationId,
            ':user_id' => $userId,
        ];

        if ($schema['last_message_at']) {
            $updates[] = pusula_ai_chat_q($schema['last_message_at']) . ' = :last_message_at';
            $params[':last_message_at'] = date('Y-m-d H:i:s');
        }
        if ($schema['updated_at']) {
            $updates[] = pusula_ai_chat_q($schema['updated_at']) . ' = :updated_at';
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }
        if ($schema['mode']) {
            $updates[] = pusula_ai_chat_q($schema['mode']) . ' = :mode';
            $params[':mode'] = $mode;
        }

        if ($updates) {
            $updateSql = 'UPDATE ' . pusula_ai_chat_q($schema['table'])
                . ' SET ' . implode(', ', $updates)
                . ' WHERE ' . pusula_ai_chat_q($schema['id']) . ' = :id AND ' . pusula_ai_chat_q($schema['user_id']) . ' = :user_id';
            $up = $pdo->prepare($updateSql);
            $up->execute($params);
        }

        return $conversationId;
    }

    $newId = function_exists('generate_uuid') ? (string)generate_uuid() : bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');
    $title = pusula_ai_chat_create_title($firstMessage);

    $fields = [
        $schema['id'] => $newId,
        $schema['user_id'] => $userId,
    ];

    if ($schema['mode']) {
        $fields[$schema['mode']] = $mode;
    }
    if ($schema['title']) {
        $fields[$schema['title']] = $title;
    }
    if ($schema['last_message_at']) {
        $fields[$schema['last_message_at']] = $now;
    }
    if ($schema['created_at']) {
        $fields[$schema['created_at']] = $now;
    }
    if ($schema['updated_at']) {
        $fields[$schema['updated_at']] = $now;
    }

    $cols = array_keys($fields);
    $holders = array_map(static fn($c) => ':' . $c, $cols);

    $sql = 'INSERT INTO ' . pusula_ai_chat_q($schema['table'])
        . ' (' . implode(', ', array_map('pusula_ai_chat_q', $cols)) . ')'
        . ' VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);

    $params = [];
    foreach ($fields as $col => $value) {
        $params[':' . $col] = $value;
    }
    $stmt->execute($params);

    return $newId;
}

function pusula_ai_chat_get_message_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, PUSULA_AI_MESSAGES_TABLE);
    if (!$cols) {
        throw new RuntimeException('Mesaj tablosu okunamadı.');
    }

    return [
        'table' => PUSULA_AI_MESSAGES_TABLE,
        'id' => pusula_ai_chat_pick($cols, ['id'], true),
        'conversation_id' => pusula_ai_chat_pick($cols, ['conversation_id'], true),
        'user_id' => pusula_ai_chat_pick($cols, ['user_id'], true),
        'role' => pusula_ai_chat_pick($cols, ['role'], true),
        'message_type' => pusula_ai_chat_pick($cols, ['message_type'], false),
        'content' => pusula_ai_chat_pick($cols, ['content'], true),
        'action_payload_json' => pusula_ai_chat_pick($cols, ['action_payload_json'], false),
        'input_tokens' => pusula_ai_chat_pick($cols, ['input_tokens'], false),
        'output_tokens' => pusula_ai_chat_pick($cols, ['output_tokens'], false),
        'created_at' => pusula_ai_chat_pick($cols, ['created_at'], false),
        'updated_at' => pusula_ai_chat_pick($cols, ['updated_at'], false),
    ];
}

function pusula_ai_chat_insert_message(
    PDO $pdo,
    string $conversationId,
    string $userId,
    string $role,
    string $content,
    ?array $actionPayload = null,
    int $inputTokens = 0,
    int $outputTokens = 0
): string {
    $schema = pusula_ai_chat_get_message_schema($pdo);
    $messageId = function_exists('generate_uuid') ? (string)generate_uuid() : bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');

    $fields = [
        $schema['id'] => $messageId,
        $schema['conversation_id'] => $conversationId,
        $schema['user_id'] => $userId,
        $schema['role'] => $role,
        $schema['content'] => $content,
    ];

    if ($schema['message_type']) {
        $fields[$schema['message_type']] = 'chat';
    }
    if ($schema['action_payload_json']) {
        $fields[$schema['action_payload_json']] = $actionPayload ? json_encode($actionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }
    if ($schema['input_tokens']) {
        $fields[$schema['input_tokens']] = max(0, $inputTokens);
    }
    if ($schema['output_tokens']) {
        $fields[$schema['output_tokens']] = max(0, $outputTokens);
    }
    if ($schema['created_at']) {
        $fields[$schema['created_at']] = $now;
    }
    if ($schema['updated_at']) {
        $fields[$schema['updated_at']] = $now;
    }

    $columns = array_keys($fields);
    $holders = array_map(static fn($c) => ':' . $c, $columns);

    $sql = 'INSERT INTO ' . pusula_ai_chat_q($schema['table'])
        . ' (' . implode(', ', array_map('pusula_ai_chat_q', $columns)) . ') VALUES (' . implode(', ', $holders) . ')';
    $stmt = $pdo->prepare($sql);

    $params = [];
    foreach ($fields as $col => $value) {
        $params[':' . $col] = $value;
    }
    $stmt->execute($params);

    return $messageId;
}

function pusula_ai_chat_fetch_recent_messages(PDO $pdo, string $conversationId, int $limit = 8): array
{
    $schema = pusula_ai_chat_get_message_schema($pdo);
    $safeLimit = max(1, min(20, $limit));

    $createdExpr = $schema['created_at'] ? pusula_ai_chat_q($schema['created_at']) : pusula_ai_chat_q($schema['id']);
    $sql = 'SELECT '
        . pusula_ai_chat_q($schema['role']) . ' AS role, '
        . pusula_ai_chat_q($schema['content']) . ' AS content '
        . 'FROM ' . pusula_ai_chat_q($schema['table'])
        . ' WHERE ' . pusula_ai_chat_q($schema['conversation_id']) . ' = :conversation_id '
        . ' ORDER BY ' . $createdExpr . ' DESC LIMIT ' . $safeLimit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':conversation_id' => $conversationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = array_reverse($rows);

    $messages = [];
    foreach ($rows as $row) {
        $role = trim((string)($row['role'] ?? 'user'));
        $content = trim((string)($row['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        $messages[] = [
            'role' => in_array($role, ['assistant', 'user', 'system'], true) ? $role : 'user',
            'content' => $content,
        ];
    }

    return $messages;
}

function pusula_ai_chat_fetch_user_context(PDO $pdo, string $userId): array
{
    $context = [
        'qualification' => null,
        'last_7_days_question_count' => null,
        'last_exam_success_rate' => null,
        'weak_topics' => [],
    ];

    try {
        $profileCols = get_table_columns($pdo, 'user_profiles');
        if ($profileCols) {
            $idCol = pusula_ai_chat_pick($profileCols, ['id'], true);
            $qCol = pusula_ai_chat_pick($profileCols, ['current_qualification_id', 'qualification_id'], false);
            if ($qCol) {
                $sql = 'SELECT up.' . pusula_ai_chat_q($qCol) . ' AS qualification_id, q.name AS qualification_name '
                    . 'FROM `user_profiles` up '
                    . 'LEFT JOIN `qualifications` q ON q.id = up.' . pusula_ai_chat_q($qCol) . ' '
                    . 'WHERE up.' . pusula_ai_chat_q($idCol) . ' = :user_id LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $name = trim((string)($row['qualification_name'] ?? ''));
                if ($name !== '') {
                    $context['qualification'] = $name;
                }
            }
        }
    } catch (Throwable $e) {
        // sessizce geç
    }

    try {
        $evCols = get_table_columns($pdo, 'question_attempt_events');
        if ($evCols) {
            $uCol = pusula_ai_chat_pick($evCols, ['user_id'], true);
            $dCol = pusula_ai_chat_pick($evCols, ['answered_at', 'created_at', 'attempted_at'], false);
            if ($dCol) {
                $sql = 'SELECT COUNT(*) FROM `question_attempt_events` '
                    . 'WHERE ' . pusula_ai_chat_q($uCol) . ' = :user_id '
                    . 'AND ' . pusula_ai_chat_q($dCol) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $context['last_7_days_question_count'] = (int)$stmt->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        // sessizce geç
    }

    try {
        $aCols = get_table_columns($pdo, 'mock_exam_attempts');
        if ($aCols) {
            $uCol = pusula_ai_chat_pick($aCols, ['user_id'], true);
            $statusCol = pusula_ai_chat_pick($aCols, ['status'], false);
            $successCol = pusula_ai_chat_pick($aCols, ['success_rate'], false);
            $createdCol = pusula_ai_chat_pick($aCols, ['submitted_at', 'created_at', 'updated_at'], false);

            if ($successCol && $createdCol) {
                $where = [pusula_ai_chat_q($uCol) . ' = :user_id'];
                if ($statusCol) {
                    $where[] = pusula_ai_chat_q($statusCol) . " = 'completed'";
                }

                $sql = 'SELECT ' . pusula_ai_chat_q($successCol) . ' AS success_rate '
                    . 'FROM `mock_exam_attempts` WHERE ' . implode(' AND ', $where)
                    . ' ORDER BY ' . pusula_ai_chat_q($createdCol) . ' DESC LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['success_rate'])) {
                    $context['last_exam_success_rate'] = (float)$row['success_rate'];
                }
            }
        }
    } catch (Throwable $e) {
        // sessizce geç
    }

    try {
        $upCols = get_table_columns($pdo, 'user_progress');
        $qCols = get_table_columns($pdo, 'questions');
        $tCols = get_table_columns($pdo, 'topics');

        if ($upCols && $qCols && $tCols) {
            $upUser = pusula_ai_chat_pick($upCols, ['user_id'], true);
            $upQuestion = pusula_ai_chat_pick($upCols, ['question_id'], true);
            $upWrong = pusula_ai_chat_pick($upCols, ['wrong_answer_count', 'wrong_count', 'incorrect_count'], false);
            $upIsCorrect = pusula_ai_chat_pick($upCols, ['is_correct'], false);
            $upTotal = pusula_ai_chat_pick($upCols, ['total_answer_count', 'answer_count', 'total_answers'], false);

            $qId = pusula_ai_chat_pick($qCols, ['id'], true);
            $qTopic = pusula_ai_chat_pick($qCols, ['topic_id'], false);

            $tId = pusula_ai_chat_pick($tCols, ['id'], true);
            $tName = pusula_ai_chat_pick($tCols, ['name', 'title'], false);

            if ($qTopic && $tName && ($upWrong || ($upIsCorrect && $upTotal))) {
                $wrongCond = [];
                if ($upWrong) {
                    $wrongCond[] = 'COALESCE(up.' . pusula_ai_chat_q($upWrong) . ',0) > 0';
                }
                if ($upIsCorrect && $upTotal) {
                    $wrongCond[] = '(COALESCE(up.' . pusula_ai_chat_q($upTotal) . ',0) > 0 AND COALESCE(up.' . pusula_ai_chat_q($upIsCorrect) . ',1) = 0)';
                }

                $sql = 'SELECT t.' . pusula_ai_chat_q($tName) . ' AS topic_name, COUNT(*) AS wrong_count '
                    . 'FROM `user_progress` up '
                    . 'INNER JOIN `questions` q ON q.' . pusula_ai_chat_q($qId) . ' = up.' . pusula_ai_chat_q($upQuestion) . ' '
                    . 'INNER JOIN `topics` t ON t.' . pusula_ai_chat_q($tId) . ' = q.' . pusula_ai_chat_q($qTopic) . ' '
                    . 'WHERE up.' . pusula_ai_chat_q($upUser) . ' = :user_id AND (' . implode(' OR ', $wrongCond) . ') '
                    . 'GROUP BY t.' . pusula_ai_chat_q($tName) . ' '
                    . 'ORDER BY wrong_count DESC LIMIT 3';

                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $weak = [];
                foreach ($rows as $row) {
                    $name = trim((string)($row['topic_name'] ?? ''));
                    if ($name !== '') {
                        $weak[] = $name;
                    }
                }
                $context['weak_topics'] = $weak;
            }
        }
    } catch (Throwable $e) {
        // sessizce geç
    }

    return $context;
}

function pusula_ai_chat_get_knowledge_bundle(PDO $pdo): array
{
    $knowledge = pusula_ai_get_knowledge($pdo);
    $tools = pusula_ai_get_tool_settings($pdo);
    $examples = pusula_ai_list_example_conversations($pdo);

    $activeExamples = [];
    foreach ($examples as $example) {
        if ((int)($example['is_active'] ?? 0) !== 1) {
            continue;
        }

        $userMessage = trim((string)($example['user_message'] ?? ''));
        $assistantReply = trim((string)($example['assistant_reply'] ?? ''));
        if ($userMessage === '' || $assistantReply === '') {
            continue;
        }

        $activeExamples[] = [
            'conversation_tag' => trim((string)($example['conversation_tag'] ?? '')),
            'user_message' => function_exists('mb_substr') ? mb_substr($userMessage, 0, 450, 'UTF-8') : substr($userMessage, 0, 450),
            'assistant_reply' => function_exists('mb_substr') ? mb_substr($assistantReply, 0, 900, 'UTF-8') : substr($assistantReply, 0, 900),
            'order_index' => (int)($example['order_index'] ?? 0),
        ];
    }

    usort($activeExamples, static function (array $a, array $b): int {
        return ($a['order_index'] <=> $b['order_index']);
    });

    return [
        'knowledge' => is_array($knowledge) ? $knowledge : pusula_ai_knowledge_defaults(),
        'tools' => is_array($tools) ? $tools : pusula_ai_tool_settings_defaults(),
        'examples' => array_slice($activeExamples, 0, 8),
    ];
}

function pusula_ai_chat_build_system_prompt(string $mode, array $userContext, array $meta = []): string
{
    $kb = is_array($meta['knowledge_bundle'] ?? null) ? $meta['knowledge_bundle'] : [];
    $knowledge = is_array($kb['knowledge'] ?? null) ? $kb['knowledge'] : pusula_ai_knowledge_defaults();
    $tools = is_array($kb['tools'] ?? null) ? $kb['tools'] : pusula_ai_tool_settings_defaults();
    $examples = is_array($kb['examples'] ?? null) ? $kb['examples'] : [];

    $intent = trim((string)($meta['user_intent'] ?? ''));
    if ($intent === '') {
        $intent = 'casual_followup';
    }
    $userMessageLength = max(0, (int)($meta['user_message_length'] ?? 0));
    $wantsDetailed = !empty($meta['user_wants_detailed']);
    $isShortUserMessage = $userMessageLength > 0 && $userMessageLength <= 45;

    $lines = [];

    $provider = trim((string)($meta['provider'] ?? ''));
    $model = trim((string)($meta['model'] ?? ''));
    if ($provider !== '' || $model !== '') {
        $lines[] = '[MODEL_CONFIG]';
        if ($provider !== '') {
            $lines[] = 'Provider: ' . $provider;
        }
        if ($model !== '') {
            $lines[] = 'Model: ' . $model;
        }
    }

    $lines[] = '[APP_GENERAL]';
    $lines[] = 'Asistan adı: ' . trim((string)($knowledge['assistant_name'] ?? 'Pusula Ai'));
    $lines[] = 'Uygulama adı: ' . trim((string)($knowledge['app_name'] ?? 'Denizci Eğitim'));
    $summary = trim((string)($knowledge['app_summary'] ?? ''));
    if ($summary !== '') {
        $lines[] = 'Uygulama özeti: ' . $summary;
    }
    $targets = trim((string)($knowledge['target_users'] ?? ''));
    if ($targets !== '') {
        $lines[] = 'Hedef kullanıcılar: ' . $targets;
    }
    $tone = trim((string)($knowledge['tone_of_voice'] ?? ''));
    if ($tone !== '') {
        $lines[] = 'Ton: ' . $tone;
    }

    $lines[] = '[BEHAVIOR_RULES]';
    foreach (['allowed_topics_text', 'blocked_topics_text', 'response_style_text', 'emotional_style_text', 'short_reply_rules_text', 'long_reply_rules_text'] as $field) {
        $val = trim((string)($knowledge[$field] ?? ''));
        if ($val !== '') {
            $lines[] = $field . ': ' . $val;
        }
    }

    $lines[] = '[APP_FEATURES]';
    foreach (['app_features_text', 'premium_features_text', 'offline_features_text', 'community_features_text', 'exam_features_text'] as $field) {
        $val = trim((string)($knowledge[$field] ?? ''));
        if ($val !== '') {
            $lines[] = $field . ': ' . $val;
        }
    }

    $lines[] = '[SYSTEM_PROMPT_LAYERS]';
    foreach (['system_prompt_base', 'system_prompt_behavior', 'system_prompt_app_knowledge', 'system_prompt_stats_behavior', 'system_prompt_exam_behavior'] as $field) {
        $val = trim((string)($knowledge[$field] ?? ''));
        if ($val !== '') {
            $lines[] = $field . ': ' . $val;
        }
    }

    if (!empty($examples)) {
        $lines[] = '[EXAMPLE_CONVERSATIONS_ACTIVE]';
        foreach ($examples as $example) {
            $tag = trim((string)($example['conversation_tag'] ?? 'general'));
            $u = trim((string)($example['user_message'] ?? ''));
            $a = trim((string)($example['assistant_reply'] ?? ''));
            if ($u === '' || $a === '') {
                continue;
            }
            $lines[] = '- tag=' . $tag;
            $lines[] = '  user: ' . $u;
            $lines[] = '  assistant: ' . $a;
        }
    }

    $lines[] = '[TOOL_SETTINGS]';
    foreach (['tool_stats_enabled', 'tool_exam_recommendation_enabled', 'tool_app_info_enabled', 'tool_action_payload_enabled', 'tool_weak_topics_enabled', 'tool_last_exam_enabled'] as $toolField) {
        $lines[] = $toolField . ': ' . (((int)($tools[$toolField] ?? 0) === 1) ? '1' : '0');
    }

    $lines[] = '[FALLBACK_CORE_RULES]';
    $lines[] = 'Sadece denizcilik eğitimi, sınav hazırlığı, çalışma yönlendirmesi, motivasyon ve uygulama kullanımı konularında yardımcı ol.';
    $lines[] = 'Finans, siyaset, gündem, sağlık teşhis ve alakasız genel sorulara cevap verme.';
    $lines[] = 'Kullanıcıyı gereksiz yere kapsam dışı sayma; yalnızca net alakasız veya yasak içeriklerde reddet.';
    $lines[] = 'Asla küfür, hakaret, uygunsuz veya illegal içerik üretme.';
    $lines[] = 'Asla uydurma bilgi verme. Emin değilsen açıkça belirt.';

    $ctx = [];
    if (!empty($userContext['qualification'])) {
        $ctx[] = 'Mevcut yeterlilik: ' . $userContext['qualification'];
    }
    if (isset($userContext['last_7_days_question_count']) && $userContext['last_7_days_question_count'] !== null) {
        $ctx[] = 'Son 7 gün soru sayısı: ' . (int)$userContext['last_7_days_question_count'];
    }
    if ($userContext['last_exam_success_rate'] !== null) {
        $ctx[] = 'Son deneme başarı oranı: %' . round((float)$userContext['last_exam_success_rate'], 1);
    }
    if (!empty($userContext['weak_topics'])) {
        $ctx[] = 'Zayıf konular: ' . implode(', ', array_slice($userContext['weak_topics'], 0, 3));
    }

    $allowContextDump = in_array($intent, ['study_plan', 'weakness_analysis', 'exam_review', 'explanation'], true);
    if ($allowContextDump && $ctx) {
        $lines[] = '[USER_CONTEXT]';
        $lines[] = 'Kullanıcı özeti:';
        foreach ($ctx as $line) {
            $lines[] = '- ' . $line;
        }
    }

    $lines[] = 'Kullanıcı niyeti: ' . $intent;

    if ($isShortUserMessage && !$wantsDetailed) {
        $lines[] = 'Kullanıcı kısa yazdı; cevabı kısa tut (genelde 1-4 cümle).';
    }
    if ($wantsDetailed) {
        $lines[] = 'Kullanıcı detay talep ediyor; bu durumda konuya göre orta/detaylı cevap verilebilir.';
    }

    switch ($intent) {
        case 'greeting':
            $lines[] = 'Yanıt biçimi: 1-2 kısa, sıcak cümle; gerekirse tek bir soru ile devam et.';
            $lines[] = 'Selamlaşmada asla uzun plan, zayıf konu dökümü veya paragraf analiz verme.';
            break;
        case 'quick_help':
            $lines[] = 'Yanıt biçimi: 2-4 cümle, hızlı ve net yardım.';
            break;
        case 'study_plan':
            $lines[] = 'Yanıt biçimi: kısa yönlendirme + mini plan. Liste gerekiyorsa en fazla 2-3 madde.';
            break;
        case 'weakness_analysis':
            $lines[] = 'Yanıt biçimi: kısa analiz + bir sonraki adım. Gereksiz uzun rapordan kaçın.';
            break;
        case 'motivation':
            $lines[] = 'Yanıt biçimi: önce empati, sonra kısa destek ve uygulanabilir küçük adım.';
            break;
        case 'explanation':
            $lines[] = 'Yanıt biçimi: orta uzunlukta, sade ve anlaşılır anlatım.';
            break;
        case 'exam_review':
            $lines[] = 'Yanıt biçimi: orta uzunlukta değerlendirme, 2-3 net çıkarım ve devam adımı.';
            break;
        case 'casual_followup':
        default:
            $lines[] = 'Yanıt biçimi: sohbet odaklı, kısa ve doğal.';
            break;
    }

    switch ($mode) {
        case 'hoca':
            $lines[] = 'Cevap tonu: öğretici, açıklayıcı, adım adım.';
            break;
        case 'kısa':
            $lines[] = 'Cevap tonu: çok kısa ve direkt.';
            break;
        case 'motivasyon':
            $lines[] = 'Cevap tonu: motive edici, destekleyici ve yön verici.';
            break;
        case 'normal':
        default:
            $lines[] = 'Cevap tonu: dengeli, pratik, premium hisli.';
            break;
    }

    return implode("\n", $lines);
}

function pusula_ai_chat_detect_action_payload(string $message): ?array
{
    $settings = pusula_ai_knowledge_defaults();
    $tools = pusula_ai_tool_settings_defaults();

    if (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        try {
            $settings = pusula_ai_get_knowledge($GLOBALS['pdo']);
            $tools = pusula_ai_get_tool_settings($GLOBALS['pdo']);
        } catch (Throwable $e) {
            // fallback defaults
        }
    }

    if ((int)($tools['tool_action_payload_enabled'] ?? 1) !== 1) {
        return null;
    }

    $text = mb_strtolower(trim($message), 'UTF-8');
    if ((int)($settings['action_exam_enabled'] ?? 1) === 1) {
        $examTriggers = ['mini deneme', 'deneme hazırla', 'deneme oluştur', '10 soruluk deneme'];
        foreach ($examTriggers as $trigger) {
            if (mb_strpos($text, $trigger, 0, 'UTF-8') !== false) {
                return [
                    'type' => 'recommended_exam',
                    'exam_mode' => (string)($settings['action_exam_default_mode'] ?? 'mini'),
                    'question_count' => max(1, (int)($settings['action_exam_default_question_count'] ?? 10)),
                    'intro_text' => trim((string)($settings['action_button_intro_text'] ?? '')),
                ];
            }
        }
    }

    if ((int)($settings['action_plan_enabled'] ?? 1) === 1) {
        $planTriggers = ['çalışma planı', 'plan yap', 'program yap', 'çalışma programı'];
        foreach ($planTriggers as $trigger) {
            if (mb_strpos($text, $trigger, 0, 'UTF-8') !== false) {
                return [
                    'type' => 'recommended_plan',
                    'intro_text' => trim((string)($settings['action_button_intro_text'] ?? '')),
                ];
            }
        }
    }

    return null;
}

function pusula_ai_chat_detect_action_payload_from_bundle(string $message, array $knowledgeBundle = []): ?array
{
    $settings = is_array($knowledgeBundle['knowledge'] ?? null)
        ? $knowledgeBundle['knowledge']
        : pusula_ai_knowledge_defaults();
    $tools = is_array($knowledgeBundle['tools'] ?? null)
        ? $knowledgeBundle['tools']
        : pusula_ai_tool_settings_defaults();

    if ((int)($tools['tool_action_payload_enabled'] ?? 1) !== 1) {
        return null;
    }

    $text = mb_strtolower(trim($message), 'UTF-8');
    if ((int)($settings['action_exam_enabled'] ?? 1) === 1) {
        foreach (['mini deneme', 'deneme hazırla', 'deneme oluştur', '10 soruluk deneme'] as $trigger) {
            if (mb_strpos($text, $trigger, 0, 'UTF-8') !== false) {
                return [
                    'type' => 'recommended_exam',
                    'exam_mode' => (string)($settings['action_exam_default_mode'] ?? 'mini'),
                    'question_count' => max(1, (int)($settings['action_exam_default_question_count'] ?? 10)),
                    'intro_text' => trim((string)($settings['action_button_intro_text'] ?? '')),
                ];
            }
        }
    }

    if ((int)($settings['action_plan_enabled'] ?? 1) === 1) {
        foreach (['çalışma planı', 'plan yap', 'program yap', 'çalışma programı'] as $trigger) {
            if (mb_strpos($text, $trigger, 0, 'UTF-8') !== false) {
                return [
                    'type' => 'recommended_plan',
                    'intro_text' => trim((string)($settings['action_button_intro_text'] ?? '')),
                ];
            }
        }
    }

    return null;
}

function pusula_ai_chat_get_usage_schema(PDO $pdo): array
{
    $cols = get_table_columns($pdo, PUSULA_AI_USAGE_LOGS_TABLE);
    if (!$cols) {
        throw new RuntimeException('Kullanım log tablosu okunamadı.');
    }

    return [
        'table' => PUSULA_AI_USAGE_LOGS_TABLE,
        'id' => pusula_ai_chat_pick($cols, ['id'], false),
        'user_id' => pusula_ai_chat_pick($cols, ['user_id'], false),
        'conversation_id' => pusula_ai_chat_pick($cols, ['conversation_id'], false),
        'request_type' => pusula_ai_chat_pick($cols, ['request_type'], false),
        'provider' => pusula_ai_chat_pick($cols, ['provider'], false),
        'model' => pusula_ai_chat_pick($cols, ['model'], false),
        'token_in' => pusula_ai_chat_pick($cols, ['token_in', 'input_tokens'], false),
        'token_out' => pusula_ai_chat_pick($cols, ['token_out', 'output_tokens'], false),
        'estimated_cost' => pusula_ai_chat_pick($cols, ['estimated_cost'], false),
        'success' => pusula_ai_chat_pick($cols, ['success'], false),
        'error_code' => pusula_ai_chat_pick($cols, ['error_code'], false),
        'error_message' => pusula_ai_chat_pick($cols, ['error_message'], false),
        'usage_date_tr' => pusula_ai_chat_pick($cols, ['usage_date_tr'], false),
        'created_at' => pusula_ai_chat_pick($cols, ['created_at'], false),
        'updated_at' => pusula_ai_chat_pick($cols, ['updated_at'], false),
    ];
}

function pusula_ai_chat_log_usage(PDO $pdo, string $userId, array $payload): void
{
    try {
        $schema = pusula_ai_chat_get_usage_schema($pdo);
        $now = date('Y-m-d H:i:s');
        $today = pusula_ai_chat_today_window();

        $fields = [];
        if ($schema['id']) {
            $fields[$schema['id']] = function_exists('generate_uuid') ? (string)generate_uuid() : bin2hex(random_bytes(16));
        }
        if ($schema['user_id']) {
            $fields[$schema['user_id']] = $userId;
        }
        if ($schema['conversation_id']) {
            $fields[$schema['conversation_id']] = (string)($payload['conversation_id'] ?? '');
        }
        if ($schema['request_type']) {
            $fields[$schema['request_type']] = 'chat_send';
        }
        if ($schema['provider']) {
            $fields[$schema['provider']] = (string)($payload['provider'] ?? '');
        }
        if ($schema['model']) {
            $fields[$schema['model']] = (string)($payload['model'] ?? '');
        }
        if ($schema['token_in']) {
            $fields[$schema['token_in']] = max(0, (int)($payload['token_in'] ?? 0));
        }
        if ($schema['token_out']) {
            $fields[$schema['token_out']] = max(0, (int)($payload['token_out'] ?? 0));
        }
        if ($schema['estimated_cost']) {
            $fields[$schema['estimated_cost']] = (float)($payload['estimated_cost'] ?? 0);
        }
        if ($schema['success']) {
            $fields[$schema['success']] = !empty($payload['success']) ? 1 : 0;
        }
        if ($schema['error_code']) {
            $fields[$schema['error_code']] = trim((string)($payload['error_code'] ?? ''));
        }
        if ($schema['error_message']) {
            $fields[$schema['error_message']] = trim((string)($payload['error_message'] ?? ''));
        }
        if ($schema['usage_date_tr']) {
            $fields[$schema['usage_date_tr']] = $today['date'];
        }
        if ($schema['created_at']) {
            $fields[$schema['created_at']] = $now;
        }
        if ($schema['updated_at']) {
            $fields[$schema['updated_at']] = $now;
        }

        if (!$fields) {
            return;
        }

        $columns = array_keys($fields);
        $holders = array_map(static fn($c) => ':' . $c, $columns);

        $sql = 'INSERT INTO ' . pusula_ai_chat_q($schema['table'])
            . ' (' . implode(', ', array_map('pusula_ai_chat_q', $columns)) . ') VALUES (' . implode(', ', $holders) . ')';
        $stmt = $pdo->prepare($sql);

        $params = [];
        foreach ($fields as $col => $value) {
            $params[':' . $col] = $value;
        }
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('[pusula_ai_chat][usage_log] ' . $e->getMessage());
    }
}

<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/pusula_ai_knowledge_helper.php';
require_once dirname(__DIR__) . '/tools/pusula_ai_tools_helper.php';
require_once __DIR__ . '/pusula_ai_master_context_parser.php';

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

function pusula_ai_chat_policy_hard_block_reply(): string
{
    return 'Pusula Ai yalnızca denizcilik eğitimi, sınav hazırlığı ve uygulama kullanımı konularında yardımcı olabilir.';
}

function pusula_ai_chat_policy_hard_block_terms(): array
{
    return [
        'finance' => ['coin', 'bitcoin', 'altcoin', 'yatırım', 'hisse', 'borsa', 'trade', 'kripto'],
        'politics' => ['seçim', 'cumhurbaşkanı', 'parti', 'siyasi', 'oy ver'],
        'health' => ['hastalık', 'ilaç', 'tedavi', 'reçete'],
        'abuse' => ['küfür et', 'hakaret et', 'söv'],
        'illegal' => ['hackle', 'crackle', 'dolandır', 'yasa dışı'],
    ];
}

function pusula_ai_chat_detect_policy_hard_block(string $message): ?array
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '') {
        return null;
    }

    $categories = pusula_ai_chat_policy_hard_block_terms();
    foreach ($categories as $category => $terms) {
        foreach ($terms as $term) {
            $needle = mb_strtolower(trim((string)$term), 'UTF-8');
            if ($needle === '') {
                continue;
            }
            if (mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                return [
                    'category' => $category,
                    'term' => $needle,
                    'reply' => pusula_ai_chat_policy_hard_block_reply(),
                ];
            }
        }
    }

    return null;
}

function pusula_ai_chat_detect_intent(string $message): string
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '') {
        return 'casual_followup';
    }

    $isShort = mb_strlen($text, 'UTF-8') <= 22;

    if (pusula_ai_chat_contains_any($text, ['merhaba', 'selam', 'selamlar', 'günaydın', 'iyi akşamlar', 'iyi günler', 'nasılsın', 'başlayalım'])) {
        if (pusula_ai_chat_contains_any($text, ['ne yapabiliyorsun', 'yardımcı olur musun', 'nasıl yardımcı olursun', 'ilk kez', 'başlayalım', 'hazırım'])) {
            return 'onboarding';
        }
        return 'greeting';
    }

    if (pusula_ai_chat_contains_any($text, ['istatistiklerim', 'istatistiklerimin', 'özetimi ver', 'özet çıkar', 'nasıl gidiyorum', 'performansım nasıl', 'durumum nasıl'])) {
        return 'stats_summary';
    }

    if (pusula_ai_chat_contains_any($text, ['uygulamada ne yapabilirim', 'uygulamada neler var', 'premium ne açıyor', 'offline ne işe yarar', 'uygulama özellikleri', 'topluluk özelliği'])) {
        return 'app_info';
    }

    if (pusula_ai_chat_contains_any($text, ['bana deneme hazırla', 'deneme oluştur', 'deneme hazırla', '20 soruluk deneme', 'yanlış yaptığım konulardan deneme', 'zayıf konulardan deneme', 'mini deneme'])) {
        return 'exam_request';
    }

    if (pusula_ai_chat_contains_any($text, ['moralim bozuk', 'çok geride kaldım', 'yetişemiyorum', 'kaygılıyım', 'stresliyim', 'çok bunaldım', 'kötü hissediyorum'])) {
        return 'emotional_support';
    }

    if (pusula_ai_chat_contains_any($text, ['motive et', 'motivasyon', 'devam edemiyorum', 'hevesim yok'])) {
        return 'motivation';
    }

    if (pusula_ai_chat_contains_any($text, ['eksik alanlarım', 'zayıf alanlarım', 'hangi konularda eksiğim', 'zayıf konularım', 'eksiklerim'])) {
        return 'weakness_analysis';
    }

    if (pusula_ai_chat_contains_any($text, ['çalışma planı', 'plan yap', 'program yap', 'bugün ne çalışayım', 'çalışma programı'])) {
        return 'study_plan';
    }

    if (pusula_ai_chat_contains_any($text, ['son denememi yorumla', 'deneme analizi', 'denememi değerlendir', 'yanlışlarımı yorumla'])) {
        return 'exam_review';
    }

    if (pusula_ai_chat_contains_any($text, ['anlat', 'açıkla', 'nedir', 'nasıl çalışır', 'örnek ver', 'konu anlat'])) {
        return 'explanation';
    }

    if (pusula_ai_chat_contains_any($text, ['yardım', 'kısaca', 'hızlıca', 'ne yapayım', 'nasıl başlayayım', 'teşekkür ederim', 'sağ ol'])) {
        return 'quick_help';
    }

    if ($isShort && preg_match('/\b(colreg|seyir|gmdss|çatışma|vardiya|deneme|sınav|çalışma)\b/u', $text) === 1) {
        return 'quick_help';
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
        'stats_summary' => [
            'istatistik', 'özetimi ver', 'özet çıkar', 'nasıl gidiyorum', 'performansım'
        ],
        'app_info' => [
            'uygulamada', 'premium ne açıyor', 'offline', 'uygulama özellikleri', 'denizci eğitim uygulaması'
        ],
        'exam_request' => [
            'deneme hazırla', 'deneme oluştur', '20 soruluk', 'yanlış yaptığım konulardan deneme'
        ],
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
        'emotional_support' => [
            'moralim bozuk', 'çok geride kaldım', 'yetişemiyorum', 'kötü hissediyorum', 'bunaldım'
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
            return ['allowed' => true, 'reason' => 'allowed_greeting', 'intent' => $greetingIntent, 'reply' => ''];
        }

        $educationIntent = pusula_ai_chat_detect_education_intent($text);
        if ($educationIntent !== null) {
            $normalizedIntentMap = [
                'topic_explanation' => 'explanation',
                'education_general' => 'quick_help',
                'app_help' => 'app_info',
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
        'is_premium' => null,
        'total_solved' => null,
        'total_correct' => null,
        'total_wrong' => null,
        'last_7_days_question_count' => null,
        'last_7_days_active_days' => null,
        'last_exam_success_rate' => null,
        'last_exam' => null,
        'weak_topics' => [],
        'strong_topics' => [],
        'proficiency_level' => null,
    ];

    if (function_exists('pusula_ai_api_is_user_premium')) {
        try {
            $context['is_premium'] = pusula_ai_api_is_user_premium($pdo, $userId) ? 1 : 0;
        } catch (Throwable $e) {
            // sessizce geç
        }
    }

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
            $isCorrectCol = pusula_ai_chat_pick($evCols, ['is_correct', 'correct', 'was_correct'], false);
            $idCol = pusula_ai_chat_pick($evCols, ['id'], false);

            $countExpr = $idCol ? 'COUNT(' . pusula_ai_chat_q($idCol) . ')' : 'COUNT(*)';
            $correctExpr = $isCorrectCol ? 'SUM(CASE WHEN COALESCE(' . pusula_ai_chat_q($isCorrectCol) . ',0) = 1 THEN 1 ELSE 0 END)' : 'NULL';

            $sqlTotal = 'SELECT ' . $countExpr . ' AS total_solved, ' . $correctExpr . ' AS total_correct '
                . 'FROM `question_attempt_events` '
                . 'WHERE ' . pusula_ai_chat_q($uCol) . ' = :user_id';
            $stmtTotal = $pdo->prepare($sqlTotal);
            $stmtTotal->execute([':user_id' => $userId]);
            $totalRow = $stmtTotal->fetch(PDO::FETCH_ASSOC) ?: [];
            $context['total_solved'] = isset($totalRow['total_solved']) ? (int)$totalRow['total_solved'] : null;
            if (isset($totalRow['total_correct']) && $totalRow['total_correct'] !== null) {
                $context['total_correct'] = (int)$totalRow['total_correct'];
                $context['total_wrong'] = max(0, (int)($context['total_solved'] ?? 0) - (int)$context['total_correct']);
            }

            if ($dCol) {
                $sql = 'SELECT COUNT(*) FROM `question_attempt_events` '
                    . 'WHERE ' . pusula_ai_chat_q($uCol) . ' = :user_id '
                    . 'AND ' . pusula_ai_chat_q($dCol) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $context['last_7_days_question_count'] = (int)$stmt->fetchColumn();

                $sqlDays = 'SELECT COUNT(DISTINCT DATE(' . pusula_ai_chat_q($dCol) . ')) '
                    . 'FROM `question_attempt_events` '
                    . 'WHERE ' . pusula_ai_chat_q($uCol) . ' = :user_id '
                    . 'AND ' . pusula_ai_chat_q($dCol) . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                $stmtDays = $pdo->prepare($sqlDays);
                $stmtDays->execute([':user_id' => $userId]);
                $context['last_7_days_active_days'] = (int)$stmtDays->fetchColumn();
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
            $correctCol = pusula_ai_chat_pick($aCols, ['correct_count', 'total_correct'], false);
            $wrongCol = pusula_ai_chat_pick($aCols, ['wrong_count', 'incorrect_count', 'total_wrong'], false);
            $questionCountCol = pusula_ai_chat_pick($aCols, ['question_count', 'total_questions'], false);

            if ($successCol && $createdCol) {
                $where = [pusula_ai_chat_q($uCol) . ' = :user_id'];
                if ($statusCol) {
                    $where[] = pusula_ai_chat_q($statusCol) . " = 'completed'";
                }

                $selectParts = [
                    pusula_ai_chat_q($successCol) . ' AS success_rate',
                    pusula_ai_chat_q($createdCol) . ' AS exam_created_at',
                ];
                if ($correctCol) {
                    $selectParts[] = pusula_ai_chat_q($correctCol) . ' AS correct_count';
                }
                if ($wrongCol) {
                    $selectParts[] = pusula_ai_chat_q($wrongCol) . ' AS wrong_count';
                }
                if ($questionCountCol) {
                    $selectParts[] = pusula_ai_chat_q($questionCountCol) . ' AS question_count';
                }

                $sql = 'SELECT ' . implode(', ', $selectParts) . ' '
                    . 'FROM `mock_exam_attempts` WHERE ' . implode(' AND ', $where)
                    . ' ORDER BY ' . pusula_ai_chat_q($createdCol) . ' DESC LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':user_id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['success_rate'])) {
                    $context['last_exam_success_rate'] = (float)$row['success_rate'];
                    $context['last_exam'] = [
                        'success_rate' => (float)$row['success_rate'],
                        'correct_count' => isset($row['correct_count']) ? (int)$row['correct_count'] : null,
                        'wrong_count' => isset($row['wrong_count']) ? (int)$row['wrong_count'] : null,
                        'question_count' => isset($row['question_count']) ? (int)$row['question_count'] : null,
                        'created_at' => trim((string)($row['exam_created_at'] ?? '')),
                    ];
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

                if ($upIsCorrect && $upTotal) {
                    $sqlStrong = 'SELECT t.' . pusula_ai_chat_q($tName) . ' AS topic_name, '
                        . 'SUM(CASE WHEN COALESCE(up.' . pusula_ai_chat_q($upIsCorrect) . ',0) = 1 THEN 1 ELSE 0 END) AS correct_count '
                        . 'FROM `user_progress` up '
                        . 'INNER JOIN `questions` q ON q.' . pusula_ai_chat_q($qId) . ' = up.' . pusula_ai_chat_q($upQuestion) . ' '
                        . 'INNER JOIN `topics` t ON t.' . pusula_ai_chat_q($tId) . ' = q.' . pusula_ai_chat_q($qTopic) . ' '
                        . 'WHERE up.' . pusula_ai_chat_q($upUser) . ' = :user_id '
                        . 'GROUP BY t.' . pusula_ai_chat_q($tName) . ' '
                        . 'ORDER BY correct_count DESC LIMIT 2';

                    $stmtStrong = $pdo->prepare($sqlStrong);
                    $stmtStrong->execute([':user_id' => $userId]);
                    $rowsStrong = $stmtStrong->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $strong = [];
                    foreach ($rowsStrong as $rowStrong) {
                        $nameStrong = trim((string)($rowStrong['topic_name'] ?? ''));
                        if ($nameStrong !== '') {
                            $strong[] = $nameStrong;
                        }
                    }
                    $context['strong_topics'] = $strong;
                }
            }
        }
    } catch (Throwable $e) {
        // sessizce geç
    }

    $sr = $context['last_exam_success_rate'];
    if ($sr !== null) {
        if ($sr >= 75) {
            $context['proficiency_level'] = 'ileri';
        } elseif ($sr >= 55) {
            $context['proficiency_level'] = 'orta';
        } else {
            $context['proficiency_level'] = 'gelişmekte';
        }
    } elseif (($context['last_7_days_question_count'] ?? 0) >= 80) {
        $context['proficiency_level'] = 'istikrarlı';
    } else {
        $context['proficiency_level'] = 'başlangıç/orta';
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

function pusula_ai_chat_json_encode($data): string
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) ? $json : '{}';
}

function pusula_ai_chat_master_context_truncate(string $text, int $limit = 900): string
{
    $text = trim($text);
    if ($text === '' || $limit < 1) {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
    }

    if (strlen($text) <= $limit) {
        return $text;
    }

    return rtrim(substr($text, 0, $limit)) . '…';
}

function pusula_ai_chat_master_context_unique_merge(array ...$groups): array
{
    $out = [];
    $seen = [];

    foreach ($groups as $blocks) {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $q = trim((string)($block['question'] ?? $block['raw_question'] ?? ''));
            $t = trim((string)($block['section_type'] ?? 'generic_knowledge'));
            $key = mb_strtolower($q . '|' . $t, 'UTF-8');
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
            $out[] = $block;
        }
    }

    return $out;
}

function pusula_ai_chat_master_context_select_by_types(array $blocks, array $types, int $limit = 5): array
{
    $filtered = array_values(array_filter($blocks, static function ($block) use ($types): bool {
        $type = (string)($block['section_type'] ?? '');
        return in_array($type, $types, true);
    }));

    if ($limit > 0) {
        return array_slice($filtered, 0, $limit);
    }

    return $filtered;
}

function pusula_ai_chat_master_context_blocks_to_prompt_lines(array $blocks, int $limit = 5, int $maxAnswerLen = 900): array
{
    $lines = [];
    $picked = array_slice($blocks, 0, max(0, $limit));

    foreach ($picked as $block) {
        $question = trim((string)($block['question'] ?? $block['raw_question'] ?? ''));
        $answer = trim((string)($block['answer'] ?? $block['raw_answer'] ?? ''));
        $type = trim((string)($block['section_type'] ?? 'generic_knowledge'));

        if ($question === '' && $answer === '') {
            continue;
        }

        $lines[] = '- [' . ($type !== '' ? $type : 'generic_knowledge') . ']';
        if ($question !== '') {
            $lines[] = '  Soru: ' . pusula_ai_chat_master_context_truncate($question, 240);
        }
        if ($answer !== '') {
            $lines[] = '  Cevap: ' . pusula_ai_chat_master_context_truncate($answer, $maxAnswerLen);
        }
    }

    return $lines;
}

function pusula_ai_chat_prepare_master_context_layers(array $knowledge, string $userMessage, string $intent): array
{
    $enabled = ((int)($knowledge['master_context_enabled'] ?? 0) === 1);
    $text = trim((string)($knowledge['master_context_text'] ?? ''));

    if (!$enabled || $text === '') {
        return [
            'enabled' => $enabled,
            'available' => false,
            'parse_fallback_used' => false,
            'parsed_block_count' => 0,
            'forbidden_blocks' => [],
            'behavior_blocks' => [],
            'app_info_blocks' => [],
            'relevant_blocks' => [],
            'selected_titles' => [],
            'selected_types' => [],
            'fallback_raw' => '',
        ];
    }

    $blocks = pusula_ai_parse_master_context($text);
    $parseFallback = empty($blocks);

    if ($parseFallback) {
        return [
            'enabled' => true,
            'available' => true,
            'parse_fallback_used' => true,
            'parsed_block_count' => 0,
            'forbidden_blocks' => [],
            'behavior_blocks' => [],
            'app_info_blocks' => [],
            'relevant_blocks' => [],
            'selected_titles' => [],
            'selected_types' => [],
            'fallback_raw' => pusula_ai_chat_master_context_truncate($text, 5000),
        ];
    }

    $forbidden = pusula_ai_extract_rule_blocks($blocks);
    $behavior = pusula_ai_extract_behavior_blocks($blocks);
    $appInfo = pusula_ai_extract_app_info_blocks($blocks);
    $relevant = pusula_ai_find_relevant_master_context_blocks($blocks, $userMessage, 5);

    if ($intent === 'app_info') {
        $relevant = pusula_ai_chat_master_context_unique_merge($relevant, array_slice($appInfo, 0, 5));
    } elseif ($intent === 'stats_summary' || $intent === 'weakness_analysis') {
        $statsBlocks = pusula_ai_chat_master_context_select_by_types($blocks, ['stats_rule', 'behavior_rule', 'action_rule'], 4);
        $relevant = pusula_ai_chat_master_context_unique_merge($relevant, $statsBlocks);
    } elseif ($intent === 'exam_request' || $intent === 'exam_review') {
        $examBlocks = pusula_ai_chat_master_context_select_by_types($blocks, ['exam_rule', 'action_rule', 'behavior_rule'], 5);
        $relevant = pusula_ai_chat_master_context_unique_merge($relevant, $examBlocks);
    } elseif ($intent === 'motivation' || $intent === 'emotional_support') {
        $motivationBlocks = pusula_ai_chat_master_context_select_by_types($blocks, ['motivation_rule', 'behavior_rule', 'persona_rule'], 5);
        $relevant = pusula_ai_chat_master_context_unique_merge($relevant, $motivationBlocks);
    }

    $messageNorm = mb_strtolower(trim($userMessage), 'UTF-8');
    if ($messageNorm !== '' && (
        mb_strpos($messageNorm, 'link', 0, 'UTF-8') !== false
        || mb_strpos($messageNorm, 'url', 0, 'UTF-8') !== false
        || mb_strpos($messageNorm, 'harici', 0, 'UTF-8') !== false
        || mb_strpos($messageNorm, 'bağlantı', 0, 'UTF-8') !== false
    )) {
        $relevant = pusula_ai_chat_master_context_unique_merge($relevant, array_slice($forbidden, 0, 5));
    }

    $relevant = array_slice($relevant, 0, 5);

    $selectedTitles = [];
    $selectedTypes = [];
    foreach ($relevant as $block) {
        $title = trim((string)($block['question'] ?? $block['raw_question'] ?? ''));
        if ($title !== '') {
            $selectedTitles[] = $title;
        }
        $type = trim((string)($block['section_type'] ?? 'generic_knowledge'));
        if ($type !== '') {
            $selectedTypes[] = $type;
        }
    }

    return [
        'enabled' => true,
        'available' => true,
        'parse_fallback_used' => false,
        'parsed_block_count' => count($blocks),
        'forbidden_blocks' => $forbidden,
        'behavior_blocks' => $behavior,
        'app_info_blocks' => $appInfo,
        'relevant_blocks' => $relevant,
        'selected_titles' => array_values(array_unique($selectedTitles)),
        'selected_types' => array_values(array_unique($selectedTypes)),
        'fallback_raw' => '',
    ];
}

function pusula_ai_chat_trusted_context_is_empty($value): bool
{
    if (!is_array($value)) {
        return true;
    }

    foreach ($value as $item) {
        if (is_array($item)) {
            if (!pusula_ai_chat_trusted_context_is_empty($item)) {
                return false;
            }
            continue;
        }

        if ($item !== null && $item !== '' && $item !== false) {
            return false;
        }
    }

    return true;
}

function pusula_ai_chat_has_meaningful_last_exam($lastExam): bool
{
    if (!is_array($lastExam)) {
        return false;
    }

    $questionCount = max(0, (int)($lastExam['question_count'] ?? 0));
    $scorePercent = $lastExam['score_percent'] ?? $lastExam['success_rate'] ?? null;
    $scorePercent = ($scorePercent !== null && $scorePercent !== '' && is_numeric($scorePercent))
        ? (float)$scorePercent
        : null;

    if ($questionCount > 0) {
        return true;
    }

    return $scorePercent !== null;
}

function pusula_ai_chat_has_meaningful_stats_data(array $trustedContext): bool
{
    $userStats = is_array($trustedContext['user_stats'] ?? null) ? $trustedContext['user_stats'] : [];
    $weakTopics = is_array($trustedContext['weak_topics'] ?? null) ? $trustedContext['weak_topics'] : [];
    $strongTopics = is_array($trustedContext['strong_topics'] ?? null) ? $trustedContext['strong_topics'] : [];

    if (!empty($weakTopics) || !empty($strongTopics)) {
        return true;
    }

    if (!$userStats) {
        return false;
    }

    $numericFields = [
        'total_questions_solved',
        'total_correct',
        'total_wrong',
        'last_7_days_questions',
        'last_30_days_questions',
        'active_days_last_7',
        'active_days_last_30',
    ];
    foreach ($numericFields as $field) {
        if ((int)($userStats[$field] ?? 0) > 0) {
            return true;
        }
    }

    if (($userStats['accuracy_percent'] ?? null) !== null && $userStats['accuracy_percent'] !== '') {
        return true;
    }

    if (pusula_ai_chat_has_meaningful_last_exam($userStats['last_exam'] ?? null)) {
        return true;
    }

    return false;
}

function pusula_ai_chat_has_meaningful_exam_review_data(array $trustedContext): bool
{
    return pusula_ai_chat_has_meaningful_last_exam($trustedContext['last_exam'] ?? null);
}

function pusula_ai_chat_intent_fallback_text(string $intent): ?string
{
    switch ($intent) {
        case 'stats_summary':
        case 'weakness_analysis':
            return 'Şu an elimde net bir istatistik özeti çıkaracak kadar veri görünmüyor. Biraz daha soru veya deneme verisi oluştukça daha anlamlı analiz yapabilirim.';
        case 'exam_review':
            return 'Şu an son denemene ait yeterli veri görünmüyor. Yeni bir deneme tamamladığında daha net yorum yapabilirim.';
        case 'exam_request':
            return 'İstersen sana uygun bir deneme önerebilirim. Verin arttıkça bunu daha kişisel hale getirebilirim.';
        default:
            return null;
    }
}

function pusula_ai_chat_build_exam_request_reply(array $trustedContext): string
{
    $recommended = is_array($trustedContext['recommended_exam'] ?? null)
        ? $trustedContext['recommended_exam']
        : null;

    if (!$recommended) {
        return (string)(pusula_ai_chat_intent_fallback_text('exam_request') ?? 'İstersen sana uygun bir deneme önerebilirim.');
    }

    $examMode = trim((string)($recommended['exam_mode'] ?? 'mixed_review'));
    $questionCount = max(1, (int)($recommended['question_count'] ?? 20));

    if ($examMode === 'weak_topics') {
        return 'İstersen seni son dönemde zorlandığın alanlara odaklı ' . $questionCount . ' soruluk bir denemeye yönlendirebilirim.';
    }
    if ($examMode === 'last_exam_mistakes') {
        return 'İstersen son denemendeki hatalara odaklanan ' . $questionCount . ' soruluk bir denemeye yönlendirebilirim.';
    }
    if ($examMode === 'motivation_warmup') {
        return 'İstersen ritim kazanman için hafif tempolu ' . $questionCount . ' soruluk bir denemeye yönlendirebilirim.';
    }
    if ($examMode === 'one_week_focus') {
        return 'İstersen son haftadaki çalışma akışına göre ' . $questionCount . ' soruluk bir denemeye yönlendirebilirim.';
    }

    return 'İstersen seviyene uygun ' . $questionCount . ' soruluk bir denemeye yönlendirebilirim.';
}

function pusula_ai_chat_build_app_info_reply(array $trustedContext, array $knowledgeBundle = [], string $userMessage = ''): string
{
    $knowledge = is_array($knowledgeBundle['knowledge'] ?? null)
        ? $knowledgeBundle['knowledge']
        : pusula_ai_knowledge_defaults();

    $masterContextEnabled = (int)($knowledge['master_context_enabled'] ?? 0) === 1;
    $masterContextText = trim((string)($knowledge['master_context_text'] ?? ''));
    if ($masterContextEnabled && $masterContextText !== '') {
        $layers = pusula_ai_chat_prepare_master_context_layers($knowledge, $userMessage, 'app_info');
        if (!empty($layers['parse_fallback_used'])) {
            pusula_ai_chat_debug_trace('master_context_parse_failed', [
                'intent' => 'app_info',
                'parse_fallback_used' => true,
            ]);
        }

        $appBlocks = is_array($layers['app_info_blocks'] ?? null) ? $layers['app_info_blocks'] : [];
        $relevantBlocks = is_array($layers['relevant_blocks'] ?? null) ? $layers['relevant_blocks'] : [];
        $pickedBlocks = pusula_ai_chat_master_context_unique_merge($relevantBlocks, $appBlocks);

        $answerSegments = [];
        foreach (array_slice($pickedBlocks, 0, 3) as $block) {
            $answer = trim((string)($block['answer'] ?? ''));
            if ($answer === '') {
                continue;
            }
            $answerSegments[] = pusula_ai_chat_master_context_truncate($answer, 320);
        }

        if (!empty($answerSegments)) {
            return trim(implode(' ', $answerSegments));
        }

        if (!empty($layers['parse_fallback_used'])) {
            $fallbackRaw = trim((string)($layers['fallback_raw'] ?? ''));
            if ($fallbackRaw !== '') {
                return pusula_ai_chat_master_context_truncate($fallbackRaw, 550);
            }
        }
    }

    $appInfo = is_array($trustedContext['app_info'] ?? null) ? $trustedContext['app_info'] : [];
    if (empty($appInfo)) {
        $appInfo = [
            'app_summary' => (string)($knowledge['app_summary'] ?? ''),
            'app_features_text' => (string)($knowledge['app_features_text'] ?? ''),
            'premium_features_text' => (string)($knowledge['premium_features_text'] ?? ''),
            'offline_features_text' => (string)($knowledge['offline_features_text'] ?? ''),
            'community_features_text' => (string)($knowledge['community_features_text'] ?? ''),
            'exam_features_text' => (string)($knowledge['exam_features_text'] ?? ''),
        ];
    }

    $segments = [];
    foreach (['app_summary', 'app_features_text', 'exam_features_text', 'offline_features_text', 'community_features_text', 'premium_features_text'] as $field) {
        $text = trim((string)($appInfo[$field] ?? ''));
        if ($text !== '') {
            $segments[] = $text;
        }
    }

    if (empty($segments)) {
        return 'Denizci Eğitim içinde soru çözebilir, deneme sınavlarına girebilir, istatistiklerini takip edebilir, offline içerikleri indirebilir ve topluluk alanını kullanabilirsin. Premium tarafta ise Pusula Ai ve gelişmiş destek özellikleri açılır.';
    }

    $joined = implode(' ', array_slice($segments, 0, 3));
    return trim($joined);
}

function pusula_ai_chat_build_intent_safe_reply(string $intent, array $trustedContext, array $knowledgeBundle = [], string $userMessage = ''): ?string
{
    if ($intent === 'app_info') {
        return pusula_ai_chat_build_app_info_reply($trustedContext, $knowledgeBundle, $userMessage);
    }

    if ($intent === 'stats_summary' || $intent === 'weakness_analysis') {
        if (!pusula_ai_chat_has_meaningful_stats_data($trustedContext)) {
            return pusula_ai_chat_intent_fallback_text($intent);
        }
        return null;
    }

    if ($intent === 'exam_review') {
        if (!pusula_ai_chat_has_meaningful_exam_review_data($trustedContext)) {
            return pusula_ai_chat_intent_fallback_text('exam_review');
        }
        return null;
    }

    if ($intent === 'exam_request') {
        return pusula_ai_chat_build_exam_request_reply($trustedContext);
    }

    return null;
}

function pusula_ai_chat_intent_requires_strict_link_sanitizer(string $intent): bool
{
    return in_array($intent, ['exam_request', 'app_info', 'stats_summary'], true);
}

function pusula_ai_chat_remove_markdown_links(string $text): string
{
    return preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '$1', $text) ?? $text;
}

function pusula_ai_chat_remove_url_like_tokens(string $text): string
{
    $clean = $text;

    // http/https veya www ile başlayan açık linkleri kaldır.
    $clean = preg_replace('/\b(?:https?:\/\/|www\.)\S+/iu', '', $clean) ?? $clean;

    // E-posta hariç domain benzeri (foo.com, bar.net/path vb.) token'ları kaldır.
    $clean = preg_replace('/\b(?!\S+@\S+)(?:[a-z0-9-]+\.)+(?:com|net|org|io|co|ai|app|dev|info|xyz|edu|gov|me|tr)\b(?:\/\S*)?/iu', '', $clean) ?? $clean;

    return $clean;
}

function pusula_ai_chat_contains_url_like_content(string $text): bool
{
    if (preg_match('/\b(?:https?:\/\/|www\.)\S+/iu', $text) === 1) {
        return true;
    }

    if (preg_match('/\b(?!\S+@\S+)(?:[a-z0-9-]+\.)+(?:com|net|org|io|co|ai|app|dev|info|xyz|edu|gov|me|tr)\b(?:\/\S*)?/iu', $text) === 1) {
        return true;
    }

    if (preg_match('/\[[^\]]+\]\(([^)]+)\)/u', $text) === 1) {
        return true;
    }

    return false;
}

function pusula_ai_chat_safe_short_reply_for_intent(string $intent, array $trustedContext = []): string
{
    if ($intent === 'exam_request') {
        return pusula_ai_chat_build_exam_request_reply($trustedContext);
    }
    if ($intent === 'app_info') {
        return 'Uygulama içindeki özellikleri kısaca anlatabilirim ve gerekirse seni ilgili akışa yönlendirebilirim.';
    }
    if ($intent === 'stats_summary') {
        return 'İstersen mevcut verine göre kısa bir istatistik özeti paylaşabilirim.';
    }

    return 'İstersen bunu uygulama içinden güvenli şekilde birlikte ilerletebiliriz.';
}

function pusula_ai_chat_sanitize_reply_links(string $intent, string $reply, array $trustedContext = []): string
{
    $safeReply = trim($reply);
    if ($safeReply === '') {
        return $safeReply;
    }

    if (!pusula_ai_chat_intent_requires_strict_link_sanitizer($intent)) {
        return $safeReply;
    }

    // trusted backend context içinde açıkça izinli link listesi varsa tutulabilir.
    $allowedLinks = is_array($trustedContext['allowed_links'] ?? null) ? $trustedContext['allowed_links'] : [];
    if (!empty($allowedLinks)) {
        return $safeReply;
    }

    if (!pusula_ai_chat_contains_url_like_content($safeReply)) {
        return $safeReply;
    }

    $clean = pusula_ai_chat_remove_markdown_links($safeReply);
    $clean = pusula_ai_chat_remove_url_like_tokens($clean);
    $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\s+([,.;:!?])/u', '$1', $clean) ?? $clean;
    $clean = trim($clean);

    if ($clean === '' || pusula_ai_chat_contains_url_like_content($clean)) {
        return pusula_ai_chat_safe_short_reply_for_intent($intent, $trustedContext);
    }

    return pusula_ai_chat_trim_to_sentence_count($clean, 2);
}

function pusula_ai_chat_enforce_action_card_language(string $intent, string $reply, bool $hasActionPayload): string
{
    $safeReply = trim($reply);
    if ($safeReply === '') {
        return $safeReply;
    }

    if (!$hasActionPayload) {
        return $safeReply;
    }

    // Action kartı varken dış linke tıklatma dili kullanma.
    $safeReply = preg_replace('/\b(aşağıdaki\s+linke\s+tıklayın|linke\s+tıkla(?:yın)?|buradan\s+git|siteye\s+git)\b/iu', 'uygulama içinden devam edebilirsin', $safeReply) ?? $safeReply;
    $safeReply = preg_replace('/\s{2,}/u', ' ', $safeReply) ?? $safeReply;
    $safeReply = trim($safeReply);

    if ($intent === 'exam_request') {
        return pusula_ai_chat_trim_to_sentence_count($safeReply, 2);
    }

    return pusula_ai_chat_trim_to_sentence_count($safeReply, 3);
}

function pusula_ai_chat_trim_to_sentence_count(string $text, int $maxSentences): string
{
    $text = trim($text);
    $maxSentences = max(1, $maxSentences);
    if ($text === '') {
        return '';
    }

    $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
    if (count($parts) <= $maxSentences) {
        return $text;
    }

    return trim(implode(' ', array_slice($parts, 0, $maxSentences)));
}

function pusula_ai_sanitize_output(string $text): string
{
    $original = (string)$text;
    $clean = str_replace(["\r\n", "\r"], "\n", $original);

    // Markdown heading marker sızıntısını engelle.
    $clean = preg_replace('/^\s*##\s+/um', '', $clean) ?? $clean;
    $clean = preg_replace('/^\s*#\s+/um', '', $clean) ?? $clean;

    // Parse/debug sızıntılarını engelle.
    $clean = preg_replace('/\braw_question\b\s*[:=]?/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/\braw_answer\b\s*[:=]?/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/^\s*(?:debug|trace|log)\s*[:=].*$/ium', '', $clean) ?? $clean;
    $clean = preg_replace('/^\s*\[[A-Z0-9_]+\]\s*$/um', '', $clean) ?? $clean;

    // Markdown kalıntıları.
    $clean = preg_replace('/^\s*[-*]\s{0,2}#+\s+/um', '- ', $clean) ?? $clean;
    $clean = preg_replace('/`{3,}[\s\S]*?`{3,}/u', '', $clean) ?? $clean;

    // Boşluk/boş satır normalizasyonu.
    $clean = preg_replace('/[ \t]{2,}/u', ' ', $clean) ?? $clean;
    $clean = preg_replace('/\n{3,}/u', "\n\n", $clean) ?? $clean;
    $clean = preg_replace('/\s+([,.;:!?])/u', '$1', $clean) ?? $clean;
    $clean = trim($clean);

    return $clean;
}

function pusula_ai_chat_is_app_info_polish_eligible_question(string $message): bool
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '') {
        return false;
    }

    $allow = [
        'denizci eğitim nedir',
        'uygulamada neler var',
        'premium ne açıyor',
        'word game nedir',
        'kart oyunu nedir',
        'offline içerikler nedir',
        'topluluk alanı nedir',
        'pusula ai nedir',
    ];

    foreach ($allow as $term) {
        if (mb_strpos($text, $term, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

function pusula_ai_chat_polish_replace_synonyms(string $text): string
{
    // Strict preservation: sadece anlamı birebir koruyan yüzey değişimleri.
    $map = [
        'sağlar' => 'sunar',
        'geliştirilmiştir' => 'hazırlanmıştır',
        'kullanıcılara' => 'sana',
    ];

    $out = $text;
    foreach ($map as $from => $to) {
        $out = preg_replace('/\b' . preg_quote($from, '/') . '\b/iu', $to, $out) ?? $out;
    }

    return $out;
}

function pusula_ai_chat_sentence_split(string $text): array
{
    $parts = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
    $parts = array_values(array_filter(array_map(static fn($p) => trim((string)$p), $parts), static fn($p) => $p !== ''));
    return $parts;
}

function pusula_ai_chat_dedupe_sentences(array $sentences): array
{
    $out = [];
    $seen = [];
    foreach ($sentences as $sentence) {
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$sentence)) ?? trim((string)$sentence), 'UTF-8');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = trim((string)$sentence);
    }

    return $out;
}

function pusula_ai_polish_response(string $text, array $meta = []): string
{
    $original = trim((string)$text);
    if ($original === '') {
        return '';
    }

    $userMessage = mb_strtolower(trim((string)($meta['user_message'] ?? '')), 'UTF-8');
    if ($userMessage === 'selam') {
        return 'Merhaba 👋 Sana nasıl yardımcı olayım?';
    }
    if ($userMessage === 'merhaba') {
        return 'Merhaba, bugün neye ihtiyacın var?';
    }

    $intent = trim((string)($meta['intent'] ?? ''));
    $strict = !isset($meta['strict_preservation']) || !empty($meta['strict_preservation']);
    $isAppInfoScope = ($intent === 'app_info') || pusula_ai_chat_is_app_info_polish_eligible_question((string)($meta['user_message'] ?? ''));

    $polished = pusula_ai_sanitize_output($original);
    if ($polished === '') {
        return '';
    }

    $polished = preg_replace('/\s+/u', ' ', $polished) ?? $polished;
    $polished = trim($polished);

    $sentences = pusula_ai_chat_sentence_split($polished);
    $sentences = pusula_ai_chat_dedupe_sentences($sentences);
    if (!empty($sentences)) {
        $polished = implode(' ', $sentences);
    }

    // Uzun metni bilgi kaybı olmadan okunabilir seviyeye indir.
    $sentences = pusula_ai_chat_sentence_split($polished);
    if (count($sentences) > 5) {
        $polished = implode(' ', array_slice($sentences, 0, 5));
    }

    if ($strict && $isAppInfoScope) {
        $polished = pusula_ai_chat_polish_replace_synonyms($polished);
    }

    $polished = preg_replace('/\s{2,}/u', ' ', trim($polished)) ?? trim($polished);
    return $polished;
}

function pusula_ai_chat_reply_has_exam_question_text(string $reply): bool
{
    $text = mb_strtolower(trim($reply), 'UTF-8');
    if ($text === '') {
        return false;
    }

    if (preg_match('/\bsoru\s*\d+/u', $text) === 1) {
        return true;
    }
    if (preg_match('/\b[abcd]\)\s+/u', $text) === 1 || preg_match('/\b[abcd]\s*[-:]\s+/u', $text) === 1) {
        return true;
    }
    if (preg_match('/\b\d+\)\s+.*\?/u', $text) === 1) {
        return true;
    }

    return false;
}

function pusula_ai_chat_enforce_reply_style(string $intent, string $reply, array $trustedContext = []): string
{
    $safeReply = trim($reply);
    if ($safeReply === '') {
        return $safeReply;
    }

    if ($intent === 'exam_request') {
        if (pusula_ai_chat_reply_has_exam_question_text($safeReply)) {
            return pusula_ai_chat_build_exam_request_reply($trustedContext);
        }
        // Exam request'te URL/link yönlendirmesi ve uzun metin yasak.
        $safeReply = pusula_ai_chat_sanitize_reply_links($intent, $safeReply, $trustedContext);
        return pusula_ai_chat_trim_to_sentence_count($safeReply, 2);
    }

    if ($intent === 'greeting' || $intent === 'onboarding' || $intent === 'casual_followup') {
        return pusula_ai_chat_trim_to_sentence_count($safeReply, 3);
    }

    if ($intent === 'quick_help') {
        return pusula_ai_chat_trim_to_sentence_count($safeReply, 4);
    }

    if ($intent === 'emotional_support') {
        return pusula_ai_chat_trim_to_sentence_count($safeReply, 3);
    }

    return $safeReply;
}

function pusula_ai_chat_debug_trace(string $stage, array $data = []): void
{
    try {
        error_log('[pusula_ai_chat][debug][' . $stage . '] ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        // sessizce geç
    }
}

function pusula_ai_chat_build_trusted_context(PDO $pdo, string $userId, string $intent, array $knowledgeBundle = [], string $message = ''): array
{
    $tools = is_array($knowledgeBundle['tools'] ?? null)
        ? $knowledgeBundle['tools']
        : pusula_ai_tool_settings_defaults();
    $settings = is_array($knowledgeBundle['knowledge'] ?? null)
        ? $knowledgeBundle['knowledge']
        : pusula_ai_knowledge_defaults();

    $meta = [
        'intent' => $intent,
        'trusted_source' => 'backend_internal_tools',
        'generated_at' => date('c'),
    ];
    $payload = [];

    $statsEnabled = ((int)($tools['tool_stats_enabled'] ?? 1) === 1);
    $weakEnabled = ((int)($tools['tool_weak_topics_enabled'] ?? 1) === 1);
    $lastExamEnabled = ((int)($tools['tool_last_exam_enabled'] ?? 1) === 1);
    $appInfoEnabled = ((int)($tools['tool_app_info_enabled'] ?? 1) === 1);

    try {
        switch ($intent) {
            case 'stats_summary':
                if ($statsEnabled) {
                    $payload['user_stats'] = pusula_ai_tool_get_user_stats($pdo, $userId);
                }
                break;

            case 'weakness_analysis':
                if ($statsEnabled) {
                    $payload['user_stats'] = pusula_ai_tool_get_user_stats($pdo, $userId);
                }
                if ($weakEnabled) {
                    $payload['weak_topics'] = pusula_ai_tool_get_weak_topics($pdo, $userId, 3);
                    $payload['strong_topics'] = pusula_ai_tool_get_strong_topics($pdo, $userId, 3);
                }
                break;

            case 'exam_review':
                if ($lastExamEnabled) {
                    $payload['last_exam'] = pusula_ai_tool_get_last_exam_summary($pdo, $userId);
                }
                break;

            case 'exam_request':
                if ($statsEnabled) {
                    $payload['user_stats'] = pusula_ai_tool_get_user_stats($pdo, $userId);
                }
                $payload['recommended_exam'] = pusula_ai_tool_build_recommended_exam(
                    $pdo,
                    $userId,
                    array_merge($settings, ['user_message' => $message]),
                    $tools
                );
                break;

            case 'app_info':
                if ($appInfoEnabled) {
                    $payload['app_info'] = [
                        'app_name' => (string)($settings['app_name'] ?? ''),
                        'assistant_name' => (string)($settings['assistant_name'] ?? ''),
                        'app_summary' => (string)($settings['app_summary'] ?? ''),
                        'app_features_text' => (string)($settings['app_features_text'] ?? ''),
                        'premium_features_text' => (string)($settings['premium_features_text'] ?? ''),
                        'offline_features_text' => (string)($settings['offline_features_text'] ?? ''),
                        'community_features_text' => (string)($settings['community_features_text'] ?? ''),
                        'exam_features_text' => (string)($settings['exam_features_text'] ?? ''),
                    ];
                }
                break;

            default:
                // greeting/emotional_support vb. için tool zorunlu değil
                break;
        }
    } catch (Throwable $e) {
        error_log('[pusula_ai_chat][trusted_context] ' . $e->getMessage());
    }

    if (($intent === 'stats_summary' || $intent === 'weakness_analysis') && !pusula_ai_chat_has_meaningful_stats_data($payload)) {
        return $meta + [
            'intent' => $intent,
            'trusted_source' => 'backend_internal_tools',
            'available' => false,
            'note' => 'No trusted stats available. Do not fabricate stats.',
        ];
    }

    if ($intent === 'exam_review' && !pusula_ai_chat_has_meaningful_exam_review_data($payload)) {
        return $meta + [
            'intent' => $intent,
            'trusted_source' => 'backend_internal_tools',
            'available' => false,
            'note' => 'No trusted exam data available. Do not fabricate exam review stats.',
        ];
    }

    if ($intent === 'app_info' && empty($payload['app_info'])) {
        $payload['app_info'] = [
            'app_name' => (string)($settings['app_name'] ?? ''),
            'assistant_name' => (string)($settings['assistant_name'] ?? ''),
            'app_summary' => (string)($settings['app_summary'] ?? ''),
            'app_features_text' => (string)($settings['app_features_text'] ?? ''),
            'premium_features_text' => (string)($settings['premium_features_text'] ?? ''),
            'offline_features_text' => (string)($settings['offline_features_text'] ?? ''),
            'community_features_text' => (string)($settings['community_features_text'] ?? ''),
            'exam_features_text' => (string)($settings['exam_features_text'] ?? ''),
        ];
    }

    if (pusula_ai_chat_trusted_context_is_empty($payload)) {
        return $meta + [
            'intent' => $intent,
            'trusted_source' => 'backend_internal_tools',
            'available' => false,
            'note' => 'Şu an için güvenilir sayısal bağlam üretilemedi.',
        ];
    }

    return $meta + $payload + ['available' => true];
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
    $userMessage = trim((string)($meta['user_message'] ?? ''));
    $masterContextLayers = pusula_ai_chat_prepare_master_context_layers($knowledge, $userMessage, $intent);

    if (!empty($masterContextLayers['enabled'])) {
        pusula_ai_chat_debug_trace('master_context_retrieval', [
            'intent' => $intent,
            'parsed_block_count' => (int)($masterContextLayers['parsed_block_count'] ?? 0),
            'selected_block_titles' => $masterContextLayers['selected_titles'] ?? [],
            'selected_block_types' => $masterContextLayers['selected_types'] ?? [],
            'parse_fallback_used' => !empty($masterContextLayers['parse_fallback_used']),
        ]);
    }

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

    $lines[] = '[A_HARD_SAFETY_RULES]';
    $lines[] = 'Sadece denizcilik eğitimi, sınav hazırlığı, çalışma yönlendirmesi, motivasyon ve Denizci Eğitim uygulama kullanımı konularında yardımcı ol.';
    $lines[] = 'Finans, siyaset, sağlık teşhis/tedavi, yatırım, gündem, illegal içerik, hakaret/küfür taleplerinde içerik üretme.';
    $lines[] = 'Asla URL/link uydurma, asla var olmayan sayfa/modül/özellik uydurma.';
    $lines[] = 'Uygulama hakkında yanıt verirken önce MASTER_CONTEXT_DOCUMENT ve Bilgi Bankası alanlarını temel al.';
    $lines[] = 'MASTER_CONTEXT_DOCUMENT ve Bilgi Bankası kaynaklarında olmayan bir özelliği gerçekmiş gibi yazma.';
    $lines[] = '“Uygulamada ne yapabilirim?” gibi sorularda genel internet cevabı verme; yalnızca Denizci Eğitim uygulamasını anlat.';

    if (!empty($masterContextLayers['available'])) {
        $forbiddenLines = pusula_ai_chat_master_context_blocks_to_prompt_lines((array)($masterContextLayers['forbidden_blocks'] ?? []), 8, 800);
        if (!empty($forbiddenLines)) {
            $lines[] = '[B_MASTER_FORBIDDEN_RULES]';
            $lines[] = 'Aşağıdaki yasak/risk kuralları yüksek önceliklidir. Link/URL/harici kaynak yönlendirmesinde bu katmana uy.';
            $lines = array_merge($lines, $forbiddenLines);
        }

        $behaviorLines = pusula_ai_chat_master_context_blocks_to_prompt_lines((array)($masterContextLayers['behavior_blocks'] ?? []), 8, 700);
        if (!empty($behaviorLines)) {
            $lines[] = '[B_MASTER_BEHAVIOR_RULES]';
            $lines[] = 'Aşağıdaki davranış/persona/motivasyon kuralları yanıt tarzına doğrudan uygulanmalıdır.';
            $lines = array_merge($lines, $behaviorLines);
        }

        $appInfoLines = pusula_ai_chat_master_context_blocks_to_prompt_lines((array)($masterContextLayers['app_info_blocks'] ?? []), 8, 700);
        if (!empty($appInfoLines)) {
            $lines[] = '[B_MASTER_APP_INFO_BLOCKS]';
            $lines[] = 'Uygulama/premium/özellik sorularında öncelik bu bloklardadır.';
            $lines = array_merge($lines, $appInfoLines);
        }

        $relevantLines = pusula_ai_chat_master_context_blocks_to_prompt_lines((array)($masterContextLayers['relevant_blocks'] ?? []), 5, 800);
        if (!empty($relevantLines)) {
            $lines[] = '[B_MASTER_RELEVANT_QA_BLOCKS]';
            $lines[] = 'Kullanıcı mesajı için seçilen en ilgili soru-cevap blokları:';
            $lines = array_merge($lines, $relevantLines);
        }

        if (!empty($masterContextLayers['parse_fallback_used'])) {
            $fallbackRaw = trim((string)($masterContextLayers['fallback_raw'] ?? ''));
            if ($fallbackRaw !== '') {
                $lines[] = '[B_MASTER_CONTEXT_FALLBACK_RAW]';
                $lines[] = 'Parser başarısız olduğu için güvenli fallback olarak ham metnin kısaltılmış hali kullanıldı:';
                $lines[] = $fallbackRaw;
            }
        }
    }

    $lines[] = '[C_IDENTITY]';
    $lines[] = 'Asistan adı: ' . trim((string)($knowledge['assistant_name'] ?? 'Pusula Ai'));
    $lines[] = 'Uygulama adı: ' . trim((string)($knowledge['app_name'] ?? 'Denizci Eğitim'));
    $tone = trim((string)($knowledge['tone_of_voice'] ?? 'Samimi, profesyonel, kısa ve insan gibi.'));
    $lines[] = 'Ton: ' . ($tone !== '' ? $tone : 'Samimi, profesyonel, kısa ve insan gibi.');

    $lines[] = '[D_KNOWLEDGE_BASE]';
    $summary = trim((string)($knowledge['app_summary'] ?? ''));
    if ($summary !== '') {
        $lines[] = 'Uygulama özeti: ' . $summary;
    }
    $targets = trim((string)($knowledge['target_users'] ?? ''));
    if ($targets !== '') {
        $lines[] = 'Hedef kullanıcılar: ' . $targets;
    }

    $lines[] = '[D_APP_FEATURES]';
    foreach (['app_features_text', 'premium_features_text', 'offline_features_text', 'community_features_text', 'exam_features_text'] as $field) {
        $val = trim((string)($knowledge[$field] ?? ''));
        if ($val !== '') {
            $lines[] = $field . ': ' . $val;
        }
    }

    $lines[] = '[D_BEHAVIOR_RULES]';
    $behaviorDefaults = [
        'allowed_topics_text' => 'Denizci Eğitim uygulaması, denizcilik eğitimi, sınav hazırlığı, çalışma yönlendirmesi ve uygulama içi özellikler.',
        'blocked_topics_text' => 'Finans, siyaset, sağlık teşhis, gündem, yatırım, genel yaşam/ilişki tavsiyesi ve kapsam dışı alanlar.',
        'response_style_text' => 'Kısa, net, doğal ve kullanıcı odaklı yanıt ver.',
        'emotional_style_text' => 'Önce duyguya temas et, sonra küçük ve uygulanabilir yönlendirme ver.',
        'short_reply_rules_text' => 'Kullanıcı kısa yazdıysa kısa yanıt ver; gereksiz paragraf ve madde listesi kurma.',
        'long_reply_rules_text' => 'Sadece kullanıcı detay isterse daha uzun anlat; gereksiz tekrar yapma.',
    ];
    foreach ($behaviorDefaults as $field => $defaultText) {
        $val = trim((string)($knowledge[$field] ?? ''));
        $lines[] = $field . ': ' . ($val !== '' ? $val : $defaultText);
    }

    $lines[] = '[D_SYSTEM_PROMPT_LAYERS]';
    $systemLayerDefaults = [
        'system_prompt_base' => 'Sadece güvenilir backend bağlamına dayan. Bilgi uydurma.',
        'system_prompt_behavior' => 'Yanıtı niyete göre uyarla, kısa mesajlarda kısa kal. Dış link/URL verme; app içi aksiyonları doğal kısa cümleyle anlat.',
        'system_prompt_app_knowledge' => 'Uygulama bilgisi yanıtlarında sadece Denizci Eğitim bilgi bankası içeriğini kullan. Dış web yönlendirmesi ve link üretimi yapma.',
        'system_prompt_stats_behavior' => 'Trusted istatistik yoksa performans analizi yapma; açık fallback ver. URL/link üretme.',
        'system_prompt_exam_behavior' => 'Deneme isteğinde soru metni üretme; kısa öneri + action odaklı kal. URL/link kesinlikle verme.',
    ];
    foreach ($systemLayerDefaults as $field => $defaultText) {
        $val = trim((string)($knowledge[$field] ?? ''));
        $lines[] = $field . ': ' . ($val !== '' ? $val : $defaultText);
    }

    if (!empty($examples)) {
        $lines[] = '[E_ACTIVE_EXAMPLE_CONVERSATIONS]';
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

    $lines[] = '[F_TOOL_PERMISSIONS]';
    foreach (['tool_stats_enabled', 'tool_exam_recommendation_enabled', 'tool_app_info_enabled', 'tool_action_payload_enabled', 'tool_weak_topics_enabled', 'tool_last_exam_enabled'] as $toolField) {
        $lines[] = $toolField . ': ' . (((int)($tools[$toolField] ?? 0) === 1) ? '1' : '0');
    }

    $lines[] = '[FALLBACK_CORE_RULES]';
    $lines[] = 'Sadece denizcilik eğitimi, sınav hazırlığı, çalışma yönlendirmesi, motivasyon ve uygulama kullanımı konularında yardımcı ol.';
    $lines[] = 'Finans, siyaset, sağlık, gündem, yatırım, genel yaşam ve ilişki tavsiyesi gibi konularda yardımcı olabileceğini ASLA söyleme.';
    $lines[] = 'Kullanıcıyı gereksiz yere kapsam dışı sayma; yalnızca net alakasız veya yasak içeriklerde reddet.';
    $lines[] = 'Asla küfür, hakaret, uygunsuz veya illegal içerik üretme.';
    $lines[] = 'Asla uydurma bilgi verme. Emin değilsen açıkça belirt.';

    $trustedContext = is_array($meta['trusted_context'] ?? null) ? $meta['trusted_context'] : [];

    $lines[] = '[HALLUCINATION_GUARDRAILS]';
    $lines[] = 'Sayı, yüzde, başarı oranı, soru sayısı, deneme skoru UYDURMA.';
    $lines[] = 'Sayısal veri için yalnızca TRUSTED_USER_CONTEXT içindeki değerleri kullan.';
    $lines[] = 'TRUSTED_USER_CONTEXT içinde olmayan hiçbir sayısal çıkarımı yazma.';
    $lines[] = 'Trusted veri yoksa sayısal analiz yapma ve bunu açıkça belirt.';
    $lines[] = 'TRUSTED_USER_CONTEXT içinde topic/course adı yoksa zayıf alan adı uydurma.';
    $lines[] = '"Son 5 denemede %70" gibi ifadeleri trusted veri olmadan ASLA kurma.';
    $lines[] = 'exam_request niyetinde düz metin soru, şık veya test içeriği üretmek YASAK.';
    $lines[] = 'Kullanıcı deneme istediğinde yalnızca kısa öneri + action payload odaklı kal.';
    $lines[] = 'Asla URL uydurma, asla link verme, asla web sayfası adı uydurma.';
    $lines[] = 'Asla “aşağıdaki linke tıklayın” veya benzeri bir yönlendirme yazma.';
    $lines[] = 'Backend tarafından özellikle ve güvenilir şekilde sağlanmadıkça hiçbir URL yazma.';
    $lines[] = 'Markdown link üretme; http:// veya https:// ile başlayan metin üretme.';
    $lines[] = 'Uygulama içinde aksiyon gerekiyorsa yalnızca kısa doğal açıklama + action_payload yaklaşımı kullan.';

    $lines[] = '[G_TRUSTED_USER_CONTEXT]';
    if (!empty($trustedContext)) {
        $lines[] = pusula_ai_chat_json_encode($trustedContext);
    } else {
        $lines[] = '{"available":false,"note":"Şu an elimde net veri görünmüyor."}';
    }

    if ($userMessage !== '') {
        $lines[] = '[H_USER_MESSAGE]';
        $lines[] = $userMessage;
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
            $lines[] = 'Yanıt biçimi: 1-3 kısa, sıcak cümle; gerekirse tek bir soru ile devam et.';
            $lines[] = 'Selamlaşmada asla uzun plan, zayıf konu dökümü veya paragraf analiz verme.';
            break;
        case 'onboarding':
            $lines[] = 'Yanıt biçimi: kısa tanıtım + kullanıcıya 1-2 seçenek sunan soru.';
            break;
        case 'quick_help':
            $lines[] = 'Yanıt biçimi: 2-4 cümle, hızlı ve net yardım.';
            break;
        case 'stats_summary':
            $lines[] = 'Yanıt biçimi: önce eldeki verilerle net bir özet ver, sonra kısa yönlendirme yap.';
            $lines[] = 'Asla “hangi istatistikleri istersin?” diye geri soru ile başlama.';
            if (!pusula_ai_chat_has_meaningful_stats_data($trustedContext)) {
                $lines[] = 'No trusted stats available. Do not fabricate stats.';
                $lines[] = 'Fallback: "Şu an elimde net bir istatistik özeti çıkaracak kadar veri görünmüyor. Biraz daha soru veya deneme verisi oluştukça daha anlamlı analiz yapabilirim."';
            }
            break;
        case 'app_info':
            $lines[] = 'Yanıt biçimi: Denizci Eğitim uygulamasını gerçek özelliklerle anlat.';
            $lines[] = 'Öncelikle app_features_text/premium_features_text/offline_features_text/community_features_text/exam_features_text alanlarını temel al.';
            $lines[] = 'Genel eğitim tavsiyesi üretip uygulama bilgisinden kopma.';
            $lines[] = 'App info cevabını yalnızca app_summary, app_features_text, premium_features_text, offline_features_text, community_features_text, exam_features_text alanlarından kur.';
            $lines[] = 'Dış site/link/URL verme; app içi işlemleri doğal cümleyle anlat.';
            break;
        case 'study_plan':
            $lines[] = 'Yanıt biçimi: kısa yönlendirme + mini plan. Liste gerekiyorsa en fazla 2-3 madde.';
            break;
        case 'weakness_analysis':
            $lines[] = 'Yanıt biçimi: kısa analiz + bir sonraki adım. Gereksiz uzun rapordan kaçın.';
            if (!pusula_ai_chat_has_meaningful_stats_data($trustedContext)) {
                $lines[] = 'No trusted stats available. Do not fabricate stats.';
                $lines[] = 'Fallback: "Şu an elimde net bir istatistik özeti çıkaracak kadar veri görünmüyor. Biraz daha soru veya deneme verisi oluştukça daha anlamlı analiz yapabilirim."';
            }
            break;
        case 'exam_request':
            $lines[] = 'Yanıt biçimi: kısa doğal öneri ver; soru listesi veya düz metin soru üretme.';
            $lines[] = 'Deneme isteğinde odak: uygun deneme modunu öner (weak_topics, last_exam_mistakes, mixed_review, motivation_warmup, one_week_focus).';
            $lines[] = 'exam_request için yalnızca kısa öneri + action mantığı uygula; soru metni yazma.';
            $lines[] = 'exam_request içinde link, URL, yönlendirme adresi, markdown link, web sitesi adı yazma.';
            break;
        case 'motivation':
            $lines[] = 'Yanıt biçimi: önce empati, sonra kısa destek ve uygulanabilir küçük adım.';
            break;
        case 'emotional_support':
            $lines[] = 'Önce duyguyu anla ve yansıt; sonra sakin, kısa ve yargılamayan bir yön ver.';
            $lines[] = 'Direkt ders listesine atlama, klişe kişisel gelişim cümlesi kurma, samimi ama laubali olma.';
            break;
        case 'explanation':
            $lines[] = 'Yanıt biçimi: orta uzunlukta, sade ve anlaşılır anlatım.';
            break;
        case 'exam_review':
            $lines[] = 'Yanıt biçimi: orta uzunlukta değerlendirme, 2-3 net çıkarım ve devam adımı.';
            if (!pusula_ai_chat_has_meaningful_exam_review_data($trustedContext)) {
                $lines[] = 'No trusted stats available. Do not fabricate stats.';
                $lines[] = 'Fallback: "Şu an son denemene ait yeterli veri görünmüyor. Yeni bir deneme tamamladığında daha net yorum yapabilirim."';
            }
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

    return pusula_ai_chat_detect_action_payload_from_bundle($message, [
        'knowledge' => $settings,
        'tools' => $tools,
    ], [
        'pdo' => (!empty($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null,
    ]);
}

function pusula_ai_chat_detect_action_payload_from_bundle(string $message, array $knowledgeBundle = [], array $meta = []): ?array
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

    $intent = trim((string)($meta['intent'] ?? ''));
    if ($intent === '') {
        $intent = detectIntent($message);
    }
    $userContext = is_array($meta['user_context'] ?? null) ? $meta['user_context'] : [];
    $trustedContext = is_array($meta['trusted_context'] ?? null) ? $meta['trusted_context'] : [];
    $pdo = ($meta['pdo'] ?? null) instanceof PDO ? $meta['pdo'] : null;
    $userId = trim((string)($meta['user_id'] ?? ''));

    $text = mb_strtolower(trim($message), 'UTF-8');

    $examIntent = $intent === 'exam_request' || pusula_ai_chat_contains_any($text, [
        'deneme', 'sınav oluştur', 'soru çözüm denemesi', '20 soruluk', '10 soruluk', 'yanlış yaptığım konulardan'
    ]);
    if ($examIntent
        && (int)($tools['tool_exam_recommendation_enabled'] ?? 1) === 1
        && (int)($settings['action_exam_enabled'] ?? 1) === 1) {

        $trustedRecommended = is_array($trustedContext['recommended_exam'] ?? null)
            ? $trustedContext['recommended_exam']
            : null;
        if ($trustedRecommended) {
            return $trustedRecommended;
        }

        if ($pdo instanceof PDO && $userId !== '') {
            $fromTool = pusula_ai_tool_build_recommended_exam(
                $pdo,
                $userId,
                array_merge($settings, ['user_message' => $message]),
                $tools
            );
            if (is_array($fromTool)) {
                return $fromTool;
            }
        }

        $supportedModes = ['weak_topics', 'last_exam_mistakes', 'mixed_review', 'motivation_warmup', 'one_week_focus'];
        $defaultMode = strtolower(trim((string)($settings['action_exam_default_mode'] ?? '')));
        if (!in_array($defaultMode, $supportedModes, true)) {
            $defaultMode = 'mixed_review';
        }

        $examMode = $defaultMode;
        $reason = 'default_mode';
        $title = 'Önerilen Deneme';

        $weakTopics = is_array($userContext['weak_topics'] ?? null) ? $userContext['weak_topics'] : [];
        $lastExam = is_array($userContext['last_exam'] ?? null) ? $userContext['last_exam'] : [];
        $lastExamWrong = (int)($lastExam['wrong_count'] ?? 0);
        $last7Count = (int)($userContext['last_7_days_question_count'] ?? 0);

        if ((int)($tools['tool_weak_topics_enabled'] ?? 1) === 1 && !empty($weakTopics)) {
            $examMode = 'weak_topics';
            $reason = 'recent_weak_topics';
            $title = 'Zayıf Alanlara Odaklı Deneme';
        } elseif ((int)($tools['tool_last_exam_enabled'] ?? 1) === 1 && $lastExamWrong > 0) {
            $examMode = 'last_exam_mistakes';
            $reason = 'last_exam_mistakes';
            $title = 'Son Deneme Hatalarına Odaklı Deneme';
        } elseif ($last7Count >= 30) {
            $examMode = 'one_week_focus';
            $reason = 'one_week_activity_focus';
            $title = 'Son 1 Haftaya Odaklı Deneme';
        } elseif ($last7Count <= 5) {
            $examMode = 'motivation_warmup';
            $reason = 'motivation_warmup';
            $title = 'Isınma Denemesi';
        } elseif ($examMode === 'mixed_review') {
            $reason = 'balanced_review';
            $title = 'Karma Tekrar Denemesi';
        }

        return [
            'type' => 'recommended_exam',
            'title' => $title,
            'exam_mode' => $examMode,
            'question_count' => max(1, (int)($settings['action_exam_default_question_count'] ?? 20)),
            'reason' => $reason,
        ];
    }

    $planIntent = $intent === 'study_plan' || pusula_ai_chat_contains_any($text, ['çalışma planı', 'plan yap', 'program yap', 'çalışma programı']);
    if ($planIntent && (int)($settings['action_plan_enabled'] ?? 1) === 1) {
        return [
            'type' => 'study_plan',
            'title' => 'Kısa Çalışma Planı',
            'plan_mode' => 'daily_focus',
        ];
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

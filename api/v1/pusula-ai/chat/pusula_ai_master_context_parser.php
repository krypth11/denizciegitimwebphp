<?php

function pusula_ai_master_context_normalize_line(string $line): string
{
    $line = str_replace(["\r\n", "\r"], "\n", $line);
    $line = preg_replace('/[\t ]+/u', ' ', $line) ?? $line;
    return trim($line);
}

function pusula_ai_master_context_strip_parse_markers(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/^\s*#{1,6}\s+/um', '', $text) ?? $text;
    $text = preg_replace('/^\s*(raw_question|raw_answer)\s*[:=]\s*/ium', '', $text) ?? $text;
    $text = preg_replace('/^\s*\[[A-Z0-9_]+\]\s*$/um', '', $text) ?? $text;
    return trim($text);
}

function pusula_ai_master_context_normalize_multiline(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);

    foreach ($lines as &$line) {
        if (trim($line) === '') {
            $line = '';
            continue;
        }
        $line = preg_replace('/[\t ]+/u', ' ', trim((string)$line)) ?? trim((string)$line);
    }
    unset($line);

    $text = implode("\n", $lines);
    $text = pusula_ai_master_context_strip_parse_markers($text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

    return trim($text);
}

function pusula_ai_master_context_normalize_for_match(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function pusula_ai_parse_master_context(?string $text): array
{
    $source = trim((string)$text);
    if ($source === '') {
        return [];
    }

    $source = str_replace(["\r\n", "\r"], "\n", $source);
    $lines = explode("\n", $source);

    $blocks = [];
    $currentQuestion = null;
    $currentRawQuestion = null;
    $answerLines = [];
    $rawAnswerLines = [];
    $answerStarted = false;

    $pushCurrent = static function () use (&$blocks, &$currentQuestion, &$currentRawQuestion, &$answerLines, &$rawAnswerLines, &$answerStarted): void {
        if ($currentQuestion === null && empty($answerLines) && empty($rawAnswerLines)) {
            return;
        }

        $rawQuestion = trim((string)$currentRawQuestion);
        $question = pusula_ai_master_context_normalize_line((string)$currentQuestion);
        $rawAnswer = trim(implode("\n", $rawAnswerLines));
        $answer = pusula_ai_master_context_normalize_multiline(implode("\n", $answerLines));

        if ($question === '' && $rawQuestion === '' && $answer === '' && $rawAnswer === '') {
            $currentQuestion = null;
            $currentRawQuestion = null;
            $answerLines = [];
            $rawAnswerLines = [];
            $answerStarted = false;
            return;
        }

        $sectionType = pusula_ai_classify_master_context_block($question !== '' ? $question : $rawQuestion, $answer);

        $blocks[] = [
            'question' => $question,
            'answer' => $answer,
            'section_type' => $sectionType,
            'raw_question' => $rawQuestion,
            'raw_answer' => $rawAnswer,
        ];

        $currentQuestion = null;
        $currentRawQuestion = null;
        $answerLines = [];
        $rawAnswerLines = [];
        $answerStarted = false;
    };

    foreach ($lines as $line) {
        $rawLine = rtrim((string)$line);
        $trimmed = ltrim($rawLine);

        if (preg_match('/^#\s+(.+)$/u', $trimmed, $m) === 1 && strpos($trimmed, '## ') !== 0) {
            $pushCurrent();
            $currentRawQuestion = trim((string)$m[1]);
            $currentQuestion = pusula_ai_master_context_normalize_line((string)$m[1]);
            continue;
        }

        if (preg_match('/^##\s*(.*)$/u', $trimmed, $m) === 1) {
            $answerStarted = true;
            $rawAnswerStart = trim((string)$m[1]);
            if ($rawAnswerStart !== '') {
                $rawAnswerLines[] = $rawAnswerStart;
                $answerLines[] = $rawAnswerStart;
            }
            continue;
        }

        if ($answerStarted) {
            $rawAnswerLines[] = $rawLine;
            $answerLines[] = $rawLine;
            continue;
        }

        // Format bozuksa kırmadan güvenli şekilde yakala.
        if ($currentQuestion !== null) {
            $answerStarted = true;
            $rawAnswerLines[] = $rawLine;
            $answerLines[] = $rawLine;
        }
    }

    $pushCurrent();

    return $blocks;
}

function pusula_ai_classify_master_context_block(string $question, string $answer): string
{
    $text = pusula_ai_master_context_normalize_for_match($question . ' ' . $answer);

    $has = static function (array $terms) use ($text): bool {
        foreach ($terms as $term) {
            $t = pusula_ai_master_context_normalize_for_match((string)$term);
            if ($t !== '' && mb_strpos($text, $t, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    };

    if ($has(['neleri yapmamalıdır', 'bağlantılar ve yönlendirmeler', 'link üretme', 'harici kaynak', 'dış bağlantı', 'url verme', 'link verme', 'yasak'])) {
        return 'forbidden_rule';
    }
    if ($has(['nasıl davranmalıdır', 'deneme sonrası', 'deneme isterse', 'deneme oluştur', 'hangi durumda', 'yönlendirme', 'navigate', 'navigasyon'])) {
        return 'action_rule';
    }
    if ($has(['nasıl hitap etmelidir', 'konuşma karakteri', 'persona', 'hitap'])) {
        return 'persona_rule';
    }
    if ($has(['cevap uzunluğu', 'iletişim tarzı', 'yanıt biçimi', 'kısa cevap', 'uzun cevap'])) {
        return 'behavior_rule';
    }
    if ($has(['motivasyon', 'moral', 'başarısız hissediyorsa', 'ara verdiyse', 'destek'])) {
        return 'motivation_rule';
    }
    if ($has(['istatistik', 'nasıl yorumlamalıdır', 'performans'])) {
        return 'stats_rule';
    }
    if ($has(['deneme sınavı', 'deneme sonrası', 'sınav'])) {
        return 'exam_rule';
    }
    if ($has(['premium'])) {
        return 'premium_info';
    }
    if ($has(['uygulama', 'çalışma alanı', 'offline', 'topluluk', 'word game', 'kart oyunu', 'maritime english'])) {
        return 'app_info';
    }
    if ($has(['özellik', 'modül', 'pusula ai nelerde yardımcı olabilir', 'yardımcı olabilir'])) {
        return 'feature_info';
    }

    return 'generic_knowledge';
}

function pusula_ai_master_context_tokenize(string $text): array
{
    $normalized = pusula_ai_master_context_normalize_for_match($text);
    if ($normalized === '') {
        return [];
    }

    $parts = preg_split('/\s+/u', $normalized) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $token = trim((string)$part);
        if ($token === '' || mb_strlen($token, 'UTF-8') < 2) {
            continue;
        }
        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

function pusula_ai_find_relevant_master_context_blocks(array $blocks, string $userMessage, int $limit = 5): array
{
    if (empty($blocks)) {
        return [];
    }

    $safeLimit = max(1, min(8, $limit));
    $messageNorm = pusula_ai_master_context_normalize_for_match($userMessage);
    $messageTokens = pusula_ai_master_context_tokenize($userMessage);
    $messageTokenMap = array_fill_keys($messageTokens, true);

    $intentBoosts = [
        'premium' => ['premium_info', 'app_info', 'feature_info'],
        'uygulama' => ['app_info', 'feature_info'],
        'deneme' => ['exam_rule', 'action_rule'],
        'istatistik' => ['stats_rule'],
        'motivasyon' => ['motivation_rule', 'behavior_rule', 'persona_rule'],
        'moral' => ['motivation_rule', 'behavior_rule'],
        'yönlendir' => ['action_rule', 'app_info'],
        'gönder' => ['action_rule', 'app_info'],
        'aç' => ['action_rule', 'app_info'],
        'git' => ['action_rule', 'app_info'],
        'navigasyon' => ['action_rule', 'app_info'],
        'link' => ['forbidden_rule'],
        'harici' => ['forbidden_rule'],
    ];

    $scored = [];
    foreach ($blocks as $idx => $block) {
        $question = (string)($block['question'] ?? '');
        $answer = (string)($block['answer'] ?? '');
        $sectionType = (string)($block['section_type'] ?? 'generic_knowledge');

        $qNorm = pusula_ai_master_context_normalize_for_match($question);
        $aNorm = pusula_ai_master_context_normalize_for_match($answer);

        $score = 0.0;
        if ($messageNorm !== '') {
            if ($qNorm !== '' && mb_strpos($qNorm, $messageNorm, 0, 'UTF-8') !== false) {
                $score += 14;
            }
            if ($aNorm !== '' && mb_strpos($aNorm, $messageNorm, 0, 'UTF-8') !== false) {
                $score += 6;
            }
        }

        $qTokens = pusula_ai_master_context_tokenize($question);
        $aTokens = pusula_ai_master_context_tokenize($answer);

        foreach ($qTokens as $token) {
            if (isset($messageTokenMap[$token])) {
                $score += 3.0;
            }
        }
        foreach ($aTokens as $token) {
            if (isset($messageTokenMap[$token])) {
                $score += 1.0;
            }
        }

        foreach ($intentBoosts as $needle => $types) {
            if ($messageNorm !== '' && mb_strpos($messageNorm, $needle, 0, 'UTF-8') !== false && in_array($sectionType, $types, true)) {
                $score += 5.0;
            }
        }

        if ($score <= 0) {
            continue;
        }

        $scored[] = [
            'idx' => $idx,
            'score' => $score,
            'block' => $block,
        ];
    }

    if (empty($scored)) {
        return array_slice($blocks, 0, min($safeLimit, count($blocks)));
    }

    usort($scored, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return $a['idx'] <=> $b['idx'];
        }
        return ($a['score'] > $b['score']) ? -1 : 1;
    });

    $result = [];
    $seen = [];
    foreach ($scored as $item) {
        $block = $item['block'];
        $key = mb_strtolower(trim((string)($block['question'] ?? '')) . '|' . trim((string)($block['section_type'] ?? '')), 'UTF-8');
        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        if ($key !== '') {
            $seen[$key] = true;
        }

        $result[] = $block;
        if (count($result) >= $safeLimit) {
            break;
        }
    }

    return $result;
}

function pusula_ai_extract_rule_blocks(array $blocks): array
{
    return array_values(array_filter($blocks, static function ($block): bool {
        $type = (string)($block['section_type'] ?? '');
        return $type === 'forbidden_rule';
    }));
}

function pusula_ai_extract_behavior_blocks(array $blocks): array
{
    return array_values(array_filter($blocks, static function ($block): bool {
        $type = (string)($block['section_type'] ?? '');
        return in_array($type, ['behavior_rule', 'persona_rule', 'motivation_rule'], true);
    }));
}

function pusula_ai_extract_app_info_blocks(array $blocks): array
{
    return array_values(array_filter($blocks, static function ($block): bool {
        $type = (string)($block['section_type'] ?? '');
        return in_array($type, ['app_info', 'premium_info', 'feature_info'], true);
    }));
}
<?php

function pusula_ai_response_polisher_words(string $text): int
{
    $parts = preg_split('/\s+/u', trim($text)) ?: [];
    $parts = array_values(array_filter($parts, static fn($v) => $v !== ''));
    return count($parts);
}

function pusula_ai_response_polisher_lines(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return explode("\n", $text);
}

function pusula_ai_response_polisher_normalize_spaces(string $text): string
{
    $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
    return trim($text);
}

function pusula_ai_response_polisher_is_short_greeting(string $userMessage, string $intent): bool
{
    if ($intent !== 'greeting' && $intent !== 'casual_followup') {
        return false;
    }

    $normalized = mb_strtolower(trim($userMessage), 'UTF-8');
    if ($normalized === '') {
        return false;
    }

    $known = ['selam', 'merhaba', 'selamlar', 'hey', 'slm'];
    if (in_array($normalized, $known, true)) {
        return true;
    }

    return pusula_ai_response_polisher_words($normalized) <= 2;
}

function pusula_ai_response_polisher_parse_list_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    if (preg_match('/^\s*[-*•]\s+(.+)$/u', $line, $m) === 1) {
        return ['type' => 'bullet', 'text' => trim((string)$m[1])];
    }

    if (preg_match('/^\s*(\d+)[\)\.:\-]\s+(.+)$/u', $line, $m) === 1) {
        return ['type' => 'numbered', 'number' => (string)$m[1], 'text' => trim((string)$m[2])];
    }

    return null;
}

function pusula_ai_response_polisher_render_list(array $items): string
{
    $out = [];
    foreach ($items as $item) {
        $type = (string)($item['type'] ?? 'bullet');
        $text = trim((string)($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        if ($type === 'numbered') {
            $num = trim((string)($item['number'] ?? ''));
            $out[] = ($num !== '' ? $num . '. ' : '• ') . $text;
            continue;
        }
        $out[] = '• ' . $text;
    }

    return implode("\n", $out);
}

function pusula_ai_response_polisher_split_sentences(string $text): array
{
    $parts = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn($v) => $v !== ''));
}

function pusula_ai_response_polisher_split_long_sentence_by_words(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $words = preg_split('/\s+/u', $text) ?: [];
    $words = array_values(array_filter($words, static fn($w) => $w !== ''));
    if (count($words) < 40) {
        return $text;
    }

    $mid = (int)ceil(count($words) / 2);
    $first = trim(implode(' ', array_slice($words, 0, $mid)));
    $second = trim(implode(' ', array_slice($words, $mid)));
    if ($first === '' || $second === '') {
        return $text;
    }

    return $first . "\n\n" . $second;
}

function pusula_ai_response_polisher_maybe_split_long_paragraph(string $paragraph): string
{
    $paragraph = pusula_ai_response_polisher_normalize_spaces($paragraph);
    if ($paragraph === '') {
        return '';
    }

    if (pusula_ai_response_polisher_words($paragraph) < 40) {
        return $paragraph;
    }

    $sentences = pusula_ai_response_polisher_split_sentences($paragraph);
    if (count($sentences) < 2) {
        return pusula_ai_response_polisher_split_long_sentence_by_words($paragraph);
    }

    $mid = (int)ceil(count($sentences) / 2);
    $first = trim(implode(' ', array_slice($sentences, 0, $mid)));
    $second = trim(implode(' ', array_slice($sentences, $mid)));
    if ($first === '' || $second === '') {
        return $paragraph;
    }

    return $first . "\n\n" . $second;
}

function pusula_ai_response_polisher_extract_comma_list(string $text): ?array
{
    $text = pusula_ai_response_polisher_normalize_spaces($text);
    if ($text === '') {
        return null;
    }

    $intro = '';
    $body = $text;
    if (mb_strpos($text, ':', 0, 'UTF-8') !== false) {
        [$left, $right] = explode(':', $text, 2);
        $left = trim((string)$left);
        $right = trim((string)$right);
        if ($left !== '' && $right !== '') {
            $intro = $left . ':';
            $body = $right;
        }
    }

    $rawItems = preg_split('/\s*[,;]\s*/u', $body) ?: [];
    $items = [];
    foreach ($rawItems as $raw) {
        $item = trim((string)$raw, " \t\n\r\0\x0B.");
        if ($item === '') {
            continue;
        }
        if (pusula_ai_response_polisher_words($item) > 8) {
            return null;
        }
        $items[] = $item;
    }

    if (count($items) < 4) {
        return null;
    }

    return ['intro' => $intro, 'items' => $items];
}

function pusula_ai_response_polisher_render_mixed_blocks(array $lines): ?string
{
    $blocks = [];
    $currentParagraph = [];
    $currentList = [];
    $hasExplicitList = false;

    $flushParagraph = static function () use (&$blocks, &$currentParagraph): void {
        if (empty($currentParagraph)) {
            return;
        }

        $normalized = array_values(array_filter(array_map(static function ($line) {
            return pusula_ai_response_polisher_normalize_spaces((string)$line);
        }, $currentParagraph), static fn($v) => $v !== ''));

        if (!empty($normalized)) {
            $blocks[] = ['type' => 'paragraph', 'lines' => $normalized];
        }
        $currentParagraph = [];
    };

    $flushList = static function () use (&$blocks, &$currentList): void {
        if (empty($currentList)) {
            return;
        }
        $blocks[] = ['type' => 'list', 'items' => $currentList];
        $currentList = [];
    };

    foreach ($lines as $line) {
        $rawLine = (string)$line;
        $trimmed = trim($rawLine);
        if ($trimmed === '') {
            $flushParagraph();
            $flushList();
            continue;
        }

        $parsed = pusula_ai_response_polisher_parse_list_line($rawLine);
        if ($parsed !== null) {
            $hasExplicitList = true;
            $flushParagraph();
            $currentList[] = $parsed;
            continue;
        }

        $flushList();
        $currentParagraph[] = $trimmed;
    }

    $flushParagraph();
    $flushList();

    if (!$hasExplicitList) {
        return null;
    }

    $out = [];
    foreach ($blocks as $block) {
        $type = (string)($block['type'] ?? '');
        if ($type === 'list') {
            $rendered = pusula_ai_response_polisher_render_list(is_array($block['items'] ?? null) ? $block['items'] : []);
            if ($rendered !== '') {
                $out[] = $rendered;
            }
            continue;
        }

        $paragraphLines = is_array($block['lines'] ?? null) ? $block['lines'] : [];
        if (empty($paragraphLines)) {
            continue;
        }
        $paragraphText = pusula_ai_response_polisher_maybe_split_long_paragraph(implode(' ', $paragraphLines));
        if ($paragraphText !== '') {
            $out[] = $paragraphText;
        }
    }

    return trim(implode("\n\n", $out));
}

function pusula_ai_response_polisher_contains_premium_signal(string $text): bool
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    if ($text === '') {
        return false;
    }

    $terms = [
        'premium',
        'üyelik',
        'ne sağlar',
        'ne sunar',
    ];
    foreach ($terms as $term) {
        if (mb_strpos($text, $term, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
}

function pusula_ai_response_polisher_polish(string $text, array $meta = []): string
{
    $original = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($original === '') {
        return '';
    }

    $intent = trim((string)($meta['intent'] ?? ''));
    $userMessage = (string)($meta['user_message'] ?? '');
    $isPremiumAsk = pusula_ai_response_polisher_contains_premium_signal($userMessage);

    if (pusula_ai_response_polisher_is_short_greeting($userMessage, $intent)) {
        return preg_replace('/\s+/u', ' ', $original) ?? $original;
    }

    $lines = pusula_ai_response_polisher_lines($original);
    $mixedRendered = pusula_ai_response_polisher_render_mixed_blocks($lines);
    if (is_string($mixedRendered) && $mixedRendered !== '') {
        // Açık listeleri ve etrafındaki metni koru, paragrafa gömme.
        return $mixedRendered;
    }

    $normalized = pusula_ai_response_polisher_normalize_spaces($original);
    $normalized = preg_replace('/\n{3,}/u', "\n\n", $normalized) ?? $normalized;
    $normalized = trim($normalized);

    // App info / premium sorularında liste önceliği.
    if (in_array($intent, ['app_info', 'general_app_info', 'feature_info'], true) || $isPremiumAsk) {
        $list = pusula_ai_response_polisher_extract_comma_list($normalized);
        if (is_array($list)) {
            $intro = trim((string)($list['intro'] ?? ''));
            $items = is_array($list['items'] ?? null) ? $list['items'] : [];
            $rows = [];
            if ($intro !== '') {
                $rows[] = $intro;
                $rows[] = '';
            }
            foreach ($items as $item) {
                $rows[] = '• ' . $item;
            }
            return trim(implode("\n", $rows));
        }
    }

    // 4+ kısa satır varsa maddeleştir.
    $shortLines = array_values(array_filter(array_map(static function ($line) {
        return pusula_ai_response_polisher_normalize_spaces((string)$line);
    }, $lines), static fn($v) => $v !== ''));
    $allShort = !empty($shortLines);
    foreach ($shortLines as $line) {
        if (pusula_ai_response_polisher_words($line) > 8) {
            $allShort = false;
            break;
        }
    }
    if ($allShort && count($shortLines) >= 4) {
        return implode("\n", array_map(static fn($v) => '• ' . $v, $shortLines));
    }

    // Paragrafları duvar yazısı olmadan koru.
    $paragraphs = preg_split('/\n\s*\n/u', $normalized) ?: [$normalized];
    $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn($v) => $v !== ''));

    $out = [];
    foreach ($paragraphs as $paragraph) {
        $out[] = pusula_ai_response_polisher_maybe_split_long_paragraph($paragraph);
    }

    return trim(implode("\n\n", $out));
}

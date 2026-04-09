<?php

if (!function_exists('bulk_question_parser_normalize_line')) {
    function bulk_question_parser_normalize_line(string $line): string
    {
        $line = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $line);
        $line = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}]/u', '', $line) ?? $line;
        $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $line) ?? $line;
        return rtrim($line, " \t");
    }
}

if (!function_exists('bulk_question_parser_line_compare_key')) {
    function bulk_question_parser_line_compare_key(string $line): string
    {
        $normalized = trim($line);
        if ($normalized === '') {
            return '';
        }

        if (class_exists('Normalizer')) {
            $tmp = Normalizer::normalize($normalized, Normalizer::FORM_KD);
            if (is_string($tmp) && $tmp !== '') {
                $normalized = $tmp;
            }
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }
}

if (!function_exists('bulk_question_parser_is_near_duplicate_line')) {
    function bulk_question_parser_is_near_duplicate_line(string $previousLine, string $currentLine): bool
    {
        $prev = trim($previousLine);
        $curr = trim($currentLine);

        if ($prev === '' || $curr === '') {
            return false;
        }

        $prevKey = bulk_question_parser_line_compare_key($prev);
        $currKey = bulk_question_parser_line_compare_key($curr);
        if ($prevKey !== '' && $prevKey === $currKey) {
            return true;
        }

        $prevLen = mb_strlen($prev, 'UTF-8');
        $currLen = mb_strlen($curr, 'UTF-8');
        if ($prevLen < 16 || $currLen < 16) {
            return false;
        }

        similar_text($prev, $curr, $percent);
        return $percent >= 96;
    }
}

if (!function_exists('bulk_question_parser_collapse_duplicate_lines')) {
    function bulk_question_parser_collapse_duplicate_lines(array $lines): array
    {
        $result = [];
        $lastNonEmpty = null;

        foreach ($lines as $line) {
            $normalizedLine = bulk_question_parser_normalize_line((string)$line);
            $trimmed = trim($normalizedLine);

            if ($trimmed === '') {
                $result[] = '';
                continue;
            }

            if ($lastNonEmpty !== null && bulk_question_parser_is_near_duplicate_line($lastNonEmpty, $trimmed)) {
                continue;
            }

            $result[] = $normalizedLine;
            $lastNonEmpty = $trimmed;
        }

        return $result;
    }
}

if (!function_exists('bulk_question_parser_normalize_multiline_field')) {
    function bulk_question_parser_normalize_multiline_field(array $lines): string
    {
        $normalized = [];
        $blankStreak = 0;

        foreach ($lines as $line) {
            $line = rtrim((string)$line, " \t");
            if (trim($line) === '') {
                $blankStreak++;
                if ($blankStreak <= 1) {
                    $normalized[] = '';
                }
                continue;
            }
            $blankStreak = 0;
            $normalized[] = $line;
        }

        while (!empty($normalized) && trim((string)$normalized[0]) === '') {
            array_shift($normalized);
        }
        while (!empty($normalized) && trim((string)$normalized[count($normalized) - 1]) === '') {
            array_pop($normalized);
        }

        return implode("\n", $normalized);
    }
}

if (!function_exists('bulk_question_parser_extract_answer_map')) {
    function bulk_question_parser_extract_answer_map(string $answerKeyText): array
    {
        $map = [];
        if ($answerKeyText === '') {
            return $map;
        }

        if (preg_match_all('/^\s*(\d+)\s*[-.):]\s*([A-E])\)?\s*$/imu', $answerKeyText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $map[(int)$m[1]] = strtoupper($m[2]);
            }
        }

        return $map;
    }
}

if (!function_exists('bulk_question_parser_looks_like_question_start')) {
    function bulk_question_parser_looks_like_question_start(array $lines, int $index): bool
    {
        if (!isset($lines[$index])) {
            return false;
        }

        if (!preg_match('/^\s*(\d+)\.\s*(.*)$/u', $lines[$index])) {
            return false;
        }

        $limit = min(count($lines) - 1, $index + 40);
        for ($i = $index + 1; $i <= $limit; $i++) {
            $candidate = trim((string)$lines[$i]);
            if ($candidate === '') {
                continue;
            }
            if (preg_match('/^\s*A\)\s+/u', $candidate)) {
                return true;
            }
            if (preg_match('/^\s*\d+\.\s+/u', $candidate)) {
                return false;
            }
        }

        return false;
    }
}

if (!function_exists('bulk_question_parser_split_blocks')) {
    function bulk_question_parser_split_blocks(string $bodyText): array
    {
        $lines = preg_split('/\n/u', $bodyText) ?: [];
        $lines = bulk_question_parser_collapse_duplicate_lines($lines);

        $blocks = [];
        $current = [];

        foreach ($lines as $idx => $line) {
            $trimmed = trim((string)$line);

            if ($trimmed === '⸻') {
                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }

            if (bulk_question_parser_looks_like_question_start($lines, $idx)) {
                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = [];
                }
            }

            $current[] = (string)$line;
        }

        if (!empty($current)) {
            $blocks[] = $current;
        }

        return $blocks;
    }
}

if (!function_exists('bulk_question_parser_guess_correct_answer_from_text')) {
    function bulk_question_parser_guess_correct_answer_from_text(string $text): string
    {
        if (preg_match('/doğru\s*cevap\s*:?\s*([A-E])\)?/iu', $text, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/([A-E])\s*seçeneğidir/iu', $text, $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }
}

if (!function_exists('bulk_question_parser_parse_block')) {
    function bulk_question_parser_parse_block(array $blockLines, array $answerMap = []): array
    {
        $lines = bulk_question_parser_collapse_duplicate_lines($blockLines);
        $lines = array_values($lines);

        while (!empty($lines) && trim((string)$lines[0]) === '') {
            array_shift($lines);
        }
        while (!empty($lines) && trim((string)$lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        if (empty($lines)) {
            return [
                'valid' => false,
                'reason' => 'empty_block',
                'number' => null,
            ];
        }

        $firstLine = (string)$lines[0];
        if (!preg_match('/^\s*(\d+)\.\s*(.*)$/u', $firstLine, $numMatch)) {
            return [
                'valid' => false,
                'reason' => 'missing_question_number',
                'number' => null,
                'question_text' => bulk_question_parser_normalize_multiline_field($lines),
            ];
        }

        $questionNumber = (int)$numMatch[1];
        $firstBody = isset($numMatch[2]) ? (string)$numMatch[2] : '';
        $lines[0] = $firstBody;

        $questionLines = [];
        $options = ['A' => [], 'B' => [], 'C' => [], 'D' => [], 'E' => []];
        $currentOption = null;
        $inExplanation = false;
        $explanationLines = [];
        $correctFromFinalLine = '';
        $correctFromText = '';
        $correctFromOptionMarker = '';

        foreach ($lines as $line) {
            $line = (string)$line;
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inExplanation) {
                    $explanationLines[] = '';
                } elseif ($currentOption !== null) {
                    $options[$currentOption][] = '';
                } else {
                    $questionLines[] = '';
                }
                continue;
            }

            if (preg_match('/^\s*Doğru\s*Cevap\s*:\s*([A-E])\)?\s*(.*)$/iu', $trimmed, $m)) {
                $correctFromFinalLine = strtoupper($m[1]);
                $tail = trim((string)($m[2] ?? ''));
                if ($tail !== '' && $inExplanation) {
                    $explanationLines[] = $tail;
                }
                continue;
            }

            if (preg_match('/^\s*Açıklama\s*:\s*(.*)$/iu', $trimmed, $m)) {
                $inExplanation = true;
                $currentOption = null;
                $firstExplanationLine = trim((string)($m[1] ?? ''));
                if ($firstExplanationLine !== '') {
                    $explanationLines[] = $firstExplanationLine;
                    if ($correctFromText === '') {
                        $correctFromText = bulk_question_parser_guess_correct_answer_from_text($firstExplanationLine);
                    }
                }
                continue;
            }

            if ($inExplanation) {
                $explanationLines[] = $line;
                if ($correctFromText === '') {
                    $correctFromText = bulk_question_parser_guess_correct_answer_from_text($trimmed);
                }
                continue;
            }

            if (preg_match('/^\s*([A-E])\)\s*(.*)$/u', $line, $optMatch)) {
                $currentOption = strtoupper($optMatch[1]);
                $optFirstLine = trim((string)($optMatch[2] ?? ''));

                if ($optFirstLine !== '') {
                    $options[$currentOption][] = $optFirstLine;
                }

                if ($correctFromOptionMarker === '' && preg_match('/\(\s*doğru\s*\)|^[*✓✔]/iu', $optFirstLine)) {
                    $correctFromOptionMarker = $currentOption;
                }

                continue;
            }

            if ($currentOption !== null) {
                $options[$currentOption][] = $line;
                if ($correctFromOptionMarker === '' && preg_match('/\(\s*doğru\s*\)|^[*✓✔]/iu', $trimmed)) {
                    $correctFromOptionMarker = $currentOption;
                }
            } else {
                $questionLines[] = $line;
                if ($correctFromText === '') {
                    $correctFromText = bulk_question_parser_guess_correct_answer_from_text($trimmed);
                }
            }
        }

        $questionText = bulk_question_parser_normalize_multiline_field($questionLines);
        $optionText = [];
        foreach ($options as $key => $valueLines) {
            $optionText[$key] = bulk_question_parser_normalize_multiline_field($valueLines);
        }

        $explanationText = bulk_question_parser_normalize_multiline_field($explanationLines);
        if ($correctFromText === '' && $explanationText !== '') {
            $correctFromText = bulk_question_parser_guess_correct_answer_from_text($explanationText);
        }

        $correctAnswer = strtoupper(
            $correctFromFinalLine
            ?: ($answerMap[$questionNumber] ?? '')
            ?: $correctFromText
            ?: $correctFromOptionMarker
        );

        $missing = [];
        if (trim($questionText) === '') {
            $missing[] = 'question_text';
        }
        foreach (['A', 'B', 'C', 'D'] as $requiredOption) {
            if (trim($optionText[$requiredOption]) === '') {
                $missing[] = 'option_' . strtolower($requiredOption);
            }
        }

        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D', 'E'], true)) {
            $missing[] = 'correct_answer';
        }
        if ($correctAnswer === 'E' && trim($optionText['E']) === '') {
            $missing[] = 'option_e_required_for_correct_answer_e';
        }

        if (!empty($missing)) {
            return [
                'valid' => false,
                'reason' => 'missing_or_invalid_fields: ' . implode(', ', $missing),
                'number' => $questionNumber,
                'question_text' => $questionText,
            ];
        }

        return [
            'valid' => true,
            'number' => $questionNumber,
            'question_text' => $questionText,
            'option_a' => $optionText['A'],
            'option_b' => $optionText['B'],
            'option_c' => $optionText['C'],
            'option_d' => $optionText['D'],
            'option_e' => trim($optionText['E']) !== '' ? $optionText['E'] : null,
            'explanation' => $explanationText,
            'correct_answer' => $correctAnswer,
        ];
    }
}

if (!function_exists('bulk_question_parser_parse_text')) {
    function bulk_question_parser_parse_text(string $rawText): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $rawText);
        $text = preg_replace('/\x{00A0}/u', ' ', $text) ?? $text;
        $text = trim($text);

        $result = [
            'parser_version' => 'BULK_PARSER_V2',
            'parsed' => [],
            'parsed_count' => 0,
            'skipped_count' => 0,
            'total_blocks' => 0,
            'skipped_reasons' => [],
            'skipped_samples' => [],
        ];

        if ($text === '') {
            return $result;
        }

        $answerKeyText = '';
        if (preg_match('/^\s*Cevap\s+Anahtarı\s*:?\s*$/imu', $text, $headerMatch, PREG_OFFSET_CAPTURE)) {
            $offset = (int)$headerMatch[0][1];
            $answerKeyText = trim((string)substr($text, $offset));
            $text = trim((string)substr($text, 0, $offset));
        }
        $answerMap = bulk_question_parser_extract_answer_map($answerKeyText);

        $blocks = bulk_question_parser_split_blocks($text);
        $result['total_blocks'] = count($blocks);

        foreach ($blocks as $blockIndex => $blockLines) {
            $parsedBlock = bulk_question_parser_parse_block($blockLines, $answerMap);

            if (!($parsedBlock['valid'] ?? false)) {
                $result['skipped_count']++;

                $reason = (string)($parsedBlock['reason'] ?? 'parse_failed');
                $result['skipped_reasons'][$reason] = (int)($result['skipped_reasons'][$reason] ?? 0) + 1;

                if (count($result['skipped_samples']) < 8) {
                    $result['skipped_samples'][] = [
                        'index' => $blockIndex + 1,
                        'number' => $parsedBlock['number'] ?? null,
                        'reason' => $reason,
                        'question_text' => (string)($parsedBlock['question_text'] ?? ''),
                    ];
                }
                continue;
            }

            $result['parsed'][] = [
                'question_text' => (string)$parsedBlock['question_text'],
                'option_a' => (string)$parsedBlock['option_a'],
                'option_b' => (string)$parsedBlock['option_b'],
                'option_c' => (string)$parsedBlock['option_c'],
                'option_d' => (string)$parsedBlock['option_d'],
                'option_e' => $parsedBlock['option_e'],
                'explanation' => (string)($parsedBlock['explanation'] ?? ''),
                'correct_answer' => (string)$parsedBlock['correct_answer'],
            ];
        }

        $result['parsed_count'] = count($result['parsed']);
        return $result;
    }
}

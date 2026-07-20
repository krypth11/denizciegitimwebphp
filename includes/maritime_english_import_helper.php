<?php

function me_import_uuid(): string
{
    return function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16));
}

function me_import_question_type(string $prompt): string
{
    $p = function_exists('mb_strtolower')
        ? mb_strtolower($prompt, 'UTF-8')
        : strtolower($prompt);
    if (str_contains($p, 'boşlu') || str_contains($prompt, '_____')) return 'fill_blank';
    if (str_contains($p, 'çeviri') || str_contains($p, 'türkçe karşılığı')) return 'translation';
    if (str_contains($p, 'diyalog') || str_contains($prompt, 'Officer:') || str_contains($prompt, 'Pilot:')) return 'dialogue';
    if (str_contains($p, 'doğru sıra') || str_contains($p, 'karışık kelime')) return 'word_order';
    if (str_contains($p, 'yanlış kullan')) return 'wrong_usage';
    if (str_contains($p, 'eşleştir')) return 'matching';
    return 'context_meaning';
}

function me_import_parse(string $raw): array
{
    $text = str_replace(["\r\n", "\r", "–"], ["\n", "\n", "—"], trim($raw));
    $errors = [];
    if ($text === '') return ['success' => false, 'errors' => ['Metin boş bırakılamaz.']];
    preg_match('/^\s*Kelime\s*:\s*(.+)$/miu', $text, $termMatch);
    preg_match('/^\s*Türkçe\s*:\s*(.+)$/miu', $text, $trMatch);
    $termEn = trim((string)($termMatch[1] ?? ''));
    $termTr = trim((string)($trMatch[1] ?? ''));
    if ($termEn === '') $errors[] = '`Kelime:` alanı bulunamadı.';
    if ($termTr === '') $errors[] = '`Türkçe:` alanı bulunamadı.';

    $parts = preg_split('/^\s*Cevap\s+Anahtarı\s*$/miu', $text, 2);
    if (count($parts) !== 2) {
        $errors[] = '`Cevap Anahtarı` bölümü bulunamadı.';
        return ['success' => false, 'errors' => $errors];
    }
    [$body, $answerSection] = $parts;
    if (!preg_match('/^\s*Cümleler\s*$(.*)$/msiu', $body, $afterSentences)) {
        $errors[] = '`Cümleler` bölümü bulunamadı.';
        return ['success' => false, 'errors' => $errors];
    }
    $content = trim((string)$afterSentences[1]);
    $split = preg_split('/^\s*—\s*$/mu', $content, 2);
    if (count($split) !== 2) {
        $errors[] = 'Cümleler ile sorular arasındaki `—` ayırıcı bulunamadı.';
        return ['success' => false, 'errors' => $errors];
    }
    [$sentenceSection, $questionSection] = $split;

    preg_match_all('/^\s*(\d+)\.\s*(.+?)\n\s*(.+?)(?=\n\s*\n?\d+\.|\z)/msu', trim($sentenceSection), $sentenceMatches, PREG_SET_ORDER);
    $sentences = [];
    foreach ($sentenceMatches as $match) {
        $en = trim((string)$match[2]);
        $tr = trim((string)$match[3]);
        if ($en !== '' && $tr !== '') $sentences[] = ['number' => (int)$match[1], 'en' => $en, 'tr' => $tr];
    }
    if (!$sentences) $errors[] = 'İngilizce–Türkçe cümle çiftleri algılanamadı.';

    $questionSection = preg_replace('/^\s*—\s*$/mu', '', $questionSection) ?? $questionSection;
    preg_match_all('/^\s*(\d+)\.\s*(.+?)(?=^\s*\d+\.\s|\z)/msu', trim($questionSection), $questionBlocks, PREG_SET_ORDER);
    $answers = [];
    preg_match_all('/^\s*(\d+)\s*[-–—]\s*([A-D])\s*$/miu', $answerSection, $answerMatches, PREG_SET_ORDER);
    foreach ($answerMatches as $match) $answers[(int)$match[1]] = strtoupper((string)$match[2]);
    $questions = [];
    foreach ($questionBlocks as $block) {
        $number = (int)$block[1];
        $blockText = trim((string)$block[2]);
        preg_match_all('/^\s*([A-D])\)\s*(.+?)\s*$/miu', $blockText, $optionMatches, PREG_SET_ORDER);
        $options = [];
        foreach ($optionMatches as $option) $options[strtoupper((string)$option[1])] = trim((string)$option[2]);
        $prompt = trim((string)(preg_split('/^\s*A\)\s*/miu', $blockText, 2)[0] ?? ''));
        if (count($options) !== 4) { $errors[] = "{$number}. soru için A, B, C ve D seçeneklerinin tamamı bulunamadı."; continue; }
        if (!isset($answers[$number])) { $errors[] = "{$number}. soru için cevap anahtarı bulunamadı."; continue; }
        $questions[] = [
            'number' => $number, 'type' => me_import_question_type($prompt), 'prompt' => $prompt,
            'options' => $options, 'correct_key' => $answers[$number],
        ];
    }
    if (!$questions) $errors[] = 'Soru blokları algılanamadı.';
    foreach ($answers as $number => $_) {
        if (!array_filter($questions, static fn($q) => $q['number'] === $number)) $errors[] = "Cevap anahtarındaki {$number}. soru metinde bulunamadı veya geçersiz.";
    }
    return [
        'success' => !$errors, 'errors' => array_values(array_unique($errors)),
        'data' => ['term_en' => $termEn, 'term_tr' => $termTr, 'sentences' => $sentences, 'questions' => $questions],
    ];
}

function me_import_save(PDO $pdo, string $categoryId, ?string $qualificationId, array $data): array
{
    $termEn = trim((string)($data['term_en'] ?? ''));
    $termTr = trim((string)($data['term_tr'] ?? ''));
    if ($categoryId === '' || $termEn === '' || $termTr === '') throw new InvalidArgumentException('Kategori, kelime ve Türkçe karşılık zorunludur.');
    $category = $pdo->prepare('SELECT id FROM maritime_english_categories WHERE id = ? AND is_active = 1');
    $category->execute([$categoryId]);
    if (!$category->fetchColumn()) throw new InvalidArgumentException('Geçersiz kategori.');
    if ($qualificationId === '') $qualificationId = null;

    $existing = $pdo->prepare('SELECT id FROM maritime_english_terms WHERE category_id = ? AND LOWER(term_en) = LOWER(?) LIMIT 1');
    $existing->execute([$categoryId, $termEn]);
    $termId = $existing->fetchColumn();
    $isExisting = (bool)$termId;
    if (!$termId) {
        $termId = me_import_uuid();
        $pdo->prepare("INSERT INTO maritime_english_terms (id, category_id, qualification_id, term_en, term_tr, short_explanation, content_status, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'published', 1, NOW(), NOW())")
            ->execute([$termId, $categoryId, $qualificationId, $termEn, $termTr, $termEn . ': ' . $termTr]);
    }
    $sentenceInsert = $pdo->prepare('INSERT INTO maritime_english_sentences (id, term_id, sentence_en, sentence_tr, sort_order, is_active, created_at, updated_at) SELECT ?, ?, ?, ?, ?, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM maritime_english_sentences WHERE term_id = ? AND sentence_en = ?)');
    $sentenceCount = 0;
    foreach (($data['sentences'] ?? []) as $sentence) {
        $sentenceInsert->execute([me_import_uuid(), $termId, (string)$sentence['en'], (string)$sentence['tr'], (int)$sentence['number'], $termId, (string)$sentence['en']]);
        $sentenceCount += $sentenceInsert->rowCount();
    }
    $questionInsert = $pdo->prepare('INSERT INTO maritime_english_questions (id, term_id, question_type, prompt, options_json, correct_option_key, explanation, sort_order, is_active, created_at, updated_at) SELECT ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW() WHERE NOT EXISTS (SELECT 1 FROM maritime_english_questions WHERE term_id = ? AND prompt = ?)');
    $questionCount = 0;
    foreach (($data['questions'] ?? []) as $question) {
        $questionInsert->execute([
            me_import_uuid(), $termId, (string)$question['type'], (string)$question['prompt'],
            json_encode($question['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string)$question['correct_key'], $termEn . ': ' . $termTr, (int)$question['number'], $termId, (string)$question['prompt'],
        ]);
        $questionCount += $questionInsert->rowCount();
    }
    return ['term_id' => $termId, 'existing_term' => $isExisting, 'sentences_added' => $sentenceCount, 'questions_added' => $questionCount];
}

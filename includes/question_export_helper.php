<?php

function question_export_get_column_flags(PDO $pdo): array
{
    $cols = get_table_columns($pdo, 'questions');
    $has = static fn(string $name): bool => is_array($cols) && in_array($name, $cols, true);

    return [
        'option_e' => $has('option_e'),
        'topic_id' => $has('topic_id'),
        'status' => $has('status'),
        'is_active' => $has('is_active'),
        'updated_at' => $has('updated_at'),
        'created_at' => $has('created_at'),
    ];
}

function question_export_get_available_formats(): array
{
    return [
        'csv' => ['label' => 'CSV', 'extension' => 'csv', 'mime' => 'text/csv; charset=UTF-8'],
        'xlsx' => ['label' => 'Excel (.xlsx)', 'extension' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'json' => ['label' => 'JSON', 'extension' => 'json', 'mime' => 'application/json; charset=UTF-8'],
        'md' => ['label' => 'AI Analiz Paketi (.md)', 'extension' => 'md', 'mime' => 'text/markdown; charset=UTF-8'],
    ];
}

function question_export_get_profile_defaults(string $profile): array
{
    $defaults = [
        'full_data' => [
            'include_options' => true,
            'include_correct_answer' => true,
            'include_explanation' => true,
            'include_taxonomy' => true,
            'include_ids' => true,
            'include_question_type' => true,
            'include_meta' => true,
        ],
        'question_texts' => [
            'include_options' => false,
            'include_correct_answer' => false,
            'include_explanation' => false,
            'include_taxonomy' => false,
            'include_ids' => false,
            'include_question_type' => false,
            'include_meta' => false,
        ],
        'question_correct' => [
            'include_options' => false,
            'include_correct_answer' => true,
            'include_explanation' => false,
            'include_taxonomy' => false,
            'include_ids' => false,
            'include_question_type' => false,
            'include_meta' => false,
        ],
        'question_correct_explanation' => [
            'include_options' => false,
            'include_correct_answer' => true,
            'include_explanation' => true,
            'include_taxonomy' => false,
            'include_ids' => false,
            'include_question_type' => false,
            'include_meta' => false,
        ],
        'ai_generation' => [
            'include_options' => true,
            'include_correct_answer' => true,
            'include_explanation' => true,
            'include_taxonomy' => true,
            'include_ids' => false,
            'include_question_type' => false,
            'include_meta' => false,
        ],
        'ai_analysis' => [
            'include_options' => true,
            'include_correct_answer' => true,
            'include_explanation' => true,
            'include_taxonomy' => true,
            'include_ids' => true,
            'include_question_type' => true,
            'include_meta' => false,
        ],
    ];

    return $defaults[$profile] ?? $defaults['full_data'];
}

function question_export_profile_labels(): array
{
    return [
        'full_data' => 'Tam veri',
        'question_texts' => 'Sadece soru metinleri',
        'question_correct' => 'Soru + doğru cevap',
        'question_correct_explanation' => 'Soru + doğru cevap + açıklama',
        'ai_generation' => 'AI üretim formatı',
        'ai_analysis' => 'AI analiz formatı',
    ];
}

function question_export_profile_label(string $profile): string
{
    $labels = question_export_profile_labels();
    return $labels[$profile] ?? $labels['full_data'];
}

function question_export_parse_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'on', 'yes', 'evet'], true);
}

function question_export_build_content_config(string $profile, array $request): array
{
    $base = question_export_get_profile_defaults($profile);

    $overrides = [
        'include_options' => array_key_exists('include_options', $request),
        'include_correct_answer' => array_key_exists('include_correct_answer', $request),
        'include_explanation' => array_key_exists('include_explanation', $request),
        'include_taxonomy' => array_key_exists('include_taxonomy', $request),
        'include_ids' => array_key_exists('include_ids', $request),
    ];

    foreach ($overrides as $key => $hasKey) {
        if ($hasKey) {
            $base[$key] = question_export_parse_bool($request[$key]);
        }
    }

    return [
        'profile' => $profile,
        'profile_label' => question_export_profile_label($profile),
        'include_options' => (bool)$base['include_options'],
        'include_correct_answer' => (bool)$base['include_correct_answer'],
        'include_explanation' => (bool)$base['include_explanation'],
        'include_taxonomy' => (bool)$base['include_taxonomy'],
        'include_ids' => (bool)$base['include_ids'],
        'include_question_type' => (bool)$base['include_question_type'],
        'include_meta' => (bool)$base['include_meta'],
    ];
}

function question_export_build_query_parts(array $filters, array $flags): array
{
    $select = [
        'q.id AS question_id',
        'c.qualification_id AS qualification_id',
        'qual.name AS qualification_name',
        'q.course_id AS course_id',
        'c.name AS course_name',
        ($flags['topic_id'] ? 'q.topic_id' : 'NULL') . ' AS topic_id',
        ($flags['topic_id'] ? 't.name' : 'NULL') . ' AS topic_name',
        'q.question_type',
        'q.question_text',
        'q.option_a',
        'q.option_b',
        'q.option_c',
        'q.option_d',
        ($flags['option_e'] ? 'q.option_e' : 'NULL') . ' AS option_e',
        'q.correct_answer',
        'q.explanation',
        ($flags['status'] ? 'q.status' : 'NULL') . ' AS status',
        ($flags['is_active'] ? 'q.is_active' : 'NULL') . ' AS is_active',
        ($flags['created_at'] ? 'q.created_at' : 'NULL') . ' AS created_at',
        ($flags['updated_at'] ? 'q.updated_at' : 'NULL') . ' AS updated_at',
    ];

    $join = ' FROM questions q
              LEFT JOIN courses c ON q.course_id = c.id
              LEFT JOIN qualifications qual ON c.qualification_id = qual.id';

    if ($flags['topic_id']) {
        $join .= ' LEFT JOIN topics t ON q.topic_id = t.id';
    }

    $where = ['c.qualification_id = ?'];
    $params = [(string)$filters['qualification_id']];

    if (!empty($filters['course_id'])) {
        $where[] = 'q.course_id = ?';
        $params[] = (string)$filters['course_id'];
    }

    if ($flags['topic_id'] && !empty($filters['topic_id'])) {
        $where[] = 'q.topic_id = ?';
        $params[] = (string)$filters['topic_id'];
    }

    return [
        'select' => $select,
        'join' => $join,
        'where' => $where,
        'params' => $params,
    ];
}

function question_export_slugify(string $text): string
{
    $map = [
        'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g',
        'ı' => 'i', 'İ' => 'i',
        'ö' => 'o', 'Ö' => 'o',
        'ş' => 's', 'Ş' => 's',
        'ü' => 'u', 'Ü' => 'u',
    ];

    $text = strtr(trim($text), $map);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text !== '' ? $text : 'qualification';
}

function question_export_get_filter_labels(PDO $pdo, array $filters): array
{
    $labels = [
        'qualification_name' => '',
        'course_name' => '',
        'topic_name' => '',
    ];

    if (!empty($filters['qualification_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM qualifications WHERE id = ? LIMIT 1');
        $stmt->execute([(string)$filters['qualification_id']]);
        $labels['qualification_name'] = (string)($stmt->fetchColumn() ?: '');
    }

    if (!empty($filters['course_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM courses WHERE id = ? LIMIT 1');
        $stmt->execute([(string)$filters['course_id']]);
        $labels['course_name'] = (string)($stmt->fetchColumn() ?: '');
    }

    if (!empty($filters['topic_id'])) {
        $stmt = $pdo->prepare('SELECT name FROM topics WHERE id = ? LIMIT 1');
        $stmt->execute([(string)$filters['topic_id']]);
        $labels['topic_name'] = (string)($stmt->fetchColumn() ?: '');
    }

    return $labels;
}

function question_export_build_base_question(array $row, array $config): array
{
    $item = [];

    if ($config['include_ids']) {
        $item['question_id'] = (string)($row['question_id'] ?? '');
    }

    if ($config['include_taxonomy']) {
        if ($config['include_ids']) {
            $item['qualification_id'] = (string)($row['qualification_id'] ?? '');
            $item['course_id'] = (string)($row['course_id'] ?? '');
            $item['topic_id'] = (string)($row['topic_id'] ?? '');
        }

        $item['qualification_name'] = (string)($row['qualification_name'] ?? '');
        $item['course_name'] = (string)($row['course_name'] ?? '');
        $item['topic_name'] = (string)($row['topic_name'] ?? '');
    }

    if ($config['include_question_type']) {
        $item['question_type'] = (string)($row['question_type'] ?? '');
    }

    $item['question_text'] = (string)($row['question_text'] ?? '');

    if ($config['include_options']) {
        $options = [
            'A' => (string)($row['option_a'] ?? ''),
            'B' => (string)($row['option_b'] ?? ''),
            'C' => (string)($row['option_c'] ?? ''),
            'D' => (string)($row['option_d'] ?? ''),
        ];

        if (array_key_exists('option_e', $row) && (string)$row['option_e'] !== '') {
            $options['E'] = (string)$row['option_e'];
        }

        $item['options'] = $options;
    }

    if ($config['include_correct_answer']) {
        $item['correct_answer'] = (string)($row['correct_answer'] ?? '');
    }

    if ($config['include_explanation']) {
        $item['explanation'] = (string)($row['explanation'] ?? '');
    }

    if ($config['include_meta']) {
        $item['status'] = (string)($row['status'] ?? '');
        $item['is_active'] = (string)($row['is_active'] ?? '');
        $item['created_at'] = (string)($row['created_at'] ?? '');
        $item['updated_at'] = (string)($row['updated_at'] ?? '');
    }

    return $item;
}

function question_export_build_tabular_columns(array $config, array $flags): array
{
    $columns = [];

    if ($config['include_ids']) {
        $columns[] = ['key' => 'question_id', 'title' => 'question_id'];
    }

    if ($config['include_taxonomy']) {
        if ($config['include_ids']) {
            $columns[] = ['key' => 'qualification_id', 'title' => 'qualification_id'];
        }
        $columns[] = ['key' => 'qualification_name', 'title' => 'qualification_name'];

        if ($config['include_ids']) {
            $columns[] = ['key' => 'course_id', 'title' => 'course_id'];
        }
        $columns[] = ['key' => 'course_name', 'title' => 'course_name'];

        if ($config['include_ids']) {
            $columns[] = ['key' => 'topic_id', 'title' => 'topic_id'];
        }
        $columns[] = ['key' => 'topic_name', 'title' => 'topic_name'];
    }

    if ($config['include_question_type']) {
        $columns[] = ['key' => 'question_type', 'title' => 'question_type'];
    }

    $columns[] = ['key' => 'question_text', 'title' => 'question_text'];

    if ($config['include_options']) {
        $columns[] = ['key' => 'option_a', 'title' => 'option_a'];
        $columns[] = ['key' => 'option_b', 'title' => 'option_b'];
        $columns[] = ['key' => 'option_c', 'title' => 'option_c'];
        $columns[] = ['key' => 'option_d', 'title' => 'option_d'];
        if ($flags['option_e']) {
            $columns[] = ['key' => 'option_e', 'title' => 'option_e'];
        }
    }

    if ($config['include_correct_answer']) {
        $columns[] = ['key' => 'correct_answer', 'title' => 'correct_answer'];
    }

    if ($config['include_explanation']) {
        $columns[] = ['key' => 'explanation', 'title' => 'explanation'];
    }

    if ($config['include_meta']) {
        if ($flags['status']) {
            $columns[] = ['key' => 'status', 'title' => 'status'];
        }
        if ($flags['is_active']) {
            $columns[] = ['key' => 'is_active', 'title' => 'is_active'];
        }
        if ($flags['created_at']) {
            $columns[] = ['key' => 'created_at', 'title' => 'created_at'];
        }
        if ($flags['updated_at']) {
            $columns[] = ['key' => 'updated_at', 'title' => 'updated_at'];
        }
    }

    return $columns;
}

function question_export_send_headers(string $mime, string $filename): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function question_export_build_filename(string $format, string $profile, string $qualificationName): string
{
    $formats = question_export_get_available_formats();
    $ext = $formats[$format]['extension'] ?? 'txt';
    $slug = question_export_slugify($qualificationName !== '' ? $qualificationName : 'qualification');
    $timestamp = date('Y-m-d_H-i');

    if ($format === 'md' && $profile === 'ai_generation') {
        return 'questions_ai_generation_' . $slug . '_' . $timestamp . '.' . $ext;
    }

    if ($format === 'md') {
        return 'questions_ai_analysis_' . $slug . '_' . $timestamp . '.' . $ext;
    }

    return 'questions_export_' . $slug . '_' . $timestamp . '.' . $ext;
}

function question_export_stream_csv(PDOStatement $stmt, array $columns): void
{
    $out = fopen('php://output', 'wb');
    echo "\xEF\xBB\xBF";

    fputcsv($out, array_map(static fn(array $col): string => $col['title'], $columns));

    $i = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $record = [];
        foreach ($columns as $col) {
            $record[] = (string)($row[$col['key']] ?? '');
        }
        fputcsv($out, $record);

        $i++;
        if ($i % 250 === 0) {
            fflush($out);
            flush();
        }
    }

    fclose($out);
}

function question_export_stream_json(PDOStatement $stmt, array $config): void
{
    echo '[';
    $first = true;
    $i = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $item = question_export_build_base_question($row, $config);
        if (!$first) {
            echo ',';
        }
        echo json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $first = false;

        $i++;
        if ($i % 200 === 0) {
            flush();
        }
    }

    echo ']';
}

function question_export_md_line(string $text = ''): string
{
    return $text . "\n";
}

function question_export_stream_markdown(PDOStatement $stmt, array $config, array $filters, array $labels, int $totalCount): void
{
    $isGeneration = ($config['profile'] === 'ai_generation');

    if ($isGeneration) {
        echo question_export_md_line('# Soru Üretim Örnek Paketi');
        echo question_export_md_line('');
        echo question_export_md_line('## Filtre Bilgileri');
    } else {
        echo question_export_md_line('# AI Soru Analiz Paketi');
        echo question_export_md_line('');
        echo question_export_md_line('## Export Özeti');
        echo question_export_md_line('- Profil: ' . $config['profile_label']);
        echo question_export_md_line('- Toplam Soru: ' . number_format($totalCount, 0, ',', '.'));
        echo question_export_md_line('');
        echo question_export_md_line('## Filtre Bilgileri');
    }

    echo question_export_md_line('- Yeterlilik: ' . ($labels['qualification_name'] !== '' ? $labels['qualification_name'] : (string)$filters['qualification_id']));
    echo question_export_md_line('- Ders: ' . ($labels['course_name'] !== '' ? $labels['course_name'] : 'Tüm dersler'));
    echo question_export_md_line('- Konu: ' . ($labels['topic_name'] !== '' ? $labels['topic_name'] : 'Tüm konular'));
    echo question_export_md_line('');
    echo question_export_md_line('## Sorular');
    echo question_export_md_line('');

    $index = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $index++;
        $item = question_export_build_base_question($row, $config);

        echo question_export_md_line('### Soru ' . $index);
        if (!empty($item['question_id'])) {
            echo question_export_md_line('- ID: ' . $item['question_id']);
        }
        if (!empty($item['qualification_name'])) {
            echo question_export_md_line('- Yeterlilik: ' . $item['qualification_name']);
        }
        if (!empty($item['course_name'])) {
            echo question_export_md_line('- Ders: ' . $item['course_name']);
        }
        if (!empty($item['topic_name'])) {
            echo question_export_md_line('- Konu: ' . $item['topic_name']);
        }
        if (!empty($item['question_type'])) {
            echo question_export_md_line('- Tür: ' . $item['question_type']);
        }

        echo question_export_md_line('');
        echo question_export_md_line('**Soru Metni**');
        echo question_export_md_line($item['question_text'] !== '' ? $item['question_text'] : '-');

        if (!empty($item['options']) && is_array($item['options'])) {
            echo question_export_md_line('');
            echo question_export_md_line('**Şıklar**');
            foreach ($item['options'] as $letter => $optionText) {
                echo question_export_md_line('- ' . $letter . ') ' . ($optionText !== '' ? $optionText : '-'));
            }
        }

        if (array_key_exists('correct_answer', $item)) {
            echo question_export_md_line('');
            echo question_export_md_line('**Doğru Cevap:** ' . ($item['correct_answer'] !== '' ? $item['correct_answer'] : '-'));
        }

        if (array_key_exists('explanation', $item)) {
            echo question_export_md_line('');
            echo question_export_md_line('**Açıklama**');
            echo question_export_md_line($item['explanation'] !== '' ? $item['explanation'] : '-');
        }

        echo question_export_md_line('');
        echo question_export_md_line('---');
        echo question_export_md_line('');

        if ($index % 100 === 0) {
            flush();
        }
    }
}

function question_export_xlsx_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(($index % 26) + 65) . $name;
        $index = (int)floor($index / 26);
    }
    return $name;
}

function question_export_xlsx_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function question_export_xlsx_write_row($fp, int $rowNumber, array $values): void
{
    fwrite($fp, '<row r="' . $rowNumber . '">');
    $col = 1;
    foreach ($values as $value) {
        $ref = question_export_xlsx_column_name($col) . $rowNumber;
        $escaped = question_export_xlsx_escape((string)$value);
        fwrite($fp, '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>');
        $col++;
    }
    fwrite($fp, '</row>');
}

function question_export_recursive_delete(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if (!is_array($items)) {
        @rmdir($path);
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            question_export_recursive_delete($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

function question_export_stream_xlsx(PDOStatement $stmt, array $columns): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive uzantısı bulunamadı. XLSX export bu sunucuda desteklenmiyor.');
    }

    $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qexport_' . bin2hex(random_bytes(6));
    $tmpXlsx = $tmpBase . '.xlsx';
    $workDir = $tmpBase . '_xlsx';

    if (!@mkdir($workDir, 0777, true) && !is_dir($workDir)) {
        throw new RuntimeException('Geçici çalışma dizini oluşturulamadı.');
    }

    $sheetXml = $workDir . DIRECTORY_SEPARATOR . 'sheet1.xml';
    $fp = fopen($sheetXml, 'wb');
    if ($fp === false) {
        question_export_recursive_delete($workDir);
        throw new RuntimeException('Geçici XLSX dosyası hazırlanamadı.');
    }

    fwrite($fp, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
    fwrite($fp, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

    $headerValues = array_map(static fn(array $col): string => $col['title'], $columns);
    question_export_xlsx_write_row($fp, 1, $headerValues);

    $rowNumber = 2;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $line = [];
        foreach ($columns as $col) {
            $line[] = (string)($row[$col['key']] ?? '');
        }
        question_export_xlsx_write_row($fp, $rowNumber, $line);
        $rowNumber++;
    }

    fwrite($fp, '</sheetData></worksheet>');
    fclose($fp);

    $contentTypes = $workDir . DIRECTORY_SEPARATOR . '[Content_Types].xml';
    file_put_contents($contentTypes, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

    if (!is_dir($workDir . DIRECTORY_SEPARATOR . '_rels')) {
        mkdir($workDir . DIRECTORY_SEPARATOR . '_rels', 0777, true);
    }
    if (!is_dir($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . '_rels')) {
        mkdir($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . '_rels', 0777, true);
    }
    if (!is_dir($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets')) {
        mkdir($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets', 0777, true);
    }

    rename($sheetXml, $workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . 'sheet1.xml');

    file_put_contents($workDir . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . '.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

    file_put_contents($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Questions" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

    file_put_contents($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

    file_put_contents($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>');

    $zip = new ZipArchive();
    if ($zip->open($tmpXlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        question_export_recursive_delete($workDir);
        throw new RuntimeException('XLSX dosyası oluşturulamadı.');
    }

    $zip->addFile($workDir . DIRECTORY_SEPARATOR . '[Content_Types].xml', '[Content_Types].xml');
    $zip->addFile($workDir . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . '.rels', '_rels/.rels');
    $zip->addFile($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'workbook.xml', 'xl/workbook.xml');
    $zip->addFile($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'styles.xml', 'xl/styles.xml');
    $zip->addFile($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'workbook.xml.rels', 'xl/_rels/workbook.xml.rels');
    $zip->addFile($workDir . DIRECTORY_SEPARATOR . 'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . 'sheet1.xml', 'xl/worksheets/sheet1.xml');
    $zip->close();

    question_export_recursive_delete($workDir);

    readfile($tmpXlsx);
    @unlink($tmpXlsx);
}

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

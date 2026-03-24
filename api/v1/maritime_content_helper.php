<?php

require_once __DIR__ . '/auth_helper.php';

function mc_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function mc_pick_column(array $columns, array $candidates, bool $required = false): ?string
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

function mc_require_query_id(string $key): string
{
    return api_require_query_param($key, 191);
}

function mc_get_maritime_signals_schema(PDO $pdo): array
{
    $catCols = get_table_columns($pdo, 'maritime_signal_categories');
    $itemCols = get_table_columns($pdo, 'maritime_signal_items');
    $signalCols = get_table_columns($pdo, 'maritime_signals');

    if (!$catCols || !$itemCols || !$signalCols) {
        throw new RuntimeException('Maritime signals tabloları okunamadı.');
    }

    return [
        'categories' => [
            'table' => 'maritime_signal_categories',
            'id' => mc_pick_column($catCols, ['id', 'category_id'], true),
            'title' => mc_pick_column($catCols, ['title', 'name', 'category_name'], true),
            'icon_name' => mc_pick_column($catCols, ['icon_name', 'icon', 'icon_key'], false),
            'order_index' => mc_pick_column($catCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
            'created_at' => mc_pick_column($catCols, ['created_at', 'created_on'], false),
        ],
        'items' => [
            'table' => 'maritime_signal_items',
            'id' => mc_pick_column($itemCols, ['id', 'item_id'], true),
            'category_id' => mc_pick_column($itemCols, ['category_id', 'maritime_signal_category_id'], true),
            'title' => mc_pick_column($itemCols, ['title', 'name', 'item_name'], true),
            'order_index' => mc_pick_column($itemCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
            'created_at' => mc_pick_column($itemCols, ['created_at', 'created_on'], false),
        ],
        'signals' => [
            'table' => 'maritime_signals',
            'id' => mc_pick_column($signalCols, ['id', 'signal_id'], true),
            'item_id' => mc_pick_column($signalCols, ['item_id', 'maritime_signal_item_id'], true),
            'title' => mc_pick_column($signalCols, ['title', 'name', 'signal_name'], true),
            'description' => mc_pick_column($signalCols, ['description', 'content', 'text'], false),
            'image_url' => mc_pick_column($signalCols, ['image_url', 'image', 'icon_url'], false),
            'order_index' => mc_pick_column($signalCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
            'created_at' => mc_pick_column($signalCols, ['created_at', 'created_on'], false),
        ],
    ];
}

function mc_get_maritime_english_schema(PDO $pdo): array
{
    $catCols = get_table_columns($pdo, 'maritime_english_categories');
    $topicCols = get_table_columns($pdo, 'maritime_english_topics');
    $qCols = get_table_columns($pdo, 'maritime_english_questions');

    if (!$catCols || !$topicCols || !$qCols) {
        throw new RuntimeException('Maritime english tabloları okunamadı.');
    }

    return [
        'categories' => [
            'table' => 'maritime_english_categories',
            'id' => mc_pick_column($catCols, ['id', 'category_id'], true),
            'name' => mc_pick_column($catCols, ['name', 'title', 'category_name'], true),
            'description' => mc_pick_column($catCols, ['description', 'content', 'text'], false),
            'color' => mc_pick_column($catCols, ['color', 'theme_color'], false),
            'icon_name' => mc_pick_column($catCols, ['icon_name', 'icon', 'icon_key'], false),
            'order_index' => mc_pick_column($catCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
        ],
        'topics' => [
            'table' => 'maritime_english_topics',
            'id' => mc_pick_column($topicCols, ['id', 'topic_id'], true),
            'category_id' => mc_pick_column($topicCols, ['category_id', 'maritime_english_category_id'], true),
            'title' => mc_pick_column($topicCols, ['title', 'name', 'topic_name'], true),
            'order_index' => mc_pick_column($topicCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
        ],
        'questions' => [
            'table' => 'maritime_english_questions',
            'id' => mc_pick_column($qCols, ['id', 'question_id'], true),
            'topic_id' => mc_pick_column($qCols, ['topic_id', 'maritime_english_topic_id'], true),
            'question_text' => mc_pick_column($qCols, ['question_text', 'text', 'question'], true),
            'option_a' => mc_pick_column($qCols, ['option_a', 'answer_a', 'choice_a'], false),
            'option_b' => mc_pick_column($qCols, ['option_b', 'answer_b', 'choice_b'], false),
            'option_c' => mc_pick_column($qCols, ['option_c', 'answer_c', 'choice_c'], false),
            'option_d' => mc_pick_column($qCols, ['option_d', 'answer_d', 'choice_d'], false),
            'option_e' => mc_pick_column($qCols, ['option_e', 'answer_e', 'choice_e', 'e_option'], false),
            'correct_answer' => mc_pick_column($qCols, ['correct_answer', 'correct_option', 'answer'], false),
            'explanation' => mc_pick_column($qCols, ['explanation', 'solution', 'description'], false),
            'created_at' => mc_pick_column($qCols, ['created_at', 'created_on'], false),
        ],
    ];
}

function mc_is_maritime_english_source(string $source): bool
{
    $normalized = strtolower(trim($source));
    return in_array($normalized, ['maritime_english', 'maritime-english', 'me', 'me_quiz', 'maritime_english_quiz'], true);
}

function mc_get_maritime_english_question_meta(PDO $pdo, string $questionId): array
{
    $schema = mc_get_maritime_english_schema($pdo)['questions'];

    $select = [
        mc_q($schema['id']) . ' AS id',
        mc_q($schema['topic_id']) . ' AS topic_id',
        ($schema['correct_answer'] ? mc_q($schema['correct_answer']) : "''") . ' AS correct_answer',
        ($schema['option_e'] ? mc_q($schema['option_e']) : 'NULL') . ' AS option_e',
    ];

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM ' . mc_q($schema['table'])
        . ' WHERE ' . mc_q($schema['id']) . ' = ? LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$questionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [
            'exists' => false,
            'correct_answer' => null,
            'option_e' => null,
            'topic_id' => null,
        ];
    }

    $correct = strtoupper(trim((string)($row['correct_answer'] ?? '')));
    $optionE = trim((string)($row['option_e'] ?? ''));

    return [
        'exists' => true,
        'correct_answer' => ($correct !== '' ? $correct : null),
        'option_e' => ($optionE !== '' ? $optionE : null),
        'topic_id' => $row['topic_id'] ?? null,
    ];
}

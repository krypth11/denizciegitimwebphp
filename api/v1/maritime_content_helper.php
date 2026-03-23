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
    $value = trim((string)($_GET[$key] ?? ''));
    if ($value === '') {
        api_error($key . ' parametresi zorunludur.', 422);
    }

    if (mb_strlen($value) > 191) {
        api_error('Geçersiz ' . $key . ' değeri.', 422);
    }

    return $value;
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
            'correct_answer' => mc_pick_column($qCols, ['correct_answer', 'correct_option', 'answer'], false),
            'explanation' => mc_pick_column($qCols, ['explanation', 'solution', 'description'], false),
            'created_at' => mc_pick_column($qCols, ['created_at', 'created_on'], false),
        ],
    ];
}

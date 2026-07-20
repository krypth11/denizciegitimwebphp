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


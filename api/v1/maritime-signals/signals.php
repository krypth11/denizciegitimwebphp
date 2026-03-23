<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/maritime_content_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);
    $itemId = mc_require_query_id('item_id');

    $schema = mc_get_maritime_signals_schema($pdo)['signals'];

    $sql = 'SELECT '
        . mc_q($schema['id']) . ' AS id, '
        . mc_q($schema['item_id']) . ' AS item_id, '
        . mc_q($schema['title']) . ' AS title, '
        . ($schema['description'] ? mc_q($schema['description']) : "''") . ' AS description, '
        . ($schema['image_url'] ? mc_q($schema['image_url']) : 'NULL') . ' AS image_url, '
        . ($schema['order_index'] ? mc_q($schema['order_index']) : '0') . ' AS order_index, '
        . ($schema['created_at'] ? mc_q($schema['created_at']) : 'NULL') . ' AS created_at '
        . 'FROM ' . mc_q($schema['table']) . ' '
        . 'WHERE ' . mc_q($schema['item_id']) . ' = ? '
        . 'ORDER BY '
        . ($schema['order_index'] ? 'COALESCE(' . mc_q($schema['order_index']) . ', 0)' : '0') . ' ASC, '
        . mc_q($schema['title']) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$itemId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    api_success('Maritime signals listesi getirildi.', [
        'signals' => $rows,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

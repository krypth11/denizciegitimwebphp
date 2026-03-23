<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/maritime_content_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);
    $categoryId = mc_require_query_id('category_id');

    $schema = mc_get_maritime_english_schema($pdo)['topics'];

    $sql = 'SELECT '
        . mc_q($schema['id']) . ' AS id, '
        . mc_q($schema['category_id']) . ' AS category_id, '
        . mc_q($schema['title']) . ' AS title, '
        . ($schema['order_index'] ? mc_q($schema['order_index']) : '0') . ' AS order_index '
        . 'FROM ' . mc_q($schema['table']) . ' '
        . 'WHERE ' . mc_q($schema['category_id']) . ' = ? '
        . 'ORDER BY '
        . ($schema['order_index'] ? 'COALESCE(' . mc_q($schema['order_index']) . ', 0)' : '0') . ' ASC, '
        . mc_q($schema['title']) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categoryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    api_success('Maritime english topic listesi getirildi.', [
        'topics' => $rows,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

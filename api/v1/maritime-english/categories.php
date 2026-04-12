<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/maritime_content_helper.php';
require_once dirname(__DIR__) . '/usage_limits_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];
    if (!usage_limits_is_user_pro($pdo, $userId)) {
        usage_limits_business_error(
            'PREMIUM_REQUIRED',
            'Maritime English Pro üyelik gerektirir.',
            403
        );
    }

    $schema = mc_get_maritime_english_schema($pdo)['categories'];

    $sql = 'SELECT '
        . mc_q($schema['id']) . ' AS id, '
        . mc_q($schema['name']) . ' AS name, '
        . ($schema['description'] ? mc_q($schema['description']) : "''") . ' AS description, '
        . ($schema['color'] ? mc_q($schema['color']) : 'NULL') . ' AS color, '
        . ($schema['icon_name'] ? mc_q($schema['icon_name']) : 'NULL') . ' AS icon_name, '
        . ($schema['order_index'] ? mc_q($schema['order_index']) : '0') . ' AS order_index '
        . 'FROM ' . mc_q($schema['table']) . ' '
        . 'ORDER BY '
        . ($schema['order_index'] ? 'COALESCE(' . mc_q($schema['order_index']) . ', 0)' : '0') . ' ASC, '
        . mc_q($schema['name']) . ' ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    api_success('Maritime english kategorileri getirildi.', [
        'categories' => $rows,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

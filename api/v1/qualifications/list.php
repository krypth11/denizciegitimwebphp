<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);

    $stmt = $pdo->query(
        'SELECT id, name, description, order_index
         FROM qualifications
         ORDER BY COALESCE(order_index, 0) ASC, name ASC'
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $qualifications = array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => $row['description'] ?? null,
            'order_index' => (int)($row['order_index'] ?? 0),
        ];
    }, $rows);

    api_success('Yeterlilik listesi getirildi.', [
        'qualifications' => $qualifications,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

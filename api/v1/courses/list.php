<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);

    $qualificationId = trim((string)($_GET['qualification_id'] ?? ''));
    if ($qualificationId === '') {
        api_error('qualification_id parametresi zorunludur.', 422);
    }

    if (mb_strlen($qualificationId) > 191) {
        api_error('Geçersiz qualification_id.', 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, qualification_id, name, description, icon, order_index, created_at
         FROM courses
         WHERE qualification_id = ?
         ORDER BY COALESCE(order_index, 0) ASC, name ASC'
    );
    $stmt->execute([$qualificationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $courses = array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'qualification_id' => (string)($row['qualification_id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'description' => $row['description'] ?? null,
            'icon' => $row['icon'] ?? null,
            'order_index' => (int)($row['order_index'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $rows);

    api_success('Kurs listesi getirildi.', [
        'courses' => $courses,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

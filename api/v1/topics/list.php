<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);

    $courseId = trim((string)($_GET['course_id'] ?? ''));
    if ($courseId === '') {
        api_error('course_id parametresi zorunludur.', 422);
    }

    if (mb_strlen($courseId) > 191) {
        api_error('Geçersiz course_id.', 422);
    }

    $stmt = $pdo->prepare(
        'SELECT id, course_id, name, content, order_index, created_at
         FROM topics
         WHERE course_id = ?
         ORDER BY COALESCE(order_index, 0) ASC, name ASC'
    );
    $stmt->execute([$courseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $topics = array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'course_id' => (string)($row['course_id'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'content' => $row['content'] ?? null,
            'order_index' => (int)($row['order_index'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
        ];
    }, $rows);

    api_success('Konu listesi getirildi.', [
        'topics' => $topics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

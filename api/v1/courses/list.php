<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);

    $qualificationId = api_require_query_param('qualification_id', 191);
    $currentQualificationId = api_assert_requested_qualification_matches_current($pdo, $auth, $qualificationId, 'courses.list');

    $stmt = $pdo->prepare(
        'SELECT id, qualification_id, name, description, icon, order_index, created_at
         FROM courses
         WHERE qualification_id = ?
         ORDER BY COALESCE(order_index, 0) ASC, name ASC'
    );
    $stmt->execute([$currentQualificationId]);
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

    api_qualification_access_log('study qualifications returned count', [
        'context' => 'courses.list',
        'count' => count($courses),
        'current_qualification_id' => $currentQualificationId,
    ]);

    api_success('Kurs listesi getirildi.', [
        'courses' => $courses,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

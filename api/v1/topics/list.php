<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $currentQualificationId = api_require_current_user_qualification_id($pdo, $auth, 'topics.list');

    $courseId = api_require_query_param('course_id', 191);

    $courseStmt = $pdo->prepare('SELECT qualification_id FROM courses WHERE id = ? LIMIT 1');
    $courseStmt->execute([$courseId]);
    $courseRow = $courseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$courseRow) {
        api_error('Kurs bulunamadı.', 404);
    }

    $requestedQualificationId = trim((string)($courseRow['qualification_id'] ?? ''));
    api_assert_requested_qualification_matches_current($pdo, $auth, $requestedQualificationId, 'topics.list');

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

    api_qualification_access_log('study qualifications returned count', [
        'context' => 'topics.list',
        'count' => count($topics),
        'requested_qualification_id' => $requestedQualificationId,
    ]);

    api_qualification_access_log('study qualification returned', [
        'context' => 'topics.list',
        'study qualification returned' => $currentQualificationId,
    ]);

    api_success('Konu listesi getirildi.', [
        'topics' => $topics,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

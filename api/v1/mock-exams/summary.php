<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__) . '/mock_exam_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $preferences = mock_exam_get_user_exam_preferences($pdo, $userId);
    $activeAttempt = mock_exam_find_active_attempt($pdo, $userId);
    $overview = mock_exam_build_summary_stats($pdo, $userId);
    $recent = mock_exam_fetch_history($pdo, $userId, ['page' => 1, 'per_page' => 5, 'status' => 'all', 'sort' => 'newest']);

    $qualificationStats = [];
    $a = mock_exam_get_attempt_schema($pdo);
    $sql = 'SELECT q.id AS qualification_id, q.name AS qualification_name, COUNT(*) AS total_attempts, '
        . 'AVG(CASE WHEN a.' . mock_exam_q($a['status']) . " = 'completed' THEN COALESCE(a." . mock_exam_q($a['success_rate']) . ',0) END) AS average_success_rate '
        . 'FROM `' . $a['table'] . '` a LEFT JOIN qualifications q ON a.' . mock_exam_q($a['qualification_id']) . ' = q.id '
        . 'WHERE a.' . mock_exam_q($a['user_id']) . ' = ? '
        . 'GROUP BY q.id, q.name ORDER BY total_attempts DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $qualificationStats[] = [
            'qualification_id' => $r['qualification_id'] ?? null,
            'qualification_name' => $r['qualification_name'] ?? null,
            'total_attempts' => (int)($r['total_attempts'] ?? 0),
            'average_success_rate' => $r['average_success_rate'] !== null ? round((float)$r['average_success_rate'], 2) : 0.0,
        ];
    }

    api_success('Deneme sınavı özet bilgisi alındı.', [
        'preferences' => $preferences,
        'active_attempt' => $activeAttempt,
        'overview_cards' => $overview,
        'recent_attempts' => $recent['items'] ?? [],
        'qualification_stats' => $qualificationStats,
    ]);
} catch (Throwable $e) {
    api_error($e->getMessage(), 422);
}

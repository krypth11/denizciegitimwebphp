<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
api_require_method('GET');
try {
    $auth = api_require_auth($pdo);
    $userId = (string)($auth['user']['id'] ?? '');
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'maritime-english.catalog');
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.description, c.sort_order,
                COUNT(DISTINCT CASE WHEN t.is_active = 1 AND t.content_status = 'published'
                  AND (t.qualification_id IS NULL OR t.qualification_id = ?) THEN t.id END) AS term_count,
                COUNT(DISTINCT CASE WHEN q.is_active = 1 AND t.is_active = 1 AND t.content_status = 'published'
                  AND (t.qualification_id IS NULL OR t.qualification_id = ?) THEN q.id END) AS question_count,
                COUNT(DISTINCT CASE WHEN ut.user_id = ? AND ut.learning_state = 'mastered' THEN t.id END) AS mastered_count,
                COUNT(DISTINCT CASE WHEN ut.user_id = ? AND ut.learning_state IN ('learning','review','relearning') THEN t.id END) AS learning_count
         FROM maritime_english_categories c
         LEFT JOIN maritime_english_terms t ON t.category_id = c.id
         LEFT JOIN maritime_english_questions q ON q.term_id = t.id
         LEFT JOIN maritime_english_user_terms ut ON ut.term_id = t.id AND ut.user_id = ?
         WHERE c.is_active = 1 GROUP BY c.id, c.name, c.description, c.sort_order ORDER BY c.sort_order, c.name"
    );
    $stmt->execute([$qualificationId, $qualificationId, $userId, $userId, $userId]);
    $categories = array_map(static fn($r) => [
        'id' => (string)$r['id'], 'name' => (string)$r['name'], 'description' => (string)($r['description'] ?? ''),
        'term_count' => (int)$r['term_count'], 'question_count' => (int)$r['question_count'],
        'mastered_count' => (int)$r['mastered_count'], 'learning_count' => (int)$r['learning_count'],
        'available' => (int)$r['term_count'] >= 5 && (int)$r['question_count'] >= 10,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    api_send_json(['success' => true, 'data' => ['categories' => $categories]]);
} catch (Throwable $e) { api_error('Maritime English kategorileri alınamadı.', 500); }

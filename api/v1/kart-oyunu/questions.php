<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once dirname(__DIR__, 3) . '/includes/kart_game_helper.php';

api_require_method('GET');

try {
    $auth = api_require_auth($pdo);
    $qualificationId = api_require_current_user_qualification_id($pdo, $auth, 'kart-oyunu.questions');

    $questions = kg_get_active_questions_for_qualification($pdo, $qualificationId);

    $payload = array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'category_id' => (string)($row['category_id'] ?? ''),
            'category_name' => (string)($row['category_name'] ?? ''),
            'question_text' => (string)($row['question_text'] ?? ''),
            'correct_answer' => (string)($row['correct_answer'] ?? ''),
            'image_url' => (string)($row['image_url'] ?? ''),
        ];
    }, $questions);

    api_send_json([
        'success' => true,
        'questions' => $payload,
    ], 200);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

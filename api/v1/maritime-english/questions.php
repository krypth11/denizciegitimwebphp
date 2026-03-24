<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/maritime_content_helper.php';

api_require_method('GET');

try {
    api_require_auth($pdo);
    $topicId = mc_require_query_id('topic_id');

    $schema = mc_get_maritime_english_schema($pdo)['questions'];

    $sql = 'SELECT '
        . mc_q($schema['id']) . ' AS id, '
        . mc_q($schema['topic_id']) . ' AS topic_id, '
        . mc_q($schema['question_text']) . ' AS question_text, '
        . ($schema['option_a'] ? mc_q($schema['option_a']) : "''") . ' AS option_a, '
        . ($schema['option_b'] ? mc_q($schema['option_b']) : "''") . ' AS option_b, '
        . ($schema['option_c'] ? mc_q($schema['option_c']) : "''") . ' AS option_c, '
        . ($schema['option_d'] ? mc_q($schema['option_d']) : "''") . ' AS option_d, '
        . ($schema['option_e'] ? mc_q($schema['option_e']) : 'NULL') . ' AS option_e, '
        . ($schema['correct_answer'] ? mc_q($schema['correct_answer']) : "''") . ' AS correct_answer, '
        . ($schema['explanation'] ? mc_q($schema['explanation']) : "''") . ' AS explanation, '
        . ($schema['created_at'] ? mc_q($schema['created_at']) : 'NULL') . ' AS created_at '
        . 'FROM ' . mc_q($schema['table']) . ' '
        . 'WHERE ' . mc_q($schema['topic_id']) . ' = ? '
        . 'ORDER BY '
        . ($schema['created_at'] ? mc_q($schema['created_at']) : mc_q($schema['id'])) . ' ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$topicId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = array_map(static function (array $row): array {
        $row['correct_answer'] = strtoupper(trim((string)($row['correct_answer'] ?? '')));
        $optionE = trim((string)($row['option_e'] ?? ''));
        $row['option_e'] = ($optionE !== '') ? $optionE : null;
        return $row;
    }, $rows);

    api_success('Maritime english soru listesi getirildi.', [
        'questions' => $rows,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}

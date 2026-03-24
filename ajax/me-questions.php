<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_admin();

const MEQ_CATEGORY_TABLE = 'maritime_english_categories';
const MEQ_TOPIC_TABLE = 'maritime_english_topics';
const MEQ_QUESTION_TABLE = 'maritime_english_questions';

function meq_json($success, $message = '', $data = [], $status = 200, $errors = [])
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function meq_table_exists(PDO $pdo, $table)
{
    $sql = 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([DB_NAME, $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function meq_columns(PDO $pdo, $table)
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) $map[$row['Field']] = $row;

    $cache[$table] = $map;
    return $map;
}

function meq_pick(array $columns, array $candidates, $required = true)
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) return $candidate;
    }
    if ($required) throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    return null;
}

function meq_q($identifier)
{
    return '`' . str_replace('`', '', $identifier) . '`';
}

function meq_topic_matches_category(PDO $pdo, array $s, string $topicId, string $categoryId): bool
{
    $topicSql = 'SELECT ' . meq_q($s['t_cat_fk']) . ' AS category_id FROM ' . meq_q($s['t_table'])
        . ' WHERE ' . meq_q($s['t_id']) . ' = ? LIMIT 1';
    $topicStmt = $pdo->prepare($topicSql);
    $topicStmt->execute([$topicId]);
    $topicRow = $topicStmt->fetch(PDO::FETCH_ASSOC);

    if (!$topicRow) {
        return false;
    }

    return (string)$topicRow['category_id'] === (string)$categoryId;
}

function meq_normalize_question_item(array $input): array
{
    return [
        'category_id' => trim((string)($input['category_id'] ?? '')),
        'topic_id' => trim((string)($input['topic_id'] ?? '')),
        'question_text' => sanitize_input($input['question_text'] ?? ''),
        'option_a' => sanitize_input($input['option_a'] ?? ''),
        'option_b' => sanitize_input($input['option_b'] ?? ''),
        'option_c' => sanitize_input($input['option_c'] ?? ''),
        'option_d' => sanitize_input($input['option_d'] ?? ''),
        'option_e' => sanitize_input($input['option_e'] ?? ''),
        'correct_answer' => strtoupper(trim((string)($input['correct_answer'] ?? ''))),
        'explanation' => sanitize_input($input['explanation'] ?? ''),
        'question_type' => sanitize_input($input['question_type'] ?? ''),
    ];
}

function meq_validate_question_item(array $q, array $s): array
{
    if (
        $q['category_id'] === '' ||
        $q['topic_id'] === '' ||
        $q['question_text'] === '' ||
        $q['option_a'] === '' ||
        $q['option_b'] === '' ||
        $q['option_c'] === '' ||
        $q['option_d'] === ''
    ) {
        return [false, 'Kategori, topic, soru metni ve A/B/C/D şıkları zorunludur.', 'missing_required_fields'];
    }

    if (!in_array($q['correct_answer'], ['A', 'B', 'C', 'D', 'E'], true)) {
        return [false, 'Doğru cevap yalnızca A/B/C/D/E olabilir.', 'invalid_correct_answer'];
    }

    if ($q['correct_answer'] === 'E' && $q['option_e'] === '') {
        return [false, 'Doğru cevap E ise E şıkkı boş bırakılamaz.', 'correct_answer_e_but_option_e_empty'];
    }

    if ($q['correct_answer'] === 'E' && !$s['q_opt_e']) {
        return [false, 'Bu tabloda E şıkkı alanı desteklenmediği için doğru cevap E olarak kaydedilemez.', 'correct_answer_e_but_option_e_not_supported'];
    }

    return [true, '', ''];
}

function meq_schema(PDO $pdo)
{
    if (!meq_table_exists($pdo, MEQ_QUESTION_TABLE)) {
        throw new RuntimeException('maritime_english_questions tablosu bulunamadı.');
    }
    if (!meq_table_exists($pdo, MEQ_TOPIC_TABLE)) {
        throw new RuntimeException('maritime_english_topics tablosu bulunamadı.');
    }
    if (!meq_table_exists($pdo, MEQ_CATEGORY_TABLE)) {
        throw new RuntimeException('maritime_english_categories tablosu bulunamadı.');
    }

    $qCols = meq_columns($pdo, MEQ_QUESTION_TABLE);
    $tCols = meq_columns($pdo, MEQ_TOPIC_TABLE);
    $cCols = meq_columns($pdo, MEQ_CATEGORY_TABLE);

    return [
        'q_table' => MEQ_QUESTION_TABLE,
        't_table' => MEQ_TOPIC_TABLE,
        'c_table' => MEQ_CATEGORY_TABLE,
        'q_cols' => $qCols,
        't_cols' => $tCols,
        'c_cols' => $cCols,

        'q_id' => meq_pick($qCols, ['id', 'question_id', 'uuid']),
        'q_topic_fk' => meq_pick($qCols, ['topic_id', 'maritime_english_topic_id']),
        'q_cat_fk' => meq_pick($qCols, ['category_id', 'maritime_english_category_id'], false),
        'q_text' => meq_pick($qCols, ['question_text', 'question', 'text', 'content']),
        'q_opt_a' => meq_pick($qCols, ['option_a', 'a_option', 'a']),
        'q_opt_b' => meq_pick($qCols, ['option_b', 'b_option', 'b']),
        'q_opt_c' => meq_pick($qCols, ['option_c', 'c_option', 'c']),
        'q_opt_d' => meq_pick($qCols, ['option_d', 'd_option', 'd']),
        'q_opt_e' => meq_pick($qCols, ['option_e', 'e_option', 'e'], false),
        'q_correct' => meq_pick($qCols, ['correct_answer', 'correct_option', 'answer']),
        'q_explanation' => meq_pick($qCols, ['explanation', 'description', 'note'], false),
        'q_type' => meq_pick($qCols, ['question_type', 'type'], false),
        'q_order' => meq_pick($qCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
        'q_created' => meq_pick($qCols, ['created_at', 'created_on'], false),
        'q_updated' => meq_pick($qCols, ['updated_at', 'updated_on'], false),

        't_id' => meq_pick($tCols, ['id', 'topic_id', 'uuid']),
        't_cat_fk' => meq_pick($tCols, ['category_id', 'maritime_english_category_id', 'maritime_category_id']),
        't_name' => meq_pick($tCols, ['name', 'topic_name', 'title']),
        't_order' => meq_pick($tCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),

        'c_id' => meq_pick($cCols, ['id', 'category_id', 'uuid']),
        'c_name' => meq_pick($cCols, ['name', 'category_name', 'title']),
        'c_order' => meq_pick($cCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
    ];
}

function meq_set_id_if_needed(array &$data, array $colMeta, $idCol)
{
    if (!$idCol || !isset($colMeta[$idCol])) return;
    $extra = strtolower((string)($colMeta[$idCol]['Extra'] ?? ''));
    if (str_contains($extra, 'auto_increment')) return;
    if (!isset($data[$idCol])) $data[$idCol] = generate_uuid();
}

function meq_insert(PDO $pdo, $table, array $data)
{
    $cols = array_keys($data);
    $quoted = array_map('meq_q', $cols);
    $holders = array_fill(0, count($cols), '?');

    $sql = 'INSERT INTO ' . meq_q($table)
        . ' (' . implode(', ', $quoted) . ')'
        . ' VALUES (' . implode(', ', $holders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
}

function meq_update(PDO $pdo, $table, array $data, $idCol, $id)
{
    if (!$data) return;
    $set = [];
    $vals = [];
    foreach ($data as $col => $val) {
        $set[] = meq_q($col) . ' = ?';
        $vals[] = $val;
    }
    $vals[] = $id;

    $sql = 'UPDATE ' . meq_q($table)
        . ' SET ' . implode(', ', $set)
        . ' WHERE ' . meq_q($idCol) . ' = ? LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $s = meq_schema($pdo);

    switch ($action) {
        case 'list_categories': {
            $sql = 'SELECT '
                . 'c.' . meq_q($s['c_id']) . ' AS id, '
                . 'c.' . meq_q($s['c_name']) . ' AS name '
                . 'FROM ' . meq_q($s['c_table']) . ' c '
                . 'ORDER BY '
                . ($s['c_order'] ? 'c.' . meq_q($s['c_order']) . ' ASC, ' : '')
                . 'c.' . meq_q($s['c_name']) . ' ASC';

            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            meq_json(true, '', ['categories' => $rows]);
            break;
        }

        case 'list_topics': {
            $categoryId = trim((string)($_GET['category_id'] ?? ''));

            $sql = 'SELECT '
                . 't.' . meq_q($s['t_id']) . ' AS id, '
                . 't.' . meq_q($s['t_cat_fk']) . ' AS category_id, '
                . 't.' . meq_q($s['t_name']) . ' AS name '
                . 'FROM ' . meq_q($s['t_table']) . ' t ';

            $params = [];
            if ($categoryId !== '') {
                $sql .= ' WHERE t.' . meq_q($s['t_cat_fk']) . ' = ?';
                $params[] = $categoryId;
            }

            $sql .= ' ORDER BY '
                . ($s['t_order'] ? 't.' . meq_q($s['t_order']) . ' ASC, ' : '')
                . 't.' . meq_q($s['t_name']) . ' ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            meq_json(true, '', ['topics' => $rows]);
            break;
        }

        case 'list_questions': {
            $categoryId = trim((string)($_GET['category_id'] ?? ''));
            $topicId = trim((string)($_GET['topic_id'] ?? ''));
            $questionType = trim((string)($_GET['question_type'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            $select = [
                'q.' . meq_q($s['q_id']) . ' AS id',
                'q.' . meq_q($s['q_topic_fk']) . ' AS topic_id',
                ($s['q_cat_fk'] ? 'q.' . meq_q($s['q_cat_fk']) : 't.' . meq_q($s['t_cat_fk'])) . ' AS category_id',
                'q.' . meq_q($s['q_text']) . ' AS question_text',
                'q.' . meq_q($s['q_opt_a']) . ' AS option_a',
                'q.' . meq_q($s['q_opt_b']) . ' AS option_b',
                'q.' . meq_q($s['q_opt_c']) . ' AS option_c',
                'q.' . meq_q($s['q_opt_d']) . ' AS option_d',
                ($s['q_opt_e'] ? 'q.' . meq_q($s['q_opt_e']) : "''") . ' AS option_e',
                'UPPER(q.' . meq_q($s['q_correct']) . ') AS correct_answer',
                ($s['q_explanation'] ? 'q.' . meq_q($s['q_explanation']) : "''") . ' AS explanation',
                ($s['q_type'] ? 'q.' . meq_q($s['q_type']) : "''") . ' AS question_type',
                ($s['q_order'] ? 'q.' . meq_q($s['q_order']) : '0') . ' AS order_index',
                't.' . meq_q($s['t_name']) . ' AS topic_name',
                'c.' . meq_q($s['c_name']) . ' AS category_name',
            ];

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . meq_q($s['q_table']) . ' q '
                . ' LEFT JOIN ' . meq_q($s['t_table']) . ' t ON t.' . meq_q($s['t_id']) . ' = q.' . meq_q($s['q_topic_fk'])
                . ' LEFT JOIN ' . meq_q($s['c_table']) . ' c ON c.' . meq_q($s['c_id']) . ' = t.' . meq_q($s['t_cat_fk']);

            $where = [];
            $params = [];

            if ($categoryId !== '') {
                if ($s['q_cat_fk']) {
                    $where[] = '(q.' . meq_q($s['q_cat_fk']) . ' = ? OR t.' . meq_q($s['t_cat_fk']) . ' = ?)';
                    $params[] = $categoryId;
                    $params[] = $categoryId;
                } else {
                    $where[] = 't.' . meq_q($s['t_cat_fk']) . ' = ?';
                    $params[] = $categoryId;
                }
            }
            if ($topicId !== '') {
                $where[] = 'q.' . meq_q($s['q_topic_fk']) . ' = ?';
                $params[] = $topicId;
            }
            if ($questionType !== '' && $s['q_type']) {
                $where[] = 'q.' . meq_q($s['q_type']) . ' = ?';
                $params[] = $questionType;
            }
            if ($search !== '') {
                $where[] = '(q.' . meq_q($s['q_text']) . ' LIKE ?'
                    . ($s['q_explanation'] ? ' OR q.' . meq_q($s['q_explanation']) . ' LIKE ?' : '')
                    . ')';
                $params[] = '%' . $search . '%';
                if ($s['q_explanation']) $params[] = '%' . $search . '%';
            }

            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY '
                . ($s['q_order'] ? 'q.' . meq_q($s['q_order']) . ' ASC, ' : '')
                . ($s['q_created'] ? 'q.' . meq_q($s['q_created']) . ' DESC, ' : '')
                . 'q.' . meq_q($s['q_id']) . ' DESC '
                . 'LIMIT 500';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            meq_json(true, '', ['questions' => $rows]);
            break;
        }

        case 'get_question': {
            $id = $_GET['id'] ?? '';
            if ($id === '') meq_json(false, 'Soru ID gerekli.', [], 422);

            $sql = 'SELECT '
                . 'q.' . meq_q($s['q_id']) . ' AS id, '
                . 'q.' . meq_q($s['q_topic_fk']) . ' AS topic_id, '
                . ($s['q_cat_fk'] ? 'q.' . meq_q($s['q_cat_fk']) : 't.' . meq_q($s['t_cat_fk'])) . ' AS category_id, '
                . 'q.' . meq_q($s['q_text']) . ' AS question_text, '
                . 'q.' . meq_q($s['q_opt_a']) . ' AS option_a, '
                . 'q.' . meq_q($s['q_opt_b']) . ' AS option_b, '
                . 'q.' . meq_q($s['q_opt_c']) . ' AS option_c, '
                . 'q.' . meq_q($s['q_opt_d']) . ' AS option_d, '
                . ($s['q_opt_e'] ? 'q.' . meq_q($s['q_opt_e']) : "''") . ' AS option_e, '
                . 'UPPER(q.' . meq_q($s['q_correct']) . ') AS correct_answer, '
                . ($s['q_explanation'] ? 'q.' . meq_q($s['q_explanation']) : "''") . ' AS explanation, '
                . ($s['q_type'] ? 'q.' . meq_q($s['q_type']) : "''") . ' AS question_type '
                . 'FROM ' . meq_q($s['q_table']) . ' q '
                . 'LEFT JOIN ' . meq_q($s['t_table']) . ' t ON t.' . meq_q($s['t_id']) . ' = q.' . meq_q($s['q_topic_fk'])
                . ' WHERE q.' . meq_q($s['q_id']) . ' = ? LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) meq_json(false, 'Soru bulunamadı.', [], 404);
            meq_json(true, '', ['question' => $row]);
            break;
        }

        case 'add_question': {
            $q = meq_normalize_question_item($_POST);
            [$valid, $validationMessage, $validationCode] = meq_validate_question_item($q, $s);
            if (!$valid) {
                meq_json(false, $validationMessage, [], 422, ['validation' => $validationCode]);
            }

            if (!meq_topic_matches_category($pdo, $s, $q['topic_id'], $q['category_id'])) {
                meq_json(false, 'Seçilen topic bu kategoriye ait değil.', [], 422);
            }

            $insert = [
                $s['q_topic_fk'] => $q['topic_id'],
                $s['q_text'] => $q['question_text'],
                $s['q_opt_a'] => $q['option_a'],
                $s['q_opt_b'] => $q['option_b'],
                $s['q_opt_c'] => $q['option_c'],
                $s['q_opt_d'] => $q['option_d'],
                $s['q_correct'] => $q['correct_answer'],
            ];
            if ($s['q_cat_fk']) $insert[$s['q_cat_fk']] = $q['category_id'];
            if ($s['q_opt_e']) $insert[$s['q_opt_e']] = ($q['option_e'] !== '' ? $q['option_e'] : null);
            if ($s['q_explanation']) $insert[$s['q_explanation']] = $q['explanation'];
            if ($s['q_type']) $insert[$s['q_type']] = $q['question_type'];
            if ($s['q_created']) $insert[$s['q_created']] = date('Y-m-d H:i:s');
            if ($s['q_updated']) $insert[$s['q_updated']] = date('Y-m-d H:i:s');

            meq_set_id_if_needed($insert, $s['q_cols'], $s['q_id']);
            meq_insert($pdo, $s['q_table'], $insert);
            meq_json(true, 'Soru başarıyla eklendi.');
            break;
        }

        case 'update_question': {
            $id = $_POST['id'] ?? '';
            $q = meq_normalize_question_item($_POST);

            if ($id === '') meq_json(false, 'Soru ID gerekli.', [], 422);
            [$valid, $validationMessage, $validationCode] = meq_validate_question_item($q, $s);
            if (!$valid) {
                meq_json(false, $validationMessage, [], 422, ['validation' => $validationCode]);
            }

            $existsSql = 'SELECT COUNT(*) FROM ' . meq_q($s['q_table'])
                . ' WHERE ' . meq_q($s['q_id']) . ' = ?';
            $existsStmt = $pdo->prepare($existsSql);
            $existsStmt->execute([$id]);
            if ((int)$existsStmt->fetchColumn() === 0) meq_json(false, 'Soru bulunamadı.', [], 404);

            if (!meq_topic_matches_category($pdo, $s, $q['topic_id'], $q['category_id'])) {
                meq_json(false, 'Seçilen topic bu kategoriye ait değil.', [], 422);
            }

            $update = [
                $s['q_topic_fk'] => $q['topic_id'],
                $s['q_text'] => $q['question_text'],
                $s['q_opt_a'] => $q['option_a'],
                $s['q_opt_b'] => $q['option_b'],
                $s['q_opt_c'] => $q['option_c'],
                $s['q_opt_d'] => $q['option_d'],
                $s['q_correct'] => $q['correct_answer'],
            ];
            if ($s['q_cat_fk']) $update[$s['q_cat_fk']] = $q['category_id'];
            if ($s['q_opt_e']) $update[$s['q_opt_e']] = ($q['option_e'] !== '' ? $q['option_e'] : null);
            if ($s['q_explanation']) $update[$s['q_explanation']] = $q['explanation'];
            if ($s['q_type']) $update[$s['q_type']] = $q['question_type'];
            if ($s['q_updated']) $update[$s['q_updated']] = date('Y-m-d H:i:s');

            meq_update($pdo, $s['q_table'], $update, $s['q_id'], $id);
            meq_json(true, 'Soru başarıyla güncellendi.');
            break;
        }

        case 'delete_question': {
            $id = $_POST['id'] ?? '';
            if ($id === '') meq_json(false, 'Soru ID gerekli.', [], 422);

            $sql = 'DELETE FROM ' . meq_q($s['q_table'])
                . ' WHERE ' . meq_q($s['q_id']) . ' = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) meq_json(false, 'Soru bulunamadı veya silinemedi.', [], 404);
            meq_json(true, 'Soru başarıyla silindi.');
            break;
        }

        case 'save_bulk_questions': {
            $raw = $_POST['questions'] ?? '[]';
            $items = json_decode($raw, true);

            if (!is_array($items) || !$items) {
                meq_json(false, 'Kaydedilecek soru bulunamadı.', [], 422);
            }

            $saved = 0;
            $skipped = 0;
            $skippedReasons = [];
            $skippedSamples = [];

            $addSkip = static function (string $reason, array $item = []) use (&$skipped, &$skippedReasons, &$skippedSamples) {
                $skipped++;
                $skippedReasons[$reason] = (int)($skippedReasons[$reason] ?? 0) + 1;

                if (count($skippedSamples) >= 8) {
                    return;
                }

                $questionText = trim((string)($item['question_text'] ?? ''));
                if (mb_strlen($questionText, 'UTF-8') > 140) {
                    $questionText = mb_substr($questionText, 0, 140, 'UTF-8') . '…';
                }

                $skippedSamples[] = [
                    'reason' => $reason,
                    'question_text' => $questionText,
                    'correct_answer' => strtoupper(trim((string)($item['correct_answer'] ?? ''))),
                    'has_option_e' => trim((string)($item['option_e'] ?? '')) !== '',
                ];
            };

            foreach ($items as $item) {
                $q = meq_normalize_question_item($item);
                [$valid, , $validationCode] = meq_validate_question_item($q, $s);
                if (!$valid) {
                    $addSkip($validationCode ?: 'validation_failed', $item);
                    continue;
                }

                if (!meq_topic_matches_category($pdo, $s, $q['topic_id'], $q['category_id'])) {
                    $addSkip('topic_category_mismatch', $item);
                    continue;
                }

                $insert = [
                    $s['q_topic_fk'] => $q['topic_id'],
                    $s['q_text'] => $q['question_text'],
                    $s['q_opt_a'] => $q['option_a'],
                    $s['q_opt_b'] => $q['option_b'],
                    $s['q_opt_c'] => $q['option_c'],
                    $s['q_opt_d'] => $q['option_d'],
                    $s['q_correct'] => $q['correct_answer'],
                ];

                if ($s['q_cat_fk']) $insert[$s['q_cat_fk']] = $q['category_id'];
                if ($s['q_opt_e']) $insert[$s['q_opt_e']] = ($q['option_e'] !== '' ? $q['option_e'] : null);
                if ($s['q_explanation']) $insert[$s['q_explanation']] = $q['explanation'];
                if ($s['q_created']) $insert[$s['q_created']] = date('Y-m-d H:i:s');
                if ($s['q_updated']) $insert[$s['q_updated']] = date('Y-m-d H:i:s');

                meq_set_id_if_needed($insert, $s['q_cols'], $s['q_id']);
                meq_insert($pdo, $s['q_table'], $insert);
                $saved++;
            }

            if ($saved === 0) {
                meq_json(false, 'Onaylanan sorular kaydedilemedi.', [
                    'saved' => 0,
                    'skipped' => $skipped,
                    'saved_count' => 0,
                    'skipped_count' => $skipped,
                    'skipped_reasons' => $skippedReasons,
                    'skipped_samples' => $skippedSamples,
                ], 422);
            }

            meq_json(true, $saved . ' soru başarıyla kaydedildi.' . ($skipped > 0 ? ' ' . $skipped . ' soru atlandı.' : ''), [
                'saved' => $saved,
                'skipped' => $skipped,
                'saved_count' => $saved,
                'skipped_count' => $skipped,
                'skipped_reasons' => $skippedReasons,
                'skipped_samples' => $skippedSamples,
            ]);
            break;
        }

        default:
            meq_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    meq_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

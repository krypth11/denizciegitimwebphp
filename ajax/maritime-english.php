<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_admin();

const ME_CATEGORY_TABLE = 'maritime_english_categories';
const ME_TOPIC_TABLE = 'maritime_english_topics';

function me_json($success, $message = '', $data = [], $status = 200, $errors = [])
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

function me_table_columns(PDO $pdo, $table)
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['Field']] = $row;
    }

    $cache[$table] = $map;
    return $map;
}

function me_pick_column(array $columns, array $candidates, $required = true)
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    if ($required) {
        throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    }

    return null;
}

function me_quote_col($col)
{
    return '`' . str_replace('`', '', $col) . '`';
}

function me_schema(PDO $pdo)
{
    $catCols = me_table_columns($pdo, ME_CATEGORY_TABLE);
    $topicCols = me_table_columns($pdo, ME_TOPIC_TABLE);

    return [
        'cat_table' => ME_CATEGORY_TABLE,
        'topic_table' => ME_TOPIC_TABLE,
        'cat_cols' => $catCols,
        'topic_cols' => $topicCols,

        'cat_id' => me_pick_column($catCols, ['id', 'category_id', 'uuid']),
        'cat_name' => me_pick_column($catCols, ['name', 'category_name', 'title']),
        'cat_description' => me_pick_column($catCols, ['description', 'content', 'summary', 'text'], false),
        'cat_order' => me_pick_column($catCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
        'cat_created' => me_pick_column($catCols, ['created_at', 'created_on'], false),
        'cat_updated' => me_pick_column($catCols, ['updated_at', 'updated_on'], false),

        'topic_id' => me_pick_column($topicCols, ['id', 'topic_id', 'uuid']),
        'topic_cat_fk' => me_pick_column($topicCols, ['category_id', 'maritime_english_category_id', 'maritime_category_id']),
        'topic_name' => me_pick_column($topicCols, ['name', 'topic_name', 'title']),
        'topic_description' => me_pick_column($topicCols, ['description', 'content', 'summary', 'text', 'body'], false),
        'topic_order' => me_pick_column($topicCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
        'topic_created' => me_pick_column($topicCols, ['created_at', 'created_on'], false),
        'topic_updated' => me_pick_column($topicCols, ['updated_at', 'updated_on'], false),
    ];
}

function me_maybe_set_id(array &$insertData, array $colMeta, $idCol)
{
    if (!$idCol || !isset($colMeta[$idCol])) {
        return;
    }

    $meta = $colMeta[$idCol];
    $extra = strtolower((string)($meta['Extra'] ?? ''));

    if (str_contains($extra, 'auto_increment')) {
        return;
    }

    if (!isset($insertData[$idCol])) {
        $insertData[$idCol] = generate_uuid();
    }
}

function me_build_insert(PDO $pdo, $table, array $data)
{
    $columns = array_keys($data);
    $quoted = array_map('me_quote_col', $columns);
    $placeholders = array_fill(0, count($columns), '?');

    $sql = 'INSERT INTO ' . me_quote_col($table)
        . ' (' . implode(', ', $quoted) . ')'
        . ' VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
}

function me_build_update(PDO $pdo, $table, array $data, $idCol, $idValue)
{
    if (!$data) {
        return;
    }

    $setParts = [];
    $values = [];

    foreach ($data as $col => $val) {
        $setParts[] = me_quote_col($col) . ' = ?';
        $values[] = $val;
    }

    $values[] = $idValue;

    $sql = 'UPDATE ' . me_quote_col($table)
        . ' SET ' . implode(', ', $setParts)
        . ' WHERE ' . me_quote_col($idCol) . ' = ? LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $schema = me_schema($pdo);

    switch ($action) {
        case 'list_categories': {
            $catId = $schema['cat_id'];
            $catName = $schema['cat_name'];
            $catDesc = $schema['cat_description'];
            $catOrder = $schema['cat_order'];
            $topicId = $schema['topic_id'];
            $topicFk = $schema['topic_cat_fk'];

            $select = [
                'c.' . me_quote_col($catId) . ' AS id',
                'c.' . me_quote_col($catName) . ' AS name',
                ($catDesc ? 'c.' . me_quote_col($catDesc) : "''") . ' AS description',
                ($catOrder ? 'c.' . me_quote_col($catOrder) : '0') . ' AS order_index',
                'COUNT(t.' . me_quote_col($topicId) . ') AS topic_count',
            ];

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . me_quote_col($schema['cat_table']) . ' c '
                . ' LEFT JOIN ' . me_quote_col($schema['topic_table']) . ' t '
                . ' ON t.' . me_quote_col($topicFk) . ' = c.' . me_quote_col($catId)
                . ' GROUP BY c.' . me_quote_col($catId) . ', c.' . me_quote_col($catName)
                . ($catDesc ? ', c.' . me_quote_col($catDesc) : '')
                . ($catOrder ? ', c.' . me_quote_col($catOrder) : '')
                . ' ORDER BY '
                . ($catOrder ? 'c.' . me_quote_col($catOrder) . ' ASC, ' : '')
                . 'c.' . me_quote_col($catName) . ' ASC';

            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            me_json(true, '', ['categories' => $rows]);
            break;
        }

        case 'list_topics': {
            $topicId = $schema['topic_id'];
            $topicFk = $schema['topic_cat_fk'];
            $topicName = $schema['topic_name'];
            $topicDesc = $schema['topic_description'];
            $topicOrder = $schema['topic_order'];

            $catId = $schema['cat_id'];
            $catName = $schema['cat_name'];

            $select = [
                't.' . me_quote_col($topicId) . ' AS id',
                't.' . me_quote_col($topicFk) . ' AS category_id',
                't.' . me_quote_col($topicName) . ' AS name',
                ($topicDesc ? 't.' . me_quote_col($topicDesc) : "''") . ' AS description',
                ($topicOrder ? 't.' . me_quote_col($topicOrder) : '0') . ' AS order_index',
                'c.' . me_quote_col($catName) . ' AS category_name',
            ];

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . me_quote_col($schema['topic_table']) . ' t '
                . ' LEFT JOIN ' . me_quote_col($schema['cat_table']) . ' c '
                . ' ON c.' . me_quote_col($catId) . ' = t.' . me_quote_col($topicFk)
                . ' ORDER BY '
                . ($topicOrder ? 't.' . me_quote_col($topicOrder) . ' ASC, ' : '')
                . 't.' . me_quote_col($topicName) . ' ASC';

            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            me_json(true, '', ['topics' => $rows]);
            break;
        }

        case 'add_category': {
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($name === '') {
                me_json(false, 'Kategori adı zorunludur.', [], 422, ['name' => 'required']);
            }

            $dupSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['cat_table'])
                . ' WHERE LOWER(' . me_quote_col($schema['cat_name']) . ') = LOWER(?)';
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute([$name]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                me_json(false, 'Aynı isimde kategori zaten mevcut.', [], 422, ['name' => 'duplicate']);
            }

            $insert = [
                $schema['cat_name'] => $name,
            ];

            if ($schema['cat_description']) {
                $insert[$schema['cat_description']] = $description;
            }
            if ($schema['cat_order']) {
                $insert[$schema['cat_order']] = $orderIndex;
            }
            if ($schema['cat_created']) {
                $insert[$schema['cat_created']] = date('Y-m-d H:i:s');
            }
            if ($schema['cat_updated']) {
                $insert[$schema['cat_updated']] = date('Y-m-d H:i:s');
            }

            me_maybe_set_id($insert, $schema['cat_cols'], $schema['cat_id']);
            me_build_insert($pdo, $schema['cat_table'], $insert);

            me_json(true, 'Kategori başarıyla eklendi.');
            break;
        }

        case 'update_category': {
            $id = $_POST['id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($id === '') {
                me_json(false, 'Kategori ID gerekli.', [], 422);
            }
            if ($name === '') {
                me_json(false, 'Kategori adı zorunludur.', [], 422, ['name' => 'required']);
            }

            $existsSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['cat_table'])
                . ' WHERE ' . me_quote_col($schema['cat_id']) . ' = ?';
            $existsStmt = $pdo->prepare($existsSql);
            $existsStmt->execute([$id]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                me_json(false, 'Kategori bulunamadı.', [], 404);
            }

            $dupSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['cat_table'])
                . ' WHERE LOWER(' . me_quote_col($schema['cat_name']) . ') = LOWER(?)'
                . ' AND ' . me_quote_col($schema['cat_id']) . ' <> ?';
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute([$name, $id]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                me_json(false, 'Aynı isimde başka kategori mevcut.', [], 422, ['name' => 'duplicate']);
            }

            $update = [
                $schema['cat_name'] => $name,
            ];

            if ($schema['cat_description']) {
                $update[$schema['cat_description']] = $description;
            }
            if ($schema['cat_order']) {
                $update[$schema['cat_order']] = $orderIndex;
            }
            if ($schema['cat_updated']) {
                $update[$schema['cat_updated']] = date('Y-m-d H:i:s');
            }

            me_build_update($pdo, $schema['cat_table'], $update, $schema['cat_id'], $id);
            me_json(true, 'Kategori başarıyla güncellendi.');
            break;
        }

        case 'delete_category': {
            $id = $_POST['id'] ?? '';
            if ($id === '') {
                me_json(false, 'Kategori ID gerekli.', [], 422);
            }

            $topicCountSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['topic_table'])
                . ' WHERE ' . me_quote_col($schema['topic_cat_fk']) . ' = ?';
            $topicCountStmt = $pdo->prepare($topicCountSql);
            $topicCountStmt->execute([$id]);
            $topicCount = (int)$topicCountStmt->fetchColumn();

            if ($topicCount > 0) {
                me_json(false, 'Bu kategoriye bağlı ' . $topicCount . ' topic var. Önce topicleri silin.', [], 422);
            }

            $delSql = 'DELETE FROM ' . me_quote_col($schema['cat_table'])
                . ' WHERE ' . me_quote_col($schema['cat_id']) . ' = ? LIMIT 1';
            $delStmt = $pdo->prepare($delSql);
            $delStmt->execute([$id]);

            if ($delStmt->rowCount() === 0) {
                me_json(false, 'Kategori bulunamadı veya silinemedi.', [], 404);
            }

            me_json(true, 'Kategori başarıyla silindi.');
            break;
        }

        case 'add_topic': {
            $categoryId = $_POST['category_id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($categoryId === '') {
                me_json(false, 'Kategori seçimi zorunludur.', [], 422, ['category_id' => 'required']);
            }
            if ($name === '') {
                me_json(false, 'Topic adı zorunludur.', [], 422, ['name' => 'required']);
            }

            $catExistsSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['cat_table'])
                . ' WHERE ' . me_quote_col($schema['cat_id']) . ' = ?';
            $catExistsStmt = $pdo->prepare($catExistsSql);
            $catExistsStmt->execute([$categoryId]);
            if ((int)$catExistsStmt->fetchColumn() === 0) {
                me_json(false, 'Seçilen kategori bulunamadı.', [], 422);
            }

            $insert = [
                $schema['topic_cat_fk'] => $categoryId,
                $schema['topic_name'] => $name,
            ];

            if ($schema['topic_description']) {
                $insert[$schema['topic_description']] = $description;
            }
            if ($schema['topic_order']) {
                $insert[$schema['topic_order']] = $orderIndex;
            }
            if ($schema['topic_created']) {
                $insert[$schema['topic_created']] = date('Y-m-d H:i:s');
            }
            if ($schema['topic_updated']) {
                $insert[$schema['topic_updated']] = date('Y-m-d H:i:s');
            }

            me_maybe_set_id($insert, $schema['topic_cols'], $schema['topic_id']);
            me_build_insert($pdo, $schema['topic_table'], $insert);

            me_json(true, 'Topic başarıyla eklendi.');
            break;
        }

        case 'update_topic': {
            $id = $_POST['id'] ?? '';
            $categoryId = $_POST['category_id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($id === '') {
                me_json(false, 'Topic ID gerekli.', [], 422);
            }
            if ($categoryId === '') {
                me_json(false, 'Kategori seçimi zorunludur.', [], 422);
            }
            if ($name === '') {
                me_json(false, 'Topic adı zorunludur.', [], 422);
            }

            $topicExistsSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['topic_table'])
                . ' WHERE ' . me_quote_col($schema['topic_id']) . ' = ?';
            $topicExistsStmt = $pdo->prepare($topicExistsSql);
            $topicExistsStmt->execute([$id]);
            if ((int)$topicExistsStmt->fetchColumn() === 0) {
                me_json(false, 'Topic bulunamadı.', [], 404);
            }

            $catExistsSql = 'SELECT COUNT(*) FROM ' . me_quote_col($schema['cat_table'])
                . ' WHERE ' . me_quote_col($schema['cat_id']) . ' = ?';
            $catExistsStmt = $pdo->prepare($catExistsSql);
            $catExistsStmt->execute([$categoryId]);
            if ((int)$catExistsStmt->fetchColumn() === 0) {
                me_json(false, 'Seçilen kategori bulunamadı.', [], 422);
            }

            $update = [
                $schema['topic_cat_fk'] => $categoryId,
                $schema['topic_name'] => $name,
            ];

            if ($schema['topic_description']) {
                $update[$schema['topic_description']] = $description;
            }
            if ($schema['topic_order']) {
                $update[$schema['topic_order']] = $orderIndex;
            }
            if ($schema['topic_updated']) {
                $update[$schema['topic_updated']] = date('Y-m-d H:i:s');
            }

            me_build_update($pdo, $schema['topic_table'], $update, $schema['topic_id'], $id);
            me_json(true, 'Topic başarıyla güncellendi.');
            break;
        }

        case 'delete_topic': {
            $id = $_POST['id'] ?? '';
            if ($id === '') {
                me_json(false, 'Topic ID gerekli.', [], 422);
            }

            $delSql = 'DELETE FROM ' . me_quote_col($schema['topic_table'])
                . ' WHERE ' . me_quote_col($schema['topic_id']) . ' = ? LIMIT 1';
            $delStmt = $pdo->prepare($delSql);
            $delStmt->execute([$id]);

            if ($delStmt->rowCount() === 0) {
                me_json(false, 'Topic bulunamadı veya silinemedi.', [], 404);
            }

            me_json(true, 'Topic başarıyla silindi.');
            break;
        }

        default:
            me_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    me_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

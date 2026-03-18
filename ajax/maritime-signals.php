<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_admin();

function ms_json($success, $message = '', $data = [], $status = 200, $errors = [])
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

function ms_table_exists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function ms_columns(PDO $pdo, $table)
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

function ms_pick(array $columns, array $candidates, $required = true)
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

function ms_q($identifier)
{
    return '`' . str_replace('`', '', $identifier) . '`';
}

function ms_schema(PDO $pdo)
{
    $categoryTable = ms_table_exists($pdo, 'maritime_signal_categories') ? 'maritime_signal_categories' : null;
    $signalTable = null;

    foreach (['maritime_signal_items', 'maritime_signals'] as $candidate) {
        if (ms_table_exists($pdo, $candidate)) {
            $signalTable = $candidate;
            break;
        }
    }

    if (!$signalTable) {
        throw new RuntimeException('Signal tablosu bulunamadı (maritime_signal_items / maritime_signals).');
    }

    $signalCols = ms_columns($pdo, $signalTable);
    $categoryCols = $categoryTable ? ms_columns($pdo, $categoryTable) : [];

    return [
        'signal_table' => $signalTable,
        'signal_cols' => $signalCols,
        'signal_id' => ms_pick($signalCols, ['id', 'signal_id', 'uuid']),
        'signal_name' => ms_pick($signalCols, ['name', 'title', 'signal_name', 'label']),
        'signal_code' => ms_pick($signalCols, ['code', 'signal_code', 'symbol', 'short_code'], false),
        'signal_description' => ms_pick($signalCols, ['description', 'meaning', 'content', 'summary', 'text'], false),
        'signal_image' => ms_pick($signalCols, ['image_url', 'image', 'icon_url', 'icon', 'asset_url'], false),
        'signal_order' => ms_pick($signalCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false),
        'signal_created' => ms_pick($signalCols, ['created_at', 'created_on'], false),
        'signal_updated' => ms_pick($signalCols, ['updated_at', 'updated_on'], false),
        'signal_category_fk' => ms_pick($signalCols, ['category_id', 'signal_category_id', 'maritime_signal_category_id'], false),

        'category_table' => $categoryTable,
        'category_cols' => $categoryCols,
        'category_id' => $categoryTable ? ms_pick($categoryCols, ['id', 'category_id', 'uuid']) : null,
        'category_name' => $categoryTable ? ms_pick($categoryCols, ['name', 'title', 'category_name']) : null,
        'category_order' => $categoryTable ? ms_pick($categoryCols, ['order_index', 'sort_order', 'display_order', 'order_no'], false) : null,
    ];
}

function ms_maybe_set_id(array &$data, array $cols, $idCol)
{
    if (!$idCol || !isset($cols[$idCol])) {
        return;
    }

    $extra = strtolower((string)($cols[$idCol]['Extra'] ?? ''));
    if (str_contains($extra, 'auto_increment')) {
        return;
    }

    if (!isset($data[$idCol])) {
        $data[$idCol] = generate_uuid();
    }
}

function ms_insert(PDO $pdo, $table, array $data)
{
    $columns = array_keys($data);
    $quoted = array_map('ms_q', $columns);
    $holders = array_fill(0, count($columns), '?');

    $sql = 'INSERT INTO ' . ms_q($table)
        . ' (' . implode(', ', $quoted) . ')'
        . ' VALUES (' . implode(', ', $holders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
}

function ms_update(PDO $pdo, $table, array $data, $idCol, $id)
{
    if (!$data) {
        return;
    }

    $set = [];
    $vals = [];
    foreach ($data as $col => $val) {
        $set[] = ms_q($col) . ' = ?';
        $vals[] = $val;
    }
    $vals[] = $id;

    $sql = 'UPDATE ' . ms_q($table)
        . ' SET ' . implode(', ', $set)
        . ' WHERE ' . ms_q($idCol) . ' = ? LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $schema = ms_schema($pdo);

    switch ($action) {
        case 'list_categories': {
            $supportsCategory = (bool)($schema['category_table'] && $schema['signal_category_fk']);
            if (!$schema['category_table']) {
                ms_json(true, '', [
                    'categories' => [],
                    'supports_category' => $supportsCategory,
                    'requires_category' => $supportsCategory,
                ]);
            }

            $sql = 'SELECT '
                . 'c.' . ms_q($schema['category_id']) . ' AS id, '
                . 'c.' . ms_q($schema['category_name']) . ' AS name, '
                . ($schema['category_order'] ? 'c.' . ms_q($schema['category_order']) : '0') . ' AS order_index '
                . 'FROM ' . ms_q($schema['category_table']) . ' c '
                . 'ORDER BY '
                . ($schema['category_order'] ? 'c.' . ms_q($schema['category_order']) . ' ASC, ' : '')
                . 'c.' . ms_q($schema['category_name']) . ' ASC';

            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            ms_json(true, '', [
                'categories' => $rows,
                'supports_category' => $supportsCategory,
                'requires_category' => $supportsCategory,
            ]);
            break;
        }

        case 'list_signals': {
            $search = trim((string)($_GET['search'] ?? ''));
            $categoryId = trim((string)($_GET['category_id'] ?? ''));

            $select = [
                's.' . ms_q($schema['signal_id']) . ' AS id',
                's.' . ms_q($schema['signal_name']) . ' AS name',
                ($schema['signal_code'] ? 's.' . ms_q($schema['signal_code']) : "''") . ' AS code',
                ($schema['signal_description'] ? 's.' . ms_q($schema['signal_description']) : "''") . ' AS description',
                ($schema['signal_image'] ? 's.' . ms_q($schema['signal_image']) : "''") . ' AS image_url',
                ($schema['signal_order'] ? 's.' . ms_q($schema['signal_order']) : '0') . ' AS order_index',
                ($schema['signal_category_fk'] ? 's.' . ms_q($schema['signal_category_fk']) : 'NULL') . ' AS category_id',
                ($schema['category_table'] && $schema['signal_category_fk'] ? 'c.' . ms_q($schema['category_name']) : "''") . ' AS category_name',
            ];

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . ms_q($schema['signal_table']) . ' s ';

            if ($schema['category_table'] && $schema['signal_category_fk']) {
                $sql .= ' LEFT JOIN ' . ms_q($schema['category_table']) . ' c '
                    . ' ON c.' . ms_q($schema['category_id']) . ' = s.' . ms_q($schema['signal_category_fk']) . ' ';
            }

            $where = [];
            $params = [];

            if ($search !== '') {
                $parts = [];
                $like = '%' . $search . '%';
                $parts[] = 's.' . ms_q($schema['signal_name']) . ' LIKE ?';
                $params[] = $like;

                if ($schema['signal_code']) {
                    $parts[] = 's.' . ms_q($schema['signal_code']) . ' LIKE ?';
                    $params[] = $like;
                }

                if ($schema['signal_description']) {
                    $parts[] = 's.' . ms_q($schema['signal_description']) . ' LIKE ?';
                    $params[] = $like;
                }

                $where[] = '(' . implode(' OR ', $parts) . ')';
            }

            if ($categoryId !== '' && $schema['signal_category_fk']) {
                $where[] = 's.' . ms_q($schema['signal_category_fk']) . ' = ?';
                $params[] = $categoryId;
            }

            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }

            $sql .= ' ORDER BY '
                . ($schema['signal_order'] ? 's.' . ms_q($schema['signal_order']) . ' ASC, ' : '')
                . 's.' . ms_q($schema['signal_name']) . ' ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ms_json(true, '', ['signals' => $rows]);
            break;
        }

        case 'add_signal': {
            $name = sanitize_input($_POST['name'] ?? '');
            $code = sanitize_input($_POST['code'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $imageUrl = sanitize_input($_POST['image_url'] ?? '');
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($name === '') {
                ms_json(false, 'Signal adı zorunludur.', [], 422, ['name' => 'required']);
            }

            if ($schema['signal_category_fk'] && $schema['category_table'] && $categoryId === '') {
                ms_json(false, 'Kategori seçimi zorunludur.', [], 422, ['category_id' => 'required']);
            }

            if ($schema['signal_category_fk'] && $schema['category_table'] && $categoryId !== '') {
                $catSql = 'SELECT COUNT(*) FROM ' . ms_q($schema['category_table'])
                    . ' WHERE ' . ms_q($schema['category_id']) . ' = ?';
                $catStmt = $pdo->prepare($catSql);
                $catStmt->execute([$categoryId]);
                if ((int)$catStmt->fetchColumn() === 0) {
                    ms_json(false, 'Seçilen kategori bulunamadı.', [], 422);
                }
            }

            $insert = [
                $schema['signal_name'] => $name,
            ];

            if ($schema['signal_code']) {
                $insert[$schema['signal_code']] = $code;
            }
            if ($schema['signal_description']) {
                $insert[$schema['signal_description']] = $description;
            }
            if ($schema['signal_image']) {
                $insert[$schema['signal_image']] = $imageUrl;
            }
            if ($schema['signal_order']) {
                $insert[$schema['signal_order']] = $orderIndex;
            }
            if ($schema['signal_category_fk']) {
                $insert[$schema['signal_category_fk']] = ($categoryId !== '' ? $categoryId : null);
            }
            if ($schema['signal_created']) {
                $insert[$schema['signal_created']] = date('Y-m-d H:i:s');
            }
            if ($schema['signal_updated']) {
                $insert[$schema['signal_updated']] = date('Y-m-d H:i:s');
            }

            ms_maybe_set_id($insert, $schema['signal_cols'], $schema['signal_id']);
            ms_insert($pdo, $schema['signal_table'], $insert);

            ms_json(true, 'Signal başarıyla eklendi.');
            break;
        }

        case 'update_signal': {
            $id = $_POST['id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $code = sanitize_input($_POST['code'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $imageUrl = sanitize_input($_POST['image_url'] ?? '');
            $categoryId = trim((string)($_POST['category_id'] ?? ''));
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($id === '') {
                ms_json(false, 'Signal ID gerekli.', [], 422);
            }
            if ($name === '') {
                ms_json(false, 'Signal adı zorunludur.', [], 422, ['name' => 'required']);
            }

            $existsSql = 'SELECT COUNT(*) FROM ' . ms_q($schema['signal_table'])
                . ' WHERE ' . ms_q($schema['signal_id']) . ' = ?';
            $existsStmt = $pdo->prepare($existsSql);
            $existsStmt->execute([$id]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                ms_json(false, 'Signal bulunamadı.', [], 404);
            }

            if ($schema['signal_category_fk'] && $schema['category_table'] && $categoryId === '') {
                ms_json(false, 'Kategori seçimi zorunludur.', [], 422, ['category_id' => 'required']);
            }

            if ($schema['signal_category_fk'] && $schema['category_table'] && $categoryId !== '') {
                $catSql = 'SELECT COUNT(*) FROM ' . ms_q($schema['category_table'])
                    . ' WHERE ' . ms_q($schema['category_id']) . ' = ?';
                $catStmt = $pdo->prepare($catSql);
                $catStmt->execute([$categoryId]);
                if ((int)$catStmt->fetchColumn() === 0) {
                    ms_json(false, 'Seçilen kategori bulunamadı.', [], 422);
                }
            }

            $update = [
                $schema['signal_name'] => $name,
            ];

            if ($schema['signal_code']) {
                $update[$schema['signal_code']] = $code;
            }
            if ($schema['signal_description']) {
                $update[$schema['signal_description']] = $description;
            }
            if ($schema['signal_image']) {
                $update[$schema['signal_image']] = $imageUrl;
            }
            if ($schema['signal_order']) {
                $update[$schema['signal_order']] = $orderIndex;
            }
            if ($schema['signal_category_fk']) {
                $update[$schema['signal_category_fk']] = ($categoryId !== '' ? $categoryId : null);
            }
            if ($schema['signal_updated']) {
                $update[$schema['signal_updated']] = date('Y-m-d H:i:s');
            }

            ms_update($pdo, $schema['signal_table'], $update, $schema['signal_id'], $id);
            ms_json(true, 'Signal başarıyla güncellendi.');
            break;
        }

        case 'delete_signal': {
            $id = $_POST['id'] ?? '';
            if ($id === '') {
                ms_json(false, 'Signal ID gerekli.', [], 422);
            }

            $sql = 'DELETE FROM ' . ms_q($schema['signal_table'])
                . ' WHERE ' . ms_q($schema['signal_id']) . ' = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                ms_json(false, 'Signal bulunamadı veya silinemedi.', [], 404);
            }

            ms_json(true, 'Signal başarıyla silindi.');
            break;
        }

        default:
            ms_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    ms_json(false, 'İşlem hatası: ' . $e->getMessage(), [], 500);
}

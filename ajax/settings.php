<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$authUser = require_admin();

const SETTINGS_TABLE = 'admin_settings';

function settings_json($success, $message = '', $data = [], $status = 200, $errors = [])
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

function settings_q($identifier)
{
    return '`' . str_replace('`', '', $identifier) . '`';
}

function settings_table_exists(PDO $pdo, $table)
{
    $sql = 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([DB_NAME, $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function settings_columns(PDO $pdo, $table)
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

function settings_pick(array $columns, array $candidates, $required = true)
{
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) return $candidate;
    }
    if ($required) throw new RuntimeException('Gerekli kolon bulunamadı: ' . implode(', ', $candidates));
    return null;
}

function settings_schema(PDO $pdo)
{
    if (!settings_table_exists($pdo, SETTINGS_TABLE)) {
        throw new RuntimeException('admin_settings tablosu bulunamadı.');
    }

    $cols = settings_columns($pdo, SETTINGS_TABLE);

    return [
        'table' => SETTINGS_TABLE,
        'cols' => $cols,

        'id' => settings_pick($cols, ['id', 'uuid'], false),
        'user_id' => settings_pick($cols, ['user_id', 'admin_user_id'], false),

        'ai_provider' => settings_pick($cols, ['ai_provider']),
        'ai_model' => settings_pick($cols, ['ai_model']),
        'api_key' => settings_pick($cols, ['api_key']),
        'max_tokens' => settings_pick($cols, ['max_tokens']),
        'temperature' => settings_pick($cols, ['temperature']),
        'default_question_count' => settings_pick($cols, ['default_question_count']),
        'default_question_type' => settings_pick($cols, ['default_question_type']),
        'created_at' => settings_pick($cols, ['created_at', 'created_on'], false),
        'updated_at' => settings_pick($cols, ['updated_at', 'updated_on'], false),
    ];
}

function settings_set_id_if_needed(array &$data, array $colMeta, $idCol)
{
    if (!$idCol || !isset($colMeta[$idCol])) return;
    $extra = strtolower((string)($colMeta[$idCol]['Extra'] ?? ''));
    if (str_contains($extra, 'auto_increment')) return;
    if (!isset($data[$idCol])) $data[$idCol] = generate_uuid();
}

function settings_insert(PDO $pdo, $table, array $data)
{
    $cols = array_keys($data);
    $quoted = array_map('settings_q', $cols);
    $holders = array_fill(0, count($cols), '?');

    $sql = 'INSERT INTO ' . settings_q($table)
        . ' (' . implode(', ', $quoted) . ')'
        . ' VALUES (' . implode(', ', $holders) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
}

function settings_update(PDO $pdo, $table, array $data, $whereSql, array $whereParams)
{
    if (!$data) return;
    $set = [];
    $vals = [];

    foreach ($data as $col => $val) {
        $set[] = settings_q($col) . ' = ?';
        $vals[] = $val;
    }
    $vals = array_merge($vals, $whereParams);

    $sql = 'UPDATE ' . settings_q($table)
        . ' SET ' . implode(', ', $set)
        . ' WHERE ' . $whereSql;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($vals);
}

function settings_normalize_provider($value)
{
    $v = strtolower(trim((string)$value));
    $allowed = ['claude', 'openai', 'gemini', 'groq'];
    return in_array($v, $allowed, true) ? $v : 'openai';
}

function settings_normalize_type($value)
{
    $v = trim((string)$value);
    $allowed = ['all', 'sayısal', 'sözel'];
    return in_array($v, $allowed, true) ? $v : 'all';
}

function settings_target_row(PDO $pdo, array $schema, $userId)
{
    if ($schema['user_id'] && $userId) {
        $sql = 'SELECT '
            . ($schema['id'] ? settings_q($schema['id']) . ' AS row_id, ' : '')
            . settings_q($schema['user_id']) . ' AS row_user_id '
            . 'FROM ' . settings_q($schema['table'])
            . ' WHERE ' . settings_q($schema['user_id']) . ' = ? '
            . 'LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'exists' => true,
                'where_sql' => settings_q($schema['user_id']) . ' = ?',
                'where_params' => [$userId],
            ];
        }

        return [
            'exists' => false,
            'where_sql' => settings_q($schema['user_id']) . ' = ?',
            'where_params' => [$userId],
        ];
    }

    $selectCols = [];
    if ($schema['id']) $selectCols[] = settings_q($schema['id']) . ' AS row_id';
    if (!$selectCols) $selectCols[] = '1 AS row_id';

    $order = $schema['updated_at']
        ? settings_q($schema['updated_at']) . ' DESC'
        : ($schema['created_at'] ? settings_q($schema['created_at']) . ' DESC' : '1');

    $sql = 'SELECT ' . implode(', ', $selectCols)
        . ' FROM ' . settings_q($schema['table'])
        . ' ORDER BY ' . $order
        . ' LIMIT 1';

    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['exists' => false, 'where_sql' => '', 'where_params' => []];
    }

    if ($schema['id']) {
        return [
            'exists' => true,
            'where_sql' => settings_q($schema['id']) . ' = ?',
            'where_params' => [$row['row_id']],
        ];
    }

    return [
        'exists' => true,
        'where_sql' => '1=1',
        'where_params' => [],
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $schema = settings_schema($pdo);
    $userId = $authUser['user_id'] ?? ($_SESSION['user_id'] ?? null);

    switch ($action) {
        case 'get_settings': {
            $select = [
                settings_q($schema['ai_provider']) . ' AS ai_provider',
                settings_q($schema['ai_model']) . ' AS ai_model',
                settings_q($schema['api_key']) . ' AS api_key',
                settings_q($schema['max_tokens']) . ' AS max_tokens',
                settings_q($schema['temperature']) . ' AS temperature',
                settings_q($schema['default_question_count']) . ' AS default_question_count',
                settings_q($schema['default_question_type']) . ' AS default_question_type',
            ];

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . settings_q($schema['table']);

            $params = [];
            if ($schema['user_id'] && $userId) {
                $sql .= ' WHERE ' . settings_q($schema['user_id']) . ' = ?';
                $params[] = $userId;
            }

            $sql .= ' ORDER BY '
                . ($schema['updated_at'] ? settings_q($schema['updated_at']) . ' DESC' : ($schema['created_at'] ? settings_q($schema['created_at']) . ' DESC' : '1'))
                . ' LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $row = [
                    'ai_provider' => 'openai',
                    'ai_model' => 'gpt-4o',
                    'api_key' => '',
                    'max_tokens' => 2000,
                    'temperature' => 0.7,
                    'default_question_count' => 10,
                    'default_question_type' => 'all',
                ];
            }

            $row['ai_provider'] = settings_normalize_provider($row['ai_provider'] ?? 'openai');
            $row['default_question_type'] = settings_normalize_type($row['default_question_type'] ?? 'all');
            $row['max_tokens'] = max(1, (int)($row['max_tokens'] ?? 2000));
            $row['temperature'] = max(0, min(1, (float)($row['temperature'] ?? 0.7)));
            $row['default_question_count'] = max(1, min(100, (int)($row['default_question_count'] ?? 10)));

            settings_json(true, '', ['settings' => $row]);
            break;
        }

        case 'save_settings': {
            $aiProvider = settings_normalize_provider($_POST['ai_provider'] ?? 'openai');
            $aiModel = sanitize_input($_POST['ai_model'] ?? '');
            $apiKey = trim((string)($_POST['api_key'] ?? ''));
            $maxTokens = (int)($_POST['max_tokens'] ?? 0);
            $temperature = (float)($_POST['temperature'] ?? -1);
            $questionCount = (int)($_POST['default_question_count'] ?? 0);
            $questionType = settings_normalize_type($_POST['default_question_type'] ?? 'all');
            if ($aiModel === '') {
                settings_json(false, 'Model seçimi zorunludur.', [], 422, ['ai_model' => 'required']);
            }
            if ($maxTokens < 1) {
                settings_json(false, 'Max Tokens en az 1 olmalıdır.', [], 422, ['max_tokens' => 'invalid']);
            }
            if ($temperature < 0 || $temperature > 1) {
                settings_json(false, 'Temperature 0 ile 1 arasında olmalıdır.', [], 422, ['temperature' => 'invalid']);
            }
            if ($questionCount < 1 || $questionCount > 100) {
                settings_json(false, 'Varsayılan soru adedi 1-100 arasında olmalıdır.', [], 422, ['default_question_count' => 'invalid']);
            }

            $target = settings_target_row($pdo, $schema, $userId);

            $payload = [
                $schema['ai_provider'] => $aiProvider,
                $schema['ai_model'] => $aiModel,
                $schema['api_key'] => $apiKey,
                $schema['max_tokens'] => $maxTokens,
                $schema['temperature'] => $temperature,
                $schema['default_question_count'] => $questionCount,
                $schema['default_question_type'] => $questionType,
            ];

            if ($schema['updated_at']) {
                $payload[$schema['updated_at']] = date('Y-m-d H:i:s');
            }

            if ($target['exists']) {
                settings_update($pdo, $schema['table'], $payload, $target['where_sql'], $target['where_params']);
            } else {
                if ($schema['id']) settings_set_id_if_needed($payload, $schema['cols'], $schema['id']);
                if ($schema['user_id'] && $userId) $payload[$schema['user_id']] = $userId;
                if ($schema['created_at']) $payload[$schema['created_at']] = date('Y-m-d H:i:s');
                settings_insert($pdo, $schema['table'], $payload);
            }

            settings_json(true, 'Ayarlar başarıyla kaydedildi.');
            break;
        }

        default:
            settings_json(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    settings_json(false, 'İşlem hatası: ' . $e->getMessage(), [], 500);
}

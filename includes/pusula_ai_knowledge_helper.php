<?php

if (!defined('PUSULA_AI_KNOWLEDGE_BASE_TABLE')) {
    define('PUSULA_AI_KNOWLEDGE_BASE_TABLE', 'pusula_ai_knowledge_base');
}

if (!defined('PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE')) {
    define('PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE', 'pusula_ai_example_conversations');
}

if (!defined('PUSULA_AI_TOOL_SETTINGS_TABLE')) {
    define('PUSULA_AI_TOOL_SETTINGS_TABLE', 'pusula_ai_tool_settings');
}

function pusula_ai_knowledge_defaults(): array
{
    return [
        'app_name' => 'Denizci Eğitim',
        'assistant_name' => 'Pusula Ai',
        'app_summary' => '',
        'target_users' => '',
        'tone_of_voice' => '',

        'app_features_text' => '',
        'premium_features_text' => '',
        'offline_features_text' => '',
        'community_features_text' => '',
        'exam_features_text' => '',

        'allowed_topics_text' => '',
        'blocked_topics_text' => '',
        'response_style_text' => '',
        'emotional_style_text' => '',
        'short_reply_rules_text' => '',
        'long_reply_rules_text' => '',

        'system_prompt_base' => '',
        'system_prompt_behavior' => '',
        'system_prompt_app_knowledge' => '',
        'system_prompt_stats_behavior' => '',
        'system_prompt_exam_behavior' => '',

        'action_button_intro_text' => '',
        'action_exam_enabled' => 1,
        'action_plan_enabled' => 1,
        'action_exam_default_question_count' => 10,
        'action_exam_default_mode' => 'mini',
    ];
}

function pusula_ai_tool_settings_defaults(): array
{
    return [
        'tool_stats_enabled' => 1,
        'tool_exam_recommendation_enabled' => 1,
        'tool_app_info_enabled' => 1,
        'tool_action_payload_enabled' => 1,
        'tool_weak_topics_enabled' => 1,
        'tool_last_exam_enabled' => 1,
    ];
}

function pusula_ai_knowledge_sections(): array
{
    return [
        'general' => [
            'app_name',
            'assistant_name',
            'app_summary',
            'target_users',
            'tone_of_voice',
        ],
        'features' => [
            'app_features_text',
            'premium_features_text',
            'offline_features_text',
            'community_features_text',
            'exam_features_text',
        ],
        'rules' => [
            'allowed_topics_text',
            'blocked_topics_text',
            'response_style_text',
            'emotional_style_text',
            'short_reply_rules_text',
            'long_reply_rules_text',
        ],
        'prompts' => [
            'system_prompt_base',
            'system_prompt_behavior',
            'system_prompt_app_knowledge',
            'system_prompt_stats_behavior',
            'system_prompt_exam_behavior',
        ],
        'actions' => [
            'action_button_intro_text',
            'action_exam_enabled',
            'action_plan_enabled',
            'action_exam_default_question_count',
            'action_exam_default_mode',
        ],
    ];
}

function pusula_ai_knowledge_bool_fields(): array
{
    return [
        'action_exam_enabled',
        'action_plan_enabled',
        'tool_stats_enabled',
        'tool_exam_recommendation_enabled',
        'tool_app_info_enabled',
        'tool_action_payload_enabled',
        'tool_weak_topics_enabled',
        'tool_last_exam_enabled',
        'is_active',
    ];
}

function pusula_ai_knowledge_text_fields(): array
{
    $all = pusula_ai_knowledge_defaults();
    return array_values(array_filter(array_keys($all), static function ($k) {
        return !in_array($k, ['action_exam_enabled', 'action_plan_enabled', 'action_exam_default_question_count', 'action_exam_default_mode'], true);
    }));
}

function pusula_ai_knowledge_to_bool_int($value): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    $str = strtolower(trim((string)$value));
    return in_array($str, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function pusula_ai_knowledge_trim_text($value, int $maxLen = 12000): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLen, 'UTF-8');
    }

    return substr($text, 0, $maxLen);
}

function pusula_ai_knowledge_generate_id(): string
{
    if (function_exists('generate_uuid')) {
        $uuid = trim((string)generate_uuid());
        if ($uuid !== '') {
            return $uuid;
        }
    }

    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
}

function pusula_ai_knowledge_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = $row;
            }
        }
    } catch (Throwable $e) {
        $columns = [];
    }

    $cache[$table] = $columns;
    return $columns;
}

function pusula_ai_knowledge_normalize_base(array $data): array
{
    $defaults = pusula_ai_knowledge_defaults();
    $merged = array_merge($defaults, $data);

    foreach (pusula_ai_knowledge_text_fields() as $field) {
        if (array_key_exists($field, $merged)) {
            $merged[$field] = pusula_ai_knowledge_trim_text($merged[$field], 12000);
        }
    }

    $merged['action_exam_enabled'] = pusula_ai_knowledge_to_bool_int($merged['action_exam_enabled'] ?? 1);
    $merged['action_plan_enabled'] = pusula_ai_knowledge_to_bool_int($merged['action_plan_enabled'] ?? 1);

    $count = (int)($merged['action_exam_default_question_count'] ?? 10);
    $merged['action_exam_default_question_count'] = max(1, min(100, $count));

    $mode = strtolower(pusula_ai_knowledge_trim_text($merged['action_exam_default_mode'] ?? 'mini', 40));
    $allowedModes = ['mini', 'standard', 'classic', 'mixed'];
    $merged['action_exam_default_mode'] = in_array($mode, $allowedModes, true) ? $mode : 'mini';

    return $merged;
}

function pusula_ai_get_knowledge(PDO $pdo): array
{
    $defaults = pusula_ai_knowledge_defaults();
    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_KNOWLEDGE_BASE_TABLE);

    if (empty($columns)) {
        return pusula_ai_knowledge_normalize_base($defaults);
    }

    $selectFields = array_values(array_filter(array_keys($defaults), static fn($k) => isset($columns[$k])));
    if (empty($selectFields)) {
        return pusula_ai_knowledge_normalize_base($defaults);
    }

    try {
        $sql = 'SELECT ' . implode(', ', array_map(static fn($f) => '`' . $f . '`', $selectFields))
            . ' FROM `' . PUSULA_AI_KNOWLEDGE_BASE_TABLE . '` LIMIT 1';
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    return pusula_ai_knowledge_normalize_base(array_merge($defaults, $row));
}

function pusula_ai_save_knowledge_section(PDO $pdo, string $section, array $payload): void
{
    $section = strtolower(trim($section));
    $sections = pusula_ai_knowledge_sections();
    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Geçersiz bilgi bankası bölümü.');
    }

    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_KNOWLEDGE_BASE_TABLE);
    if (empty($columns)) {
        return;
    }

    $current = pusula_ai_get_knowledge($pdo);
    foreach ($sections[$section] as $field) {
        if (!array_key_exists($field, $payload)) {
            continue;
        }
        $current[$field] = $payload[$field];
    }

    $normalized = pusula_ai_knowledge_normalize_base($current);
    $writeFields = [];
    foreach ($sections[$section] as $field) {
        if (isset($columns[$field])) {
            $writeFields[$field] = $normalized[$field];
        }
    }

    if (empty($writeFields)) {
        return;
    }

    $hasUpdatedAt = isset($columns['updated_at']);
    $hasCreatedAt = isset($columns['created_at']);
    $hasId = isset($columns['id']);

    if ($hasUpdatedAt) {
        $writeFields['updated_at'] = date('Y-m-d H:i:s');
    }

    try {
        $row = $pdo->query('SELECT ' . ($hasId ? '`id`' : '1 AS `row_exists`') . ' FROM `' . PUSULA_AI_KNOWLEDGE_BASE_TABLE . '` LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }

    if ($row) {
        $set = [];
        $params = [];
        foreach ($writeFields as $field => $value) {
            $set[] = '`' . $field . '` = :' . $field;
            $params[':' . $field] = $value;
        }

        $sql = 'UPDATE `' . PUSULA_AI_KNOWLEDGE_BASE_TABLE . '` SET ' . implode(', ', $set);
        if ($hasId && !empty($row['id'])) {
            $sql .= ' WHERE `id` = :id';
            $params[':id'] = (string)$row['id'];
        } else {
            $sql .= ' LIMIT 1';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return;
    }

    if ($hasCreatedAt) {
        $writeFields['created_at'] = date('Y-m-d H:i:s');
    }
    if ($hasId) {
        $writeFields['id'] = pusula_ai_knowledge_generate_id();
    }

    $insertCols = array_keys($writeFields);
    $sql = 'INSERT INTO `' . PUSULA_AI_KNOWLEDGE_BASE_TABLE . '` (`' . implode('`, `', $insertCols) . '`)' 
        . ' VALUES (:' . implode(', :', $insertCols) . ')';

    $params = [];
    foreach ($writeFields as $col => $value) {
        $params[':' . $col] = $value;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function pusula_ai_list_example_conversations(PDO $pdo): array
{
    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE);
    if (empty($columns)) {
        return [];
    }

    $required = ['id', 'user_message', 'assistant_reply'];
    foreach ($required as $field) {
        if (!isset($columns[$field])) {
            return [];
        }
    }

    $selectFields = ['id', 'user_message', 'assistant_reply'];
    foreach (['conversation_tag', 'is_active', 'order_index', 'created_at', 'updated_at'] as $optional) {
        if (isset($columns[$optional])) {
            $selectFields[] = $optional;
        }
    }

    $orderBy = isset($columns['order_index'])
        ? '`order_index` ASC, `id` ASC'
        : (isset($columns['created_at']) ? '`created_at` ASC' : '`id` ASC');

    try {
        $sql = 'SELECT ' . implode(', ', array_map(static fn($f) => '`' . $f . '`', $selectFields))
            . ' FROM `' . PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE . '` ORDER BY ' . $orderBy;
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'id' => (string)($row['id'] ?? ''),
            'user_message' => pusula_ai_knowledge_trim_text($row['user_message'] ?? '', 2000),
            'assistant_reply' => pusula_ai_knowledge_trim_text($row['assistant_reply'] ?? '', 4000),
            'conversation_tag' => pusula_ai_knowledge_trim_text($row['conversation_tag'] ?? '', 80),
            'is_active' => pusula_ai_knowledge_to_bool_int($row['is_active'] ?? 1),
            'order_index' => max(0, (int)($row['order_index'] ?? 0)),
        ];
    }

    return $items;
}

function pusula_ai_save_example_conversation(PDO $pdo, array $payload): array
{
    $item = [
        'id' => trim((string)($payload['id'] ?? '')),
        'user_message' => pusula_ai_knowledge_trim_text($payload['user_message'] ?? '', 2000),
        'assistant_reply' => pusula_ai_knowledge_trim_text($payload['assistant_reply'] ?? '', 4000),
        'conversation_tag' => strtolower(pusula_ai_knowledge_trim_text($payload['conversation_tag'] ?? '', 80)),
        'is_active' => pusula_ai_knowledge_to_bool_int($payload['is_active'] ?? 1),
        'order_index' => max(0, min(100000, (int)($payload['order_index'] ?? 0))),
    ];

    if ($item['user_message'] === '' || $item['assistant_reply'] === '') {
        throw new InvalidArgumentException('Kullanıcı mesajı ve asistan cevabı zorunludur.');
    }

    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE);
    if (empty($columns)) {
        if ($item['id'] === '') {
            $item['id'] = pusula_ai_knowledge_generate_id();
        }
        return $item;
    }

    $hasId = isset($columns['id']);
    $hasCreatedAt = isset($columns['created_at']);
    $hasUpdatedAt = isset($columns['updated_at']);

    $saveFields = [];
    foreach (['user_message', 'assistant_reply', 'conversation_tag', 'is_active', 'order_index'] as $field) {
        if (isset($columns[$field])) {
            $saveFields[$field] = $item[$field];
        }
    }
    if ($hasUpdatedAt) {
        $saveFields['updated_at'] = date('Y-m-d H:i:s');
    }

    if ($item['id'] !== '' && $hasId) {
        $set = [];
        $params = [':id' => $item['id']];
        foreach ($saveFields as $field => $value) {
            $set[] = '`' . $field . '` = :' . $field;
            $params[':' . $field] = $value;
        }

        if (!empty($set)) {
            $sql = 'UPDATE `' . PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE . '` SET ' . implode(', ', $set) . ' WHERE `id` = :id LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                return $item;
            }
        }
    }

    if ($item['id'] === '') {
        $item['id'] = pusula_ai_knowledge_generate_id();
    }

    $insertFields = $saveFields;
    if ($hasCreatedAt) {
        $insertFields['created_at'] = date('Y-m-d H:i:s');
    }
    if ($hasId) {
        $insertFields['id'] = $item['id'];
    }

    if (!empty($insertFields)) {
        $cols = array_keys($insertFields);
        $sql = 'INSERT INTO `' . PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE . '` (`' . implode('`, `', $cols) . '`)' 
            . ' VALUES (:' . implode(', :', $cols) . ')';
        $params = [];
        foreach ($insertFields as $col => $value) {
            $params[':' . $col] = $value;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    return $item;
}

function pusula_ai_delete_example_conversation(PDO $pdo, string $id): void
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Geçersiz örnek konuşma kimliği.');
    }

    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE);
    if (empty($columns) || !isset($columns['id'])) {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM `' . PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE . '` WHERE `id` = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
}

function pusula_ai_reorder_example_conversations(PDO $pdo, array $items): void
{
    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE);
    if (empty($columns) || !isset($columns['id']) || !isset($columns['order_index'])) {
        return;
    }

    $hasUpdatedAt = isset($columns['updated_at']);
    $sql = 'UPDATE `' . PUSULA_AI_EXAMPLE_CONVERSATIONS_TABLE . '` SET `order_index` = :order_index'
        . ($hasUpdatedAt ? ', `updated_at` = :updated_at' : '')
        . ' WHERE `id` = :id LIMIT 1';
    $stmt = $pdo->prepare($sql);

    foreach ($items as $row) {
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }

        $params = [
            ':id' => $id,
            ':order_index' => max(0, min(100000, (int)($row['order_index'] ?? 0))),
        ];
        if ($hasUpdatedAt) {
            $params[':updated_at'] = date('Y-m-d H:i:s');
        }

        $stmt->execute($params);
    }
}

function pusula_ai_get_tool_settings(PDO $pdo): array
{
    $defaults = pusula_ai_tool_settings_defaults();
    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_TOOL_SETTINGS_TABLE);

    if (empty($columns)) {
        return $defaults;
    }

    $selectFields = array_values(array_filter(array_keys($defaults), static fn($k) => isset($columns[$k])));
    if (empty($selectFields)) {
        return $defaults;
    }

    try {
        $sql = 'SELECT ' . implode(', ', array_map(static fn($f) => '`' . $f . '`', $selectFields))
            . ' FROM `' . PUSULA_AI_TOOL_SETTINGS_TABLE . '` LIMIT 1';
        $stmt = $pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $row = [];
    }

    $settings = array_merge($defaults, $row);
    foreach (array_keys($defaults) as $field) {
        $settings[$field] = pusula_ai_knowledge_to_bool_int($settings[$field] ?? 0);
    }

    return $settings;
}

function pusula_ai_save_tool_settings(PDO $pdo, array $payload): void
{
    $defaults = pusula_ai_tool_settings_defaults();
    $columns = pusula_ai_knowledge_table_columns($pdo, PUSULA_AI_TOOL_SETTINGS_TABLE);

    if (empty($columns)) {
        return;
    }

    $current = pusula_ai_get_tool_settings($pdo);
    foreach (array_keys($defaults) as $field) {
        if (array_key_exists($field, $payload)) {
            $current[$field] = pusula_ai_knowledge_to_bool_int($payload[$field]);
        }
    }

    $writeFields = [];
    foreach (array_keys($defaults) as $field) {
        if (isset($columns[$field])) {
            $writeFields[$field] = pusula_ai_knowledge_to_bool_int($current[$field] ?? 0);
        }
    }

    if (empty($writeFields)) {
        return;
    }

    $hasId = isset($columns['id']);
    $hasCreatedAt = isset($columns['created_at']);
    $hasUpdatedAt = isset($columns['updated_at']);
    if ($hasUpdatedAt) {
        $writeFields['updated_at'] = date('Y-m-d H:i:s');
    }

    try {
        $row = $pdo->query('SELECT ' . ($hasId ? '`id`' : '1 AS `row_exists`') . ' FROM `' . PUSULA_AI_TOOL_SETTINGS_TABLE . '` LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $row = false;
    }

    if ($row) {
        $set = [];
        $params = [];
        foreach ($writeFields as $field => $value) {
            $set[] = '`' . $field . '` = :' . $field;
            $params[':' . $field] = $value;
        }

        $sql = 'UPDATE `' . PUSULA_AI_TOOL_SETTINGS_TABLE . '` SET ' . implode(', ', $set);
        if ($hasId && !empty($row['id'])) {
            $sql .= ' WHERE `id` = :id';
            $params[':id'] = (string)$row['id'];
        } else {
            $sql .= ' LIMIT 1';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return;
    }

    if ($hasCreatedAt) {
        $writeFields['created_at'] = date('Y-m-d H:i:s');
    }
    if ($hasId) {
        $writeFields['id'] = pusula_ai_knowledge_generate_id();
    }

    $cols = array_keys($writeFields);
    $sql = 'INSERT INTO `' . PUSULA_AI_TOOL_SETTINGS_TABLE . '` (`' . implode('`, `', $cols) . '`)' 
        . ' VALUES (:' . implode(', :', $cols) . ')';
    $params = [];
    foreach ($writeFields as $col => $value) {
        $params[':' . $col] = $value;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

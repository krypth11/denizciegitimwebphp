<?php

function app_runtime_settings_defaults(): array
{
    return [
        'dashboard_daily_goal_questions' => 30,
        'free_daily_study_question_limit' => 60,
        'free_daily_mock_exam_limit' => 3,
        'study_all_questions_max_limit' => 100,
        'mock_exam_question_count' => 20,
    ];
}

function app_runtime_settings_rules(): array
{
    return [
        'dashboard_daily_goal_questions' => ['min' => 1, 'max' => 500],
        'free_daily_study_question_limit' => ['min' => 1, 'max' => 1000],
        'free_daily_mock_exam_limit' => ['min' => 0, 'max' => 100],
        'study_all_questions_max_limit' => ['min' => 1, 'max' => 2000],
        'mock_exam_question_count' => ['min' => 1, 'max' => 200],
    ];
}

function app_runtime_settings_allowed_keys(): array
{
    return array_keys(app_runtime_settings_rules());
}

function app_runtime_settings_table_columns(PDO $pdo): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $cols = get_table_columns($pdo, 'app_runtime_settings');
        if (!is_array($cols) || !$cols) {
            $cache[$cacheKey] = [];
            return [];
        }

        $cache[$cacheKey] = array_values(array_unique(array_map(static fn($c): string => (string)$c, $cols)));
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = [];
        return [];
    }
}

function app_runtime_settings_q(string $column): string
{
    return '`' . str_replace('`', '', $column) . '`';
}

function app_runtime_settings_validate_value(string $key, $value): int
{
    $defaults = app_runtime_settings_defaults();
    $rules = app_runtime_settings_rules();
    if (!isset($rules[$key])) {
        throw new InvalidArgumentException('Geçersiz ayar anahtarı: ' . $key);
    }

    $rule = $rules[$key];
    $fallback = (int)($defaults[$key] ?? 0);

    if (is_bool($value) || $value === null || !is_numeric($value)) {
        return $fallback;
    }

    $intVal = (int)$value;
    if ((string)$intVal !== (string)(int)$intVal) {
        $intVal = (int)floor((float)$value);
    }

    if ($intVal < (int)$rule['min']) {
        $intVal = (int)$rule['min'];
    }
    if ($intVal > (int)$rule['max']) {
        $intVal = (int)$rule['max'];
    }

    return $intVal;
}

function app_runtime_settings_normalize(array $settings): array
{
    $defaults = app_runtime_settings_defaults();
    $out = [];
    foreach (app_runtime_settings_allowed_keys() as $key) {
        $value = array_key_exists($key, $settings) ? $settings[$key] : $defaults[$key];
        $out[$key] = app_runtime_settings_validate_value($key, $value);
    }
    return $out;
}

function app_runtime_settings_try_insert_defaults(PDO $pdo, array $columns): bool
{
    if (!$columns) {
        return false;
    }

    $defaults = app_runtime_settings_defaults();
    $insertCols = [];
    $insertVals = [];
    $params = [];

    if (in_array('id', $columns, true)) {
        $insertCols[] = app_runtime_settings_q('id');
        $insertVals[] = '?';
        $params[] = 1;
    }

    foreach (app_runtime_settings_allowed_keys() as $key) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $insertCols[] = app_runtime_settings_q($key);
        $insertVals[] = '?';
        $params[] = (int)$defaults[$key];
    }

    if (in_array('created_at', $columns, true)) {
        $insertCols[] = app_runtime_settings_q('created_at');
        $insertVals[] = 'NOW()';
    }
    if (in_array('updated_at', $columns, true)) {
        $insertCols[] = app_runtime_settings_q('updated_at');
        $insertVals[] = 'NOW()';
    }

    if (!$insertCols) {
        return false;
    }

    try {
        $sql = 'INSERT INTO `app_runtime_settings` (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function app_runtime_settings_fetch_row(PDO $pdo, array $columns): ?array
{
    if (!$columns) {
        return null;
    }

    $select = [];
    foreach (app_runtime_settings_allowed_keys() as $key) {
        if (in_array($key, $columns, true)) {
            $select[] = app_runtime_settings_q($key) . ' AS ' . app_runtime_settings_q($key);
        }
    }

    if (!$select) {
        return null;
    }

    try {
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM `app_runtime_settings`';
        if (in_array('id', $columns, true)) {
            $sql .= ' WHERE `id` = 1';
        }
        $sql .= ' LIMIT 1';

        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function app_runtime_settings_get(PDO $pdo): array
{
    $defaults = app_runtime_settings_defaults();
    $columns = app_runtime_settings_table_columns($pdo);
    if (!$columns) {
        return $defaults;
    }

    $row = app_runtime_settings_fetch_row($pdo, $columns);
    if (!$row) {
        app_runtime_settings_try_insert_defaults($pdo, $columns);
        $row = app_runtime_settings_fetch_row($pdo, $columns);
    }

    if (!$row) {
        return $defaults;
    }

    return app_runtime_settings_normalize($row);
}

function app_runtime_settings_update(PDO $pdo, array $input): array
{
    $columns = app_runtime_settings_table_columns($pdo);
    if (!$columns) {
        return app_runtime_settings_defaults();
    }

    $current = app_runtime_settings_get($pdo);
    $next = $current;

    foreach (app_runtime_settings_allowed_keys() as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $next[$key] = app_runtime_settings_validate_value($key, $input[$key]);
    }

    $set = [];
    $params = [];
    foreach (app_runtime_settings_allowed_keys() as $key) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $set[] = app_runtime_settings_q($key) . ' = ?';
        $params[] = (int)$next[$key];
    }

    if (in_array('updated_at', $columns, true)) {
        $set[] = app_runtime_settings_q('updated_at') . ' = NOW()';
    }

    if ($set) {
        try {
            $sql = 'UPDATE `app_runtime_settings` SET ' . implode(', ', $set);
            if (in_array('id', $columns, true)) {
                $sql .= ' WHERE `id` = 1';
            }
            $sql .= ' LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() < 1) {
                app_runtime_settings_try_insert_defaults($pdo, $columns);

                $setFallback = [];
                $fallbackParams = [];
                foreach (app_runtime_settings_allowed_keys() as $key) {
                    if (!in_array($key, $columns, true)) {
                        continue;
                    }
                    $setFallback[] = app_runtime_settings_q($key) . ' = ?';
                    $fallbackParams[] = (int)$next[$key];
                }
                if (in_array('updated_at', $columns, true)) {
                    $setFallback[] = app_runtime_settings_q('updated_at') . ' = NOW()';
                }

                if ($setFallback) {
                    $sqlFallback = 'UPDATE `app_runtime_settings` SET ' . implode(', ', $setFallback);
                    if (in_array('id', $columns, true)) {
                        $sqlFallback .= ' WHERE `id` = 1';
                    }
                    $sqlFallback .= ' LIMIT 1';
                    $stmtFallback = $pdo->prepare($sqlFallback);
                    $stmtFallback->execute($fallbackParams);
                }
            }
        } catch (Throwable $e) {
            return app_runtime_settings_get($pdo);
        }
    }

    return app_runtime_settings_get($pdo);
}

function app_runtime_settings_int(array $settings, string $key, int $fallback): int
{
    $rules = app_runtime_settings_rules();
    if (!isset($rules[$key])) {
        return $fallback;
    }

    $value = array_key_exists($key, $settings) ? $settings[$key] : $fallback;
    if (!is_numeric($value)) {
        $value = $fallback;
    }

    return app_runtime_settings_validate_value($key, $value);
}

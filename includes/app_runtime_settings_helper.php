<?php

function app_runtime_settings_log(string $message, array $context = []): void
{
    $prefix = '[app_runtime_settings] ';
    if (!empty($context)) {
        error_log($prefix . $message . ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        return;
    }

    error_log($prefix . $message);
}

function app_runtime_settings_defaults(): array
{
    return [
        'dashboard_daily_goal_questions' => 30,
        'free_daily_study_question_limit' => 60,
        'free_daily_mock_exam_limit' => 3,
        'study_all_questions_max_limit' => 1000,
        'mock_exam_question_count' => 20,
        'study_auto_advance_delay_ms' => 500,
        'rewarded_study_bonus' => 10,
        'rewarded_mock_exam_bonus' => 1,
        'rewarded_study_daily_ad_limit' => 3,
        'rewarded_mock_exam_daily_ad_limit' => 1,
        'kart_game_daily_attempt_limit' => 5,
        'kart_game_practice_daily_limit' => 20,
        'kart_game_ranked_free_plays' => 1,
        'kart_game_ranked_rewarded_plays' => 4,
        'question_new_badge_days' => 240,
        'question_source_scenario_label' => 'Senaryo Tipi',
        'question_source_gasm_label' => 'GASM Tipi',
    ];
}

function app_runtime_settings_text_keys(): array
{
    return [
        'question_source_scenario_label',
        'question_source_gasm_label',
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
        'study_auto_advance_delay_ms' => ['min' => 100, 'max' => 5000],
        'rewarded_study_bonus' => ['min' => 1, 'max' => 100],
        'rewarded_mock_exam_bonus' => ['min' => 1, 'max' => 10],
        'rewarded_study_daily_ad_limit' => ['min' => 0, 'max' => 20],
        'rewarded_mock_exam_daily_ad_limit' => ['min' => 0, 'max' => 10],
        'kart_game_daily_attempt_limit' => ['min' => 0, 'max' => 100],
        'kart_game_practice_daily_limit' => ['min' => 0, 'max' => 999],
        'kart_game_ranked_free_plays' => ['min' => 0, 'max' => 999],
        'kart_game_ranked_rewarded_plays' => ['min' => 0, 'max' => 999],
        'question_new_badge_days' => ['min' => 0, 'max' => 3650],
    ];
}

function is_question_new_by_created_at(?string $createdAt, int $days): bool
{
    if ($days <= 0) {
        return false;
    }

    $raw = trim((string)($createdAt ?? ''));
    if ($raw === '') {
        return false;
    }

    try {
        $created = new DateTimeImmutable($raw);
        $threshold = (new DateTimeImmutable('now'))->sub(new DateInterval('P' . $days . 'D'));
        return $created >= $threshold;
    } catch (Throwable $e) {
        return false;
    }
}

function get_runtime_settings_row() {
    global $db;

    if ($db instanceof mysqli) {
        $result = $db->query("SELECT * FROM app_runtime_settings WHERE id = 1");
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            if (is_array($row)) {
                return $row;
            }
        }
    }

    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $stmt = $GLOBALS['pdo']->query('SELECT * FROM `app_runtime_settings` WHERE `id` = 1 LIMIT 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (is_array($row)) {
            return $row;
        }
    }

    return app_runtime_settings_defaults();
}

function get_rewarded_study_bonus() {
    $row = get_runtime_settings_row();
    return (int) ($row['rewarded_study_bonus'] ?? 10);
}

function get_rewarded_mock_exam_bonus() {
    $row = get_runtime_settings_row();
    return (int) ($row['rewarded_mock_exam_bonus'] ?? 1);
}

function get_rewarded_study_daily_ad_limit() {
    $row = get_runtime_settings_row();
    return (int) ($row['rewarded_study_daily_ad_limit'] ?? 3);
}

function get_rewarded_mock_exam_daily_ad_limit() {
    $row = get_runtime_settings_row();
    return (int) ($row['rewarded_mock_exam_daily_ad_limit'] ?? 1);
}

function app_runtime_settings_allowed_keys(): array
{
    return array_merge(array_keys(app_runtime_settings_rules()), app_runtime_settings_text_keys());
}

function app_runtime_settings_numeric_keys(): array
{
    return array_keys(app_runtime_settings_rules());
}

function app_runtime_settings_validate_text_value(string $key, $value): string
{
    $defaults = app_runtime_settings_defaults();
    if (!in_array($key, app_runtime_settings_text_keys(), true)) {
        throw new InvalidArgumentException('Geçersiz text ayar anahtarı: ' . $key);
    }

    $normalized = trim((string)$value);
    if ($normalized === '') {
        $normalized = (string)($defaults[$key] ?? '');
    }
    if ($normalized === '') {
        throw new InvalidArgumentException($key . ' boş olamaz.');
    }

    if (mb_strlen($normalized, 'UTF-8') > 100) {
        $normalized = mb_substr($normalized, 0, 100, 'UTF-8');
    }

    return $normalized;
}

function app_runtime_settings_table_columns(PDO $pdo): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $cols = [];

    try {
        if (function_exists('get_table_columns')) {
            $cols = get_table_columns($pdo, 'app_runtime_settings');
        }
    } catch (Throwable $e) {
        app_runtime_settings_log('get_table_columns() ile kolonlar okunamadı, lokal keşif denenecek.', [
            'error' => $e->getMessage(),
        ]);
        $cols = [];
    }

    if (!is_array($cols) || !$cols) {
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM `app_runtime_settings`');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (!empty($row['Field'])) {
                    $cols[] = (string)$row['Field'];
                }
            }
        } catch (Throwable $e) {
            app_runtime_settings_log('app_runtime_settings tablo kolonları okunamadı.', [
                'error' => $e->getMessage(),
            ]);
            $cache[$cacheKey] = [];
            return [];
        }
    }

    $cache[$cacheKey] = array_values(array_unique(array_map(static fn($c): string => (string)$c, $cols)));
    if (!$cache[$cacheKey]) {
        app_runtime_settings_log('app_runtime_settings kolon listesi boş döndü.');
    }

    return $cache[$cacheKey];
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
    foreach (app_runtime_settings_numeric_keys() as $key) {
        $value = array_key_exists($key, $settings) ? $settings[$key] : $defaults[$key];
        $out[$key] = app_runtime_settings_validate_value($key, $value);
    }
    foreach (app_runtime_settings_text_keys() as $key) {
        $value = array_key_exists($key, $settings) ? $settings[$key] : ($defaults[$key] ?? '');
        $out[$key] = app_runtime_settings_validate_text_value($key, $value);
    }
    return $out;
}

function app_runtime_settings_try_insert_defaults(PDO $pdo, array $columns): bool
{
    if (!$columns) {
        app_runtime_settings_log('Varsayılan ayarlar eklenemedi: kolon listesi boş.');
        return false;
    }

    if (!in_array('id', $columns, true)) {
        app_runtime_settings_log('Varsayılan ayarlar eklenemedi: id kolonu bulunamadı.');
        return false;
    }

    $defaults = app_runtime_settings_defaults();
    $insertCols = [];
    $insertVals = [];
    $params = [];

    $insertCols[] = app_runtime_settings_q('id');
    $insertVals[] = '?';
    $params[] = 1;

    foreach (app_runtime_settings_numeric_keys() as $key) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $insertCols[] = app_runtime_settings_q($key);
        $insertVals[] = '?';
        $params[] = (int)$defaults[$key];
    }
    foreach (app_runtime_settings_text_keys() as $key) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $insertCols[] = app_runtime_settings_q($key);
        $insertVals[] = '?';
        $params[] = app_runtime_settings_validate_text_value($key, $defaults[$key] ?? '');
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
        app_runtime_settings_log('Varsayılan ayarlar eklenemedi.', [
            'error' => $e->getMessage(),
        ]);
        return false;
    }
}

function app_runtime_settings_fetch_row(PDO $pdo, array $columns): ?array
{
    if (!$columns) {
        app_runtime_settings_log('Ayar satırı okunamadı: kolon listesi boş.');
        return null;
    }

    if (!in_array('id', $columns, true)) {
        app_runtime_settings_log('Ayar satırı okunamadı: id kolonu bulunamadı.');
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
        $sql .= ' WHERE `id` = 1';
        $sql .= ' LIMIT 1';

        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        app_runtime_settings_log('Ayar satırı sorgusunda DB hatası.', [
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}

function app_runtime_settings_get(PDO $pdo): array
{
    $defaults = app_runtime_settings_defaults();
    $columns = app_runtime_settings_table_columns($pdo);
    if (!$columns) {
        app_runtime_settings_log('Varsayılan fallback: tablo/kolonlar okunamadı.');
        return $defaults;
    }

    $row = app_runtime_settings_fetch_row($pdo, $columns);
    if (!$row) {
        app_runtime_settings_try_insert_defaults($pdo, $columns);
        $row = app_runtime_settings_fetch_row($pdo, $columns);
    }

    if (!$row) {
        app_runtime_settings_log('Varsayılan fallback: id=1 ayar satırı bulunamadı.');
        return $defaults;
    }

    return app_runtime_settings_normalize($row);
}

function app_runtime_settings_update(PDO $pdo, array $input): array
{
    $columns = app_runtime_settings_table_columns($pdo);
    if (!$columns) {
        app_runtime_settings_log('Güncelleme fallback: tablo/kolonlar okunamadı.');
        return app_runtime_settings_defaults();
    }

    if (!in_array('id', $columns, true)) {
        app_runtime_settings_log('Güncelleme fallback: id kolonu bulunamadı.');
        return app_runtime_settings_defaults();
    }

    $current = app_runtime_settings_get($pdo);
    $next = $current;

    foreach (app_runtime_settings_numeric_keys() as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $next[$key] = app_runtime_settings_validate_value($key, $input[$key]);
    }
    foreach (app_runtime_settings_text_keys() as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $next[$key] = app_runtime_settings_validate_text_value($key, $input[$key]);
    }

    $set = [];
    $params = [];
    foreach (app_runtime_settings_numeric_keys() as $key) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $set[] = app_runtime_settings_q($key) . ' = ?';
        $params[] = (int)$next[$key];
    }
    foreach (app_runtime_settings_text_keys() as $key) {
        if (!in_array($key, $columns, true)) {
            continue;
        }
        $set[] = app_runtime_settings_q($key) . ' = ?';
        $params[] = (string)$next[$key];
    }

    if (in_array('updated_at', $columns, true)) {
        $set[] = app_runtime_settings_q('updated_at') . ' = NOW()';
    }

    if ($set) {
        try {
            $sql = 'UPDATE `app_runtime_settings` SET ' . implode(', ', $set);
            $sql .= ' WHERE `id` = 1';
            $sql .= ' LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() < 1) {
                app_runtime_settings_try_insert_defaults($pdo, $columns);

                $setFallback = [];
                $fallbackParams = [];
                foreach (app_runtime_settings_numeric_keys() as $key) {
                    if (!in_array($key, $columns, true)) {
                        continue;
                    }
                    $setFallback[] = app_runtime_settings_q($key) . ' = ?';
                    $fallbackParams[] = (int)$next[$key];
                }
                foreach (app_runtime_settings_text_keys() as $key) {
                    if (!in_array($key, $columns, true)) {
                        continue;
                    }
                    $setFallback[] = app_runtime_settings_q($key) . ' = ?';
                    $fallbackParams[] = (string)$next[$key];
                }
                if (in_array('updated_at', $columns, true)) {
                    $setFallback[] = app_runtime_settings_q('updated_at') . ' = NOW()';
                }

                if ($setFallback) {
                    $sqlFallback = 'UPDATE `app_runtime_settings` SET ' . implode(', ', $setFallback);
                    $sqlFallback .= ' WHERE `id` = 1';
                    $sqlFallback .= ' LIMIT 1';
                    $stmtFallback = $pdo->prepare($sqlFallback);
                    $stmtFallback->execute($fallbackParams);
                }
            }
        } catch (Throwable $e) {
            app_runtime_settings_log('Ayar güncelleme işleminde DB hatası.', [
                'error' => $e->getMessage(),
            ]);
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

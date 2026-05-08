<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';

api_require_method('GET');

try {
    $result = [
        'success' => true,
        'checks' => [
            'php_loaded' => [
                'ok' => true,
                'error' => null,
            ],
        ],
    ];

    try {
        $result['checks']['auth_helper_loaded'] = [
            'ok' => function_exists('api_find_user_id_by_auth_provider'),
            'error' => null,
        ];
    } catch (Throwable $e) {
        $result['checks']['auth_helper_loaded'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $result['checks']['cleanup_function_exists'] = [
            'ok' => function_exists('api_cleanup_deleted_auth_provider_bindings_for_google'),
            'error' => null,
        ];
    } catch (Throwable $e) {
        $result['checks']['cleanup_function_exists'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $result['checks']['active_provider_lookup_exists'] = [
            'ok' => function_exists('api_find_active_user_id_by_auth_provider'),
            'error' => null,
        ];
    } catch (Throwable $e) {
        $result['checks']['active_provider_lookup_exists'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $lockKey = 'google_login_health_' . bin2hex(random_bytes(4));
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 1)');
        $lockStmt->execute([$lockKey]);
        $locked = ((int)$lockStmt->fetchColumn()) === 1;

        if ($locked) {
            $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $unlockStmt->execute([$lockKey]);
        }

        $result['checks']['mysql_named_lock_available'] = [
            'ok' => $locked,
            'error' => $locked ? null : 'GET_LOCK başarısız veya timeout.',
        ];
    } catch (Throwable $e) {
        $result['checks']['mysql_named_lock_available'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $providerSchema = api_get_user_auth_provider_schema($pdo);
        $result['checks']['provider_schema_available'] = [
            'ok' => true,
            'error' => null,
        ];
        $result['checks']['provider_schema'] = [
            'ok' => true,
            'error' => null,
            'data' => $providerSchema,
        ];
    } catch (Throwable $e) {
        $result['checks']['provider_schema_available'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
        $result['checks']['provider_schema'] = [
            'ok' => false,
            'error' => $e->getMessage(),
            'data' => null,
        ];
    }

    try {
        $userProfileSchema = api_get_profile_schema($pdo);
        $result['checks']['user_profile_schema_available'] = [
            'ok' => true,
            'error' => null,
        ];
        $result['checks']['user_profile_schema'] = [
            'ok' => true,
            'error' => null,
            'data' => $userProfileSchema,
        ];
    } catch (Throwable $e) {
        $result['checks']['user_profile_schema_available'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
        $result['checks']['user_profile_schema'] = [
            'ok' => false,
            'error' => $e->getMessage(),
            'data' => null,
        ];
    }

    try {
        $tableExistsStmt = $pdo->query("SHOW TABLES LIKE 'user_auth_providers'");
        $tableExists = $tableExistsStmt ? (bool)$tableExistsStmt->fetchColumn() : false;

        $result['checks']['user_auth_providers_table_exists'] = [
            'ok' => $tableExists,
            'error' => $tableExists ? null : 'user_auth_providers tablosu bulunamadı.',
        ];
    } catch (Throwable $e) {
        $result['checks']['user_auth_providers_table_exists'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $columnsStmt = $pdo->query('SHOW COLUMNS FROM user_auth_providers');
        $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $result['checks']['user_auth_providers_columns'] = [
            'ok' => !empty($columns),
            'error' => empty($columns) ? 'Kolon bilgisi bulunamadı.' : null,
            'data' => $columns,
        ];
    } catch (Throwable $e) {
        $result['checks']['user_auth_providers_columns'] = [
            'ok' => false,
            'error' => $e->getMessage(),
            'data' => null,
        ];
    }

    try {
        $providerSchema = api_get_user_auth_provider_schema($pdo);
        $profileSchema = api_get_profile_schema($pdo);
        $joinProviderUserIdExpr = api_sql_collated_utf8_expr('p.`' . $providerSchema['user_id'] . '`');
        $joinProfileIdExpr = api_sql_collated_utf8_expr('u.`' . $profileSchema['id'] . '`');

        $joinSql = 'SELECT p.`' . $providerSchema['user_id'] . '` AS user_id '
            . 'FROM `' . $providerSchema['table'] . '` p '
            . 'INNER JOIN `' . $profileSchema['table'] . '` u '
            . 'ON ' . $joinProviderUserIdExpr . ' = ' . $joinProfileIdExpr . ' '
            . 'LIMIT 1';

        $joinStmt = $pdo->query($joinSql);
        $joinStmt->fetch(PDO::FETCH_ASSOC);

        $result['checks']['can_query_provider_join'] = [
            'ok' => true,
            'error' => null,
        ];
    } catch (Throwable $e) {
        $result['checks']['can_query_provider_join'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    api_success('Google login health check başarılı.', $result);
} catch (Throwable $e) {
    api_error('Google login health debug hatası.', 500, [
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

<?php
if (!isset($user)) {
    $user = require_auth();
}

$customCssPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/css/custom.css';
$customCssVersion = file_exists($customCssPath) ? filemtime($customCssPath) : time();

$initialTheme = 'system';
try {
    if (isset($pdo) && isset($user['user_id'])) {
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'admin_settings'");
        $colStmt->execute([DB_NAME]);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $themeCol = in_array('theme_mode', $cols, true) ? 'theme_mode' : (in_array('theme', $cols, true) ? 'theme' : null);
        $userCol = in_array('user_id', $cols, true) ? 'user_id' : null;
        $updatedCol = in_array('updated_at', $cols, true) ? 'updated_at' : (in_array('created_at', $cols, true) ? 'created_at' : null);

        if ($themeCol) {
            if ($userCol) {
                $sql = "SELECT `$themeCol` AS theme_mode FROM `admin_settings` WHERE `$userCol` = ? ORDER BY " . ($updatedCol ? "`$updatedCol` DESC" : "1") . " LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$user['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && in_array(strtolower((string)$row['theme_mode']), ['light', 'dark', 'system'], true)) {
                    $initialTheme = strtolower((string)$row['theme_mode']);
                }
            } else {
                $sql = "SELECT `$themeCol` AS theme_mode FROM `admin_settings` ORDER BY " . ($updatedCol ? "`$updatedCol` DESC" : "1") . " LIMIT 1";
                $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
                if ($row && in_array(strtolower((string)$row['theme_mode']), ['light', 'dark', 'system'], true)) {
                    $initialTheme = strtolower((string)$row['theme_mode']);
                }
            }
        }
    }
} catch (Throwable $e) {
    // Sessiz fallback: light
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Dashboard' ?> - <?= SITE_NAME ?></title>

    <script>
        (function () {
            try {
                var dbTheme = <?= json_encode($initialTheme) ?>;
                var pref = dbTheme || localStorage.getItem('admin_theme_preference') || 'system';
                if (!['light', 'dark', 'system'].includes(pref)) pref = 'system';

                var resolved = pref;
                if (pref === 'system') {
                    resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                }

                document.documentElement.setAttribute('data-theme-preference', pref);
                document.documentElement.setAttribute('data-theme', resolved);
                document.documentElement.setAttribute('data-bs-theme', resolved);
                localStorage.setItem('admin_theme_preference', pref);
            } catch (e) {}
        })();
    </script>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="/assets/css/custom.css?v=<?= (int)$customCssVersion ?>" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <div class="app-main">

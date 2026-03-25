<?php
if (!isset($user)) {
    $user = require_auth();
}

$customCssPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/css/custom.css';
$customCssVersion = file_exists($customCssPath) ? filemtime($customCssPath) : time();
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
                var pref = localStorage.getItem('app_theme') || 'dark';
                if (!['light', 'dark', 'system'].includes(pref)) pref = 'dark';

                var resolved = pref;
                if (pref === 'system') {
                    resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                }

                document.documentElement.setAttribute('data-theme-preference', pref);
                document.documentElement.setAttribute('data-theme', resolved);
                document.documentElement.setAttribute('data-bs-theme', resolved);
                localStorage.setItem('app_theme', pref);
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

<?php
if (!isset($user)) {
    $user = require_auth();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?? 'Dashboard' ?> - <?= SITE_NAME ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="/assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <div class="app-shell">
        <nav class="topbar">
            <div class="container-fluid px-3 px-lg-4 topbar-inner">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-secondary btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
                        <i class="bi bi-list"></i>
                    </button>
                    <a class="navbar-brand" href="/dashboard.php">Denizci Eğitim Admin</a>
                </div>
                <div class="d-flex align-items-center gap-2 topbar-user">
                    <small class="text-muted d-none d-md-inline"><?= htmlspecialchars($user['email']) ?></small>
                    <a href="/logout.php" class="btn btn-danger btn-sm">
                        <i class="bi bi-box-arrow-right"></i>
                        <span class="d-none d-sm-inline">Çıkış</span>
                    </a>
                </div>
            </div>
        </nav>
        <div class="app-main">

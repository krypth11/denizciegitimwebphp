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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="/assets/css/custom.css" rel="stylesheet">

    <style>
        body { background: #F5F7FA; }
        .navbar { background: white !important; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .sidebar {
            position: fixed;
            top: 56px;
            left: 0;
            width: 250px;
            height: calc(100vh - 56px);
            background: white;
            box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            overflow-y: auto;
        }
        .main-content {
            margin-left: 250px;
            padding: 30px;
            min-height: calc(100vh - 56px);
        }
        .sidebar .nav-link {
            color: #6B7280;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 4px 12px;
            transition: all 0.2s;
        }
        .sidebar .nav-link:hover { background: #F1F4F8; color: #5B9BD5; }
        .sidebar .nav-link.active { background: #5B9BD5; color: white; }
        .sidebar .nav-link i { margin-right: 10px; width: 20px; text-align: center; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="/dashboard.php">
                <span style="color: #5B9BD5;">Denizci</span> Eğitim
            </a>

            <div class="d-flex align-items-center">
                <span class="me-3 text-muted"><?= htmlspecialchars($user['email']) ?></span>
                <a href="/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Çıkış
                </a>
            </div>
        </div>
    </nav>

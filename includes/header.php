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

    <style>
        body {
            background: #F5F7FA;
            margin: 0;
            padding: 0;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: white;
            box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            overflow-y: auto;
            z-index: 1000;
        }
        .main-content {
            margin-left: 250px;
            padding: 30px;
            min-height: 100vh;
        }
        .sidebar .nav-link {
            color: #6B7280;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 4px 12px;
            transition: all 0.2s;
        }
        .sidebar .nav-link:hover {
            background: #F1F4F8;
            color: #5B9BD5;
        }
        .sidebar .nav-link.active {
            background: #5B9BD5;
            color: white;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid #E5E7EB;
            background: linear-gradient(135deg, #5B9BD5 0%, #4A8AC4 100%);
        }
        .sidebar-header h4 {
            color: white;
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }
        .sidebar-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 15px;
            border-top: 1px solid #E5E7EB;
            background: white;
        }
        .user-info {
            padding: 10px 12px;
            background: #F9FAFB;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .user-info small {
            color: #6B7280;
            font-size: 12px;
        }
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>

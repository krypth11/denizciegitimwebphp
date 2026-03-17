<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$user = require_auth();
$current_page = 'settings';
$page_title = 'Ayarlar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Ayarlar</h2>
            <p class="text-muted mb-0">Sistem ayarları ve panel tercihleri bu alana eklenecektir.</p>
        </div>
    </div>
    <div class="card placeholder-page">
        <div class="card-body">
            <div class="alert alert-info mb-0">Bu sayfa kurulumun sonraki adımında tamamlanacak.</div>
        </div>
    </div>
</div>
<?php include '../includes/footer.php'; ?>

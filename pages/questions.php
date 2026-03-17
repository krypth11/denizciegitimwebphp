<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$user = require_auth();
$current_page = 'questions';
$page_title = 'Sorular';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <h2>Sorular</h2>
    <div class="alert alert-info mt-3">Bu sayfa kurulumun sonraki adımında tamamlanacak.</div>
</div>
<?php include '../includes/footer.php'; ?>

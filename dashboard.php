<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$user = require_auth();
$current_page = 'dashboard';
$page_title = 'Dashboard';

$stats = [
    'total_questions' => $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
    'total_courses' => $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    'total_qualifications' => $pdo->query('SELECT COUNT(*) FROM qualifications')->fetchColumn(),
    'total_users' => $pdo->query('SELECT COUNT(*) FROM user_profiles WHERE is_deleted = 0')->fetchColumn(),
];

$question_types = $pdo->query('SELECT question_type, COUNT(*) as count FROM questions GROUP BY question_type')->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="container-fluid">
    <h2 class="mb-4">Dashboard</h2>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Toplam Soru</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_questions']) ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded p-3"><i class="bi bi-question-circle fs-2 text-primary"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Dersler</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_courses']) ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded p-3"><i class="bi bi-book fs-2 text-success"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Yeterlilikler</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_qualifications']) ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 rounded p-3"><i class="bi bi-award fs-2 text-warning"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Kullanıcılar</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_users']) ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded p-3"><i class="bi bi-people fs-2 text-info"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h5 class="mb-0">Soru Tipleri</h5></div>
                <div class="card-body"><canvas id="questionTypeChart"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white"><h5 class="mb-0">Son Aktiviteler</h5></div>
                <div class="card-body"><p class="text-muted">Henüz aktivite yok</p></div>
            </div>
        </div>
    </div>
</div>

<script>
const ctx = document.getElementById('questionTypeChart');
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($question_types, 'question_type')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($question_types, 'count')) ?>,
            backgroundColor: ['#5B9BD5', '#9EB6CB', '#4a8ac4', '#84a9c0', '#b7d0e1']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>

<?php include 'includes/footer.php'; ?>

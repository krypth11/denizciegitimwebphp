<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'notifications-stats';
$page_title = 'İstatistikler';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Bildirim İstatistikleri</h2>
            <p class="text-muted mb-0">Token, gönderim ve başarı oranı metrikleri.</p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Toplam token</small><div class="h4 mb-0" id="statTotalTokens">0</div></div></div></div>
        <div class="col-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Aktif token</small><div class="h4 mb-0" id="statActiveTokens">0</div></div></div></div>
        <div class="col-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Android cihaz</small><div class="h4 mb-0" id="statAndroid">0</div></div></div></div>
        <div class="col-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">iOS cihaz</small><div class="h4 mb-0" id="statIos">0</div></div></div></div>
        <div class="col-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">Bugün gönderilen</small><div class="h4 mb-0" id="statToday">0</div></div></div></div>
        <div class="col-6 col-xl-2"><div class="card"><div class="card-body"><small class="text-muted">7 gün başarı</small><div class="h4 mb-0" id="statSuccessRate">0%</div></div></div></div>
    </div>

    <div class="card">
        <div class="card-header bg-white"><h6 class="mb-0">Son 7 Gün Gönderim Grafiği</h6></div>
        <div class="card-body">
            <canvas id="notificationsChart" height="90"></canvas>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/notifications.php';
    let chart = null;

    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const api = async (action, method = 'GET', data = {}) => await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });

    function renderChart(data) {
        const labels = (data || []).map(x => x.day || '');
        const values = (data || []).map(x => Number(x.total || 0));
        const ctx = document.getElementById('notificationsChart');
        if (!ctx) return;

        if (chart) chart.destroy();
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Gönderim',
                    data: values,
                    borderColor: '#8fa8d1',
                    backgroundColor: 'rgba(143,168,209,0.15)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    async function load() {
        const res = await api('stats_summary');
        if (!res.success) {
            await appAlert('Hata', res.message || 'İstatistikler alınamadı.', 'error');
            return;
        }

        const d = res.data || {};
        $('#statTotalTokens').text(Number(d.total_tokens || 0));
        $('#statActiveTokens').text(Number(d.active_tokens || 0));
        $('#statAndroid').text(Number(d.android_devices || 0));
        $('#statIos').text(Number(d.ios_devices || 0));
        $('#statToday').text(Number(d.today_sent_notifications || 0));
        $('#statSuccessRate').text((Number(d.success_rate_7d || 0)).toFixed(2) + '%');
        renderChart(d.chart_7d || []);
    }

    load();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

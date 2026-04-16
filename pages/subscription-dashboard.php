<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'subscription-dashboard';
$page_title = 'Abonelik Yönetimi - Genel Bakış';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid subscription-page">
    <div class="page-header">
        <div>
            <h2>Abonelik Yönetimi</h2>
            <p class="text-muted mb-0">Webhook tabanlı abonelik operasyonlarının genel görünümü.</p>
        </div>
    </div>

    <div class="row g-3 mb-3" id="subSummaryCards">
        <div class="col-6 col-lg-2"><div class="card sub-soft-card"><div class="card-body"><div class="sub-label">Aktif Premium</div><div class="sub-value" id="sumActivePremium">0</div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card sub-soft-card"><div class="card-body"><div class="sub-label">30g Initial</div><div class="sub-value" id="sumInitial">0</div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card sub-soft-card"><div class="card-body"><div class="sub-label">30g Renewal</div><div class="sub-value" id="sumRenewal">0</div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card sub-soft-card"><div class="card-body"><div class="sub-label">30g Expiration</div><div class="sub-value" id="sumExpiration">0</div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card sub-soft-card"><div class="card-body"><div class="sub-label">30g Cancellation</div><div class="sub-value" id="sumCancellation">0</div></div></div></div>
        <div class="col-6 col-lg-2"><div class="card sub-soft-card"><div class="card-body"><div class="sub-label">Son Başarılı Webhook</div><div class="small fw-semibold mt-2" id="sumLastSuccess">-</div></div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card sub-soft-card">
                <div class="card-header bg-transparent border-0 pb-0 d-flex align-items-center justify-content-between">
                    <h6 class="mb-0">Son 30 Gün Event Trendi</h6>
                    <small class="text-muted" id="chartSourceHint">Kaynak: -</small>
                </div>
                <div class="card-body">
                    <div class="chart-wrap">
                        <canvas id="dashboardTrendChart" height="110"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12">
            <div class="card sub-soft-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="mb-0">Son Webhook Eventleri</h6>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Event</th><th>Kullanıcı</th><th>App User</th><th>Durum</th><th>Tarih</th><th class="text-end">İşlem</th></tr></thead>
                        <tbody id="recentEventsBody"><tr><td colspan="6" class="text-muted">Yükleniyor...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card sub-soft-card">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="mb-0">Son Sorunlu Kayıtlar</h6>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Tip</th><th>Açıklama</th><th>Tarih</th></tr></thead>
                        <tbody id="recentIssuesBody"><tr><td colspan="3" class="text-muted">Yükleniyor...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="userTimelineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Kullanıcı Abonelik Timeline</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="card border mb-3">
                    <div class="card-body py-3" id="timelineUserSummary">
                        <div class="text-muted small">Yükleniyor...</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Plan</th>
                                <th>Provider/Store</th>
                                <th>Entitlement</th>
                                <th>Eski</th>
                                <th>Yeni</th>
                                <th>Kaynak</th>
                                <th>Tarih</th>
                            </tr>
                        </thead>
                        <tbody id="timelineTableBody"><tr><td colspan="8" class="text-muted">Yükleniyor...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/subscriptions.php';
    const fmtDate = (v) => v ? (window.formatDate ? window.formatDate(v) : v) : '-';
    const esc = (t) => $('<div>').text(t ?? '').html();
    let trendChart = null;

    const chartColors = {
        INITIAL_PURCHASE: '#0d6efd',
        RENEWAL: '#198754',
        EXPIRATION: '#dc3545',
        CANCELLATION: '#fd7e14'
    };

    const statusBadge = (s) => {
        const key = String(s || '').toLowerCase();
        if (key === 'processed') return '<span class="badge text-bg-success">Processed</span>';
        if (key === 'duplicate') return '<span class="badge text-bg-warning">Duplicate</span>';
        if (key === 'unmatched_user') return '<span class="badge text-bg-secondary">Unmatched</span>';
        if (key === 'conflict') return '<span class="badge text-bg-info">Conflict</span>';
        return '<span class="badge text-bg-danger">Failed</span>';
    };

    const api = async (action, data = {}) => {
        return window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method: 'GET', data, dataType: 'json' });
    };

    function renderSummary(summary) {
        $('#sumActivePremium').text(summary.active_premium_count || 0);
        $('#sumInitial').text(summary.last_30_initial_purchase || 0);
        $('#sumRenewal').text(summary.last_30_renewal || 0);
        $('#sumExpiration').text(summary.last_30_expiration || 0);
        $('#sumCancellation').text(summary.last_30_cancellation || 0);
        $('#sumLastSuccess').text(fmtDate(summary.last_successful_webhook_at));
    }

    function renderUserCell(x) {
        const fullName = (x.full_name || '').trim();
        const email = (x.email || '').trim();
        const userId = (x.user_id || '').trim();
        const appUserId = (x.app_user_id || x.rc_app_user_id || '').trim();

        if (!fullName && !email && !userId) {
            return `<small class="text-muted">${esc(appUserId || '-')}</small>`;
        }

        return `
            <div class="user-cell">
                <div class="fw-semibold text-truncate">${esc(fullName || userId || appUserId || '-')}</div>
                <div class="small text-muted text-truncate">${esc(email || (appUserId || '-'))}</div>
                <div class="xsmall text-secondary text-truncate">${esc(userId || '-')}</div>
            </div>
        `;
    }

    function renderEvents(items) {
        const rows = (items || []).map((x) => `
            <tr>
                <td><span class="badge text-bg-light border">${esc(x.event_type || '-')}</span></td>
                <td>${renderUserCell(x)}</td>
                <td><small class="text-muted">${esc(x.app_user_id || x.rc_app_user_id || '-')}</small></td>
                <td>${statusBadge(x.process_status)}</td>
                <td><small class="text-muted">${esc(fmtDate(x.created_at))}</small></td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view-user" data-user-id="${esc(x.user_id || '')}" ${x.user_id ? '' : 'disabled'} title="Kullanıcı timeline">
                        <i class="bi bi-eye"></i>
                    </button>
                </td>
            </tr>
        `).join('');
        $('#recentEventsBody').html(rows || '<tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>');
    }

    function renderIssues(items) {
        const rows = (items || []).map((x) => `
            <tr>
                <td>${statusBadge(x.process_status)}</td>
                <td><small>${esc(x.error_message || x.event_type || '-')}</small></td>
                <td><small class="text-muted">${esc(fmtDate(x.created_at))}</small></td>
            </tr>
        `).join('');
        $('#recentIssuesBody').html(rows || '<tr><td colspan="3" class="text-muted">Sorunlu kayıt yok.</td></tr>');
    }

    function renderTrendChart(payload) {
        const labels = payload?.labels || [];
        const series = payload?.series || {};
        const source = payload?.source || '-';
        const ctx = document.getElementById('dashboardTrendChart');
        if (!ctx) return;

        const datasets = ['INITIAL_PURCHASE', 'RENEWAL', 'EXPIRATION', 'CANCELLATION'].map((key) => ({
            label: key,
            data: series[key] || new Array(labels.length).fill(0),
            borderColor: chartColors[key],
            backgroundColor: chartColors[key] + '1f',
            tension: 0.35,
            fill: false,
            pointRadius: 2,
            pointHoverRadius: 4,
            borderWidth: 2
        }));

        if (trendChart) {
            trendChart.destroy();
        }

        trendChart = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top' },
                    tooltip: { enabled: true }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });

        $('#chartSourceHint').text('Kaynak: ' + (source === 'webhook_fallback' ? 'Webhook (fallback)' : 'History'));
    }

    function renderTimelineModal(data) {
        const u = data?.user || {};
        const timeline = data?.timeline || [];

        $('#timelineUserSummary').html(`
            <div class="row g-2 small">
                <div class="col-12 col-lg-4"><span class="text-muted">Ad Soyad:</span> <span class="fw-semibold">${esc(u.full_name || '-')}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">Email:</span> <span class="fw-semibold">${esc(u.email || '-')}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">User ID:</span> <span class="fw-semibold">${esc(u.user_id || '-')}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">RC App User ID:</span> <span class="fw-semibold">${esc(u.rc_app_user_id || '-')}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">Plan:</span> <span class="fw-semibold">${esc(u.plan_code || '-')}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">Entitlement:</span> <span class="fw-semibold">${esc(u.entitlement_id || '-')}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">Expires At:</span> <span class="fw-semibold">${esc(fmtDate(u.expires_at))}</span></div>
                <div class="col-12 col-lg-4"><span class="text-muted">Last Synced:</span> <span class="fw-semibold">${esc(fmtDate(u.last_synced_at))}</span></div>
            </div>
        `);

        if (!timeline.length) {
            $('#timelineTableBody').html('<tr><td colspan="8" class="text-muted">Bu kullanıcı için henüz abonelik geçmişi bulunmuyor.</td></tr>');
            return;
        }

        const rows = timeline.map((x) => `
            <tr>
                <td>
                    <span class="badge text-bg-light border">${esc(x.event_type || '-')}</span>
                    ${x.event_title ? `<div class="small text-muted">${esc(x.event_title)}</div>` : ''}
                </td>
                <td><small>${esc(x.plan_code || '-')}</small></td>
                <td><small>${esc((x.provider || '-') + ' / ' + (x.store || '-'))}</small></td>
                <td><small>${esc(x.entitlement_id || '-')}</small></td>
                <td><small>${esc(x.old_value || '-')}</small></td>
                <td><small>${esc(x.new_value || '-')}</small></td>
                <td><small>${esc(x.source || '-')}</small></td>
                <td><small class="text-muted">${esc(fmtDate(x.event_at || x.created_at))}</small></td>
            </tr>
        `).join('');
        $('#timelineTableBody').html(rows);
    }

    async function openTimeline(userId) {
        const uid = String(userId || '').trim();
        if (!uid) return;

        $('#timelineUserSummary').html('<div class="text-muted small">Yükleniyor...</div>');
        $('#timelineTableBody').html('<tr><td colspan="8" class="text-muted">Yükleniyor...</td></tr>');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userTimelineModal')).show();

        const res = await api('user_timeline', { user_id: uid });
        if (!res.success) {
            $('#timelineTableBody').html('<tr><td colspan="8" class="text-danger">Timeline verisi alınamadı.</td></tr>');
            return;
        }

        renderTimelineModal(res.data || {});
    }

    async function load() {
        const [summaryRes, chartRes, recentRes] = await Promise.all([
            api('dashboard_summary'),
            api('dashboard_chart'),
            api('dashboard_recent_events', { limit: 40 })
        ]);

        if (!summaryRes.success) {
            if (window.showAppAlert) await window.showAppAlert({ title: 'Hata', message: summaryRes.message || 'Dashboard yüklenemedi.', type: 'error' });
            return;
        }

        renderSummary(summaryRes.data?.summary || {});
        renderEvents((recentRes.success ? recentRes.data?.items : summaryRes.data?.recent_events) || []);
        renderIssues(summaryRes.data?.recent_issues || []);

        if (chartRes.success) {
            renderTrendChart(chartRes.data || {});
        }
    }

    $(document).on('click', '.btn-view-user', function () {
        openTimeline($(this).data('user-id'));
    });

    load();
});
</script>

<style>
.subscription-page .sub-soft-card { border: none; box-shadow: var(--shadow-soft); }
.subscription-page .sub-label { color: var(--text-muted); font-size: 12px; }
.subscription-page .sub-value { font-size: 24px; font-weight: 700; margin-top: 6px; }
.subscription-page .chart-wrap { position: relative; width: 100%; height: 320px; }
.subscription-page .xsmall { font-size: 11px; }
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

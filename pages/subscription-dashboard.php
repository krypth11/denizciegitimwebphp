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

    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card sub-soft-card h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <h6 class="mb-0">Son Webhook Eventleri</h6>
                </div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Event</th><th>Kullanıcı</th><th>Durum</th><th>Tarih</th></tr></thead>
                        <tbody id="recentEventsBody"><tr><td colspan="4" class="text-muted">Yükleniyor...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card sub-soft-card h-100">
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

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/subscriptions.php';
    const fmtDate = (v) => v ? (window.formatDate ? window.formatDate(v) : v) : '-';
    const esc = (t) => $('<div>').text(t ?? '').html();
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

    function renderEvents(items) {
        const rows = (items || []).map((x) => `
            <tr>
                <td><span class="badge text-bg-light border">${esc(x.event_type || '-')}</span></td>
                <td><small>${esc(x.user_id || x.app_user_id || '-')}</small></td>
                <td>${statusBadge(x.process_status)}</td>
                <td><small class="text-muted">${esc(fmtDate(x.created_at))}</small></td>
            </tr>
        `).join('');
        $('#recentEventsBody').html(rows || '<tr><td colspan="4" class="text-muted">Kayıt yok.</td></tr>');
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

    async function load() {
        const res = await api('dashboard_summary');
        if (!res.success) {
            if (window.showAppAlert) await window.showAppAlert({ title: 'Hata', message: res.message || 'Dashboard yüklenemedi.', type: 'error' });
            return;
        }
        renderSummary(res.data?.summary || {});
        renderEvents(res.data?.recent_events || []);
        renderIssues(res.data?.recent_issues || []);
    }

    load();
});
</script>

<style>
.subscription-page .sub-soft-card { border: none; box-shadow: var(--shadow-soft); }
.subscription-page .sub-label { color: var(--text-muted); font-size: 12px; }
.subscription-page .sub-value { font-size: 24px; font-weight: 700; margin-top: 6px; }
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'subscription-issues';
$page_title = 'Abonelik Yönetimi - Sorunlu Kayıtlar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid subscription-page">
    <div class="page-header">
        <div>
            <h2>Sorunlu Kayıtlar</h2>
            <p class="text-muted mb-0">Eşleşmeyen kullanıcı, işleme hatası, tekrar eden olay ve durum çakışması kayıtları.</p>
        </div>
    </div>

    <div class="card sub-soft-card">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Sorun Tipi</th><th>Olay</th><th>Kullanıcı</th><th>Açıklama</th><th>Tarih</th></tr></thead>
                <tbody id="issuesBody"><tr><td colspan="5" class="text-muted">Yükleniyor...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/subscriptions.php';
    const esc = (t) => $('<div>').text(t ?? '').html();
    const fmtDate = (v) => v ? (window.formatDate ? window.formatDate(v) : v) : '-';
    const trUi = window.subscriptionAdminUi || {};
    const eventTypeLabel = (v) => trUi.eventTypeLabel ? trUi.eventTypeLabel(v) : (v || '-');
    const statusLabel = (v) => trUi.statusLabel ? trUi.statusLabel(v) : (v || '-');

    const badge = (status) => {
        const s = String(status || '').toLowerCase();
        if (s === 'duplicate') return '<span class="badge text-bg-warning">' + esc(statusLabel('duplicate')) + '</span>';
        if (s === 'unmatched_user') return '<span class="badge text-bg-secondary">' + esc(statusLabel('unmatched_user')) + '</span>';
        if (s === 'conflict' || s === 'status_conflict') return '<span class="badge text-bg-info">' + esc(statusLabel('conflict')) + '</span>';
        if (s === 'failed') return '<span class="badge text-bg-danger">' + esc(statusLabel('failed')) + '</span>';
        return '<span class="badge text-bg-light border">Sorun</span>';
    };

    const api = async () => window.appAjax({
        url: endpoint + '?action=list_issues',
        method: 'GET',
        data: { limit: 300 },
        dataType: 'json'
    });

    function render(items) {
        const rows = (items || []).map(x => `
            <tr>
                <td>${badge(x.process_status)}</td>
                <td><small>${esc(eventTypeLabel(x.event_type || '-'))}</small><div class="small text-muted">${esc(x.event_id || '-')}</div></td>
                <td><small>${esc(x.user_id || x.app_user_id || '-')}</small></td>
                <td><small>${esc(x.error_message || '-')}</small></td>
                <td><small class="text-muted">${esc(fmtDate(x.created_at))}</small></td>
            </tr>
        `).join('');
        $('#issuesBody').html(rows || '<tr><td colspan="5" class="text-muted">Sorunlu kayıt bulunamadı.</td></tr>');
    }

    api().then(res => {
        if (!res.success) {
            if (window.showAppAlert) window.showAppAlert({ title: 'Hata', message: res.message || 'Sorunlu kayıtlar alınamadı.', type: 'error' });
            return;
        }
        render(res.data?.items || []);
    });
});
</script>

<style>.subscription-page .sub-soft-card{border:none;box-shadow:var(--shadow-soft);}</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'subscription-events';
$page_title = 'Abonelik Yönetimi - Webhook Olayları';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid subscription-page">
    <div class="page-header">
        <div>
            <h2>Webhook Olayları</h2>
            <p class="text-muted mb-0">Ham RevenueCat event loglarını filtreleyin ve payload detayını inceleyin.</p>
        </div>
    </div>

    <div class="card sub-soft-card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-6 col-lg-2"><input type="text" id="fltEventType" class="form-control" placeholder="Olay tipi"></div>
                <div class="col-6 col-lg-2">
                    <select id="fltStatus" class="form-select">
                        <option value="">Durum (Tümü)</option>
                        <option value="processed">İşlendi</option>
                        <option value="failed">Hata</option>
                        <option value="duplicate">Tekrar</option>
                        <option value="unmatched_user">Eşleşmedi</option>
                        <option value="conflict">Çakışma</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2"><input type="date" id="fltFrom" class="form-control"></div>
                <div class="col-6 col-lg-2"><input type="date" id="fltTo" class="form-control"></div>
                <div class="col-12 col-lg-3"><input type="search" id="fltSearch" class="form-control" placeholder="Kullanıcı / app_user_id / event_id"></div>
                <div class="col-12 col-lg-1"><button id="btnFilter" class="btn btn-primary w-100">Filtrele</button></div>
            </div>
        </div>
    </div>

    <div class="card sub-soft-card">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Olay</th><th>Kullanıcı</th><th>Uygulama Kullanıcısı</th><th>Durum</th><th>Tarih</th></tr></thead>
                <tbody id="eventsBody"><tr><td colspan="5" class="text-muted">Yükleniyor...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="payloadModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Yük İçeriği Detayı</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <pre class="small bg-body-tertiary p-3 rounded" id="payloadDetailPre">-</pre>
            </div>
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
    const statusBadge = (s) => {
        const k = String(s || '').toLowerCase();
        if (k === 'processed') return '<span class="badge text-bg-success">' + esc(statusLabel(k)) + '</span>';
        if (k === 'duplicate') return '<span class="badge text-bg-warning">' + esc(statusLabel(k)) + '</span>';
        if (k === 'unmatched_user') return '<span class="badge text-bg-secondary">' + esc(statusLabel(k)) + '</span>';
        if (k === 'conflict') return '<span class="badge text-bg-info">' + esc(statusLabel(k)) + '</span>';
        return '<span class="badge text-bg-danger">' + esc(statusLabel('failed')) + '</span>';
    };

    const api = async (action, data = {}) => window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method: 'GET', data, dataType: 'json' });

    const collectFilters = () => ({
        event_type: ($('#fltEventType').val() || '').trim(),
        status: $('#fltStatus').val() || '',
        date_from: $('#fltFrom').val() || '',
        date_to: $('#fltTo').val() || '',
        search: ($('#fltSearch').val() || '').trim(),
        limit: 200
    });

    function render(items) {
        const rows = (items || []).map(x => `
            <tr class="event-row" data-id="${esc(x.id || x.event_id || '')}">
                <td><span class="badge text-bg-light border">${esc(eventTypeLabel(x.event_type || '-'))}</span><div class="small text-muted">${esc(x.event_id || '-')}</div></td>
                <td><small>${esc(x.user_id || '-')}</small></td>
                <td><small>${esc(x.app_user_id || x.rc_app_user_id || '-')}</small></td>
                <td>${statusBadge(x.process_status)}</td>
                <td><small class="text-muted">${esc(fmtDate(x.created_at || x.event_timestamp))}</small></td>
            </tr>
        `).join('');
        $('#eventsBody').html(rows || '<tr><td colspan="5" class="text-muted">Kayıt bulunamadı.</td></tr>');
    }

    async function load() {
        const res = await api('list_events', collectFilters());
        if (!res.success) {
            if (window.showAppAlert) await window.showAppAlert({ title: 'Hata', message: res.message || 'Olaylar alınamadı.', type: 'error' });
            return;
        }
        render(res.data?.items || []);
    }

    $(document).on('click', '.event-row', async function () {
        const id = ($(this).data('id') || '').toString();
        if (!id) return;
        const res = await api('event_payload_detail', { id });
        if (!res.success) return;
        const payload = res.data?.item?.payload || {};
        $('#payloadDetailPre').text(JSON.stringify(payload, null, 2));
        bootstrap.Modal.getOrCreateInstance(document.getElementById('payloadModal')).show();
    });

    $('#btnFilter').on('click', load);
    $('#fltSearch').on('input', function () { clearTimeout(window._subEvSearchDeb); window._subEvSearchDeb = setTimeout(load, 250); });
    $('#fltStatus, #fltFrom, #fltTo').on('change', load);

    load();
});
</script>

<style>
.subscription-page .sub-soft-card { border: none; box-shadow: var(--shadow-soft); }
#eventsBody tr { cursor: pointer; }
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'community-reports';
$page_title = 'Raporlanan Mesajlar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Raporlanan Mesajlar</h2>
            <p class="text-muted mb-0">Kullanıcı raporlarını inceleyin, mesajı kaldırın veya kullanıcıyı susturun.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="reportsTable">
                    <thead>
                        <tr>
                            <th>Rapor Nedeni</th>
                            <th>Mesaj Önizlemesi</th>
                            <th>Oda</th>
                            <th>Raporlayan</th>
                            <th>Mesaj Sahibi</th>
                            <th>Tarih</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="reportDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rapor Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-muted small" id="detailMeta"></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Rapor Nedeni</label>
                        <div id="detailReason" class="small"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Oda</label>
                        <div id="detailRoom" class="small"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Raporlayan</label>
                        <div id="detailReporter" class="small"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Mesaj Sahibi</label>
                        <div id="detailOwner" class="small"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Silinmiş Mi?</label>
                        <div id="detailDeleted" class="small"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Rapor Tarihi</label>
                        <div id="detailDate" class="small"></div>
                    </div>
                </div>
                <label class="form-label fw-semibold mb-1">Tam Mesaj Metni</label>
                <div id="detailMessage" class="border rounded p-3 bg-light-subtle" style="white-space: pre-wrap;"></div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/community-reports.php';
    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const reasonMap = {
        'küfür_hakaret': 'Küfür / Hakaret',
        'spam': 'Spam',
        'alakasiz_icerik': 'Alakasız İçerik',
        'yanlis_bilgi': 'Yanlış Bilgi',
        'diger': 'Diğer'
    };
    const reasonLabel = (reason) => reasonMap[reason] || reason || '-';
    const detailModalEl = document.getElementById('reportDetailModal');
    const detailModal = detailModalEl ? new bootstrap.Modal(detailModalEl) : null;

    const api = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    };

    async function loadReports() {
        const res = await api('list');
        const $tbody = $('#reportsTable tbody');
        if (!res.success) {
            $tbody.html('<tr><td colspan="7" class="text-muted p-3">Raporlar getirilemedi.</td></tr>');
            return;
        }

        const rows = res.data?.reports || [];
        if (!rows.length) {
            $tbody.html('<tr><td colspan="7" class="text-muted p-3">Bekleyen rapor yok.</td></tr>');
            return;
        }

        $tbody.html(rows.map((r) => `
            <tr>
                <td><span class="badge text-bg-warning">${esc(reasonLabel(r.reason))}</span></td>
                <td>${esc(r.message_preview || '-')}</td>
                <td>${esc(r.room_name || '-')}</td>
                <td>${esc(r.reporter_name || '-')}</td>
                <td>${esc(r.owner_name || '-')}</td>
                <td><small class="text-muted">${esc(r.created_at || '-')}</small></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-sm btn-info detail-btn"
                            data-report='${esc(JSON.stringify(r))}'>
                            <i class="bi bi-search"></i> Detay
                        </button>
                        <button class="btn btn-sm btn-danger del-msg-btn" data-message-id="${esc(r.message_id)}"><i class="bi bi-trash"></i> Mesajı Sil</button>
                        <button class="btn btn-sm btn-secondary ignore-btn" data-report-id="${esc(r.id)}"><i class="bi bi-eye-slash"></i> Yok Say</button>
                        <button class="btn btn-sm btn-warning mute-btn" data-user-id="${esc(r.owner_id)}"><i class="bi bi-volume-mute"></i> Sustur</button>
                    </div>
                </td>
            </tr>
        `).join(''));
    }

    $(document).on('click', '.detail-btn', function () {
        const raw = $(this).attr('data-report') || '{}';
        let r = {};
        try { r = JSON.parse(raw); } catch (e) { r = {}; }

        $('#detailMeta').text(`Report ID: ${r.report_id || '-'} • Message ID: ${r.message_id || '-'}`);
        $('#detailReason').text(reasonLabel(r.reason));
        $('#detailRoom').text(r.room_name || '-');
        $('#detailReporter').text(`${r.reporter_name || '-'}${r.reporter_email ? ' (' + r.reporter_email + ')' : ''}`);
        $('#detailOwner').text(`${r.owner_name || '-'}${r.owner_email ? ' (' + r.owner_email + ')' : ''}`);
        $('#detailDeleted').html((Number(r.message_is_deleted || 0) === 1)
            ? '<span class="badge text-bg-danger">Evet</span>'
            : '<span class="badge text-bg-success">Hayır</span>');
        $('#detailDate').text(r.created_at || '-');
        $('#detailMessage').text(r.message_text_full || '-');

        if (detailModal) detailModal.show();
    });

    $(document).on('click', '.del-msg-btn', async function () {
        const messageId = $(this).data('message-id');
        const ok = await window.showAppConfirm({
            title: 'Mesajı Kaldır',
            message: 'Bu mesaj moderasyon nedeniyle kaldırılsın mı?',
            type: 'warning',
            confirmText: 'Kaldır',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_message', 'POST', { message_id: messageId });
        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Mesaj kaldırıldı.', 'success');
        await loadReports();
    });

    $(document).on('click', '.ignore-btn', async function () {
        const reportId = $(this).data('report-id');
        const res = await api('ignore_report', 'POST', { report_id: reportId });
        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Rapor yok sayıldı.', 'success');
        await loadReports();
    });

    $(document).on('click', '.mute-btn', async function () {
        const userId = $(this).data('user-id');
        const minutesRaw = prompt('Susturma süresi (dakika):', '60');
        if (minutesRaw === null) return;
        const minutes = parseInt(minutesRaw, 10);

        const res = await api('mute_user', 'POST', {
            user_id: userId,
            minutes: Number.isNaN(minutes) ? 60 : minutes
        });
        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Kullanıcı susturuldu.', 'success');
    });

    loadReports();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

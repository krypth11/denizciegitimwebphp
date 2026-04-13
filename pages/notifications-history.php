<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'notifications-history';
$page_title = 'Gönderim Geçmişi';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Gönderim Geçmişi</h2>
            <p class="text-muted mb-0">Gönderilen/taslak bildirimlerin geçmiş kayıtları.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mobile-card-table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Başlık</th>
                            <th>Kanal</th>
                            <th>Hedef Tipi</th>
                            <th>Toplam Hedef</th>
                            <th>Başarılı</th>
                            <th>Başarısız</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody"></tbody>
                </table>
            </div>
            <div class="alert alert-light text-muted d-none mt-2" id="historyEmpty">Kayıt bulunamadı.</div>
        </div>
    </div>
</div>

<div class="modal fade" id="historyDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bildirim Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="historyDetailMeta" class="mb-3"></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Zaman</th>
                                <th>Kullanıcı</th>
                                <th>Platform</th>
                                <th>Token</th>
                                <th>Durum</th>
                                <th>Mesaj</th>
                            </tr>
                        </thead>
                        <tbody id="historyLogsBody"></tbody>
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
    const endpoint = '../ajax/notifications.php';
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const appConfirm = (title, message, options = {}) => window.showAppConfirm({ title, message, ...options });

    const api = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
    };

    const esc = (t) => $('<div>').text(t ?? '').html();

    function renderRows(items) {
        const $tb = $('#historyBody').empty();
        $('#historyEmpty').toggleClass('d-none', items.length > 0);

        items.forEach(r => {
            $tb.append(`
                <tr class="mobile-card-row">
                    <td>${esc(r.created_at || r.sent_at || '-')}</td>
                    <td>${esc(r.title || '-')}</td>
                    <td>${esc(r.channel || '-')}</td>
                    <td>${esc(r.target_type || '-')}</td>
                    <td>${Number(r.total_target || 0)}</td>
                    <td>${Number(r.success_count || 0)}</td>
                    <td>${Number(r.failure_count || 0)}</td>
                    <td><span class="badge text-bg-secondary">${esc(r.status || '-')}</span></td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-secondary js-detail" data-id="${esc(r.id)}"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-sm btn-warning js-copy" data-id="${esc(r.id)}"><i class="bi bi-files"></i></button>
                            <button class="btn btn-sm btn-primary js-resend" data-id="${esc(r.id)}"><i class="bi bi-arrow-repeat"></i></button>
                        </div>
                    </td>
                </tr>
            `);
        });
    }

    async function load() {
        const res = await api('list_history');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Geçmiş listelenemedi.', 'error');
            return;
        }
        renderRows(res.data?.items || []);
    }

    $(document).on('click', '.js-detail', async function () {
        const id = $(this).data('id');
        const res = await api('get_notification_detail', 'GET', { notification_id: id });
        if (!res.success) return appAlert('Hata', res.message || 'Detay alınamadı.', 'error');

        const n = res.data?.notification || {};
        const logs = res.data?.logs || [];

        $('#historyDetailMeta').html(`
            <div class="card bg-light border-0"><div class="card-body py-2">
                <div><strong>${esc(n.title || '-')}</strong></div>
                <div class="text-muted small">${esc(n.message || '')}</div>
            </div></div>
        `);

        const $lb = $('#historyLogsBody').empty();
        logs.forEach(l => {
            $lb.append(`
                <tr>
                    <td>${esc(l.created_at || '-')}</td>
                    <td>${esc(l.user_id || '-')}</td>
                    <td>${esc(l.platform || '-')}</td>
                    <td><span class="small text-muted">${esc(l.token_masked || '-')}</span></td>
                    <td>${esc(l.status || '-')}</td>
                    <td>${esc(l.response_message || '-')}</td>
                </tr>
            `);
        });

        bootstrap.Modal.getOrCreateInstance(document.getElementById('historyDetailModal')).show();
    });

    $(document).on('click', '.js-copy', async function () {
        const id = $(this).data('id');
        const res = await api('duplicate_notification', 'POST', { notification_id: id });
        if (!res.success) return appAlert('Hata', res.message || 'Kopyalanamadı.', 'error');
        await appAlert('Başarılı', 'Bildirim kopyalandı.', 'success');
        load();
    });

    $(document).on('click', '.js-resend', async function () {
        const id = $(this).data('id');
        const ok = await appConfirm('Yeniden Gönder', 'Bu bildirimi tekrar göndermek istiyor musunuz?', { type: 'warning', confirmText: 'Gönder', cancelText: 'İptal' });
        if (!ok) return;

        const res = await api('resend_notification', 'POST', { notification_id: id });
        if (!res.success) return appAlert('Hata', res.message || 'Yeniden gönderilemedi.', 'error');
        await appAlert('Başarılı', 'Bildirim yeniden gönderildi.', 'success');
        load();
    });

    load();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

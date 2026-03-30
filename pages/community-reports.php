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

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/community-reports.php';
    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

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
                <td><span class="badge text-bg-warning">${esc(r.reason || '-')}</span></td>
                <td>${esc(r.message_preview || '-')}</td>
                <td>${esc(r.room_name || '-')}</td>
                <td>${esc(r.reporter_name || '-')}</td>
                <td>${esc(r.owner_name || '-')}</td>
                <td><small class="text-muted">${esc(r.created_at || '-')}</small></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <button class="btn btn-sm btn-danger del-msg-btn" data-message-id="${esc(r.message_id)}"><i class="bi bi-trash"></i> Mesajı Sil</button>
                        <button class="btn btn-sm btn-secondary ignore-btn" data-report-id="${esc(r.id)}"><i class="bi bi-eye-slash"></i> Yok Say</button>
                        <button class="btn btn-sm btn-warning mute-btn" data-user-id="${esc(r.owner_id)}"><i class="bi bi-volume-mute"></i> Sustur</button>
                    </div>
                </td>
            </tr>
        `).join(''));
    }

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

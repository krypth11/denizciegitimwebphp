<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'community-messages';
$page_title = 'Topluluk Mesajları';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Topluluk Mesajları</h2>
            <p class="text-muted mb-0">Oda ve kullanıcı bazlı mesaj moderasyonu yapın.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Oda ID</label>
                    <input type="text" class="form-control" id="filterRoomId" placeholder="room_id">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Kullanıcı ID</label>
                    <input type="text" class="form-control" id="filterUserId" placeholder="user_id">
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button class="btn btn-primary" id="filterBtn"><i class="bi bi-search"></i> Filtrele</button>
                    <button class="btn btn-secondary" id="clearBtn"><i class="bi bi-x-circle"></i> Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="messagesTable">
                    <thead>
                        <tr>
                            <th>Oda</th>
                            <th>Kullanıcı</th>
                            <th>Mesaj Önizleme</th>
                            <th>Tarih</th>
                            <th>Silinmiş mi</th>
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
    const endpoint = '../ajax/community-messages.php';
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

    async function loadMessages() {
        const res = await api('list', 'GET', {
            room_id: ($('#filterRoomId').val() || '').trim(),
            user_id: ($('#filterUserId').val() || '').trim()
        });

        const $tbody = $('#messagesTable tbody');
        if (!res.success) {
            $tbody.html('<tr><td colspan="6" class="text-muted p-3">Kayıtlar getirilemedi.</td></tr>');
            return;
        }

        const rows = res.data?.messages || [];
        if (!rows.length) {
            $tbody.html('<tr><td colspan="6" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        $tbody.html(rows.map((r) => `
            <tr>
                <td>${esc(r.room_name || '-')}</td>
                <td>${esc(r.user_name || '-')}<br><small class="text-muted">${esc(r.user_id || '')}</small></td>
                <td>${esc(r.message_preview || '-')}</td>
                <td><small class="text-muted">${esc(r.created_at || '-')}</small></td>
                <td>${Number(r.is_deleted) === 1 ? '<span class="badge text-bg-secondary">Evet</span>' : '<span class="badge text-bg-success">Hayır</span>'}</td>
                <td>
                    ${Number(r.is_deleted) === 1 ? '<span class="text-muted small">-</span>' : `<button class="btn btn-sm btn-danger delete-btn" data-id="${esc(r.id)}"><i class="bi bi-trash"></i> Moderasyon Sil</button>`}
                </td>
            </tr>
        `).join(''));
    }

    $(document).on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        const ok = await window.showAppConfirm({
            title: 'Mesaj Silme',
            message: 'Bu mesaj moderasyon nedeniyle kaldırılsın mı?',
            type: 'warning',
            confirmText: 'Kaldır',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Mesaj kaldırıldı.', 'success');
        await loadMessages();
    });

    $('#filterBtn').on('click', loadMessages);
    $('#clearBtn').on('click', async function () {
        $('#filterRoomId').val('');
        $('#filterUserId').val('');
        await loadMessages();
    });

    loadMessages();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

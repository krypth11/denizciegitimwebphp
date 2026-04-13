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
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="text-muted small" id="historyCountText">Toplam Gönderim: 0 / Listelenen: 0</div>
            </div>
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
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bildirim Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="historyDetailMeta">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Başlık</label>
                        <div class="border rounded p-2 bg-light" id="historyDetailTitle">-</div>
                    </div>
                    <div>
                        <label class="form-label fw-semibold">İçerik</label>
                        <div class="border rounded p-2 bg-light" id="historyDetailMessage">-</div>
                    </div>
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
                        </div>
                    </td>
                </tr>
            `);
        });
    }

    function renderCount(totalCount, listedCount) {
        $('#historyCountText').text(`Toplam Gönderim: ${Number(totalCount || 0)} / Listelenen: ${Number(listedCount || 0)}`);
    }

    async function load() {
        const res = await api('list_history');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Geçmiş listelenemedi.', 'error');
            return;
        }
        const items = res.data?.items || [];
        renderRows(items);
        renderCount(res.data?.total_count || 0, res.data?.listed_count || items.length);
    }

    $(document).on('click', '.js-detail', async function () {
        const id = $(this).data('id');
        const res = await api('get_notification_detail', 'GET', { notification_id: id });
        if (!res.success) return appAlert('Hata', res.message || 'Detay alınamadı.', 'error');

        const n = res.data?.notification || {};
        $('#historyDetailTitle').text(n.title || '-');
        $('#historyDetailMessage').text(n.message || '-');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('historyDetailModal')).show();
    });

    load();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'community-rooms';
$page_title = 'Topluluk Odaları';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Topluluk Odaları</h2>
            <p class="text-muted mb-0">System ve custom odaları yönetin, topluluk kurallarını düzenleyin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomRoomModal">
                <i class="bi bi-plus-lg"></i> Custom Oda Ekle
            </button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white">
            <h6 class="mb-0">Topluluk Kuralları</h6>
        </div>
        <div class="card-body">
            <form id="rulesForm">
                <div class="mb-2">
                    <textarea class="form-control" name="rules_text" id="rules_text" rows="6" placeholder="Topluluk kurallarını buradan düzenleyin..."></textarea>
                </div>
                <button type="submit" class="btn btn-outline-primary" id="saveRulesBtn">
                    <i class="bi bi-save"></i> Kuralları Kaydet
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="roomsTable">
                    <thead>
                    <tr>
                        <th>Oda Adı</th>
                        <th>Tip</th>
                        <th>Yeterlilik</th>
                        <th>Açıklama</th>
                        <th>Sıra</th>
                        <th>Durum</th>
                        <th>İşlemler</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addCustomRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Custom Oda Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addCustomRoomForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ad *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="sort_order" value="0">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="add_is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="add_is_active">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Oda Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editRoomForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ad *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="sort_order" id="edit_sort_order" value="0">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">Aktif</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/community-rooms.php';
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

    function typeBadge(type) {
        if (type === 'general') return '<span class="badge text-bg-primary">general</span>';
        if (type === 'qualification') return '<span class="badge text-bg-info">qualification</span>';
        return '<span class="badge text-bg-secondary">custom</span>';
    }

    function statusBadge(isActive) {
        return isActive ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
    }

    async function loadRules() {
        const res = await api('get_rules');
        if (!res.success) return;

        const current = (res.data?.rules_text || '').trim();
        const defaultRules = [
            '1) Saygılı ve yapıcı bir iletişim dili kullanın.',
            '2) Link paylaşımı tamamen yasaktır.',
            '3) Spam / flood davranışı yasaktır.',
            '4) Yeterlilik dışı, alakasız içerikleri paylaşmayın.',
            '5) Moderasyon kararlarına uyun.'
        ].join('\n');

        $('#rules_text').val(current || defaultRules);
    }

    async function loadRooms() {
        const res = await api('list');
        const $tbody = $('#roomsTable tbody');
        if (!res.success) {
            $tbody.html('<tr><td colspan="7" class="text-muted p-3">Kayıtlar getirilemedi.</td></tr>');
            return;
        }

        const rows = res.data?.rooms || [];
        if (!rows.length) {
            $tbody.html('<tr><td colspan="7" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        $tbody.html(rows.map((r) => {
            return `<tr>
                <td><strong>${esc(r.name)}</strong>${r.is_system ? ' <small class="text-muted">(system)</small>' : ''}</td>
                <td>${typeBadge(r.type)}</td>
                <td>${esc(r.qualification_name || '-')}</td>
                <td class="text-muted">${esc(r.description || '-')}</td>
                <td>${Number(r.sort_order || 0)}</td>
                <td>${statusBadge(Number(r.is_active) === 1)}</td>
                <td>
                    <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button>
                </td>
            </tr>`;
        }).join(''));
    }

    $('#rulesForm').on('submit', async function (e) {
        e.preventDefault();
        const res = await api('save_rules', 'POST', { rules_text: $('#rules_text').val() || '' });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kurallar kaydedilemedi.', 'error');
            return;
        }
        await appAlert('Başarılı', res.message || 'Kurallar kaydedildi.', 'success');
    });

    $('#addCustomRoomForm').on('submit', async function (e) {
        e.preventDefault();
        const data = $(this).serializeArray();
        const payload = {};
        data.forEach(x => payload[x.name] = x.value);
        if (!payload.is_active) payload.is_active = 0;

        const res = await api('add_custom', 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Custom oda eklenemedi.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('addCustomRoomModal')).hide();
        this.reset();
        $('#add_is_active').prop('checked', true);
        await appAlert('Başarılı', res.message || 'Custom oda eklendi.', 'success');
        await loadRooms();
    });

    $(document).on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const res = await api('get', 'GET', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Oda bilgisi alınamadı.', 'error');
            return;
        }

        const room = res.data?.room || {};
        $('#edit_id').val(room.id || '');
        $('#edit_name').val(room.name || '');
        $('#edit_description').val(room.description || '');
        $('#edit_sort_order').val(Number(room.sort_order || 0));
        $('#edit_is_active').prop('checked', Number(room.is_active || 0) === 1);

        const isSystem = Number(room.is_system || 0) === 1;
        $('#edit_name').prop('readonly', isSystem);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editRoomModal')).show();
    });

    $('#editRoomForm').on('submit', async function (e) {
        e.preventDefault();
        const data = $(this).serializeArray();
        const payload = {};
        data.forEach(x => payload[x.name] = x.value);
        if (!payload.is_active) payload.is_active = 0;

        const res = await api('update', 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Oda güncellenemedi.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editRoomModal')).hide();
        await appAlert('Başarılı', res.message || 'Oda güncellendi.', 'success');
        await loadRooms();
    });

    (async function init() {
        await loadRules();
        await loadRooms();
    })();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

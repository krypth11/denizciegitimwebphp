<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'community-blacklist';
$page_title = 'Blacklist Kelimeler';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Blacklist Kelimeler</h2>
            <p class="text-muted mb-0">Toplulukta engellenecek kelimeleri yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBlacklistModal">
                <i class="bi bi-plus-lg"></i> Kelime Ekle
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="blacklistTable">
                    <thead>
                        <tr>
                            <th>Kelime</th>
                            <th>Eşleşme Tipi</th>
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

<div class="modal fade" id="addBlacklistModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Blacklist Kelime Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addBlacklistForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kelime *</label>
                        <input type="text" class="form-control" name="term" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Eşleşme Tipi</label>
                        <select class="form-select" name="match_type">
                            <option value="contains" selected>contains</option>
                            <option value="exact">exact</option>
                        </select>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="add_bl_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="add_bl_active">Aktif</label>
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

<div class="modal fade" id="editBlacklistModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Blacklist Kelime Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editBlacklistForm">
                <input type="hidden" name="id" id="edit_bl_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kelime *</label>
                        <input type="text" class="form-control" name="term" id="edit_bl_term" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Eşleşme Tipi</label>
                        <select class="form-select" name="match_type" id="edit_bl_match_type">
                            <option value="contains">contains</option>
                            <option value="exact">exact</option>
                        </select>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="edit_bl_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_bl_active">Aktif</label>
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
    const endpoint = '../ajax/community-blacklist.php';
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

    function statusBadge(v) {
        return Number(v) === 1
            ? '<span class="badge text-bg-success">Aktif</span>'
            : '<span class="badge text-bg-secondary">Pasif</span>';
    }

    async function loadTerms() {
        const res = await api('list');
        const $tbody = $('#blacklistTable tbody');

        if (!res.success) {
            $tbody.html('<tr><td colspan="4" class="text-muted p-3">Kayıtlar getirilemedi.</td></tr>');
            return;
        }

        const rows = res.data?.terms || [];
        if (!rows.length) {
            $tbody.html('<tr><td colspan="4" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        $tbody.html(rows.map((r) => `
            <tr>
                <td><strong>${esc(r.term)}</strong></td>
                <td><span class="badge text-bg-info">${esc(r.match_type || 'contains')}</span></td>
                <td>${statusBadge(r.is_active)}</td>
                <td>
                    <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(r.id)}"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        `).join(''));
    }

    $('#addBlacklistForm').on('submit', async function (e) {
        e.preventDefault();
        const payload = {};
        $(this).serializeArray().forEach(x => payload[x.name] = x.value);
        if (!payload.is_active) payload.is_active = 0;

        const res = await api('add', 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kelime eklenemedi.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('addBlacklistModal')).hide();
        this.reset();
        $('#add_bl_active').prop('checked', true);
        await appAlert('Başarılı', res.message || 'Kelime eklendi.', 'success');
        await loadTerms();
    });

    $(document).on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const res = await api('get', 'GET', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt bulunamadı.', 'error');
            return;
        }

        const row = res.data?.term || {};
        $('#edit_bl_id').val(row.id || '');
        $('#edit_bl_term').val(row.term || '');
        $('#edit_bl_match_type').val(row.match_type || 'contains');
        $('#edit_bl_active').prop('checked', Number(row.is_active || 0) === 1);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editBlacklistModal')).show();
    });

    $('#editBlacklistForm').on('submit', async function (e) {
        e.preventDefault();
        const payload = {};
        $(this).serializeArray().forEach(x => payload[x.name] = x.value);
        if (!payload.is_active) payload.is_active = 0;

        const res = await api('update', 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Güncelleme başarısız.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editBlacklistModal')).hide();
        await appAlert('Başarılı', res.message || 'Kayıt güncellendi.', 'success');
        await loadTerms();
    });

    $(document).on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        const ok = await window.showAppConfirm({
            title: 'Silme Onayı',
            message: 'Bu blacklist kelimeyi silmek istediğinize emin misiniz?',
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Kayıt silindi.', 'success');
        await loadTerms();
    });

    loadTerms();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

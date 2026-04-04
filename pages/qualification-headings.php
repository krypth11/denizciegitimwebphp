<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'qualification-headings';
$page_title = 'Yeterlilik Başlıkları';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Yeterlilik Başlıkları</h2>
            <p class="text-muted mb-0">Onboarding için yeterlilik gruplarını yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addHeadingModal">
                <i class="bi bi-plus-lg"></i> Yeni Başlık
            </button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="headingsTable">
                    <thead>
                        <tr>
                            <th>Başlık</th>
                            <th>Sıra</th>
                            <th>Durum</th>
                            <th>Yeterlilik Sayısı</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="5" class="text-muted">Yükleniyor...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addHeadingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Başlık Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addHeadingForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Başlık Adı *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="order_index" value="0">
                    </div>
                    <div class="form-check">
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

<div class="modal fade" id="editHeadingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Başlık Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editHeadingForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Başlık Adı *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="order_index" id="edit_order_index" value="0">
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

<div class="modal fade" id="manageItemsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Başlık Yeterlilikleri</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="manage_heading_id">

                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-7">
                        <label class="form-label">Yeterlilik Ekle</label>
                        <select class="form-select" id="attach_qualification_id">
                            <option value="">Seçiniz...</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" id="attach_order_index" value="0">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="button" class="btn btn-primary" id="attachBtn">Ekle</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Yeterlilik</th>
                                <th style="width:120px;">Sıra</th>
                                <th style="width:90px;">İşlem</th>
                            </tr>
                        </thead>
                        <tbody id="headingItemsBody">
                            <tr><td colspan="3" class="text-muted">Kayıt yok</td></tr>
                        </tbody>
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
    const esc = (v) => $('<div>').text(v ?? '').html();

    async function api(action, method = 'GET', data = {}) {
        return await window.appAjax({
            url: '../ajax/qualification-headings.php?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    }

    async function loadHeadings() {
        const res = await api('list_headings');
        const rows = res.data?.headings || [];
        const $tbody = $('#headingsTable tbody');

        if (!rows.length) {
            $tbody.html('<tr><td colspan="5" class="text-muted">Kayıt bulunamadı.</td></tr>');
            return;
        }

        $tbody.html(rows.map(r => `
            <tr>
                <td><strong>${esc(r.name)}</strong></td>
                <td>
                    <input type="number" class="form-control form-control-sm heading-order" data-id="${esc(r.id)}" value="${Number(r.order_index || 0)}">
                </td>
                <td>${r.is_active ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>'}</td>
                <td>${Number(r.item_count || 0)}</td>
                <td>
                    <div class="table-actions">
                        <button class="btn btn-sm btn-info manage-btn" data-id="${esc(r.id)}"><i class="bi bi-list"></i></button>
                        <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-${r.is_active ? 'secondary' : 'success'} toggle-btn" data-id="${esc(r.id)}" data-active="${r.is_active ? 1 : 0}">${r.is_active ? 'Pasif' : 'Aktif'}</button>
                        <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(r.id)}"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join(''));
    }

    async function loadHeadingDetail(headingId) {
        const res = await api('get_heading_detail', 'GET', { heading_id: headingId });
        if (!res.success) {
            await window.showAppAlert('Hata', res.message || 'Detay getirilemedi.', 'error');
            return null;
        }
        return res.data || null;
    }

    function renderDetail(data) {
        const heading = data.heading || {};
        const available = data.available_qualifications || [];
        const items = heading.items || [];

        $('#manage_heading_id').val(heading.id || '');
        $('.modal-title', '#manageItemsModal').text('Başlık Yeterlilikleri - ' + (heading.name || ''));

        const $select = $('#attach_qualification_id');
        $select.html('<option value="">Seçiniz...</option>');
        available.forEach(q => {
            $select.append(`<option value="${esc(q.id)}">${esc(q.name)}</option>`);
        });

        const $body = $('#headingItemsBody');
        if (!items.length) {
            $body.html('<tr><td colspan="3" class="text-muted">Henüz yeterlilik eklenmemiş.</td></tr>');
            return;
        }

        $body.html(items.map(item => `
            <tr>
                <td>${esc(item.qualification_name)}</td>
                <td>
                    <input type="number" class="form-control form-control-sm item-order"
                        data-heading="${esc(item.heading_id)}"
                        data-qualification="${esc(item.qualification_id)}"
                        value="${Number(item.order_index || 0)}">
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-danger detach-btn"
                        data-heading="${esc(item.heading_id)}"
                        data-qualification="${esc(item.qualification_id)}">
                        Sil
                    </button>
                </td>
            </tr>
        `).join(''));
    }

    $('#addHeadingForm').on('submit', async function (e) {
        e.preventDefault();
        const res = await api('create_heading', 'POST', $(this).serialize());
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Oluşturulamadı.', 'error');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addHeadingModal')).hide();
        this.reset();
        await window.showAppAlert('Başarılı', res.message || 'Başlık oluşturuldu.', 'success');
        await loadHeadings();
    });

    $(document).on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const data = await loadHeadingDetail(id);
        if (!data) return;
        const heading = data.heading || {};

        $('#edit_id').val(heading.id || '');
        $('#edit_name').val(heading.name || '');
        $('#edit_order_index').val(Number(heading.order_index || 0));
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editHeadingModal')).show();
    });

    $('#editHeadingForm').on('submit', async function (e) {
        e.preventDefault();
        const res = await api('update_heading', 'POST', $(this).serialize());
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Güncellenemedi.', 'error');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editHeadingModal')).hide();
        await window.showAppAlert('Başarılı', res.message || 'Güncellendi.', 'success');
        await loadHeadings();
    });

    $(document).on('click', '.toggle-btn', async function () {
        const id = $(this).data('id');
        const current = Number($(this).data('active')) === 1;
        const res = await api('toggle_heading_active', 'POST', { id, is_active: current ? 0 : 1 });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Durum güncellenemedi.', 'error');
        await loadHeadings();
    });

    $(document).on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        const ok = await window.showAppConfirm('Silme Onayı', 'Bu başlığı silmek istediğinizden emin misiniz?', {
            type: 'warning', confirmText: 'Sil', cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_heading', 'POST', { id });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Silinemedi.', 'error');
        await loadHeadings();
    });

    $(document).on('change', '.heading-order', async function () {
        await api('reorder_heading', 'POST', { id: $(this).data('id'), order_index: Number($(this).val() || 0) });
        await loadHeadings();
    });

    $(document).on('click', '.manage-btn', async function () {
        const id = $(this).data('id');
        const data = await loadHeadingDetail(id);
        if (!data) return;
        renderDetail(data);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('manageItemsModal')).show();
    });

    $('#attachBtn').on('click', async function () {
        const heading_id = $('#manage_heading_id').val() || '';
        const qualification_id = $('#attach_qualification_id').val() || '';
        const order_index = Number($('#attach_order_index').val() || 0);

        if (!qualification_id) {
            return window.showAppAlert('Uyarı', 'Lütfen eklenecek yeterliliği seçin.', 'warning');
        }

        const res = await api('attach_qualification', 'POST', { heading_id, qualification_id, order_index });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Ekleme yapılamadı.', 'error');

        const data = await loadHeadingDetail(heading_id);
        if (data) renderDetail(data);
        await loadHeadings();
    });

    $(document).on('click', '.detach-btn', async function () {
        const heading_id = $(this).data('heading');
        const qualification_id = $(this).data('qualification');

        const res = await api('detach_qualification', 'POST', { heading_id, qualification_id });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Kaldırılamadı.', 'error');

        const data = await loadHeadingDetail(heading_id);
        if (data) renderDetail(data);
        await loadHeadings();
    });

    $(document).on('change', '.item-order', async function () {
        const heading_id = $(this).data('heading');
        const qualification_id = $(this).data('qualification');
        const order_index = Number($(this).val() || 0);

        const res = await api('reorder_heading_item', 'POST', { heading_id, qualification_id, order_index });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Sıra güncellenemedi.', 'error');

        const data = await loadHeadingDetail(heading_id);
        if (data) renderDetail(data);
    });

    loadHeadings();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

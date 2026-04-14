<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'kart-game-categories';
$page_title = 'Kart Oyunu - Başlıklar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kart Oyunu - Başlıklar</h2>
            <p class="text-muted mb-0">Kart oyunu kategori başlıklarını yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" id="addCategoryBtn"><i class="bi bi-plus-lg"></i> Yeni Başlık</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="filterSearch" placeholder="Başlık / slug ara...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="filterActive">
                        <option value="">Tümü</option>
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-secondary w-100" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle" id="kgCategoriesTable">
                    <thead>
                    <tr>
                        <th>Başlık</th>
                        <th>Slug</th>
                        <th>Durum</th>
                        <th>Sıra</th>
                        <th>Yeterlilik</th>
                        <th>Soru</th>
                        <th>Oluşturulma</th>
                        <th>İşlemler</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr><td colspan="8" class="text-muted p-3">Yükleniyor...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="kgCategoriesMobile" class="d-md-none"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="kgCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kgCategoryModalTitle">Başlık</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kgCategoryForm">
                <input type="hidden" id="cat_id" name="id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Başlık *</label>
                        <input type="text" class="form-control" name="title" id="cat_title" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" class="form-control" name="slug" id="cat_slug" placeholder="Otomatik üretilebilir">
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="sort_order" id="cat_sort_order" value="0">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="cat_is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="cat_is_active">Aktif</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="kgCategorySaveBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/kart-game-categories.php';
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('kgCategoryModal'));

    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const appConfirm = (title, message, options = {}) => window.showAppConfirm({ title, message, ...options });
    const esc = (v) => $('<div>').text(v ?? '').html();

    let rows = [];

    async function api(action, method = 'GET', data = {}) {
        return await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
    }

    function resetForm() {
        $('#kgCategoryModalTitle').text('Yeni Başlık');
        $('#kgCategoryForm')[0].reset();
        $('#cat_id').val('');
        $('#cat_sort_order').val('0');
        $('#cat_is_active').prop('checked', true);
    }

    function render() {
        const desktop = $('#kgCategoriesTable tbody');
        const mobile = $('#kgCategoriesMobile');
        desktop.empty();
        mobile.empty();

        if (!rows.length) {
            desktop.html('<tr><td colspan="8" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            mobile.html('<div class="text-muted p-2">Kayıt bulunamadı.</div>');
            return;
        }

        rows.forEach((r) => {
            const status = Number(r.is_active) === 1
                ? '<span class="badge text-bg-success">Aktif</span>'
                : '<span class="badge text-bg-secondary">Pasif</span>';

            desktop.append(`
                <tr>
                    <td class="fw-semibold">${esc(r.title)}</td>
                    <td><code>${esc(r.slug)}</code></td>
                    <td>${status}</td>
                    <td>${esc(r.sort_order || 0)}</td>
                    <td>${esc(r.qualification_count || 0)}</td>
                    <td>${esc(r.question_count || 0)}</td>
                    <td><small>${esc(r.created_at || '-')}</small></td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-outline-primary edit-btn" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-btn" data-id="${esc(r.id)}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            mobile.append(`
                <div class="card mb-2">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">${esc(r.title)}</div>
                                <div class="small text-muted"><code>${esc(r.slug)}</code></div>
                            </div>
                            ${status}
                        </div>
                        <div class="small text-muted mt-2">Sıra: ${esc(r.sort_order || 0)} • Yeterlilik: ${esc(r.qualification_count || 0)} • Soru: ${esc(r.question_count || 0)}</div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-sm btn-outline-primary edit-btn" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-outline-danger delete-btn" data-id="${esc(r.id)}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function loadList() {
        const res = await api('list', 'GET', {
            search: $('#filterSearch').val() || '',
            is_active: $('#filterActive').val() || ''
        });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Liste alınamadı.', 'error');
            return;
        }
        rows = res.data?.items || [];
        render();
    }

    $('#addCategoryBtn').on('click', function () {
        resetForm();
        modal.show();
    });

    $('#filterSearch').on('input', function () {
        clearTimeout(window.__kgCatSearchTimer);
        window.__kgCatSearchTimer = setTimeout(loadList, 250);
    });
    $('#filterActive').on('change', loadList);
    $('#clearFiltersBtn').on('click', function () {
        $('#filterSearch').val('');
        $('#filterActive').val('');
        loadList();
    });

    $(document).on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const res = await api('get', 'GET', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt alınamadı.', 'error');
            return;
        }
        const item = res.data?.item;
        if (!item) {
            await appAlert('Hata', 'Kayıt bulunamadı.', 'error');
            return;
        }
        $('#kgCategoryModalTitle').text('Başlık Düzenle');
        $('#cat_id').val(item.id || '');
        $('#cat_title').val(item.title || '');
        $('#cat_slug').val(item.slug || '');
        $('#cat_sort_order').val(item.sort_order || 0);
        $('#cat_is_active').prop('checked', Number(item.is_active) === 1);
        modal.show();
    });

    $('#kgCategoryForm').on('submit', async function (e) {
        e.preventDefault();
        const id = ($('#cat_id').val() || '').trim();
        const action = id ? 'update' : 'create';
        const res = await api(action, 'POST', $(this).serialize());
        if (!res.success) {
            const errs = res.data?.errors || {};
            const msg = Object.values(errs).join('\n') || res.message || 'Kayıt başarısız.';
            await appAlert('Hata', msg, 'error');
            return;
        }
        modal.hide();
        await appAlert('Başarılı', res.message || 'Kaydedildi.', 'success');
        loadList();
    });

    $(document).on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        const infoRes = await api('get', 'GET', { id });
        if (!infoRes.success) {
            await appAlert('Hata', infoRes.message || 'Silme ön bilgisi alınamadı.', 'error');
            return;
        }
        const item = infoRes.data?.item || {};
        const counts = infoRes.data?.relation_counts || {};
        const ok = await appConfirm(
            'Başlığı Sil',
            `"${esc(item.title || 'Başlık')}" silinecek.\nBağlı yeterlilik: ${Number(counts.mapping_count || 0)}\nBağlı soru: ${Number(counts.question_count || 0)}\n\nDevam edilsin mi?`,
            { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' }
        );
        if (!ok) return;

        const res = await api('delete', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme başarısız.', 'error');
            return;
        }
        await appAlert('Başarılı', res.message || 'Silindi.', 'success');
        loadList();
    });

    loadList();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

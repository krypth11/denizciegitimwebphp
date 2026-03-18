<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'maritime-signals';
$page_title = 'İşaretler';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Maritime Signals</h2>
            <p class="text-muted mb-0">Kategori bazlı sinyal içeriklerini yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-primary" id="addCategoryBtn">
                <i class="bi bi-plus-lg"></i> Kategori Ekle
            </button>
            <button class="btn btn-primary" id="addSignalBtn" disabled>
                <i class="bi bi-plus-lg"></i> Yeni Signal Ekle
            </button>
        </div>
    </div>

    <div class="row g-3 maritime-signals-layout">
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <h5 class="mb-0">Kategoriler</h5>
                    <div class="input-group input-group-sm ms-search-wrap">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" id="categorySearch" class="form-control" placeholder="Kategori ara...">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="categoryList" class="ms-list"></div>
                    <div id="categoryEmpty" class="p-3 text-muted">Kategori yükleniyor...</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                        <h5 class="mb-0">Sinyaller</h5>
                        <small class="text-muted" id="selectedCategoryTitle">Kategori seçiniz</small>
                    </div>
                    <div class="input-group input-group-sm ms-search-wrap">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" id="signalSearchInput" class="form-control" placeholder="Signal ara..." disabled>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="signalList" class="ms-list"></div>
                    <div id="signalEmpty" class="p-3 text-muted">Önce bir kategori seçin.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">Kategori Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="categoryForm">
                <input type="hidden" name="id" id="category_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kategori Adı *</label>
                        <input type="text" class="form-control" name="name" id="category_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="description" id="category_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="order_index" id="category_order_index" value="0">
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

<div class="modal fade" id="signalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="signalModalTitle">Yeni Signal Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="signalForm">
                <input type="hidden" name="id" id="signal_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kategori</label>
                            <select class="form-select" name="category_id" id="signal_category_id">
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Signal Adı *</label>
                            <input type="text" class="form-control" name="name" id="signal_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Signal Kodu</label>
                            <input type="text" class="form-control" name="code" id="signal_code">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="order_index" id="signal_order_index" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama / Meaning</label>
                            <textarea class="form-control" name="description" id="signal_description" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Görsel URL (varsa)</label>
                            <input type="text" class="form-control" name="image_url" id="signal_image_url" placeholder="https://...">
                        </div>
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

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/maritime-signals.php';
    let categories = [];
    let signals = [];
    let selectedCategoryId = null;
    let supportsCategory = false;
    let requiresCategory = false;

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const appConfirm = (title, message, options = {}) =>
        window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);

    const escapeHtml = (text) => $('<div>').text(text ?? '').html();

    const api = async (action, method = 'GET', data = {}) => {
        try {
            return await $.ajax({
                url: endpoint + '?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        } catch (xhr) {
            return {
                success: false,
                message: xhr?.responseJSON?.message || 'Sunucu hatası oluştu. Lütfen tekrar deneyin.'
            };
        }
    };

    function renderCategorySelects(selectedId = '') {
        const $form = $('#signal_category_id');

        const currentForm = selectedId || $form.val() || '';
        $form.html('<option value="">Seçiniz...</option>');

        categories.forEach(c => {
            const opt = `<option value="${c.id}">${escapeHtml(c.name)}</option>`;
            $form.append(opt);
        });

        if (currentForm) $form.val(currentForm);

        if (!supportsCategory) {
            $form.prop('disabled', true);
            return;
        }

        $form.prop('disabled', false).prop('required', requiresCategory);
    }

    function renderCategories() {
        const query = ($('#categorySearch').val() || '').toLowerCase().trim();
        const list = categories.filter(c => {
            const hay = `${c.name || ''} ${c.description || ''}`.toLowerCase();
            return !query || hay.includes(query);
        });

        const $list = $('#categoryList');
        $list.empty();

        if (!supportsCategory) {
            $('#categoryEmpty').removeClass('d-none').text('Kategori yapısı bu veritabanında desteklenmiyor.');
            $('#addCategoryBtn').prop('disabled', true);
            $('#addSignalBtn').prop('disabled', false);
            return;
        }

        $('#addCategoryBtn').prop('disabled', false);
        $('#addSignalBtn').prop('disabled', !selectedCategoryId);

        if (!list.length) {
            $('#categoryEmpty').removeClass('d-none').text(categories.length ? 'Aramaya uygun kategori yok.' : 'Henüz kategori yok.');
            return;
        }

        $('#categoryEmpty').addClass('d-none');

        list.forEach(c => {
            const activeClass = c.id === selectedCategoryId ? 'active' : '';
            $list.append(`
                <div class="ms-list-item ${activeClass}">
                    <div class="ms-list-main" data-role="select-category" data-id="${c.id}">
                        <h6 class="mb-1">${escapeHtml(c.name)}</h6>
                        <div class="small text-muted">${escapeHtml(c.description || '-')}</div>
                        <div class="small mt-1">
                            <span class="badge bg-light text-dark">Sıra: ${c.order_index ?? 0}</span>
                            <span class="badge bg-primary-subtle text-primary-emphasis">Signal: ${c.signal_count ?? 0}</span>
                        </div>
                    </div>
                    <div class="ms-list-actions">
                        <button class="btn btn-sm btn-warning" data-role="edit-category" data-id="${c.id}"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-danger" data-role="delete-category" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            `);
        });
    }

    function renderSignals() {
        const $list = $('#signalList');
        $list.empty();

        if (supportsCategory && !selectedCategoryId) {
            $('#signalEmpty').removeClass('d-none').text('Önce bir kategori seçin.');
            $('#signalSearchInput').prop('disabled', true).val('');
            $('#selectedCategoryTitle').text('Kategori seçiniz');
            return;
        }

        $('#signalSearchInput').prop('disabled', false);

        if (supportsCategory) {
            const category = categories.find(c => c.id === selectedCategoryId);
            $('#selectedCategoryTitle').text(category ? `${category.name} kategorisine ait sinyaller` : 'Kategori');
        } else {
            $('#selectedCategoryTitle').text('Tüm sinyaller');
        }

        if (!signals.length) {
            $('#signalEmpty').removeClass('d-none').text('Bu kategori için sinyal bulunamadı.');
            return;
        }

        $('#signalEmpty').addClass('d-none');

        signals.forEach(s => {
            const imageCell = s.image_url
                ? `<img src="${escapeHtml(s.image_url)}" alt="signal" class="signal-thumb">`
                : '';

            $list.append(`
                <div class="ms-list-item signal-item">
                    <div class="ms-list-main">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h6 class="mb-1">${escapeHtml(s.name)}</h6>
                                <div class="small text-muted">${escapeHtml(s.category_name || '-')}</div>
                            </div>
                            <span class="badge bg-secondary">#${s.order_index ?? 0}</span>
                        </div>
                        <div class="small mt-2"><strong>Kod:</strong> ${escapeHtml(s.code || '-')}</div>
                        <div class="small text-muted mt-1 signal-desc-clamp">${escapeHtml(s.description || '-')}</div>
                        ${imageCell ? `<div class="mt-2">${imageCell}</div>` : ''}
                    </div>
                    <div class="ms-list-actions">
                        <button class="btn btn-sm btn-warning edit-signal-btn" data-id="${s.id}"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-danger delete-signal-btn" data-id="${s.id}"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            `);
        });
    }

    async function loadCategories(autoSelect = true) {
        const res = await api('list_categories');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kategoriler yüklenemedi.', 'error');
            return;
        }
        categories = res.data?.categories || [];
        supportsCategory = !!res.data?.supports_category;
        requiresCategory = !!res.data?.requires_category;

        if (supportsCategory) {
            if (!selectedCategoryId || !categories.some(c => c.id === selectedCategoryId)) {
                selectedCategoryId = autoSelect && categories.length ? categories[0].id : null;
            }
        } else {
            selectedCategoryId = null;
        }

        renderCategorySelects(selectedCategoryId);
        renderCategories();
    }

    async function loadSignals() {
        const search = ($('#signalSearchInput').val() || '').trim();
        const categoryId = supportsCategory ? (selectedCategoryId || '') : '';

        const res = await api('list_signals', 'GET', { category_id: categoryId, search });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Signal listesi yüklenemedi.', 'error');
            return;
        }

        signals = res.data?.signals || [];
        renderSignals();
    }

    function openSignalModal(mode, item = null) {
        $('#signalForm')[0].reset();
        $('#signal_id').val('');
        $('#signal_order_index').val('0');
        renderCategorySelects(selectedCategoryId);

        if (mode === 'edit' && item) {
            $('#signalModalTitle').text('Signal Düzenle');
            $('#signal_id').val(item.id);
            $('#signal_name').val(item.name || '');
            $('#signal_code').val(item.code || '');
            $('#signal_description').val(item.description || '');
            $('#signal_image_url').val(item.image_url || '');
            $('#signal_order_index').val(item.order_index ?? 0);
            if (supportsCategory) {
                $('#signal_category_id').val(item.category_id || '');
            }
        } else {
            $('#signalModalTitle').text('Yeni Signal Ekle');
            if (supportsCategory) {
                if (selectedCategoryId) $('#signal_category_id').val(selectedCategoryId);
            }
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('signalModal')).show();
    }

    function openCategoryModal(mode, item = null) {
        $('#categoryForm')[0].reset();
        $('#category_id').val('');
        $('#category_order_index').val('0');

        if (mode === 'edit' && item) {
            $('#categoryModalTitle').text('Kategori Düzenle');
            $('#category_id').val(item.id);
            $('#category_name').val(item.name || '');
            $('#category_description').val(item.description || '');
            $('#category_order_index').val(item.order_index ?? 0);
        } else {
            $('#categoryModalTitle').text('Kategori Ekle');
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal')).show();
    }

    $('#addCategoryBtn').on('click', function () {
        openCategoryModal('add');
    });

    $('#addSignalBtn').on('click', async function () {
        if (supportsCategory && !selectedCategoryId) {
            await appAlert('Uyarı', 'Önce bir kategori seçin.', 'warning');
            return;
        }
        openSignalModal('add');
    });

    $('#categorySearch').on('input', renderCategories);
    $('#signalSearchInput').on('input', loadSignals);

    $('#categoryList').on('click', '[data-role="select-category"]', async function () {
        selectedCategoryId = $(this).data('id');
        renderCategories();
        await loadSignals();
    });

    $('#categoryList').on('click', '[data-role="edit-category"]', function () {
        const id = $(this).data('id');
        const item = categories.find(c => c.id === id);
        if (item) openCategoryModal('edit', item);
    });

    $('#categoryList').on('click', '[data-role="delete-category"]', async function () {
        const id = $(this).data('id');
        const item = categories.find(c => c.id === id);
        if (!item) return;

        const ok = await appConfirm('Kategori Sil', `"${item.name}" kategorisini silmek istediğinizden emin misiniz?`, {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_category', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme işlemi başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Kategori silindi.', 'success');
        await loadCategories(true);
        await loadSignals();
    });

    $(document).on('click', '.edit-signal-btn', function () {
        const id = $(this).data('id');
        const item = signals.find(s => s.id === id);
        if (!item) return;
        openSignalModal('edit', item);
    });

    $(document).on('click', '.delete-signal-btn', async function () {
        const id = $(this).data('id');
        const item = signals.find(s => s.id === id);
        if (!item) return;

        const ok = await appConfirm('Signal Sil', `"${item.name}" kaydını silmek istediğinizden emin misiniz?`, {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_signal', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme işlemi başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Signal silindi.', 'success');
        await loadSignals();
    });

    $('#signalForm').on('submit', async function (e) {
        e.preventDefault();

        if (requiresCategory && !$('#signal_category_id').val()) {
            await appAlert('Uyarı', 'Kategori seçimi zorunludur.', 'warning');
            return;
        }

        const isEdit = !!$('#signal_id').val();
        const action = isEdit ? 'update_signal' : 'add_signal';
        const res = await api(action, 'POST', $(this).serialize());

        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('signalModal')).hide();
        await appAlert('Başarılı', res.message || 'Signal kaydedildi.', 'success');
        await loadCategories(false);
        await loadSignals();
    });

    $('#categoryForm').on('submit', async function (e) {
        e.preventDefault();

        const isEdit = !!$('#category_id').val();
        const action = isEdit ? 'update_category' : 'add_category';
        const res = await api(action, 'POST', $(this).serialize());

        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal')).hide();
        await appAlert('Başarılı', res.message || 'Kategori kaydedildi.', 'success');
        await loadCategories(true);
        await loadSignals();
    });

    (async function init() {
        await loadCategories(true);
        await loadSignals();
    })();
});
</script>

<style>
.ms-list { max-height: 68vh; overflow: auto; }
.ms-list-item {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 14px;
    border-bottom: 1px solid #f0f2f5;
}
.ms-list-item.active { background: #eef5ff; }
.ms-list-main { flex: 1; min-width: 0; }
.ms-list-main[data-role="select-category"] { cursor: pointer; }
.ms-list-main h6 { word-break: break-word; }
.ms-list-actions { display: flex; gap: 6px; align-items: flex-start; }
.ms-search-wrap { width: 240px; }

.signal-thumb {
    width: 56px;
    height: 56px;
    object-fit: contain;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: #fff;
}

.signal-desc-clamp {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

@media (max-width: 991.98px) {
    .ms-search-wrap { width: 100%; }
    .ms-list { max-height: none; }
    .ms-list-item { flex-direction: column; }
    .ms-list-actions { justify-content: flex-end; }
    .signal-item .ms-list-actions {
        flex-direction: row;
        width: 100%;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

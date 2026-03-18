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
            <p class="text-muted mb-0">Denizcilik işaretlerini kategoriye göre yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" id="addSignalBtn">
                <i class="bi bi-plus-lg"></i> Yeni Signal Ekle
            </button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Kategori</label>
                    <select id="signalCategoryFilter" class="form-select">
                        <option value="">Tüm kategoriler</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Arama</label>
                    <input type="search" id="signalSearchInput" class="form-control" placeholder="Ad, kod veya açıklama ara...">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary w-100" id="applySignalFiltersBtn"><i class="bi bi-funnel"></i> Filtrele</button>
                    <button class="btn btn-secondary" id="resetSignalFiltersBtn" title="Temizle"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="signalsTable">
                    <thead>
                        <tr>
                            <th>Signal</th>
                            <th>Kategori</th>
                            <th>Kod</th>
                            <th>Açıklama</th>
                            <th>Görsel</th>
                            <th>Sıra</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="signalsTableBody"></tbody>
                </table>
                <div id="signalsDesktopEmpty" class="text-muted p-2 d-none">Kayıt bulunamadı.</div>
            </div>
        </div>
    </div>

    <div class="d-md-none" id="signalsMobileList"></div>
    <div id="signalsMobileEmpty" class="alert alert-light text-muted d-none mt-2">Kayıt bulunamadı.</div>
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
    let supportsCategory = false;
    let requiresCategory = false;

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const appConfirm = (title, message, options = {}) =>
        window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);

    const escapeHtml = (text) => $('<div>').text(text ?? '').html();

    const api = (action, method = 'GET', data = {}) => {
        return $.ajax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    };

    function renderCategorySelects() {
        const $filter = $('#signalCategoryFilter');
        const $form = $('#signal_category_id');

        const currentFilter = $filter.val() || '';
        const currentForm = $form.val() || '';

        $filter.html('<option value="">Tüm kategoriler</option>');
        $form.html('<option value="">Seçiniz...</option>');

        categories.forEach(c => {
            const opt = `<option value="${c.id}">${escapeHtml(c.name)}</option>`;
            $filter.append(opt);
            $form.append(opt);
        });

        if (currentFilter) $filter.val(currentFilter);
        if (currentForm) $form.val(currentForm);

        if (!supportsCategory) {
            $filter.prop('disabled', true);
            $form.prop('disabled', true);
            return;
        }

        $filter.prop('disabled', false);
        $form.prop('disabled', false).prop('required', requiresCategory);
    }

    function renderSignals() {
        const $tbody = $('#signalsTableBody');
        const $mobile = $('#signalsMobileList');
        $tbody.empty();
        $mobile.empty();

        const empty = !signals.length;
        $('#signalsDesktopEmpty').toggleClass('d-none', !empty);
        $('#signalsMobileEmpty').toggleClass('d-none', !empty);

        signals.forEach(s => {
            const imageCell = s.image_url
                ? `<img src="${escapeHtml(s.image_url)}" alt="signal" class="signal-thumb">`
                : '<span class="text-muted">-</span>';

            $tbody.append(`
                <tr>
                    <td><strong>${escapeHtml(s.name)}</strong></td>
                    <td>${escapeHtml(s.category_name || '-')}</td>
                    <td>${escapeHtml(s.code || '-')}</td>
                    <td><div class="text-muted signal-desc-clamp">${escapeHtml(s.description || '-')}</div></td>
                    <td>${imageCell}</td>
                    <td>${s.order_index ?? 0}</td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-warning edit-signal-btn" data-id="${s.id}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger delete-signal-btn" data-id="${s.id}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            $mobile.append(`
                <div class="card signal-mobile-card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <h6 class="mb-1">${escapeHtml(s.name)}</h6>
                                <div class="small text-muted">${escapeHtml(s.category_name || '-')}</div>
                            </div>
                            <span class="badge bg-secondary">#${s.order_index ?? 0}</span>
                        </div>
                        <div class="small mt-2"><strong>Kod:</strong> ${escapeHtml(s.code || '-')}</div>
                        <div class="small text-muted mt-1">${escapeHtml(s.description || '-')}</div>
                        ${s.image_url ? `<img src="${escapeHtml(s.image_url)}" alt="signal" class="signal-thumb mt-2">` : ''}
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button class="btn btn-sm btn-warning edit-signal-btn" data-id="${s.id}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-signal-btn" data-id="${s.id}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function loadCategories() {
        const res = await api('list_categories');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kategoriler yüklenemedi.', 'error');
            return;
        }
        categories = res.data?.categories || [];
        supportsCategory = !!res.data?.supports_category;
        requiresCategory = !!res.data?.requires_category;
        renderCategorySelects();
    }

    async function loadSignals() {
        const categoryId = $('#signalCategoryFilter').val() || '';
        const search = ($('#signalSearchInput').val() || '').trim();

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
        renderCategorySelects();

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
                const selectedFilter = $('#signalCategoryFilter').val();
                if (selectedFilter) $('#signal_category_id').val(selectedFilter);
            }
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('signalModal')).show();
    }

    $('#addSignalBtn').on('click', function () {
        openSignalModal('add');
    });

    $('#applySignalFiltersBtn').on('click', loadSignals);
    $('#signalSearchInput').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            loadSignals();
        }
    });

    $('#resetSignalFiltersBtn').on('click', function () {
        $('#signalCategoryFilter').val('');
        $('#signalSearchInput').val('');
        loadSignals();
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
        await loadSignals();
    });

    (async function init() {
        await loadCategories();
        await loadSignals();
    })();
});
</script>

<style>
.signal-thumb {
    width: 56px;
    height: 56px;
    object-fit: contain;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: #fff;
}

.signal-desc-clamp {
    max-width: 280px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.signal-mobile-card .signal-thumb {
    width: 64px;
    height: 64px;
}

@media (max-width: 767.98px) {
    .signal-mobile-card .card-body {
        padding: 14px;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

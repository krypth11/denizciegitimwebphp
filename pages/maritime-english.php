<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'maritime-english';
$page_title = 'Maritime English';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Maritime English</h2>
            <p class="text-muted mb-0">Kategori ve topic içeriklerini yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-primary" id="addCategoryBtn">
                <i class="bi bi-plus-lg"></i> Kategori Ekle
            </button>
            <button class="btn btn-primary" id="addTopicBtn" disabled>
                <i class="bi bi-plus-lg"></i> Topic Ekle
            </button>
        </div>
    </div>

    <div class="row g-3 maritime-english-layout">
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <h5 class="mb-0">Kategoriler</h5>
                    <div class="input-group input-group-sm me-search-wrap">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" id="categorySearch" class="form-control" placeholder="Kategori ara...">
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="categoryList" class="me-list"></div>
                    <div id="categoryEmpty" class="p-3 text-muted d-none">Henüz kategori yok.</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                        <h5 class="mb-0">Topicler</h5>
                        <small class="text-muted" id="topicSelectedCategoryText">Kategori seçiniz</small>
                    </div>
                    <div class="input-group input-group-sm me-search-wrap">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="search" id="topicSearch" class="form-control" placeholder="Topic ara..." disabled>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="topicList" class="me-list"></div>
                    <div id="topicEmpty" class="p-3 text-muted">Önce bir kategori seçin.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
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

<!-- Topic Modal -->
<div class="modal fade" id="topicModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="topicModalTitle">Topic Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="topicForm">
                <input type="hidden" name="id" id="topic_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kategori *</label>
                        <select class="form-select" name="category_id" id="topic_category_id" required></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Topic Adı *</label>
                        <input type="text" class="form-control" name="name" id="topic_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama / İçerik</label>
                        <textarea class="form-control" name="description" id="topic_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="order_index" id="topic_order_index" value="0">
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
    const endpoint = '../ajax/maritime-english.php';
    let categories = [];
    let topics = [];
    let selectedCategoryId = null;

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

    function renderCategoryOptions(selectedId = '') {
        const $select = $('#topic_category_id');
        $select.empty();
        $select.append('<option value="">Seçiniz...</option>');
        categories.forEach(c => {
            $select.append(`<option value="${c.id}">${escapeHtml(c.name)}</option>`);
        });
        if (selectedId) $select.val(selectedId);
    }

    function renderCategories() {
        const query = ($('#categorySearch').val() || '').toLowerCase().trim();
        const list = categories.filter(c => {
            const hay = `${c.name || ''} ${c.description || ''}`.toLowerCase();
            return !query || hay.includes(query);
        });

        const $list = $('#categoryList');
        $list.empty();

        if (!list.length) {
            $('#categoryEmpty').removeClass('d-none').text(categories.length ? 'Aramaya uygun kategori yok.' : 'Henüz kategori yok.');
            return;
        }

        $('#categoryEmpty').addClass('d-none');

        list.forEach(c => {
            const activeClass = c.id === selectedCategoryId ? 'active' : '';
            $list.append(`
                <div class="me-list-item ${activeClass}" data-id="${c.id}">
                    <div class="me-list-main" data-role="select-category" data-id="${c.id}">
                        <h6 class="mb-1">${escapeHtml(c.name)}</h6>
                        <div class="small text-muted">${escapeHtml(c.description || '-')}</div>
                        <div class="small mt-1">
                            <span class="badge bg-light text-dark">Sıra: ${c.order_index ?? 0}</span>
                            <span class="badge bg-primary-subtle text-primary-emphasis">Topic: ${c.topic_count ?? 0}</span>
                        </div>
                    </div>
                    <div class="me-list-actions">
                        <button class="btn btn-sm btn-warning" data-role="edit-category" data-id="${c.id}"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-danger" data-role="delete-category" data-id="${c.id}"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            `);
        });
    }

    function renderTopics() {
        const $list = $('#topicList');
        $list.empty();

        if (!selectedCategoryId) {
            $('#topicEmpty').removeClass('d-none').text('Önce bir kategori seçin.');
            $('#topicSearch').prop('disabled', true).val('');
            $('#addTopicBtn').prop('disabled', true);
            $('#topicSelectedCategoryText').text('Kategori seçiniz');
            return;
        }

        $('#topicSearch').prop('disabled', false);
        $('#addTopicBtn').prop('disabled', false);

        const category = categories.find(c => c.id === selectedCategoryId);
        $('#topicSelectedCategoryText').text(category ? `${category.name} kategorisi` : 'Kategori');

        const query = ($('#topicSearch').val() || '').toLowerCase().trim();
        const list = topics.filter(t => {
            if (t.category_id !== selectedCategoryId) return false;
            const hay = `${t.name || ''} ${t.description || ''}`.toLowerCase();
            return !query || hay.includes(query);
        });

        if (!list.length) {
            $('#topicEmpty').removeClass('d-none').text('Bu kategori için topic bulunamadı.');
            return;
        }

        $('#topicEmpty').addClass('d-none');

        list.forEach(t => {
            $list.append(`
                <div class="me-list-item" data-id="${t.id}">
                    <div class="me-list-main">
                        <h6 class="mb-1">${escapeHtml(t.name)}</h6>
                        <div class="small text-muted">${escapeHtml(t.description || '-')}</div>
                        <div class="small mt-1">
                            <span class="badge bg-light text-dark">Sıra: ${t.order_index ?? 0}</span>
                            <span class="badge bg-secondary">${escapeHtml(t.category_name || '')}</span>
                        </div>
                    </div>
                    <div class="me-list-actions">
                        <button class="btn btn-sm btn-warning" data-role="edit-topic" data-id="${t.id}"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-danger" data-role="delete-topic" data-id="${t.id}"><i class="bi bi-trash"></i></button>
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
        renderCategoryOptions(selectedCategoryId);

        if (!selectedCategoryId || !categories.some(c => c.id === selectedCategoryId)) {
            selectedCategoryId = autoSelect && categories.length ? categories[0].id : null;
        }

        renderCategories();
        await loadTopics();
    }

    async function loadTopics() {
        const res = await api('list_topics');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Topicler yüklenemedi.', 'error');
            return;
        }
        topics = res.data?.topics || [];
        renderTopics();
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

    function openTopicModal(mode, item = null) {
        if (!categories.length) {
            appAlert('Uyarı', 'Önce kategori eklemelisiniz.', 'warning');
            return;
        }

        $('#topicForm')[0].reset();
        $('#topic_id').val('');
        $('#topic_order_index').val('0');
        renderCategoryOptions(selectedCategoryId);

        if (mode === 'edit' && item) {
            $('#topicModalTitle').text('Topic Düzenle');
            $('#topic_id').val(item.id);
            $('#topic_name').val(item.name || '');
            $('#topic_description').val(item.description || '');
            $('#topic_order_index').val(item.order_index ?? 0);
            $('#topic_category_id').val(item.category_id || selectedCategoryId || '');
        } else {
            $('#topicModalTitle').text('Topic Ekle');
            $('#topic_category_id').val(selectedCategoryId || '');
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('topicModal')).show();
    }

    $('#addCategoryBtn').on('click', () => openCategoryModal('add'));
    $('#addTopicBtn').on('click', () => openTopicModal('add'));
    $('#categorySearch').on('input', renderCategories);
    $('#topicSearch').on('input', renderTopics);

    $('#categoryList').on('click', '[data-role="select-category"]', function () {
        selectedCategoryId = $(this).data('id');
        renderCategories();
        renderTopics();
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
    });

    $('#topicList').on('click', '[data-role="edit-topic"]', function () {
        const id = $(this).data('id');
        const item = topics.find(t => t.id === id);
        if (item) openTopicModal('edit', item);
    });

    $('#topicList').on('click', '[data-role="delete-topic"]', async function () {
        const id = $(this).data('id');
        const item = topics.find(t => t.id === id);
        if (!item) return;

        const ok = await appConfirm('Topic Sil', `"${item.name}" topicini silmek istediğinizden emin misiniz?`, {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_topic', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme işlemi başarısız.', 'error');
            return;
        }
        await appAlert('Başarılı', res.message || 'Topic silindi.', 'success');
        await loadTopics();
        await loadCategories(false);
    });

    $('#categoryForm').on('submit', async function (e) {
        e.preventDefault();
        const formData = $(this).serialize();
        const isEdit = !!$('#category_id').val();
        const action = isEdit ? 'update_category' : 'add_category';
        const res = await api(action, 'POST', formData);

        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal')).hide();
        await appAlert('Başarılı', res.message || 'Kategori kaydedildi.', 'success');
        await loadCategories(true);
    });

    $('#topicForm').on('submit', async function (e) {
        e.preventDefault();
        const formData = $(this).serialize();
        const isEdit = !!$('#topic_id').val();
        const action = isEdit ? 'update_topic' : 'add_topic';
        const res = await api(action, 'POST', formData);

        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        const categoryId = $('#topic_category_id').val();
        if (categoryId) selectedCategoryId = categoryId;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('topicModal')).hide();
        await appAlert('Başarılı', res.message || 'Topic kaydedildi.', 'success');
        await loadTopics();
        await loadCategories(false);
    });

    loadCategories(true);
});
</script>

<style>
.me-list { max-height: 68vh; overflow: auto; }
.me-list-item {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 14px;
    border-bottom: 1px solid #f0f2f5;
}
.me-list-item.active { background: #eef5ff; }
.me-list-main { flex: 1; min-width: 0; }
.me-list-main[data-role="select-category"] { cursor: pointer; }
.me-list-main h6 { word-break: break-word; }
.me-list-actions { display: flex; gap: 6px; align-items: flex-start; }
.me-search-wrap { width: 220px; }

@media (max-width: 991.98px) {
    .maritime-english-layout .card { min-height: auto; }
    .me-list { max-height: none; }
    .me-search-wrap { width: 100%; }
    .me-list-item { flex-direction: column; }
    .me-list-actions { justify-content: flex-end; }
}
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

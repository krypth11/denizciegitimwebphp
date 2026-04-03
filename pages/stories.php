<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/story_helper.php';

$user = require_auth();
$current_page = 'stories';
$page_title = 'Dashboard Hikayeleri';

story_ensure_schema($pdo);

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Dashboard Hikayeleri</h2>
            <p class="text-muted mb-0">Mobil dashboard story içeriklerini yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-secondary" id="refreshStoriesBtn"><i class="bi bi-arrow-clockwise"></i> Yenile</button>
            <button class="btn btn-primary" id="addStoryBtn"><i class="bi bi-plus-lg"></i> Yeni Hikaye Ekle</button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Thumbnail</th>
                            <th>Hikaye Adı</th>
                            <th>Story Görseli</th>
                            <th>Durum</th>
                            <th>Oluşturulma</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="storiesTableBody"></tbody>
                </table>
            </div>

            <div class="d-md-none" id="storiesMobileList"></div>
            <div class="text-muted p-2 d-none" id="storiesEmpty">Henüz hikaye yok.</div>
        </div>
    </div>
</div>

<div class="modal fade" id="storyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Hikaye Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="storyForm" enctype="multipart/form-data" autocomplete="off">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Hikaye Adı *</label>
                            <input type="text" class="form-control" name="title" id="storyTitle" maxlength="191" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Thumbnail Görseli *</label>
                            <input type="file" class="form-control" name="thumbnail" id="storyThumbnail" accept="image/jpeg,image/png,image/webp" required>
                            <small class="text-muted">JPG, PNG, WEBP - max 5MB</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Story Görseli *</label>
                            <input type="file" class="form-control" name="image" id="storyImage" accept="image/jpeg,image/png,image/webp" required>
                            <small class="text-muted">JPG, PNG, WEBP - max 5MB</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="saveStoryBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/stories.php';
    let stories = [];

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const appConfirm = (title, message, options = {}) =>
        window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);

    const esc = (txt) => $('<div>').text(txt ?? '').html();

    function formatDateSafe(value) {
        if (!value) return '-';
        if (typeof window.formatDate === 'function') return window.formatDate(value);
        return value;
    }

    async function api(action, method = 'GET', data = null, useFormData = false) {
        const options = {
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            dataType: 'json'
        };

        if (useFormData) {
            options.data = data;
            options.processData = false;
            options.contentType = false;
        } else {
            options.data = data || {};
        }

        try {
            return await $.ajax(options);
        } catch (xhr) {
            return {
                success: false,
                message: xhr?.responseJSON?.message || 'Sunucu hatası oluştu.'
            };
        }
    }

    function render() {
        const $tb = $('#storiesTableBody');
        const $mobile = $('#storiesMobileList');
        $tb.empty();
        $mobile.empty();

        const empty = stories.length === 0;
        $('#storiesEmpty').toggleClass('d-none', !empty);
        if (empty) return;

        stories.forEach((s) => {
            const activeBadge = s.is_active === 1
                ? '<span class="badge text-bg-success">Aktif</span>'
                : '<span class="badge text-bg-secondary">Pasif</span>';

            const toggleLabel = s.is_active === 1 ? 'Pasife Al' : 'Aktif Et';
            const toggleClass = s.is_active === 1 ? 'btn-outline-secondary' : 'btn-outline-success';
            const nextStatus = s.is_active === 1 ? 0 : 1;

            $tb.append(`
                <tr>
                    <td><img src="${esc(s.thumbnail_url || '')}" alt="thumb" style="width:56px;height:56px;object-fit:cover;border-radius:8px;"></td>
                    <td class="fw-semibold">${esc(s.title || '')}</td>
                    <td><a href="${esc(s.image_url || '#')}" target="_blank" rel="noopener">Görseli Aç</a></td>
                    <td>${activeBadge}</td>
                    <td><small class="text-muted">${formatDateSafe(s.created_at)}</small></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-2">
                            <button class="btn btn-sm ${toggleClass} toggle-btn" data-id="${esc(s.id)}" data-next="${nextStatus}">${toggleLabel}</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(s.id)}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            $mobile.append(`
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex gap-3">
                            <img src="${esc(s.thumbnail_url || '')}" alt="thumb" style="width:64px;height:64px;object-fit:cover;border-radius:10px;">
                            <div class="flex-grow-1">
                                <div class="fw-semibold">${esc(s.title || '')}</div>
                                <div class="small text-muted mt-1">${formatDateSafe(s.created_at)}</div>
                                <div class="mt-2">${activeBadge}</div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-sm ${toggleClass} toggle-btn" data-id="${esc(s.id)}" data-next="${nextStatus}">${toggleLabel}</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(s.id)}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function loadStories() {
        const res = await api('list', 'GET');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Hikayeler alınamadı.', 'error');
            return;
        }
        stories = res.data?.stories || [];
        render();
    }

    $('#addStoryBtn').on('click', function () {
        $('#storyForm')[0].reset();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('storyModal')).show();
    });

    $('#refreshStoriesBtn').on('click', loadStories);

    $('#storyForm').on('submit', async function (e) {
        e.preventDefault();

        const title = ($('#storyTitle').val() || '').trim();
        if (!title) {
            await appAlert('Uyarı', 'Hikaye adı zorunludur.', 'warning');
            return;
        }

        const thumb = $('#storyThumbnail')[0].files?.[0];
        const image = $('#storyImage')[0].files?.[0];
        if (!thumb || !image) {
            await appAlert('Uyarı', 'Thumbnail ve story görseli zorunludur.', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('title', title);
        fd.append('thumbnail', thumb);
        fd.append('image', image);

        const $btn = $('#saveStoryBtn');
        if (typeof window.appSetButtonLoading === 'function') window.appSetButtonLoading($btn, true, 'Kaydediliyor...');
        const res = await api('create', 'POST', fd, true);
        if (typeof window.appSetButtonLoading === 'function') window.appSetButtonLoading($btn, false);

        if (!res.success) {
            const baseMessage = res.message || 'Hikaye oluşturulamadı.';
            const debugError = (res.data && (res.data.error || res.data.type))
                ? `\n\nTeknik Detay: ${res.data.error || ''}${res.data.type ? ` (${res.data.type})` : ''}`
                : '';
            await appAlert('Hata', baseMessage + debugError, 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('storyModal')).hide();
        await appAlert('Başarılı', res.message || 'Hikaye oluşturuldu.', 'success');
        await loadStories();
    });

    $(document).on('click', '.toggle-btn', async function () {
        const storyId = $(this).data('id');
        const next = parseInt($(this).data('next'), 10);
        const res = await api('toggle_active', 'POST', { story_id: storyId, is_active: next });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Durum güncellenemedi.', 'error');
            return;
        }
        await loadStories();
    });

    $(document).on('click', '.delete-btn', async function () {
        const storyId = $(this).data('id');
        const story = stories.find(x => String(x.id) === String(storyId));
        const ok = await appConfirm(
            'Hikayeyi Sil',
            `"${esc(story?.title || 'Bu hikaye')}" kaydı silinecek. Onaylıyor musunuz?`,
            { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' }
        );
        if (!ok) return;

        const res = await api('delete', 'POST', { story_id: storyId });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Hikaye silinemedi.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Hikaye silindi.', 'success');
        await loadStories();
    });

    loadStories();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

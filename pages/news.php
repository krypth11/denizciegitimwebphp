<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/news_helper.php';

$user = require_auth();
$current_page = 'news';
$page_title = 'Denizcilik Haberleri';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Denizcilik Haberleri</h2>
            <p class="text-muted mb-0">Haber kaynaklarını yönetin, gelen haberleri onaylayın/yayınlayın.</p>
        </div>
        <div class="ms-auto">
            <button class="btn btn-primary" id="fetchNowBtn"><i class="bi bi-arrow-repeat"></i> Şimdi Haber Çek</button>
        </div>
    </div>

    <ul class="nav nav-tabs" id="newsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabPending" type="button">Onay Bekleyenler</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabApproved" type="button">Yayında</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRejected" type="button">Reddedilenler</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSources" type="button">Kaynaklar</button>
        </li>
    </ul>

    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="tabPending">
            <div class="card"><div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Başlık / Özet</th>
                            <th>Kaynak</th>
                            <th>Kategori</th>
                            <th>Yayın Tarihi</th>
                            <th>Görsel</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                        </thead>
                        <tbody id="pendingArticlesBody"></tbody>
                    </table>
                </div>
                <div id="pendingEmpty" class="text-muted d-none">Bekleyen haber bulunamadı.</div>
            </div></div>
        </div>

        <div class="tab-pane fade" id="tabApproved">
            <div class="card"><div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Başlık / Özet</th>
                            <th>Kaynak</th>
                            <th>Kategori</th>
                            <th>Yayın Tarihi</th>
                            <th>Görsel</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                        </thead>
                        <tbody id="approvedArticlesBody"></tbody>
                    </table>
                </div>
                <div id="approvedEmpty" class="text-muted d-none">Yayındaki haber bulunamadı.</div>
            </div></div>
        </div>

        <div class="tab-pane fade" id="tabRejected">
            <div class="card"><div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Başlık / Özet</th>
                            <th>Kaynak</th>
                            <th>Kategori</th>
                            <th>Yayın Tarihi</th>
                            <th>Görsel</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                        </thead>
                        <tbody id="rejectedArticlesBody"></tbody>
                    </table>
                </div>
                <div id="rejectedEmpty" class="text-muted d-none">Reddedilmiş haber bulunamadı.</div>
            </div></div>
        </div>

        <div class="tab-pane fade" id="tabSources">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-primary" id="addSourceBtn"><i class="bi bi-plus-lg"></i> Kaynak Ekle</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Kaynak Adı</th>
                            <th>RSS URL</th>
                            <th>Kategori</th>
                            <th>Dil</th>
                            <th>Durum</th>
                            <th>Son Çekilme</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                        </thead>
                        <tbody id="sourcesBody"></tbody>
                    </table>
                </div>
                <div id="sourcesEmpty" class="text-muted d-none">Kayıtlı kaynak bulunamadı.</div>
            </div></div>
        </div>
    </div>
</div>

<div class="modal fade" id="sourceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sourceModalTitle">Kaynak Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="sourceForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="sourceId" name="id">
                    <div class="mb-2">
                        <label class="form-label">Kaynak Adı</label>
                        <input class="form-control" id="sourceName" name="name" maxlength="191" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">RSS URL</label>
                        <input class="form-control" id="sourceRssUrl" name="rss_url" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Kategori</label>
                        <select class="form-select" id="sourceCategory" name="category"></select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Dil</label>
                        <input class="form-control" id="sourceLanguage" name="language" value="tr" maxlength="16">
                    </div>
                    <div>
                        <label class="form-label">Durum</label>
                        <select class="form-select" id="sourceIsActive" name="is_active">
                            <option value="1">Aktif</option>
                            <option value="0">Pasif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="saveSourceBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="articleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Haberi Güncelle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="articleForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="articleId" name="id">
                    <div class="mb-2">
                        <label class="form-label">Başlık</label>
                        <input class="form-control" id="articleTitle" name="title" maxlength="500" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Özet</label>
                        <textarea class="form-control" id="articleSummary" name="summary" rows="4" maxlength="1200"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Kaynak Adı</label>
                            <input class="form-control" id="articleSourceName" name="source_name" maxlength="191">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kategori</label>
                            <select class="form-select" id="articleCategory" name="category"></select>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Kaynak Linki</label>
                            <input class="form-control" id="articleSourceUrl" name="source_url">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Görsel URL</label>
                            <input class="form-control" id="articleImageUrl" name="image_url">
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Yayın Tarihi</label>
                        <input class="form-control" id="articlePublishedAt" name="published_at" placeholder="YYYY-MM-DD HH:MM:SS">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="saveArticleBtn">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const sourceApi = '/api/v1/admin/news/sources.php';
    const articleApi = '/api/v1/admin/news/articles.php';

    let categoryMap = {
        general: 'Genel',
        world: 'Dünya Denizcilik',
        turkey: 'Türkiye / Limanlar',
        accidents: 'Kaza / Olay',
        education: 'Eğitim / Sertifika',
        technology: 'Teknoloji',
        trade: 'Ticaret / Navlun'
    };
    let sources = [];
    let pendingArticles = [];
    let approvedArticles = [];
    let rejectedArticles = [];
    let currentStatusTab = 'pending';

    const esc = (txt) => $('<div>').text(txt ?? '').html();
    const fmtDate = (v) => !v ? '-' : (typeof window.formatDate === 'function' ? window.formatDate(v) : v);
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const appConfirm = (title, message, opts = {}) => window.showAppConfirm({ title, message, ...opts });

    const api = async (url, method = 'GET', data = {}) => {
        return await window.appAjax({ url, method, data, dataType: 'json' });
    };

    function fillCategorySelect($select) {
        const options = Object.entries(categoryMap).map(([key, label]) => `<option value="${esc(key)}">${esc(label)}</option>`);
        $select.html(options.join(''));
    }

    function articleActions(a, status) {
        const id = esc(a.id);
        const actions = [`<button class="btn btn-sm btn-outline-primary article-edit-btn" data-id="${id}" data-status="${status}"><i class="bi bi-pencil-square"></i></button>`];
        if (status === 'pending') {
            actions.push(`<button class="btn btn-sm btn-success article-action-btn" data-id="${id}" data-action="approve">Onayla</button>`);
            actions.push(`<button class="btn btn-sm btn-warning article-action-btn" data-id="${id}" data-action="reject">Reddet</button>`);
        } else if (status === 'approved') {
            actions.push(`<button class="btn btn-sm btn-warning article-action-btn" data-id="${id}" data-action="reject">Yayından Kaldır</button>`);
        } else {
            actions.push(`<button class="btn btn-sm btn-success article-action-btn" data-id="${id}" data-action="approve">Onayla</button>`);
        }
        actions.push(`<button class="btn btn-sm btn-danger article-action-btn" data-id="${id}" data-action="delete">Sil</button>`);
        return actions.join(' ');
    }

    function renderArticles(items, status, bodyId, emptyId) {
        const $body = $(bodyId).empty();
        $(emptyId).toggleClass('d-none', items.length > 0);

        items.forEach((a) => {
            const imageHtml = a.image_url
                ? `<img src="${esc(a.image_url)}" alt="img" style="width:64px;height:48px;object-fit:cover;border-radius:8px;">`
                : '<span class="text-muted">-</span>';

            $body.append(`
                <tr>
                    <td>
                        <div class="fw-semibold">${esc(a.title || '')}</div>
                        <div class="small text-muted">${esc(a.summary || '')}</div>
                    </td>
                    <td>
                        <div>${esc(a.source_name || '-')}</div>
                        <a href="${esc(a.source_url || '#')}" target="_blank" rel="noopener">Kaynağı Aç</a>
                    </td>
                    <td>${esc(a.category_label || a.category || '-')}</td>
                    <td>${fmtDate(a.published_at || a.created_at)}</td>
                    <td>${imageHtml}</td>
                    <td class="text-end"><div class="d-inline-flex gap-1 flex-wrap justify-content-end">${articleActions(a, status)}</div></td>
                </tr>
            `);
        });
    }

    function renderSources() {
        const $body = $('#sourcesBody').empty();
        $('#sourcesEmpty').toggleClass('d-none', sources.length > 0);

        sources.forEach((s) => {
            const isActive = Number(s.is_active) === 1;
            $body.append(`
                <tr>
                    <td>${esc(s.name || '')}</td>
                    <td><a href="${esc(s.rss_url || '#')}" target="_blank" rel="noopener">${esc(s.rss_url || '-')}</a></td>
                    <td>${esc(s.category_label || s.category || '-')}</td>
                    <td>${esc(s.language || '-')}</td>
                    <td>${isActive ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>'}</td>
                    <td>${fmtDate(s.last_fetched_at)}</td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <button class="btn btn-sm btn-outline-primary source-edit-btn" data-id="${esc(s.id)}"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm ${isActive ? 'btn-outline-secondary' : 'btn-outline-success'} source-toggle-btn" data-id="${esc(s.id)}">${isActive ? 'Pasif' : 'Aktif'}</button>
                            <button class="btn btn-sm btn-danger source-delete-btn" data-id="${esc(s.id)}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);
        });
    }

    async function loadSources() {
        const res = await api(sourceApi, 'GET');
        if (!res.success) return appAlert('Hata', res.message || 'Kaynaklar alınamadı.', 'error');
        sources = res.data?.sources || [];
        if (res.data?.categories) categoryMap = res.data.categories;
        fillCategorySelect($('#sourceCategory'));
        fillCategorySelect($('#articleCategory'));
        renderSources();
    }

    async function loadArticles(status) {
        const res = await api(articleApi + '?status=' + encodeURIComponent(status), 'GET');
        if (!res.success) return appAlert('Hata', res.message || 'Haberler alınamadı.', 'error');
        if (res.data?.categories) categoryMap = res.data.categories;
        const items = res.data?.articles || [];

        if (status === 'pending') pendingArticles = items;
        if (status === 'approved') approvedArticles = items;
        if (status === 'rejected') rejectedArticles = items;

        renderArticles(pendingArticles, 'pending', '#pendingArticlesBody', '#pendingEmpty');
        renderArticles(approvedArticles, 'approved', '#approvedArticlesBody', '#approvedEmpty');
        renderArticles(rejectedArticles, 'rejected', '#rejectedArticlesBody', '#rejectedEmpty');
    }

    async function refreshAllArticles() {
        await loadArticles('pending');
        await loadArticles('approved');
        await loadArticles('rejected');
    }

    function getActiveTabStatus() {
        const activeTarget = $('#newsTabs .nav-link.active').data('bsTarget') || '#tabPending';
        if (activeTarget === '#tabApproved') return 'approved';
        if (activeTarget === '#tabRejected') return 'rejected';
        if (activeTarget === '#tabSources') return 'sources';
        return 'pending';
    }

    async function refreshActiveTabContent() {
        const activeStatus = getActiveTabStatus();
        currentStatusTab = activeStatus;

        if (activeStatus === 'sources') {
            await loadSources();
            return;
        }

        await loadArticles(activeStatus);
    }

    function resetSourceForm() {
        $('#sourceForm')[0].reset();
        $('#sourceId').val('');
        $('#sourceModalTitle').text('Kaynak Ekle');
        $('#sourceIsActive').val('1');
    }

    function findArticleById(id, status) {
        const list = status === 'pending' ? pendingArticles : (status === 'approved' ? approvedArticles : rejectedArticles);
        return list.find((x) => String(x.id) === String(id)) || null;
    }

    $('#addSourceBtn').on('click', function () {
        resetSourceForm();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('sourceModal')).show();
    });

    $('#fetchNowBtn').on('click', async function () {
        const $btn = $(this);
        const defaultHtml = $btn.html();

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Haberler çekiliyor...');

        const res = await api(articleApi, 'POST', { action: 'fetch_now' });

        $btn.prop('disabled', false).html(defaultHtml);

        if (!res.success) {
            return appAlert('Hata', res.message || 'Haberler çekilemedi.', 'error');
        }

        const summary = res.data?.summary || {};
        const inserted = Number(summary.inserted || 0);
        const skipped = Number(summary.skipped_duplicates || 0);
        const failed = Number(summary.sources_failed || 0);

        await appAlert(
            'Haber çekme tamamlandı.',
            `Yeni haber: ${inserted}, Tekrar atlanan: ${skipped}, Hatalı kaynak: ${failed}`,
            'success'
        );

        await refreshActiveTabContent();
    });

    $('#sourceForm').on('submit', async function (e) {
        e.preventDefault();
        const payload = {
            action: ($('#sourceId').val() || '').trim() ? 'update' : 'create',
            id: ($('#sourceId').val() || '').trim(),
            name: ($('#sourceName').val() || '').trim(),
            rss_url: ($('#sourceRssUrl').val() || '').trim(),
            category: ($('#sourceCategory').val() || 'general').trim(),
            language: ($('#sourceLanguage').val() || 'tr').trim(),
            is_active: Number($('#sourceIsActive').val() || 1)
        };

        const res = await api(sourceApi, 'POST', payload);
        if (!res.success) return appAlert('Hata', res.message || 'Kaynak kaydedilemedi.', 'error');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('sourceModal')).hide();
        await appAlert('Başarılı', res.message || 'İşlem başarılı.', 'success');
        await loadSources();
    });

    $(document).on('click', '.source-edit-btn', function () {
        const sourceId = $(this).data('id');
        const item = sources.find((x) => String(x.id) === String(sourceId));
        if (!item) return;
        $('#sourceModalTitle').text('Kaynak Güncelle');
        $('#sourceId').val(item.id || '');
        $('#sourceName').val(item.name || '');
        $('#sourceRssUrl').val(item.rss_url || '');
        $('#sourceCategory').val(item.category || 'general');
        $('#sourceLanguage').val(item.language || 'tr');
        $('#sourceIsActive').val(Number(item.is_active) === 1 ? '1' : '0');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('sourceModal')).show();
    });

    $(document).on('click', '.source-toggle-btn', async function () {
        const id = $(this).data('id');
        const res = await api(sourceApi, 'POST', { action: 'toggle', id });
        if (!res.success) return appAlert('Hata', res.message || 'Durum güncellenemedi.', 'error');
        await loadSources();
    });

    $(document).on('click', '.source-delete-btn', async function () {
        const id = $(this).data('id');
        const ok = await appConfirm('Kaynağı Sil', 'Kaynak silinecek. Onaylıyor musunuz?', { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' });
        if (!ok) return;
        const res = await api(sourceApi, 'POST', { action: 'delete', id });
        if (!res.success) return appAlert('Hata', res.message || 'Kaynak silinemedi.', 'error');
        await appAlert('Başarılı', res.message || 'Kaynak silindi.', 'success');
        await loadSources();
    });

    $(document).on('click', '.article-action-btn', async function () {
        const id = $(this).data('id');
        const action = $(this).data('action');
        const confirmMap = {
            approve: 'Bu haber onaylanıp yayına alınacak. Devam edilsin mi?',
            reject: 'Bu haber reddedilecek/yayından kaldırılacak. Devam edilsin mi?',
            delete: 'Bu haber kalıcı olarak silinecek. Devam edilsin mi?'
        };

        const ok = await appConfirm('Haber İşlemi', confirmMap[action] || 'İşlem yapılsın mı?', { type: 'warning', confirmText: 'Evet', cancelText: 'İptal' });
        if (!ok) return;

        const res = await api(articleApi, 'POST', { action, id });
        if (!res.success) return appAlert('Hata', res.message || 'İşlem başarısız.', 'error');

        await appAlert('Başarılı', res.message || 'İşlem tamamlandı.', 'success');
        await refreshAllArticles();
    });

    $(document).on('click', '.article-edit-btn', function () {
        const id = $(this).data('id');
        const status = $(this).data('status');
        const a = findArticleById(id, status);
        if (!a) return;

        $('#articleId').val(a.id || '');
        $('#articleTitle').val(a.title || '');
        $('#articleSummary').val(a.summary || '');
        $('#articleSourceName').val(a.source_name || '');
        $('#articleSourceUrl').val(a.source_url || '');
        $('#articleImageUrl').val(a.image_url || '');
        $('#articleCategory').val(a.category || 'general');
        $('#articlePublishedAt').val(a.published_at || '');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('articleModal')).show();
    });

    $('#articleForm').on('submit', async function (e) {
        e.preventDefault();
        const payload = {
            action: 'update',
            id: ($('#articleId').val() || '').trim(),
            title: ($('#articleTitle').val() || '').trim(),
            summary: ($('#articleSummary').val() || '').trim(),
            source_name: ($('#articleSourceName').val() || '').trim(),
            source_url: ($('#articleSourceUrl').val() || '').trim(),
            image_url: ($('#articleImageUrl').val() || '').trim(),
            category: ($('#articleCategory').val() || 'general').trim(),
            published_at: ($('#articlePublishedAt').val() || '').trim()
        };

        const res = await api(articleApi, 'POST', payload);
        if (!res.success) return appAlert('Hata', res.message || 'Haber güncellenemedi.', 'error');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('articleModal')).hide();
        await appAlert('Başarılı', res.message || 'Haber güncellendi.', 'success');
        await refreshAllArticles();
    });

    $('#newsTabs button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        currentStatusTab = getActiveTabStatus();
    });

    loadSources();
    refreshAllArticles();
});
</script>
JAVASCRIPT;

include '../includes/footer.php';

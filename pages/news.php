<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/news_helper.php';
require_once '../includes/notification_helper.php';

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
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabNewsNotifications" type="button">Haber Bildirimleri</button>
        </li>
    </ul>

    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="tabPending">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-danger bulk-delete-btn" id="pendingBulkDeleteBtn" data-status="pending" disabled>
                        <i class="bi bi-trash"></i> Seçilenleri Sil
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" class="form-check-input article-select-all" id="pendingSelectAll" data-status="pending"></th>
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
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-danger bulk-delete-btn" id="approvedBulkDeleteBtn" data-status="approved" disabled>
                        <i class="bi bi-trash"></i> Seçilenleri Sil
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" class="form-check-input article-select-all" id="approvedSelectAll" data-status="approved"></th>
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
                <div class="d-flex justify-content-end mb-3">
                    <button class="btn btn-danger bulk-delete-btn" id="rejectedBulkDeleteBtn" data-status="rejected" disabled>
                        <i class="bi bi-trash"></i> Seçilenleri Sil
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" class="form-check-input article-select-all" id="rejectedSelectAll" data-status="rejected"></th>
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

        <div class="tab-pane fade" id="tabNewsNotifications">
            <div class="card"><div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                        <tr>
                            <th>Bildirim Başlığı</th>
                            <th>Haber</th>
                            <th>Hedef</th>
                            <th>Durum</th>
                            <th>Zamanlama</th>
                            <th>Başarı / Hata</th>
                            <th>Tarih</th>
                            <th>İşlemler</th>
                        </tr>
                        </thead>
                        <tbody id="newsNotificationsBody"></tbody>
                    </table>
                </div>
                <div id="newsNotificationsEmpty" class="text-muted d-none">Haber bildirimi bulunamadı.</div>
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

<div class="modal fade" id="newsNotificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Haber Bildirimi Gönder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="newsNotificationArticleId">

                <div class="card bg-light border-0 mb-3">
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-9">
                                <label class="form-label small text-muted mb-1">Haber Başlığı</label>
                                <div class="fw-semibold" id="newsNotificationArticleTitleDisplay">-</div>
                                <div class="small text-muted mt-1" id="newsNotificationArticleSummaryDisplay"></div>
                            </div>
                            <div class="col-md-3 text-md-end">
                                <img id="newsNotificationArticleImagePreview" src="" alt="Haber Görseli" class="img-fluid rounded d-none" style="max-height:90px;object-fit:cover;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Bildirim Başlığı</label>
                        <input type="text" class="form-control" id="newsNotificationTitle" maxlength="120">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Görsel URL</label>
                        <input type="url" class="form-control" id="newsNotificationImageUrl" placeholder="https://...">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Bildirim Mesajı</label>
                        <textarea class="form-control" id="newsNotificationMessage" rows="4" maxlength="500"></textarea>
                    </div>
                </div>

                <hr>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Hedef Tipi</label>
                        <select class="form-select" id="newsNotificationTargetType">
                            <option value="all_users" selected>Tüm kullanıcılar</option>
                            <option value="single_user">Tek kullanıcı</option>
                            <option value="premium_users">Premium kullanıcılar</option>
                            <option value="free_users">Free kullanıcılar</option>
                            <option value="qualification">Belirli yeterlilik</option>
                            <option value="last_7_days_active">Son 7 gün aktif</option>
                            <option value="last_30_days_passive">Son 30 gün pasif</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-none" id="newsNotificationSingleUserWrap">
                        <label class="form-label">Kullanıcı Ara</label>
                        <input type="text" class="form-control mb-2" id="newsNotificationUserSearch" placeholder="Email / ad ile ara...">
                        <select class="form-select" id="newsNotificationTargetUserId"></select>
                    </div>
                    <div class="col-md-6 d-none" id="newsNotificationQualificationWrap">
                        <label class="form-label">Yeterlilik</label>
                        <select class="form-select" id="newsNotificationTargetQualificationId"></select>
                    </div>
                </div>

                <hr>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label d-block">Zamanlama</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="news_notification_schedule_mode" id="newsNotificationScheduleNow" value="now" checked>
                            <label class="form-check-label" for="newsNotificationScheduleNow">Hemen gönder</label>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="radio" name="news_notification_schedule_mode" id="newsNotificationScheduleScheduled" value="scheduled">
                            <label class="form-check-label" for="newsNotificationScheduleScheduled">Planlı gönder</label>
                        </div>
                    </div>
                    <div class="col-md-6 d-none" id="newsNotificationScheduledAtWrap">
                        <label class="form-label">Planlanan Tarih/Saat</label>
                        <input type="datetime-local" class="form-control" id="newsNotificationScheduledAt">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="sendNewsNotificationBtn"><i class="bi bi-send"></i> Bildirim Gönder</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const sourceApi = '/api/v1/admin/news/sources.php';
    const articleApi = '/api/v1/admin/news/articles.php';
    const notificationApi = '../ajax/notifications.php';

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
    let newsNotifications = [];
    const selectedArticleIds = {
        pending: new Set(),
        approved: new Set(),
        rejected: new Set()
    };
    let currentStatusTab = 'pending';

    const esc = (txt) => $('<div>').text(txt ?? '').html();
    const fmtDate = (v) => !v ? '-' : (typeof window.formatDate === 'function' ? window.formatDate(v) : v);
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const appConfirm = (title, message, opts = {}) => window.showAppConfirm({ title, message, ...opts });

    const api = async (url, method = 'GET', data = {}) => {
        return await window.appAjax({ url, method, data, dataType: 'json' });
    };

    const notificationApiCall = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({
            url: notificationApi + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
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
            actions.push(`<button class="btn btn-sm btn-outline-info article-notify-btn" data-id="${id}" title="Bildirim Gönder"><i class="bi bi-bell"></i></button>`);
            actions.push(`<button class="btn btn-sm btn-warning article-action-btn" data-id="${id}" data-action="reject">Yayından Kaldır</button>`);
        } else {
            actions.push(`<button class="btn btn-sm btn-success article-action-btn" data-id="${id}" data-action="approve">Onayla</button>`);
        }
        actions.push(`<button class="btn btn-sm btn-danger article-action-btn" data-id="${id}" data-action="delete">Sil</button>`);
        return actions.join(' ');
    }

    function articleListByStatus(status) {
        if (status === 'approved') return approvedArticles;
        if (status === 'rejected') return rejectedArticles;
        return pendingArticles;
    }

    function tabSelectorByStatus(status) {
        if (status === 'approved') return '#tabApproved';
        if (status === 'rejected') return '#tabRejected';
        return '#tabPending';
    }

    function updateBulkDeleteButton(status) {
        const count = (selectedArticleIds[status] || new Set()).size;
        const $btn = $('#' + status + 'BulkDeleteBtn');
        if (!$btn.length) return;
        $btn.prop('disabled', count === 0);
        $btn.html(`<i class="bi bi-trash"></i> Seçilenleri Sil${count > 0 ? ' (' + count + ')' : ''}`);
    }

    function syncSelectAllCheckbox(status) {
        const items = articleListByStatus(status);
        const selected = selectedArticleIds[status] || new Set();
        const total = items.length;
        const selectedCount = items.filter((item) => selected.has(String(item.id))).length;
        const checkbox = document.getElementById(status + 'SelectAll');
        if (!checkbox) return;

        checkbox.checked = total > 0 && selectedCount === total;
        checkbox.indeterminate = selectedCount > 0 && selectedCount < total;
        updateBulkDeleteButton(status);
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
                    <td><input type="checkbox" class="form-check-input article-select-checkbox" data-status="${status}" data-id="${esc(a.id)}" ${selectedArticleIds[status].has(String(a.id)) ? 'checked' : ''}></td>
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

        const validIds = new Set(items.map((item) => String(item.id)));
        selectedArticleIds[status] = new Set(Array.from(selectedArticleIds[status]).filter((id) => validIds.has(id)));
        syncSelectAllCheckbox(status);
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
        if (activeTarget === '#tabNewsNotifications') return 'news_notifications';
        return 'pending';
    }

    async function refreshActiveTabContent() {
        const activeStatus = getActiveTabStatus();
        currentStatusTab = activeStatus;

        if (activeStatus === 'sources') {
            await loadSources();
            return;
        }

        if (activeStatus === 'news_notifications') {
            await loadNewsNotifications();
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

    function getApprovedArticleById(id) {
        return approvedArticles.find((x) => String(x.id) === String(id)) || null;
    }

    function toggleNewsNotificationTargetFields() {
        const targetType = $('#newsNotificationTargetType').val();
        $('#newsNotificationSingleUserWrap').toggleClass('d-none', targetType !== 'single_user');
        $('#newsNotificationQualificationWrap').toggleClass('d-none', targetType !== 'qualification');
    }

    function toggleNewsNotificationScheduleField() {
        const mode = $('input[name="news_notification_schedule_mode"]:checked').val();
        $('#newsNotificationScheduledAtWrap').toggleClass('d-none', mode !== 'scheduled');
    }

    async function loadNotificationQualifications() {
        try {
            const res = await notificationApiCall('list_qualifications');
            const items = res.data?.items || [];
            const $sel = $('#newsNotificationTargetQualificationId');
            $sel.empty().append('<option value="">Seçiniz...</option>');
            items.forEach(i => $sel.append('<option value="' + esc(i.id) + '">' + esc(i.name) + '</option>'));
        } catch (e) {
            console.error('[news] loadNotificationQualifications', e);
        }
    }

    async function searchNotificationUsers(q = '') {
        try {
            const res = await notificationApiCall('search_users', 'GET', { q });
            const items = res.data?.items || [];
            const $sel = $('#newsNotificationTargetUserId');
            $sel.empty().append('<option value="">Kullanıcı seçiniz...</option>');
            items.forEach(i => {
                const label = (i.full_name || '') + (i.email ? ' (' + i.email + ')' : '');
                $sel.append('<option value="' + esc(i.id) + '">' + esc(label.trim()) + '</option>');
            });
        } catch (e) {
            console.error('[news] searchNotificationUsers', e);
        }
    }

    function openNewsNotificationModal(article) {
        const fallbackMessage = 'Yeni haber yayında. Detayları görmek için dokunun.';
        $('#newsNotificationArticleId').val(article.id || '');
        $('#newsNotificationArticleTitleDisplay').text(article.title || '-');
        $('#newsNotificationArticleSummaryDisplay').text(article.summary || '');
        $('#newsNotificationTitle').val(article.title || '');
        $('#newsNotificationMessage').val(article.summary || fallbackMessage);
        $('#newsNotificationImageUrl').val(article.image_url || '');
        $('#newsNotificationTargetType').val('all_users');
        $('#newsNotificationTargetUserId').val('');
        $('#newsNotificationTargetQualificationId').val('');
        $('#newsNotificationUserSearch').val('');
        $('#newsNotificationScheduleNow').prop('checked', true);
        $('#newsNotificationScheduledAt').val('');
        toggleNewsNotificationTargetFields();
        toggleNewsNotificationScheduleField();

        const $img = $('#newsNotificationArticleImagePreview');
        if (article.image_url) {
            $img.attr('src', article.image_url).removeClass('d-none');
        } else {
            $img.attr('src', '').addClass('d-none');
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('newsNotificationModal')).show();
    }

    function buildNewsNotificationPayload(article) {
        return JSON.stringify({
            type: 'news_article',
            screen: 'news',
            deep_link: 'news',
            entity_id: article.id,
            article_id: article.id,
            article_title: article.title || ''
        });
    }

    function renderNewsNotifications() {
        const $body = $('#newsNotificationsBody').empty();
        $('#newsNotificationsEmpty').toggleClass('d-none', newsNotifications.length > 0);

        newsNotifications.forEach((item) => {
            const scheduleText = item.schedule_type === 'scheduled'
                ? ('Planlı • ' + (item.scheduled_at || '-'))
                : (item.schedule_type === 'draft' ? 'Taslak' : 'Hemen');
            const dateText = item.created_at || item.sent_at || item.scheduled_at || '-';

            $body.append(`
                <tr>
                    <td>
                        <div class="fw-semibold">${esc(item.title || '-')}</div>
                        <div class="small text-muted">${esc(item.message || '')}</div>
                    </td>
                    <td>
                        <div>${esc(item.article_title || '-')}</div>
                        <div class="small text-muted">ID: ${esc(item.article_id || '-')}</div>
                    </td>
                    <td>${esc(item.target_label || item.target_type || '-')}</td>
                    <td><span class="badge text-bg-secondary">${esc(item.status_label || item.status || '-')}</span></td>
                    <td>${esc(scheduleText)}</td>
                    <td>${Number(item.success_count || 0)} / ${Number(item.failure_count || 0)}</td>
                    <td>${fmtDate(dateText)}</td>
                    <td>
                        <button class="btn btn-sm btn-secondary news-notification-detail-btn" data-id="${esc(item.id)}" title="Detay">
                            <i class="bi bi-eye"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    async function loadNewsNotifications() {
        const res = await notificationApiCall('list_news_notifications', 'GET');
        if (!res.success) return appAlert('Hata', res.message || 'Haber bildirimleri alınamadı.', 'error');
        newsNotifications = res.data?.items || [];
        renderNewsNotifications();
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
        const skippedNoImage = Number(summary.skipped_no_image || 0);
        const failed = Number(summary.sources_failed || 0);

        await appAlert(
            'Haber çekme tamamlandı.',
            `Yeni haber: ${inserted}, Tekrar atlanan: ${skipped}, Görselsiz atlanan: ${skippedNoImage}, Hatalı kaynak: ${failed}`,
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

    $(document).on('change', '.article-select-checkbox', function () {
        const status = String($(this).data('status') || 'pending');
        const id = String($(this).data('id') || '');
        if (!id || !selectedArticleIds[status]) return;

        if ($(this).is(':checked')) {
            selectedArticleIds[status].add(id);
        } else {
            selectedArticleIds[status].delete(id);
        }
        syncSelectAllCheckbox(status);
    });

    $(document).on('change', '.article-select-all', function () {
        const status = String($(this).data('status') || 'pending');
        const items = articleListByStatus(status);
        if (!selectedArticleIds[status]) return;

        if ($(this).is(':checked')) {
            items.forEach((item) => selectedArticleIds[status].add(String(item.id)));
        } else {
            selectedArticleIds[status].clear();
        }

        renderArticles(
            articleListByStatus(status),
            status,
            status === 'pending' ? '#pendingArticlesBody' : (status === 'approved' ? '#approvedArticlesBody' : '#rejectedArticlesBody'),
            status === 'pending' ? '#pendingEmpty' : (status === 'approved' ? '#approvedEmpty' : '#rejectedEmpty')
        );
    });

    $(document).on('click', '.bulk-delete-btn', async function () {
        const status = String($(this).data('status') || 'pending');
        const ids = Array.from(selectedArticleIds[status] || []);

        if (!ids.length) {
            return appAlert('Uyarı', 'Lütfen silmek için en az bir haber seçin.', 'warning');
        }

        const ok = await appConfirm(
            'Seçilenleri Sil',
            `${ids.length} haber kalıcı olarak silinecek. Devam edilsin mi?`,
            { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' }
        );
        if (!ok) return;

        const res = await api(articleApi, 'POST', { action: 'bulk_delete', ids });
        if (!res.success) return appAlert('Hata', res.message || 'Toplu silme başarısız.', 'error');

        selectedArticleIds[status].clear();
        await appAlert('Başarılı', res.message || 'Seçili haberler silindi.', 'success');
        await refreshActiveTabContent();
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

    $(document).on('click', '.article-notify-btn', function () {
        const id = $(this).data('id');
        const article = getApprovedArticleById(id);
        if (!article) return appAlert('Hata', 'Haber bilgisi bulunamadı.', 'error');
        openNewsNotificationModal(article);
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

    $('#newsNotificationTargetType').on('change', toggleNewsNotificationTargetFields);
    $('input[name="news_notification_schedule_mode"]').on('change', toggleNewsNotificationScheduleField);

    let newsNotificationUserTimer = null;
    $('#newsNotificationUserSearch').on('input', function () {
        clearTimeout(newsNotificationUserTimer);
        const q = $(this).val() || '';
        newsNotificationUserTimer = setTimeout(() => searchNotificationUsers(q), 280);
    });

    $('#sendNewsNotificationBtn').on('click', async function () {
        const articleId = ($('#newsNotificationArticleId').val() || '').trim();
        const article = getApprovedArticleById(articleId);
        const title = ($('#newsNotificationTitle').val() || '').trim();
        const message = ($('#newsNotificationMessage').val() || '').trim();
        const imageUrl = ($('#newsNotificationImageUrl').val() || '').trim();
        const targetType = $('#newsNotificationTargetType').val() || 'all_users';
        const targetUserId = $('#newsNotificationTargetUserId').val() || '';
        const targetQualificationId = $('#newsNotificationTargetQualificationId').val() || '';
        const scheduleMode = $('input[name="news_notification_schedule_mode"]:checked').val() || 'now';
        const scheduledAt = $('#newsNotificationScheduledAt').val() || '';

        if (!article) {
            return appAlert('Hata', 'Seçili haber bulunamadı.', 'error');
        }
        if (!title || !message) {
            return appAlert('Doğrulama', 'Başlık ve mesaj zorunludur.', 'warning');
        }
        if (targetType === 'single_user' && !targetUserId) {
            return appAlert('Doğrulama', 'Tek kullanıcı seçmelisiniz.', 'warning');
        }
        if (targetType === 'qualification' && !targetQualificationId) {
            return appAlert('Doğrulama', 'Yeterlilik seçmelisiniz.', 'warning');
        }
        if (scheduleMode === 'scheduled' && !scheduledAt) {
            return appAlert('Doğrulama', 'Planlı gönderim için tarih/saat seçiniz.', 'warning');
        }

        const payload = {
            title,
            message,
            image_url: imageUrl,
            channel: 'news',
            deep_link: 'news',
            target_type: targetType,
            target_user_id: targetUserId,
            target_qualification_id: targetQualificationId,
            schedule_mode: scheduleMode,
            scheduled_at: scheduledAt,
            payload_json: buildNewsNotificationPayload(article)
        };

        const action = scheduleMode === 'scheduled' ? 'create_notification' : 'send_now';
        const $btn = $(this);
        const defaultHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Gönderiliyor...');

        try {
            const res = await notificationApiCall(action, 'POST', payload);
            if (!res.success) {
                await appAlert('Hata', res.message || 'Bildirim gönderilemedi.', 'error');
                return;
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('newsNotificationModal')).hide();
            await appAlert('Başarılı', res.message || 'Bildirim başarıyla işlendi.', 'success');
            await loadNewsNotifications();
        } catch (e) {
            console.error('[news] sendNewsNotification', e);
            await appAlert('Hata', 'Bildirim gönderimi sırasında beklenmeyen bir hata oluştu.', 'error');
        } finally {
            $btn.prop('disabled', false).html(defaultHtml);
        }
    });

    $(document).on('click', '.news-notification-detail-btn', async function () {
        const id = $(this).data('id');
        const res = await notificationApiCall('get_notification_detail', 'GET', { notification_id: id });
        if (!res.success) return appAlert('Hata', res.message || 'Bildirim detayı alınamadı.', 'error');

        const n = res.data?.notification || {};
        await appAlert(n.title || 'Bildirim Detayı', n.message || '-', 'info');
    });

    $('#newsTabs button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
        currentStatusTab = getActiveTabStatus();
        if (currentStatusTab === 'news_notifications') {
            loadNewsNotifications();
            return;
        }
        if (currentStatusTab !== 'sources') {
            syncSelectAllCheckbox(currentStatusTab);
        }
    });

    loadSources();
    loadNotificationQualifications();
    searchNotificationUsers('');
    refreshAllArticles();
});
</script>
JAVASCRIPT;

include '../includes/footer.php';

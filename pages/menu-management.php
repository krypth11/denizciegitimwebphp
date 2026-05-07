<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'menu-management';
$page_title = 'Menü Yönetimi';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Menü Yönetimi</h2>
            <p class="text-muted mb-0">App ve Portal menülerini global olarak aç/kapatabilir, kullanıcı bazlı istisna tanımlayabilirsiniz.</p>
        </div>
    </div>

    <div class="card soft-card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Platform</label>
                    <select class="form-select" id="platformFilter">
                        <option value="app">App</option>
                        <option value="portal">Portal</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bölüm</label>
                    <select class="form-select" id="sectionFilter">
                        <option value="">Tümü</option>
                        <option value="main">Ana Menü</option>
                        <option value="quick">Hızlı Erişim</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Arama</label>
                    <input type="text" class="form-control" id="searchFilter" placeholder="Menü adı veya açıklama ara...">
                </div>
            </div>
        </div>
    </div>

    <div class="card soft-card">
        <div class="card-body table-responsive">
            <table class="table align-middle table-hover" id="menusTable">
                <thead>
                <tr>
                    <th>Menü</th>
                    <th>Platform</th>
                    <th>Bölüm</th>
                    <th>Global Durum</th>
                    <th>Kullanıcı İstisnaları</th>
                    <th>Sıra</th>
                    <th>İşlemler</th>
                </tr>
                </thead>
                <tbody>
                <tr><td colspan="7" class="text-muted">Yükleniyor...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="overrideModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Kullanıcı İstisnaları</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <div class="fw-semibold" id="overrideMenuLabel">-</div>
                    <div class="text-muted small" id="overrideHint">-</div>
                </div>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="userSearchInput" placeholder="Email / ad soyad ile kullanıcı ara...">
                    <button class="btn btn-outline-secondary" id="searchUserBtn" type="button">Ara</button>
                </div>
                <div id="userSearchResults" class="mb-3"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <h6>Özel Aktif Kullanıcılar</h6>
                        <div id="enabledUsersList" class="override-list"></div>
                    </div>
                    <div class="col-md-6">
                        <h6>Özel Pasif Kullanıcılar</h6>
                        <div id="disabledUsersList" class="override-list"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/menu-management.php';
    let allItems = [];
    let currentOverrideMenu = null;

    const esc = (v) => $('<div>').text(v ?? '').html();
    const alertApp = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    async function api(action, method = 'GET', data = {}) {
        try {
            return await $.ajax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
        } catch (xhr) {
            return { success: false, message: xhr?.responseJSON?.message || 'Sunucu hatası.' };
        }
    }

    function sectionText(s) {
        const key = String(s || '').toLowerCase();
        if (key === 'main') return 'Ana Menü';
        if (key === 'quick') return 'Hızlı Erişim';
        return s || '-';
    }

    function overrideSummary(item) {
        if (Number(item.global_enabled) === 1) {
            return `Global Açık + ${Number(item.disabled_users_count || 0)} kullanıcıya kapalı`;
        }
        return `Global Kapalı + ${Number(item.enabled_users_count || 0)} kullanıcıya açık`;
    }

    function filteredItems() {
        const section = $('#sectionFilter').val();
        const q = ($('#searchFilter').val() || '').toLowerCase().trim();
        return allItems.filter(i => {
            if (section && String(i.section || '').toLowerCase() !== section) return false;
            if (!q) return true;
            return String(i.label || '').toLowerCase().includes(q)
                || String(i.description || '').toLowerCase().includes(q)
                || String(i.menu_key || '').toLowerCase().includes(q);
        });
    }

    function renderTable() {
        const rows = filteredItems();
        if (!rows.length) {
            $('#menusTable tbody').html('<tr><td colspan="7" class="text-muted">Kayıt bulunamadı.</td></tr>');
            return;
        }

        const html = rows.map(item => {
            const checked = Number(item.global_enabled) === 1 ? 'checked' : '';
            return `<tr>
                <td><div class="fw-semibold">${esc(item.label || item.menu_key)}</div><div class="small text-muted">${esc(item.description || '')}</div></td>
                <td><span class="badge text-bg-secondary text-uppercase">${esc(item.platform)}</span></td>
                <td>${esc(sectionText(item.section))}</td>
                <td><div class="form-check form-switch m-0"><input class="form-check-input js-global" type="checkbox" data-key="${esc(item.menu_key)}" ${checked}></div></td>
                <td><span class="small text-muted">${esc(overrideSummary(item))}</span></td>
                <td>${Number(item.sort_order || 0)}</td>
                <td><button class="btn btn-sm btn-outline-primary js-overrides" data-key="${esc(item.menu_key)}">Kullanıcı İstisnaları</button></td>
            </tr>`;
        }).join('');
        $('#menusTable tbody').html(html);
    }

    async function loadList() {
        const platform = $('#platformFilter').val();
        const res = await api('list', 'GET', { platform });
        if (!res.success) {
            await alertApp('Hata', res.message || 'Liste yüklenemedi.', 'error');
            return;
        }
        allItems = res.items || [];
        renderTable();
    }

    async function saveGlobal(menuKey, globalEnabled) {
        const item = allItems.find(i => i.menu_key === menuKey);
        if (!item) return;
        const res = await api('save_item', 'POST', {
            platform: item.platform,
            menu_key: item.menu_key,
            global_enabled: globalEnabled ? 1 : 0,
            label: item.label || '',
            description: item.description || '',
            sort_order: item.sort_order || 0
        });
        if (!res.success) {
            await alertApp('Hata', res.message || 'Güncellenemedi.', 'error');
            return false;
        }
        return true;
    }

    function userRow(user, mode) {
        return `<div class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary-subtle">
            <div><div>${esc(user.full_name || '-')}</div><small class="text-muted">${esc(user.email || '')}</small></div>
            <button class="btn btn-sm btn-outline-danger js-remove-override" data-user-id="${esc(user.id)}" data-mode="${esc(mode)}">Sil</button>
        </div>`;
    }

    async function loadOverridesModal() {
        if (!currentOverrideMenu) return;
        const res = await api('get_overrides', 'POST', { platform: currentOverrideMenu.platform, menu_key: currentOverrideMenu.menu_key });
        if (!res.success) {
            await alertApp('Hata', res.message || 'İstisnalar yüklenemedi.', 'error');
            return;
        }
        const ge = Number(res.global_enabled) === 1;
        $('#overrideMenuLabel').text(currentOverrideMenu.label || currentOverrideMenu.menu_key);
        $('#overrideHint').text(ge
            ? 'Bu menü herkese açık. Aşağıdan belirli kullanıcılarda gizleyebilirsiniz.'
            : 'Bu menü herkese kapalı. Aşağıdan belirli kullanıcılara açabilirsiniz.');

        $('#enabledUsersList').html((res.enabled_users || []).map(u => userRow(u, 'enabled')).join('') || '<div class="text-muted small">Kayıt yok.</div>');
        $('#disabledUsersList').html((res.disabled_users || []).map(u => userRow(u, 'disabled')).join('') || '<div class="text-muted small">Kayıt yok.</div>');
    }

    async function searchUsers() {
        const q = $('#userSearchInput').val() || '';
        const res = await api('search_users', 'POST', { q });
        if (!res.success) {
            await alertApp('Hata', res.message || 'Kullanıcı aranamadı.', 'error');
            return;
        }
        const html = (res.items || []).map(u => `<div class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary-subtle">
            <div><div>${esc(u.full_name || '-')} ${Number(u.is_pro||0)===1?'<span class="badge text-bg-warning">PRO</span>':''}</div><small class="text-muted">${esc(u.email || '')}</small></div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-success js-add-override" data-user-id="${esc(u.id)}" data-enabled="1">Aç</button>
                <button class="btn btn-outline-danger js-add-override" data-user-id="${esc(u.id)}" data-enabled="0">Kapat</button>
            </div>
        </div>`).join('') || '<div class="text-muted small">Kullanıcı bulunamadı.</div>';
        $('#userSearchResults').html(html);
    }

    $(document).on('change', '.js-global', async function () {
        const menuKey = $(this).data('key');
        const ok = await saveGlobal(menuKey, $(this).is(':checked'));
        if (!ok) return;
        await loadList();
    });

    $(document).on('click', '.js-overrides', async function () {
        const menuKey = $(this).data('key');
        currentOverrideMenu = allItems.find(i => i.menu_key === menuKey) || null;
        if (!currentOverrideMenu) return;
        await loadOverridesModal();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('overrideModal')).show();
    });

    $(document).on('click', '.js-add-override', async function () {
        if (!currentOverrideMenu) return;
        const userId = $(this).data('user-id');
        const enabled = Number($(this).data('enabled')) === 1 ? 1 : 0;
        const res = await api('add_override', 'POST', {
            platform: currentOverrideMenu.platform,
            menu_key: currentOverrideMenu.menu_key,
            user_id: userId,
            override_enabled: enabled
        });
        if (!res.success) {
            await alertApp('Hata', res.message || 'İstisna eklenemedi.', 'error');
            return;
        }
        await loadOverridesModal();
        await loadList();
    });

    $(document).on('click', '.js-remove-override', async function () {
        if (!currentOverrideMenu) return;
        const userId = $(this).data('user-id');
        const res = await api('remove_override', 'POST', {
            platform: currentOverrideMenu.platform,
            menu_key: currentOverrideMenu.menu_key,
            user_id: userId
        });
        if (!res.success) {
            await alertApp('Hata', res.message || 'İstisna silinemedi.', 'error');
            return;
        }
        await loadOverridesModal();
        await loadList();
    });

    $('#platformFilter').on('change', loadList);
    $('#sectionFilter, #searchFilter').on('input change', renderTable);
    $('#searchUserBtn').on('click', searchUsers);
    $('#userSearchInput').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); searchUsers(); } });

    loadList();
});
</script>
<style>
.soft-card{border:0;background:var(--card-bg, #1f2430);box-shadow:0 8px 24px rgba(0,0,0,.12);border-radius:14px}
.override-list{background:rgba(255,255,255,.02);border-radius:10px;padding:8px;min-height:120px}
</style>
JS;

include '../includes/footer.php';

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'notifications-tokens';
$page_title = 'Tokenlar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Tokenlar</h2>
            <p class="text-muted mb-0">Push token envanteri ve cihaz durumu.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-md-4">
                    <input type="search" id="tokenSearch" class="form-control" placeholder="Kullanıcı ara...">
                </div>
                <div class="col-md-3">
                    <select id="platformFilter" class="form-select">
                        <option value="">Platform (Tümü)</option>
                        <option value="android">android</option>
                        <option value="ios">ios</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="activeFilter" class="form-select">
                        <option value="">Durum (Tümü)</option>
                        <option value="1">aktif</option>
                        <option value="0">pasif</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-secondary w-100" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mobile-card-table">
                    <thead>
                        <tr>
                            <th>Kullanıcı</th>
                            <th>Platform</th>
                            <th>App Version</th>
                            <th>Aktif mi</th>
                            <th>Son Görülme</th>
                            <th>Token (Maskeli)</th>
                        </tr>
                    </thead>
                    <tbody id="tokensBody"></tbody>
                </table>
            </div>
            <div class="alert alert-light text-muted d-none mt-2" id="tokensEmpty">Token kaydı bulunamadı.</div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/notifications.php';
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const esc = (txt) => $('<div>').text(txt ?? '').html();

    const api = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
    };

    function render(items) {
        const $tb = $('#tokensBody').empty();
        $('#tokensEmpty').toggleClass('d-none', items.length > 0);

        items.forEach(row => {
            const userLabel = row.full_name || row.email || row.user_id || '-';
            const activeBadge = Number(row.is_active || 0) === 1
                ? '<span class="badge text-bg-success">Aktif</span>'
                : '<span class="badge text-bg-secondary">Pasif</span>';

            $tb.append(`
                <tr class="mobile-card-row">
                    <td>${esc(userLabel)}</td>
                    <td>${esc(row.platform || '-')}</td>
                    <td>${esc(row.app_version || '-')}</td>
                    <td>${activeBadge}</td>
                    <td>${esc(row.last_seen_at || '-')}</td>
                    <td><span class="small text-muted">${esc(row.token_masked || '-')}</span></td>
                </tr>
            `);
        });
    }

    async function load() {
        const params = {
            search: ($('#tokenSearch').val() || '').trim(),
            platform: $('#platformFilter').val() || '',
            active: $('#activeFilter').val() || ''
        };

        const res = await api('list_tokens', 'GET', params);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Tokenlar listelenemedi.', 'error');
            return;
        }

        render(res.data?.items || []);
    }

    let searchTimer = null;
    $('#tokenSearch').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(load, 250);
    });
    $('#platformFilter, #activeFilter').on('change', load);

    $('#clearFiltersBtn').on('click', function () {
        $('#tokenSearch').val('');
        $('#platformFilter').val('');
        $('#activeFilter').val('');
        load();
    });

    load();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

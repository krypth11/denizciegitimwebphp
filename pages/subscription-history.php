<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'subscription-history';
$page_title = 'Abonelik Yönetimi - Abonelik Geçmişi';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid subscription-page">
    <div class="page-header">
        <div>
            <h2>Abonelik Geçmişi</h2>
            <p class="text-muted mb-0">Normalize edilmiş abonelik geçmişi kayıtları.</p>
        </div>
    </div>

    <div class="card sub-soft-card mb-3">
        <div class="card-body">
            <div class="row g-2">
                <div class="col-12 col-lg-4"><input type="search" id="fltUser" class="form-control" placeholder="Kullanıcı (user_id) ara"></div>
                <div class="col-8 col-lg-3"><input type="text" id="fltEventType" class="form-control" placeholder="Event type"></div>
                <div class="col-4 col-lg-2"><button id="btnFilter" class="btn btn-primary w-100">Filtrele</button></div>
            </div>
        </div>
    </div>

    <div class="card sub-soft-card">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Kullanıcı</th><th>Olay</th><th>Plan</th><th>Provider/Store</th><th>Entitlement</th><th>Eski</th><th>Yeni</th><th>Kaynak</th><th>Tarih</th>
                    </tr>
                </thead>
                <tbody id="historyBody"><tr><td colspan="9" class="text-muted">Yükleniyor...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/subscriptions.php';
    const esc = (t) => $('<div>').text(t ?? '').html();
    const fmtDate = (v) => v ? (window.formatDate ? window.formatDate(v) : v) : '-';
    const api = async (action, data = {}) => window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method: 'GET', data, dataType: 'json' });

    const filters = () => ({
        user: ($('#fltUser').val() || '').trim(),
        event_type: ($('#fltEventType').val() || '').trim(),
        limit: 300
    });

    function render(items) {
        const userCell = (x) => {
            const fullName = (x.full_name || '').trim();
            const email = (x.email || '').trim();
            const userId = (x.user_id || '').trim();

            if (!fullName && !email) {
                return `<small>${esc(userId || '-')}</small>`;
            }

            return `
                <div>
                    <div class="fw-semibold text-truncate">${esc(fullName || userId || '-')}</div>
                    <div class="small text-muted text-truncate">${esc(email || '-')}</div>
                    <div class="xsmall text-secondary text-truncate">${esc(userId || '-')}</div>
                </div>
            `;
        };

        const rows = (items || []).map(x => `
            <tr>
                <td>${userCell(x)}</td>
                <td><span class="badge text-bg-light border">${esc(x.event_type || '-')}</span></td>
                <td>${esc(x.plan_code || '-')}</td>
                <td><small>${esc((x.provider || '-') + ' / ' + (x.store || '-'))}</small></td>
                <td><small>${esc(x.entitlement_id || '-')}</small></td>
                <td><small>${esc(x.old_value || '-')}</small></td>
                <td><small>${esc(x.new_value || '-')}</small></td>
                <td><small>${esc(x.source || '-')}</small></td>
                <td><small class="text-muted">${esc(fmtDate(x.created_at))}</small></td>
            </tr>
        `).join('');
        $('#historyBody').html(rows || '<tr><td colspan="9" class="text-muted">Kayıt bulunamadı.</td></tr>');
    }

    async function load() {
        const res = await api('list_history', filters());
        if (!res.success) {
            if (window.showAppAlert) await window.showAppAlert({ title: 'Hata', message: res.message || 'Geçmiş alınamadı.', type: 'error' });
            return;
        }
        render(res.data?.items || []);
    }

    $('#btnFilter').on('click', load);
    $('#fltUser, #fltEventType').on('input', function () { clearTimeout(window._subHistDeb); window._subHistDeb = setTimeout(load, 250); });

    load();
});
</script>

<style>
.subscription-page .sub-soft-card{border:none;box-shadow:var(--shadow-soft);}
.subscription-page .xsmall{font-size:11px;}
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

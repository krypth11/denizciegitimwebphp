<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'subscription-active';
$page_title = 'Abonelik Yönetimi - Aktif Premiumlar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid subscription-page">
    <div class="page-header">
        <div>
            <h2>Aktif Premiumlar</h2>
            <p class="text-muted mb-0">Şu an premium aktif durumda olan kullanıcılar.</p>
        </div>
    </div>

    <div class="card sub-soft-card">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Ad Soyad</th><th>Email</th><th>Plan</th><th>Entitlement</th><th>Expires At</th><th>Provider</th><th>Last Synced</th><th>RC App User ID</th>
                    </tr>
                </thead>
                <tbody id="activeBody"><tr><td colspan="8" class="text-muted">Yükleniyor...</td></tr></tbody>
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

    const api = async () => window.appAjax({
        url: endpoint + '?action=list_active',
        method: 'GET',
        data: { limit: 500 },
        dataType: 'json'
    });

    function render(items) {
        const rows = (items || []).map(x => `
            <tr>
                <td>${esc(x.full_name || '-')}</td>
                <td><small>${esc(x.email || '-')}</small></td>
                <td>${esc(x.plan_code || '-')}</td>
                <td><small>${esc(x.entitlement_id || '-')}</small></td>
                <td><small>${esc(fmtDate(x.expires_at))}</small></td>
                <td><span class="badge text-bg-light border">${esc(x.provider || '-')}</span></td>
                <td><small>${esc(fmtDate(x.last_synced_at))}</small></td>
                <td><small>${esc(x.rc_app_user_id || '-')}</small></td>
            </tr>
        `).join('');
        $('#activeBody').html(rows || '<tr><td colspan="8" class="text-muted">Aktif premium bulunamadı.</td></tr>');
    }

    api().then(res => {
        if (!res.success) {
            if (window.showAppAlert) window.showAppAlert({ title: 'Hata', message: res.message || 'Aktif premium listesi alınamadı.', type: 'error' });
            return;
        }
        render(res.data?.items || []);
    });
});
</script>

<style>.subscription-page .sub-soft-card{border:none;box-shadow:var(--shadow-soft);}</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

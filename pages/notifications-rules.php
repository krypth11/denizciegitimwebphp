<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'notifications-rules';
$page_title = 'Kurallar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kurallar</h2>
            <p class="text-muted mb-0">Otomasyon kurallarını aktif/pasif yönetimi ve konfigürasyonu.</p>
        </div>
    </div>

    <div class="row g-3" id="rulesContainer"></div>
    <div class="alert alert-light text-muted d-none mt-2" id="rulesEmpty">Kural bulunamadı.</div>
</div>

<div class="modal fade" id="ruleConfigModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Kural Konfigürasyonu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ruleConfigId">
                <label class="form-label">Config JSON</label>
                <textarea class="form-control font-monospace" id="ruleConfigJson" rows="8"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="saveRuleConfigBtn">Kaydet</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/notifications.php';
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });

    const defaults = [
        { key: 'daily_rights_reset', title: 'Günlük haklar yenilendi', description: 'Kullanıcılara günlük haklarının yenilendiğini hatırlatır.' },
        { key: 'inactive_3_days', title: '3 gündür aktif değil', description: '3 gündür uygulamaya girmeyen kullanıcıları hedefler.' },
        { key: 'no_exam_7_days', title: '7 gündür deneme çözmedi', description: 'Sınav pratik alışkanlığını artırmak için bildirim gönderir.' },
        { key: 'premium_expiring', title: 'Premium süresi bitiyor', description: 'Premium bitişi yaklaşan kullanıcıları bilgilendirir.' }
    ];

    const esc = (txt) => $('<div>').text(txt ?? '').html();
    const api = async (action, method = 'GET', data = {}) => await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });

    function normalizeRules(items) {
        const byKey = {};
        items.forEach(i => {
            const key = i.slug || i.name || i.id;
            byKey[key] = i;
        });

        return defaults.map(def => {
            const row = byKey[def.key] || Object.values(byKey).find(x => (x.name || '').toLowerCase() === def.title.toLowerCase()) || null;
            if (!row) {
                return {
                    id: '',
                    slug: def.key,
                    name: def.title,
                    description: def.description,
                    is_active: 0,
                    config_json: '{}'
                };
            }
            return {
                id: row.id || '',
                slug: row.slug || def.key,
                name: row.name || def.title,
                description: row.description || def.description,
                is_active: Number(row.is_active || 0),
                config_json: row.config_json || '{}'
            };
        });
    }

    function render(items) {
        const $wrap = $('#rulesContainer').empty();
        $('#rulesEmpty').toggleClass('d-none', items.length > 0);

        items.forEach(rule => {
            const checked = Number(rule.is_active || 0) === 1 ? 'checked' : '';
            $wrap.append(`
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <h6 class="mb-1">${esc(rule.name || '-')}</h6>
                                    <div class="text-muted small">${esc(rule.description || '')}</div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input js-rule-toggle" type="checkbox" data-id="${esc(rule.id)}" data-slug="${esc(rule.slug)}" ${checked}>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button class="btn btn-sm btn-secondary js-rule-config" data-id="${esc(rule.id)}" data-name="${esc(rule.name)}" data-config="${esc(rule.config_json || '{}')}">
                                    <i class="bi bi-gear"></i> Config
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function load() {
        const res = await api('list_rules');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kurallar yüklenemedi.', 'error');
            return;
        }
        render(normalizeRules(res.data?.items || []));
    }

    $(document).on('change', '.js-rule-toggle', async function () {
        const id = $(this).data('id');
        if (!id) {
            await appAlert('Bilgi', 'Bu kural veritabanında henüz tanımlı değil.', 'warning');
            $(this).prop('checked', !$(this).is(':checked'));
            return;
        }
        const isActive = $(this).is(':checked') ? 1 : 0;
        const res = await api('toggle_rule', 'POST', { rule_id: id, is_active: isActive });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kural güncellenemedi.', 'error');
            $(this).prop('checked', !$(this).is(':checked'));
            return;
        }
    });

    $(document).on('click', '.js-rule-config', function () {
        const id = $(this).data('id') || '';
        const name = $(this).data('name') || 'Kural';
        const config = $(this).attr('data-config') || '{}';
        $('#ruleConfigId').val(id);
        $('#ruleConfigJson').val(config);
        $('#ruleConfigModal .modal-title').text(name + ' - Config');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleConfigModal')).show();
    });

    $('#saveRuleConfigBtn').on('click', async function () {
        const id = $('#ruleConfigId').val() || '';
        const config = ($('#ruleConfigJson').val() || '').trim();

        if (!id) {
            await appAlert('Uyarı', 'Bu kural veritabanında henüz tanımlı değil.', 'warning');
            return;
        }

        try { JSON.parse(config || '{}'); } catch (e) {
            await appAlert('Doğrulama', 'Config JSON geçerli değil.', 'warning');
            return;
        }

        const res = await api('toggle_rule', 'POST', { rule_id: id, config_json: config });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Config kaydedilemedi.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleConfigModal')).hide();
        await appAlert('Başarılı', 'Kural config kaydedildi.', 'success');
        load();
    });

    load();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

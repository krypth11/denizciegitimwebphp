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
                <input type="hidden" id="ruleConfigKey">
                <div id="ruleConfigFields" class="row g-2"></div>
                <div class="mt-2 small text-muted" id="ruleConfigHint"></div>
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

    const rulesMeta = {
        daily_reset: {
            title: 'Günlük haklar yenilendi',
            description: 'Kullanıcılara günlük haklarının yenilendiğini hatırlatır.',
            fields: [
                { key: 'send_hour', label: 'Gönderim Saati', type: 'number', min: 0, max: 23, default: 9 },
                { key: 'send_minute', label: 'Gönderim Dakikası', type: 'number', min: 0, max: 59, default: 0 }
            ]
        },
        inactive_3_days: {
            title: '3 gündür aktif değil',
            description: '3 gündür uygulamaya girmeyen kullanıcıları hedefler.',
            fields: [
                { key: 'days_threshold', label: 'Gün Eşiği', type: 'number', min: 1, max: 60, default: 3 },
                { key: 'send_hour', label: 'Gönderim Saati', type: 'number', min: 0, max: 23, default: 19 },
                { key: 'send_minute', label: 'Gönderim Dakikası', type: 'number', min: 0, max: 59, default: 0 }
            ]
        },
        inactive_7_days_exam: {
            title: '7 gündür deneme çözmedi',
            description: 'Son 7 günde deneme tamamlamayan kullanıcıları hedefler.',
            fields: [
                { key: 'days_threshold', label: 'Gün Eşiği', type: 'number', min: 1, max: 60, default: 7 },
                { key: 'send_hour', label: 'Gönderim Saati', type: 'number', min: 0, max: 23, default: 20 },
                { key: 'send_minute', label: 'Gönderim Dakikası', type: 'number', min: 0, max: 59, default: 0 }
            ]
        },
        premium_expiring: {
            title: 'Premium süresi bitiyor',
            description: 'Premium bitişi yaklaşan kullanıcıları bilgilendirir.',
            fields: [
                { key: 'days_before_expiry', label: 'Bitişe Kalan Gün', type: 'number', min: 0, max: 30, default: 2 },
                { key: 'send_hour', label: 'Gönderim Saati', type: 'number', min: 0, max: 23, default: 12 },
                { key: 'send_minute', label: 'Gönderim Dakikası', type: 'number', min: 0, max: 59, default: 0 }
            ]
        }
    };

    const aliases = {
        daily_rights_reset: 'daily_reset',
        no_exam_7_days: 'inactive_7_days_exam'
    };

    const defaults = Object.entries(rulesMeta).map(([key, val]) => ({ key, title: val.title, description: val.description }));

    const esc = (txt) => $('<div>').text(txt ?? '').html();
    const api = async (action, method = 'GET', data = {}) => await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
    const canonicalKey = (key) => aliases[key] || key;

    function toDefaultConfig(ruleKey) {
        const meta = rulesMeta[ruleKey];
        if (!meta) return {};
        const out = {};
        meta.fields.forEach(f => out[f.key] = Number(f.default));
        return out;
    }

    function normalizeConfig(ruleKey, raw) {
        const base = toDefaultConfig(ruleKey);
        let cfg = {};
        try {
            cfg = raw ? JSON.parse(raw) : {};
        } catch (e) {
            cfg = {};
        }

        const merged = { ...base, ...(cfg || {}) };
        const meta = rulesMeta[ruleKey];
        if (!meta) return merged;

        meta.fields.forEach(f => {
            let val = Number(merged[f.key]);
            if (Number.isNaN(val)) val = Number(f.default);
            if (typeof f.min === 'number') val = Math.max(f.min, val);
            if (typeof f.max === 'number') val = Math.min(f.max, val);
            merged[f.key] = val;
        });

        return merged;
    }

    function normalizeRules(items) {
        const byKey = {};
        items.forEach(i => {
            const key = canonicalKey(i.rule_key || i.slug || i.name || i.id);
            byKey[key] = i;
        });

        return defaults.map(def => {
            const row = byKey[def.key] || Object.values(byKey).find(x => (x.name || '').toLowerCase() === def.title.toLowerCase()) || null;
            if (!row) {
                return {
                    id: '',
                    slug: def.key,
                    rule_key: def.key,
                    name: def.title,
                    description: def.description,
                    is_active: 0,
                    config_json: JSON.stringify(toDefaultConfig(def.key))
                };
            }
            return {
                id: row.id || '',
                slug: row.slug || row.rule_key || def.key,
                rule_key: canonicalKey(row.rule_key || row.slug || def.key),
                name: row.name || def.title,
                description: row.description || def.description,
                is_active: Number(row.is_active || 0),
                config_json: JSON.stringify(normalizeConfig(canonicalKey(row.rule_key || row.slug || def.key), row.config_json || '{}'))
            };
        });
    }

    function renderConfigFields(ruleKey, rawConfig) {
        const meta = rulesMeta[ruleKey];
        const $wrap = $('#ruleConfigFields').empty();
        if (!meta) {
            $wrap.html('<div class="col-12"><div class="alert alert-warning mb-0">Bu kural için tanımlı config alanı bulunamadı.</div></div>');
            return;
        }

        const config = normalizeConfig(ruleKey, rawConfig);
        meta.fields.forEach(field => {
            const val = Number(config[field.key]);
            $wrap.append(`
                <div class="col-12">
                    <label class="form-label">${esc(field.label)}</label>
                    <input
                        type="number"
                        class="form-control js-config-field"
                        data-key="${esc(field.key)}"
                        min="${field.min ?? ''}"
                        max="${field.max ?? ''}"
                        value="${esc(val)}">
                </div>
            `);
        });
    }

    function collectConfig(ruleKey) {
        const meta = rulesMeta[ruleKey];
        if (!meta) return {};

        const out = {};
        let valid = true;
        meta.fields.forEach(field => {
            let val = Number($(`.js-config-field[data-key="${field.key}"]`).val());
            if (Number.isNaN(val)) {
                valid = false;
                return;
            }
            if (typeof field.min === 'number' && val < field.min) val = field.min;
            if (typeof field.max === 'number' && val > field.max) val = field.max;
            out[field.key] = val;
        });

        return valid ? out : null;
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
                                <button class="btn btn-sm btn-secondary js-rule-config" data-id="${esc(rule.id)}" data-key="${esc(rule.rule_key || rule.slug)}" data-name="${esc(rule.name)}" data-config="${esc(rule.config_json || '{}')}">
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
        const ruleKey = canonicalKey($(this).data('key') || '');
        const name = $(this).data('name') || 'Kural';
        const config = $(this).attr('data-config') || '{}';
        $('#ruleConfigId').val(id);
        $('#ruleConfigKey').val(ruleKey);
        renderConfigFields(ruleKey, config);
        $('#ruleConfigHint').text(`Kural anahtarı: ${ruleKey}`);
        $('#ruleConfigModal .modal-title').text(name + ' - Config');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ruleConfigModal')).show();
    });

    $('#saveRuleConfigBtn').on('click', async function () {
        const id = $('#ruleConfigId').val() || '';
        const ruleKey = $('#ruleConfigKey').val() || '';

        if (!id) {
            await appAlert('Uyarı', 'Bu kural veritabanında henüz tanımlı değil.', 'warning');
            return;
        }

        const configObj = collectConfig(ruleKey);
        if (!configObj) {
            await appAlert('Doğrulama', 'Config alanlarını kontrol edin.', 'warning');
            return;
        }

        const config = JSON.stringify(configObj);

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

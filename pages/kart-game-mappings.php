<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'kart-game-mappings';
$page_title = 'Kart Oyunu - Başlık / Yeterlilik Eşleştirme';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kart Oyunu - Başlık / Yeterlilik Eşleştirme</h2>
            <p class="text-muted mb-0">Her başlığa bir veya birden çok yeterlilik bağlayın.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Başlık Ara</label>
                    <input type="search" class="form-control" id="mappingSearch" placeholder="Başlık / slug ara...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="mappingStatus">
                        <option value="">Tümü</option>
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-secondary w-100" id="mappingFilterClear"><i class="bi bi-x-circle"></i> Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div id="mappingList"></div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/kart-game-mappings.php';
    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });

    let state = { categories: [], qualifications: [], mapping: {} };

    async function api(action, method = 'GET', data = {}) {
        return await window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
    }

    function selectedSetForCategory(categoryId) {
        return new Set(state.mapping?.[categoryId] || []);
    }

    function render() {
        const q = ($('#mappingSearch').val() || '').toLowerCase().trim();
        const status = $('#mappingStatus').val();
        const wrap = $('#mappingList');
        wrap.empty();

        const rows = (state.categories || []).filter(c => {
            if (status !== '' && String(c.is_active) !== status) return false;
            if (!q) return true;
            return (String(c.title || '').toLowerCase().includes(q) || String(c.slug || '').toLowerCase().includes(q));
        });

        if (!rows.length) {
            wrap.html('<div class="card"><div class="card-body text-muted">Kayıt bulunamadı.</div></div>');
            return;
        }

        rows.forEach((c) => {
            const selected = selectedSetForCategory(String(c.id || ''));
            const optionsHtml = (state.qualifications || []).map((qItem) => {
                const checked = selected.has(String(qItem.id)) ? 'checked' : '';
                return `
                    <div class="form-check mb-1">
                        <input class="form-check-input map-qual" type="checkbox" value="${esc(qItem.id)}" id="map_${esc(c.id)}_${esc(qItem.id)}" ${checked}>
                        <label class="form-check-label" for="map_${esc(c.id)}_${esc(qItem.id)}">${esc(qItem.name)}</label>
                    </div>
                `;
            }).join('');

            const statusBadge = Number(c.is_active) === 1
                ? '<span class="badge text-bg-success">Aktif</span>'
                : '<span class="badge text-bg-secondary">Pasif</span>';

            wrap.append(`
                <div class="card mb-3 mapping-card" data-category-id="${esc(c.id)}">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <div class="fw-semibold">${esc(c.title)}</div>
                                <div class="small text-muted"><code>${esc(c.slug || '')}</code></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                ${statusBadge}
                                <button class="btn btn-sm btn-primary map-save-btn" data-category-id="${esc(c.id)}"><i class="bi bi-save"></i> Kaydet</button>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                ${optionsHtml || '<div class="text-muted small">Yeterlilik bulunamadı.</div>'}
                            </div>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function load() {
        const res = await api('list', 'GET');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Liste alınamadı.', 'error');
            return;
        }

        state = {
            categories: res.data?.categories || [],
            qualifications: res.data?.qualifications || [],
            mapping: res.data?.mapping || {}
        };
        render();
    }

    $(document).on('click', '.map-save-btn', async function () {
        const categoryId = String($(this).data('category-id') || '');
        if (!categoryId) return;

        const $card = $('.mapping-card[data-category-id="' + categoryId + '"]');
        const selected = [];
        $card.find('.map-qual:checked').each(function () {
            selected.push(String($(this).val() || ''));
        });

        const $btn = $(this);
        window.appSetButtonLoading($btn, true, 'Kaydediliyor...');
        const res = await api('save', 'POST', {
            category_id: categoryId,
            qualification_ids: selected
        });
        window.appSetButtonLoading($btn, false);

        if (!res.success) {
            await appAlert('Hata', res.message || 'Kaydetme başarısız.', 'error');
            return;
        }

        state.mapping[categoryId] = selected;
        await appAlert('Başarılı', res.message || 'Eşleştirmeler güncellendi.', 'success');
    });

    $('#mappingSearch').on('input', function () {
        clearTimeout(window.__kgMapSearchTimer);
        window.__kgMapSearchTimer = setTimeout(render, 200);
    });
    $('#mappingStatus').on('change', render);
    $('#mappingFilterClear').on('click', function () {
        $('#mappingSearch').val('');
        $('#mappingStatus').val('');
        render();
    });

    load();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

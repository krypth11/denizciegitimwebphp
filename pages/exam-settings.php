<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'exam-settings';
$page_title = 'Sınav Ayarları';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Sınav Ayarları</h2>
            <p class="text-muted mb-0">Yeterlilik bazlı deneme sınavı soru sayısı, geçme puanı ve süre ayarlarını yönetin.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle" id="examSettingsTable">
                    <thead>
                        <tr>
                            <th>Yeterlilik</th>
                            <th>Soru Sayısı</th>
                            <th>Geçme Puanı</th>
                            <th>Süre (dk)</th>
                            <th>Aktif</th>
                            <th class="text-end">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Yükleniyor...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const listUrl = '/api/v1/admin/exam-settings/list.php';
    const updateUrl = '/api/v1/admin/exam-settings/update.php';

    function esc(str) {
        return $('<div>').text(str ?? '').html();
    }

    function clampInt(value, min, max, fallback) {
        const n = Number(value);
        if (!Number.isFinite(n)) return fallback;
        const i = Math.floor(n);
        return Math.max(min, Math.min(max, i));
    }

    function clampFloat(value, min, max, fallback) {
        const n = Number(value);
        if (!Number.isFinite(n)) return fallback;
        return Math.max(min, Math.min(max, n));
    }

    function rowHtml(item) {
        const q = clampInt(item.question_count, 1, 200, 20);
        const p = clampFloat(item.passing_score, 0, 100, 60);
        const d = clampInt(item.duration_minutes, 1, 300, 40);
        const isActive = Number(item.is_active) === 1;
        const disabled = Number(item.qualification_is_active) === 1 ? '' : 'disabled';

        return `
            <tr data-qualification-id="${esc(item.qualification_id)}">
                <td>
                    <div class="fw-semibold">${esc(item.qualification_name)}</div>
                    ${Number(item.qualification_is_active) === 1 ? '' : '<small class="text-muted">Yeterlilik pasif</small>'}
                </td>
                <td><input type="number" class="form-control form-control-sm js-question-count" min="1" max="200" value="${q}" ${disabled}></td>
                <td><input type="number" class="form-control form-control-sm js-passing-score" min="0" max="100" step="0.01" value="${p}" ${disabled}></td>
                <td><input type="number" class="form-control form-control-sm js-duration-minutes" min="1" max="300" value="${d}" ${disabled}></td>
                <td>
                    <div class="form-check form-switch">
                        <input class="form-check-input js-is-active" type="checkbox" ${isActive ? 'checked' : ''} ${disabled}>
                    </div>
                </td>
                <td class="text-end">
                    <button class="btn btn-primary btn-sm js-save-row" ${disabled}><i class="bi bi-save"></i> Kaydet</button>
                </td>
            </tr>
        `;
    }

    async function loadRows() {
        const $tbody = $('#examSettingsTable tbody');
        $tbody.html('<tr><td colspan="6" class="text-center text-muted py-4">Yükleniyor...</td></tr>');

        try {
            const res = await window.appAjax({ url: listUrl, method: 'GET', dataType: 'json' });
            const items = Array.isArray(res?.data?.items) ? res.data.items : [];

            if (!items.length) {
                $tbody.html('<tr><td colspan="6" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>');
                return;
            }

            $tbody.html(items.map(rowHtml).join(''));
        } catch (err) {
            $tbody.html('<tr><td colspan="6" class="text-center text-danger py-4">Ayarlar yüklenemedi.</td></tr>');
            await window.showAppAlert({ title: 'Hata', message: err?.message || 'Sınav ayarları alınamadı.', type: 'error' });
        }
    }

    async function saveRow($tr) {
        const qualificationId = String($tr.data('qualification-id') || '').trim();
        const questionCount = clampInt($tr.find('.js-question-count').val(), 1, 200, NaN);
        const passingScore = clampFloat($tr.find('.js-passing-score').val(), 0, 100, NaN);
        const durationMinutes = clampInt($tr.find('.js-duration-minutes').val(), 1, 300, NaN);
        const isActive = $tr.find('.js-is-active').is(':checked') ? 1 : 0;

        if (!qualificationId || !Number.isFinite(questionCount) || !Number.isFinite(passingScore) || !Number.isFinite(durationMinutes)) {
            await window.showAppAlert({ title: 'Geçersiz Veri', message: 'Lütfen tüm alanlara geçerli değer girin.', type: 'warning' });
            return;
        }

        const $btn = $tr.find('.js-save-row');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Kaydediliyor');

        try {
            await window.appAjax({
                url: updateUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    qualification_id: qualificationId,
                    question_count: questionCount,
                    passing_score: passingScore,
                    duration_minutes: durationMinutes,
                    is_active: isActive
                }
            });

            await window.showAppAlert({ title: 'Başarılı', message: 'Sınav ayarı güncellendi.', type: 'success' });
        } catch (err) {
            await window.showAppAlert({ title: 'Hata', message: err?.message || 'Kayıt güncellenemedi.', type: 'error' });
        } finally {
            $btn.prop('disabled', false).html(oldHtml);
        }
    }

    $(document).on('click', '.js-save-row', function () {
        const $tr = $(this).closest('tr');
        saveRow($tr);
    });

    loadRows();
});
</script>
JAVASCRIPT;

include '../includes/footer.php';

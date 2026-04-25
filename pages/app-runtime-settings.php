<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'app-runtime-settings';
$page_title = 'Uygulama Limitleri';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Uygulama Limitleri</h2>
            <p class="text-muted mb-0">Dashboard, free kullanım ve deneme akışındaki sayısal limitleri yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" id="saveRuntimeSettingsBtn"><i class="bi bi-save"></i> Kaydet</button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form id="runtimeSettingsForm" autocomplete="off">
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="dashboard_daily_goal_questions">Dashboard günlük hedef soru sayısı</label>
                        <input type="number" class="form-control" id="dashboard_daily_goal_questions" name="dashboard_daily_goal_questions" min="1" max="500" step="1" required>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="free_daily_study_question_limit">Free kullanıcı günlük çalışma soru hakkı</label>
                        <input type="number" class="form-control" id="free_daily_study_question_limit" name="free_daily_study_question_limit" min="1" max="1000" step="1" required>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="free_daily_mock_exam_limit">Free kullanıcı günlük deneme hakkı</label>
                        <input type="number" class="form-control" id="free_daily_mock_exam_limit" name="free_daily_mock_exam_limit" min="0" max="100" step="1" required>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="study_all_questions_max_limit">Çalışma modu tüm sorulardan max soru sınırı</label>
                        <input type="number" class="form-control" id="study_all_questions_max_limit" name="study_all_questions_max_limit" min="1" max="2000" step="1" required>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="mock_exam_question_count">Deneme soru sayısı</label>
                        <input type="number" class="form-control" id="mock_exam_question_count" name="mock_exam_question_count" min="1" max="200" step="1" required>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const getUrl = '/api/v1/admin/app-runtime-settings/get.php';
    const updateUrl = '/api/v1/admin/app-runtime-settings/update.php';

    const fieldRules = {
        dashboard_daily_goal_questions: { min: 1, max: 500 },
        free_daily_study_question_limit: { min: 1, max: 1000 },
        free_daily_mock_exam_limit: { min: 0, max: 100 },
        study_all_questions_max_limit: { min: 1, max: 2000 },
        mock_exam_question_count: { min: 1, max: 200 }
    };

    function clampInt(value, min, max, fallback) {
        const num = Number(value);
        if (!Number.isFinite(num)) return fallback;
        const intVal = Math.floor(num);
        return Math.min(max, Math.max(min, intVal));
    }

    function setFormValues(settings) {
        Object.keys(fieldRules).forEach((key) => {
            const rule = fieldRules[key];
            const fallback = rule.min;
            const safeValue = clampInt(settings?.[key], rule.min, rule.max, fallback);
            $('#' + key).val(safeValue);
        });
    }

    function collectPayload() {
        const payload = {};
        for (const [key, rule] of Object.entries(fieldRules)) {
            const rawVal = $('#' + key).val();
            const safeVal = clampInt(rawVal, rule.min, rule.max, NaN);
            if (!Number.isFinite(safeVal)) {
                return { error: key };
            }
            payload[key] = safeVal;
        }
        return { payload };
    }

    async function loadSettings() {
        try {
            const res = await window.appAjax({ url: getUrl, method: 'GET', dataType: 'json' });
            setFormValues(res?.data?.settings || {});
        } catch (err) {
            await window.showAppAlert({ title: 'Hata', message: err?.message || 'Ayarlar alınamadı.', type: 'error' });
        }
    }

    async function saveSettings() {
        const result = collectPayload();
        if (result.error) {
            await window.showAppAlert({ title: 'Geçersiz Değer', message: 'Lütfen tüm alanlara geçerli bir sayı girin.', type: 'warning' });
            return;
        }

        const $btn = $('#saveRuntimeSettingsBtn');
        const originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Kaydediliyor...');

        try {
            const res = await window.appAjax({
                url: updateUrl,
                method: 'POST',
                data: result.payload,
                dataType: 'json'
            });

            setFormValues(res?.data?.settings || result.payload);
            await window.showAppAlert({ title: 'Başarılı', message: 'Uygulama limitleri güncellendi.', type: 'success' });
        } catch (err) {
            await window.showAppAlert({ title: 'Hata', message: err?.message || 'Ayarlar kaydedilemedi.', type: 'error' });
        } finally {
            $btn.prop('disabled', false).html(originalHtml);
        }
    }

    $('#saveRuntimeSettingsBtn').on('click', saveSettings);
    $('#runtimeSettingsForm').on('submit', function (e) {
        e.preventDefault();
        saveSettings();
    });

    loadSettings();
});
</script>
JAVASCRIPT;

include '../includes/footer.php';

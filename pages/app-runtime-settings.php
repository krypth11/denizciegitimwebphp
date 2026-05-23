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
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="study_auto_advance_delay_ms">Doğru cevapta otomatik ilerleme bekleme süresi (ms)</label>
                        <input type="number" class="form-control" id="study_auto_advance_delay_ms" name="study_auto_advance_delay_ms" min="100" max="5000" step="1" required>
                        <div class="form-text">Örn: 500 = 0.5 saniye. Mobil ve portal çalışma modunda doğru cevap sonrası otomatik geçiş için kullanılır.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="rewarded_study_bonus">Reklamla kazanılacak çalışma hakkı</label>
                        <input type="number" class="form-control" id="rewarded_study_bonus" name="rewarded_study_bonus" min="1" max="100" step="1" value="10" required>
                        <div class="form-text">Kullanıcı ödüllü reklam izlediğinde eklenecek çalışma soru hakkı.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="rewarded_mock_exam_bonus">Reklamla kazanılacak deneme hakkı</label>
                        <input type="number" class="form-control" id="rewarded_mock_exam_bonus" name="rewarded_mock_exam_bonus" min="1" max="10" step="1" value="1" required>
                        <div class="form-text">Kullanıcı ödüllü reklam izlediğinde eklenecek deneme hakkı.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="rewarded_study_daily_ad_limit">Günlük çalışma reklamı izleme limiti</label>
                        <input type="number" class="form-control" id="rewarded_study_daily_ad_limit" name="rewarded_study_daily_ad_limit" min="0" max="20" step="1" value="3" required>
                        <div class="form-text">Ücretsiz/guest kullanıcı bir günde çalışma hakkı için en fazla kaç reklam izleyebilir. 0 = kapalı.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="rewarded_mock_exam_daily_ad_limit">Günlük deneme reklamı izleme limiti</label>
                        <input type="number" class="form-control" id="rewarded_mock_exam_daily_ad_limit" name="rewarded_mock_exam_daily_ad_limit" min="0" max="10" step="1" value="1" required>
                        <div class="form-text">Ücretsiz/guest kullanıcı bir günde deneme hakkı için en fazla kaç reklam izleyebilir. 0 = kapalı.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="kart_game_daily_attempt_limit">Kart oyunu günlük görev oynama hakkı</label>
                        <input type="number" class="form-control" id="kart_game_daily_attempt_limit" name="kart_game_daily_attempt_limit" min="0" max="100" step="1" value="5" required>
                        <div class="form-text">Sıralı Mod için tüm kullanıcıların günlük leaderboard/XP etkili oynama hakkı. Premium kullanıcılar da bu limite tabidir. 0 = kapalı.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="kart_game_practice_daily_limit">Kart oyunu pratik mod günlük hak</label>
                        <input type="number" class="form-control" id="kart_game_practice_daily_limit" name="kart_game_practice_daily_limit" min="0" max="999" step="1" value="20" required>
                        <div class="form-text">Hızlı Tur / Uzun Seyir / Sonsuz Mod için ortak günlük hak.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="kart_game_ranked_free_plays">Kart oyunu sıralı mod ücretsiz hak</label>
                        <input type="number" class="form-control" id="kart_game_ranked_free_plays" name="kart_game_ranked_free_plays" min="0" max="999" step="1" value="1" required>
                        <div class="form-text">Premium olmayan kullanıcıların reklamsız sıralı mod hakkı. Toplam sıralı mod hakkı genel sıralı mod limitini aşamaz.</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="kart_game_ranked_rewarded_plays">Kart oyunu sıralı mod reklamlı ekstra hak</label>
                        <input type="number" class="form-control" id="kart_game_ranked_rewarded_plays" name="kart_game_ranked_rewarded_plays" min="0" max="999" step="1" value="4" required>
                        <div class="form-text">Premium olmayan kullanıcıların reklam izleyerek kullanabileceği ekstra sıralı mod hakkı. Toplam sıralı mod hakkı genel sıralı mod limitini aşamaz.</div>
                    </div>
                </div>

                <hr class="my-4">
                <h5 class="mb-3">Soru Tipi Etiketleri</h5>
                <p class="text-muted">Admin panelde Sorular listesindeki Senaryo/GASM geçişlerinde gösterilecek metinler.</p>
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="question_source_scenario_label">Senaryo tipi etiketi</label>
                        <input type="text" class="form-control" id="question_source_scenario_label" name="question_source_scenario_label" maxlength="100" required>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="question_source_gasm_label">GASM tipi etiketi</label>
                        <input type="text" class="form-control" id="question_source_gasm_label" name="question_source_gasm_label" maxlength="100" required>
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
        mock_exam_question_count: { min: 1, max: 200 },
        study_auto_advance_delay_ms: { min: 100, max: 5000 },
        rewarded_study_bonus: { min: 1, max: 100 },
        rewarded_mock_exam_bonus: { min: 1, max: 10 },
        rewarded_study_daily_ad_limit: { min: 0, max: 20 },
        rewarded_mock_exam_daily_ad_limit: { min: 0, max: 10 },
        kart_game_daily_attempt_limit: { min: 0, max: 100 },
        kart_game_practice_daily_limit: { min: 0, max: 999 },
        kart_game_ranked_free_plays: { min: 0, max: 999 },
        kart_game_ranked_rewarded_plays: { min: 0, max: 999 }
    };
    const textFieldRules = {
        question_source_scenario_label: { fallback: 'Senaryo Tipi', max: 100 },
        question_source_gasm_label: { fallback: 'GASM Tipi', max: 100 }
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
        Object.keys(textFieldRules).forEach((key) => {
            const rule = textFieldRules[key];
            const raw = String(settings?.[key] ?? rule.fallback).trim();
            const safe = (raw || rule.fallback).slice(0, rule.max);
            $('#' + key).val(safe);
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
        for (const [key, rule] of Object.entries(textFieldRules)) {
            const rawVal = String($('#' + key).val() ?? '').trim();
            if (!rawVal) {
                return { error: key };
            }
            if (rawVal.length > rule.max) {
                return { error: key };
            }
            payload[key] = rawVal;
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
                await window.showAppAlert({ title: 'Geçersiz Değer', message: 'Lütfen tüm alanlara geçerli değer girin.', type: 'warning' });
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

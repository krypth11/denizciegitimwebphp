<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'questions-export';
$page_title = 'Soru Dışa Aktar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Soru Dışa Aktar</h2>
            <p class="text-muted mb-0">Soruları CSV, Excel, JSON ve AI paket formatlarında dışa aktarın</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form id="questionExportForm" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Yeterlilik *</label>
                    <select class="form-select" id="exportQualification" required>
                        <option value="">Yeterlilik seçin</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Ders</label>
                    <select class="form-select" id="exportCourse" disabled>
                        <option value="">Tüm dersler</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Konu</label>
                    <select class="form-select" id="exportTopic" disabled>
                        <option value="">Tüm konular</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Export formatı</label>
                    <select class="form-select" id="exportFormat">
                        <option value="csv" selected>CSV</option>
                        <option value="xlsx">Excel (.xlsx)</option>
                        <option value="json">JSON</option>
                        <option value="md">AI Analiz Paketi (.md)</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">İçerik şablonu / Export profili</label>
                    <select class="form-select" id="exportProfile">
                        <option value="full_data" selected>Tam veri</option>
                        <option value="question_texts">Sadece soru metinleri</option>
                        <option value="question_correct">Soru + doğru cevap</option>
                        <option value="question_correct_explanation">Soru + doğru cevap + açıklama</option>
                        <option value="ai_generation">AI üretim formatı</option>
                        <option value="ai_analysis">AI analiz formatı</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label d-block mb-2">İçerik seçenekleri</label>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeOptions" checked>
                                <label class="form-check-label" for="includeOptions">Şıkları dahil et</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeCorrectAnswer" checked>
                                <label class="form-check-label" for="includeCorrectAnswer">Doğru cevabı dahil et</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeExplanation" checked>
                                <label class="form-check-label" for="includeExplanation">Açıklamayı dahil et</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeTaxonomy" checked>
                                <label class="form-check-label" for="includeTaxonomy">Yeterlilik / ders / konu bilgilerini dahil et</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="includeIds" checked>
                                <label class="form-check-label" for="includeIds">ID alanlarını dahil et</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card bg-light-subtle border">
                        <div class="card-body py-3">
                            <h6 class="mb-2">Önizleme</h6>
                            <div class="small text-muted mb-2" id="exportCountInfo">Yeterlilik seçtiğinizde dışa aktarılacak soru sayısı gösterilecektir.</div>
                            <ul class="mb-0 ps-3 small">
                                <li><strong>Toplam Soru:</strong> <span id="previewTotal">-</span></li>
                                <li><strong>Filtre:</strong> <span id="previewFilters">-</span></li>
                                <li><strong>Format:</strong> <span id="previewFormat">CSV</span></li>
                                <li><strong>Profil:</strong> <span id="previewProfile">Tam veri</span></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary w-100" id="downloadExportBtn">
                        <i class="bi bi-download"></i> Dışa Aktar ve İndir
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const esc = (v) => $('<div>').text(v ?? '').html();

    const appAlert = (title, message, type = 'info') => {
        if (typeof window.showAppAlert === 'function') return window.showAppAlert({ title, message, type });
        return Promise.resolve();
    };

    const state = {
        filters: {
            qualification_id: '',
            course_id: '',
            topic_id: ''
        },
        preview: {
            total: 0,
            filters: {
                qualification_name: '',
                course_name: '',
                topic_name: ''
            }
        }
    };

    const profileDefaults = {
        full_data: {
            include_options: true,
            include_correct_answer: true,
            include_explanation: true,
            include_taxonomy: true,
            include_ids: true
        },
        question_texts: {
            include_options: false,
            include_correct_answer: false,
            include_explanation: false,
            include_taxonomy: false,
            include_ids: false
        },
        question_correct: {
            include_options: false,
            include_correct_answer: true,
            include_explanation: false,
            include_taxonomy: false,
            include_ids: false
        },
        question_correct_explanation: {
            include_options: false,
            include_correct_answer: true,
            include_explanation: true,
            include_taxonomy: false,
            include_ids: false
        },
        ai_generation: {
            include_options: true,
            include_correct_answer: true,
            include_explanation: true,
            include_taxonomy: true,
            include_ids: false
        },
        ai_analysis: {
            include_options: true,
            include_correct_answer: true,
            include_explanation: true,
            include_taxonomy: true,
            include_ids: true
        }
    };

    function formatLabel(value) {
        return $('#exportFormat option[value="' + value + '"]').text() || 'CSV';
    }

    function profileLabel(value) {
        return $('#exportProfile option[value="' + value + '"]').text() || 'Tam veri';
    }

    function getSelectedOptions() {
        return {
            include_options: $('#includeOptions').is(':checked') ? '1' : '0',
            include_correct_answer: $('#includeCorrectAnswer').is(':checked') ? '1' : '0',
            include_explanation: $('#includeExplanation').is(':checked') ? '1' : '0',
            include_taxonomy: $('#includeTaxonomy').is(':checked') ? '1' : '0',
            include_ids: $('#includeIds').is(':checked') ? '1' : '0'
        };
    }

    function applyProfileDefaults(profile) {
        const d = profileDefaults[profile] || profileDefaults.full_data;
        $('#includeOptions').prop('checked', !!d.include_options);
        $('#includeCorrectAnswer').prop('checked', !!d.include_correct_answer);
        $('#includeExplanation').prop('checked', !!d.include_explanation);
        $('#includeTaxonomy').prop('checked', !!d.include_taxonomy);
        $('#includeIds').prop('checked', !!d.include_ids);
    }

    function renderPreviewFromState() {
        const qName = state.preview.filters.qualification_name || '—';
        const cName = state.preview.filters.course_name || 'Tüm dersler';
        const tName = state.preview.filters.topic_name || 'Tüm konular';
        const filterText = qName + ' / ' + cName + ' / ' + tName;

        $('#previewTotal').text(Number(state.preview.total || 0).toLocaleString('tr-TR'));
        $('#previewFilters').text(filterText);
        $('#previewFormat').text(formatLabel($('#exportFormat').val()));
        $('#previewProfile').text(profileLabel($('#exportProfile').val()));
    }

    async function loadQualifications() {
        const res = await window.appAjax({
            url: '../ajax/questions.php?action=list_qualifications',
            method: 'GET',
            dataType: 'json'
        });

        const rows = res.data?.qualifications || [];
        const $q = $('#exportQualification');
        $q.html('<option value="">Yeterlilik seçin</option>');
        rows.forEach((row) => {
            $q.append('<option value="' + esc(row.id) + '">' + esc(row.name) + '</option>');
        });
    }

    async function loadCourses(qualificationId) {
        const $course = $('#exportCourse');
        $course.html('<option value="">Tüm dersler</option>');

        if (!qualificationId) {
            $course.prop('disabled', true);
            return;
        }

        const res = await window.appAjax({
            url: '../ajax/questions.php?action=list_courses',
            method: 'GET',
            data: { qualification_id: qualificationId },
            dataType: 'json'
        });

        const rows = res.data?.courses || [];
        rows.forEach((row) => {
            $course.append('<option value="' + esc(row.id) + '">' + esc(row.name) + '</option>');
        });

        $course.prop('disabled', false);
    }

    async function loadTopics(courseId) {
        const $topic = $('#exportTopic');
        $topic.html('<option value="">Tüm konular</option>');

        if (!courseId) {
            $topic.prop('disabled', true);
            return;
        }

        const res = await window.appAjax({
            url: '../ajax/questions.php?action=list_topics',
            method: 'GET',
            data: { course_id: courseId },
            dataType: 'json'
        });

        const rows = res.data?.topics || [];
        if (!rows.length) {
            $topic.prop('disabled', true);
            return;
        }

        rows.forEach((row) => {
            $topic.append('<option value="' + esc(row.id) + '">' + esc(row.name) + '</option>');
        });

        $topic.prop('disabled', false);
    }

    async function refreshPreviewCount() {
        const $info = $('#exportCountInfo');
        if (!state.filters.qualification_id) {
            $info.text('Yeterlilik seçtiğinizde dışa aktarılacak soru sayısı gösterilecektir.');
            state.preview.total = 0;
            state.preview.filters = {
                qualification_name: '',
                course_name: '',
                topic_name: ''
            };
            renderPreviewFromState();
            return;
        }

        const req = {
            action: 'preview_count',
            qualification_id: state.filters.qualification_id,
            course_id: state.filters.course_id,
            topic_id: state.filters.topic_id,
            format: $('#exportFormat').val() || 'csv',
            profile: $('#exportProfile').val() || 'full_data',
            ...getSelectedOptions()
        };

        const res = await window.appAjax({
            url: '../ajax/questions-export.php',
            method: 'GET',
            data: req,
            dataType: 'json'
        });

        if (!res.success) {
            $info.text('Soru sayısı alınamadı.');
            state.preview.total = 0;
            renderPreviewFromState();
            return;
        }

        state.preview.total = Number(res.data?.total_count || 0);
        state.preview.filters = {
            qualification_name: res.data?.filters?.qualification_name || '',
            course_name: res.data?.filters?.course_name || '',
            topic_name: res.data?.filters?.topic_name || ''
        };

        $info.text('Toplam ' + state.preview.total.toLocaleString('tr-TR') + ' soru dışa aktarılacak');
        renderPreviewFromState();
    }

    $('#exportQualification').on('change', async function () {
        state.filters.qualification_id = $(this).val() || '';
        state.filters.course_id = '';
        state.filters.topic_id = '';

        $('#exportCourse').val('');
        $('#exportTopic').html('<option value="">Tüm konular</option>').prop('disabled', true).val('');

        await loadCourses(state.filters.qualification_id);
        await refreshPreviewCount();
    });

    $('#exportCourse').on('change', async function () {
        state.filters.course_id = $(this).val() || '';
        state.filters.topic_id = '';
        $('#exportTopic').val('');

        if (!state.filters.course_id) {
            $('#exportTopic').html('<option value="">Tüm konular</option>').prop('disabled', true);
            await refreshPreviewCount();
            return;
        }

        await loadTopics(state.filters.course_id);
        await refreshPreviewCount();
    });

    $('#exportTopic').on('change', async function () {
        state.filters.topic_id = $(this).val() || '';
        await refreshPreviewCount();
    });

    $('#exportFormat').on('change', async function () {
        const selectedFormat = $(this).val() || 'csv';
        if (selectedFormat === 'md') {
            const profile = $('#exportProfile').val() || '';
            if (!['ai_generation', 'ai_analysis'].includes(profile)) {
                $('#exportProfile').val('ai_analysis');
                applyProfileDefaults('ai_analysis');
            }
        }

        renderPreviewFromState();
        await refreshPreviewCount();
    });

    $('#exportProfile').on('change', async function () {
        const profile = $(this).val() || 'full_data';
        if (profileDefaults[profile]) {
            applyProfileDefaults(profile);
        }

        if ($('#exportFormat').val() === 'md' && !['ai_generation', 'ai_analysis'].includes(profile)) {
            $('#exportFormat').val('csv');
        }

        renderPreviewFromState();
        await refreshPreviewCount();
    });

    $('#includeOptions, #includeCorrectAnswer, #includeExplanation, #includeTaxonomy, #includeIds').on('change', async function () {
        await refreshPreviewCount();
    });

    $('#questionExportForm').on('submit', async function (e) {
        e.preventDefault();

        if (!state.filters.qualification_id) {
            await appAlert('Doğrulama', 'Yeterlilik seçimi zorunludur.', 'warning');
            return;
        }

        const $btn = $('#downloadExportBtn');
        window.appSetButtonLoading($btn, true, 'Hazırlanıyor...');

        const payload = {
            action: 'download_export',
            qualification_id: state.filters.qualification_id,
            course_id: state.filters.course_id,
            topic_id: state.filters.topic_id,
            format: $('#exportFormat').val() || 'csv',
            profile: $('#exportProfile').val() || 'full_data',
            ...getSelectedOptions()
        };

        const $tmpForm = $('<form>', {
            method: 'GET',
            action: '../ajax/questions-export.php'
        }).css('display', 'none');

        Object.keys(payload).forEach((key) => {
            $('<input>', { type: 'hidden', name: key, value: payload[key] || '' }).appendTo($tmpForm);
        });

        $('body').append($tmpForm);
        $tmpForm.trigger('submit');
        $tmpForm.remove();

        setTimeout(() => window.appSetButtonLoading($btn, false), 1200);
    });

    (async function init() {
        applyProfileDefaults('full_data');
        await loadQualifications();
        await refreshPreviewCount();
        renderPreviewFromState();
    })();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

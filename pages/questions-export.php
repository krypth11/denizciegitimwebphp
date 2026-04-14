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
            <p class="text-muted mb-0">Seçilen filtrelere göre soruları CSV olarak dışa aktar</p>
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
                    <label class="form-label">İndir formatı</label>
                    <select class="form-select" id="exportFormat">
                        <option value="csv" selected>CSV</option>
                    </select>
                </div>

                <div class="col-12">
                    <div class="alert alert-info mb-0" id="exportCountInfo">Yeterlilik seçtiğinizde dışa aktarılacak soru sayısı gösterilecektir.</div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary w-100" id="downloadCsvBtn">
                        <i class="bi bi-download"></i> CSV Olarak İndir
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
        }
    };

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
            return;
        }

        const res = await window.appAjax({
            url: '../ajax/questions-export.php',
            method: 'GET',
            data: {
                action: 'preview_count',
                qualification_id: state.filters.qualification_id,
                course_id: state.filters.course_id,
                topic_id: state.filters.topic_id
            },
            dataType: 'json'
        });

        if (!res.success) {
            $info.text('Soru sayısı alınamadı.');
            return;
        }

        const total = Number(res.data?.total_count || 0);
        $info.text('Toplam ' + total.toLocaleString('tr-TR') + ' soru dışa aktarılacak');
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

    $('#questionExportForm').on('submit', async function (e) {
        e.preventDefault();

        if (!state.filters.qualification_id) {
            await appAlert('Doğrulama', 'Yeterlilik seçimi zorunludur.', 'warning');
            return;
        }

        const $btn = $('#downloadCsvBtn');
        window.appSetButtonLoading($btn, true, 'Hazırlanıyor...');

        const $tmpForm = $('<form>', {
            method: 'GET',
            action: '../ajax/questions-export.php'
        }).css('display', 'none');

        const fields = {
            action: 'download_csv',
            qualification_id: state.filters.qualification_id,
            course_id: state.filters.course_id,
            topic_id: state.filters.topic_id
        };

        Object.keys(fields).forEach((key) => {
            $('<input>', { type: 'hidden', name: key, value: fields[key] || '' }).appendTo($tmpForm);
        });

        $('body').append($tmpForm);
        $tmpForm.trigger('submit');
        $tmpForm.remove();

        setTimeout(() => window.appSetButtonLoading($btn, false), 1200);
    });

    (async function init() {
        await loadQualifications();
        await refreshPreviewCount();
    })();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

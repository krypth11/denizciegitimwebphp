<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'ai-question-review';
$page_title = 'AI Soru Kontrol';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>AI Soru Kontrol</h2>
            <p class="text-muted mb-0">AI ön denetim batch’i başlatın, uyarıları inceleyin ve admin kararı ile kapatın.</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white"><h6 class="mb-0">Batch Başlat</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Yeterlilik</label>
                    <select class="form-select" id="startQualification"><option value="">Tümü</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ders</label>
                    <select class="form-select" id="startCourse"><option value="">Tümü</option></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-block">Adet</label>
                    <div class="btn-group" role="group">
                        <button class="btn btn-outline-primary start-count active" data-count="10" type="button">10</button>
                        <button class="btn btn-outline-primary start-count" data-count="50" type="button">50</button>
                        <button class="btn btn-outline-primary start-count" data-count="100" type="button">100</button>
                    </div>
                    <input type="number" min="1" max="500" id="startCustomCount" class="form-control mt-2" placeholder="Özel sayı (öncelikli)">
                    <input type="hidden" id="startCountValue" value="10">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-primary w-100" id="startBatchBtn"><i class="bi bi-robot"></i> AI ile Kontrol Et</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="reviewStatsRow">
        <div class="col-md-3 col-6"><div class="card"><div class="card-body py-2"><small class="text-muted d-block">Toplam Soru</small><strong id="statTotalQuestions">0</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card"><div class="card-body py-2"><small class="text-muted d-block">İncelenen Soru</small><strong id="statReviewedQuestions">0</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card"><div class="card-body py-2"><small class="text-muted d-block">İncelenmeyen</small><strong id="statUnreviewedQuestions">0</strong></div></div></div>
        <div class="col-md-3 col-6"><div class="card border-primary"><div class="card-body py-2"><small class="text-muted d-block">Kapatılan / İncelendi</small><strong id="statReviewedClosedCount" class="text-primary">0</strong></div></div></div>
        <div class="col-md-4 col-6"><div class="card border-danger"><div class="card-body py-2"><small class="text-muted d-block">Hatalı (Bekleyen)</small><strong id="statErrorCount" class="text-danger">0</strong></div></div></div>
        <div class="col-md-4 col-6"><div class="card border-warning"><div class="card-body py-2"><small class="text-muted d-block">Warning (Bekleyen)</small><strong id="statWarningCount" class="text-warning">0</strong></div></div></div>
        <div class="col-md-4 col-12"><div class="card border-success"><div class="card-body py-2"><small class="text-muted d-block">OK (Bekleyen)</small><strong id="statOkCount" class="text-success">0</strong></div></div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-white"><h6 class="mb-0">Yeterlilik Bazlı Durum</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Yeterlilik</th>
                            <th>İncelenen</th>
                            <th>İncelenmeyen</th>
                            <th>Toplam</th>
                        </tr>
                    </thead>
                    <tbody id="qualificationStatsBody">
                        <tr><td colspan="4" class="text-muted">Kayıt bulunamadı.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <button class="btn btn-success w-100" id="bulkDismissOkBtn"><i class="bi bi-check2-square"></i> OK Olanları Sorun Yok Olarak Kapat</button>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white"><h6 class="mb-0">Review Listesi</h6></div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-2"><label class="form-label">AI Status</label><select class="form-select" id="filterAiStatus"><option value="">Tümü</option><option value="ok">ok</option><option value="warning">warning</option><option value="error">error</option></select></div>
                <div class="col-md-2"><label class="form-label">Review State</label><select class="form-select" id="filterReviewState"><option value="">Tümü</option><option value="pending">pending</option><option value="reviewed">reviewed</option></select></div>
                <div class="col-md-2"><label class="form-label">Yeterlilik</label><select class="form-select" id="filterQualification"><option value="">Tümü</option></select></div>
                <div class="col-md-2"><label class="form-label">Ders</label><select class="form-select" id="filterCourse"><option value="">Tümü</option></select></div>
                <div class="col-md-2"><label class="form-label">Sayfa Boyutu</label><select class="form-select" id="filterPerPage"><option value="10">10</option><option value="20">20</option><option value="50">50</option></select></div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-secondary w-100" id="refreshListBtn"><i class="bi bi-arrow-clockwise"></i> Yenile</button></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Soru</th>
                            <th>Yeterlilik</th>
                            <th>Ders</th>
                            <th>AI</th>
                            <th>Confidence</th>
                            <th>State</th>
                            <th>Tarih</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody id="reviewTableBody"></tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
                <button class="btn btn-outline-secondary btn-sm" id="paginationPrevBtn"><i class="bi bi-chevron-left"></i> Önceki</button>
                <div class="text-muted small" id="paginationInfo">Sayfa 1 / 1</div>
                <button class="btn btn-outline-secondary btn-sm" id="paginationNextBtn">Sonraki <i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="reviewDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">AI Review Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="detailReviewId">
                <div class="row g-3">
                    <div class="col-md-7">
                        <h6>Soru</h6>
                        <textarea class="form-control mb-2" id="d_question_text" rows="3"></textarea>
                        <div class="row g-2">
                            <div class="col-md-6"><input class="form-control" id="d_option_a" placeholder="A"></div>
                            <div class="col-md-6"><input class="form-control" id="d_option_b" placeholder="B"></div>
                            <div class="col-md-6"><input class="form-control" id="d_option_c" placeholder="C"></div>
                            <div class="col-md-6"><input class="form-control" id="d_option_d" placeholder="D"></div>
                            <div class="col-md-6"><input class="form-control" id="d_option_e" placeholder="E (opsiyonel)"></div>
                            <div class="col-md-6"><select class="form-select" id="d_correct_answer"><option>A</option><option>B</option><option>C</option><option>D</option><option>E</option></select></div>
                        </div>
                        <textarea class="form-control mt-2" id="d_explanation" rows="3" placeholder="Açıklama"></textarea>
                    </div>
                    <div class="col-md-5">
                        <div class="card"><div class="card-body small">
                            <div><strong>AI Status:</strong> <span id="d_ai_status"></span></div>
                            <div><strong>Confidence:</strong> <span id="d_confidence"></span></div>
                            <div><strong>Issue Types:</strong> <span id="d_issue_types"></span></div>
                            <div class="mt-2"><strong>AI Notes</strong><div id="d_ai_notes" class="text-muted"></div></div>
                            <div class="mt-2"><strong>Suggested Fix</strong><div id="d_suggested_fix" class="text-muted"></div></div>
                            <div class="mt-2"><strong>Review State:</strong> <span id="d_review_state"></span></div>
                            <div class="mt-2"><label class="form-label">Admin Notu (dismissed)</label><textarea class="form-control" id="d_admin_notes" rows="2"></textarea></div>
                        </div></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="resolveFixedBtn">Soruyu Düzenle ve İncelemeyi Kapat</button>
                <button type="button" class="btn btn-success" id="resolveDismissedBtn">Sorun Yok, İncelemeyi Kapat</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const listEndpoint = '../ajax/ai-question-review-list.php';
    const startEndpoint = '../ajax/ai-question-review-start.php';
    const detailEndpoint = '../ajax/ai-question-review-detail.php';
    const resolveEndpoint = '../ajax/ai-question-review-resolve.php';

    let courses = [];
    let qualifications = [];
    const listState = {
        page: 1,
        per_page: 10
    };

    const badgeAi = (v) => v === 'ok' ? 'bg-success' : (v === 'warning' ? 'bg-warning text-dark' : 'bg-danger');
    const badgeState = (v) => v === 'reviewed' ? 'bg-primary' : 'bg-secondary';

    function getListParams() {
        return {
            ai_status: $('#filterAiStatus').val() || '',
            review_state: $('#filterReviewState').val() || '',
            qualification_id: $('#filterQualification').val() || '',
            course_id: $('#filterCourse').val() || '',
            page: listState.page,
            per_page: listState.per_page
        };
    }

    function updateStats(stats) {
        $('#statTotalQuestions').text(stats?.total_questions ?? 0);
        $('#statReviewedQuestions').text(stats?.reviewed_questions ?? 0);
        $('#statUnreviewedQuestions').text(stats?.unreviewed_questions ?? 0);
        $('#statErrorCount').text(stats?.error_count ?? 0);
        $('#statWarningCount').text(stats?.warning_count ?? 0);
        $('#statOkCount').text(stats?.ok_count ?? 0);
        $('#statReviewedClosedCount').text(stats?.reviewed_closed_count ?? 0);
    }

    function updateQualificationStats(list) {
        const $body = $('#qualificationStatsBody');
        $body.empty();
        if (!Array.isArray(list) || !list.length) {
            $body.html('<tr><td colspan="4" class="text-muted">Kayıt bulunamadı.</td></tr>');
            return;
        }

        list.forEach(item => {
            $body.append(`
                <tr>
                    <td>${item.qualification_name || '-'}</td>
                    <td><span class="badge bg-primary">${item.reviewed_questions ?? 0}</span></td>
                    <td><span class="badge bg-secondary">${item.unreviewed_questions ?? 0}</span></td>
                    <td><strong>${item.total_questions ?? 0}</strong></td>
                </tr>
            `);
        });
    }

    function updatePaginationUI(pagination) {
        const page = pagination?.page ?? 1;
        const totalPages = pagination?.total_pages ?? 1;
        $('#paginationInfo').text(`Sayfa ${page} / ${totalPages} • Toplam ${pagination?.total ?? 0} kayıt`);
        $('#paginationPrevBtn').prop('disabled', !pagination?.has_prev);
        $('#paginationNextBtn').prop('disabled', !pagination?.has_next);
    }

    async function loadList() {
        const params = getListParams();
        const res = await window.appAjax({ url: listEndpoint, method: 'GET', data: params });
        if (!res.success) {
            return window.showAppAlert('Hata', res.message || 'Liste yüklenemedi', 'error');
        }

        qualifications = res.data?.qualifications || [];
        courses = res.data?.courses || [];

        const setOptions = ($el, arr, textKey = 'name') => {
            const current = $el.val() || '';
            $el.html('<option value="">Tümü</option>');
            arr.forEach(x => $el.append(`<option value="${x.id}">${x[textKey]}</option>`));
            if (current) $el.val(current);
        };

        setOptions($('#startQualification'), qualifications);
        setOptions($('#filterQualification'), qualifications);

        const fQual = $('#filterQualification').val() || '';
        const sQual = $('#startQualification').val() || '';
        const filteredCoursesForFilter = fQual ? courses.filter(c => String(c.qualification_id) === String(fQual)) : courses;
        const filteredCoursesForStart = sQual ? courses.filter(c => String(c.qualification_id) === String(sQual)) : courses;

        setOptions($('#filterCourse'), filteredCoursesForFilter);
        setOptions($('#startCourse'), filteredCoursesForStart);

        const pagination = res.data?.pagination || {};
        listState.page = Number(pagination.page || 1);
        listState.per_page = Number(pagination.per_page || listState.per_page || 10);
        $('#filterPerPage').val(String(listState.per_page));

        updateStats(res.data?.stats || {});
        updateQualificationStats(res.data?.qualification_stats || []);
        updatePaginationUI(pagination);

        const rows = res.data?.reviews || [];
        const $tb = $('#reviewTableBody');
        $tb.empty();
        if (!rows.length) {
            $tb.html('<tr><td colspan="8" class="text-muted">Kayıt bulunamadı.</td></tr>');
            return;
        }

        rows.forEach(r => {
            $tb.append(`
                <tr>
                    <td>${(r.question_text || '').slice(0, 120)}</td>
                    <td>${r.qualification_name || '-'}</td>
                    <td>${r.course_name || '-'}</td>
                    <td><span class="badge ${badgeAi(r.ai_status)}">${r.ai_status}</span></td>
                    <td>${r.confidence_score ?? '-'}</td>
                    <td><span class="badge ${badgeState(r.review_state)}">${r.review_state}</span></td>
                    <td>${r.created_at || ''}</td>
                    <td><button class="btn btn-sm btn-outline-primary open-detail" data-id="${r.id}">Önizle / İncele</button></td>
                </tr>
            `);
        });
    }

    function calcRequestedCount() {
        const custom = parseInt($('#startCustomCount').val(), 10);
        if (!Number.isNaN(custom) && custom > 0) return custom;
        return parseInt($('#startCountValue').val(), 10) || 10;
    }

    $('#startQualification').on('change', function () {
        const q = $(this).val() || '';
        const filtered = q ? courses.filter(c => String(c.qualification_id) === String(q)) : courses;
        const $c = $('#startCourse');
        const current = $c.val() || '';
        $c.html('<option value="">Tümü</option>');
        filtered.forEach(x => $c.append(`<option value="${x.id}">${x.name}</option>`));
        if (current && filtered.some(x => String(x.id) === String(current))) $c.val(current); else $c.val('');
    });

    $('#filterQualification').on('change', function () {
        const q = $(this).val() || '';
        const filtered = q ? courses.filter(c => String(c.qualification_id) === String(q)) : courses;
        const $c = $('#filterCourse');
        $c.html('<option value="">Tümü</option>');
        filtered.forEach(x => $c.append(`<option value="${x.id}">${x.name}</option>`));
        $c.val('');
        listState.page = 1;
        loadList();
    });

    $('.start-count').on('click', function () {
        $('.start-count').removeClass('active');
        $(this).addClass('active');
        $('#startCountValue').val($(this).data('count'));
    });

    $('#refreshListBtn').on('click', loadList);

    $('#filterAiStatus, #filterReviewState, #filterCourse').on('change', function () {
        listState.page = 1;
        loadList();
    });

    $('#filterPerPage').on('change', function () {
        listState.per_page = parseInt($(this).val(), 10) || 10;
        listState.page = 1;
        loadList();
    });

    $('#paginationPrevBtn').on('click', function () {
        if (listState.page > 1) {
            listState.page -= 1;
            loadList();
        }
    });

    $('#paginationNextBtn').on('click', function () {
        listState.page += 1;
        loadList();
    });

    $('#startBatchBtn').on('click', async function () {
        const requested = calcRequestedCount();
        if (requested < 1 || requested > 500) {
            return window.showAppAlert('Uyarı', 'Adet 1-500 arasında olmalı', 'warning');
        }
        window.appSetButtonLoading('#startBatchBtn', true, 'Çalışıyor...');
        const res = await window.appAjax({
            url: startEndpoint,
            method: 'POST',
            data: {
                qualification_id: $('#startQualification').val() || '',
                course_id: $('#startCourse').val() || '',
                requested_count: requested
            }
        });
        window.appSetButtonLoading('#startBatchBtn', false);
        if (!res.success) {
            return window.showAppAlert('Hata', res.message || 'Batch başlatılamadı', 'error');
        }
        await window.showAppAlert('Başarılı', `Batch tamamlandı. İstenen: ${res.data?.requested_count}, Seçilen: ${res.data?.actual_count}`, 'success');
        loadList();
    });

    $(document).on('click', '.open-detail', async function () {
        const reviewId = $(this).data('id');
        const res = await window.appAjax({ url: detailEndpoint, method: 'GET', data: { review_id: reviewId } });
        if (!res.success) {
            return window.showAppAlert('Hata', res.message || 'Detay alınamadı', 'error');
        }
        const d = res.data?.review || {};
        $('#detailReviewId').val(d.id || '');
        $('#d_question_text').val(d.question_text || '');
        $('#d_option_a').val(d.option_a || '');
        $('#d_option_b').val(d.option_b || '');
        $('#d_option_c').val(d.option_c || '');
        $('#d_option_d').val(d.option_d || '');
        $('#d_option_e').val(d.option_e || '');
        $('#d_correct_answer').val((d.correct_answer || 'A').toUpperCase());
        $('#d_explanation').val(d.explanation || '');

        $('#d_ai_status').html(`<span class="badge ${badgeAi(d.ai_status)}">${d.ai_status || '-'}</span>`);
        $('#d_confidence').text(d.confidence_score ?? '-');
        $('#d_issue_types').text(Array.isArray(d.issue_types) ? d.issue_types.join(', ') : '-');
        $('#d_ai_notes').text(d.ai_notes || '-');
        $('#d_suggested_fix').text(d.suggested_fix || '-');
        $('#d_review_state').html(`<span class="badge ${badgeState(d.review_state)}">${d.review_state || '-'}</span>`);
        $('#d_admin_notes').val(d.admin_notes || '');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewDetailModal')).show();
    });

    $('#resolveFixedBtn').on('click', async function () {
        const reviewId = $('#detailReviewId').val();
        const payload = {
            review_id: reviewId,
            action_type: 'fixed',
            question_text: $('#d_question_text').val() || '',
            option_a: $('#d_option_a').val() || '',
            option_b: $('#d_option_b').val() || '',
            option_c: $('#d_option_c').val() || '',
            option_d: $('#d_option_d').val() || '',
            option_e: $('#d_option_e').val() || '',
            correct_answer: $('#d_correct_answer').val() || '',
            explanation: $('#d_explanation').val() || ''
        };
        const res = await window.appAjax({ url: resolveEndpoint, method: 'POST', data: payload });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Kapatılamadı', 'error');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewDetailModal')).hide();
        await window.showAppAlert('Başarılı', 'İnceleme fixed olarak kapatıldı.', 'success');
        loadList();
    });

    $('#resolveDismissedBtn').on('click', async function () {
        const reviewId = $('#detailReviewId').val();
        const res = await window.appAjax({
            url: resolveEndpoint,
            method: 'POST',
            data: {
                review_id: reviewId,
                action_type: 'dismissed',
                admin_notes: $('#d_admin_notes').val() || ''
            }
        });
        if (!res.success) return window.showAppAlert('Hata', res.message || 'Kapatılamadı', 'error');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('reviewDetailModal')).hide();
        await window.showAppAlert('Başarılı', 'İnceleme dismissed olarak kapatıldı.', 'success');
        loadList();
    });

    $('#bulkDismissOkBtn').on('click', async function () {
        const ok = await window.showAppConfirm('Toplu Kapatma Onayı', 'Mevcut filtre bağlamında pending + ok kayıtlar "sorun yok" olarak kapatılacak. Devam edilsin mi?', {
            type: 'warning',
            confirmText: 'Kapat',
            cancelText: 'İptal'
        });
        if (!ok) return;

        window.appSetButtonLoading('#bulkDismissOkBtn', true, 'Kapatılıyor...');
        const res = await window.appAjax({
            url: resolveEndpoint,
            method: 'POST',
            data: {
                action_type: 'bulk_dismiss_ok',
                qualification_id: $('#filterQualification').val() || '',
                course_id: $('#filterCourse').val() || '',
                ai_status: $('#filterAiStatus').val() || '',
                review_state: $('#filterReviewState').val() || ''
            }
        });
        window.appSetButtonLoading('#bulkDismissOkBtn', false);

        if (!res.success) {
            return window.showAppAlert('Hata', res.message || 'Toplu kapatma başarısız', 'error');
        }

        await window.showAppAlert('Başarılı', res.message || 'Kayıtlar kapatıldı.', 'success');
        loadList();
    });

    loadList();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'question-reports';
$page_title = 'Soru Bildirimleri';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Soru Bildirimleri</h2>
            <p class="text-muted mb-0">Kullanıcıların soru bildirimlerini inceleyin ve yönetin.</p>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="questionReportsTable">
                    <thead>
                    <tr>
                        <th>Bildirimi Yapan</th>
                        <th>Bildirim</th>
                        <th>Soru</th>
                        <th>Tarih</th>
                        <th>İşlemler</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-md-none" id="questionReportsMobileList"></div>
    <div class="alert alert-light text-muted d-none mt-2" id="questionReportsMobileEmpty">Kayıt bulunamadı.</div>
</div>

<div class="modal fade" id="questionReportDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Soru Bildirim Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-3" id="detailMeta"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Bildiren Kullanıcı</label>
                        <div id="detailReporter"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Tarih</label>
                        <div id="detailDates"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-1">Bildirim Metni</label>
                        <div id="detailReportText" class="border rounded p-3 bg-light-subtle text-break" style="white-space: pre-wrap;"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-1">Soru Metni</label>
                        <div id="detailQuestionText" class="border rounded p-3 bg-light-subtle text-break" style="white-space: pre-wrap;"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Şıklar</label>
                        <div id="detailOptions" class="border rounded p-3 bg-light-subtle text-break" style="white-space: pre-wrap;"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold mb-1">Doğru Cevap / Meta</label>
                        <div id="detailAnswerMeta" class="border rounded p-3 bg-light-subtle text-break" style="white-space: pre-wrap;"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold mb-1">Açıklama</label>
                        <div id="detailExplanation" class="border rounded p-3 bg-light-subtle text-break" style="white-space: pre-wrap;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editQuestionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Soruyu Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <form id="editQuestionForm">
                <input type="hidden" name="question_id" id="editQuestionId">
                <div class="modal-body">
                    <div id="editQuestionAlert" class="d-none"></div>

                    <div id="editQuestionLoading" class="py-5 text-center">
                        <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                        <div class="mt-2 text-muted">Soru yükleniyor...</div>
                    </div>

                    <div id="editQuestionNotFound" class="d-none">
                        <div class="alert alert-warning mb-0">Soru bulunamadı veya silinmiş.</div>
                    </div>

                    <div id="editQuestionFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label">Soru Metni *</label>
                            <textarea class="form-control" name="question_text" id="editQuestionText" rows="4" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">A *</label><input type="text" class="form-control" name="option_a" id="editOptionA" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">B *</label><input type="text" class="form-control" name="option_b" id="editOptionB" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">C *</label><input type="text" class="form-control" name="option_c" id="editOptionC" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">D *</label><input type="text" class="form-control" name="option_d" id="editOptionD" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Şık E (Opsiyonel)</label><input type="text" class="form-control" name="option_e" id="editOptionE"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Doğru Cevap *</label>
                            <select class="form-select" name="correct_answer" id="editCorrectAnswer" required>
                                <option value="">Seçiniz...</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                                <option value="E">E</option>
                            </select>
                        </div>

                        <div class="mb-3 d-none" id="editQuestionTypeWrap">
                            <label class="form-label">Tip *</label>
                            <select class="form-select" name="question_type" id="editQuestionType">
                                <option value="sayısal">Sayısal</option>
                                <option value="sözel">Sözel</option>
                                <option value="karışık">Karışık</option>
                            </select>
                        </div>

                        <div class="mb-3 d-none" id="editQuestionStatusWrap">
                            <label class="form-label">Durum *</label>
                            <select class="form-select" name="status" id="editQuestionStatus">
                                <option value="active">Aktif</option>
                                <option value="inactive">Pasif</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="explanation" id="editExplanation" rows="4"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                    <button type="submit" class="btn btn-primary" id="editQuestionSubmitBtn" disabled>Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/question-reports.php';
    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const detailModalEl = document.getElementById('questionReportDetailModal');
    const detailModal = detailModalEl ? new bootstrap.Modal(detailModalEl) : null;
    const editModalEl = document.getElementById('editQuestionModal');
    const editModal = editModalEl ? new bootstrap.Modal(editModalEl) : null;
    let editMeta = { has_question_type: false, status_mode: 'none', has_option_e: true };
    let currentEditingQuestionId = '';

    const api = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    };

    const nl2br = (v) => esc(v || '-').replace(/\n/g, '<br>');
    const shortText = (v, n = 110) => {
        const s = String(v || '');
        return s.length > n ? s.slice(0, n) + '…' : s;
    };

    function questionOptionsHtml(q) {
        const lines = [
            `A) ${q.option_a || '-'}`,
            `B) ${q.option_b || '-'}`,
            `C) ${q.option_c || '-'}`,
            `D) ${q.option_d || '-'}`,
            `E) ${q.option_e || '-'}`,
        ];
        return nl2br(lines.join('\n'));
    }

    function answerMetaHtml(q) {
        const lines = [
            `Doğru Cevap: ${q.correct_answer || '-'}`,
            `Ders ID: ${q.course_id || '-'}`,
            `Soru Tipi: ${q.question_type || '-'}`,
        ];
        return nl2br(lines.join('\n'));
    }

    function fillDetail(r) {
        const q = r.question || {};
        $('#detailMeta').text(`Report ID: ${r.report_id || '-'} • Question ID: ${r.question_id || '-'}`);
        $('#detailReporter').html(`${esc(r.reporter_name || '-')}${r.reporter_email ? `<br><small class="text-muted">${esc(r.reporter_email)}</small>` : ''}<br><small class="text-muted">User ID: ${esc(r.reporter_user_id || '-')}</small>`);
        $('#detailDates').html(`Oluşturulma: ${esc(r.created_at || '-')}<br>Güncellenme: ${esc(r.updated_at || '-')}`);
        $('#detailReportText').html(nl2br(r.report_text || '-'));
        $('#detailQuestionText').html(nl2br(q.question_text || '-'));
        $('#detailOptions').html(questionOptionsHtml(q));
        $('#detailAnswerMeta').html(answerMetaHtml(q));
        $('#detailExplanation').html(nl2br(q.explanation || '-'));
    }

    function renderDesktop(rows) {
        const $tbody = $('#questionReportsTable tbody');
        if (!rows.length) {
            $tbody.html('<tr><td colspan="5" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        $tbody.html(rows.map((r) => {
            const q = r.question || {};
            return `
                <tr>
                    <td>
                        <div class="fw-semibold text-break">${esc(r.reporter_name || '-')}</div>
                        <div class="small text-muted text-break">${esc(r.reporter_email || '-')}</div>
                        <div class="small text-muted">ID: ${esc(r.reporter_user_id || '-')}</div>
                    </td>
                    <td>
                        <div class="text-break" style="max-width:320px; white-space:pre-wrap;">${esc(shortText(r.report_text || '-', 160))}</div>
                    </td>
                    <td>
                        <div class="fw-semibold text-break report-question-text" data-question-id="${esc(r.question_id || '')}" style="max-width:420px; white-space:pre-wrap;">${esc(shortText(q.question_text || '-', 180))}</div>
                        <div class="small text-muted">QID: ${esc(r.question_id || '-')}</div>
                    </td>
                    <td><small class="text-muted">${esc(r.created_at || '-')}</small></td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            <button class="btn btn-sm btn-info detail-btn" data-report='${esc(JSON.stringify(r))}'><i class="bi bi-search"></i> Detay</button>
                            <button class="btn btn-sm btn-warning edit-question-btn" data-question-id="${esc(r.question_id || '')}" ${r.question_id ? '' : 'disabled'}><i class="bi bi-pencil"></i> Soruyu Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-report-id="${esc(r.report_id || '')}"><i class="bi bi-trash"></i> Bildirimi Sil</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join(''));
    }

    function renderMobile(rows) {
        const $list = $('#questionReportsMobileList');
        const $empty = $('#questionReportsMobileEmpty');

        if (!rows.length) {
            $list.html('');
            $empty.removeClass('d-none');
            return;
        }

        $empty.addClass('d-none');
        $list.html(rows.map((r) => {
            const q = r.question || {};
            return `
                <div class="card mb-2">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="fw-semibold text-break">${esc(r.reporter_name || '-')}</div>
                                <div class="small text-muted text-break">${esc(r.reporter_email || '-')}</div>
                            </div>
                            <small class="text-muted">${esc(r.created_at || '-')}</small>
                        </div>

                        <div class="small text-muted">Bildirim</div>
                        <div class="mb-2 text-break" style="white-space:pre-wrap;">${esc(shortText(r.report_text || '-', 180))}</div>

                        <div class="small text-muted">Soru</div>
                        <div class="mb-2 text-break report-question-text" data-question-id="${esc(r.question_id || '')}" style="white-space:pre-wrap;">${esc(shortText(q.question_text || '-', 180))}</div>

                        <div class="d-flex gap-1 flex-wrap">
                            <button class="btn btn-sm btn-info detail-btn" data-report='${esc(JSON.stringify(r))}'><i class="bi bi-search"></i> Detay</button>
                            <button class="btn btn-sm btn-warning edit-question-btn" data-question-id="${esc(r.question_id || '')}" ${r.question_id ? '' : 'disabled'}><i class="bi bi-pencil"></i> Soruyu Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-report-id="${esc(r.report_id || '')}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `;
        }).join(''));
    }

    async function loadReports() {
        const res = await api('list');
        if (!res.success) {
            $('#questionReportsTable tbody').html('<tr><td colspan="5" class="text-muted p-3">Bildirimler getirilemedi.</td></tr>');
            $('#questionReportsMobileList').html('');
            $('#questionReportsMobileEmpty').removeClass('d-none').text('Bildirimler getirilemedi.');
            return;
        }
        const rows = res.data?.reports || [];
        renderDesktop(rows);
        renderMobile(rows);
    }

    function setEditAlert(message, type = 'info') {
        const cls = type === 'success' ? 'alert-success' : type === 'error' ? 'alert-danger' : 'alert-info';
        $('#editQuestionAlert').removeClass('d-none alert-success alert-danger alert-info').addClass(`alert ${cls}`).text(message || '');
    }

    function resetEditView() {
        $('#editQuestionAlert').addClass('d-none').removeClass('alert alert-success alert-danger alert-info').text('');
        $('#editQuestionLoading').removeClass('d-none');
        $('#editQuestionNotFound').addClass('d-none');
        $('#editQuestionFields').addClass('d-none');
        $('#editQuestionSubmitBtn').prop('disabled', true).text('Güncelle');
    }

    function updateReportQuestionText(questionId, questionText) {
        const display = shortText(questionText || '-', 180);
        $(`.report-question-text[data-question-id="${questionId}"]`).text(display);
        if ($('#detailMeta').text().includes(`Question ID: ${questionId}`)) {
            $('#detailQuestionText').html(nl2br(questionText || '-'));
        }
    }

    async function openEditModal(questionId) {
        currentEditingQuestionId = String(questionId || '').trim();
        if (!currentEditingQuestionId) return;

        resetEditView();
        $('#editQuestionId').val(currentEditingQuestionId);
        if (editModal) editModal.show();

        const res = await api('get_question', 'GET', { question_id: currentEditingQuestionId });
        $('#editQuestionLoading').addClass('d-none');

        if (!res.success || !res.data?.question) {
            $('#editQuestionNotFound').removeClass('d-none');
            setEditAlert(res.message || 'Soru bulunamadı veya silinmiş.', 'error');
            return;
        }

        const q = res.data.question;
        editMeta = res.data.meta || editMeta;

        $('#editQuestionText').val(q.question_text || '');
        $('#editOptionA').val(q.option_a || '');
        $('#editOptionB').val(q.option_b || '');
        $('#editOptionC').val(q.option_c || '');
        $('#editOptionD').val(q.option_d || '');
        $('#editOptionE').val(q.option_e || '');
        $('#editCorrectAnswer').val(q.correct_answer || '');
        $('#editExplanation').val(q.explanation || '');

        const hasQuestionType = !!editMeta.has_question_type;
        $('#editQuestionTypeWrap').toggleClass('d-none', !hasQuestionType);
        if (hasQuestionType) {
            $('#editQuestionType').val(q.question_type || 'sözel');
        }

        const statusMode = editMeta.status_mode || 'none';
        const showStatus = statusMode === 'status' || statusMode === 'is_active';
        $('#editQuestionStatusWrap').toggleClass('d-none', !showStatus);
        if (showStatus) {
            $('#editQuestionStatus').val((q.status || 'active').toLowerCase() === 'inactive' ? 'inactive' : 'active');
        }

        const hasOptionE = editMeta.has_option_e !== false;
        $('#editOptionE').prop('disabled', !hasOptionE).closest('.mb-3').toggleClass('opacity-50', !hasOptionE);
        if (!hasOptionE && $('#editCorrectAnswer').val() === 'E') {
            $('#editCorrectAnswer').val('');
        }

        $('#editQuestionFields').removeClass('d-none');
        $('#editQuestionSubmitBtn').prop('disabled', false);
    }

    $(document).on('click', '.detail-btn', function () {
        const raw = $(this).attr('data-report') || '{}';
        let r = {};
        try { r = JSON.parse(raw); } catch (e) { r = {}; }
        fillDetail(r);
        if (detailModal) detailModal.show();
    });

    $(document).on('click', '.delete-btn', async function () {
        const reportId = String($(this).data('report-id') || '').trim();
        if (!reportId) return;

        const ok = await window.showAppConfirm({
            title: 'Bildirimi Sil',
            message: 'Bu soru bildirim kaydı silinsin mi?',
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_report', 'POST', { report_id: reportId });
        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Bildirim silindi.', 'success');
        await loadReports();
    });

    $(document).on('click', '.edit-question-btn', async function () {
        const questionId = String($(this).data('question-id') || '').trim();
        if (!questionId) return;
        await openEditModal(questionId);
    });

    $('#editQuestionForm').on('submit', async function (e) {
        e.preventDefault();
        if (!currentEditingQuestionId) return;

        const payload = {
            question_id: currentEditingQuestionId,
            question_text: String($('#editQuestionText').val() || '').trim(),
            option_a: String($('#editOptionA').val() || '').trim(),
            option_b: String($('#editOptionB').val() || '').trim(),
            option_c: String($('#editOptionC').val() || '').trim(),
            option_d: String($('#editOptionD').val() || '').trim(),
            option_e: String($('#editOptionE').val() || '').trim(),
            correct_answer: String($('#editCorrectAnswer').val() || '').trim(),
            explanation: String($('#editExplanation').val() || '').trim(),
        };

        if (!payload.question_text || !payload.option_a || !payload.option_b || !payload.option_c || !payload.option_d || !payload.correct_answer) {
            setEditAlert('Lütfen zorunlu alanları doldurun.', 'error');
            return;
        }

        if (editMeta.has_question_type) {
            payload.question_type = String($('#editQuestionType').val() || '').trim();
        }

        if ((editMeta.status_mode || 'none') !== 'none') {
            payload.status = String($('#editQuestionStatus').val() || 'active').trim();
        }

        $('#editQuestionSubmitBtn').prop('disabled', true).text('Güncelleniyor...');
        const res = await api('update_question', 'POST', payload);
        $('#editQuestionSubmitBtn').prop('disabled', false).text('Güncelle');

        if (!res.success) {
            setEditAlert(res.message || 'Güncelleme başarısız.', 'error');
            return;
        }

        setEditAlert(res.message || 'Soru güncellendi.', 'success');
        updateReportQuestionText(currentEditingQuestionId, payload.question_text);
        await loadReports();
    });

    loadReports();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

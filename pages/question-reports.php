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

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/question-reports.php';
    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const detailModalEl = document.getElementById('questionReportDetailModal');
    const detailModal = detailModalEl ? new bootstrap.Modal(detailModalEl) : null;

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
                        <div class="fw-semibold text-break" style="max-width:420px; white-space:pre-wrap;">${esc(shortText(q.question_text || '-', 180))}</div>
                        <div class="small text-muted">QID: ${esc(r.question_id || '-')}</div>
                    </td>
                    <td><small class="text-muted">${esc(r.created_at || '-')}</small></td>
                    <td>
                        <div class="d-flex gap-1 flex-wrap">
                            <button class="btn btn-sm btn-info detail-btn" data-report='${esc(JSON.stringify(r))}'><i class="bi bi-search"></i> Detay</button>
                            <a class="btn btn-sm btn-warning" href="/pages/questions.php?question_id=${encodeURIComponent(r.question_id || '')}" target="_blank" rel="noopener noreferrer"><i class="bi bi-pencil"></i> Soruyu Düzenle</a>
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
                        <div class="mb-2 text-break" style="white-space:pre-wrap;">${esc(shortText(q.question_text || '-', 180))}</div>

                        <div class="d-flex gap-1 flex-wrap">
                            <button class="btn btn-sm btn-info detail-btn" data-report='${esc(JSON.stringify(r))}'><i class="bi bi-search"></i> Detay</button>
                            <a class="btn btn-sm btn-warning" href="/pages/questions.php?question_id=${encodeURIComponent(r.question_id || '')}" target="_blank" rel="noopener noreferrer"><i class="bi bi-pencil"></i> Soruyu Düzenle</a>
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

    loadReports();
});
</script>
JS;
?>

<?php include '../includes/footer.php'; ?>

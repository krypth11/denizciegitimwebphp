<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'me-questions';
$page_title = 'ME Sorular';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>ME Sorular</h2>
            <p class="text-muted mb-0">Maritime English soru bankasını filtreleyin ve yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-secondary" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Filtreyi Temizle</button>
            <button class="btn btn-secondary" id="bulkUploadBtn"><i class="bi bi-upload"></i> Toplu Soru Yükle</button>
            <button class="btn btn-primary" id="addQuestionBtn"><i class="bi bi-plus-lg"></i> Yeni Soru Ekle</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Kategori</label>
                    <select class="form-select" id="filterCategory">
                        <option value="">Tüm kategoriler</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Topic</label>
                    <select class="form-select" id="filterTopic" disabled>
                        <option value="">Tüm topicler</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="filterSearch" placeholder="Soru metni / açıklama ara...">
                </div>
            </div>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Soru</th>
                            <th>Kategori / Topic</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="meQuestionsTableBody"></tbody>
                </table>
                <div class="text-muted p-2 d-none" id="meQuestionsDesktopEmpty">Kayıt bulunamadı.</div>
            </div>
        </div>
    </div>

    <div class="d-md-none" id="meQuestionsMobileList"></div>
    <div class="alert alert-light text-muted d-none mt-2" id="meQuestionsMobileEmpty">Kayıt bulunamadı.</div>
</div>

<div class="modal fade" id="questionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="questionModalTitle">Yeni ME Sorusu Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="questionForm">
                <input type="hidden" name="id" id="question_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kategori *</label>
                            <select class="form-select" name="category_id" id="question_category_id" required></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Topic *</label>
                            <select class="form-select" name="topic_id" id="question_topic_id" required></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Soru Metni *</label>
                            <textarea class="form-control" name="question_text" id="question_text" rows="3" required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">A Şıkkı *</label>
                            <input type="text" class="form-control" name="option_a" id="option_a" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">B Şıkkı *</label>
                            <input type="text" class="form-control" name="option_b" id="option_b" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">C Şıkkı *</label>
                            <input type="text" class="form-control" name="option_c" id="option_c" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">D Şıkkı *</label>
                            <input type="text" class="form-control" name="option_d" id="option_d" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Doğru Cevap *</label>
                            <select class="form-select" name="correct_answer" id="correct_answer" required>
                                <option value="">Seçiniz...</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama</label>
                            <textarea class="form-control" name="explanation" id="explanation" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkUploadModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Toplu Soru Yükle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="bulkUploadForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kategori *</label>
                            <select class="form-select" id="bulk_category_id" required>
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Topic *</label>
                            <select class="form-select" id="bulk_topic_id" required disabled>
                                <option value="">Önce kategori seçin...</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Soruları Yapıştırın *</label>
                        <textarea class="form-control" id="bulk_questions_text" rows="12" placeholder="Soruları buraya yapıştırın..." required></textarea>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        <strong>Beklenen Format:</strong>
<pre class="mb-0 mt-2" style="white-space:pre-wrap; font-size:12px;">1. Soru metni?
A) Şık A
B) Şık B
C) Şık C
D) Şık D
Açıklama:
Açıklama metni
⸻
2. İkinci soru?
A) ...
B) ...
C) ...
D) ...
Açıklama:
...
⸻
Cevap Anahtarı
1-A
2-B</pre>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-diagram-3"></i> Ayrıştır / Önizle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> Ayrıştırılan Sorular</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bulkPreviewBody" style="max-height:70vh; overflow-y:auto;"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Kapat</button>
                <button type="button" class="btn btn-success" id="bulkSaveBtn" disabled>0 Soruyu Kaydet</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/me-questions.php';
    let categories = [];
    let topics = [];
    let questions = [];
    let bulkQuestions = [];
    let bulkMeta = null;

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const appConfirm = (title, message, options = {}) =>
        window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);

    const esc = (text) => $('<div>').text(text ?? '').html();

    const api = async (action, method = 'GET', data = {}) => {
        try {
            return await $.ajax({
                url: endpoint + '?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        } catch (xhr) {
            return { success: false, message: xhr?.responseJSON?.message || 'Sunucu hatası oluştu.' };
        }
    };

    function renderCategoryFilters() {
        const current = $('#filterCategory').val() || '';
        const currentForm = $('#question_category_id').val() || '';
        const currentBulk = $('#bulk_category_id').val() || '';

        $('#filterCategory').html('<option value="">Tüm kategoriler</option>');
        $('#question_category_id').html('<option value="">Seçiniz...</option>');
        $('#bulk_category_id').html('<option value="">Seçiniz...</option>');

        categories.forEach(c => {
            const o = `<option value="${c.id}">${esc(c.name)}</option>`;
            $('#filterCategory').append(o);
            $('#question_category_id').append(o);
            $('#bulk_category_id').append(o);
        });

        if (current) $('#filterCategory').val(current);
        if (currentForm) $('#question_category_id').val(currentForm);
        if (currentBulk) $('#bulk_category_id').val(currentBulk);
    }

    function renderTopicFilters() {
        const selectedCategory = $('#filterCategory').val() || '';
        const selectedTopic = $('#filterTopic').val() || '';

        const source = selectedCategory ? topics.filter(t => String(t.category_id) === String(selectedCategory)) : topics;

        $('#filterTopic').html('<option value="">Tüm topicler</option>');
        source.forEach(t => $('#filterTopic').append(`<option value="${t.id}">${esc(t.name)}</option>`));
        $('#filterTopic').val(selectedTopic);
        $('#filterTopic').prop('disabled', source.length === 0);
    }

    function renderQuestionTopicOptions(categoryId, selectedTopicId = '') {
        const source = topics.filter(t => String(t.category_id) === String(categoryId));
        $('#question_topic_id').html('<option value="">Seçiniz...</option>');
        source.forEach(t => $('#question_topic_id').append(`<option value="${t.id}">${esc(t.name)}</option>`));
        if (selectedTopicId) $('#question_topic_id').val(selectedTopicId);
    }

    function renderBulkTopicOptions(categoryId, selectedTopicId = '') {
        const source = topics.filter(t => String(t.category_id) === String(categoryId));
        $('#bulk_topic_id').html('<option value="">Seçiniz...</option>');
        source.forEach(t => $('#bulk_topic_id').append(`<option value="${t.id}">${esc(t.name)}</option>`));
        if (selectedTopicId) $('#bulk_topic_id').val(selectedTopicId);
        $('#bulk_topic_id').prop('disabled', !categoryId || source.length === 0);
    }

    function optionLine(letter, text, correct) {
        const cls = correct === letter ? 'meq-option meq-option-correct' : 'meq-option';
        return `<div class="${cls}">${letter}) ${esc(text || '-')}</div>`;
    }

    function renderQuestions() {
        const $tb = $('#meQuestionsTableBody');
        const $mobile = $('#meQuestionsMobileList');
        $tb.empty();
        $mobile.empty();

        const empty = !questions.length;
        $('#meQuestionsDesktopEmpty').toggleClass('d-none', !empty);
        $('#meQuestionsMobileEmpty').toggleClass('d-none', !empty);

        questions.forEach(q => {
            const optionsHtml = `
                <div class="meq-options-grid">
                    ${optionLine('A', q.option_a, q.correct_answer)}
                    ${optionLine('B', q.option_b, q.correct_answer)}
                    ${optionLine('C', q.option_c, q.correct_answer)}
                    ${optionLine('D', q.option_d, q.correct_answer)}
                </div>
            `;

            $tb.append(`
                <tr>
                    <td>
                        <div class="fw-semibold mb-1">${esc(q.question_text)}</div>
                        ${optionsHtml}
                    </td>
                    <td>
                        <div>${esc(q.category_name || '-')}</div>
                        <small class="text-muted">${esc(q.topic_name || '-')}</small>
                    </td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${q.id}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${q.id}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            $mobile.append(`
                <div class="card mb-3 meq-mobile-card">
                    <div class="card-body">
                        <div class="fw-semibold">${esc(q.question_text)}</div>
                        <div class="small text-muted mt-1">${esc(q.category_name || '-')} / ${esc(q.topic_name || '-')}</div>
                        ${optionsHtml}
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${q.id}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${q.id}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function loadCategories() {
        const res = await api('list_categories');
        if (!res.success) return appAlert('Hata', res.message || 'Kategoriler yüklenemedi.', 'error');
        categories = res.data?.categories || [];
        renderCategoryFilters();
    }

    async function loadTopics() {
        const res = await api('list_topics');
        if (!res.success) return appAlert('Hata', res.message || 'Topicler yüklenemedi.', 'error');
        topics = res.data?.topics || [];
        renderTopicFilters();
    }

    async function loadQuestions() {
        const params = {
            category_id: $('#filterCategory').val() || '',
            topic_id: $('#filterTopic').val() || '',
            search: ($('#filterSearch').val() || '').trim()
        };
        const res = await api('list_questions', 'GET', params);
        if (!res.success) return appAlert('Hata', res.message || 'Sorular yüklenemedi.', 'error');
        questions = res.data?.questions || [];

        renderQuestions();
    }

    function openQuestionModal(mode, item = null) {
        $('#questionForm')[0].reset();
        $('#question_id').val('');

        if (mode === 'edit' && item) {
            $('#questionModalTitle').text('ME Sorusu Düzenle');
            $('#question_id').val(item.id);
            $('#question_category_id').val(item.category_id || '');
            renderQuestionTopicOptions(item.category_id || '', item.topic_id || '');
            $('#question_text').val(item.question_text || '');
            $('#option_a').val(item.option_a || '');
            $('#option_b').val(item.option_b || '');
            $('#option_c').val(item.option_c || '');
            $('#option_d').val(item.option_d || '');
            $('#correct_answer').val((item.correct_answer || '').toUpperCase());
            $('#explanation').val(item.explanation || '');
        } else {
            $('#questionModalTitle').text('Yeni ME Sorusu Ekle');
            const filterCat = $('#filterCategory').val() || '';
            $('#question_category_id').val(filterCat);
            renderQuestionTopicOptions(filterCat, '');
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('questionModal')).show();
    }

    function parseBulkQuestions(rawText, categoryId, topicId) {
        const fullText = (rawText || '').replace(/\r\n/g, '\n').trim();
        const result = { parsed: [], parsed_count: 0, skipped_count: 0, total_blocks: 0 };

        if (!fullText) return result;

        const lowerText = fullText.toLocaleLowerCase('tr-TR');
        const answerKeyIndex = lowerText.indexOf('cevap anahtarı');
        const bodyText = answerKeyIndex >= 0 ? fullText.slice(0, answerKeyIndex).trim() : fullText;
        const answerKeyText = answerKeyIndex >= 0 ? fullText.slice(answerKeyIndex) : '';

        const answerMap = {};
        if (answerKeyText) {
            const answerRegex = /(\d+)\s*[-:]\s*([ABCD])/gi;
            let m;
            while ((m = answerRegex.exec(answerKeyText)) !== null) {
                answerMap[parseInt(m[1], 10)] = m[2].toUpperCase();
            }
        }

        const startRegex = /^\s*(\d+)\.\s*(.*)$/gm;
        const starts = [];
        let s;
        while ((s = startRegex.exec(bodyText)) !== null) {
            starts.push({ num: parseInt(s[1], 10), index: s.index });
        }

        result.total_blocks = starts.length;
        if (!starts.length) return result;

        const normalize = (txt) => (txt || '').replace(/\s+/g, ' ').trim();
        const cleanOption = (txt) => normalize((txt || '').replace(/\(\s*doğru\s*\)/ig, '').replace(/^[*✓✔]+\s*/, ''));

        for (let i = 0; i < starts.length; i++) {
            const blockStart = starts[i].index;
            const blockEnd = i + 1 < starts.length ? starts[i + 1].index : bodyText.length;
            const blockText = bodyText.slice(blockStart, blockEnd).trim();
            const number = starts[i].num;

            const lines = blockText.split('\n').map(l => l.trim()).filter(Boolean);
            if (!lines.length) {
                result.skipped_count++;
                continue;
            }

            lines[0] = lines[0].replace(/^\s*\d+\.\s*/, '').trim();

            const questionLines = [];
            const options = { A: '', B: '', C: '', D: '' };
            const explanationLines = [];
            let explanationMode = false;
            let currentOption = null;
            let inferredCorrect = '';

            for (const rawLine of lines) {
                const line = rawLine.trim();
                if (!line) continue;

                if (explanationMode) {
                    explanationLines.push(line);
                    continue;
                }

                const expMatch = line.match(/^açıklama\s*:\s*(.*)$/i);
                if (expMatch) {
                    explanationMode = true;
                    if (expMatch[1]) explanationLines.push(expMatch[1].trim());
                    continue;
                }

                const optMatch = line.match(/^([ABCD])[\)\.\-:]\s*(.*)$/i);
                if (optMatch) {
                    currentOption = optMatch[1].toUpperCase();
                    let optVal = optMatch[2] || '';
                    if (/^\s*[*✓✔]/.test(optVal) || /\(\s*doğru\s*\)/i.test(optVal)) {
                        inferredCorrect = currentOption;
                    }
                    options[currentOption] = cleanOption(optVal);
                    continue;
                }

                if (currentOption) {
                    options[currentOption] = normalize(`${options[currentOption]} ${line}`);
                } else {
                    questionLines.push(line);
                }
            }

            const questionText = normalize(questionLines.join(' '));
            const correct = (answerMap[number] || inferredCorrect || '').toUpperCase();

            const isValid = questionText.length >= 5 && options.A && options.B && options.C && options.D && ['A', 'B', 'C', 'D'].includes(correct);
            if (!isValid) {
                result.skipped_count++;
                continue;
            }

            result.parsed.push({
                category_id: categoryId,
                topic_id: topicId,
                question_text: questionText,
                option_a: options.A,
                option_b: options.B,
                option_c: options.C,
                option_d: options.D,
                correct_answer: correct,
                explanation: normalize(explanationLines.join(' ')),
                status: 'pending'
            });
        }

        result.parsed_count = result.parsed.length;
        return result;
    }

    function bulkCounts() {
        return {
            approved: bulkQuestions.filter(q => q.status === 'approved').length,
            pending: bulkQuestions.filter(q => q.status === 'pending').length,
            cancelled: bulkQuestions.filter(q => q.status === 'cancelled').length
        };
    }

    function renderBulkPreview() {
        const counts = bulkCounts();
        $('#bulkSaveBtn').text(`${counts.approved} Soruyu Kaydet`).prop('disabled', counts.approved === 0);

        let html = '';
        if (bulkMeta) {
            html += `
                <div class="alert alert-info">
                    Toplam blok: <strong>${bulkMeta.total_blocks}</strong> • Ayrıştırılan: <strong>${bulkMeta.parsed_count}</strong> • Atlanan: <strong>${bulkMeta.skipped_count}</strong>
                </div>
            `;
        }

        html += `
            <div class="row mb-3">
                <div class="col-md-4"><div class="alert alert-success mb-0">Onaylanan: <strong>${counts.approved}</strong></div></div>
                <div class="col-md-4"><div class="alert alert-warning mb-0">Bekleyen: <strong>${counts.pending}</strong></div></div>
                <div class="col-md-4"><div class="alert alert-danger mb-0">İptal Edilen: <strong>${counts.cancelled}</strong></div></div>
            </div>
        `;

        bulkQuestions.forEach((q, index) => {
            const cardClass = q.status === 'approved' ? 'bulk-card-approved' : (q.status === 'cancelled' ? 'bulk-card-cancelled' : '');
            const tag = q.status === 'approved'
                ? '<span class="badge bg-success">Onaylandı</span>'
                : (q.status === 'cancelled' ? '<span class="badge bg-danger">İptal</span>' : '<span class="badge bg-secondary">Bekleyen</span>');

            if (q._editing) {
                html += `
                    <div class="card mb-3 ${cardClass}">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <strong>#${index + 1}</strong>${tag}
                        </div>
                        <div class="card-body">
                            <div class="mb-2"><label class="form-label">Soru</label><textarea class="form-control bulk-draft-field" data-index="${index}" data-field="question_text" rows="2">${esc(q._draft.question_text)}</textarea></div>
                            <div class="row">
                                <div class="col-md-6 mb-2"><label class="form-label">A</label><input class="form-control bulk-draft-field" data-index="${index}" data-field="option_a" value="${esc(q._draft.option_a)}"></div>
                                <div class="col-md-6 mb-2"><label class="form-label">B</label><input class="form-control bulk-draft-field" data-index="${index}" data-field="option_b" value="${esc(q._draft.option_b)}"></div>
                                <div class="col-md-6 mb-2"><label class="form-label">C</label><input class="form-control bulk-draft-field" data-index="${index}" data-field="option_c" value="${esc(q._draft.option_c)}"></div>
                                <div class="col-md-6 mb-2"><label class="form-label">D</label><input class="form-control bulk-draft-field" data-index="${index}" data-field="option_d" value="${esc(q._draft.option_d)}"></div>
                            </div>
                            <div class="row">
                                <div class="col-md-3"><label class="form-label">Doğru Cevap</label><select class="form-select bulk-draft-field" data-index="${index}" data-field="correct_answer"><option ${q._draft.correct_answer==='A'?'selected':''}>A</option><option ${q._draft.correct_answer==='B'?'selected':''}>B</option><option ${q._draft.correct_answer==='C'?'selected':''}>C</option><option ${q._draft.correct_answer==='D'?'selected':''}>D</option></select></div>
                                <div class="col-md-9"><label class="form-label">Açıklama</label><input class="form-control bulk-draft-field" data-index="${index}" data-field="explanation" value="${esc(q._draft.explanation || '')}"></div>
                            </div>
                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-primary btn-sm bulk-edit-save" data-index="${index}">Düzenlemeyi Onayla</button>
                                <button class="btn btn-secondary btn-sm bulk-edit-cancel" data-index="${index}">İptal</button>
                            </div>
                        </div>
                    </div>
                `;
                return;
            }

            const optionBox = (letter, value) => {
                const cls = q.correct_answer === letter ? 'meq-option meq-option-correct' : 'meq-option';
                return `<div class="${cls}">${letter}) ${esc(value || '-')}</div>`;
            };

            html += `
                <div class="card mb-3 ${cardClass}">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <div><strong>#${index + 1}</strong> ${tag}</div>
                        <div class="btn-group btn-group-sm">
                            ${q.status === 'approved' || q.status === 'cancelled' ? `<button class="btn btn-outline-secondary bulk-revert" data-index="${index}">Geri Al</button>` : ''}
                            ${q.status !== 'approved' ? `<button class="btn btn-outline-success bulk-approve" data-index="${index}">Onayla</button>` : ''}
                            ${q.status !== 'cancelled' ? `<button class="btn btn-outline-danger bulk-cancel" data-index="${index}">İptal</button>` : ''}
                            <button class="btn btn-outline-warning bulk-edit" data-index="${index}">Düzenle</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-2 fw-semibold">${esc(q.question_text)}</div>
                        <div class="meq-options-grid">
                            ${optionBox('A', q.option_a)}
                            ${optionBox('B', q.option_b)}
                            ${optionBox('C', q.option_c)}
                            ${optionBox('D', q.option_d)}
                        </div>
                        ${q.explanation ? `<div class="small text-muted mt-2">${esc(q.explanation)}</div>` : ''}
                    </div>
                </div>
            `;
        });

        $('#bulkPreviewBody').html(html || '<div class="alert alert-warning mb-0">Ayrıştırılmış soru yok.</div>');
    }

    $('#addQuestionBtn').on('click', () => openQuestionModal('add'));

    $('#filterCategory').on('change', async function () {
        await loadTopics();
        await loadQuestions();
    });
    $('#filterTopic').on('change', loadQuestions);
    $('#filterSearch').on('input', loadQuestions);

    $('#clearFiltersBtn').on('click', async function () {
        $('#filterCategory').val('');
        $('#filterSearch').val('');
        await loadTopics();
        $('#filterTopic').val('');
        await loadQuestions();
    });

    $('#bulkUploadBtn').on('click', function () {
        const selectedCategory = $('#filterCategory').val() || $('#bulk_category_id').val() || '';
        const selectedTopic = $('#filterTopic').val() || $('#bulk_topic_id').val() || '';
        if (selectedCategory) $('#bulk_category_id').val(selectedCategory);
        renderBulkTopicOptions($('#bulk_category_id').val() || '', selectedTopic);
        $('#bulk_questions_text').val('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkUploadModal')).show();
    });

    $('#bulk_category_id').on('change', function () {
        renderBulkTopicOptions($(this).val() || '', '');
    });

    $('#bulkUploadForm').on('submit', function (e) {
        e.preventDefault();

        const categoryId = $('#bulk_category_id').val();
        const topicId = $('#bulk_topic_id').val();
        const rawText = $('#bulk_questions_text').val();

        if (!categoryId) return appAlert('Uyarı', 'Lütfen kategori seçiniz.', 'warning');
        if (!topicId) return appAlert('Uyarı', 'Lütfen topic seçiniz.', 'warning');
        if (!rawText || !rawText.trim()) return appAlert('Uyarı', 'Lütfen soru metnini yapıştırınız.', 'warning');

        const parsed = parseBulkQuestions(rawText, categoryId, topicId);
        if (!parsed.parsed_count) {
            return appAlert('Hata', 'Hiç soru ayrıştırılamadı. Formatı kontrol edin.', 'error');
        }

        bulkQuestions = parsed.parsed;
        bulkMeta = {
            parsed_count: parsed.parsed_count,
            skipped_count: parsed.skipped_count,
            total_blocks: parsed.total_blocks
        };

        bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkUploadModal')).hide();
        renderBulkPreview();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkPreviewModal')).show();
    });

    $(document).on('click', '.bulk-approve', function () {
        bulkQuestions[$(this).data('index')].status = 'approved';
        renderBulkPreview();
    });

    $(document).on('click', '.bulk-cancel', function () {
        bulkQuestions[$(this).data('index')].status = 'cancelled';
        renderBulkPreview();
    });

    $(document).on('click', '.bulk-revert', function () {
        bulkQuestions[$(this).data('index')].status = 'pending';
        renderBulkPreview();
    });

    $(document).on('click', '.bulk-edit', function () {
        const i = $(this).data('index');
        bulkQuestions[i]._editing = true;
        bulkQuestions[i]._draft = { ...bulkQuestions[i] };
        renderBulkPreview();
    });

    $(document).on('input change', '.bulk-draft-field', function () {
        const i = $(this).data('index');
        const f = $(this).data('field');
        bulkQuestions[i]._draft[f] = $(this).val();
    });

    $(document).on('click', '.bulk-edit-cancel', function () {
        const i = $(this).data('index');
        bulkQuestions[i]._editing = false;
        delete bulkQuestions[i]._draft;
        renderBulkPreview();
    });

    $(document).on('click', '.bulk-edit-save', async function () {
        const i = $(this).data('index');
        const d = bulkQuestions[i]._draft;

        if (!d.question_text || !d.option_a || !d.option_b || !d.option_c || !d.option_d || !['A', 'B', 'C', 'D'].includes((d.correct_answer || '').toUpperCase())) {
            return appAlert('Uyarı', 'Düzenleme geçersiz. Zorunlu alanları kontrol edin.', 'warning');
        }

        Object.assign(bulkQuestions[i], {
            question_text: d.question_text,
            option_a: d.option_a,
            option_b: d.option_b,
            option_c: d.option_c,
            option_d: d.option_d,
            correct_answer: (d.correct_answer || '').toUpperCase(),
            explanation: d.explanation || ''
        });

        bulkQuestions[i]._editing = false;
        delete bulkQuestions[i]._draft;
        renderBulkPreview();
    });

    $('#bulkSaveBtn').on('click', async function () {
        const approved = bulkQuestions.filter(q => q.status === 'approved').map(q => ({
            category_id: q.category_id,
            topic_id: q.topic_id,
            question_text: q.question_text,
            option_a: q.option_a,
            option_b: q.option_b,
            option_c: q.option_c,
            option_d: q.option_d,
            correct_answer: q.correct_answer,
            explanation: q.explanation || ''
        }));

        if (!approved.length) {
            return appAlert('Uyarı', 'Kaydedilecek onaylı soru yok.', 'warning');
        }

        const ok = await appConfirm(
            'Kaydetme Onayı',
            `Bu işlem geri alınamaz.<br><br>Onaylanan <strong>${approved.length}</strong> soru veritabanına kaydedilecek.<br><br>Devam etmek istiyor musunuz?`,
            { type: 'warning', confirmText: 'Kaydet', cancelText: 'İptal' }
        );
        if (!ok) return;

        $('#bulkSaveBtn').prop('disabled', true).text('Kaydediliyor...');
        const res = await api('save_bulk_questions', 'POST', { questions: JSON.stringify(approved) });

        if (!res.success) {
            $('#bulkSaveBtn').prop('disabled', false).text(`${approved.length} Soruyu Kaydet`);
            return appAlert('Hata', res.message || 'Kayıt başarısız.', 'error');
        }

        await appAlert('Başarılı', res.message || 'Sorular kaydedildi.', 'success');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkPreviewModal')).hide();
        await loadQuestions();
    });

    $('#question_category_id').on('change', function () {
        renderQuestionTopicOptions($(this).val() || '', '');
    });

    $(document).on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const res = await api('get_question', 'GET', { id });
        if (!res.success) return appAlert('Hata', res.message || 'Kayıt alınamadı.', 'error');
        openQuestionModal('edit', res.data?.question || null);
    });

    $(document).on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        const q = questions.find(x => String(x.id) === String(id));
        const ok = await appConfirm('Soru Sil', `"${esc(q?.question_text || 'Bu soru')}" kaydını silmek istediğinize emin misiniz?`, {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete_question', 'POST', { id });
        if (!res.success) return appAlert('Hata', res.message || 'Silme başarısız.', 'error');
        await appAlert('Başarılı', res.message || 'Soru silindi.', 'success');
        await loadQuestions();
    });

    $('#questionForm').on('submit', async function (e) {
        e.preventDefault();
        const correct = ($('#correct_answer').val() || '').toUpperCase();
        if (!['A', 'B', 'C', 'D'].includes(correct)) {
            return appAlert('Uyarı', 'Doğru cevap A/B/C/D olmalıdır.', 'warning');
        }

        const isEdit = !!$('#question_id').val();
        const action = isEdit ? 'update_question' : 'add_question';
        const res = await api(action, 'POST', $(this).serialize());
        if (!res.success) return appAlert('Hata', res.message || 'İşlem başarısız.', 'error');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('questionModal')).hide();
        await appAlert('Başarılı', res.message || 'Soru kaydedildi.', 'success');
        await loadQuestions();
    });

    (async function init() {
        await loadCategories();
        await loadTopics();
        await loadQuestions();
    })();
});
</script>

<style>
.meq-options-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
    margin-top: 8px;
}

.meq-option {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 6px 8px;
    font-size: 13px;
    line-height: 1.35;
}

.meq-option-correct {
    background: #eaf8ef;
    border-color: #b9e5c8;
    color: #1d7f44;
    font-weight: 600;
}

.meq-mobile-card .meq-options-grid {
    grid-template-columns: 1fr;
}

.bulk-card-approved {
    border: 1px solid #b9e5c8;
    background: #f4fbf6;
}

.bulk-card-cancelled {
    border: 1px solid #f3c2c7;
    background: #fff6f7;
}

@media (max-width: 767.98px) {
    .page-actions {
        width: 100%;
    }

    .page-actions .btn {
        flex: 1;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

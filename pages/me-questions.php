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
                <div class="col-md-2">
                    <label class="form-label">Soru Türü</label>
                    <select class="form-select" id="filterType">
                        <option value="">Tümü</option>
                    </select>
                </div>
                <div class="col-md-4">
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
                            <th>Tür</th>
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
                            <label class="form-label">Soru Türü</label>
                            <input type="text" class="form-control" name="question_type" id="question_type" placeholder="Örn: grammar">
                        </div>
                        <div class="col-12">
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

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/me-questions.php';
    let categories = [];
    let topics = [];
    let questions = [];

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

        $('#filterCategory').html('<option value="">Tüm kategoriler</option>');
        $('#question_category_id').html('<option value="">Seçiniz...</option>');

        categories.forEach(c => {
            const o = `<option value="${c.id}">${esc(c.name)}</option>`;
            $('#filterCategory').append(o);
            $('#question_category_id').append(o);
        });

        if (current) $('#filterCategory').val(current);
        if (currentForm) $('#question_category_id').val(currentForm);
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
                    <td><span class="badge bg-light text-dark">${esc(q.question_type || '-')}</span></td>
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
                        <div class="d-flex justify-content-between gap-2 align-items-start">
                            <div class="fw-semibold">${esc(q.question_text)}</div>
                            <span class="badge bg-light text-dark">${esc(q.question_type || '-')}</span>
                        </div>
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
            question_type: $('#filterType').val() || '',
            search: ($('#filterSearch').val() || '').trim()
        };
        const res = await api('list_questions', 'GET', params);
        if (!res.success) return appAlert('Hata', res.message || 'Sorular yüklenemedi.', 'error');
        questions = res.data?.questions || [];

        const types = [...new Set(questions.map(q => (q.question_type || '').trim()).filter(Boolean))].sort();
        const currentType = $('#filterType').val() || '';
        $('#filterType').html('<option value="">Tümü</option>');
        types.forEach(t => $('#filterType').append(`<option value="${esc(t)}">${esc(t)}</option>`));
        if (currentType) $('#filterType').val(currentType);

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
            $('#question_type').val(item.question_type || '');
        } else {
            $('#questionModalTitle').text('Yeni ME Sorusu Ekle');
            const filterCat = $('#filterCategory').val() || '';
            $('#question_category_id').val(filterCat);
            renderQuestionTopicOptions(filterCat, '');
            const filterType = $('#filterType').val() || '';
            if (filterType) $('#question_type').val(filterType);
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('questionModal')).show();
    }

    $('#addQuestionBtn').on('click', () => openQuestionModal('add'));

    $('#filterCategory').on('change', async function () {
        await loadTopics();
        await loadQuestions();
    });
    $('#filterTopic, #filterType').on('change', loadQuestions);
    $('#filterSearch').on('input', loadQuestions);

    $('#clearFiltersBtn').on('click', async function () {
        $('#filterCategory').val('');
        $('#filterType').val('');
        $('#filterSearch').val('');
        await loadTopics();
        $('#filterTopic').val('');
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

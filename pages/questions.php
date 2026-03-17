<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'questions';
$page_title = 'Sorular';

$filter_qualification = $_GET['qualification'] ?? '';
$filter_course = $_GET['course'] ?? '';
$filter_type = $_GET['type'] ?? '';

$sql = "
    SELECT q.*, c.name as course_name, qual.name as qualification_name, c.qualification_id
    FROM questions q
    LEFT JOIN courses c ON q.course_id = c.id
    LEFT JOIN qualifications qual ON c.qualification_id = qual.id
    WHERE 1=1
";

$params = [];

if (!empty($filter_qualification)) {
    $sql .= ' AND c.qualification_id = ?';
    $params[] = $filter_qualification;
}

if (!empty($filter_course)) {
    $sql .= ' AND q.course_id = ?';
    $params[] = $filter_course;
}

if (!empty($filter_type)) {
    $sql .= ' AND q.question_type = ?';
    $params[] = $filter_type;
}

$sql .= ' ORDER BY q.created_at DESC LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();

$qualifications = $pdo->query('SELECT * FROM qualifications ORDER BY order_index, name')->fetchAll();

$courses = $pdo->query(
    "SELECT c.*, q.name as qualification_name
     FROM courses c
     LEFT JOIN qualifications q ON c.qualification_id = q.id
     ORDER BY q.order_index, c.order_index"
)->fetchAll();

$total_questions = $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$sayisal_count = $pdo->query("SELECT COUNT(*) FROM questions WHERE question_type = 'sayısal'")->fetchColumn();
$sozel_count = $pdo->query("SELECT COUNT(*) FROM questions WHERE question_type = 'sözel'")->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
.question-row { cursor: pointer; }
.question-row:hover { background-color: #f8f9fa; }
.correct-badge {
    display: inline-block;
    width: 30px;
    height: 30px;
    line-height: 30px;
    text-align: center;
    border-radius: 50%;
    font-weight: bold;
    color: white;
    background-color: #28a745;
}
</style>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Toplam Soru</h6>
                            <h3 class="mb-0"><?= number_format($total_questions) ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded p-3"><i class="bi bi-question-circle fs-2 text-primary"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Sayısal Sorular</h6>
                            <h3 class="mb-0"><?= number_format($sayisal_count) ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 rounded p-3"><i class="bi bi-calculator fs-2 text-success"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Sözel Sorular</h6>
                            <h3 class="mb-0"><?= number_format($sozel_count) ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 rounded p-3"><i class="bi bi-chat-text fs-2 text-info"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Sorular</h2>
        <div class="btn-group">
            <button class="btn btn-danger" id="bulkDeleteBtn" style="display:none;">
                <i class="bi bi-trash"></i> Seçilenleri Sil (<span id="selectedCount">0</span>)
            </button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#aiModal">
                <i class="bi bi-stars"></i> AI ile Üret
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i> Manuel Ekle
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Yeterlilik</label>
                    <select class="form-select" name="qualification" id="filter_qualification">
                        <option value="">Tüm Yeterlilikler</option>
                        <?php foreach ($qualifications as $q): ?>
                            <option value="<?= htmlspecialchars($q['id']) ?>" <?= $filter_qualification === $q['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($q['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ders</label>
                    <select class="form-select" name="course" id="filter_course">
                        <option value="">Tüm Dersler</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>"
                                    data-qualification="<?= htmlspecialchars($c['qualification_id']) ?>"
                                    <?= $filter_course === $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tip</label>
                    <select class="form-select" name="type">
                        <option value="">Tümü</option>
                        <option value="sayısal" <?= $filter_type === 'sayısal' ? 'selected' : '' ?>>Sayısal</option>
                        <option value="sözel" <?= $filter_type === 'sözel' ? 'selected' : '' ?>>Sözel</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="bi bi-funnel"></i> Filtrele</button>
                    <a href="/pages/questions.php" class="btn btn-secondary"><i class="bi bi-x-circle"></i> Temizle</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <table id="questionsTable" class="table table-hover">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
                        <th style="width:70px;">Tip</th>
                        <th>Soru</th>
                        <th>Yeterlilik</th>
                        <th>Ders</th>
                        <th style="width:80px;">Doğru</th>
                        <th>Tarih</th>
                        <th style="width:100px;">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($questions as $q): ?>
                    <tr class="question-row" data-id="<?= htmlspecialchars($q['id']) ?>">
                        <td onclick="event.stopPropagation()"><input type="checkbox" class="question-checkbox" value="<?= htmlspecialchars($q['id']) ?>"></td>
                        <td>
                            <?php if ($q['question_type'] === 'sayısal'): ?>
                                <span class="badge bg-success">Sayısal</span>
                            <?php else: ?>
                                <span class="badge bg-info">Sözel</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars(mb_substr($q['question_text'], 0, 60)) ?>...</strong><br>
                            <small class="text-muted">
                                <span class="me-2">A) <?= htmlspecialchars(mb_substr($q['option_a'], 0, 15)) ?>...</span>
                                <span class="me-2">B) <?= htmlspecialchars(mb_substr($q['option_b'], 0, 15)) ?>...</span>
                                <span class="me-2">C) <?= htmlspecialchars(mb_substr($q['option_c'], 0, 15)) ?>...</span>
                                <span>D) <?= htmlspecialchars(mb_substr($q['option_d'], 0, 15)) ?>...</span>
                            </small>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($q['qualification_name']) ?></small></td>
                        <td><strong><?= htmlspecialchars($q['course_name']) ?></strong></td>
                        <td><span class="correct-badge"><?= htmlspecialchars($q['correct_answer']) ?></span></td>
                        <td><?= format_date($q['created_at']) ?></td>
                        <td onclick="event.stopPropagation()">
                            <button class="btn btn-sm btn-info view-btn" data-id="<?= htmlspecialchars($q['id']) ?>" title="Görüntüle"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($q['id']) ?>" title="Düzenle"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($q['id']) ?>" title="Sil"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Soru Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button></div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Soru Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ders *</label>
                            <select class="form-select" name="course_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tip *</label>
                            <select class="form-select" name="question_type" required>
                                <option value="">Seçiniz...</option>
                                <option value="sayısal">Sayısal</option>
                                <option value="sözel">Sözel</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Soru Metni *</label><textarea class="form-control" name="question_text" rows="3" required></textarea></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">A Şıkkı *</label><input type="text" class="form-control" name="option_a" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">B Şıkkı *</label><input type="text" class="form-control" name="option_b" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">C Şıkkı *</label><input type="text" class="form-control" name="option_c" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">D Şıkkı *</label><input type="text" class="form-control" name="option_d" required></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Doğru Cevap *</label>
                        <select class="form-select" name="correct_answer" required>
                            <option value="">Seçiniz...</option><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Açıklama</label><textarea class="form-control" name="explanation" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Soru Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ders *</label>
                            <select class="form-select" name="course_id" id="edit_course_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tip *</label>
                            <select class="form-select" name="question_type" id="edit_question_type" required>
                                <option value="sayısal">Sayısal</option>
                                <option value="sözel">Sözel</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">Soru Metni *</label><textarea class="form-control" name="question_text" id="edit_question_text" rows="3" required></textarea></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">A Şıkkı *</label><input type="text" class="form-control" name="option_a" id="edit_option_a" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">B Şıkkı *</label><input type="text" class="form-control" name="option_b" id="edit_option_b" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">C Şıkkı *</label><input type="text" class="form-control" name="option_c" id="edit_option_c" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">D Şıkkı *</label><input type="text" class="form-control" name="option_d" id="edit_option_d" required></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Doğru Cevap *</label>
                        <select class="form-select" name="correct_answer" id="edit_correct_answer" required>
                            <option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Açıklama</label><textarea class="form-control" name="explanation" id="edit_explanation" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- AI Modal -->
<div class="modal fade" id="aiModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-stars"></i> AI ile Soru Üret</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="aiForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Yeterlilik *</label>
                        <select class="form-select" id="ai_qualification_id" required>
                            <option value="">Önce yeterlilik seçin...</option>
                            <?php foreach ($qualifications as $q): ?>
                                <option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ders *</label>
                        <select class="form-select" name="course_id" id="ai_course_id" required disabled>
                            <option value="">Önce yeterlilik seçin...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tip *</label>
                        <select class="form-select" name="question_type" required>
                            <option value="">Seçiniz...</option>
                            <option value="sayısal">Sayısal</option>
                            <option value="sözel">Sözel</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kaç Soru? *</label>
                        <input type="number" class="form-control" name="count" value="10" min="1" max="20" required>
                        <small class="text-muted">1-20 arası soru üretilebilir</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konu / Açıklama (Opsiyonel)</label>
                        <textarea class="form-control" name="topic" rows="2" placeholder="Örn: Denizcilik mevzuatı, Radar kullanımı, vb."></textarea>
                    </div>
                    <div id="aiProgress" class="d-none">
                        <div class="alert alert-info">
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                <strong>AI Çalışıyor...</strong> Sorular üretiliyor, lütfen bekleyin...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success" id="aiGenerateBtn"><i class="bi bi-stars"></i> Üret</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- AI Preview Modal -->
<div class="modal fade" id="aiPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle"></i> Üretilen Sorular</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="aiPreviewBody" style="max-height: 70vh; overflow-y: auto;"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> İptal Et</button>
                <button type="button" class="btn btn-success" id="saveAiQuestionsBtn"><i class="bi bi-save"></i> Tümünü Kaydet</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
let generatedQuestions = [];
let coursesData = JSON.parse(document.getElementById('courses-data-json').textContent);

$(document).ready(function() {
    $('#questionsTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [[6, 'desc']],
        pageLength: 50,
        responsive: true,
        columnDefs: [{ orderable: false, targets: [0, 7] }]
    });

    $('#filter_qualification').on('change', function() {
        const qualId = $(this).val();
        $('#filter_course option').each(function() {
            const optQual = $(this).data('qualification');
            if (!qualId || !optQual || optQual === qualId) $(this).show();
            else $(this).hide();
        });
        $('#filter_course').val('');
    });

    $('#ai_qualification_id').on('change', function() {
        const qualId = $(this).val();
        const courseSelect = $('#ai_course_id');
        courseSelect.html('<option value="">Ders seçin...</option>');
        if (qualId) {
            const filtered = coursesData.filter(c => c.qualification_id === qualId);
            filtered.forEach(c => courseSelect.append(`<option value="${c.id}">${c.name}</option>`));
            courseSelect.prop('disabled', false);
        } else {
            courseSelect.prop('disabled', true);
        }
    });

    $('#selectAll').on('change', function() {
        $('.question-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkDeleteBtn();
    });
    $(document).on('change', '.question-checkbox', updateBulkDeleteBtn);

    function updateBulkDeleteBtn() {
        const checked = $('.question-checkbox:checked').length;
        if (checked > 0) {
            $('#bulkDeleteBtn').show();
            $('#selectedCount').text(checked);
        } else {
            $('#bulkDeleteBtn').hide();
        }
    }

    $('.question-row').on('click', function() {
        const id = $(this).data('id');
        $('.view-btn[data-id="' + id + '"]').trigger('click');
    });

    $('#bulkDeleteBtn').on('click', function() {
        const ids = $('.question-checkbox:checked').map(function() { return $(this).val(); }).get();
        if (!confirm(ids.length + ' soruyu silmek istediğinizden emin misiniz?')) return;
        $.ajax({
            url: '../ajax/questions.php?action=bulk_delete',
            method: 'POST',
            data: { ids: ids },
            dataType: 'json',
            success: r => r.success ? (alert(r.message), location.reload()) : alert('Hata: ' + r.message)
        });
    });

    $('.view-btn').on('click', function() {
        const id = $(this).data('id');
        $.getJSON('../ajax/questions.php?action=get&id=' + id, function(response) {
            if (!response.success) return alert('Hata: ' + response.message);
            const q = response.data;
            $('#viewModalBody').html(`
                <div class="mb-3"><strong>Soru:</strong><br>${q.question_text}</div>
                <div class="row">
                    <div class="col-md-6 mb-2"><span class="badge ${q.correct_answer==='A'?'bg-success':'bg-secondary'}">A)</span> ${q.option_a}</div>
                    <div class="col-md-6 mb-2"><span class="badge ${q.correct_answer==='B'?'bg-success':'bg-secondary'}">B)</span> ${q.option_b}</div>
                    <div class="col-md-6 mb-2"><span class="badge ${q.correct_answer==='C'?'bg-success':'bg-secondary'}">C)</span> ${q.option_c}</div>
                    <div class="col-md-6 mb-2"><span class="badge ${q.correct_answer==='D'?'bg-success':'bg-secondary'}">D)</span> ${q.option_d}</div>
                </div>
                <div class="mt-3"><strong>Doğru Cevap:</strong> <span class="badge bg-success">${q.correct_answer}</span></div>
                ${q.explanation ? `<div class="mt-3"><strong>Açıklama:</strong><br>${q.explanation}</div>` : ''}
            `);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('viewModal')).show();
        });
    });

    $('#addForm').on('submit', function(e) {
        e.preventDefault();
        $.post('../ajax/questions.php?action=add', $(this).serialize(), function(r) {
            if (r.success) { alert(r.message); location.reload(); }
            else alert('Hata: ' + r.message);
        }, 'json');
    });

    $('.edit-btn').on('click', function() {
        const id = $(this).data('id');
        $.getJSON('../ajax/questions.php?action=get&id=' + id, function(r) {
            if (!r.success) return alert('Hata: ' + r.message);
            const q = r.data;
            $('#edit_id').val(q.id);
            $('#edit_course_id').val(q.course_id);
            $('#edit_question_type').val(q.question_type);
            $('#edit_question_text').val(q.question_text);
            $('#edit_option_a').val(q.option_a);
            $('#edit_option_b').val(q.option_b);
            $('#edit_option_c').val(q.option_c);
            $('#edit_option_d').val(q.option_d);
            $('#edit_correct_answer').val(q.correct_answer);
            $('#edit_explanation').val(q.explanation || '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
        });
    });

    $('#editForm').on('submit', function(e) {
        e.preventDefault();
        $.post('../ajax/questions.php?action=update', $(this).serialize(), function(r) {
            if (r.success) { alert(r.message); location.reload(); }
            else alert('Hata: ' + r.message);
        }, 'json');
    });

    $('.delete-btn').on('click', function() {
        if (!confirm('Bu soruyu silmek istediğinizden emin misiniz?')) return;
        const id = $(this).data('id');
        $.post('../ajax/questions.php?action=delete', { id }, function(r) {
            if (r.success) { alert(r.message); location.reload(); }
            else alert('Hata: ' + r.message);
        }, 'json');
    });

    $('#aiForm').on('submit', function(e) {
        e.preventDefault();
        $('#aiProgress').removeClass('d-none');
        $('#aiGenerateBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Üretiliyor...');
        $.ajax({
            url: '../ajax/ai-generate-questions.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            timeout: 90000,
            success: function(response) {
                $('#aiProgress').addClass('d-none');
                $('#aiGenerateBtn').prop('disabled', false).html('<i class="bi bi-stars"></i> Üret');
                if (response.success && response.questions) {
                    generatedQuestions = response.questions;
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('aiModal')).hide();
                    showAiPreview(generatedQuestions);
                } else {
                    alert('Hata: ' + (response.message || 'Bilinmeyen hata'));
                }
            },
            error: function(xhr) {
                $('#aiProgress').addClass('d-none');
                $('#aiGenerateBtn').prop('disabled', false).html('<i class="bi bi-stars"></i> Üret');
                console.error(xhr.responseText);
                alert('AI hatası! Lütfen tekrar deneyin.');
            }
        });
    });

    function showAiPreview(questions) {
        let html = `<div class="alert alert-success"><strong>${questions.length} soru üretildi!</strong> Soruları kontrol edin, düzenleyin ve kaydedin.</div>`;
        questions.forEach((q, index) => {
            const b = a => q.correct_answer === a ? 'bg-success' : 'bg-secondary';
            html += `
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>#${index + 1} - ${q.question_type}</strong>
                        <button class="btn btn-sm btn-danger remove-ai-question" data-index="${index}"><i class="bi bi-x"></i> Kaldır</button>
                    </div>
                    <div class="card-body">
                        <div class="mb-3"><strong>Soru:</strong><br>${q.question_text}</div>
                        <div class="row">
                            <div class="col-md-6 mb-2"><span class="badge ${b('A')}">A)</span> ${q.option_a}</div>
                            <div class="col-md-6 mb-2"><span class="badge ${b('B')}">B)</span> ${q.option_b}</div>
                            <div class="col-md-6 mb-2"><span class="badge ${b('C')}">C)</span> ${q.option_c}</div>
                            <div class="col-md-6 mb-2"><span class="badge ${b('D')}">D)</span> ${q.option_d}</div>
                        </div>
                        ${q.explanation ? `<div class="mt-3"><strong>Açıklama:</strong><br><em class="text-muted">${q.explanation}</em></div>` : ''}
                    </div>
                </div>`;
        });
        $('#aiPreviewBody').html(html);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('aiPreviewModal')).show();
    }

    $(document).on('click', '.remove-ai-question', function() {
        const index = $(this).data('index');
        generatedQuestions.splice(index, 1);
        showAiPreview(generatedQuestions);
    });

    $('#saveAiQuestionsBtn').on('click', function() {
        if (!generatedQuestions.length) return alert('Kaydedilecek soru yok!');
        $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Kaydediliyor...');
        $.post('../ajax/save-ai-questions.php', { questions: JSON.stringify(generatedQuestions) }, function(r) {
            if (r.success) { alert(r.message); location.reload(); }
            else {
                alert('Hata: ' + r.message);
                $('#saveAiQuestionsBtn').prop('disabled', false).html('<i class="bi bi-save"></i> Tümünü Kaydet');
            }
        }, 'json');
    });
});
</script>
JAVASCRIPT;
?>

<script id="courses-data-json" type="application/json"><?= json_encode($courses, JSON_UNESCAPED_UNICODE) ?></script>

<?php include '../includes/footer.php'; ?>

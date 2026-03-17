<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'questions';
$page_title = 'Sorular';

// Filtreleme parametreleri
$filter_course = $_GET['course'] ?? '';
$filter_type = $_GET['type'] ?? '';

// Soruları çek
$sql = "
    SELECT q.*, c.name as course_name, qual.name as qualification_name
    FROM questions q
    LEFT JOIN courses c ON q.course_id = c.id
    LEFT JOIN qualifications qual ON c.qualification_id = qual.id
    WHERE 1=1
";

$params = [];

if (!empty($filter_course)) {
    $sql .= ' AND q.course_id = ?';
    $params[] = $filter_course;
}

if (!empty($filter_type)) {
    $sql .= ' AND q.question_type = ?';
    $params[] = $filter_type;
}

$sql .= ' ORDER BY q.created_at DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// Tüm dersleri çek (dropdown için)
$courses = $pdo->query("
    SELECT c.*, q.name as qualification_name
    FROM courses c
    LEFT JOIN qualifications q ON c.qualification_id = q.id
    ORDER BY q.order_index, c.order_index
")->fetchAll();

// İstatistikler
$total_questions = $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$sayisal_count = $pdo->query("SELECT COUNT(*) FROM questions WHERE question_type = 'sayısal'")->fetchColumn();
$sozel_count = $pdo->query("SELECT COUNT(*) FROM questions WHERE question_type = 'sözel'")->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <!-- İstatistikler -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted mb-1">Toplam Soru</h6>
                            <h3 class="mb-0"><?= number_format($total_questions) ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 rounded p-3">
                            <i class="bi bi-question-circle fs-2 text-primary"></i>
                        </div>
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
                        <div class="bg-success bg-opacity-10 rounded p-3">
                            <i class="bi bi-calculator fs-2 text-success"></i>
                        </div>
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
                        <div class="bg-info bg-opacity-10 rounded p-3">
                            <i class="bi bi-chat-text fs-2 text-info"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Üst Bar -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Sorular</h2>
        <div class="btn-group">
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#aiModal">
                <i class="bi bi-stars"></i> AI ile Üret
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i> Manuel Ekle
            </button>
        </div>
    </div>

    <!-- Filtreler -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Ders</label>
                    <select class="form-select" name="course">
                        <option value="">Tüm Dersler</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>" <?= $filter_course === $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tip</label>
                    <select class="form-select" name="type">
                        <option value="">Tümü</option>
                        <option value="sayısal" <?= $filter_type === 'sayısal' ? 'selected' : '' ?>>Sayısal</option>
                        <option value="sözel" <?= $filter_type === 'sözel' ? 'selected' : '' ?>>Sözel</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-funnel"></i> Filtrele
                    </button>
                    <a href="/pages/questions.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Temizle
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Soru Listesi -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <table id="questionsTable" class="table table-hover">
                <thead>
                    <tr>
                        <th style="width: 50px;">Tip</th>
                        <th>Soru</th>
                        <th>Ders</th>
                        <th>Doğru Cevap</th>
                        <th>Tarih</th>
                        <th style="width: 100px;">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($questions as $q): ?>
                    <tr>
                        <td>
                            <?php if ($q['question_type'] === 'sayısal'): ?>
                                <span class="badge bg-success">Sayısal</span>
                            <?php else: ?>
                                <span class="badge bg-info">Sözel</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars(mb_substr($q['question_text'], 0, 80)) ?>...</strong>
                        </td>
                        <td>
                            <small class="text-muted"><?= htmlspecialchars($q['qualification_name']) ?></small><br>
                            <strong><?= htmlspecialchars($q['course_name']) ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-primary"><?= htmlspecialchars($q['correct_answer']) ?></span>
                        </td>
                        <td><?= format_date($q['created_at']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-info view-btn" data-id="<?= htmlspecialchars($q['id']) ?>" title="Görüntüle">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($q['id']) ?>" title="Düzenle">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($q['id']) ?>" title="Sil">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- View Modal (Soru Detayı) -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Soru Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- JavaScript ile doldurulacak -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal (Manuel Soru Ekleme) -->
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
                                    <option value="<?= htmlspecialchars($c['id']) ?>">
                                        <?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?>
                                    </option>
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

                    <div class="mb-3">
                        <label class="form-label">Soru Metni *</label>
                        <textarea class="form-control" name="question_text" rows="3" required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">A Şıkkı *</label>
                            <input type="text" class="form-control" name="option_a" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">B Şıkkı *</label>
                            <input type="text" class="form-control" name="option_b" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">C Şıkkı *</label>
                            <input type="text" class="form-control" name="option_c" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">D Şıkkı *</label>
                            <input type="text" class="form-control" name="option_d" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Doğru Cevap *</label>
                        <select class="form-select" name="correct_answer" required>
                            <option value="">Seçiniz...</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="explanation" rows="2"></textarea>
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
                                    <option value="<?= htmlspecialchars($c['id']) ?>">
                                        <?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?>
                                    </option>
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

                    <div class="mb-3">
                        <label class="form-label">Soru Metni *</label>
                        <textarea class="form-control" name="question_text" id="edit_question_text" rows="3" required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">A Şıkkı *</label>
                            <input type="text" class="form-control" name="option_a" id="edit_option_a" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">B Şıkkı *</label>
                            <input type="text" class="form-control" name="option_b" id="edit_option_b" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">C Şıkkı *</label>
                            <input type="text" class="form-control" name="option_c" id="edit_option_c" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">D Şıkkı *</label>
                            <input type="text" class="form-control" name="option_d" id="edit_option_d" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Doğru Cevap *</label>
                        <select class="form-select" name="correct_answer" id="edit_correct_answer" required>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="explanation" id="edit_explanation" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Güncelle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- AI Modal (Soru Üretimi) -->
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
                        <label class="form-label">Ders *</label>
                        <select class="form-select" name="course_id" id="ai_course_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= htmlspecialchars($c['id']) ?>">
                                    <?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
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
                        <input type="number" class="form-control" name="count" value="5" min="1" max="20" required>
                        <small class="text-muted">1-20 arası soru üretilebilir</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Konu / Açıklama (Opsiyonel)</label>
                        <textarea class="form-control" name="topic" rows="2" placeholder="Örn: Denizcilik mevzuatı, Radar kullanımı, vb."></textarea>
                        <small class="text-muted">AI'ya konu hakkında bilgi verin</small>
                    </div>

                    <div id="aiProgress" class="d-none">
                        <div class="alert alert-info">
                            <i class="bi bi-hourglass-split"></i> <strong>AI Çalışıyor...</strong><br>
                            Sorular üretiliyor, lütfen bekleyin...
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success" id="aiGenerateBtn">
                        <i class="bi bi-stars"></i> Üret
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function() {
    console.log('Questions page ready!');

    // DataTable
    $('#questionsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
        },
        order: [[4, 'desc']],
        pageLength: 25,
        responsive: true
    });

    // View Button (Soru Detayı)
    $('.view-btn').on('click', function() {
        const id = $(this).data('id');

        $.ajax({
            url: '../ajax/questions.php?action=get&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const q = response.data;
                    const html = `
                        <div class="mb-3">
                            <strong>Soru:</strong><br>
                            ${q.question_text}
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <span class="badge ${q.correct_answer === 'A' ? 'bg-success' : 'bg-secondary'}">A)</span> ${q.option_a}
                            </div>
                            <div class="col-md-6 mb-2">
                                <span class="badge ${q.correct_answer === 'B' ? 'bg-success' : 'bg-secondary'}">B)</span> ${q.option_b}
                            </div>
                            <div class="col-md-6 mb-2">
                                <span class="badge ${q.correct_answer === 'C' ? 'bg-success' : 'bg-secondary'}">C)</span> ${q.option_c}
                            </div>
                            <div class="col-md-6 mb-2">
                                <span class="badge ${q.correct_answer === 'D' ? 'bg-success' : 'bg-secondary'}">D)</span> ${q.option_d}
                            </div>
                        </div>
                        <div class="mt-3">
                            <strong>Doğru Cevap:</strong> <span class="badge bg-success">${q.correct_answer}</span>
                        </div>
                        ${q.explanation ? `<div class="mt-3"><strong>Açıklama:</strong><br>${q.explanation}</div>` : ''}
                        <div class="mt-3 text-muted">
                            <small>Tip: ${q.question_type}</small>
                        </div>
                    `;

                    $('#viewModalBody').html(html);
                    new bootstrap.Modal(document.getElementById('viewModal')).show();
                }
            }
        });
    });

    // Add Form
    $('#addForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: '../ajax/questions.php?action=add',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Hata: ' + response.message);
                }
            },
            error: function(xhr) {
                console.error(xhr.responseText);
                alert('Hata oluştu!');
            }
        });
    });

    // Edit Button
    $('.edit-btn').on('click', function() {
        const id = $(this).data('id');

        $.ajax({
            url: '../ajax/questions.php?action=get&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const q = response.data;
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

                    new bootstrap.Modal(document.getElementById('editModal')).show();
                }
            }
        });
    });

    // Edit Form
    $('#editForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: '../ajax/questions.php?action=update',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Hata: ' + response.message);
                }
            }
        });
    });

    // Delete Button
    $('.delete-btn').on('click', function() {
        if (!confirm('Bu soruyu silmek istediğinizden emin misiniz?')) return;

        const id = $(this).data('id');

        $.ajax({
            url: '../ajax/questions.php?action=delete',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Hata: ' + response.message);
                }
            }
        });
    });

    // AI Form
    $('#aiForm').on('submit', function(e) {
        e.preventDefault();

        $('#aiProgress').removeClass('d-none');
        $('#aiGenerateBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Üretiliyor...');

        $.ajax({
            url: '../ajax/ai-generate-questions.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            timeout: 60000,
            success: function(response) {
                $('#aiProgress').addClass('d-none');
                $('#aiGenerateBtn').prop('disabled', false).html('<i class="bi bi-stars"></i> Üret');

                if (response.success) {
                    alert('Başarılı! ' + response.count + ' soru eklendi.');
                    location.reload();
                } else {
                    alert('Hata: ' + response.message);
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
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'questions';
$page_title = 'Sorular';

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

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted mb-1">Toplam Soru</h6><h3 class="mb-0"><?= number_format($total_questions) ?></h3></div><div class="stat-icon" style="background:#eef3ff;color:#5f84d8;"><i class="bi bi-question-circle"></i></div></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted mb-1">Sayısal Sorular</h6><h3 class="mb-0"><?= number_format($sayisal_count) ?></h3></div><div class="stat-icon" style="background:#edf8f1;color:#5ea67a;"><i class="bi bi-calculator"></i></div></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted mb-1">Sözel Sorular</h6><h3 class="mb-0"><?= number_format($sozel_count) ?></h3></div><div class="stat-icon" style="background:#eef6ff;color:#4b8dbf;"><i class="bi bi-chat-text"></i></div></div></div></div></div>
    </div>

    <div class="page-header">
        <div>
            <h2>Sorular</h2>
            <p class="text-muted mb-0">Soru bankasını filtreleyin, düzenleyin ve AI ile üretin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-danger" id="bulkDeleteBtn" style="display:none;"><i class="bi bi-trash"></i> Seçilenleri Sil (<span id="selectedCount">0</span>)</button>
            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#bulkUploadModal"><i class="bi bi-upload"></i> Toplu Soru Yükle</button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#aiModal"><i class="bi bi-stars"></i> AI ile Üret</button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg"></i> Manuel Ekle</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Yeterlilik</label>
                    <select class="form-select" id="filterQualification">
                        <option value="">Tüm yeterlilikler</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ders</label>
                    <select class="form-select" id="filterCourse" disabled>
                        <option value="">Tüm dersler</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Konu</label>
                    <select class="form-select" id="filterTopic" disabled>
                        <option value="">Tüm konular</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tip</label>
                    <select class="form-select" id="filterType">
                        <option value="">Tümü</option>
                        <option value="sayısal">Sayısal</option>
                        <option value="sözel">Sözel</option>
                        <option value="karışık">Karışık</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="filterStatus" disabled>
                        <option value="">Tümü</option>
                    </select>
                </div>
                <div class="col-md-10">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="filterSearch" placeholder="Soru metni / şık / açıklama ara...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-secondary w-100" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Filtreyi Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
            <table id="questionsTable" class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th class="questions-col-select" style="width:40px;"><input type="checkbox" id="selectAll"></th>
                        <th class="questions-col-question">Soru</th>
                        <th class="questions-col-meta">Yeterlilik / Ders</th>
                        <th class="questions-actions-col" style="width:80px;">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="4" class="text-muted p-3">Yükleniyor...</td></tr>
                </tbody>
            </table>
            <div class="text-muted p-2 d-none" id="questionsDesktopEmpty">Kayıt bulunamadı.</div>
            </div>
        </div>
    </div>

    <div class="d-md-none" id="questionsMobileList"></div>
    <div class="alert alert-light text-muted d-none mt-2" id="questionsMobileEmpty">Kayıt bulunamadı.</div>
</div>

<!-- Add / Edit modalları -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Yeni Soru Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="addForm"><div class="modal-body">
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Ders *</label><select class="form-select" name="course_id" required><option value="">Seçiniz...</option><?php foreach ($courses as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 mb-3"><label class="form-label">Tip *</label><select class="form-select" name="question_type" required><option value="">Seçiniz...</option><option value="sayısal">Sayısal</option><option value="sözel">Sözel</option><option value="karışık">Karışık</option></select></div>
            </div>
            <div class="mb-3"><label class="form-label">Soru Metni *</label><textarea class="form-control" name="question_text" rows="3" required></textarea></div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">A *</label><input type="text" class="form-control" name="option_a" required></div><div class="col-md-6 mb-3"><label class="form-label">B *</label><input type="text" class="form-control" name="option_b" required></div><div class="col-md-6 mb-3"><label class="form-label">C *</label><input type="text" class="form-control" name="option_c" required></div><div class="col-md-6 mb-3"><label class="form-label">D *</label><input type="text" class="form-control" name="option_d" required></div><div class="col-md-6 mb-3"><label class="form-label">Şık E (Opsiyonel)</label><input type="text" class="form-control" name="option_e"></div></div>
            <div class="mb-3"><label class="form-label">Doğru Cevap *</label><select class="form-select" name="correct_answer" required><option value="">Seçiniz...</option><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option><option value="E">E</option></select></div>
            <div class="mb-3"><label class="form-label">Açıklama</label><textarea class="form-control" name="explanation" rows="2"></textarea></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div></form>
    </div></div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Soru Düzenle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="editForm"><input type="hidden" name="id" id="edit_id"><div class="modal-body">
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Ders *</label><select class="form-select" name="course_id" id="edit_course_id" required><option value="">Seçiniz...</option><?php foreach ($courses as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['qualification_name']) ?> - <?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 mb-3"><label class="form-label">Tip *</label><select class="form-select" name="question_type" id="edit_question_type" required><option value="sayısal">Sayısal</option><option value="sözel">Sözel</option><option value="karışık">Karışık</option></select></div>
            </div>
            <div class="mb-3"><label class="form-label">Soru Metni *</label><textarea class="form-control" name="question_text" id="edit_question_text" rows="3" required></textarea></div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">A *</label><input type="text" class="form-control" name="option_a" id="edit_option_a" required></div><div class="col-md-6 mb-3"><label class="form-label">B *</label><input type="text" class="form-control" name="option_b" id="edit_option_b" required></div><div class="col-md-6 mb-3"><label class="form-label">C *</label><input type="text" class="form-control" name="option_c" id="edit_option_c" required></div><div class="col-md-6 mb-3"><label class="form-label">D *</label><input type="text" class="form-control" name="option_d" id="edit_option_d" required></div><div class="col-md-6 mb-3"><label class="form-label">Şık E (Opsiyonel)</label><input type="text" class="form-control" name="option_e" id="edit_option_e"></div></div>
            <div class="mb-3"><label class="form-label">Doğru Cevap *</label><select class="form-select" name="correct_answer" id="edit_correct_answer" required><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option><option value="E">E</option></select></div>
            <div class="mb-3"><label class="form-label">Açıklama</label><textarea class="form-control" name="explanation" id="edit_explanation" rows="2"></textarea></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Güncelle</button></div></form>
    </div></div>
</div>

<!-- AI Modal -->
<div class="modal fade" id="aiModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-stars"></i> AI ile Soru Üret</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <form id="aiForm"><div class="modal-body">
            <div class="mb-3"><label class="form-label">Yeterlilik *</label><select class="form-select" id="ai_qualification_id" required><option value="">Önce yeterlilik seçin...</option><?php foreach ($qualifications as $q): ?><option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="form-label">Ders *</label><select class="form-select" name="course_id" id="ai_course_id" required disabled><option value="">Önce yeterlilik seçin...</option></select></div>
            <div class="mb-3"><label class="form-label">Soru Türü *</label><select class="form-select" name="question_type" required><option value="mixed">Karışık</option><option value="verbal">Sözel</option><option value="numerical">Sayısal</option></select></div>
            <div class="mb-2"><label class="form-label d-block">Soru Sayısı *</label>
                <div class="btn-group mb-2" role="group">
                    <button type="button" class="btn btn-outline-primary ai-count-btn active" data-count="5">5</button>
                    <button type="button" class="btn btn-outline-primary ai-count-btn" data-count="10">10</button>
                    <button type="button" class="btn btn-outline-primary ai-count-btn" data-count="20">20</button>
                    <button type="button" class="btn btn-outline-primary ai-count-btn" data-count="50">50</button>
                </div>
                <input type="number" class="form-control" id="ai_count_custom" min="1" max="100" value="5">
                <input type="hidden" name="question_count" id="ai_question_count" value="5">
                <small class="text-muted">Min 1 - Max 100</small>
            </div>
            <div class="mb-3"><label class="form-label">Konu / Açıklama (Opsiyonel)</label><textarea class="form-control" name="topic" rows="2" placeholder="Örn: Denizcilik mevzuatı, Radar kullanımı"></textarea></div>
            <div class="mb-3"><label class="form-label">E şıkkı dahil edilsin mi?</label><select class="form-select" name="include_option_e"><option value="0" selected>Hayır</option><option value="1">Evet</option></select></div>
            <div id="aiProgress" class="d-none"><div class="alert alert-info"><div class="d-flex align-items-center"><div class="spinner-border spinner-border-sm me-2"></div><strong>AI Çalışıyor...</strong> Sorular üretiliyor, lütfen bekleyin...</div></div></div>
        </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-success" id="aiGenerateBtn"><i class="bi bi-stars"></i> Üret</button></div></form>
    </div></div>
</div>

<!-- Bulk Upload Modal -->
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
                        <div class="col-md-4">
                            <label class="form-label">Yeterlilik *</label>
                            <select class="form-select" id="bulk_qualification_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($qualifications as $q): ?>
                                    <option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ders *</label>
                            <select class="form-select" id="bulk_course_id" required disabled>
                                <option value="">Önce yeterlilik seçin...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Soru Türü *</label>
                            <select class="form-select" id="bulk_question_type" required>
                                <option value="">Seçiniz...</option>
                                <option value="sözel">Sözel</option>
                                <option value="sayısal">Sayısal</option>
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
E) Şık E (opsiyonel)
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
2-B
3-E</pre>
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

<!-- AI Preview -->
<div class="modal fade" id="aiPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-check-circle"></i> Üretilen Sorular</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="aiPreviewBody" style="max-height:70vh;overflow-y:auto"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-danger" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Kapat</button>
            <button type="button" class="btn btn-success" id="saveAiQuestionsBtn">0 Soruyu Kaydet</button>
        </div>
    </div></div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
window.__QUESTIONS_PAGE_VERSION__ = 'E-OPTION-FIX-1';
console.log('QUESTIONS PAGE VERSION', window.__QUESTIONS_PAGE_VERSION__);

let generatedQuestions = [];
let coursesData = JSON.parse(document.getElementById('courses-data-json').textContent);
let generationMeta = null;

function normalizeCount(v) {
    const n = parseInt(v, 10);
    if (Number.isNaN(n) || n < 1 || n > 100) return null;
    return n;
}

function parseBulkQuestions(rawText, selectedType, selectedCourseId) {
    const stripInvisibleChars = (txt) => (txt || '')
        .replace(/\uFFFC/g, '')
        .replace(/[\u200B-\u200D\u2060\uFEFF\u00AD]/g, '')
        .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');

    const normalizeText = (txt) => stripInvisibleChars(txt)
        .replace(/\u00A0/g, ' ')
        .replace(/\r\n?/g, '\n')
        .replace(/[ \t]+/g, ' ')
        .trim();

    const cleanOptionText = (txt) => normalizeText((txt || '')
        .replace(/\(\s*doğru\s*\)/ig, '')
        .replace(/^[*✓✔]+\s*/, ''));

    const fullText = stripInvisibleChars(rawText || '')
        .replace(/\u00A0/g, ' ')
        .replace(/\r\n?/g, '\n')
        .trim();
    const result = { parsed: [], parsed_count: 0, skipped_count: 0, total_blocks: 0 };

    if (!fullText) {
        return result;
    }

    const answerHeaderRegex = /^\s*cevap\s+anahtarı\s*:?\s*$/im;
    const answerHeaderMatch = answerHeaderRegex.exec(fullText);
    const bodyText = answerHeaderMatch ? fullText.slice(0, answerHeaderMatch.index).trim() : fullText;
    const answerKeyText = answerHeaderMatch ? fullText.slice(answerHeaderMatch.index) : '';

    const answerMap = {};
    if (answerKeyText) {
        const answerRegex = /^\s*(\d+)\s*[-.):]\s*([ABCDE])\s*$/gim;
        let answerMatch;
        while ((answerMatch = answerRegex.exec(answerKeyText)) !== null) {
            answerMap[parseInt(answerMatch[1], 10)] = answerMatch[2].toUpperCase();
        }
    }

    const blockDivider = '__BULK_QUESTION_DIVIDER__';
    const blocks = bodyText
        .replace(/^\s*⸻\s*$/gm, blockDivider)
        .split(blockDivider)
        .map((b) => b.trim())
        .filter((b) => b.length > 0);

    result.total_blocks = blocks.length;
    if (!blocks.length) {
        return result;
    }

    for (const rawBlock of blocks) {
        const blockText = rawBlock.trim();

        const lines = blockText.split('\n').map((line) => line.trim()).filter((line) => line.length > 0);
        if (!lines.length) {
            result.skipped_count++;
            continue;
        }

        const firstLineMatch = lines[0].match(/^\s*(\d+)\s*[\.)]\s*(.*)$/);
        if (!firstLineMatch) {
            result.skipped_count++;
            continue;
        }

        const number = parseInt(firstLineMatch[1], 10);
        lines[0] = (firstLineMatch[2] || '').trim();

        const questionLines = [];
        const options = { A: '', B: '', C: '', D: '', E: '' };
        let currentOption = null;
        let explanationMode = false;
        const explanationLines = [];
        let inferredCorrect = '';

        for (const rawLine of lines) {
            const line = rawLine.trim();
            if (!line) continue;

            if (/^⸻$/.test(line)) {
                continue;
            }

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

            const optMatch = line.match(/^[\s\-–—•\*]*([ABCDE])\s*[\)\.\-:]\s*(.*)$/i);
            if (optMatch) {
                currentOption = optMatch[1].toUpperCase();
                let optValue = optMatch[2] || '';
                if (/^\s*[*✓✔]/.test(optValue) || /\(\s*doğru\s*\)/i.test(optValue)) {
                    inferredCorrect = currentOption;
                }
                const value = cleanOptionText(optValue || '').trim();
                options[currentOption] = value.length ? value : null;
                continue;
            }

            if (currentOption) {
                const currentValue = options[currentOption] || '';
                options[currentOption] = normalizeText(`${currentValue} ${line}`);
            } else {
                questionLines.push(line);
            }
        }

        const questionText = normalizeText(questionLines.join(' '));
        const correctAnswer = (answerMap[number] || inferredCorrect || '').toUpperCase();
        const explanation = normalizeText(explanationLines.join(' '));

        const isValid =
            questionText.length >= 10 &&
            options.A && options.B && options.C && options.D &&
            ['A', 'B', 'C', 'D', 'E'].includes(correctAnswer) &&
            (correctAnswer !== 'E' || (options.E && options.E.length > 0));

        if (!isValid) {
            result.skipped_count++;
            continue;
        }

        result.parsed.push({
            question_text: questionText,
            option_a: options.A,
            option_b: options.B,
            option_c: options.C,
            option_d: options.D,
            option_e: options.E ?? null,
            correct_answer: correctAnswer,
            explanation,
            question_type: selectedType,
            course_id: selectedCourseId,
            status: 'pending'
        });
    }

    result.parsed_count = result.parsed.length;
    return result;
}

function statusCounts() {
    return {
        approved: generatedQuestions.filter(q => q.status === 'approved').length,
        pending: generatedQuestions.filter(q => q.status === 'pending').length,
        cancelled: generatedQuestions.filter(q => q.status === 'cancelled').length
    };
}

function renderAiPreview() {
    const counts = statusCounts();
    $('#saveAiQuestionsBtn').text(counts.approved + ' Soruyu Kaydet').prop('disabled', counts.approved === 0);

    let html = '';

    if (generationMeta && generationMeta.source === 'bulk') {
        const parsed = generationMeta.parsed_count ?? generatedQuestions.length;
        const skipped = generationMeta.skipped_count ?? 0;
        const total = generationMeta.total_blocks ?? parsed + skipped;
        html += `
          <div class="alert alert-info">
            Toplam blok: <strong>${total}</strong> • Ayrıştırılan: <strong>${parsed}</strong> • Atlanan: <strong>${skipped}</strong>
          </div>`;
    } else if (generationMeta) {
        const requested = generationMeta.requested_count ?? generatedQuestions.length;
        const generated = generationMeta.generated_count ?? generatedQuestions.length;
        const filteredDup = generationMeta.filtered_duplicates ?? 0;
        const filteredExisting = generationMeta.filtered_existing ?? 0;

        html += `
          <div class="alert alert-info">
            İstenen: <strong>${requested}</strong> • Üretilen: <strong>${generated}</strong>
            ${filteredDup > 0 || filteredExisting > 0
                ? `• Batch duplicate filtre: <strong>${filteredDup}</strong> • Mevcut sorularla benzerlik filtre: <strong>${filteredExisting}</strong>`
                : ''}
          </div>`;
    }

    html += `
      <div class="row mb-3">
        <div class="col-md-4"><div class="alert alert-success mb-0">Onaylanan: <strong>${counts.approved}</strong></div></div>
        <div class="col-md-4"><div class="alert alert-warning mb-0">Bekleyen: <strong>${counts.pending}</strong></div></div>
        <div class="col-md-4"><div class="alert alert-danger mb-0">İptal Edilen: <strong>${counts.cancelled}</strong></div></div>
      </div>`;

    generatedQuestions.forEach((q, index) => {
        const cardClass = q.status === 'approved' ? 'approved' : (q.status === 'cancelled' ? 'cancelled' : '');
        const tag = q.status === 'approved' ? '<span class="badge bg-success">Onaylandı</span>' : (q.status === 'cancelled' ? '<span class="badge bg-danger">İptal</span>' : '<span class="badge bg-secondary">Bekleyen</span>');

        if (q._editing) {
            html += `
            <div class="card mb-3 ai-card ${cardClass}">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong>#${index + 1}</strong> ${tag}
              </div>
              <div class="card-body">
                <div class="mb-2"><label class="form-label">Soru</label><textarea class="form-control ai-draft-field" data-index="${index}" data-field="question_text" rows="2">${q._draft.question_text || ''}</textarea></div>
                <div class="row">
                  <div class="col-md-6 mb-2"><label class="form-label">A</label><input class="form-control ai-draft-field" data-index="${index}" data-field="option_a" value="${q._draft.option_a || ''}"></div>
                  <div class="col-md-6 mb-2"><label class="form-label">B</label><input class="form-control ai-draft-field" data-index="${index}" data-field="option_b" value="${q._draft.option_b || ''}"></div>
                  <div class="col-md-6 mb-2"><label class="form-label">C</label><input class="form-control ai-draft-field" data-index="${index}" data-field="option_c" value="${q._draft.option_c || ''}"></div>
                  <div class="col-md-6 mb-2"><label class="form-label">D</label><input class="form-control ai-draft-field" data-index="${index}" data-field="option_d" value="${q._draft.option_d || ''}"></div>
                  <div class="col-md-6 mb-2"><label class="form-label">E (Opsiyonel)</label><input class="form-control ai-draft-field" data-index="${index}" data-field="option_e" value="${q._draft.option_e || ''}"></div>
                </div>
                <div class="row">
                  <div class="col-md-3"><label class="form-label">Doğru Cevap</label><select class="form-select ai-draft-field" data-index="${index}" data-field="correct_answer"><option ${q._draft.correct_answer==='A'?'selected':''}>A</option><option ${q._draft.correct_answer==='B'?'selected':''}>B</option><option ${q._draft.correct_answer==='C'?'selected':''}>C</option><option ${q._draft.correct_answer==='D'?'selected':''}>D</option><option ${q._draft.correct_answer==='E'?'selected':''}>E</option></select></div>
                  <div class="col-md-9"><label class="form-label">Açıklama</label><input class="form-control ai-draft-field" data-index="${index}" data-field="explanation" value="${q._draft.explanation || ''}"></div>
                </div>
                <div class="mt-3">
                  <button class="btn btn-primary btn-sm ai-edit-save" data-index="${index}">Düzenlemeyi Onayla</button>
                  <button class="btn btn-secondary btn-sm ai-edit-cancel" data-index="${index}">İptal</button>
                </div>
              </div>
            </div>`;
        } else {
            const b = (letter) => q.correct_answer === letter ? 'bg-success text-white' : 'bg-light';
            html += `
            <div class="card mb-3 ai-card ${cardClass}">
              <div class="card-header d-flex justify-content-between align-items-center">
                <div><strong>#${index + 1}</strong> ${tag}</div>
                <div class="btn-group btn-group-sm">
                  ${q.status === 'approved' || q.status === 'cancelled' ? `<button class="btn btn-outline-secondary ai-revert" data-index="${index}">Geri Al</button>` : ''}
                  ${q.status !== 'approved' ? `<button class="btn btn-outline-success ai-approve" data-index="${index}">Onayla</button>` : ''}
                  ${q.status !== 'cancelled' ? `<button class="btn btn-outline-danger ai-cancel" data-index="${index}">İptal</button>` : ''}
                  <button class="btn btn-outline-warning ai-edit" data-index="${index}">Düzenle</button>
                </div>
              </div>
              <div class="card-body">
                <div class="mb-2"><strong>Soru:</strong> ${q.question_text || ''}</div>
                <div class="row g-2">
                  <div class="col-md-6"><div class="p-2 rounded ${b('A')}">A) ${q.option_a || ''}</div></div>
                  <div class="col-md-6"><div class="p-2 rounded ${b('B')}">B) ${q.option_b || ''}</div></div>
                  <div class="col-md-6"><div class="p-2 rounded ${b('C')}">C) ${q.option_c || ''}</div></div>
                  <div class="col-md-6"><div class="p-2 rounded ${b('D')}">D) ${q.option_d || ''}</div></div>
                  ${q.option_e ? `<div class="col-md-6"><div class="p-2 rounded ${b('E')}">E) ${q.option_e || ''}</div></div>` : ''}
                </div>
                ${q.explanation ? `<div class="mt-2 text-muted"><small>${q.explanation}</small></div>` : ''}
              </div>
            </div>`;
        }
    });

    $('#aiPreviewBody').html(html || '<div class="alert alert-warning">Soru yok.</div>');
}

function formatSkipSummary(resp) {
    const skippedCount = Number(resp?.skipped_count || 0);
    const reasons = resp?.skipped_reasons || {};
    const samples = Array.isArray(resp?.skipped_samples) ? resp.skipped_samples : [];

    if (!skippedCount) {
        return '';
    }

    const reasonLines = Object.entries(reasons)
        .map(([reason, count]) => `${reason}: ${count}`)
        .join('\n');

    const sampleLines = samples.slice(0, 3).map((s, idx) => {
        const q = s.question_text || '(metin yok)';
        return `${idx + 1}) [${s.reason}] ${q}`;
    }).join('\n');

    let msg = `Atlanan soru sayısı: ${skippedCount}`;
    if (reasonLines) {
        msg += `\n\nNedenler:\n${reasonLines}`;
    }
    if (sampleLines) {
        msg += `\n\nÖrnekler:\n${sampleLines}`;
    }

    return msg;
}

function formatDebugSummary(resp) {
    const debugVersion = resp?.debug_version || '-';
    const receivedCount = Number(resp?.debug_received_questions_count || 0);
    const eAnswerCount = Number(resp?.debug_e_answer_count || 0);
    const eWithOptionECount = Number(resp?.debug_e_with_option_e_count || 0);

    return `Debug Version: ${debugVersion}\nAlınan Soru: ${receivedCount}\nE Cevaplı: ${eAnswerCount}\nE + option_e dolu: ${eWithOptionECount}`;
}

function toPrettyJson(value) {
    try {
        return JSON.stringify(value, null, 2);
    } catch (e) {
        return String(value);
    }
}

function formatSaveResponseDetails(resp) {
    const detail = {
        success: !!resp?.success,
        message: resp?.message ?? null,
        saved_count: resp?.saved_count ?? null,
        skipped_count: resp?.skipped_count ?? null,
        skipped_reasons: resp?.skipped_reasons ?? null,
        skipped_samples: resp?.skipped_samples ?? null,
        row_results: resp?.row_results ?? null,
        exception_message: resp?.exception_message ?? null,
        exception_code: resp?.exception_code ?? null,
        debug_version: resp?.debug_version ?? null,
        debug_e_answer_count: resp?.debug_e_answer_count ?? null,
        debug_e_saved_count: resp?.debug_e_saved_count ?? null,
        debug_e_skipped_count: resp?.debug_e_skipped_count ?? null,
    };

    return `<pre style="text-align:left;max-height:360px;overflow:auto;white-space:pre-wrap;">${toPrettyJson(detail)}</pre>`;
}

function parseRawJson(text) {
    if (!text || typeof text !== 'string') return null;
    try {
        return JSON.parse(text);
    } catch (e) {
        return null;
    }
}

$(document).ready(function() {
    let isSavingAiQuestions = false;

    const appAlert = (title, message, type = 'info') => {
        if (typeof window.showAppAlert === 'function') {
            window.showAppAlert(title, message, type);
        }
    };

    const setSaveButtonState = (isLoading, text) => {
        const $btn = $('#saveAiQuestionsBtn');
        $btn.prop('disabled', !!isLoading).text(text);
        isSavingAiQuestions = !!isLoading;
    };

    const closeAiPreviewModalSafely = () => {
        const modalEl = document.getElementById('aiPreviewModal');
        if (!modalEl) return;

        const instance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.hide();

        // Bootstrap state cleanup (edge-case: stuck backdrop/modal-open)
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
        document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove());

        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
    };

    const appConfirm = (title, message, options = {}) => {
        if (typeof window.showAppConfirm === 'function') {
            return window.showAppConfirm({ title, message, ...options });
        }
        return Promise.resolve(false);
    };

    const esc = (v) => $('<div>').text(v ?? '').html();
    const qState = {
        qualifications: [],
        courses: [],
        topics: [],
        filters: {
            qualification_id: '',
            course_id: '',
            topic_id: '',
            question_type: '',
            status: '',
            search: ''
        },
        meta: {
            has_topic_filter: false,
            has_status_filter: false,
            status_options: []
        }
    };

    const shortText = (txt, max = 90) => {
        const t = String(txt || '');
        return t.length > max ? `${t.slice(0, max)}…` : t;
    };

    const typeBadge = (type) => {
        if (type === 'sayısal') return '<span class="badge bg-success">Sayısal</span>';
        if (type === 'karışık') return '<span class="badge bg-warning text-dark">Karışık</span>';
        return '<span class="badge bg-info">Sözel</span>';
    };

    function renderDesktopRows(rows) {
        const $tb = $('#questionsTable tbody');
        if (!rows.length) {
            $tb.html('<tr><td colspan="4" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        const html = rows.map((q) => {
            const optE = q.option_e ? `<div class="meq-option ${q.correct_answer === 'E' ? 'meq-option-correct' : ''}">E) ${esc(shortText(q.option_e))}</div>` : '';
            return `
                <tr class="question-row-card desktop-question-row">
                    <td class="questions-col-select" onclick="event.stopPropagation()"><input type="checkbox" class="question-checkbox" value="${esc(q.id)}"></td>
                    <td class="questions-col-question">
                        <div class="question-mobile-head">
                            <strong class="question-title-mobile question-title">${esc(shortText(q.question_text, 220))}</strong>
                        </div>
                        <div class="mt-1">${typeBadge(q.question_type)}</div>
                        <div class="question-mobile-meta d-none">
                            <span>${esc(q.qualification_name || '-')}</span><span>/</span><span>${esc(q.course_name || '-')}</span>
                        </div>
                        <div class="meq-options-grid mt-2">
                            <div class="meq-option ${q.correct_answer === 'A' ? 'meq-option-correct' : ''}">A) ${esc(shortText(q.option_a))}</div>
                            <div class="meq-option ${q.correct_answer === 'B' ? 'meq-option-correct' : ''}">B) ${esc(shortText(q.option_b))}</div>
                            <div class="meq-option ${q.correct_answer === 'C' ? 'meq-option-correct' : ''}">C) ${esc(shortText(q.option_c))}</div>
                            <div class="meq-option ${q.correct_answer === 'D' ? 'meq-option-correct' : ''}">D) ${esc(shortText(q.option_d))}</div>
                            ${optE}
                        </div>
                    </td>
                    <td class="questions-col-meta">
                        <div>${esc(q.qualification_name || '-')}</div>
                        <small class="text-muted">${esc(q.course_name || '-')}</small>
                    </td>
                    <td class="questions-actions-cell" onclick="event.stopPropagation()">
                        <div class="table-actions questions-actions-wrap">
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(q.id)}" title="Düzenle"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(q.id)}" title="Sil"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        $tb.html(html);
    }

    function renderMobileRows(rows) {
        const $list = $('#questionsMobileList');
        if (!rows.length) {
            $list.empty();
            $('#questionsMobileEmpty').removeClass('d-none');
            return;
        }
        $('#questionsMobileEmpty').addClass('d-none');

        const html = rows.map((q) => {
            const optE = q.option_e ? `<div class="meq-option ${q.correct_answer === 'E' ? 'meq-option-correct' : ''}">E) ${esc(shortText(q.option_e))}</div>` : '';
            return `
                <div class="card mb-3 meq-mobile-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="form-check" onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input question-checkbox" value="${esc(q.id)}">
                            </div>
                            <div>${typeBadge(q.question_type)}</div>
                        </div>
                        <div class="fw-semibold mt-2">${esc(shortText(q.question_text, 220))}</div>
                        <div class="small text-muted mt-1">${esc(q.qualification_name || '-')} / ${esc(q.course_name || '-')}</div>
                        <div class="meq-options-grid mt-2">
                            <div class="meq-option ${q.correct_answer === 'A' ? 'meq-option-correct' : ''}">A) ${esc(shortText(q.option_a))}</div>
                            <div class="meq-option ${q.correct_answer === 'B' ? 'meq-option-correct' : ''}">B) ${esc(shortText(q.option_b))}</div>
                            <div class="meq-option ${q.correct_answer === 'C' ? 'meq-option-correct' : ''}">C) ${esc(shortText(q.option_c))}</div>
                            <div class="meq-option ${q.correct_answer === 'D' ? 'meq-option-correct' : ''}">D) ${esc(shortText(q.option_d))}</div>
                            ${optE}
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(q.id)}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(q.id)}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        $list.html(html);
    }

    function renderStatusOptions() {
        const $status = $('#filterStatus');
        $status.html('<option value="">Tümü</option>');
        if (!qState.meta.has_status_filter) {
            $status.prop('disabled', true);
            return;
        }
        (qState.meta.status_options || []).forEach((s) => {
            $status.append(`<option value="${esc(s.value)}">${esc(s.label || s.value)}</option>`);
        });
        $status.prop('disabled', false).val(qState.filters.status || '');
    }

    async function loadQualifications() {
        const res = await window.appAjax({ url: '../ajax/questions.php?action=list_qualifications' });
        if (!res.success) return;
        qState.qualifications = res.data?.qualifications || [];
        const $q = $('#filterQualification');
        $q.html('<option value="">Tüm yeterlilikler</option>');
        qState.qualifications.forEach((row) => {
            $q.append(`<option value="${esc(row.id)}">${esc(row.name)}</option>`);
        });
    }

    async function loadCourses() {
        const query = qState.filters.qualification_id ? `&qualification_id=${encodeURIComponent(qState.filters.qualification_id)}` : '';
        const res = await window.appAjax({ url: `../ajax/questions.php?action=list_courses${query}` });
        if (!res.success) return;
        qState.courses = res.data?.courses || [];
        const $c = $('#filterCourse');
        $c.html('<option value="">Tüm dersler</option>');
        qState.courses.forEach((row) => $c.append(`<option value="${esc(row.id)}">${esc(row.name)}</option>`));
        $c.prop('disabled', qState.courses.length === 0).val(qState.filters.course_id || '');
    }

    async function loadTopics() {
        const $t = $('#filterTopic');
        if (!qState.filters.course_id) {
            qState.topics = [];
            $t.html('<option value="">Tüm konular</option>').prop('disabled', true);
            qState.filters.topic_id = '';
            return;
        }
        const res = await window.appAjax({ url: `../ajax/questions.php?action=list_topics&course_id=${encodeURIComponent(qState.filters.course_id)}` });
        if (!res.success) return;
        qState.meta.has_topic_filter = !!(res.data?.meta?.has_topic_filter);
        qState.topics = res.data?.topics || [];
        $t.html('<option value="">Tüm konular</option>');
        qState.topics.forEach((row) => $t.append(`<option value="${esc(row.id)}">${esc(row.name)}</option>`));
        $t.prop('disabled', !qState.meta.has_topic_filter || qState.topics.length === 0).val(qState.filters.topic_id || '');
    }

    async function loadQuestions() {
        const params = new URLSearchParams({ action: 'list_questions' });
        Object.entries(qState.filters).forEach(([k, v]) => { if (v) params.append(k, v); });
        const res = await window.appAjax({ url: `../ajax/questions.php?${params.toString()}` });
        if (!res.success) {
            renderDesktopRows([]);
            renderMobileRows([]);
            return;
        }
        qState.meta = { ...qState.meta, ...(res.data?.meta || {}) };
        renderStatusOptions();
        const rows = res.data?.questions || [];
        renderDesktopRows(rows);
        renderMobileRows(rows);
        $('#selectAll').prop('checked', false);
        toggleBulk();
    }

    const debouncedLoad = (() => {
        let timer = null;
        return () => {
            clearTimeout(timer);
            timer = setTimeout(() => loadQuestions(), 280);
        };
    })();

    $('#filterQualification').on('change', async function () {
        qState.filters.qualification_id = $(this).val() || '';
        qState.filters.course_id = '';
        qState.filters.topic_id = '';
        await loadCourses();
        await loadTopics();
        await loadQuestions();
    });

    $('#filterCourse').on('change', async function () {
        qState.filters.course_id = $(this).val() || '';
        qState.filters.topic_id = '';
        await loadTopics();
        await loadQuestions();
    });

    $('#filterTopic').on('change', function () {
        qState.filters.topic_id = $(this).val() || '';
        loadQuestions();
    });

    $('#filterType').on('change', function () {
        qState.filters.question_type = $(this).val() || '';
        loadQuestions();
    });

    $('#filterStatus').on('change', function () {
        qState.filters.status = $(this).val() || '';
        loadQuestions();
    });

    $('#filterSearch').on('input', function () {
        qState.filters.search = ($(this).val() || '').trim();
        debouncedLoad();
    });

    $('#clearFiltersBtn').on('click', async function (e) {
        e.preventDefault();
        qState.filters = { qualification_id: '', course_id: '', topic_id: '', question_type: '', status: '', search: '' };
        $('#filterQualification').val('');
        $('#filterType').val('');
        $('#filterStatus').val('');
        $('#filterSearch').val('');
        await loadCourses();
        await loadTopics();
        await loadQuestions();
    });

    $('#ai_qualification_id').on('change', function() {
        const qualId = $(this).val();
        const $course = $('#ai_course_id');
        $course.html('<option value="">Ders seçin...</option>');
        if (!qualId) return $course.prop('disabled', true);
        coursesData.filter(c => c.qualification_id === qualId).forEach(c => $course.append(`<option value="${c.id}">${c.name}</option>`));
        $course.prop('disabled', false);
    });

    $('#bulk_qualification_id').on('change', function() {
        const qualId = $(this).val();
        const $course = $('#bulk_course_id');
        $course.html('<option value="">Ders seçin...</option>');
        if (!qualId) {
            $course.prop('disabled', true);
            return;
        }
        coursesData
            .filter(c => c.qualification_id === qualId)
            .forEach(c => $course.append(`<option value="${c.id}">${c.name}</option>`));
        $course.prop('disabled', false);
    });

    $('#bulkUploadForm').on('submit', function(e) {
        e.preventDefault();

        const qualificationId = $('#bulk_qualification_id').val();
        const courseId = $('#bulk_course_id').val();
        const questionType = $('#bulk_question_type').val();
        const rawText = $('#bulk_questions_text').val();

        if (!qualificationId) return appAlert('Uyarı', 'Lütfen yeterlilik seçiniz.', 'warning');
        if (!courseId) return appAlert('Uyarı', 'Lütfen ders seçiniz.', 'warning');
        if (!questionType) return appAlert('Uyarı', 'Lütfen soru türü seçiniz.', 'warning');
        if (!rawText || !rawText.trim()) return appAlert('Uyarı', 'Lütfen soru metnini yapıştırınız.', 'warning');

        const parsedResult = parseBulkQuestions(rawText, questionType, courseId);
        if (!parsedResult.parsed_count) {
            return appAlert('Hata', 'Hiç soru ayrıştırılamadı. Format hatalı olabilir.', 'error');
        }

        generatedQuestions = parsedResult.parsed;
        generationMeta = {
            source: 'bulk',
            parsed_count: parsedResult.parsed_count,
            skipped_count: parsedResult.skipped_count,
            total_blocks: parsedResult.total_blocks
        };

        bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkUploadModal')).hide();
        $('#bulk_questions_text').val('');
        renderAiPreview();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('aiPreviewModal')).show();
    });

    $('#bulkUploadModal').on('show.bs.modal', function() {
        $('#bulk_questions_text').val('');
    });

    $('#bulkUploadModal').on('hidden.bs.modal', function() {
        $('#bulk_questions_text').val('');
    });

    $('.ai-count-btn').on('click', function() {
        $('.ai-count-btn').removeClass('active');
        $(this).addClass('active');
        const v = $(this).data('count');
        $('#ai_count_custom').val(v);
        $('#ai_question_count').val(v);
    });

    $('#ai_count_custom').on('input', function() {
        const v = normalizeCount($(this).val());
        if (v === null) return;
        $('#ai_question_count').val(v);
        $('.ai-count-btn').removeClass('active');
    });

    $('#selectAll').on('change', function() {
        $('.question-checkbox').prop('checked', this.checked);
        toggleBulk();
    });
    $(document).on('change', '.question-checkbox', toggleBulk);
    function toggleBulk() {
        const n = $('.question-checkbox:checked').length;
        $('#selectedCount').text(n);
        $('#bulkDeleteBtn').toggle(n > 0);
    }

    $('#bulkDeleteBtn').on('click', async function() {
        const ids = $('.question-checkbox:checked').map(function(){ return $(this).val(); }).get();
        const ok = await appConfirm('Toplu Silme Onayı', ids.length + ' soruyu silmek istediğinizden emin misiniz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        $.post('../ajax/questions.php?action=bulk_delete', { ids }, function(r){
            if(r.success){
                appAlert('Başarılı', r.message, 'success');
                setTimeout(() => location.reload(), 350);
            } else {
                appAlert('Hata', r.message, 'error');
            }
        }, 'json');
    });

    $('#addForm').on('submit', function(e){
        e.preventDefault();
        const addCorrect = String($('[name="correct_answer"]', this).val() || '').toUpperCase();
        const addOptionE = String($('[name="option_e"]', this).val() || '').trim();
        if (addCorrect === 'E' && addOptionE === '') {
            return appAlert('Uyarı', 'Doğru cevap E ise Şık E (Opsiyonel) alanı doldurulmalıdır.', 'warning');
        }
        $.post('../ajax/questions.php?action=add', $(this).serialize(), function(r){
            if (r.success) {
                appAlert('Başarılı', r.message, 'success');
                setTimeout(() => location.reload(), 350);
            } else {
                appAlert('Hata', r.message, 'error');
            }
        }, 'json');
    });
    $(document).on('click', '.edit-btn', function(){
        const id=$(this).data('id');
        $.getJSON('../ajax/questions.php?action=get&id='+id, function(r){
            if(!r.success) return appAlert('Hata', r.message, 'error');
            const q=r.data;
            $('#edit_id').val(q.id); $('#edit_course_id').val(q.course_id); $('#edit_question_type').val(q.question_type);
            $('#edit_question_text').val(q.question_text); $('#edit_option_a').val(q.option_a); $('#edit_option_b').val(q.option_b);
            $('#edit_option_c').val(q.option_c); $('#edit_option_d').val(q.option_d); $('#edit_option_e').val(q.option_e || ''); $('#edit_correct_answer').val((q.correct_answer || '').toUpperCase()); $('#edit_explanation').val(q.explanation||'');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
        });
    });
    $('#editForm').on('submit', function(e){
        e.preventDefault();
        const editCorrect = String($('#edit_correct_answer').val() || '').toUpperCase();
        const editOptionE = String($('#edit_option_e').val() || '').trim();
        if (editCorrect === 'E' && editOptionE === '') {
            return appAlert('Uyarı', 'Doğru cevap E ise Şık E (Opsiyonel) alanı doldurulmalıdır.', 'warning');
        }
        $.post('../ajax/questions.php?action=update', $(this).serialize(), function(r){
            if (r.success) {
                appAlert('Başarılı', r.message, 'success');
                setTimeout(() => location.reload(), 350);
            } else {
                appAlert('Hata', r.message, 'error');
            }
        }, 'json');
    });
    $(document).on('click', '.delete-btn', async function(){
        const id=$(this).data('id');
        const ok = await appConfirm('Silme Onayı', 'Bu soruyu silmek istediğinizden emin misiniz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        $.post('../ajax/questions.php?action=delete', {id}, function(r){
            if (r.success) {
                appAlert('Başarılı', r.message, 'success');
                setTimeout(() => location.reload(), 350);
            } else {
                appAlert('Hata', r.message, 'error');
            }
        }, 'json');
    });

    $('#aiForm').on('submit', function(e){
        e.preventDefault();
        const count = normalizeCount($('#ai_question_count').val());
        if (count === null) return appAlert('Uyarı', 'Soru sayısı 1-100 arasında olmalıdır!', 'warning');

        $('#aiProgress').removeClass('d-none');
        $('#aiGenerateBtn').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Üretiliyor...');
        $.ajax({
            url:'../ajax/ai-generate-questions.php', method:'POST', data:$(this).serialize(), dataType:'json', timeout:90000,
            success:function(r){
                $('#aiProgress').addClass('d-none');
                $('#aiGenerateBtn').prop('disabled', false).html('<i class="bi bi-stars"></i> Üret');
                if(!(r.success && Array.isArray(r.questions))) return appAlert('Hata', (r.message||'Bilinmeyen hata'), 'error');
                generatedQuestions = r.questions.map(q => ({ ...q, status:'pending' }));
                generationMeta = {
                    requested_count: r.requested_count ?? count,
                    generated_count: r.generated_count ?? generatedQuestions.length,
                    filtered_duplicates: r.filtered_duplicates ?? 0,
                    filtered_existing: r.filtered_existing ?? 0
                };

                const validationSkipMsg = formatSkipSummary({
                    skipped_count: r.validation_skipped_count,
                    skipped_reasons: r.validation_skipped_reasons,
                    skipped_samples: r.validation_skipped_samples
                });

                if (validationSkipMsg) {
                    appAlert('AI Filtre Bilgisi', validationSkipMsg.replace(/\n/g, '<br>'), 'warning');
                }

                bootstrap.Modal.getOrCreateInstance(document.getElementById('aiModal')).hide();
                renderAiPreview();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('aiPreviewModal')).show();
            },
            error:function(xhr){
                $('#aiProgress').addClass('d-none');
                $('#aiGenerateBtn').prop('disabled', false).html('<i class="bi bi-stars"></i> Üret');
                console.error(xhr.responseText); appAlert('Hata', 'AI hatası!', 'error');
            }
        });
    });

    $(document).on('click', '.ai-approve', function(){ generatedQuestions[$(this).data('index')].status='approved'; renderAiPreview(); });
    $(document).on('click', '.ai-cancel', function(){ generatedQuestions[$(this).data('index')].status='cancelled'; renderAiPreview(); });
    $(document).on('click', '.ai-revert', function(){ generatedQuestions[$(this).data('index')].status='pending'; renderAiPreview(); });
    $(document).on('click', '.ai-edit', function(){ const i=$(this).data('index'); generatedQuestions[i]._editing=true; generatedQuestions[i]._draft={...generatedQuestions[i]}; renderAiPreview(); });
    $(document).on('input change', '.ai-draft-field', function(){ const i=$(this).data('index'); const f=$(this).data('field'); generatedQuestions[i]._draft[f]=$(this).val(); });
    $(document).on('click', '.ai-edit-cancel', function(){ const i=$(this).data('index'); generatedQuestions[i]._editing=false; delete generatedQuestions[i]._draft; renderAiPreview(); });
    $(document).on('click', '.ai-edit-save', function(){
        const i=$(this).data('index'); const d=generatedQuestions[i]._draft;
        const answer = (d.correct_answer || '').toUpperCase();
        if(!d.question_text||!d.option_a||!d.option_b||!d.option_c||!d.option_d||!['A','B','C','D','E'].includes(answer)){
            return appAlert('Uyarı', 'Düzenleme geçersiz. Zorunlu alanları kontrol edin.', 'warning');
        }
        if(answer === 'E' && !(d.option_e || '').trim()){
            return appAlert('Uyarı', 'Düzenleme geçersiz. Zorunlu alanları kontrol edin.', 'warning');
        }
        Object.assign(generatedQuestions[i], {
            question_text:d.question_text, option_a:d.option_a, option_b:d.option_b, option_c:d.option_c, option_d:d.option_d,
            option_e:d.option_e||'', correct_answer:answer, explanation:d.explanation||''
        });
        generatedQuestions[i]._editing=false; delete generatedQuestions[i]._draft; renderAiPreview();
    });

    $('#saveAiQuestionsBtn').on('click', async function(){
        if (isSavingAiQuestions) return;

        const approved = generatedQuestions.filter(q => q.status === 'approved');
        if(!approved.length) return appAlert('Uyarı', 'Kaydedilecek onaylı soru yok!', 'warning');
        const idleButtonText = approved.length + ' Soruyu Kaydet';

        const normalizedApproved = approved.map(q => {
            const item = { ...q };
            if (item.option_e === '') {
                item.option_e = null;
            }
            return item;
        });

        const ok = await appConfirm(
            'Kaydetme Onayı',
            'Bu işlem geri alınamaz.<br><br>Onaylanan <strong>' + approved.length + '</strong> soru veritabanına kaydedilecek.<br><br>Devam etmek istiyor musunuz?',
            { type: 'warning', confirmText: 'Kaydet', cancelText: 'İptal' }
        );
        if (!ok) return;

        console.log('SAVE PAYLOAD', normalizedApproved);

        setSaveButtonState(true, 'Kaydediliyor...');
        let shouldCloseModal = false;
        let shouldReload = false;

        $.ajax({
            url: '../ajax/save-ai-questions.php',
            method: 'POST',
            dataType: 'json',
            xhrFields: { withCredentials: true },
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            data: { questions: JSON.stringify(normalizedApproved) },
            success: function(r){
                console.log('SAVE RESPONSE SUCCESS', r);
                const skipSummary = formatSkipSummary(r);
                const debugSummary = formatDebugSummary(r);
                const detailsHtml = formatSaveResponseDetails(r);

                if(r.success){
                    const msgParts = [r.message, debugSummary];
                    if (skipSummary) msgParts.push(skipSummary);
                    msgParts.push('Backend Response Detayı:', detailsHtml);
                    const msg = msgParts.filter(Boolean).join('<br><br>').replace(/\n/g, '<br>');
                    appAlert('Başarılı', msg, 'success');
                    shouldCloseModal = true;
                    shouldReload = true;
                    return;
                }

                const errParts = [r.message, debugSummary];
                if (skipSummary) errParts.push(skipSummary);
                errParts.push('Backend Response Detayı:', detailsHtml);
                const errMsg = errParts.filter(Boolean).join('<br><br>').replace(/\n/g, '<br>');
                appAlert('Hata', errMsg, 'error');
            },
            error: function(xhr){
                console.log('SAVE RESPONSE ERROR', xhr.status, xhr.responseText);

                if (xhr.status === 401) {
                    appAlert('Oturum', 'Oturum süresi dolmuş görünüyor. Lütfen tekrar giriş yapın.', 'warning');
                    setTimeout(() => {
                        window.location.href = '/index.php';
                    }, 700);
                    return;
                }

                const raw = xhr.responseText || '(boş response)';
                const parsed = parseRawJson(raw);
                const errorBody = parsed ? `<pre style="text-align:left;max-height:360px;overflow:auto;white-space:pre-wrap;">${toPrettyJson(parsed)}</pre>` : `<pre style="text-align:left;max-height:360px;overflow:auto;white-space:pre-wrap;">${String(raw)}</pre>`;

                appAlert('Hata', `Kaydetme sırasında bir hata oluştu.<br><br>HTTP: ${xhr.status}<br><br>Raw Response:<br>${errorBody}`, 'error');
            },
            complete: function(){
                setSaveButtonState(false, idleButtonText);

                if (shouldCloseModal) {
                    closeAiPreviewModalSafely();
                }

                if (shouldReload) {
                    setTimeout(() => location.reload(), 600);
                }
            }
        });
    });

    (async function initQuestionsPage() {
        await loadQualifications();
        await loadCourses();
        await loadTopics();
        await loadQuestions();
    })();
});
</script>
JAVASCRIPT;
?>

<script id="courses-data-json" type="application/json"><?= json_encode($courses, JSON_UNESCAPED_UNICODE) ?></script>

<style>
#questionsTable tbody td {
    vertical-align: top;
}

.question-row-card {
    transition: background-color .15s ease;
}

.question-title {
    display: block;
    font-weight: 600;
    color: var(--text-main);
    line-height: 1.35;
}

.meq-options-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
}

.meq-option {
    background: var(--bg-soft);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 8px;
    color: var(--text-main);
    font-size: 13px;
    line-height: 1.35;
}

.meq-option-correct {
    background: var(--success-soft);
    border-color: var(--success);
    font-weight: 600;
}

.question-course {
    color: var(--text-main);
    font-weight: 600;
}

.questions-actions-wrap {
    justify-content: flex-end;
}

@media (max-width: 991.98px) {
    .meq-options-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .question-mobile-meta {
        display: flex !important;
        gap: 6px;
        color: var(--text-muted);
        font-size: 12px;
        margin-top: 4px;
    }

    .questions-actions-wrap {
        justify-content: flex-start;
    }
}
</style>

<?php include '../includes/footer.php'; ?>

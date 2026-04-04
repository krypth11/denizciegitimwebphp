<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'word-game-questions';
$page_title = 'Kelime Oyunu';

$qualifications = $pdo->query('SELECT id, name FROM qualifications ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kelime Oyunu Soru Havuzu</h2>
            <p class="text-muted mb-0">Yeterlilik bazlı kelime oyunu soru-cevap kayıtlarını yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" id="addWordGameBtn">
                <i class="bi bi-plus-lg"></i> Yeni Soru Ekle
            </button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Yeterlilik</label>
                    <select class="form-select" id="filterQualification">
                        <option value="">Tüm yeterlilikler</option>
                        <?php foreach ($qualifications as $q): ?>
                            <option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="filterActive">
                        <option value="">Tümü</option>
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="filterSearch" placeholder="Soru / cevap ara...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-secondary w-100" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="wordGameTable">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Yeterlilik</th>
                        <th>Soru Metni</th>
                        <th>Cevap</th>
                        <th>Normalize Cevap</th>
                        <th>Karakter Sayısı</th>
                        <th>Durum</th>
                        <th>Sıra</th>
                        <th>İşlemler</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td colspan="9" class="text-muted p-3">Yükleniyor...</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="wordGameModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="wordGameModalTitle">Kelime Oyunu Sorusu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="wordGameForm">
                <input type="hidden" name="id" id="wg_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Yeterlilik *</label>
                            <select class="form-select" name="qualification_id" id="wg_qualification_id" required>
                                <option value="">Seçiniz...</option>
                                <?php foreach ($qualifications as $q): ?>
                                    <option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="order_index" id="wg_order_index" value="0">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="wg_is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="wg_is_active">Aktif mi?</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Soru Metni *</label>
                            <textarea class="form-control" name="question_text" id="wg_question_text" rows="3" required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Doğru Cevap *</label>
                            <input type="text" class="form-control" name="answer_text" id="wg_answer_text" required>
                            <small class="text-muted">Backend otomatik normalize eder ve karakter sayısını hesaplar.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Not</label>
                            <textarea class="form-control" name="notes" id="wg_notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="wordGameSaveBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/word-game-questions.php';
    const modalEl = document.getElementById('wordGameModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    const appAlert = (title, message, type = 'info') => {
        if (typeof window.showAppAlert === 'function') {
            return window.showAppAlert(title, message, type);
        }
        return Promise.resolve();
    };

    const appConfirm = (title, message, options = {}) => {
        if (typeof window.showAppConfirm === 'function') {
            return window.showAppConfirm({ title, message, ...options });
        }
        return Promise.resolve(false);
    };

    const api = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    };

    const esc = (v) => $('<div>').text(v ?? '').html();

    const formatErrors = (response) => {
        const errors = response?.data?.errors || {};
        const messages = Object.values(errors).filter(Boolean);
        if (!messages.length) return response?.message || 'İşlem başarısız.';
        return messages.join('\n');
    };

    function setFormModeCreate() {
        $('#wordGameModalTitle').text('Yeni Kelime Oyunu Sorusu');
        $('#wordGameForm')[0].reset();
        $('#wg_id').val('');
        $('#wg_order_index').val('0');
        $('#wg_is_active').prop('checked', true);
    }

    async function loadList() {
        const params = {
            qualification_id: $('#filterQualification').val() || '',
            is_active: $('#filterActive').val() || '',
            search: $('#filterSearch').val() || ''
        };

        const res = await api('list_questions', 'GET', params);
        if (!res.success) {
            $('#wordGameTable tbody').html('<tr><td colspan="9" class="text-danger p-3">Liste yüklenemedi.</td></tr>');
            return;
        }

        const rows = res.data?.questions || [];
        if (!rows.length) {
            $('#wordGameTable tbody').html('<tr><td colspan="9" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        const html = rows.map((row) => {
            const statusBadge = Number(row.is_active) === 1
                ? '<span class="badge bg-success">Aktif</span>'
                : '<span class="badge bg-secondary">Pasif</span>';
            const toggleText = Number(row.is_active) === 1 ? 'Pasif Yap' : 'Aktif Yap';
            const toggleIcon = Number(row.is_active) === 1 ? 'bi-toggle-off' : 'bi-toggle-on';
            return `
                <tr>
                    <td><small>${esc(row.id)}</small></td>
                    <td>${esc(row.qualification_name || '-')}</td>
                    <td>${esc(row.question_text || '')}</td>
                    <td>${esc(row.answer_text || '')}</td>
                    <td><span class="badge bg-light text-dark">${esc(row.answer_normalized || '')}</span></td>
                    <td>${esc(row.answer_length || 0)}</td>
                    <td>${statusBadge}</td>
                    <td>${esc(row.order_index || 0)}</td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-warning wg-edit" data-id="${esc(row.id)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-primary wg-toggle" data-id="${esc(row.id)}" data-active="${Number(row.is_active) === 1 ? '1' : '0'}" title="${toggleText}"><i class="bi ${toggleIcon}"></i></button>
                            <button class="btn btn-sm btn-danger wg-delete" data-id="${esc(row.id)}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        $('#wordGameTable tbody').html(html);
    }

    $('#addWordGameBtn').on('click', function () {
        setFormModeCreate();
        modal.show();
    });

    $('#filterQualification, #filterActive').on('change', loadList);
    $('#filterSearch').on('input', function () {
        clearTimeout(window.__wgSearchTimer);
        window.__wgSearchTimer = setTimeout(loadList, 250);
    });

    $('#clearFiltersBtn').on('click', function () {
        $('#filterQualification').val('');
        $('#filterActive').val('');
        $('#filterSearch').val('');
        loadList();
    });

    $(document).on('click', '.wg-edit', async function () {
        const id = $(this).data('id');
        const res = await api('get_question', 'GET', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt yüklenemedi.', 'error');
            return;
        }

        const q = res.data?.question;
        if (!q) {
            await appAlert('Hata', 'Kayıt bulunamadı.', 'error');
            return;
        }

        $('#wordGameModalTitle').text('Kelime Oyunu Sorusu Düzenle');
        $('#wg_id').val(q.id || '');
        $('#wg_qualification_id').val(q.qualification_id || '');
        $('#wg_question_text').val(q.question_text || '');
        $('#wg_answer_text').val(q.answer_text || '');
        $('#wg_order_index').val(q.order_index || 0);
        $('#wg_notes').val(q.notes || '');
        $('#wg_is_active').prop('checked', Number(q.is_active) === 1);
        modal.show();
    });

    let isSubmitting = false;
    $('#wordGameForm').on('submit', async function (e) {
        e.preventDefault();
        if (isSubmitting) return;

        isSubmitting = true;
        const $btn = $('#wordGameSaveBtn');
        window.appSetButtonLoading($btn, true, 'Kaydediliyor...');

        const id = $('#wg_id').val();
        const action = id ? 'update_question' : 'create_question';
        const payload = $(this).serialize();
        const res = await api(action, 'POST', payload);

        window.appSetButtonLoading($btn, false);
        isSubmitting = false;

        if (!res.success) {
            await appAlert('Hata', formatErrors(res), 'error');
            return;
        }

        modal.hide();
        await appAlert('Başarılı', res.message || 'İşlem başarılı.', 'success');
        loadList();
    });

    $(document).on('click', '.wg-delete', async function () {
        const id = $(this).data('id');
        const confirmed = await appConfirm('Silme Onayı', 'Bu kelime oyunu sorusunu silmek istediğinizden emin misiniz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!confirmed) return;

        const res = await api('delete_question', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Kayıt silindi.', 'success');
        loadList();
    });

    $(document).on('click', '.wg-toggle', async function () {
        const id = $(this).data('id');
        const current = String($(this).data('active')) === '1';
        const next = current ? 0 : 1;

        const res = await api('toggle_active', 'POST', { id, is_active: next });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Durum güncellenemedi.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Durum güncellendi.', 'success');
        loadList();
    });

    loadList();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

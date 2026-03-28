<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'topics';
$page_title = 'Konular';

$total_topics = (int)$pdo->query('SELECT COUNT(*) FROM topics')->fetchColumn();
$total_qualifications = (int)$pdo->query('SELECT COUNT(*) FROM qualifications')->fetchColumn();
$total_courses = (int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted mb-1">Toplam Konu</h6><h3 class="mb-0"><?= number_format($total_topics) ?></h3></div><div class="stat-icon" style="background:#eef3ff;color:#5f84d8;"><i class="bi bi-diagram-3"></i></div></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted mb-1">Yeterlilik Sayısı</h6><h3 class="mb-0"><?= number_format($total_qualifications) ?></h3></div><div class="stat-icon" style="background:#edf8f1;color:#5ea67a;"><i class="bi bi-award"></i></div></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h6 class="text-muted mb-1">Ders Sayısı</h6><h3 class="mb-0"><?= number_format($total_courses) ?></h3></div><div class="stat-icon" style="background:#eef6ff;color:#4b8dbf;"><i class="bi bi-book"></i></div></div></div></div></div>
    </div>

    <div class="page-header">
        <div>
            <h2>Konular</h2>
            <p class="text-muted mb-0">Konu kayıtlarını yeterlilik ve ders bazında yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg"></i> Konu Ekle</button>
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
                <div class="col-md-4">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="filterSearch" placeholder="Konu adı / içerik ara...">
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
                <table id="topicsTable" class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Konu Adı</th>
                            <th>Yeterlilik</th>
                            <th>Ders</th>
                            <th>İçerik Özeti</th>
                            <th>Sıra</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" class="text-muted p-3">Yükleniyor...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Konu Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Yeterlilik *</label>
                        <select class="form-select" name="qualification_id" id="add_qualification_id" required>
                            <option value="">Seçiniz...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ders *</label>
                        <select class="form-select" name="course_id" id="add_course_id" required disabled>
                            <option value="">Önce yeterlilik seçin...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konu Adı *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">İçerik <small class="text-muted">(opsiyonel)</small></label>
                        <textarea class="form-control" name="content" rows="3"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Sıra <small class="text-muted">(opsiyonel)</small></label>
                        <input type="number" class="form-control" name="order_index" value="0">
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

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Konu Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Yeterlilik *</label>
                        <select class="form-select" name="qualification_id" id="edit_qualification_id" required>
                            <option value="">Seçiniz...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ders *</label>
                        <select class="form-select" name="course_id" id="edit_course_id" required disabled>
                            <option value="">Önce yeterlilik seçin...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Konu Adı *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">İçerik <small class="text-muted">(opsiyonel)</small></label>
                        <textarea class="form-control" name="content" id="edit_content" rows="3"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Sıra <small class="text-muted">(opsiyonel)</small></label>
                        <input type="number" class="form-control" name="order_index" id="edit_order_index" value="0">
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

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function() {
    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => {
        if (typeof window.showAppAlert === 'function') return window.showAppAlert(title, message, type);
    };
    const appConfirm = (title, message, options = {}) => {
        if (typeof window.showAppConfirm === 'function') return window.showAppConfirm({ title, message, ...options });
        return Promise.resolve(false);
    };

    const state = {
        qualifications: [],
        courses: [],
        filters: { qualification_id: '', course_id: '', search: '' }
    };

    async function api(action, method = 'GET', data = {}) {
        return await window.appAjax({
            url: '../ajax/topics.php?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    }

    async function loadQualifications() {
        const res = await api('list_qualifications');
        if (!res.success) return;
        state.qualifications = res.data?.qualifications || [];

        const opts = ['<option value="">Tüm yeterlilikler</option>']
            .concat(state.qualifications.map(q => `<option value="${esc(q.id)}">${esc(q.name)}</option>`));
        $('#filterQualification').html(opts.join(''));

        const formOpts = ['<option value="">Seçiniz...</option>']
            .concat(state.qualifications.map(q => `<option value="${esc(q.id)}">${esc(q.name)}</option>`));
        $('#add_qualification_id, #edit_qualification_id').html(formOpts.join(''));
    }

    async function loadCourses(qualificationId = '', target = '#filterCourse', allLabel = 'Tüm dersler') {
        const params = qualificationId ? { qualification_id: qualificationId } : {};
        const res = await api('list_courses', 'GET', params);
        if (!res.success) return [];

        const courses = res.data?.courses || [];
        if (target === '#filterCourse') {
            state.courses = courses;
        }

        const $target = $(target);
        $target.html(`<option value="">${allLabel}</option>`);
        courses.forEach(c => {
            $target.append(`<option value="${esc(c.id)}">${esc(c.name)}</option>`);
        });
        $target.prop('disabled', courses.length === 0);
        return courses;
    }

    function renderRows(rows) {
        const $tbody = $('#topicsTable tbody');
        if (!rows.length) {
            $tbody.html('<tr><td colspan="6" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            return;
        }

        $tbody.html(rows.map((r) => {
            const content = (r.content || '').trim();
            const summary = content.length > 100 ? content.slice(0, 100) + '…' : content;
            return `<tr>
                <td><strong>${esc(r.name)}</strong></td>
                <td>${esc(r.qualification_name || '-')}</td>
                <td>${esc(r.course_name || '-')}</td>
                <td class="text-muted">${esc(summary || '-')}</td>
                <td>${Number(r.order_index || 0)}</td>
                <td>
                    <div class="table-actions">
                        <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(r.id)}"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join(''));
    }

    async function loadTopics() {
        const res = await api('list_topics', 'GET', state.filters);
        if (!res.success) {
            renderRows([]);
            return;
        }
        renderRows(res.data?.topics || []);
    }

    $('#filterQualification').on('change', async function() {
        state.filters.qualification_id = $(this).val() || '';
        state.filters.course_id = '';
        await loadCourses(state.filters.qualification_id, '#filterCourse', 'Tüm dersler');
        $('#filterCourse').val('');
        await loadTopics();
    });

    $('#filterCourse').on('change', async function() {
        state.filters.course_id = $(this).val() || '';
        await loadTopics();
    });

    $('#filterSearch').on('input', function() {
        state.filters.search = ($(this).val() || '').trim();
        loadTopics();
    });

    $('#clearFiltersBtn').on('click', async function(e) {
        e.preventDefault();
        state.filters = { qualification_id: '', course_id: '', search: '' };
        $('#filterQualification').val('');
        $('#filterCourse').val('');
        $('#filterSearch').val('');
        await loadCourses('', '#filterCourse', 'Tüm dersler');
        await loadTopics();
    });

    $('#add_qualification_id').on('change', async function() {
        const qualificationId = $(this).val() || '';
        await loadCourses(qualificationId, '#add_course_id', 'Ders seçin...');
    });

    $('#edit_qualification_id').on('change', async function() {
        const qualificationId = $(this).val() || '';
        await loadCourses(qualificationId, '#edit_course_id', 'Ders seçin...');
    });

    $('#addForm').on('submit', async function(e) {
        e.preventDefault();
        const res = await api('add', 'POST', $(this).serialize());
        if (!res.success) return appAlert('Hata', res.message || 'Konu eklenemedi.', 'error');
        await appAlert('Başarılı', res.message || 'Konu eklendi.', 'success');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addModal')).hide();
        this.reset();
        $('#add_course_id').html('<option value="">Önce yeterlilik seçin...</option>').prop('disabled', true);
        await loadTopics();
    });

    $(document).on('click', '.edit-btn', async function() {
        const id = $(this).data('id');
        const res = await api('get', 'GET', { id });
        if (!res.success) return appAlert('Hata', res.message || 'Kayıt bulunamadı.', 'error');

        const row = res.data?.topic || {};
        $('#edit_id').val(row.id || '');
        $('#edit_qualification_id').val(row.qualification_id || '');
        await loadCourses(row.qualification_id || '', '#edit_course_id', 'Ders seçin...');
        $('#edit_course_id').val(row.course_id || '');
        $('#edit_name').val(row.name || '');
        $('#edit_content').val(row.content || '');
        $('#edit_order_index').val(Number(row.order_index || 0));

        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
    });

    $('#editForm').on('submit', async function(e) {
        e.preventDefault();
        const res = await api('update', 'POST', $(this).serialize());
        if (!res.success) return appAlert('Hata', res.message || 'Konu güncellenemedi.', 'error');
        await appAlert('Başarılı', res.message || 'Konu güncellendi.', 'success');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).hide();
        await loadTopics();
    });

    $(document).on('click', '.delete-btn', async function() {
        const id = $(this).data('id');
        const ok = await appConfirm('Silme Onayı', 'Bu konuyu silmek istediğinizden emin misiniz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete', 'POST', { id });
        if (!res.success) return appAlert('Hata', res.message || 'Silme işlemi başarısız.', 'error');
        await appAlert('Başarılı', res.message || 'Konu silindi.', 'success');
        await loadTopics();
    });

    (async function init() {
        await loadQualifications();
        await loadCourses('', '#filterCourse', 'Tüm dersler');
        await loadTopics();
    })();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

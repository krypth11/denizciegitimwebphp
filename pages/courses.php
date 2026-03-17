<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'courses';
$page_title = 'Dersler';

// Dersleri çek (qualification bilgisiyle birlikte)
$courses = $pdo->query(
    "SELECT c.*, q.name as qualification_name
     FROM courses c
     LEFT JOIN qualifications q ON c.qualification_id = q.id
     ORDER BY q.order_index, c.order_index, c.name"
)->fetchAll();

// Yeterlilikleri çek (dropdown için)
$qualifications = $pdo->query("SELECT * FROM qualifications ORDER BY order_index, name")->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Dersler</h2>
            <p class="text-muted mb-0">Dersleri yeterliliklere göre düzenleyin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i> Yeni Ders Ekle
            </button>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
                <table id="coursesTable" class="table table-hover align-middle mobile-card-table">
                    <thead>
                        <tr>
                            <th class="mobile-hide">Sıra</th>
                            <th>Ders Adı</th>
                            <th class="mobile-hide">Yeterlilik</th>
                            <th class="mobile-hide">Açıklama</th>
                            <th class="mobile-hide">Oluşturulma</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c): ?>
                        <tr class="mobile-card-row">
                            <td class="mobile-hide"><?= (int)$c['order_index'] ?></td>
                            <td class="course-main-cell">
                                <div class="mobile-card-head">
                                    <strong class="mobile-card-title"><?= htmlspecialchars($c['name']) ?></strong>
                                    <span class="badge bg-secondary">#<?= (int)$c['order_index'] ?></span>
                                </div>
                                <div class="mobile-card-meta d-none">
                                    <span><?= htmlspecialchars($c['qualification_name'] ?? 'N/A') ?></span>
                                    <span>•</span>
                                    <span><?= htmlspecialchars($c['description'] ?? '-') ?></span>
                                </div>
                            </td>
                            <td class="mobile-hide">
                                <span class="badge bg-primary"><?= htmlspecialchars($c['qualification_name'] ?? 'N/A') ?></span>
                            </td>
                            <td class="mobile-hide"><?= htmlspecialchars($c['description'] ?? '-') ?></td>
                            <td class="mobile-hide"><?= format_date($c['created_at']) ?></td>
                            <td class="course-actions-cell">
                                <div class="table-actions mobile-list-actions">
                                    <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($c['id']) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($c['id']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-md-none courses-mobile-tools mb-3">
        <div class="row g-2">
            <div class="col-5">
                <select id="mobileCoursePageSize" class="form-select form-select-sm">
                    <option value="10">10 kayıt</option>
                    <option value="25" selected>25 kayıt</option>
                    <option value="50">50 kayıt</option>
                    <option value="all">Tümü</option>
                </select>
            </div>
            <div class="col-7">
                <input type="search" id="mobileCourseSearch" class="form-control form-control-sm" placeholder="Ders veya yeterlilik ara...">
            </div>
        </div>
    </div>

    <div id="coursesMobileList" class="d-md-none">
        <?php foreach ($courses as $c): ?>
            <?php
                $searchText = mb_strtolower(trim(($c['name'] ?? '') . ' ' . ($c['qualification_name'] ?? '') . ' ' . ($c['description'] ?? '')), 'UTF-8');
            ?>
            <div class="card course-mobile-card mb-3" data-search="<?= htmlspecialchars($searchText) ?>">
                <div class="card-body">
                    <div class="course-mobile-head">
                        <h6 class="course-mobile-title mb-0"><?= htmlspecialchars($c['name']) ?></h6>
                        <span class="badge bg-secondary">#<?= (int)$c['order_index'] ?></span>
                    </div>
                    <div class="course-mobile-meta mt-2">
                        <div><strong>Yeterlilik:</strong> <?= htmlspecialchars($c['qualification_name'] ?? 'N/A') ?></div>
                        <?php if (!empty($c['description'])): ?>
                            <div><strong>Açıklama:</strong> <?= htmlspecialchars($c['description']) ?></div>
                        <?php endif; ?>
                        <div><strong>Oluşturulma:</strong> <?= format_date($c['created_at']) ?></div>
                    </div>
                    <div class="course-mobile-actions mt-3">
                        <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($c['id']) ?>">
                            <i class="bi bi-pencil"></i> Düzenle
                        </button>
                        <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($c['id']) ?>">
                            <i class="bi bi-trash"></i> Sil
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Ders Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Yeterlilik *</label>
                        <select class="form-select" name="qualification_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($qualifications as $q): ?>
                                <option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ders Adı *</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
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

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ders Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Yeterlilik *</label>
                        <select class="form-select" name="qualification_id" id="edit_qualification_id" required>
                            <option value="">Seçiniz...</option>
                            <?php foreach ($qualifications as $q): ?>
                                <option value="<?= htmlspecialchars($q['id']) ?>"><?= htmlspecialchars($q['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ders Adı *</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Açıklama</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sıra</label>
                        <input type="number" class="form-control" name="order_index" id="edit_order_index">
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
    const appAlert = (title, message, type = 'info') => {
        if (typeof window.showAppAlert === 'function') {
            window.showAppAlert(title, message, type);
        }
    };

    const appConfirm = (title, message, options = {}) => {
        if (typeof window.showAppConfirm === 'function') {
            return window.showAppConfirm({ title, message, ...options });
        }
        return Promise.resolve(false);
    };

    // DataTable (sadece desktop)
    if (window.matchMedia('(min-width: 768px)').matches) {
        $('#coursesTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            order: [[0, 'asc']],
            pageLength: 25
        });
    }

    // Mobil kart filtre + sayfa boyutu
    function applyMobileCourseFilter() {
        if (window.matchMedia('(min-width: 768px)').matches) return;

        const query = ($('#mobileCourseSearch').val() || '').toLowerCase().trim();
        const pageSize = $('#mobileCoursePageSize').val();
        let shown = 0;

        $('#coursesMobileList .course-mobile-card').each(function() {
            const text = ($(this).data('search') || '').toString().toLowerCase();
            const matches = !query || text.includes(query);

            if (!matches) {
                $(this).hide();
                return;
            }

            if (pageSize !== 'all' && shown >= parseInt(pageSize, 10)) {
                $(this).hide();
                return;
            }

            $(this).show();
            shown++;
        });
    }

    $('#mobileCourseSearch').on('input', applyMobileCourseFilter);
    $('#mobileCoursePageSize').on('change', applyMobileCourseFilter);
    applyMobileCourseFilter();

    // Add Form
    $('#addForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: '../ajax/courses.php?action=add',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    appAlert('Başarılı', response.message || 'Ders eklendi!', 'success');
                    setTimeout(() => location.reload(), 350);
                } else {
                    appAlert('Hata', response.message || 'İşlem başarısız.', 'error');
                }
            },
            error: function(xhr) {
                console.error('Error:', xhr.responseText);
                appAlert('Hata', 'Hata oluştu!', 'error');
            }
        });
    });

    // Edit Button
    $('.edit-btn').on('click', function() {
        const id = $(this).data('id');

        $.ajax({
            url: '../ajax/courses.php?action=get&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit_id').val(response.data.id);
                    $('#edit_name').val(response.data.name);
                    $('#edit_description').val(response.data.description || '');
                    $('#edit_order_index').val(response.data.order_index || 0);
                    $('#edit_qualification_id').val(response.data.qualification_id);

                    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
                    editModal.show();
                }
            }
        });
    });

    // Edit Form
    $('#editForm').on('submit', function(e) {
        e.preventDefault();

        $.ajax({
            url: '../ajax/courses.php?action=update',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    appAlert('Başarılı', response.message || 'Güncellendi!', 'success');
                    setTimeout(() => location.reload(), 350);
                } else {
                    appAlert('Hata', response.message || 'Güncelleme başarısız.', 'error');
                }
            },
            error: function(xhr) {
                console.error('Error:', xhr.responseText);
                appAlert('Hata', 'Hata oluştu!', 'error');
            }
        });
    });

    // Delete Button
    $('.delete-btn').on('click', async function() {
        const id = $(this).data('id');
        const ok = await appConfirm('Silme Onayı', 'Bu dersi silmek istediğinizden emin misiniz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        $.ajax({
            url: '../ajax/courses.php?action=delete',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    appAlert('Başarılı', response.message || 'Silindi!', 'success');
                    setTimeout(() => location.reload(), 350);
                } else {
                    appAlert('Hata', response.message || 'Silme başarısız.', 'error');
                }
            },
            error: function(xhr) {
                console.error('Error:', xhr.responseText);
                appAlert('Hata', 'Hata oluştu!', 'error');
            }
        });
    });
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

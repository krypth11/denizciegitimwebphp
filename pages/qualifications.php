<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'qualifications';
$page_title = 'Yeterlilikler';

$qualifications = $pdo->query('SELECT * FROM qualifications ORDER BY order_index, name')->fetchAll();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Yeterlilikler</h2>
            <p class="text-muted mb-0">Yeterlilik kayıtlarını görüntüleyin ve yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i> Yeni Ekle
            </button>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
                <table id="qualificationsTable" class="table table-hover align-middle mobile-card-table">
                    <thead>
                        <tr>
                            <th class="mobile-hide">Sıra</th>
                            <th>İsim</th>
                            <th class="mobile-hide">Açıklama</th>
                            <th class="mobile-hide">Oluşturulma</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qualifications as $q): ?>
                            <tr class="mobile-card-row">
                                <td class="mobile-hide"><?= (int)$q['order_index'] ?></td>
                                <td>
                                    <div class="mobile-card-head">
                                        <strong class="mobile-card-title"><?= htmlspecialchars($q['name']) ?></strong>
                                        <span class="badge bg-secondary">#<?= (int)$q['order_index'] ?></span>
                                    </div>
                                    <div class="mobile-card-meta d-none">
                                        <span><?= htmlspecialchars($q['description'] ?? '-') ?></span>
                                        <span>•</span>
                                        <span><?= format_date($q['created_at']) ?></span>
                                    </div>
                                </td>
                                <td class="mobile-hide"><?= htmlspecialchars($q['description'] ?? '-') ?></td>
                                <td class="mobile-hide"><?= format_date($q['created_at']) ?></td>
                                <td>
                                    <div class="table-actions mobile-list-actions">
                                        <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($q['id']) ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($q['id']) ?>">
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

    <div class="d-md-none qualifications-mobile-tools mb-3">
        <div class="row g-2">
            <div class="col-5">
                <select id="mobileQualificationPageSize" class="form-select form-select-sm">
                    <option value="10">10 kayıt</option>
                    <option value="25" selected>25 kayıt</option>
                    <option value="50">50 kayıt</option>
                    <option value="all">Tümü</option>
                </select>
            </div>
            <div class="col-7">
                <input type="search" id="mobileQualificationSearch" class="form-control form-control-sm" placeholder="Yeterlilik ara...">
            </div>
        </div>
    </div>

    <div id="qualificationsMobileList" class="d-md-none">
        <?php foreach ($qualifications as $q): ?>
            <?php
                $searchText = mb_strtolower(trim(($q['name'] ?? '') . ' ' . ($q['description'] ?? '')), 'UTF-8');
            ?>
            <div class="card qualification-mobile-card mb-3" data-search="<?= htmlspecialchars($searchText) ?>">
                <div class="card-body">
                    <div class="qualification-mobile-head">
                        <h6 class="qualification-mobile-title mb-0"><?= htmlspecialchars($q['name']) ?></h6>
                        <span class="badge bg-secondary">#<?= (int)$q['order_index'] ?></span>
                    </div>

                    <div class="qualification-mobile-meta mt-2">
                        <?php if (!empty($q['description'])): ?>
                            <div><strong>Açıklama:</strong> <?= htmlspecialchars($q['description']) ?></div>
                        <?php endif; ?>
                        <div><strong>Oluşturulma:</strong> <?= format_date($q['created_at']) ?></div>
                    </div>

                    <div class="qualification-mobile-actions mt-3">
                        <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($q['id']) ?>">
                            <i class="bi bi-pencil"></i> Düzenle
                        </button>
                        <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($q['id']) ?>">
                            <i class="bi bi-trash"></i> Sil
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeni Yeterlilik Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">İsim *</label>
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

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Yeterlilik Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">İsim *</label>
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
// jQuery hazır mı kontrol et
$(document).ready(function() {
    console.log('Qualifications page - jQuery ready!');

    // DataTable başlat (sadece desktop)
    if (window.matchMedia('(min-width: 768px)').matches) {
        $('#qualificationsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            order: [[0, 'asc']],
            pageLength: 25,
            responsive: true
        });
        console.log('DataTable initialized');
    }

    // Mobil kart filtre + sayfa boyutu
    function applyMobileQualificationFilter() {
        if (window.matchMedia('(min-width: 768px)').matches) return;

        const query = ($('#mobileQualificationSearch').val() || '').toLowerCase().trim();
        const pageSize = $('#mobileQualificationPageSize').val();
        let shown = 0;

        $('#qualificationsMobileList .qualification-mobile-card').each(function() {
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

    $('#mobileQualificationSearch').on('input', applyMobileQualificationFilter);
    $('#mobileQualificationPageSize').on('change', applyMobileQualificationFilter);
    applyMobileQualificationFilter();

    // Yeni Ekle Form
    $('#addForm').on('submit', function(e) {
        e.preventDefault();
        console.log('Add form submit triggered');

        const formData = $(this).serialize();
        console.log('Form data:', formData);

        $.ajax({
            url: '../ajax/qualifications.php?action=add',
            method: 'POST',
            data: formData,
            dataType: 'json',
            beforeSend: function() {
                console.log('Sending AJAX request...');
            },
            success: function(response) {
                console.log('AJAX Success:', response);

                if (response.success) {
                    alert(response.message || 'Başarıyla eklendi!');
                    location.reload();
                } else {
                    alert('Hata: ' + (response.message || 'Bilinmeyen hata'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                alert('AJAX Hatası!\nStatus: ' + status + '\nHata: ' + error + '\n\nDetay için Console\'a bakın (F12)');
            }
        });
    });

    // Düzenle Butonu
    $('.edit-btn').on('click', function() {
        const id = $(this).data('id');
        console.log('Edit button clicked, ID:', id);

        $.ajax({
            url: '../ajax/qualifications.php?action=get&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                console.log('Get response:', response);

                if (response.success) {
                    $('#edit_id').val(response.data.id);
                    $('#edit_name').val(response.data.name);
                    $('#edit_description').val(response.data.description || '');
                    $('#edit_order_index').val(response.data.order_index || 0);

                    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
                    editModal.show();
                } else {
                    alert('Hata: ' + (response.message || 'Veri yüklenemedi'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Get AJAX Error:', {xhr, status, error});
                alert('Veri yüklenemedi: ' + error);
            }
        });
    });

    // Düzenle Form
    $('#editForm').on('submit', function(e) {
        e.preventDefault();
        console.log('Edit form submit triggered');

        const formData = $(this).serialize();
        console.log('Edit form data:', formData);

        $.ajax({
            url: '../ajax/qualifications.php?action=update',
            method: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                console.log('Update response:', response);

                if (response.success) {
                    alert(response.message || 'Başarıyla güncellendi!');
                    location.reload();
                } else {
                    alert('Hata: ' + (response.message || 'Güncelleme başarısız'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Update AJAX Error:', {xhr, status, error});
                alert('Güncelleme hatası: ' + error);
            }
        });
    });

    // Sil Butonu
    $('.delete-btn').on('click', function() {
        if (!confirm('Bu kaydı silmek istediğinizden emin misiniz?')) {
            return;
        }

        const id = $(this).data('id');
        console.log('Delete button clicked, ID:', id);

        $.ajax({
            url: '../ajax/qualifications.php?action=delete',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                console.log('Delete response:', response);

                if (response.success) {
                    alert(response.message || 'Başarıyla silindi!');
                    location.reload();
                } else {
                    alert('Hata: ' + (response.message || 'Silme başarısız'));
                }
            },
            error: function(xhr, status, error) {
                console.error('Delete AJAX Error:', {xhr, status, error});
                alert('Silme hatası: ' + error);
            }
        });
    });

    console.log('All event handlers attached successfully');
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

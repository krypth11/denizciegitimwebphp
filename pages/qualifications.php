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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Yeterlilikler</h2>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-lg"></i> Yeni Ekle
        </button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <table id="qualificationsTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>Sıra</th>
                        <th>İsim</th>
                        <th>Açıklama</th>
                        <th>Oluşturulma</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qualifications as $q): ?>
                        <tr>
                            <td><?= (int)$q['order_index'] ?></td>
                            <td><?= htmlspecialchars($q['name']) ?></td>
                            <td><?= htmlspecialchars($q['description'] ?? '-') ?></td>
                            <td><?= format_date($q['created_at']) ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning edit-btn" data-id="<?= htmlspecialchars($q['id']) ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-danger delete-btn" data-id="<?= htmlspecialchars($q['id']) ?>">
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

<script>
$(function () {
    $('#qualificationsTable').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json' },
        order: [[0, 'asc']]
    });

    $('#addForm').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/ajax/qualifications.php?action=add',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.success) location.reload();
                else alert(response.message);
            }
        });
    });

    $('.edit-btn').on('click', function () {
        const id = $(this).data('id');
        $.ajax({
            url: '/ajax/qualifications.php?action=get&id=' + encodeURIComponent(id),
            method: 'GET',
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    $('#edit_id').val(response.data.id);
                    $('#edit_name').val(response.data.name);
                    $('#edit_description').val(response.data.description);
                    $('#edit_order_index').val(response.data.order_index);
                    new bootstrap.Modal(document.getElementById('editModal')).show();
                } else {
                    alert(response.message || 'Kayıt alınamadı');
                }
            }
        });
    });

    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            url: '/ajax/qualifications.php?action=update',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.success) location.reload();
                else alert(response.message);
            }
        });
    });

    $('.delete-btn').on('click', function () {
        if (!confirm('Silmek istediğinizden emin misiniz?')) return;
        const id = $(this).data('id');
        $.ajax({
            url: '/ajax/qualifications.php?action=delete',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function (response) {
                if (response.success) location.reload();
                else alert(response.message);
            }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>

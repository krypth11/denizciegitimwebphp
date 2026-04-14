<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'kart-game-questions';
$page_title = 'Kart Oyunu - Sorular';

$extra_head = <<<'HEAD'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
HEAD;

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kart Oyunu - Sorular</h2>
            <p class="text-muted mb-0">Kart oyunu soru ve görsellerini yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" id="kgqAddBtn"><i class="bi bi-plus-lg"></i> Yeni Soru</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Başlık</label>
                    <select class="form-select" id="kgqFilterCategory">
                        <option value="">Tümü</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="kgqFilterActive">
                        <option value="">Tümü</option>
                        <option value="1">Aktif</option>
                        <option value="0">Pasif</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="kgqFilterSearch" placeholder="Soru / cevap ara...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button class="btn btn-secondary w-100" id="kgqFilterClear"><i class="bi bi-x-circle"></i> Temizle</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle" id="kgqTable">
                    <thead>
                    <tr>
                        <th>Görsel</th>
                        <th>Soru Metni</th>
                        <th>Doğru Cevap</th>
                        <th>Başlık</th>
                        <th>Durum</th>
                        <th>Sıra</th>
                        <th>Oluşturulma</th>
                        <th>İşlemler</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr><td colspan="8" class="text-muted p-3">Yükleniyor...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="kgqMobileList" class="d-md-none"></div>

            <div class="d-flex justify-content-between align-items-center mt-3" id="kgqPaginationWrap">
                <button class="btn btn-sm btn-outline-secondary" id="kgqPrevBtn"><i class="bi bi-chevron-left"></i> Önceki</button>
                <div class="small text-muted" id="kgqPageInfo">Sayfa 1 / 1</div>
                <button class="btn btn-sm btn-outline-secondary" id="kgqNextBtn">Sonraki <i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="kgqModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kgqModalTitle">Kart Oyunu Sorusu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kgqForm" enctype="multipart/form-data">
                <input type="hidden" name="id" id="kgq_id">
                <input type="hidden" name="cropped_ready" id="kgq_cropped_ready" value="0">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Başlık *</label>
                            <select class="form-select" name="category_id" id="kgq_category_id" required>
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sıra</label>
                            <input type="number" class="form-control" name="sort_order" id="kgq_sort_order" value="0">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="kgq_is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="kgq_is_active">Aktif</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Soru Metni *</label>
                            <textarea class="form-control" name="question_text" id="kgq_question_text" rows="3" required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Doğru Cevap *</label>
                            <input type="text" class="form-control" name="correct_answer" id="kgq_correct_answer" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Görsel * <small class="text-muted">(4:5 zorunlu crop)</small></label>
                            <input type="file" class="form-control" id="kgq_image_input" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">Görsel seçildikten sonra crop modalı açılır.</div>
                        </div>
                        <div class="col-12">
                            <div class="kgq-image-preview-wrap">
                                <img src="" alt="Önizleme" id="kgqImagePreview" class="kgq-image-preview d-none">
                                <div class="small text-muted mt-2" id="kgqImagePreviewHint">Henüz görsel seçilmedi.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="kgqSaveBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="kgqCropModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Görseli Kırp (4:5)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="kgq-cropper-stage">
                    <img id="kgqCropImage" src="" alt="Crop">
                </div>
                <div class="small text-muted mt-2">Fare ile alanı sürükleyin, tekerlek/pinch ile zoom yapın.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="kgqCropUseBtn">Kırp ve Kullan</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
window.KGQ_CONFIG = {
    endpoint: '../ajax/kart-game-questions.php',
    cropAspectRatio: 4 / 5,
    minCropWidth: 320,
    minCropHeight: 400
};
</script>
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script src="../assets/js/kart-game-questions.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/kart-game-questions.js') ?>"></script>
JS;
?>

<?php include '../includes/footer.php'; ?>

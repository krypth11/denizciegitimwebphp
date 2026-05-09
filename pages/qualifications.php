<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'qualifications';
$page_title = 'Yeterlilikler';

$qualifications = $pdo->query(
    'SELECT q.*, COALESCE(COUNT(DISTINCT qq.id), 0) AS total_question_count
     FROM qualifications q
     LEFT JOIN courses c ON c.qualification_id = q.id
     LEFT JOIN questions qq ON qq.course_id = c.id
     GROUP BY q.id
     ORDER BY q.order_index, q.name'
)->fetchAll();

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
                            <th style="width:40px;"></th>
                            <th class="mobile-hide">Sıra</th>
                            <th>İsim</th>
                            <th class="mobile-hide">Soru Sayısı</th>
                            <th class="mobile-hide">Açıklama</th>
                            <th class="mobile-hide">Durum</th>
                            <th class="mobile-hide">Oluşturulma</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($qualifications as $q): ?>
                            <tr class="mobile-card-row qualification-row" data-qualification-id="<?= htmlspecialchars($q['id']) ?>">
                                <td>
                                    <button type="button" class="btn btn-sm btn-light qualification-expand-btn" data-id="<?= htmlspecialchars($q['id']) ?>" aria-expanded="false" title="Detayları aç/kapat">
                                        <i class="bi bi-chevron-right"></i>
                                    </button>
                                </td>
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
                                <td class="mobile-hide">
                                    <?php $qCount = (int)($q['total_question_count'] ?? 0); ?>
                                    <span class="badge <?= $qCount > 0 ? 'bg-info-subtle text-info-emphasis' : 'bg-secondary' ?>"><?= $qCount ?></span>
                                </td>
                                <td class="mobile-hide"><?= htmlspecialchars($q['description'] ?? '-') ?></td>
                                <td class="mobile-hide">
                                    <?php $isActive = (int)($q['is_active'] ?? 1) === 1; ?>
                                    <div class="form-check form-switch d-inline-flex align-items-center gap-2">
                                        <input class="form-check-input active-toggle" type="checkbox" role="switch" data-id="<?= htmlspecialchars($q['id']) ?>" <?= $isActive ? 'checked' : '' ?>>
                                        <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> status-badge"><?= $isActive ? 'Aktif' : 'Pasif' ?></span>
                                    </div>
                                </td>
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
                        <div>
                            <strong>Soru Sayısı:</strong>
                            <?php $qCount = (int)($q['total_question_count'] ?? 0); ?>
                            <span class="badge <?= $qCount > 0 ? 'bg-info-subtle text-info-emphasis' : 'bg-secondary' ?>"><?= $qCount ?></span>
                        </div>
                        <div>
                            <strong>Durum:</strong>
                            <?php $isActive = (int)($q['is_active'] ?? 1) === 1; ?>
                            <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> mobile-status-badge"><?= $isActive ? 'Aktif' : 'Pasif' ?></span>
                        </div>
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
                    <div class="mb-3">
                        <label class="form-label">Durum</label>
                        <select class="form-select" name="is_active">
                            <option value="1" selected>Aktif</option>
                            <option value="0">Pasif</option>
                        </select>
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
                    <div class="mb-3">
                        <label class="form-label">Durum</label>
                        <select class="form-select" name="is_active" id="edit_is_active">
                            <option value="1">Aktif</option>
                            <option value="0">Pasif</option>
                        </select>
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
<style>
.qualification-detail-row > td {
    background: #fff;
}

.qualification-breakdown-wrapper {
    width: 100%;
    max-width: 100%;
    overflow-x: visible;
}

.course-breakdown-card {
    display: block;
    width: 100%;
    min-width: 0;
    overflow: visible;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: .5rem;
    background: var(--bs-light-bg-subtle, #f8f9fa);
    margin-bottom: .5rem;
}

.course-breakdown-toggle {
    width: 100%;
    border: 0;
    background: transparent;
    padding: .625rem .75rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    text-align: left;
}

.course-breakdown-toggle-main {
    display: inline-flex;
    align-items: center;
    gap: .375rem;
    min-width: 0;
    flex: 1 1 auto;
}

.course-title {
    min-width: 0;
    overflow-wrap: anywhere;
}

.topic-breakdown-list {
    display: none;
    width: 100%;
    margin-left: .75rem;
    margin-right: .75rem;
    margin-bottom: .75rem;
}

.course-breakdown-card.open .topic-breakdown-list {
    display: block;
}

.topic-breakdown-row {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    padding: .5rem .625rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: .375rem;
    background: #fff;
}

@media (max-width: 768px) {
    .qualification-breakdown-wrapper {
        padding: 10px 8px;
        overflow: visible;
    }

    .course-breakdown-card {
        width: 100%;
        overflow: visible;
    }

    .topic-breakdown-list {
        display: none;
        width: 100%;
        margin-top: 8px;
        margin-left: 0;
        margin-right: 0;
        padding-left: 8px;
        overflow: visible;
    }

    .topic-breakdown-row {
        width: 100%;
        flex-wrap: wrap;
    }
}
</style>
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

    const api = async (action, method = 'GET', data = {}) => {
        if (typeof window.appAjax === 'function') {
            return await window.appAjax({
                url: '../ajax/qualifications.php?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        }
        try {
            return await $.ajax({
                url: '../ajax/qualifications.php?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        } catch (_) {
            return { success: false, message: 'İşlem sırasında bir hata oluştu.' };
        }
    };

    const toggleBtn = ($btn, loading, text = 'İşleniyor...') => {
        if (typeof window.appSetButtonLoading === 'function') {
            window.appSetButtonLoading($btn, loading, text);
            return;
        }
        $btn.prop('disabled', !!loading);
    };

    if (window.matchMedia('(min-width: 768px)').matches) {
        $('#qualificationsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/tr.json'
            },
            order: [[0, 'asc']],
            pageLength: 25,
            responsive: true
        });
    }

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

    let addSubmitting = false;
    $('#addForm').on('submit', async function(e) {
        e.preventDefault();
        if (addSubmitting) return;

        addSubmitting = true;
        const $submit = $('#addForm button[type="submit"]');
        toggleBtn($submit, true, 'Kaydediliyor...');

        const response = await api('add', 'POST', $(this).serialize());
        toggleBtn($submit, false);
        addSubmitting = false;

        if (response.success) {
            await appAlert('Başarılı', response.message || 'Başarıyla eklendi!', 'success');
            setTimeout(() => location.reload(), 250);
            return;
        }
        await appAlert('Hata', response.message || 'Kayıt işlemi başarısız.', 'error');
    });

    $('.edit-btn').on('click', async function() {
        const id = $(this).data('id');

        const response = await api('get', 'GET', { id });
        if (!response.success) {
            await appAlert('Hata', response.message || 'Veri yüklenemedi', 'error');
            return;
        }

        $('#edit_id').val(response.data.id);
        $('#edit_name').val(response.data.name);
        $('#edit_description').val(response.data.description || '');
        $('#edit_order_index').val(response.data.order_index || 0);
        $('#edit_is_active').val((parseInt(response.data.is_active ?? 1, 10) === 1) ? '1' : '0');

        const editModal = new bootstrap.Modal(document.getElementById('editModal'));
        editModal.show();
    });

    let editSubmitting = false;
    $('#editForm').on('submit', async function(e) {
        e.preventDefault();
        if (editSubmitting) return;

        editSubmitting = true;
        const $submit = $('#editForm button[type="submit"]');
        toggleBtn($submit, true, 'Güncelleniyor...');

        const response = await api('update', 'POST', $(this).serialize());
        toggleBtn($submit, false);
        editSubmitting = false;

        if (response.success) {
            await appAlert('Başarılı', response.message || 'Başarıyla güncellendi!', 'success');
            setTimeout(() => location.reload(), 250);
            return;
        }
        await appAlert('Hata', response.message || 'Güncelleme başarısız', 'error');
    });

    $('.delete-btn').on('click', async function() {
        const id = $(this).data('id');
        const ok = await appConfirm('Silme Onayı', 'Bu kaydı silmek istediğinizden emin misiniz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const response = await api('delete', 'POST', { id });
        if (response.success) {
            await appAlert('Başarılı', response.message || 'Başarıyla silindi!', 'success');
            setTimeout(() => location.reload(), 250);
            return;
        }
        await appAlert('Hata', response.message || 'Silme başarısız', 'error');
    });

    $('.active-toggle').on('change', async function() {
        const $toggle = $(this);
        const id = $toggle.data('id');
        const isActive = $toggle.is(':checked') ? 1 : 0;
        const previous = isActive === 1 ? 0 : 1;

        $toggle.prop('disabled', true);
        const response = await api('toggle_active', 'POST', { id, is_active: isActive });
        $toggle.prop('disabled', false);

        if (!response.success) {
            $toggle.prop('checked', previous === 1);
            await appAlert('Hata', response.message || 'Durum güncellenemedi.', 'error');
            return;
        }

        const finalActive = parseInt(response.is_active ?? isActive, 10) === 1;
        $toggle.prop('checked', finalActive);
        const $badge = $toggle.closest('td, .form-check').find('.status-badge');
        $badge.removeClass('bg-success bg-secondary').addClass(finalActive ? 'bg-success' : 'bg-secondary').text(finalActive ? 'Aktif' : 'Pasif');
        await appAlert('Başarılı', response.message || 'Durum güncellendi.', 'success');
    });

    const breakdownCache = {};

    const countBadgeClass = (count) => (count > 0 ? 'bg-info-subtle text-info-emphasis' : 'bg-secondary');

    const topicHtml = (topic) => {
        const count = parseInt(topic.question_count || 0, 10);
        return `
            <div class="topic-breakdown-row">
                <span>${$('<div>').text(topic.name || '-').html()}</span>
                <span class="badge ${countBadgeClass(count)}">${count}</span>
            </div>
        `;
    };

    const unassignedTopicHtml = (count) => `
        <div class="topic-breakdown-row">
            <span class="text-muted">Konuya bağlanmamış sorular</span>
            <span class="badge ${countBadgeClass(count)}">${count}</span>
        </div>
    `;

    const courseHtml = (course) => {
        const count = parseInt(course.question_count || 0, 10);
        const unassignedCount = parseInt(course.unassigned_question_count || 0, 10);
        const topics = Array.isArray(course.topics) ? course.topics : [];
        const shouldShowUnassigned = unassignedCount > 0;
        const topicItems = [
            ...(shouldShowUnassigned ? [unassignedTopicHtml(unassignedCount)] : []),
            ...topics.map(topicHtml)
        ].join('');

        const topicsContent = (topics.length || shouldShowUnassigned)
            ? `<div class="topic-breakdown-list">${topicItems}</div>`
            : '<div class="topic-breakdown-list text-muted small mt-2">Bu ders için konu bulunamadı.</div>';

        return `
            <div class="course-breakdown-card">
                <button type="button" class="course-breakdown-toggle" data-course-toggle aria-expanded="false">
                    <span class="course-breakdown-toggle-main">
                        <i class="bi bi-chevron-right" data-course-icon></i>
                        <span class="course-title">${$('<div>').text(course.name || '-').html()}</span>
                    </span>
                    <span class="badge ${countBadgeClass(count)}">${count}</span>
                </button>
                ${topicsContent}
            </div>
        `;
    };

    const buildBreakdownHtml = (payload) => {
        const courses = Array.isArray(payload.courses) ? payload.courses : [];
        if (!courses.length) return '<div class="text-muted">Bu yeterlilik için ders bulunamadı.</div>';
        return courses.map(courseHtml).join('');
    };

    const createDetailRowHtml = (detailId, colCount, contentHtml, tdClass = 'p-0') => {
        return `
            <tr id="${detailId}" class="qualification-detail-row">
                <td colspan="${colCount}" class="${tdClass}">
                    <div class="qualification-breakdown-wrapper">${contentHtml}</div>
                </td>
            </tr>
        `;
    };

    $(document).on('click', '.qualification-expand-btn', async function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $btn = $(this);
        const id = $btn.data('id');
        const $row = $btn.closest('tr.qualification-row');
        const isOpen = $btn.attr('aria-expanded') === 'true';
        const $icon = $btn.find('i');
        const colCount = $row.children('td').length;
        const detailId = `qualification-detail-${id}`;

        if (isOpen) {
            $('#' + detailId).remove();
            $btn.attr('aria-expanded', 'false');
            $icon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
            return;
        }

        $btn.attr('aria-expanded', 'true');
        $icon.removeClass('bi-chevron-right').addClass('bi-chevron-down');

        if (!breakdownCache[id]) {
            const loadingHtml = '<div class="text-muted px-2 py-2">Yükleniyor...</div>';
            const detailRow = $(createDetailRowHtml(detailId, colCount, loadingHtml, 'p-0'));
            $row.after(detailRow);
            const response = await api('get_breakdown', 'GET', { qualification_id: id });
            if (!response.success) {
                $('#' + detailId).remove();
                $btn.attr('aria-expanded', 'false');
                $icon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
                await appAlert('Hata', response.message || 'Detay yüklenemedi.', 'error');
                return;
            }
            breakdownCache[id] = response;
        }

        const html = buildBreakdownHtml(breakdownCache[id]);
        if (!$('#' + detailId).length) {
            const detailRow = $(createDetailRowHtml(detailId, colCount, html, 'p-0'));
            $row.after(detailRow);
        } else {
            $('#' + detailId + ' .qualification-breakdown-wrapper').html(html);
        }
    });

    document.addEventListener('click', function(e) {
        const toggle = e.target.closest('[data-course-toggle]');
        if (!toggle) return;

        e.preventDefault();
        e.stopPropagation();

        const card = toggle.closest('.course-breakdown-card');
        if (!card) return;

        const topics = card.querySelector('.topic-breakdown-list');
        if (!topics) return;

        const isOpen = card.classList.contains('open');

        card.classList.toggle('open');

        if (isOpen) {
            topics.style.display = 'none';
        } else {
            topics.style.display = 'block';
        }

        toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        const icon = toggle.querySelector('[data-course-icon]');
        if (icon) {
            icon.classList.toggle('bi-chevron-right', isOpen);
            icon.classList.toggle('bi-chevron-down', !isOpen);
        }
    });
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

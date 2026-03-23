<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'users';
$page_title = 'Kullanıcılar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kullanıcılar</h2>
            <p class="text-muted mb-0">Kullanıcıları listeleyin, düzenleyin, yetkilerini yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-secondary" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Filtreyi Temizle</button>
            <button class="btn btn-primary" id="addUserBtn"><i class="bi bi-plus-lg"></i> Yeni Kullanıcı Ekle</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Arama</label>
                    <input type="search" class="form-control" id="userSearchInput" placeholder="Ad Soyad veya Email ara...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rol</label>
                    <select class="form-select" id="roleFilter">
                        <option value="all">Tümü</option>
                        <option value="admin">Admin</option>
                        <option value="user">Kullanıcı</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select class="form-select" id="statusFilter">
                        <option value="active">Aktif</option>
                        <option value="passive">Pasif</option>
                        <option value="all">Tümü</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card d-none d-md-block">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Ad Soyad</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Durum</th>
                            <th>Oluşturulma</th>
                            <th>Son Giriş</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody"></tbody>
                </table>
                <div class="text-muted p-2 d-none" id="usersDesktopEmpty">Kullanıcı bulunamadı.</div>
            </div>
        </div>
    </div>

    <div class="d-md-none" id="usersMobileList"></div>
    <div class="alert alert-light text-muted d-none mt-2" id="usersMobileEmpty">Kullanıcı bulunamadı.</div>
</div>

<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Yeni Kullanıcı Ekle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="userForm" autocomplete="off">
                <input type="hidden" name="id" id="user_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Ad Soyad *</label>
                            <input type="text" class="form-control" name="full_name" id="full_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" id="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Şifre <span id="passwordRequiredMark">*</span></label>
                            <input type="password" class="form-control" name="password" id="password" minlength="6">
                            <small class="text-muted" id="passwordHint">En az 6 karakter.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Şifre Tekrar <span id="passwordConfirmRequiredMark">*</span></label>
                            <input type="password" class="form-control" name="password_confirm" id="password_confirm" minlength="6">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" name="is_admin" id="is_admin" value="1">
                                <label class="form-check-label" for="is_admin">Admin yetkisi ver</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="saveUserBtn">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/users.php';
    let users = [];
    let currentUserId = '';
    let mode = 'add';

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const appConfirm = (title, message, options = {}) =>
        window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);

    const esc = (text) => $('<div>').text(text ?? '').html();

    const api = async (action, method = 'GET', data = {}) => {
        if (typeof window.appAjax === 'function') {
            return await window.appAjax({
                url: endpoint + '?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        }
        try {
            return await $.ajax({
                url: endpoint + '?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        } catch (xhr) {
            return {
                success: false,
                message: xhr?.responseJSON?.message || 'Sunucu hatası oluştu.'
            };
        }
    };

    const toggleBtn = ($btn, loading, text = 'İşleniyor...') => {
        if (typeof window.appSetButtonLoading === 'function') {
            window.appSetButtonLoading($btn, loading, text);
            return;
        }
        $btn.prop('disabled', !!loading);
    };

    function roleBadge(isAdmin) {
        return isAdmin
            ? '<span class="badge text-bg-primary">Admin</span>'
            : '<span class="badge text-bg-secondary">Kullanıcı</span>';
    }

    function statusBadge(isDeleted) {
        return isDeleted
            ? '<span class="badge text-bg-danger">Pasif</span>'
            : '<span class="badge text-bg-success">Aktif</span>';
    }

    function fmtDate(value) {
        if (!value) return '-';
        if (typeof window.formatDate === 'function') return window.formatDate(value);
        return value;
    }

    function renderUsers() {
        const $tb = $('#usersTableBody');
        const $mobile = $('#usersMobileList');
        $tb.empty();
        $mobile.empty();

        const empty = !users.length;
        $('#usersDesktopEmpty').toggleClass('d-none', !empty);
        $('#usersMobileEmpty').toggleClass('d-none', !empty);

        users.forEach(u => {
            const isSelf = String(u.id) === String(currentUserId);
            const disableDelete = isSelf ? 'disabled' : '';
            const selfText = isSelf ? '<span class="badge text-bg-light border ms-1">Siz</span>' : '';

            $tb.append(`
                <tr>
                    <td><span class="fw-semibold">${esc(u.full_name || '-')}</span>${selfText}</td>
                    <td>${esc(u.email || '-')}</td>
                    <td>${roleBadge(u.is_admin === 1)}</td>
                    <td>${statusBadge(u.is_deleted === 1)}</td>
                    <td><small class="text-muted">${fmtDate(u.created_at)}</small></td>
                    <td><small class="text-muted">${fmtDate(u.last_sign_in_at)}</small></td>
                    <td>
                        <div class="table-actions users-actions-wrap">
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(u.id)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(u.id)}" ${disableDelete}><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            $mobile.append(`
                <div class="card mb-3 user-mobile-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">${esc(u.full_name || '-')} ${selfText}</div>
                                <div class="small text-muted mt-1">${esc(u.email || '-')}</div>
                            </div>
                            <div class="d-flex gap-1">
                                ${roleBadge(u.is_admin === 1)}
                                ${statusBadge(u.is_deleted === 1)}
                            </div>
                        </div>
                        <div class="small text-muted mt-2">Oluşturulma: ${fmtDate(u.created_at)}</div>
                        <div class="small text-muted">Son Giriş: ${fmtDate(u.last_sign_in_at)}</div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(u.id)}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(u.id)}" ${disableDelete}><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    async function loadUsers() {
        const params = {
            search: ($('#userSearchInput').val() || '').trim(),
            role: $('#roleFilter').val() || 'all',
            status: $('#statusFilter').val() || 'active'
        };

        const res = await api('list', 'GET', params);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kullanıcılar yüklenemedi.', 'error');
            return;
        }

        users = res.data?.users || [];
        currentUserId = res.data?.current_user_id || '';
        renderUsers();
    }

    function resetFormForAdd() {
        mode = 'add';
        $('#userModalTitle').text('Yeni Kullanıcı Ekle');
        $('#userForm')[0].reset();
        $('#user_id').val('');
        $('#password').prop('required', true);
        $('#password_confirm').prop('required', true);
        $('#passwordRequiredMark').removeClass('d-none');
        $('#passwordConfirmRequiredMark').removeClass('d-none');
        $('#passwordHint').text('En az 6 karakter.');
    }

    function setFormForEdit(userData) {
        mode = 'edit';
        $('#userModalTitle').text('Kullanıcı Düzenle');
        $('#userForm')[0].reset();
        $('#user_id').val(userData.id || '');
        $('#full_name').val(userData.full_name || '');
        $('#email').val(userData.email || '');
        $('#is_admin').prop('checked', (userData.is_admin || 0) == 1);
        $('#password').prop('required', false).val('');
        $('#password_confirm').prop('required', false).val('');
        $('#passwordRequiredMark').addClass('d-none');
        $('#passwordConfirmRequiredMark').addClass('d-none');
        $('#passwordHint').text('Boş bırakırsanız şifre değişmez.');
    }

    function validateForm() {
        const fullName = ($('#full_name').val() || '').trim();
        const email = ($('#email').val() || '').trim();
        const password = $('#password').val() || '';
        const passwordConfirm = $('#password_confirm').val() || '';

        if (!fullName) return 'Ad Soyad zorunludur.';
        if (!email || !/^\S+@\S+\.\S+$/.test(email)) return 'Geçerli bir email giriniz.';

        if (mode === 'add') {
            if (password.length < 6) return 'Şifre en az 6 karakter olmalıdır.';
            if (password !== passwordConfirm) return 'Şifre tekrarı eşleşmiyor.';
        } else {
            if (password || passwordConfirm) {
                if (password.length < 6) return 'Yeni şifre en az 6 karakter olmalıdır.';
                if (password !== passwordConfirm) return 'Şifre tekrarı eşleşmiyor.';
            }
        }

        return null;
    }

    $('#addUserBtn').on('click', function () {
        resetFormForAdd();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
    });

    $('#clearFiltersBtn').on('click', async function () {
        $('#userSearchInput').val('');
        $('#roleFilter').val('all');
        $('#statusFilter').val('active');
        await loadUsers();
    });

    let searchDebounce = null;
    $('#userSearchInput').on('input', function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(loadUsers, 260);
    });
    $('#roleFilter, #statusFilter').on('change', loadUsers);

    $(document).on('click', '.edit-btn', async function () {
        const id = $(this).data('id');
        const res = await api('get', 'GET', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kullanıcı bilgisi alınamadı.', 'error');
            return;
        }
        setFormForEdit(res.data?.user || {});
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
    });

    $(document).on('click', '.delete-btn', async function () {
        const id = $(this).data('id');
        const u = users.find(x => String(x.id) === String(id));

        if (!id) return;
        if (String(id) === String(currentUserId)) {
            await appAlert('Uyarı', 'Kendi hesabınızı silemezsiniz.', 'warning');
            return;
        }

        const ok = await appConfirm(
            'Kullanıcıyı Sil',
            `"${esc(u?.full_name || u?.email || 'Bu kullanıcı')}" kaydını silmek istediğinize emin misiniz?`,
            { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' }
        );
        if (!ok) return;

        const res = await api('delete', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme işlemi başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Kullanıcı silindi.', 'success');
        await loadUsers();
    });

    $('#userForm').on('submit', async function (e) {
        e.preventDefault();

        const $saveBtn = $('#saveUserBtn');
        if ($saveBtn.prop('disabled')) return;

        const validationError = validateForm();
        if (validationError) {
            await appAlert('Doğrulama Hatası', validationError, 'warning');
            return;
        }

        const payload = {
            id: $('#user_id').val() || '',
            full_name: ($('#full_name').val() || '').trim(),
            email: ($('#email').val() || '').trim(),
            password: $('#password').val() || '',
            password_confirm: $('#password_confirm').val() || '',
            is_admin: $('#is_admin').is(':checked') ? 1 : 0
        };

        const action = mode === 'add' ? 'add' : 'update';
        toggleBtn($saveBtn, true, mode === 'add' ? 'Kaydediliyor...' : 'Güncelleniyor...');
        const res = await api(action, 'POST', payload);
        toggleBtn($saveBtn, false);
        if (!res.success) {
            await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).hide();
        await appAlert('Başarılı', res.message || 'Kullanıcı kaydedildi.', 'success');
        await loadUsers();
    });

    loadUsers();
});
</script>

<style>
.users-actions-wrap {
    justify-content: flex-end;
}

.user-mobile-card .badge {
    white-space: nowrap;
}

@media (max-width: 767.98px) {
    .page-actions {
        width: 100%;
    }

    .page-actions .btn {
        flex: 1;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

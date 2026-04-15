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
<div class="container-fluid users-center-page">
    <div class="page-header">
        <div>
            <h2>Kullanıcı Yönetimi</h2>
            <p class="text-muted mb-0">Kullanıcıları güvenli şekilde görüntüleyin, filtreleyin ve inceleme ekranına geçin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-secondary" id="clearFiltersBtn"><i class="bi bi-x-circle"></i> Filtreyi Temizle</button>
            <button class="btn btn-primary" id="addUserBtn"><i class="bi bi-plus-lg"></i> Yeni Kullanıcı Ekle</button>
        </div>
    </div>

    <div class="row g-3 mb-3" id="summaryCardsRow">
        <div class="col-6 col-lg-2">
            <div class="card users-summary-card h-100">
                <div class="card-body">
                    <div class="users-summary-label">Toplam Kullanıcı</div>
                    <div class="users-summary-value" id="sumTotal">0</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card users-summary-card h-100">
                <div class="card-body">
                    <div class="users-summary-label">Guest</div>
                    <div class="users-summary-value" id="sumGuest">0</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card users-summary-card h-100">
                <div class="card-body">
                    <div class="users-summary-label">Kayıtlı Free</div>
                    <div class="users-summary-value" id="sumFree">0</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card users-summary-card h-100">
                <div class="card-body">
                    <div class="users-summary-label">Premium Aktif</div>
                    <div class="users-summary-value" id="sumPremium">0</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card users-summary-card h-100">
                <div class="card-body">
                    <div class="users-summary-label">Son 7 Gün Yeni</div>
                    <div class="users-summary-value" id="sumNew7">0</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3 users-filter-card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-lg-3">
                    <label class="form-label">Ad / Email / User ID</label>
                    <input type="search" class="form-control" id="userSearchInput" placeholder="Arama yapın...">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Kullanıcı Durumu</label>
                    <select class="form-select" id="userStatusFilter">
                        <option value="all">Tümü</option>
                        <option value="guest">Guest</option>
                        <option value="registered_free">Registered Free</option>
                        <option value="premium_active">Premium Active</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Email Verified</label>
                    <select class="form-select" id="emailVerifiedFilter">
                        <option value="all">Tümü</option>
                        <option value="yes">Verified</option>
                        <option value="no">Unverified</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label">Onboarding</label>
                    <select class="form-select" id="onboardingFilter">
                        <option value="all">Tümü</option>
                        <option value="yes">Tamamlandı</option>
                        <option value="no">Bekliyor</option>
                    </select>
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label">Mevcut Yeterlilik</label>
                    <select class="form-select" id="qualificationFilter">
                        <option value="">Tümü</option>
                    </select>
                </div>

                <div class="col-6 col-lg-3">
                    <label class="form-label">Kayıt Tarihi (Başlangıç)</label>
                    <input type="date" class="form-control" id="createdFromFilter">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label">Kayıt Tarihi (Bitiş)</label>
                    <input type="date" class="form-control" id="createdToFilter">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label">Son Giriş (Başlangıç)</label>
                    <input type="date" class="form-control" id="lastSignInFromFilter">
                </div>
                <div class="col-6 col-lg-3">
                    <label class="form-label">Son Giriş (Bitiş)</label>
                    <input type="date" class="form-control" id="lastSignInToFilter">
                </div>
            </div>
        </div>
    </div>

    <div class="card d-none d-md-block users-table-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Ad Soyad</th>
                            <th>Email</th>
                            <th>Durum</th>
                            <th>Mevcut Yeterlilik</th>
                            <th>Email Durumu</th>
                            <th>Oluşturulma</th>
                            <th>Son Giriş</th>
                            <th>Premium Bitiş</th>
                            <th class="text-end">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody"></tbody>
                </table>
                <div class="text-center py-4 d-none" id="usersLoadingState">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span class="text-muted">Kullanıcılar yükleniyor...</span>
                </div>
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
$(function () {
    const endpoint = '../ajax/users.php';
    let users = [];
    let qualifications = [];
    let currentUserId = '';
    let mode = 'add';

    const esc = (text) => $('<div>').text(text ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const appConfirm = (title, message, options = {}) => window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);
    const fmtDate = (value) => !value ? '-' : (typeof window.formatDate === 'function' ? window.formatDate(value) : value);
    const boolBadge = (v, yes='Evet', no='Hayır') => v ? `<span class="badge text-bg-success">${yes}</span>` : `<span class="badge text-bg-secondary">${no}</span>`;

    const api = async (action, method = 'GET', data = {}) => {
        if (typeof window.appAjax === 'function') {
            return window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
        }
        try {
            return await $.ajax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data, dataType: 'json' });
        } catch (xhr) {
            return { success: false, message: xhr?.responseJSON?.message || 'Sunucu hatası oluştu.' };
        }
    };

    const statusBadge = (u) => {
        if (u.status === 'guest') return '<span class="badge text-bg-warning">Guest</span>';
        if (u.status === 'premium_active') return '<span class="badge text-bg-success">Premium Aktif</span>';
        return '<span class="badge text-bg-primary">Registered Free</span>';
    };

    const premiumExpiryView = (u) => {
        if (!u.premium_expires_at) return '-';
        const ts = Date.parse(u.premium_expires_at);
        let warning = '';
        if (!Number.isNaN(ts)) {
            const dayDiff = Math.floor((ts - Date.now()) / (1000 * 60 * 60 * 24));
            if (dayDiff >= 0 && dayDiff <= 7) {
                warning = ' <span class="badge text-bg-warning">Yaklaşıyor</span>';
            }
        }
        return `<span>${fmtDate(u.premium_expires_at)}</span>${warning}`;
    };

    function renderSummary(summary) {
        $('#sumTotal').text(summary?.total_users || 0);
        $('#sumGuest').text(summary?.guest || 0);
        $('#sumFree').text(summary?.registered_free || 0);
        $('#sumPremium').text(summary?.premium_active || 0);
        $('#sumNew7').text(summary?.new_last_7_days || 0);
    }

    function renderQualificationOptions(items) {
        qualifications = items || [];
        const $sel = $('#qualificationFilter');
        const current = $sel.val() || '';
        $sel.empty().append('<option value="">Tümü</option>');
        qualifications.forEach(q => $sel.append(`<option value="${esc(q.id)}">${esc(q.name || '-')}</option>`));
        $sel.val(current);
    }

    function renderUsers() {
        const $tb = $('#usersTableBody');
        const $mobile = $('#usersMobileList');
        $tb.empty();
        $mobile.empty();

        const empty = users.length === 0;
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
                    <td>${statusBadge(u)}</td>
                    <td>${esc(u.current_qualification_name || '-')}</td>
                    <td>${boolBadge(u.email_verified === 1, 'Verified', 'Unverified')}</td>
                    <td><small class="text-muted">${fmtDate(u.created_at)}</small></td>
                    <td><small class="text-muted">${fmtDate(u.last_sign_in_at)}</small></td>
                    <td>${premiumExpiryView(u)}</td>
                    <td>
                        <div class="table-actions users-actions-wrap">
                            <a class="btn btn-sm btn-info" href="user-detail.php?id=${encodeURIComponent(u.id)}" title="Detay"><i class="bi bi-eye"></i></a>
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(u.id)}" title="Düzenle"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(u.id)}" ${disableDelete} title="Sil"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            $mobile.append(`
                <div class="card mb-3 user-mobile-card users-soft-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">${esc(u.full_name || '-')} ${selfText}</div>
                                <div class="small text-muted mt-1">${esc(u.email || '-')}</div>
                            </div>
                            <div>${statusBadge(u)}</div>
                        </div>
                        <div class="small text-muted mt-2">Yeterlilik: ${esc(u.current_qualification_name || '-')}</div>
                        <div class="small text-muted">Email: ${u.email_verified === 1 ? 'Verified' : 'Unverified'}</div>
                        <div class="small text-muted">Oluşturulma: ${fmtDate(u.created_at)}</div>
                        <div class="small text-muted">Son Giriş: ${fmtDate(u.last_sign_in_at)}</div>
                        <div class="small text-muted">Premium Bitiş: ${u.premium_expires_at ? fmtDate(u.premium_expires_at) : '-'}</div>
                        <div class="d-flex gap-2 mt-3 flex-wrap">
                            <a class="btn btn-sm btn-info" href="user-detail.php?id=${encodeURIComponent(u.id)}"><i class="bi bi-eye"></i> İncele</a>
                            <button class="btn btn-sm btn-warning edit-btn" data-id="${esc(u.id)}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-danger delete-btn" data-id="${esc(u.id)}" ${disableDelete}><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    function buildFilterParams() {
        return {
            search: ($('#userSearchInput').val() || '').trim(),
            user_status: $('#userStatusFilter').val() || 'all',
            email_verified: $('#emailVerifiedFilter').val() || 'all',
            onboarding: $('#onboardingFilter').val() || 'all',
            current_qualification_id: $('#qualificationFilter').val() || '',
            created_from: $('#createdFromFilter').val() || '',
            created_to: $('#createdToFilter').val() || '',
            last_sign_in_from: $('#lastSignInFromFilter').val() || '',
            last_sign_in_to: $('#lastSignInToFilter').val() || ''
        };
    }

    async function loadUsers() {
        $('#usersLoadingState').removeClass('d-none');
        const res = await api('list', 'GET', buildFilterParams());
        $('#usersLoadingState').addClass('d-none');

        if (!res.success) {
            await appAlert('Hata', res.message || 'Kullanıcılar yüklenemedi.', 'error');
            return;
        }
        users = res.data?.users || [];
        currentUserId = res.data?.current_user_id || '';
        renderSummary(res.data?.summary || {});
        renderQualificationOptions(res.data?.qualifications || []);
        renderUsers();
    }

    function resetFormForAdd() {
        mode = 'add';
        $('#userModalTitle').text('Yeni Kullanıcı Ekle');
        $('#userForm')[0].reset();
        $('#user_id').val('');
        $('#password').prop('required', true);
        $('#password_confirm').prop('required', true);
        $('#passwordRequiredMark, #passwordConfirmRequiredMark').removeClass('d-none');
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
        $('#password, #password_confirm').prop('required', false).val('');
        $('#passwordRequiredMark, #passwordConfirmRequiredMark').addClass('d-none');
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
        } else if (password || passwordConfirm) {
            if (password.length < 6) return 'Yeni şifre en az 6 karakter olmalıdır.';
            if (password !== passwordConfirm) return 'Şifre tekrarı eşleşmiyor.';
        }
        return null;
    }

    $('#addUserBtn').on('click', () => {
        resetFormForAdd();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
    });

    $('#clearFiltersBtn').on('click', async () => {
        $('#userSearchInput').val('');
        $('#userStatusFilter').val('all');
        $('#emailVerifiedFilter').val('all');
        $('#onboardingFilter').val('all');
        $('#qualificationFilter').val('');
        $('#createdFromFilter, #createdToFilter, #lastSignInFromFilter, #lastSignInToFilter').val('');
        await loadUsers();
    });

    let searchDebounce = null;
    $('#userSearchInput').on('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(loadUsers, 280);
    });
    $('#userStatusFilter, #emailVerifiedFilter, #onboardingFilter, #qualificationFilter, #createdFromFilter, #createdToFilter, #lastSignInFromFilter, #lastSignInToFilter').on('change', loadUsers);

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

        const ok = await appConfirm('Kullanıcıyı Sil', `"${esc(u?.full_name || u?.email || 'Bu kullanıcı')}" kaydını silmek istediğinize emin misiniz?`, {
            type: 'warning', confirmText: 'Sil', cancelText: 'İptal'
        });
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
        const $btn = $('#saveUserBtn');
        if (typeof window.appSetButtonLoading === 'function') window.appSetButtonLoading($btn, true, mode === 'add' ? 'Kaydediliyor...' : 'Güncelleniyor...');
        const res = await api(action, 'POST', payload);
        if (typeof window.appSetButtonLoading === 'function') window.appSetButtonLoading($btn, false);

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
.users-center-page .users-summary-card,
.users-center-page .users-filter-card,
.users-center-page .users-table-card,
.users-center-page .users-soft-card {
    border: none;
    box-shadow: var(--shadow-soft);
}

.users-summary-label {
    color: var(--text-muted);
    font-size: 12px;
}

.users-summary-value {
    font-size: 26px;
    font-weight: 700;
    color: var(--text-main);
    margin-top: 6px;
}

.users-actions-wrap {
    justify-content: flex-end;
    gap: 6px;
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

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'notifications-special';
$page_title = 'Özel Bildirimler';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Özel Bildirimler</h2>
            <p class="text-muted mb-0">Uygulama içi özel duyuru ve bildirim oluşturun, planlayın ve geçmişini görüntüleyin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" id="specialSendBtn"><i class="bi bi-send"></i> Kaydet / Gönder</button>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-5">
            <div class="card mb-3">
                <div class="card-header bg-white"><h6 class="mb-0">Özel Bildirim Formu</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Bildirim Başlığı *</label>
                            <input type="text" class="form-control" id="title" maxlength="120">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Kısa Bildirim Metni *</label>
                            <textarea class="form-control" id="message" rows="3" maxlength="500"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Detay Metni</label>
                            <textarea class="form-control" id="detail_text" rows="5" maxlength="5000"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Görsel URL</label>
                            <input type="url" class="form-control" id="image_url" placeholder="https://...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Hedef Tipi *</label>
                            <select class="form-select" id="target_type">
                                <option value="all_users" selected>Tüm kullanıcılar</option>
                                <option value="single_user">Tek kullanıcı</option>
                                <option value="premium_users">Premium kullanıcılar</option>
                                <option value="free_users">Free kullanıcılar</option>
                                <option value="qualification">Belirli yeterlilik</option>
                                <option value="last_7_days_active">Son 7 gün aktif</option>
                                <option value="last_30_days_passive">Son 30 gün pasif</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="singleUserWrap">
                            <label class="form-label">Tek Kullanıcı</label>
                            <input type="text" class="form-control mb-2" id="userSearch" placeholder="Email / ad ile ara...">
                            <select class="form-select" id="target_user_id"></select>
                        </div>
                        <div class="col-md-6 d-none" id="qualificationWrap">
                            <label class="form-label">Yeterlilik</label>
                            <select class="form-select" id="target_qualification_id"></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label d-block">Zamanlama</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="schedule_mode" id="schedule_now" value="now" checked>
                                <label class="form-check-label" for="schedule_now">Hemen gönder</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="schedule_mode" id="schedule_scheduled" value="scheduled">
                                <label class="form-check-label" for="schedule_scheduled">Planlı gönder</label>
                            </div>
                        </div>
                        <div class="col-12 d-none" id="scheduledAtWrap">
                            <label class="form-label">Planlanan Tarih/Saat</label>
                            <input type="datetime-local" class="form-control" id="scheduled_at">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-7">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Son Özel Bildirimler</h6>
                    <button class="btn btn-sm btn-outline-secondary" id="refreshSpecialList"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mobile-card-table">
                            <thead>
                                <tr>
                                    <th>Başlık</th>
                                    <th>Mesaj</th>
                                    <th>Durum</th>
                                    <th>Hedef</th>
                                    <th>Planlama</th>
                                    <th>Başarı/Hata</th>
                                    <th>Tarih</th>
                                    <th>Detay</th>
                                </tr>
                            </thead>
                            <tbody id="specialListBody"></tbody>
                        </table>
                    </div>
                    <div class="alert alert-light text-muted d-none mt-2" id="specialEmpty">Kayıt bulunamadı.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="specialDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Özel Bildirim Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre class="small mb-0" id="specialDetailJson">-</pre>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/notifications.php';
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const api = async (action, method = 'GET', data = {}) => await window.appAjax({
        url: endpoint + '?action=' + encodeURIComponent(action),
        method,
        data,
        dataType: 'json'
    });
    const esc = (txt) => $('<div>').text(txt ?? '').html();

    function toggleTargetFields() {
        const targetType = $('#target_type').val();
        $('#singleUserWrap').toggleClass('d-none', targetType !== 'single_user');
        $('#qualificationWrap').toggleClass('d-none', targetType !== 'qualification');
    }

    function toggleScheduleField() {
        const mode = $('input[name="schedule_mode"]:checked').val();
        $('#scheduledAtWrap').toggleClass('d-none', mode !== 'scheduled');
    }

    async function loadQualifications() {
        const res = await api('list_qualifications');
        const items = res.data?.items || [];
        const $sel = $('#target_qualification_id');
        $sel.empty().append('<option value="">Seçiniz...</option>');
        items.forEach(i => $sel.append('<option value="' + esc(i.id) + '">' + esc(i.name) + '</option>'));
    }

    async function searchUsers(q = '') {
        const res = await api('search_users', 'GET', { q });
        const items = res.data?.items || [];
        const $sel = $('#target_user_id');
        $sel.empty().append('<option value="">Kullanıcı seçiniz...</option>');
        items.forEach(i => {
            const label = (i.full_name || '') + (i.email ? ' (' + i.email + ')' : '');
            $sel.append('<option value="' + esc(i.id) + '">' + esc(label.trim()) + '</option>');
        });
    }

    function collectPayload() {
        return {
            title: ($('#title').val() || '').trim(),
            message: ($('#message').val() || '').trim(),
            detail_text: ($('#detail_text').val() || '').trim(),
            image_url: ($('#image_url').val() || '').trim(),
            target_type: $('#target_type').val() || 'all_users',
            target_user_id: $('#target_user_id').val() || '',
            target_qualification_id: $('#target_qualification_id').val() || '',
            schedule_mode: $('input[name="schedule_mode"]:checked').val() || 'now',
            scheduled_at: $('#scheduled_at').val() || ''
        };
    }

    function validatePayload(payload) {
        if (!payload.title) return 'Başlık zorunludur.';
        if (!payload.message) return 'Kısa bildirim metni zorunludur.';
        if (payload.target_type === 'single_user' && !payload.target_user_id) return 'Tek kullanıcı seçmelisiniz.';
        if (payload.target_type === 'qualification' && !payload.target_qualification_id) return 'Yeterlilik seçmelisiniz.';
        if (payload.schedule_mode === 'scheduled' && !payload.scheduled_at) return 'Planlı gönderim için tarih/saat zorunludur.';
        return null;
    }

    function renderSpecialList(items) {
        const $tb = $('#specialListBody').empty();
        $('#specialEmpty').toggleClass('d-none', items.length > 0);
        items.forEach(item => {
            $tb.append(`
                <tr>
                    <td>${esc(item.title || '-')}</td>
                    <td>${esc(item.message || '-')}</td>
                    <td>${esc(item.status_label || item.status || '-')}</td>
                    <td>${esc(item.target_label || '-')}</td>
                    <td>${esc(item.schedule_label || '-')}</td>
                    <td>${Number(item.success_count || 0)} / ${Number(item.failure_count || 0)}</td>
                    <td>${esc(item.created_at || item.sent_at || '-')}</td>
                    <td><button class="btn btn-sm btn-secondary js-special-detail" data-json='${esc(JSON.stringify(item, null, 2))}'><i class="bi bi-eye"></i></button></td>
                </tr>
            `);
        });
    }

    async function loadSpecialList() {
        const res = await api('list_special_notifications');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Liste alınamadı.', 'error');
            return;
        }
        renderSpecialList(res.data?.items || []);
    }

    async function submit() {
        const payload = collectPayload();
        const error = validatePayload(payload);
        if (error) {
            await appAlert('Doğrulama', error, 'warning');
            return;
        }

        const res = await api('create_special_notification', 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Özel bildirim kaydedildi.', 'success');
        await loadSpecialList();
    }

    let userTimer = null;
    $('#userSearch').on('input', function () {
        clearTimeout(userTimer);
        const q = $(this).val() || '';
        userTimer = setTimeout(() => searchUsers(q), 250);
    });

    $('#target_type').on('change', toggleTargetFields);
    $('input[name="schedule_mode"]').on('change', toggleScheduleField);
    $('#specialSendBtn').on('click', submit);
    $('#refreshSpecialList').on('click', loadSpecialList);

    $(document).on('click', '.js-special-detail', function () {
        $('#specialDetailJson').text($(this).attr('data-json') || '-');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('specialDetailModal')).show();
    });

    toggleTargetFields();
    toggleScheduleField();
    loadQualifications();
    searchUsers('');
    loadSpecialList();
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>
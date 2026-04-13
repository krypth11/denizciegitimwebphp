<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'notifications-create';
$page_title = 'Yeni Bildirim';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Yeni Bildirim</h2>
            <p class="text-muted mb-0">Firebase push kampanyası oluşturun, hedefleyin ve gönderin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-secondary" id="saveBtn"><i class="bi bi-save"></i> Taslak Olarak Kaydet</button>
            <button class="btn btn-primary" id="sendBtn"><i class="bi bi-send"></i> Gönder</button>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card mb-3">
                <div class="card-header bg-white"><h6 class="mb-0">A) İçerik</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Başlık *</label>
                            <input type="text" class="form-control" id="title" maxlength="120" placeholder="Bildirim başlığı">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kanal *</label>
                            <select class="form-select" id="channel">
                                <option value="general">general</option>
                                <option value="study">study</option>
                                <option value="exam">exam</option>
                                <option value="community">community</option>
                                <option value="premium">premium</option>
                                <option value="system">system</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mesaj *</label>
                            <textarea class="form-control" id="message" rows="4" maxlength="500" placeholder="Bildirim mesajı"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Görsel URL (opsiyonel)</label>
                            <input type="url" class="form-control" id="image_url" placeholder="https://...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Deep Link (opsiyonel)</label>
                            <input type="text" class="form-control" id="deep_link" placeholder="denizci://...">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Payload JSON (opsiyonel)</label>
                            <textarea class="form-control font-monospace" id="payload_json" rows="4" placeholder='{"screen":"exam_detail","id":"123"}'></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-white"><h6 class="mb-0">C) Hedefleme</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Hedef Tipi *</label>
                            <select class="form-select" id="target_type">
                                <option value="single_user">Tek kullanıcı</option>
                                <option value="all_users">Tüm kullanıcılar</option>
                                <option value="premium_users">Premium kullanıcılar</option>
                                <option value="free_users">Free kullanıcılar</option>
                                <option value="qualification">Belirli yeterlilik</option>
                                <option value="last_7_days_active">Son 7 gün aktif</option>
                                <option value="last_30_days_passive">Son 30 gün pasif</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="singleUserWrap">
                            <label class="form-label">Kullanıcı Ara</label>
                            <input type="text" class="form-control mb-2" id="userSearch" placeholder="Email / ad ile ara...">
                            <select class="form-select" id="target_user_id"></select>
                        </div>
                        <div class="col-md-6 d-none" id="qualificationWrap">
                            <label class="form-label">Yeterlilik</label>
                            <select class="form-select" id="target_qualification_id"></select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card">
                <div class="card-header bg-white"><h6 class="mb-0">D) Zamanlama</h6></div>
                <div class="card-body">
                    <div class="vstack gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="schedule_mode" id="schedule_now" value="now" checked>
                            <label class="form-check-label" for="schedule_now">Hemen gönder</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="schedule_mode" id="schedule_scheduled" value="scheduled">
                            <label class="form-check-label" for="schedule_scheduled">Planlı gönder</label>
                        </div>
                    </div>
                    <div class="mt-3 d-none" id="scheduledAtWrap">
                        <label class="form-label">Planlanan Tarih/Saat</label>
                        <input type="datetime-local" class="form-control" id="scheduled_at">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/notifications.php';

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert
            ? window.showAppAlert({ title, message, type })
            : Promise.resolve(window.alert(message || title || 'Bir hata oluştu.'));

    const api = async (action, method = 'GET', data = {}) => {
        if (typeof window.appAjax !== 'function') {
            console.error('[notifications-create] window.appAjax bulunamadı.');
            await appAlert('Hata', 'İstek altyapısı yüklenemedi (appAjax). Sayfayı yenileyip tekrar deneyin.', 'error');
            throw new Error('window.appAjax is not available');
        }

        return await window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    };

    const esc = (s) => $('<div>').text(s ?? '').html();

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
        try {
            const res = await api('list_qualifications');
            const items = res.data?.items || [];
            const $sel = $('#target_qualification_id');
            $sel.empty().append('<option value="">Seçiniz...</option>');
            items.forEach(i => $sel.append('<option value="' + esc(i.id) + '">' + esc(i.name) + '</option>'));
        } catch (e) {
            console.error('[notifications-create] loadQualifications', e);
        }
    }

    async function searchUsers(q = '') {
        try {
            const res = await api('search_users', 'GET', { q });
            const items = res.data?.items || [];
            const $sel = $('#target_user_id');
            $sel.empty().append('<option value="">Kullanıcı seçiniz...</option>');
            items.forEach(i => {
                const label = (i.full_name || '') + (i.email ? ' (' + i.email + ')' : '');
                $sel.append('<option value="' + esc(i.id) + '">' + esc(label.trim()) + '</option>');
            });
        } catch (e) {
            console.error('[notifications-create] searchUsers', e);
        }
    }

    function collectPayload() {
        const payload = {
            title: ($('#title').val() || '').trim(),
            message: ($('#message').val() || '').trim(),
            image_url: ($('#image_url').val() || '').trim(),
            deep_link: ($('#deep_link').val() || '').trim(),
            payload_json: ($('#payload_json').val() || '').trim(),
            channel: $('#channel').val() || 'general',
            target_type: $('#target_type').val() || 'all_users',
            target_user_id: $('#target_user_id').val() || '',
            target_qualification_id: $('#target_qualification_id').val() || '',
            schedule_mode: $('input[name="schedule_mode"]:checked').val() || 'now',
            scheduled_at: $('#scheduled_at').val() || ''
        };

        return payload;
    }

    function validatePayload(payload) {
        if (!payload.title || !payload.message) {
            return 'Başlık ve mesaj zorunludur.';
        }
        if (payload.target_type === 'single_user' && !payload.target_user_id) {
            return 'Tek kullanıcı seçmelisiniz.';
        }
        if (payload.target_type === 'qualification' && !payload.target_qualification_id) {
            return 'Yeterlilik seçmelisiniz.';
        }
        if (payload.schedule_mode === 'scheduled' && !payload.scheduled_at) {
            return 'Planlı gönderim için tarih/saat seçiniz.';
        }
        if (payload.payload_json) {
            try { JSON.parse(payload.payload_json); } catch (e) { return 'Payload JSON geçerli değil.'; }
        }
        return null;
    }

    async function submit(action) {
        const payload = collectPayload();
        if (action === 'save_draft') {
            payload.schedule_mode = 'draft';
            payload.scheduled_at = '';
        }
        const err = validatePayload(payload);
        if (err) {
            await appAlert('Doğrulama', err, 'warning');
            return;
        }

        try {
            const res = await api(action, 'POST', payload);
            if (!res.success) {
                await appAlert('Hata', res.message || 'İşlem başarısız.', 'error');
                return;
            }
            await appAlert('Başarılı', res.message || 'İşlem başarılı.', 'success');
        } catch (e) {
            console.error('[notifications-create] submit', e);
            await appAlert('Hata', 'İşlem sırasında beklenmeyen bir hata oluştu.', 'error');
        }
    }

    $('#target_type').on('change', toggleTargetFields);
    $('input[name="schedule_mode"]').on('change', toggleScheduleField);

    let userTimer = null;
    $('#userSearch').on('input', function () {
        clearTimeout(userTimer);
        const q = $(this).val() || '';
        userTimer = setTimeout(() => searchUsers(q), 280);
    });

    $('#saveBtn').on('click', async function () {
        await submit('save_draft');
    });

    $('#sendBtn').on('click', async function () {
        const mode = $('input[name="schedule_mode"]:checked').val() || 'now';
        const action = mode === 'scheduled' ? 'create_notification' : 'send_now';
        await submit(action);
    });

    toggleTargetFields();
    toggleScheduleField();
    loadQualifications();
    searchUsers('');
});
</script>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

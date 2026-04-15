<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'users';
$page_title = 'Kullanıcı Detayı';

$userId = trim((string)($_GET['id'] ?? ''));

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid user-detail-page" id="userDetailRoot" data-user-id="<?= htmlspecialchars($userId, ENT_QUOTES, 'UTF-8') ?>">
    <div class="page-header">
        <div>
            <h2>Kullanıcı İnceleme Merkezi</h2>
            <p class="text-muted mb-0">Kullanıcı profili, abonelik, çalışma ve cihaz verilerini read-only inceleyin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <a href="users.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Listeye Dön</a>
        </div>
    </div>

    <div class="card user-detail-summary-card mb-3" id="detailSummaryCard">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <h4 class="mb-1" id="sumName">-</h4>
                    <div class="text-muted" id="sumEmail">-</div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-start" id="sumBadges"></div>
            </div>
            <div class="row g-2 mt-2 small text-muted" id="sumMetaRow"></div>
        </div>
    </div>

    <ul class="nav nav-tabs user-detail-tabs" id="detailTabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-general" type="button">Genel</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-lifecycle" type="button">Yaşam Döngüsü</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-subscription" type="button">Abonelik</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-study" type="button">Çalışma</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-exams" type="button">Denemeler</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-usage" type="button">Kullanım Limitleri</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-devices" type="button">Cihazlar / Tokenlar</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-notes" type="button">Admin Notları</button></li>
    </ul>

    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="tab-general"></div>
        <div class="tab-pane fade" id="tab-lifecycle"></div>
        <div class="tab-pane fade" id="tab-subscription"></div>
        <div class="tab-pane fade" id="tab-study"></div>
        <div class="tab-pane fade" id="tab-exams"></div>
        <div class="tab-pane fade" id="tab-usage"></div>
        <div class="tab-pane fade" id="tab-devices"></div>
        <div class="tab-pane fade" id="tab-notes"></div>
    </div>
</div>

<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="noteModalTitle">Admin Notu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="noteForm">
                <input type="hidden" id="note_id" value="">
                <div class="modal-body">
                    <label class="form-label">Not</label>
                    <textarea class="form-control" id="note_text" rows="5" maxlength="3000" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary" id="saveNoteBtn">Kaydet</button>
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
    const userId = String($('#userDetailRoot').data('userId') || '');
    let detailData = null;
    let noteList = [];
    const loadedTabs = { general: false };

    const esc = (text) => $('<div>').text(text ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const appConfirm = (title, message, options = {}) => window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);
    const fmtDate = (value) => !value ? '-' : (typeof window.formatDate === 'function' ? window.formatDate(value) : value);

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

    const statusBadge = (status) => {
        if (status === 'guest') return '<span class="badge text-bg-warning">Guest</span>';
        if (status === 'premium_active') return '<span class="badge text-bg-success">Premium Aktif</span>';
        return '<span class="badge text-bg-primary">Registered Free</span>';
    };

    function setLoading(tabId, text = 'Yükleniyor...') {
        $(tabId).html(`<div class="card user-soft-card"><div class="card-body text-muted"><div class="spinner-border spinner-border-sm me-2"></div>${esc(text)}</div></div>`);
    }

    function renderTopSummary(user, kpi) {
        $('#sumName').text(user.full_name || '-');
        $('#sumEmail').text(user.email || '-');

        const badges = [];
        badges.push(statusBadge(user.status));
        badges.push(user.email_verified === 1 ? '<span class="badge text-bg-success">Email Verified</span>' : '<span class="badge text-bg-secondary">Email Unverified</span>');
        badges.push(user.onboarding_completed === 1 ? '<span class="badge text-bg-success">Onboarding Tamam</span>' : '<span class="badge text-bg-secondary">Onboarding Bekliyor</span>');
        badges.push(user.premium?.is_active ? '<span class="badge text-bg-success">Premium</span>' : '<span class="badge text-bg-secondary">Free</span>');
        $('#sumBadges').html(badges.join(' '));

        $('#sumMetaRow').html(`
            <div class="col-12 col-lg-4">Mevcut Yeterlilik: <strong>${esc(user.current_qualification_name || '-')}</strong></div>
            <div class="col-12 col-lg-4">Hedef Yeterlilik: <strong>${esc(user.target_qualification_name || '-')}</strong></div>
            <div class="col-12 col-lg-4">Premium Bitiş: <strong>${esc(fmtDate(user.premium?.expires_at || null))}</strong></div>
            <div class="col-12 col-lg-4">Kayıt: <strong>${esc(fmtDate(user.created_at))}</strong></div>
            <div class="col-12 col-lg-4">Son Giriş: <strong>${esc(fmtDate(user.last_sign_in_at))}</strong></div>
            <div class="col-12 col-lg-4">Toplam Çözülen Soru: <strong>${esc(kpi.total_solved || 0)}</strong></div>
        `);
    }

    function renderGeneral() {
        const u = detailData.user;
        const k = detailData.kpi || {};
        $('#tab-general').html(`
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam Çözülen</div><div class="h4 mb-0">${esc(k.total_solved || 0)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Doğru</div><div class="h4 mb-0">${esc(k.correct || 0)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Yanlış</div><div class="h4 mb-0">${esc(k.wrong || 0)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Başarı Oranı</div><div class="h4 mb-0">%${esc(k.success_rate || 0)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam Deneme</div><div class="h4 mb-0">${esc(k.total_exams || 0)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Tamamlanan Deneme</div><div class="h4 mb-0">${esc(k.completed_exams || 0)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Premium</div><div class="h6 mb-0">${k.premium_active ? 'Aktif' : 'Free'}</div></div></div></div>
            </div>
            <div class="card user-soft-card">
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr><th width="240">User ID</th><td>${esc(u.id)}</td></tr>
                            <tr><th>full_name</th><td>${esc(u.full_name || '-')}</td></tr>
                            <tr><th>email</th><td>${esc(u.email || '-')}</td></tr>
                            <tr><th>pending_email</th><td>${esc(u.pending_email || '-')}</td></tr>
                            <tr><th>is_guest</th><td>${u.is_guest ? '1' : '0'}</td></tr>
                            <tr><th>is_admin</th><td>${u.is_admin ? '1' : '0'}</td></tr>
                            <tr><th>email_verified</th><td>${u.email_verified ? '1' : '0'}</td></tr>
                            <tr><th>email_verified_at</th><td>${esc(fmtDate(u.email_verified_at))}</td></tr>
                            <tr><th>onboarding_completed</th><td>${u.onboarding_completed ? '1' : '0'}</td></tr>
                            <tr><th>current qualification</th><td>${esc(u.current_qualification_name || '-')} (${esc(u.current_qualification_id || '-')})</td></tr>
                            <tr><th>target qualification</th><td>${esc(u.target_qualification_name || '-')} (${esc(u.target_qualification_id || '-')})</td></tr>
                            <tr><th>created_at</th><td>${esc(fmtDate(u.created_at))}</td></tr>
                            <tr><th>updated_at</th><td>${esc(fmtDate(u.updated_at))}</td></tr>
                            <tr><th>last_sign_in_at</th><td>${esc(fmtDate(u.last_sign_in_at))}</td></tr>
                            <tr><th>is_deleted</th><td>${u.is_deleted ? '1' : '0'}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `);
    }

    function renderLifecycle(items) {
        if (!items.length) {
            $('#tab-lifecycle').html('<div class="card user-soft-card"><div class="card-body text-muted">Yaşam döngüsü kaydı bulunamadı.</div></div>');
            return;
        }

        const rows = items.map(item => `
            <div class="lifecycle-item">
                <div class="lifecycle-dot"></div>
                <div class="lifecycle-content card user-soft-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <strong>${esc(item.title || item.event_type || 'Olay')}</strong>
                            <small class="text-muted">${esc(fmtDate(item.created_at))}</small>
                        </div>
                        <div class="small text-muted mt-1">Kaynak: ${esc(item.source || '-')}</div>
                        <div class="mt-2 small">Old: <code>${esc(item.old_value ?? '-')}</code> | New: <code>${esc(item.new_value ?? '-')}</code></div>
                        ${item.meta ? `<pre class="small mt-2 mb-0 lifecycle-meta">${esc(JSON.stringify(item.meta, null, 2))}</pre>` : ''}
                    </div>
                </div>
            </div>
        `).join('');

        $('#tab-lifecycle').html(`<div class="lifecycle-wrap">${rows}</div>`);
    }

    function renderSubscription(s) {
        $('#tab-subscription').html(`
            <div class="card user-soft-card">
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr><th width="220">is_pro</th><td>${s.is_pro ? '1' : '0'}</td></tr>
                            <tr><th>premium aktif mi</th><td>${s.premium_active ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Free</span>'}</td></tr>
                            <tr><th>plan_code</th><td>${esc(s.plan_code || '-')}</td></tr>
                            <tr><th>provider</th><td>${esc(s.provider || '-')}</td></tr>
                            <tr><th>entitlement_id</th><td>${esc(s.entitlement_id || '-')}</td></tr>
                            <tr><th>rc_app_user_id</th><td>${esc(s.rc_app_user_id || '-')}</td></tr>
                            <tr><th>expires_at</th><td>${esc(fmtDate(s.expires_at))}</td></tr>
                            <tr><th>last_synced_at</th><td>${esc(fmtDate(s.last_synced_at))}</td></tr>
                            <tr><th>created_at</th><td>${esc(fmtDate(s.created_at))}</td></tr>
                            <tr><th>updated_at</th><td>${esc(fmtDate(s.updated_at))}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `);
    }

    function renderStudy(data) {
        const totals = data.totals || {};
        const dist = data.source_distribution || [];
        const recent = data.recent_events || [];
        const breakdowns = data.breakdowns || {};

        const distRows = dist.length ? dist.map(x => `<tr><td>${esc(x.source || '-')}</td><td>${esc(x.total || 0)}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">Kayıt yok</td></tr>';
        const recentRows = recent.length ? recent.map(x => `<tr><td>${esc(fmtDate(x.created_at))}</td><td>${esc(x.source || '-')}</td><td>${esc(x.question_id || '-')}</td><td>${x.is_correct == 1 ? '<span class="badge text-bg-success">Doğru</span>' : '<span class="badge text-bg-danger">Yanlış</span>'}</td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">Kayıt yok</td></tr>';

        const renderBreak = (title, rows) => {
            const body = (rows || []).length ? rows.map(r => `<tr><td>${esc(r.name || r.id || '-')}</td><td>${esc(r.total || 0)}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">Kayıt yok</td></tr>';
            return `<div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body table-responsive"><h6>${title}</h6><table class="table table-sm mb-0"><thead><tr><th>Ad</th><th>Toplam</th></tr></thead><tbody>${body}</tbody></table></div></div></div>`;
        };

        $('#tab-study').html(`
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam</div><div class="h5 mb-0">${esc(totals.total_solved || 0)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Doğru</div><div class="h5 mb-0">${esc(totals.correct || 0)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Yanlış</div><div class="h5 mb-0">${esc(totals.wrong || 0)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Başarı</div><div class="h5 mb-0">%${esc(totals.success_rate || 0)}</div></div></div></div>
                <div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Son Çalışma</div><div class="h6 mb-0">${esc(fmtDate(totals.last_study_at))}</div></div></div></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-4">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>Source Dağılımı</h6><table class="table table-sm mb-0"><thead><tr><th>Source</th><th>Toplam</th></tr></thead><tbody>${distRows}</tbody></table></div></div>
                </div>
                <div class="col-12 col-lg-8">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>Son 20 Cevap Eventi</h6><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Source</th><th>Soru</th><th>Sonuç</th></tr></thead><tbody>${recentRows}</tbody></table></div></div>
                </div>
            </div>
            <div class="row g-3">
                ${renderBreak('Qualification Kırılımı', breakdowns.qualification)}
                ${renderBreak('Course Kırılımı', breakdowns.course)}
                ${renderBreak('Topic Kırılımı', breakdowns.topic)}
            </div>
        `);
    }

    function renderExams(data) {
        const s = data.summary || {};
        const attempts = data.attempts || [];
        const rows = attempts.length ? attempts.map(a => `<tr><td>${esc(a.id || '-')}</td><td>${esc(a.status || '-')}</td><td>${esc(a.score || '-')}</td><td>${esc(a.qualification_id || '-')}</td><td>${esc(fmtDate(a.created_at))}</td><td>${esc(fmtDate(a.updated_at))}</td></tr>`).join('') : '<tr><td colspan="6" class="text-muted">Deneme kaydı yok.</td></tr>';

        $('#tab-exams').html(`
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam</div><div class="h5 mb-0">${esc(s.total || 0)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Tamamlanan</div><div class="h5 mb-0">${esc(s.completed || 0)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">In Progress</div><div class="h5 mb-0">${esc(s.in_progress || 0)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Abandoned</div><div class="h5 mb-0">${esc(s.abandoned || 0)}</div></div></div></div>
                <div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Son Deneme</div><div class="h6 mb-0">${esc(fmtDate(s.last_exam_at))}</div></div></div></div>
            </div>
            <div class="card user-soft-card"><div class="card-body table-responsive"><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Status</th><th>Skor</th><th>Qualification</th><th>Başlangıç</th><th>Güncelleme</th></tr></thead><tbody>${rows}</tbody></table></div></div>
        `);
    }

    function renderUsage(data) {
        const summary = data.summary || {};
        const rows = data.rows || [];
        const sumRows = Object.keys(summary).length ? Object.keys(summary).map(k => `<tr><td>${esc(k)}</td><td>${esc(summary[k])}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">Özet veri yok.</td></tr>';
        const listRows = rows.length ? rows.map(r => `<tr><td>${esc(r.usage_date || '-')}</td><td>${esc(r.feature_key || '-')}</td><td>${esc(r.used_count || 0)}</td><td>${esc(r.qualification_id || '-')}</td><td>${esc(fmtDate(r.updated_at || r.created_at))}</td></tr>`).join('') : '<tr><td colspan="5" class="text-muted">Kayıt yok.</td></tr>';

        $('#tab-usage').html(`
            <div class="row g-3">
                <div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body table-responsive"><h6>Günlük Hak Özetleri</h6><table class="table table-sm mb-0"><thead><tr><th>Feature</th><th>Toplam Kullanım</th></tr></thead><tbody>${sumRows}</tbody></table></div></div></div>
                <div class="col-12 col-lg-8"><div class="card user-soft-card"><div class="card-body table-responsive"><h6>Kullanım Kayıtları</h6><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Feature</th><th>Kullanım</th><th>Qualification</th><th>Güncelleme</th></tr></thead><tbody>${listRows}</tbody></table></div></div></div>
            </div>
        `);
    }

    function maskToken(token) {
        if (!token) return '-';
        const t = String(token);
        if (t.length <= 12) return t;
        return t.slice(0, 6) + '...' + t.slice(-6);
    }

    function renderDevices(data) {
        const apiTokens = data.api_tokens || [];
        const pushTokens = data.push_tokens || [];
        const apiRows = apiTokens.length ? apiTokens.map(r => `<tr><td>${esc(r.id || '-')}</td><td>${esc(r.name || '-')}</td><td>${esc(fmtDate(r.last_used_at))}</td><td>${esc(fmtDate(r.expires_at))}</td><td>${r.revoked_at ? '<span class="badge text-bg-danger">Revoked</span>' : '<span class="badge text-bg-success">Active</span>'}</td></tr>`).join('') : '<tr><td colspan="5" class="text-muted">API token yok.</td></tr>';
        const pushRows = pushTokens.length ? pushTokens.map(r => `<tr><td>${esc(r.id || '-')}</td><td>${esc(maskToken(r.token))}</td><td>${esc(r.platform || '-')}</td><td>${esc(r.device_id || '-')}</td><td>${esc(fmtDate(r.last_seen_at))}</td><td>${r.revoked_at ? '<span class="badge text-bg-danger">Revoked</span>' : '<span class="badge text-bg-success">Active</span>'}</td></tr>`).join('') : '<tr><td colspan="6" class="text-muted">Push token yok.</td></tr>';

        $('#tab-devices').html(`
            <div class="row g-3">
                <div class="col-12">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>API Tokens</h6><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Ad</th><th>Son Kullanım</th><th>Expires</th><th>Durum</th></tr></thead><tbody>${apiRows}</tbody></table></div></div>
                </div>
                <div class="col-12">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>Push Tokens / Cihazlar</h6><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Token</th><th>Platform</th><th>Cihaz</th><th>Son Görülme</th><th>Durum</th></tr></thead><tbody>${pushRows}</tbody></table></div></div>
                </div>
            </div>
        `);
    }

    function renderNotes(items) {
        noteList = items || [];
        const rows = noteList.length ? noteList.map(n => `
            <tr>
                <td>${esc(n.note || '-')}</td>
                <td>${esc(n.admin_name || n.admin_user_id || '-')}</td>
                <td>${esc(fmtDate(n.created_at))}</td>
                <td>${esc(fmtDate(n.updated_at))}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-warning note-edit-btn" data-id="${esc(n.id || '')}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-danger note-delete-btn" data-id="${esc(n.id || '')}"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        `).join('') : '<tr><td colspan="5" class="text-muted">Not bulunamadı.</td></tr>';

        $('#tab-notes').html(`
            <div class="card user-soft-card">
                <div class="card-body table-responsive">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Admin Notları</h6>
                        <button class="btn btn-sm btn-primary" id="addNoteBtn"><i class="bi bi-plus-lg"></i> Not Ekle</button>
                    </div>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Not</th><th>Ekleyen</th><th>Oluşturulma</th><th>Güncelleme</th><th></th></tr></thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
            </div>
        `);
    }

    async function loadTab(key) {
        if (!userId) return;

        if (key === 'lifecycle') {
            setLoading('#tab-lifecycle');
            const res = await api('get_user_lifecycle', 'GET', { user_id: userId });
            if (!res.success) return $('#tab-lifecycle').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(res.message || 'Yüklenemedi')}</div></div>`);
            renderLifecycle(res.data?.items || []);
            loadedTabs.lifecycle = true;
        }

        if (key === 'subscription') {
            setLoading('#tab-subscription');
            const res = await api('get_user_subscription', 'GET', { user_id: userId });
            if (!res.success) return $('#tab-subscription').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(res.message || 'Yüklenemedi')}</div></div>`);
            renderSubscription(res.data?.subscription || {});
            loadedTabs.subscription = true;
        }

        if (key === 'study') {
            setLoading('#tab-study');
            const res = await api('get_user_study_stats', 'GET', { user_id: userId });
            if (!res.success) return $('#tab-study').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(res.message || 'Yüklenemedi')}</div></div>`);
            renderStudy(res.data || {});
            loadedTabs.study = true;
        }

        if (key === 'exams') {
            setLoading('#tab-exams');
            const res = await api('get_user_exam_stats', 'GET', { user_id: userId });
            if (!res.success) return $('#tab-exams').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(res.message || 'Yüklenemedi')}</div></div>`);
            renderExams(res.data || {});
            loadedTabs.exams = true;
        }

        if (key === 'usage') {
            setLoading('#tab-usage');
            const res = await api('get_user_usage_limits', 'GET', { user_id: userId });
            if (!res.success) return $('#tab-usage').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(res.message || 'Yüklenemedi')}</div></div>`);
            renderUsage(res.data || {});
            loadedTabs.usage = true;
        }

        if (key === 'devices') {
            setLoading('#tab-devices');
            const res = await api('get_user_devices', 'GET', { user_id: userId });
            if (!res.success) return $('#tab-devices').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(res.message || 'Yüklenemedi')}</div></div>`);
            renderDevices(res.data || {});
            loadedTabs.devices = true;
        }

        if (key === 'notes') {
            renderNotes(noteList);
            loadedTabs.notes = true;
        }
    }

    async function loadDetail() {
        if (!userId) {
            await appAlert('Hata', 'Geçersiz kullanıcı id.', 'error');
            window.location.href = 'users.php';
            return;
        }

        setLoading('#tab-general', 'Kullanıcı detayı yükleniyor...');
        const res = await api('get_user_detail', 'GET', { user_id: userId });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kullanıcı detayı alınamadı.', 'error');
            window.location.href = 'users.php';
            return;
        }

        detailData = res.data || {};
        noteList = detailData.admin_notes || [];
        renderTopSummary(detailData.user || {}, detailData.kpi || {});
        renderGeneral();
    }

    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', async function () {
        const target = $(this).data('bs-target');
        const map = {
            '#tab-general': 'general',
            '#tab-lifecycle': 'lifecycle',
            '#tab-subscription': 'subscription',
            '#tab-study': 'study',
            '#tab-exams': 'exams',
            '#tab-usage': 'usage',
            '#tab-devices': 'devices',
            '#tab-notes': 'notes'
        };
        const key = map[target] || '';
        if (!key) return;
        if (loadedTabs[key]) return;
        await loadTab(key);
    });

    $(document).on('click', '#addNoteBtn', function () {
        $('#noteModalTitle').text('Admin Notu Ekle');
        $('#note_id').val('');
        $('#note_text').val('');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('noteModal')).show();
    });

    $(document).on('click', '.note-edit-btn', function () {
        const id = String($(this).data('id') || '');
        const note = noteList.find(n => String(n.id) === id);
        if (!note) return;
        $('#noteModalTitle').text('Admin Notu Düzenle');
        $('#note_id').val(id);
        $('#note_text').val(note.note || '');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('noteModal')).show();
    });

    $(document).on('click', '.note-delete-btn', async function () {
        const id = String($(this).data('id') || '');
        if (!id) return;
        const ok = await appConfirm('Not Sil', 'Bu admin notunu silmek istediğinize emin misiniz?', { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' });
        if (!ok) return;

        const res = await api('delete_note', 'POST', { note_id: id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Not silinemedi.', 'error');
            return;
        }
        noteList = res.data?.notes || [];
        renderNotes(noteList);
        await appAlert('Başarılı', res.message || 'Not silindi.', 'success');
    });

    $('#noteForm').on('submit', async function (e) {
        e.preventDefault();
        const noteId = ($('#note_id').val() || '').trim();
        const text = ($('#note_text').val() || '').trim();
        if (!text) {
            await appAlert('Uyarı', 'Not boş olamaz.', 'warning');
            return;
        }

        const action = noteId ? 'update_note' : 'add_note';
        const payload = noteId ? { note_id: noteId, note: text } : { user_id: userId, note: text };

        const $btn = $('#saveNoteBtn');
        if (typeof window.appSetButtonLoading === 'function') window.appSetButtonLoading($btn, true, 'Kaydediliyor...');
        const res = await api(action, 'POST', payload);
        if (typeof window.appSetButtonLoading === 'function') window.appSetButtonLoading($btn, false);

        if (!res.success) {
            await appAlert('Hata', res.message || 'Not kaydedilemedi.', 'error');
            return;
        }

        noteList = res.data?.notes || [];
        renderNotes(noteList);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('noteModal')).hide();
        await appAlert('Başarılı', res.message || 'Not kaydedildi.', 'success');
    });

    loadDetail();
});
</script>

<style>
.user-detail-page .user-detail-summary-card,
.user-detail-page .user-soft-card {
    border: none;
    box-shadow: var(--shadow-soft);
}

.user-detail-tabs .nav-link {
    color: var(--text-soft);
}

.user-detail-tabs .nav-link.active {
    color: var(--text-main);
    font-weight: 600;
}

.lifecycle-wrap {
    position: relative;
    padding-left: 26px;
}

.lifecycle-wrap::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--border-soft);
}

.lifecycle-item {
    position: relative;
    margin-bottom: 12px;
}

.lifecycle-dot {
    position: absolute;
    left: -20px;
    top: 16px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--primary);
}

.lifecycle-meta {
    background: var(--bg-soft);
    border-radius: 8px;
    padding: 10px;
}

@media (max-width: 767.98px) {
    .user-detail-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        white-space: nowrap;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

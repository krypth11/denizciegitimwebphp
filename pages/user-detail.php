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
    const EMPTY_TEXT = '-';

    const esc = (text) => $('<div>').text(text ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const appConfirm = (title, message, options = {}) => window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);
    const fmtDate = (value) => !value ? EMPTY_TEXT : (typeof window.formatDate === 'function' ? window.formatDate(value) : value);
    const fmtInt = (value) => Number(value || 0).toLocaleString('tr-TR');
    const fmtPct = (value) => {
        const n = Number(value || 0);
        if (!Number.isFinite(n)) return '0';
        return n.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    };
    const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
    const toPlainObject = (value) => isPlainObject(value) ? value : {};
    const toArray = (value) => Array.isArray(value) ? value : [];
    const hasItems = (value) => Array.isArray(value) && value.length > 0;
    const isFilledString = (value) => typeof value === 'string' && value.trim() !== '';
    const normalizeBool = (value) => value === true || value === 1 || value === '1' || String(value || '').toLowerCase() === 'true';
    const safeText = (value, fallback = EMPTY_TEXT) => {
        if (value === null || value === undefined) return fallback;
        if (typeof value === 'string') {
            const trimmed = value.trim();
            return trimmed === '' ? fallback : trimmed;
        }
        return String(value);
    };
    const safeNumber = (value, fallback = 0) => {
        const n = Number(value);
        return Number.isFinite(n) ? n : fallback;
    };
    const safeDateText = (value, fallback = EMPTY_TEXT) => {
        const formatted = fmtDate(value);
        return formatted === EMPTY_TEXT ? fallback : formatted;
    };
    const boolBadge = (value) => normalizeBool(value)
        ? '<span class="badge text-bg-success">Evet</span>'
        : '<span class="badge text-bg-secondary">Hayır</span>';
    const resolveQualificationName = (nameValue, idValue) => {
        const name = isFilledString(nameValue) ? nameValue.trim() : '';
        if (name !== '') {
            return name;
        }

        const id = isFilledString(idValue) ? idValue.trim() : '';
        if (id !== '') {
            return 'Tanımsız Yeterlilik';
        }

        return EMPTY_TEXT;
    };
    const getQualificationName = (row, prefix = '') => {
        const item = toPlainObject(row);
        const nestedKey = prefix ? item[prefix] : null;
        const nested = toPlainObject(nestedKey);
        const nameKey = prefix ? `${prefix}_name` : 'name';
        return resolveQualificationName(item[nameKey], nested.name, item.qualification_name, toPlainObject(item.qualification).name);
    };
    const getDisplayName = (row, keys = []) => {
        const item = toPlainObject(row);
        for (const key of keys) {
            const value = item[key];
            if (isFilledString(value)) return value.trim();
        }
        return EMPTY_TEXT;
    };
    const getCountValue = (row) => {
        const item = toPlainObject(row);
        return safeNumber(item.total ?? item.count ?? item.used_count ?? item.value ?? 0, 0);
    };
    const formatDuration = (seconds) => {
        if (seconds === null || seconds === undefined || seconds === '') return EMPTY_TEXT;
        const totalSeconds = safeNumber(seconds, -1);
        if (totalSeconds < 0) return EMPTY_TEXT;
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const remainingSeconds = totalSeconds % 60;
        if (hours > 0) return `${hours}s ${minutes}d ${remainingSeconds}sn`;
        if (minutes > 0) return `${minutes}d ${remainingSeconds}sn`;
        return `${remainingSeconds} sn`;
    };
    const getResponseMessage = (response, fallback) => {
        const directMessage = safeText(response?.message, '');
        if (directMessage) return directMessage;
        const errors = toArray(response?.errors);
        if (errors.length > 0) {
            const firstError = errors[0];
            if (isFilledString(firstError)) return firstError.trim();
            if (isPlainObject(firstError) && isFilledString(firstError.message)) return firstError.message.trim();
        }
        return fallback;
    };
    const examStatusLabel = (status) => {
        const s = String(status || '').toLowerCase();
        if (['completed', 'submitted', 'finished'].includes(s)) return '<span class="badge text-bg-success">Tamamlandı</span>';
        if (['in_progress', 'active', 'started'].includes(s)) return '<span class="badge text-bg-warning">Devam Ediyor</span>';
        if (['abandoned', 'cancelled', 'expired'].includes(s)) return '<span class="badge text-bg-danger">Terk Edildi</span>';
        return `<span class="badge text-bg-secondary">${esc(safeText(status))}</span>`;
    };
    const subscriptionEventUiMap = {
        premium_started: { label: 'Premium başlatıldı', className: 'sub-event-started' },
        premium_renewed: { label: 'Premium yenilendi', className: 'sub-event-renewed' },
        premium_expired: { label: 'Süre doldu', className: 'sub-event-expired' },
        premium_cancelled: { label: 'İptal edildi', className: 'sub-event-cancelled' }
    };
    const getSubscriptionEventUi = (eventType) => {
        const key = String(eventType || '').trim().toLowerCase();
        return subscriptionEventUiMap[key] || { label: safeText(eventType), className: 'sub-event-default' };
    };
    const summarizeSubscriptionMeta = (meta, event) => {
        const src = toPlainObject(meta);
        if (!Object.keys(src).length) return [];

        const lines = [];
        const planCode = safeText(event?.plan_code || src.plan_code || src.product_id || src.subscription_plan, '');
        const previousExpiry = safeText(src.previous_expiry || src.old_expiry || src.before_expiry || src.previous_expires_at, '');
        const nextExpiry = safeText(src.new_expiry || src.after_expiry || src.expires_at || src.next_expiry || src.new_expires_at, '');
        const entitlementId = safeText(src.entitlement_id, '');

        if (planCode) lines.push({ label: 'Plan', value: planCode });
        if (previousExpiry || nextExpiry) {
            lines.push({
                label: 'Bitiş değişimi',
                value: `${previousExpiry || '-'} → ${nextExpiry || '-'}`
            });
        }
        if (entitlementId) lines.push({ label: 'Entitlement', value: entitlementId });

        return lines.slice(0, 4);
    };

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
        if (status === 'guest') return '<span class="badge text-bg-warning">Misafir</span>';
        if (status === 'premium_active') return '<span class="badge text-bg-success">Premium Aktif</span>';
        return '<span class="badge text-bg-primary">Kayıtlı (Free)</span>';
    };

    const lifeValueLabel = (value) => {
        const v = String(value ?? '').trim().toLowerCase();
        const map = {
            registered: '<span class="badge text-bg-primary">Kayıtlı</span>',
            free: '<span class="badge text-bg-secondary">Ücretsiz</span>',
            premium_active: '<span class="badge text-bg-success">Premium Aktif</span>',
            guest: '<span class="badge text-bg-warning">Misafir</span>',
            true: '<span class="badge text-bg-success">Evet</span>',
            false: '<span class="badge text-bg-secondary">Hayır</span>',
            '1': '<span class="badge text-bg-success">Evet</span>',
            '0': '<span class="badge text-bg-secondary">Hayır</span>'
        };
        if (map[v]) return map[v];
        return `<code>${esc(value ?? '-')}</code>`;
    };

    const normalizeStudyData = (data) => {
        const totals = data.totals || {};
        const mergedTotals = {
            total_solved: Number(data.total_solved ?? totals.total_solved ?? 0),
            total_correct: Number(data.total_correct ?? totals.total_correct ?? 0),
            total_wrong: Number(data.total_wrong ?? totals.total_wrong ?? 0),
            success_rate: Number(data.success_rate ?? totals.success_rate ?? 0),
            last_study_at: data.last_study_at ?? totals.last_study_at ?? null
        };
        return {
            totals: mergedTotals,
            source_distribution: Array.isArray(data.source_distribution) ? data.source_distribution : [],
            qualification_distribution: Array.isArray(data.qualification_distribution) ? data.qualification_distribution : (data.breakdowns?.qualification || []),
            course_distribution: Array.isArray(data.course_distribution) ? data.course_distribution : (data.breakdowns?.course || []),
            topic_distribution: Array.isArray(data.topic_distribution) ? data.topic_distribution : (data.breakdowns?.topic || []),
            recent_attempts: Array.isArray(data.recent_attempts) ? data.recent_attempts : (Array.isArray(data.recent_events) ? data.recent_events : [])
        };
    };

    const normalizeExamData = (data) => {
        const summary = data.summary || {};
        return {
            summary: {
                total_exams: Number(data.total_exams ?? summary.total ?? summary.total_exams ?? 0),
                completed_exams: Number(data.completed_exams ?? summary.completed ?? summary.completed_exams ?? 0),
                in_progress_exams: Number(data.in_progress_exams ?? summary.in_progress ?? summary.in_progress_exams ?? 0),
                abandoned_exams: Number(data.abandoned_exams ?? summary.abandoned ?? summary.abandoned_exams ?? 0),
                last_exam_at: data.last_exam_at ?? summary.last_exam_at ?? null
            },
            rows: Array.isArray(data.exam_rows) ? data.exam_rows : (Array.isArray(data.attempts) ? data.attempts : [])
        };
    };

    const normalizeUsageData = (data) => ({
        summary: toPlainObject(data.summary_by_feature),
        rows: toArray(data.usage_rows)
    });

    function setLoading(tabId, text = 'Yükleniyor...') {
        $(tabId).html(`<div class="card user-soft-card"><div class="card-body text-muted"><div class="spinner-border spinner-border-sm me-2"></div>${esc(text)}</div></div>`);
    }

    function renderTopSummary(user, topSummary) {
        const summary = toPlainObject(topSummary);
        const userInfo = toPlainObject(user);
        const fullName = safeText(userInfo.full_name);
        const email = safeText(userInfo.email);
        const currentQualification = resolveQualificationName(userInfo.current_qualification_name, userInfo.current_qualification_id);
        const targetQualification = resolveQualificationName(userInfo.target_qualification_name, userInfo.target_qualification_id);
        const premiumSummary = safeDateText(userInfo.premium_expires_at ?? toPlainObject(userInfo.premium).expires_at);
        const lastSignIn = safeDateText(userInfo.last_sign_in_at);
        const totalSolved = fmtInt(summary.total_solved ?? 0);
        const totalCorrect = fmtInt(summary.total_correct ?? 0);
        const totalWrong = fmtInt(summary.total_wrong ?? 0);
        const successRate = fmtPct(summary.success_rate ?? 0);
        const totalExams = fmtInt(summary.total_exams ?? 0);
        const completedExams = fmtInt(summary.completed_exams ?? 0);

        $('#sumName').text(fullName);
        $('#sumEmail').text(email);

        const badges = [];
        badges.push(statusBadge(userInfo.status));
        badges.push(normalizeBool(userInfo.email_verified) ? '<span class="badge text-bg-success">E-posta Doğrulandı</span>' : '<span class="badge text-bg-secondary">E-posta Doğrulanmadı</span>');
        badges.push(normalizeBool(userInfo.onboarding_completed) ? '<span class="badge text-bg-success">Onboarding Tamam</span>' : '<span class="badge text-bg-secondary">Onboarding Bekliyor</span>');
        badges.push(normalizeBool(userInfo.premium_active ?? toPlainObject(userInfo.premium).is_active) ? '<span class="badge text-bg-success">Premium</span>' : '<span class="badge text-bg-secondary">Ücretsiz</span>');
        $('#sumBadges').html(badges.join(' '));

        $('#sumMetaRow').html(`
            <div class="col-12 col-lg-4">Mevcut Yeterlilik: <strong>${esc(currentQualification)}</strong></div>
            <div class="col-12 col-lg-4">Hedef Yeterlilik: <strong>${esc(targetQualification)}</strong></div>
            <div class="col-12 col-lg-4">Premium Bitiş: <strong>${esc(premiumSummary)}</strong></div>
            <div class="col-12 col-lg-4">Son Giriş: <strong>${esc(lastSignIn)}</strong></div>
            <div class="col-6 col-lg-2">Çözülen: <strong>${esc(totalSolved)}</strong></div>
            <div class="col-6 col-lg-2">Doğru: <strong>${esc(totalCorrect)}</strong></div>
            <div class="col-6 col-lg-2">Yanlış: <strong>${esc(totalWrong)}</strong></div>
            <div class="col-6 col-lg-2">Başarı: <strong>%${esc(successRate)}</strong></div>
            <div class="col-6 col-lg-2">Deneme: <strong>${esc(totalExams)}</strong></div>
            <div class="col-6 col-lg-2">Tamamlanan: <strong>${esc(completedExams)}</strong></div>
        `);
    }

    function renderGeneral() {
        const u = toPlainObject(detailData.user);
        const k = toPlainObject(detailData.top_summary ?? detailData.kpi);
        const userIdText = safeText(u.id);
        const fullName = safeText(u.full_name);
        const email = safeText(u.email);
        const pendingEmail = safeText(u.pending_email);
        const emailVerifiedAt = safeDateText(u.email_verified_at);
        const currentQualification = resolveQualificationName(u.current_qualification_name, u.current_qualification_id);
        const targetQualification = resolveQualificationName(u.target_qualification_name, u.target_qualification_id);
        const createdAt = safeDateText(u.created_at);
        const updatedAt = safeDateText(u.updated_at);
        const lastSignIn = safeDateText(u.last_sign_in_at);
        const totalSolved = fmtInt(k.total_solved ?? 0);
        const totalCorrect = fmtInt(k.total_correct ?? 0);
        const totalWrong = fmtInt(k.total_wrong ?? 0);
        const successRate = fmtPct(k.success_rate ?? 0);
        const totalExams = fmtInt(k.total_exams ?? 0);
        const completedExams = fmtInt(k.completed_exams ?? 0);
        const premiumState = normalizeBool(u.premium_active ?? toPlainObject(u.premium).is_active) ? 'Aktif' : 'Ücretsiz';

        $('#tab-general').html(`
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam Çözülen</div><div class="h4 mb-0">${esc(totalSolved)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Doğru</div><div class="h4 mb-0">${esc(totalCorrect)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Yanlış</div><div class="h4 mb-0">${esc(totalWrong)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Başarı Oranı</div><div class="h4 mb-0">%${esc(successRate)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam Deneme</div><div class="h4 mb-0">${esc(totalExams)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Tamamlanan Deneme</div><div class="h4 mb-0">${esc(completedExams)}</div></div></div></div>
                <div class="col-6 col-lg-3"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Premium</div><div class="h6 mb-0">${esc(premiumState)}</div></div></div></div>
            </div>
            <div class="card user-soft-card">
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr><th width="240">Kullanıcı ID</th><td>${esc(userIdText)}</td></tr>
                            <tr><th>Ad Soyad</th><td>${esc(fullName)}</td></tr>
                            <tr><th>E-posta</th><td>${esc(email)}</td></tr>
                            <tr><th>Bekleyen E-posta</th><td>${esc(pendingEmail)}</td></tr>
                            <tr><th>Misafir Hesap</th><td>${boolBadge(u.is_guest)}</td></tr>
                            <tr><th>Admin</th><td>${boolBadge(u.is_admin)}</td></tr>
                            <tr><th>E-posta Doğrulandı</th><td>${boolBadge(u.email_verified)}</td></tr>
                            <tr><th>E-posta Doğrulama Tarihi</th><td>${esc(emailVerifiedAt)}</td></tr>
                            <tr><th>Onboarding Tamamlandı</th><td>${boolBadge(u.onboarding_completed)}</td></tr>
                            <tr><th>Mevcut Yeterlilik</th><td>${esc(currentQualification)}</td></tr>
                            <tr><th>Hedef Yeterlilik</th><td>${esc(targetQualification)}</td></tr>
                            <tr><th>Kayıt Tarihi</th><td>${esc(createdAt)}</td></tr>
                            <tr><th>Güncellenme Tarihi</th><td>${esc(updatedAt)}</td></tr>
                            <tr><th>Son Giriş</th><td>${esc(lastSignIn)}</td></tr>
                            <tr><th>Silinmiş mi</th><td>${boolBadge(u.is_deleted)}</td></tr>
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
                        <div class="mt-2 small">Eski: ${lifeValueLabel(item.old_value)} | Yeni: ${lifeValueLabel(item.new_value)}</div>
                        ${item.meta ? `<pre class="small mt-2 mb-0 lifecycle-meta">${esc(JSON.stringify(item.meta, null, 2))}</pre>` : ''}
                    </div>
                </div>
            </div>
        `).join('');

        $('#tab-lifecycle').html(`<div class="lifecycle-wrap">${rows}</div>`);
    }

    function renderSubscription(s, historyItems = []) {
        const isPro = Number(s.is_pro || 0) === 1;
        const isPremiumActive = Number(s.premium_active || 0) === 1;
        const premiumState = isPremiumActive
            ? '<span class="badge text-bg-success">Aktif</span>'
            : (isPro ? '<span class="badge text-bg-warning">Pro var, süresi dolmuş olabilir</span>' : '<span class="badge text-bg-secondary">Ücretsiz</span>');
        const history = toArray(historyItems);
        const historyHtml = history.length ? history.map((item) => {
            const ui = getSubscriptionEventUi(item.event_type);
            const oldValue = safeText(item.old_value);
            const newValue = safeText(item.new_value);
            const planCode = safeText(item.plan_code);
            const source = safeText(item.source);
            const summaryRows = summarizeSubscriptionMeta(item.meta, item);
            const hasMeta = isPlainObject(item.meta) && Object.keys(item.meta).length > 0;
            const metaSummaryHtml = summaryRows.length
                ? `<div class="sub-meta-summary">${summaryRows.map(row => `<span><strong>${esc(row.label)}:</strong> ${esc(row.value)}</span>`).join('')}</div>`
                : '<div class="sub-meta-summary text-muted">Ek meta özeti yok.</div>';

            return `
                <div class="sub-history-item card user-soft-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge sub-event-badge ${esc(ui.className)}">${esc(ui.label)}</span>
                                <span class="text-muted small">${esc(fmtDate(item.created_at))}</span>
                            </div>
                            <span class="badge text-bg-light">${esc(planCode)}</span>
                        </div>
                        <div class="row g-2 small mb-2">
                            <div class="col-12 col-md-6"><strong>Eski değer:</strong> ${esc(oldValue)}</div>
                            <div class="col-12 col-md-6"><strong>Yeni değer:</strong> ${esc(newValue)}</div>
                            <div class="col-12 col-md-6"><strong>Kaynak:</strong> ${esc(source)}</div>
                            <div class="col-12 col-md-6"><strong>Plan kodu:</strong> ${esc(planCode)}</div>
                        </div>
                        ${metaSummaryHtml}
                        ${hasMeta ? `<details class="mt-2"><summary class="small text-muted">Ham veri</summary><pre class="small mt-2 mb-0 lifecycle-meta">${esc(JSON.stringify(item.meta, null, 2))}</pre></details>` : ''}
                    </div>
                </div>
            `;
        }).join('') : `
            <div class="card user-soft-card">
                <div class="card-body text-muted">Henüz abonelik geçmişi kaydı bulunmuyor.</div>
            </div>
        `;

        $('#tab-subscription').html(`
            <div class="card user-soft-card mb-3">
                <div class="card-body">
                    <h6 class="mb-3">Mevcut Abonelik Durumu</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                <tr><th width="220">Premium aktif mi</th><td>${premiumState}</td></tr>
                                <tr><th>Pro üyelik alanı</th><td>${boolBadge(s.is_pro)}</td></tr>
                                <tr><th>Paket kodu</th><td>${esc(s.plan_code || '-')}</td></tr>
                                <tr><th>Sağlayıcı</th><td>${esc(s.provider || '-')}</td></tr>
                                <tr><th>Entitlement ID</th><td>${esc(s.entitlement_id || '-')}</td></tr>
                                <tr><th>RC App User ID</th><td>${esc(s.rc_app_user_id || '-')}</td></tr>
                                <tr><th>Bitiş tarihi</th><td>${esc(fmtDate(s.expires_at))}</td></tr>
                                <tr><th>Son senkron</th><td>${esc(fmtDate(s.last_synced_at))}</td></tr>
                                <tr><th>Oluşturulma</th><td>${esc(fmtDate(s.created_at))}</td></tr>
                                <tr><th>Güncellenme</th><td>${esc(fmtDate(s.updated_at))}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="mb-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <h6 class="mb-0">Abonelik Geçmişi</h6>
                <span class="text-muted small">Toplam kayıt: ${esc(history.length)}</span>
            </div>
            <div class="sub-history-wrap">${historyHtml}</div>
        `);
    }

    function renderStudy(data) {
        const normalized = normalizeStudyData(data || {});
        const totals = normalized.totals;
        const dist = normalized.source_distribution;
        const recent = normalized.recent_attempts;
        const hasSourceDistribution = hasItems(dist);
        const hasRecentAttempts = hasItems(recent);
        const qualificationDistribution = toArray(normalized.qualification_distribution);
        const courseDistribution = toArray(normalized.course_distribution);
        const topicDistribution = toArray(normalized.topic_distribution);

        const distRows = hasSourceDistribution
            ? dist.map(x => {
                const sourceName = getDisplayName(x, ['source_label', 'source_name', 'source']);
                const totalText = fmtInt(getCountValue(x));
                return `<tr><td>${esc(sourceName)}</td><td>${esc(totalText)}</td></tr>`;
            }).join('')
            : '<tr><td colspan="2" class="text-muted">Kayıt yok</td></tr>';
        const recentRows = hasRecentAttempts ? recent.map(x => {
            const attemptDate = safeDateText(x.event_at ?? x.created_at ?? x.answered_at);
            const sourceName = getDisplayName(x, ['source_label', 'source_name', 'source']);
            const qualificationName = resolveQualificationName(x.qualification_name, toPlainObject(x.qualification).name);
            const lessonName = getDisplayName(x, ['course_name', 'topic_name', 'content_title']);
            const questionText = safeText(x.question_id);
            let resultBadge = '<span class="badge text-bg-secondary">Bilinmiyor</span>';
            if (x.is_correct == 1) resultBadge = '<span class="badge text-bg-success">Doğru</span>';
            else if (x.is_correct == 0) resultBadge = '<span class="badge text-bg-danger">Yanlış</span>';
            return `<tr><td>${esc(attemptDate)}</td><td>${esc(sourceName)}</td><td>${esc(qualificationName)}</td><td>${esc(lessonName)}</td><td>${esc(questionText)}</td><td>${resultBadge}</td></tr>`;
        }).join('') : '<tr><td colspan="6" class="text-muted">Kayıt yok</td></tr>';

        const renderBreak = (title, rows) => {
            const rowList = toArray(rows);
            const body = hasItems(rowList)
                ? rowList.map(r => {
                    const rowName = getDisplayName(r, ['name', 'qualification_name', 'course_name', 'topic_name', 'title', 'label']);
                    const totalText = fmtInt(getCountValue(r));
                    return `<tr><td>${esc(rowName)}</td><td>${esc(totalText)}</td></tr>`;
                }).join('')
                : '<tr><td colspan="2" class="text-muted">Kayıt yok</td></tr>';
            return `<div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body table-responsive"><h6>${title}</h6><table class="table table-sm mb-0"><thead><tr><th>Ad</th><th>Toplam</th></tr></thead><tbody>${body}</tbody></table></div></div></div>`;
        };

        const totalSolved = fmtInt(totals.total_solved ?? 0);
        const totalCorrect = fmtInt(totals.total_correct ?? 0);
        const totalWrong = fmtInt(totals.total_wrong ?? 0);
        const successRate = fmtPct(totals.success_rate ?? 0);
        const lastStudyAt = safeDateText(totals.last_study_at);

        $('#tab-study').html(`
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam Çözülen</div><div class="h5 mb-0">${esc(totalSolved)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Doğru</div><div class="h5 mb-0">${esc(totalCorrect)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Yanlış</div><div class="h5 mb-0">${esc(totalWrong)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Başarı</div><div class="h5 mb-0">%${esc(successRate)}</div></div></div></div>
                <div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Son Çalışma</div><div class="h6 mb-0">${esc(lastStudyAt)}</div></div></div></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-4">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>Kaynak Dağılımı</h6><table class="table table-sm mb-0"><thead><tr><th>Kaynak</th><th>Toplam</th></tr></thead><tbody>${distRows}</tbody></table></div></div>
                </div>
                <div class="col-12 col-lg-8">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>Son 20 Cevap Olayı</h6><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Kaynak</th><th>Yeterlilik</th><th>Ders / Konu</th><th>Soru</th><th>Sonuç</th></tr></thead><tbody>${recentRows}</tbody></table></div></div>
                </div>
            </div>
            <div class="row g-3">
                ${renderBreak('Yeterlilik Dağılımı', qualificationDistribution)}
                ${renderBreak('Ders Dağılımı', courseDistribution)}
                ${renderBreak('Konu Dağılımı', topicDistribution)}
            </div>
        `);
    }

    function renderExams(data) {
        const normalized = normalizeExamData(data || {});
        const s = normalized.summary;
        const attempts = normalized.rows;
        const rows = hasItems(attempts) ? attempts.map(a => {
            const qualificationName = resolveQualificationName(a.qualification_name, toPlainObject(a.qualification).name);
            const startedAt = safeDateText(a.started_at);
            const submittedAt = safeDateText(a.submitted_at);
            const abandonedAt = safeDateText(a.abandoned_at);
            const elapsedText = formatDuration(a.elapsed_seconds);
            const requestedCount = safeText(a.requested_question_count);
            const actualCount = safeText(a.actual_question_count);
            const modeText = safeText(a.mode);
            const poolTypeText = safeText(a.pool_type);
            const warningText = safeText(a.warning_message);
            const statusHtml = isFilledString(a.status_label) ? `<span class="badge text-bg-secondary">${esc(a.status_label.trim())}</span>` : examStatusLabel(a.status);
            return `
            <tr>
                <td>${esc(qualificationName)}</td>
                <td>${esc(startedAt)}</td>
                <td>${esc(submittedAt)}</td>
                <td>${esc(abandonedAt)}</td>
                <td>${esc(elapsedText)}</td>
                <td>${esc(actualCount + ' / ' + requestedCount)}</td>
                <td>${esc(modeText)}</td>
                <td>${esc(poolTypeText)}</td>
                <td>${statusHtml}</td>
                <td>${esc(warningText)}</td>
            </tr>
        `;
        }).join('') : '<tr><td colspan="10" class="text-muted">Deneme kaydı yok.</td></tr>';

        const totalExams = fmtInt(s.total_exams ?? 0);
        const completedExams = fmtInt(s.completed_exams ?? 0);
        const inProgressExams = fmtInt(s.in_progress_exams ?? 0);
        const abandonedExams = fmtInt(s.abandoned_exams ?? 0);
        const lastExamAt = safeDateText(s.last_exam_at);

        $('#tab-exams').html(`
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Toplam Deneme</div><div class="h5 mb-0">${esc(totalExams)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Tamamlanan</div><div class="h5 mb-0">${esc(completedExams)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Devam Ediyor</div><div class="h5 mb-0">${esc(inProgressExams)}</div></div></div></div>
                <div class="col-6 col-lg-2"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Terk Edildi</div><div class="h5 mb-0">${esc(abandonedExams)}</div></div></div></div>
                <div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body"><div class="small text-muted">Son Deneme</div><div class="h6 mb-0">${esc(lastExamAt)}</div></div></div></div>
            </div>
            <div class="card user-soft-card"><div class="card-body table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Yeterlilik</th><th>Başlangıç</th><th>Gönderim</th><th>Terk</th><th>Süre (sn)</th><th>Soru (Gerçek/İstenen)</th><th>Mod</th><th>Havuz Tipi</th><th>Durum</th><th>Uyarı</th></tr></thead><tbody>${rows}</tbody></table></div></div>
        `);
    }

    function renderUsage(data) {
        const normalized = normalizeUsageData(data || {});
        const summary = normalized.summary;
        const rows = normalized.rows;
        const summaryRows = Object.keys(summary).map((key) => ({ feature_key: key, ...toPlainObject(summary[key]) }));
        const sumRows = hasItems(summaryRows)
            ? summaryRows.map((row) => {
                const featureName = getDisplayName(row, ['feature_label', 'feature_name', 'feature_key']);
                const usedValue = fmtInt(safeNumber(row.used_count ?? row.total_used ?? 0));
                const dailyLimit = safeText(row.daily_limit);
                return `<tr><td>${esc(featureName)}</td><td>${esc(usedValue)}</td><td>${esc(dailyLimit)}</td></tr>`;
            }).join('')
            : '<tr><td colspan="3" class="text-muted">Özet veri yok.</td></tr>';
        const listRows = hasItems(rows)
            ? rows.map(r => {
                const usageDate = safeText(r?.usage_date_tr);
                const featureKey = getDisplayName(r, ['feature_label', 'feature_name', 'feature_key']);
                const usedCount = fmtInt(safeNumber(r?.used_count ?? 0));
                const dailyLimit = safeText(r?.daily_limit);
                const qualificationName = safeText(r?.qualification_name);
                const updatedAt = safeDateText(r?.updated_at);
                return `<tr><td>${esc(usageDate)}</td><td>${esc(featureKey)}</td><td>${esc(usedCount)}</td><td>${esc(dailyLimit)}</td><td>${esc(qualificationName)}</td><td>${esc(updatedAt)}</td></tr>`;
            }).join('')
            : '<tr><td colspan="6" class="text-muted">Kayıt yok.</td></tr>';

        $('#tab-usage').html(`
            <div class="row g-3">
                <div class="col-12 col-lg-4"><div class="card user-soft-card"><div class="card-body table-responsive"><h6>Özellik Bazlı Özet</h6><table class="table table-sm mb-0"><thead><tr><th>Özellik</th><th>Toplam Kullanım</th><th>Günlük Limit</th></tr></thead><tbody>${sumRows}</tbody></table></div></div></div>
                <div class="col-12 col-lg-8"><div class="card user-soft-card"><div class="card-body table-responsive"><h6>Kullanım Kayıtları</h6><table class="table table-sm mb-0"><thead><tr><th>Tarih</th><th>Özellik</th><th>Kullanım</th><th>Günlük Limit</th><th>Yeterlilik</th><th>Güncelleme</th></tr></thead><tbody>${listRows}</tbody></table></div></div></div>
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
        const apiTokens = toArray(data.api_tokens);
        const pushTokens = toArray(data.push_tokens);
        const apiRows = hasItems(apiTokens)
            ? apiTokens.map(r => {
                const statusLabel = r.revoked_at ? '<span class="badge text-bg-danger">Pasif</span>' : '<span class="badge text-bg-success">Aktif</span>';
                const createdAt = safeDateText(r.created_at);
                const expiresAt = safeDateText(r.expires_at);
                const lastUsedAt = safeDateText(r.last_used_at);
                const revokedAt = safeDateText(r.revoked_at);
                return `<tr><td>${esc(safeText(r.id))}</td><td>${esc(safeText(r.name))}</td><td>${esc(createdAt)}</td><td>${esc(expiresAt)}</td><td>${esc(lastUsedAt)}</td><td>${esc(revokedAt)}</td><td>${statusLabel}</td></tr>`;
            }).join('')
            : '<tr><td colspan="7" class="text-muted">API token yok.</td></tr>';
        const pushRows = hasItems(pushTokens)
            ? pushTokens.map(r => {
                const idText = safeText(r.id);
                const fcmToken = safeText(r.fcm_token);
                const installationId = safeText(r.installation_id);
                const deviceName = safeText(r.device_name);
                const appVersion = safeText(r.app_version);
                const permissionStatus = safeText(r.permission_status);
                const platform = safeText(r.platform);
                const isActive = normalizeBool(r.is_active);
                const lastSeenAt = safeDateText(r.last_seen_at);
                const createdAt = safeDateText(r.created_at);
                const updatedAt = safeDateText(r.updated_at);
                const statusLabel = isActive ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>';
                return `<tr><td>${esc(idText)}</td><td>${esc(maskToken(fcmToken))}</td><td>${esc(installationId)}</td><td>${esc(deviceName)}</td><td>${esc(appVersion)}</td><td>${esc(permissionStatus)}</td><td>${esc(platform)}</td><td>${isActive ? '<span class="badge text-bg-success">Evet</span>' : '<span class="badge text-bg-secondary">Hayır</span>'}</td><td>${esc(lastSeenAt)}</td><td>${esc(createdAt)}</td><td>${esc(updatedAt)}</td><td>${statusLabel}</td></tr>`;
            }).join('')
            : '<tr><td colspan="12" class="text-muted">Push token yok.</td></tr>';

        $('#tab-devices').html(`
            <div class="row g-3">
                <div class="col-12">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>API Tokenları</h6><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Ad</th><th>Oluşturulma</th><th>Bitiş</th><th>Son Kullanım</th><th>Revoked</th><th>Durum</th></tr></thead><tbody>${apiRows}</tbody></table></div></div>
                </div>
                <div class="col-12">
                    <div class="card user-soft-card"><div class="card-body table-responsive"><h6>Push Tokenları / Cihazlar</h6><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>FCM Token</th><th>Installation ID</th><th>Cihaz Adı</th><th>Uygulama Sürümü</th><th>İzin Durumu</th><th>Platform</th><th>Aktif</th><th>Son Görülme</th><th>Oluşturulma</th><th>Güncelleme</th><th>Durum</th></tr></thead><tbody>${pushRows}</tbody></table></div></div>
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
            const [subRes, historyRes] = await Promise.all([
                api('get_user_subscription', 'GET', { user_id: userId }),
                api('get_user_subscription_history', 'GET', { user_id: userId })
            ]);
            if (!subRes.success) return $('#tab-subscription').html(`<div class="card user-soft-card"><div class="card-body text-danger">${esc(subRes.message || 'Yüklenemedi')}</div></div>`);
            const historyItems = historyRes?.success ? toArray(historyRes.data?.items) : [];
            renderSubscription(subRes.data?.subscription || {}, historyItems);
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
        try {
            if (!userId) {
                await appAlert('Hata', 'Geçersiz kullanıcı id.', 'error');
                window.location.href = 'users.php';
                return;
            }

            setLoading('#tab-general', 'Kullanıcı detayı yükleniyor...');
            const res = await api('get_user_detail', 'GET', { user_id: userId });
            const isSuccess = normalizeBool(res?.success);
            if (!isSuccess) {
                await appAlert('Hata', getResponseMessage(res, 'Kullanıcı detayı alınamadı.'), 'error');
                window.location.href = 'users.php';
                return;
            }

            const responseData = toPlainObject(res?.data);
            const parsedDetail = toPlainObject(responseData.detail || responseData.user_detail || responseData);
            const userData = toPlainObject(parsedDetail.user);
            if (!Object.keys(userData).length) {
                $('#tab-general').html('<div class="card user-soft-card"><div class="card-body text-muted">Kullanıcı detayı bulunamadı.</div></div>');
                $('#sumName').text('Kullanıcı bulunamadı');
                $('#sumEmail').text(EMPTY_TEXT);
                $('#sumBadges').html('');
                $('#sumMetaRow').html('');
                return;
            }

            detailData = parsedDetail;
            noteList = toArray(detailData.admin_notes);
            renderTopSummary(userData, detailData.top_summary ?? detailData.kpi ?? {});
            renderGeneral();
            loadedTabs.general = true;

            if (isPlainObject(detailData.study_stats)) {
                renderStudy(detailData.study_stats);
                loadedTabs.study = true;
            }
            if (isPlainObject(detailData.exam_stats)) {
                renderExams(detailData.exam_stats);
                loadedTabs.exams = true;
            }
            if (isPlainObject(detailData.usage_limits)) {
                renderUsage(detailData.usage_limits);
                loadedTabs.usage = true;
            }
            if (Array.isArray(detailData.api_tokens) || Array.isArray(detailData.push_tokens)) {
                renderDevices({ api_tokens: detailData.api_tokens, push_tokens: detailData.push_tokens });
                loadedTabs.devices = true;
            }

            if (!loadedTabs.study) {
                await loadTab('study');
            }
            if (!loadedTabs.exams) {
                await loadTab('exams');
            }
        } catch (err) {
            console.error('[user-detail] loadDetail failed', err);
        }
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

    loadDetail().catch(err => {
        console.error('[user-detail] initial load failed', err);
    });
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

.sub-history-wrap {
    display: grid;
    gap: 12px;
}

.sub-event-badge {
    font-weight: 600;
}

.sub-event-started {
    background: #d1fae5;
    color: #065f46;
}

.sub-event-renewed {
    background: #dbeafe;
    color: #1e40af;
}

.sub-event-expired {
    background: #fef3c7;
    color: #92400e;
}

.sub-event-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.sub-event-default {
    background: #e5e7eb;
    color: #374151;
}

.sub-meta-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    font-size: .85rem;
}

@media (max-width: 767.98px) {
    .user-detail-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        white-space: nowrap;
    }

    .sub-meta-summary {
        flex-direction: column;
        gap: 4px;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

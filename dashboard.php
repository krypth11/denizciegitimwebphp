<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$user = require_auth();
$current_page = 'dashboard';
$page_title = 'Dashboard';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="container-fluid dashboard-page">
    <div class="page-header mb-3">
        <div>
            <h2>Dashboard</h2>
            <p class="text-muted mb-0">Canlı metrikler, aktiviteler ve trend analizi</p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
            <div class="card dashboard-widget h-100" id="cardTotalQuestions">
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <h6 class="mb-0">Toplam Soru</h6>
                        <span class="badge bg-primary-subtle text-primary">Canlı</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-12 col-md-6 col-lg-12 col-xl-6">
                            <label class="form-label mb-1">Yeterlilik</label>
                            <select class="form-select form-select-sm" id="qQualificationFilter">
                                <option value="">Tümü</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-12 col-xl-6">
                            <label class="form-label mb-1">Ders</label>
                            <select class="form-select form-select-sm" id="qCourseFilter" disabled>
                                <option value="">Önce yeterlilik seçin</option>
                            </select>
                        </div>
                    </div>
                    <div class="dashboard-metric-value" id="totalQuestionsValue">-</div>
                    <small class="text-muted" id="totalQuestionsHint">Filtreye göre toplam soru adedi</small>
                    <small class="activity-refresh-note" id="statsRefreshNote">Son güncelleme: -</small>
                    <div class="widget-message" id="totalQuestionsMsg"></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card dashboard-widget h-100" id="cardSolvedQuestions">
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <h6 class="mb-0">Çözülen Soru Sayısı</h6>
                        <span class="badge bg-success-subtle text-success">Attempt Events</span>
                    </div>
                    <div class="dashboard-date-filter" id="solvedDateFilter">
                        <div class="dashboard-chip-group date-chip-group" id="solvedRangeChips">
                            <button type="button" class="chip" data-range="1d">1 Gün</button>
                            <button type="button" class="chip" data-range="3d">3 Gün</button>
                            <button type="button" class="chip active" data-range="7d">7 Gün</button>
                            <button type="button" class="chip" data-range="15d">15 Gün</button>
                            <button type="button" class="chip" data-range="30d">30 Gün</button>
                        </div>
                        <label class="form-label mb-1">Tarih seçiniz</label>
                        <button type="button" class="date-range-display" id="solvedDateDisplay">Tarih seçiniz</button>
                        <div class="date-range-panel d-none" id="solvedDatePanel">
                            <input type="date" class="form-control form-control-sm" id="solvedStartDate">
                            <input type="date" class="form-control form-control-sm" id="solvedEndDate">
                        </div>
                    </div>
                    <div class="dashboard-metric-value" id="solvedQuestionsValue">-</div>
                    <small class="text-muted" id="solvedQuestionsHint">Seçili zaman aralığındaki toplam attempt</small>
                    <small class="activity-refresh-note" id="solvedRefreshNote">Son güncelleme: -</small>
                    <div class="widget-message" id="solvedQuestionsMsg"></div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card dashboard-widget h-100" id="cardTotalUsers">
                <div class="card-body d-flex flex-column gap-3">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <h6 class="mb-0">Toplam Kullanıcı Sayısı</h6>
                        <span class="badge bg-info-subtle text-info">Aktif Kayıtlar</span>
                    </div>

                    <div class="dashboard-toggle" id="userTypeToggle">
                        <button type="button" class="btn btn-sm active" data-type="all">Tümü</button>
                        <button type="button" class="btn btn-sm" data-type="guest">Guest</button>
                        <button type="button" class="btn btn-sm" data-type="registered">Kayıtlı</button>
                    </div>

                    <div class="dashboard-metric-value" id="totalUsersValue">-</div>
                    <small class="text-muted" id="totalUsersHint">is_deleted=0 kayıtları baz alınır</small>
                    <small class="activity-refresh-note" id="usersRefreshNote">Son güncelleme: -</small>
                    <div class="widget-message" id="totalUsersMsg"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card dashboard-widget mb-3">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
            <div>
                <h5 class="mb-0">Son Aktiviteler</h5>
                <small class="text-muted" id="activityRefreshInfo"><span class="live-dot"></span> Canlı · Son güncelleme: - · Otomatik yenileme: 1sn</small>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="dashboard-chip-group" id="activityTypes">
                    <button type="button" class="chip active" data-type="registrations">Kaydolan Kullanıcılar</button>
                    <button type="button" class="chip active" data-type="daily_quiz">Daily Quiz</button>
                    <button type="button" class="chip active" data-type="solved_questions">Çözülen Sorular</button>
                    <button type="button" class="chip active" data-type="subscription_started">Yeni Abonelikler</button>
                    <button type="button" class="chip active" data-type="subscription_renewed">Abonelik Yenilemeleri</button>
                </div>
                <select class="form-select form-select-sm w-auto" id="activityLimit">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="activityError" class="p-3 small text-danger d-none"></div>
            <div id="activityEmpty" class="p-3 text-muted d-none">Bu filtrelere uygun aktivite bulunamadı.</div>
            <div class="activity-list" id="activityList"></div>
        </div>
    </div>

    <div class="card dashboard-widget">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
            <div>
                <h5 class="mb-0">Aktivite Grafiği</h5>
                <small class="text-muted">Filtreler değişince otomatik güncellenir · Abonelik serileri plan bazlı adet olarak hesaplanır · <span id="chartRefreshInfo">Son güncelleme: -</span></small>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="dashboard-date-filter" id="chartDateFilter">
                    <div class="dashboard-chip-group date-chip-group" id="chartRangeChips">
                        <button type="button" class="chip" data-range="1d">1 Gün</button>
                        <button type="button" class="chip" data-range="3d">3 Gün</button>
                        <button type="button" class="chip active" data-range="7d">7 Gün</button>
                        <button type="button" class="chip" data-range="15d">15 Gün</button>
                        <button type="button" class="chip" data-range="30d">30 Gün</button>
                    </div>
                    <button type="button" class="date-range-display" id="chartDateDisplay">Tarih seçiniz</button>
                    <div class="date-range-panel d-none" id="chartDatePanel">
                        <input type="date" class="form-control form-control-sm" id="chartStartDate">
                        <input type="date" class="form-control form-control-sm" id="chartEndDate">
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="dashboard-chip-group mb-3" id="chartTypes">
                <button type="button" class="chip active" data-type="registrations">Kaydolan Kullanıcılar</button>
                <button type="button" class="chip active" data-type="subscription_monthly">1 Aylık Abonelikler</button>
                <button type="button" class="chip active" data-type="subscription_quarterly">3 Aylık Abonelikler</button>
                <button type="button" class="chip active" data-type="subscription_semiannual">6 Aylık Abonelikler</button>
                <button type="button" class="chip active" data-type="subscription_annual">Yıllık Abonelikler</button>
            </div>
            <div class="row g-2 mb-3" id="chartTotals"></div>
            <div class="chart-wrap">
                <canvas id="activityLineChart" height="110"></canvas>
            </div>
            <div class="widget-message mt-2" id="chartMsg"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="activityDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="activityDetailTitle">Aktivite Detayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div id="activityDetailBody"></div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const dashboardState = {
        question: { qualification_id: '', course_id: '' },
        solved: { range: '7d', start_date: '', end_date: '' },
        users: { user_type: 'all' },
        activity: { types: ['registrations', 'daily_quiz', 'solved_questions', 'subscription_started', 'subscription_renewed'], limit: 25 },
        chart: { range: '7d', start_date: '', end_date: '', types: ['registrations', 'subscription_monthly', 'subscription_quarterly', 'subscription_semiannual', 'subscription_annual'] },
        refs: { qualifications: [], courses: [] },
        polling: { timer: null, interval: 1000 }
    };

    let activityChart = null;
    const activityMap = new Map();
    let lastActivitySignature = '';
    let lastChartSignature = '';
    let statisticsInFlight = false;
    let activityInFlight = false;
    let trendsInFlight = false;
    let filtersInFlight = false;
    let firstStatsLoaded = false;
    let firstActivitiesLoaded = false;
    let firstChartLoaded = false;

    const qs = (s) => document.querySelector(s);
    const qsa = (s) => Array.from(document.querySelectorAll(s));

    function setWidgetLoading(elId, isLoading) {
        const el = qs(elId);
        if (!el) return;
        el.classList.toggle('is-loading', !!isLoading);
    }

    function setMsg(elId, message = '', type = '') {
        const el = qs(elId);
        if (!el) return;
        el.className = 'widget-message';
        if (!message) {
            el.textContent = '';
            return;
        }
        if (type) el.classList.add(type);
        el.textContent = message;
    }

    async function jsonGet(url, params = {}) {
        const query = new URLSearchParams(params);
        const fullUrl = query.toString() ? `${url}?${query}` : url;
        const res = await fetch(fullUrl, { credentials: 'same-origin' });
        let data = {};
        try { data = await res.json(); } catch (_) {}
        if (!res.ok || !data.success) {
            throw new Error(data.message || 'İstek başarısız');
        }
        return data.data || {};
    }

    function formatNumber(v) {
        return new Intl.NumberFormat('tr-TR').format(Number(v || 0));
    }

    function formatDateTime(v) {
        if (!v) return '-';
        const d = new Date(v);
        if (Number.isNaN(d.getTime())) return String(v);
        return d.toLocaleString('tr-TR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function nowTimeLabel() {
        return new Date().toLocaleTimeString('tr-TR', { hour12: false });
    }

    function setRefreshNote(elId) {
        const el = qs(elId);
        if (!el) return;
        el.textContent = `Son güncelleme: ${nowTimeLabel()}`;
    }

    function setActivityRefreshInfo() {
        const el = qs('#activityRefreshInfo');
        if (!el) return;
        el.innerHTML = `<span class="live-dot"></span> Canlı · Son güncelleme: ${nowTimeLabel()} · Otomatik yenileme: 1sn`;
    }

    function safe(v, fallback = '-') {
        if (v === null || v === undefined || String(v).trim() === '') return fallback;
        return String(v);
    }

    function setTextIfChanged(selector, value) {
        const el = qs(selector);
        if (!el) return;
        const next = String(value);
        if (el.textContent !== next) {
            el.textContent = next;
        }
    }

    function normalizeAnswer(v) {
        const val = String(v || '').trim().toUpperCase();
        return ['A', 'B', 'C', 'D', 'E'].includes(val) ? val : null;
    }

    function formatDateRangeLabel(startDate, endDate) {
        if (!startDate || !endDate) return 'Tarih seçiniz';
        const f = (iso) => {
            const [y, m, d] = String(iso).split('-');
            if (!y || !m || !d) return iso;
            return `${d}.${m}.${y}`;
        };
        return `${f(startDate)} - ${f(endDate)}`;
    }

    function rangeDays(range) {
        return Number(String(range).replace('d', '')) || 7;
    }

    function toIsoDate(dt) {
        const year = dt.getFullYear();
        const month = String(dt.getMonth() + 1).padStart(2, '0');
        const day = String(dt.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function applyPresetRange(stateObj, range) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const days = Math.max(1, rangeDays(range));
        const start = new Date(today);
        start.setDate(start.getDate() - (days - 1));
        stateObj.range = range;
        stateObj.start_date = toIsoDate(start);
        stateObj.end_date = toIsoDate(today);
    }

    function detectRangePreset(startDate, endDate) {
        if (!startDate || !endDate) return null;
        const s = new Date(startDate + 'T00:00:00');
        const e = new Date(endDate + 'T00:00:00');
        if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime()) || e < s) return null;
        const diff = Math.floor((e - s) / 86400000) + 1;
        return ['1d', '3d', '7d', '15d', '30d'].find((r) => rangeDays(r) === diff) || null;
    }

    function syncDateFilterUI(prefix, stateObj) {
        const startEl = qs(`#${prefix}StartDate`);
        const endEl = qs(`#${prefix}EndDate`);
        const displayEl = qs(`#${prefix}DateDisplay`);
        if (startEl) startEl.value = stateObj.start_date || '';
        if (endEl) endEl.value = stateObj.end_date || '';
        if (displayEl) displayEl.textContent = formatDateRangeLabel(stateObj.start_date, stateObj.end_date);

        const matched = detectRangePreset(stateObj.start_date, stateObj.end_date);
        qsa(`#${prefix}RangeChips .chip`).forEach((chip) => {
            chip.classList.toggle('active', matched === chip.dataset.range);
        });
    }

    function initDateFilter(prefix, stateObj, onChange) {
        applyPresetRange(stateObj, stateObj.range || '7d');
        syncDateFilterUI(prefix, stateObj);

        const panel = qs(`#${prefix}DatePanel`);
        const display = qs(`#${prefix}DateDisplay`);
        const startEl = qs(`#${prefix}StartDate`);
        const endEl = qs(`#${prefix}EndDate`);

        display?.addEventListener('click', (e) => {
            e.stopPropagation();
            panel?.classList.toggle('d-none');
        });

        qsa(`#${prefix}RangeChips .chip`).forEach((chip) => {
            chip.addEventListener('click', async () => {
                applyPresetRange(stateObj, chip.dataset.range || '7d');
                syncDateFilterUI(prefix, stateObj);
                panel?.classList.add('d-none');
                await onChange();
            });
        });

        [startEl, endEl].forEach((el) => {
            el?.addEventListener('change', async () => {
                stateObj.start_date = startEl?.value || '';
                stateObj.end_date = endEl?.value || '';
                const matched = detectRangePreset(stateObj.start_date, stateObj.end_date);
                if (matched) stateObj.range = matched;
                syncDateFilterUI(prefix, stateObj);
                await onChange();
            });
        });

        document.addEventListener('click', (e) => {
            if (!panel || !display) return;
            if (panel.classList.contains('d-none')) return;
            if (panel.contains(e.target) || display.contains(e.target)) return;
            panel.classList.add('d-none');
        });
    }

    function getActiveTypes(containerId) {
        return qsa(`${containerId} .chip.active`).map(x => x.dataset.type);
    }

    async function loadFilters() {
        if (filtersInFlight) return;
        filtersInFlight = true;
        try {
            const data = await jsonGet('/api/v1/dashboard/details.php', { scope: 'admin', qualification_id: dashboardState.question.qualification_id || '' });
            dashboardState.refs.qualifications = data.details?.qualifications || [];
            dashboardState.refs.courses = data.details?.courses || [];

            const qSelect = qs('#qQualificationFilter');
            const cSelect = qs('#qCourseFilter');
            qSelect.innerHTML = '<option value="">Tümü</option>';
            cSelect.innerHTML = '';

            dashboardState.refs.qualifications.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                qSelect.appendChild(opt);
            });

            qSelect.value = dashboardState.question.qualification_id;

            if (!dashboardState.question.qualification_id) {
                dashboardState.question.course_id = '';
                cSelect.disabled = true;
                cSelect.innerHTML = '<option value="">Önce yeterlilik seçin</option>';
            } else {
                cSelect.disabled = false;
                cSelect.innerHTML = '<option value="">Tümü</option>';
                dashboardState.refs.courses.forEach(item => {
                    const opt = document.createElement('option');
                    opt.value = item.id;
                    opt.textContent = item.name;
                    cSelect.appendChild(opt);
                });
                cSelect.value = dashboardState.question.course_id;
            }
        } finally {
            filtersInFlight = false;
        }
    }

    async function loadStatistics() {
        if (statisticsInFlight) return;
        statisticsInFlight = true;

        if (!firstStatsLoaded) {
            setWidgetLoading('#cardTotalQuestions', true);
            setWidgetLoading('#cardSolvedQuestions', true);
            setWidgetLoading('#cardTotalUsers', true);
        }
        setMsg('#totalQuestionsMsg');
        setMsg('#solvedQuestionsMsg');
        setMsg('#totalUsersMsg');

        try {
            const [questionsData, solvedData, usersData] = await Promise.all([
                jsonGet('/api/v1/dashboard/statistics.php', {
                    scope: 'admin',
                    qualification_id: dashboardState.question.qualification_id,
                    course_id: dashboardState.question.course_id
                }),
                jsonGet('/api/v1/dashboard/statistics.php', {
                    scope: 'admin',
                    solved_range: dashboardState.solved.range,
                    solved_start_date: dashboardState.solved.start_date,
                    solved_end_date: dashboardState.solved.end_date
                }),
                jsonGet('/api/v1/dashboard/statistics.php', {
                    scope: 'admin',
                    user_type: dashboardState.users.user_type
                })
            ]);

            console.log('statistics:', { questionsData, solvedData, usersData });

            setTextIfChanged('#totalQuestionsValue', formatNumber(questionsData.statistics?.total_questions || 0));
            setTextIfChanged('#solvedQuestionsValue', formatNumber(solvedData.statistics?.solved_questions_count || 0));
            setTextIfChanged('#totalUsersValue', formatNumber(usersData.statistics?.total_users || 0));

            setRefreshNote('#statsRefreshNote');
            setRefreshNote('#solvedRefreshNote');
            setRefreshNote('#usersRefreshNote');
        } catch (e) {
            setMsg('#totalQuestionsMsg', e.message, 'error');
            setMsg('#solvedQuestionsMsg', e.message, 'error');
            setMsg('#totalUsersMsg', e.message, 'error');
        } finally {
            if (!firstStatsLoaded) {
                setWidgetLoading('#cardTotalQuestions', false);
                setWidgetLoading('#cardSolvedQuestions', false);
                setWidgetLoading('#cardTotalUsers', false);
                firstStatsLoaded = true;
            }
            statisticsInFlight = false;
        }
    }

    function activityTypeLabel(type) {
        const map = {
            registrations: 'Kayıt',
            daily_quiz: 'Daily Quiz',
            solved_questions: 'Soru Çözümü',
            subscription_started: 'Yeni Abonelik',
            subscription_renewed: 'Abonelik Yenileme'
        };
        return map[type] || type;
    }

    function activityIcon(type) {
        const map = {
            registrations: 'bi-person-plus',
            daily_quiz: 'bi-lightning-charge',
            solved_questions: 'bi-bullseye',
            subscription_started: 'bi-gem',
            subscription_renewed: 'bi-arrow-repeat'
        };
        return map[type] || 'bi-activity';
    }

    function activitySentence(item) {
        const name = safe(item.user?.full_name, safe(item.user?.email, 'Kullanıcı'));
        if (item.type === 'solved_questions') {
            const ok = item.detail?.is_correct === true;
            return `${name} bir soruyu ${ok ? 'doğru' : 'yanlış'} çözdü`;
        }
        if (item.type === 'registrations') return `Yeni kayıt: ${name}`;
        if (item.type === 'daily_quiz') return `Daily Quiz tamamlandı: ${item.detail?.correct_count ?? 0}/${item.detail?.total_count ?? 0} doğru`;
        if (item.type === 'subscription_started' || item.type === 'subscription_renewed') {
            return safe(item.detail?.sentence, safe(item.subtitle, `${name} için abonelik hareketi`));
        }
        return safe(item.title, 'Aktivite');
    }

    function activityKey(item) {
        return [item.type, item.created_at, item.user?.id, item.detail?.question_id, item.detail?.quiz_date, item.detail?.question_code].join('|');
    }

    function scoreLabel(percent) {
        if (percent >= 90) return 'Mükemmel Seri';
        if (percent >= 70) return 'Güçlü Performans';
        if (percent >= 50) return 'Dengeli İlerleme';
        return 'Tekrar Faydalı Olur';
    }

    function buildInfoGrid(items = []) {
        return `<div class="activity-info-grid">${items.map(([k, v]) => `
            <div class="activity-info-item"><span>${k}</span><strong>${safe(v)}</strong></div>
        `).join('')}</div>`;
    }

    function renderQuestionOptions(detail = {}) {
        const correct = normalizeAnswer(detail.correct_answer);
        const selected = normalizeAnswer(detail.selected_answer);
        const options = [
            ['A', detail.option_a],
            ['B', detail.option_b],
            ['C', detail.option_c],
            ['D', detail.option_d],
            ['E', detail.option_e]
        ].filter(([letter, text]) => letter !== 'E' || (text !== null && text !== undefined && String(text).trim() !== ''));

        if (!options.length) {
            return '<div class="activity-option-list"><div class="activity-option-item">Şık bilgisi bulunamadı</div></div>';
        }

        return `<div class="activity-option-list">${options.map(([letter, text]) => {
            const isCorrect = correct === letter;
            const isSelected = selected === letter;
            let cls = 'activity-option-item';
            if (isCorrect && isSelected) cls += ' activity-option-selected-correct';
            else if (isCorrect) cls += ' activity-option-correct';
            else if (isSelected && !isCorrect) cls += ' activity-option-selected-wrong';
            return `<div class="${cls}"><span>${letter})</span><p>${safe(text, 'Seçenek metni yok')}</p></div>`;
        }).join('')}</div>`;
    }

    function renderSolvedQuestionDetail(activity) {
        const isCorrect = activity.detail?.is_correct === true;
        const correctAnswer = normalizeAnswer(activity.detail?.correct_answer);
        const selectedAnswer = normalizeAnswer(activity.detail?.selected_answer);
        return `
            <div class="activity-detail-modal">
                <div class="activity-detail-head">
                    <i class="bi bi-bullseye"></i>
                    <div><h6>Soru Çözüm Detayı</h6><p>${safe(activity.user?.full_name, safe(activity.user?.email))} tarafından çözüldü</p></div>
                </div>
                <div class="activity-badges">
                    <span class="activity-stat-pill ${isCorrect ? 'activity-result-success' : 'activity-result-danger'}">${isCorrect ? 'Doğru' : 'Yanlış'}</span>
                    <span class="activity-stat-pill">${activity.user?.user_type === 'guest' ? 'Guest' : 'Kayıtlı'}</span>
                    <span class="activity-stat-pill">Soru Çözümü</span>
                    <span class="activity-stat-pill">${isCorrect ? '+1 isabet' : 'Gelişim alanı'}</span>
                </div>
                ${buildInfoGrid([
                    ['Kullanıcı', safe(activity.user?.full_name)],
                    ['Email', safe(activity.user?.email)],
                    ['Yeterlilik', safe(activity.detail?.qualification_name)],
                    ['Ders', safe(activity.detail?.course_name)],
                    ['Soru ID', safe(activity.detail?.question_id)],
                    ['Soru Kodu', safe(activity.detail?.question_code, 'Kod yok')],
                    ['Çözüm Zamanı', formatDateTime(activity.detail?.attempted_at || activity.created_at)]
                ])}
                <div class="activity-question-block">
                    <h6 class="mb-2">Soru Detayı</h6>
                    <div class="activity-question-text">${safe(activity.detail?.question_text, 'Soru metni bulunamadı')}</div>
                    ${renderQuestionOptions(activity.detail || {})}
                    <div class="activity-answer-summary">
                        <span class="activity-stat-pill">Doğru cevap: ${safe(correctAnswer, 'Doğru cevap bilgisi yok')}</span>
                        <span class="activity-stat-pill">Kullanıcı seçimi: ${safe(selectedAnswer, 'Seçim kaydı yok')}</span>
                    </div>
                </div>
                <div class="activity-score-box ${isCorrect ? 'activity-result-success' : 'activity-result-danger'}">
                    <strong>${isCorrect ? 'İsabetli çözüm' : 'Seçim doğru cevaptan farklı'}</strong>
                    <small>${isCorrect ? 'Harika! Doğru seçenek işaretlendi.' : 'Kısa tekrar ile benzer sorularda isabet artabilir.'}</small>
                </div>
            </div>`;
    }

    function renderRegistrationDetail(activity) {
        const isGuest = activity.user?.user_type === 'guest';
        return `
            <div class="activity-detail-modal">
                <div class="activity-detail-head">
                    <i class="bi bi-person-plus"></i>
                    <div><h6>Yeni Kullanıcı Kaydı</h6><p>Sisteme yeni bir kullanıcı katıldı</p></div>
                </div>
                <div class="activity-badges">
                    <span class="activity-stat-pill">${isGuest ? 'Guest' : 'Kayıtlı'}</span>
                    <span class="activity-stat-pill">Aktif kayıt</span>
                    <span class="activity-stat-pill">Yeni üye</span>
                </div>
                ${buildInfoGrid([
                    ['Ad Soyad', safe(activity.user?.full_name)],
                    ['Email', safe(activity.user?.email)],
                    ['Kullanıcı Tipi', isGuest ? 'Guest' : 'Kayıtlı'],
                    ['Kayıt Zamanı', formatDateTime(activity.detail?.registration_at || activity.created_at)],
                    ['Yeterlilik', safe(activity.detail?.qualification_name)],
                    ['Email Durumu', activity.detail?.email_verified === true ? 'Doğrulandı' : 'Doğrulanmamış / Bilinmiyor']
                ])}
                <div class="activity-score-box">
                    <strong>Yeni katılım</strong>
                    <small>${isGuest ? 'Kullanıcı şu an deneme aşamasında.' : 'Platform büyümeye devam ediyor.'}</small>
                </div>
            </div>`;
    }

    function renderDailyQuizDetail(activity) {
        const total = Number(activity.detail?.total_count || 0);
        const correct = Number(activity.detail?.correct_count || 0);
        const wrong = Number(activity.detail?.wrong_count || 0);
        const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
        const isCompleted = activity.detail?.completed === true;
        const qList = Array.isArray(activity.detail?.questions) ? activity.detail.questions : [];
        return `
            <div class="activity-detail-modal">
                <div class="activity-detail-head">
                    <i class="bi bi-lightning-charge"></i>
                    <div><h6>Daily Quiz Tamamlandı</h6><p>Kullanıcının günlük quiz performansı</p></div>
                </div>
                <div class="activity-badges">
                    <span class="activity-stat-pill ${isCompleted ? 'activity-result-success' : 'activity-result-danger'}">${isCompleted ? 'Tamamlandı' : 'Yarım kaldı'}</span>
                    <span class="activity-stat-pill">${activity.user?.user_type === 'guest' ? 'Guest' : 'Kayıtlı'}</span>
                    <span class="activity-stat-pill">Daily Quiz</span>
                </div>
                <div class="activity-quiz-layout">
                    <div class="activity-quiz-summary">
                        ${buildInfoGrid([
                            ['Kullanıcı', safe(activity.user?.full_name)],
                            ['Email', safe(activity.user?.email)],
                            ['Quiz Tarihi', safe(activity.detail?.quiz_date)],
                            ['Doğru Sayısı', correct],
                            ['Yanlış Sayısı', wrong],
                            ['Toplam Soru', total],
                            ['Başarı Oranı', `%${percent}`],
                            ['Durum', isCompleted ? 'Tamamlandı' : 'Yarım kaldı'],
                            ['Performans', scoreLabel(percent)],
                            ['Aktivite Zamanı', formatDateTime(activity.created_at)]
                        ])}
                        <div class="activity-score-box">
                            <strong>%${percent} · ${scoreLabel(percent)}</strong>
                            <div class="activity-progress"><span style="width:${percent}%"></span></div>
                        </div>
                    </div>
                    <div class="activity-quiz-questions">
                        <h6>Çözülen Sorular</h6>
                        ${qList.length ? qList.map(q => `
                            <div class="activity-quiz-question-item">
                                <div class="activity-quiz-question-meta">
                                    <strong>#${safe(q.order_no, '-')}</strong>
                                    <span>${safe(q.question_code, 'Kod yok')}</span>
                                    <span class="activity-quiz-question-result ${q.is_correct ? 'activity-result-success' : 'activity-result-danger'}">${q.is_correct ? 'Doğru' : 'Yanlış'}</span>
                                </div>
                                <div class="small text-muted" title="${safe(q.question_text, 'Soru metni bulunamadı')}">${safe(q.question_text, 'Soru metni bulunamadı')}</div>
                                <div class="small">Seçim: <strong>${safe(normalizeAnswer(q.selected_answer), 'Seçim kaydı yok')}</strong> · Doğru: <strong>${safe(normalizeAnswer(q.correct_answer), 'Doğru cevap bilgisi yok')}</strong></div>
                            </div>
                        `).join('') : '<div class="text-muted small">Bu quiz için soru listesi detayı bulunamadı</div>'}
                    </div>
                </div>
            </div>`;
    }

    function renderSubscriptionDetail(activity) {
        const isRenewal = activity.type === 'subscription_renewed';
        const eventTypeMap = {
            INITIAL_PURCHASE: 'İlk Satın Alma',
            RENEWAL: 'Yenileme'
        };
        const eventTypeLabel = eventTypeMap[String(activity.detail?.event_type || '').toUpperCase()] || safe(activity.detail?.event_type);
        const planLabel = safe(activity.detail?.plan_duration_label, safe(activity.detail?.plan_code, 'Bilinmeyen Plan'));
        return `
            <div class="activity-detail-modal">
                <div class="activity-detail-head">
                    <i class="bi ${isRenewal ? 'bi-arrow-repeat' : 'bi-gem'}"></i>
                    <div><h6>${isRenewal ? 'Abonelik Yenileme' : 'Yeni Abonelik'} Detayı</h6><p>${safe(activity.detail?.sentence, 'Abonelik olayı')}</p></div>
                </div>
                <div class="activity-badges">
                    <span class="activity-stat-pill">${isRenewal ? 'Yenileme' : 'Yeni Abonelik'}</span>
                    <span class="activity-stat-pill">Plan: ${planLabel}</span>
                </div>
                ${buildInfoGrid([
                    ['Kullanıcı', safe(activity.user?.full_name)],
                    ['Email', safe(activity.user?.email)],
                    ['Plan', planLabel],
                    ['Mağaza', safe(activity.detail?.store)],
                    ['Entitlement', safe(activity.detail?.entitlement_id)],
                    ['Olay Tipi', eventTypeLabel],
                    ['Tarih', formatDateTime(activity.detail?.event_at || activity.created_at)],
                    ['Provider', safe(activity.detail?.provider)],
                    ['Source', safe(activity.detail?.source)],
                    ['Plan Kodu', safe(activity.detail?.plan_code)]
                ])}
            </div>`;
    }

    function renderActivityDetail(activity) {
        if (!activity) return '<div class="activity-detail-modal"><p>Detay bulunamadı.</p></div>';
        if (activity.type === 'solved_questions') return renderSolvedQuestionDetail(activity);
        if (activity.type === 'registrations') return renderRegistrationDetail(activity);
        if (activity.type === 'daily_quiz') return renderDailyQuizDetail(activity);
        if (activity.type === 'subscription_started' || activity.type === 'subscription_renewed') return renderSubscriptionDetail(activity);
        return `
            <div class="activity-detail-modal">
                <div class="activity-detail-head"><i class="bi bi-activity"></i><div><h6>Aktivite Detayı</h6><p>Bu aktivite için özel görünüm bulunamadı.</p></div></div>
                ${buildInfoGrid([['Tip', safe(activity.type)], ['Zaman', formatDateTime(activity.created_at)]])}
            </div>`;
    }

    function openActivityModal(item) {
        qs('#activityDetailTitle').textContent = item.title || 'Aktivite Detayı';
        qs('#activityDetailBody').innerHTML = renderActivityDetail(item);
        const modal = bootstrap.Modal.getOrCreateInstance(qs('#activityDetailModal'));
        modal.show();
    }

    async function loadActivities() {
        if (activityInFlight) return;
        activityInFlight = true;

        const listEl = qs('#activityList');
        const errEl = qs('#activityError');
        const emptyEl = qs('#activityEmpty');
        errEl.classList.add('d-none');
        if (!firstActivitiesLoaded) {
            listEl.classList.add('is-loading');
        }

        try {
            const params = {
                scope: 'admin',
                limit: dashboardState.activity.limit,
                types: dashboardState.activity.types.join(',')
            };
            const data = await jsonGet('/api/v1/dashboard/recent_activity.php', params);
            console.log('activities:', data);
            const rows = data.activities || [];
            const signature = rows.map(activityKey).join('||');
            if (signature === lastActivitySignature) {
                setActivityRefreshInfo();
                return;
            }
            lastActivitySignature = signature;

            activityMap.clear();
            if (!rows.length) {
                emptyEl.classList.remove('d-none');
                return;
            }
            emptyEl.classList.add('d-none');

            const oldNodes = new Map(Array.from(listEl.querySelectorAll('.activity-row')).map((el) => [el.dataset.activityKey, el]));
            const fragment = document.createDocumentFragment();

            rows.forEach((item) => {
                const key = activityKey(item);
                activityMap.set(key, item);
                const hash = JSON.stringify([item.type, item.title, item.subtitle, item.created_at, item.user?.full_name, item.user?.email, item.detail?.is_correct, item.detail?.correct_count, item.detail?.total_count]);
                let row = oldNodes.get(key);
                if (!row) {
                    row = document.createElement('div');
                    row.className = 'activity-row';
                    row.dataset.activityKey = key;
                }
                if (row.dataset.hash !== hash) {
                    row.innerHTML = `
                    <div class="activity-icon"><i class="bi ${activityIcon(item.type)}"></i></div>
                    <div class="activity-row-main">
                        <div class="activity-title-row">
                            <span class="activity-type-badge">${activityTypeLabel(item.type)}</span>
                            <strong class="text-truncate">${activitySentence(item)}</strong>
                        </div>
                        <div class="small text-muted text-truncate">${item.subtitle || ''}</div>
                        <div class="activity-timestamp">${formatDateTime(item.created_at)} · ${(item.user?.full_name || item.user?.email || '-')}</div>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary activity-view-btn" data-activity-key="${key}" title="Detayı Gör">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>`;
                    row.dataset.hash = hash;
                }

                oldNodes.delete(key);
                fragment.appendChild(row);
            });

            oldNodes.forEach((node) => node.remove());
            listEl.replaceChildren(fragment);
            setActivityRefreshInfo();
        } catch (e) {
            errEl.textContent = e.message;
            errEl.classList.remove('d-none');
        } finally {
            if (!firstActivitiesLoaded) {
                listEl.classList.remove('is-loading');
                firstActivitiesLoaded = true;
            }
            activityInFlight = false;
        }
    }

    function renderChartTotals(totals = {}) {
        const box = qs('#chartTotals');
        const items = [
            ['Toplam Kayıt', totals.registrations || 0],
            ['Toplam 1 Aylık Abonelik', totals.subscription_monthly || 0],
            ['Toplam 3 Aylık Abonelik', totals.subscription_quarterly || 0],
            ['Toplam 6 Aylık Abonelik', totals.subscription_semiannual || 0],
            ['Toplam Yıllık Abonelik', totals.subscription_annual || 0]
        ];
        box.innerHTML = items.map(([k, v]) => `<div class="col-6 col-lg-4"><div class="chart-total-box"><small>${k}</small><strong>${formatNumber(v)}</strong></div></div>`).join('');
    }

    async function loadChart() {
        if (trendsInFlight) return;
        trendsInFlight = true;
        setMsg('#chartMsg');
        const params = {
            scope: 'admin',
            range: dashboardState.chart.range,
            start_date: dashboardState.chart.start_date,
            end_date: dashboardState.chart.end_date,
            types: dashboardState.chart.types.join(',')
        };

        try {
            const data = await jsonGet('/api/v1/dashboard/trends.php', params);
            console.log('trends:', data);
            const trends = data.trends || {};
            const labels = trends.labels || [];
            const series = trends.series || {};
            const signature = JSON.stringify([labels, series, trends.totals || {}]);
            if (signature === lastChartSignature) {
                setRefreshNote('#chartRefreshInfo');
                return;
            }
            lastChartSignature = signature;
            renderChartTotals(trends.totals || {});

            const datasetsMeta = [
                { key: 'registrations', label: 'Kayıt Olan Kullanıcılar', color: '#5B9BD5', dash: [] },
                { key: 'subscription_monthly', label: '1 Aylık Abonelikler', color: '#2F9E44', dash: [] },
                { key: 'subscription_quarterly', label: '3 Aylık Abonelikler', color: '#D97706', dash: [4, 3] },
                { key: 'subscription_semiannual', label: '6 Aylık Abonelikler', color: '#8A63D2', dash: [] },
                { key: 'subscription_annual', label: 'Yıllık Abonelikler', color: '#C89B54', dash: [6, 4] },
            ];

            const datasets = datasetsMeta
                .filter(d => dashboardState.chart.types.includes(d.key))
                .map(d => ({
                    label: d.label,
                    data: series[d.key] || [],
                    borderColor: d.color,
                    backgroundColor: d.color,
                    borderWidth: 2,
                    fill: false,
                    tension: 0.25,
                    pointRadius: 3,
                    pointHoverRadius: 4,
                    borderDash: d.dash,
                }));

            const ctx = qs('#activityLineChart').getContext('2d');
            if (!activityChart) {
                activityChart = new Chart(ctx, {
                    type: 'line',
                    data: { labels, datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: false,
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            } else {
                activityChart.data.labels = labels;
                activityChart.data.datasets = datasets;
                activityChart.update('none');
            }
            setRefreshNote('#chartRefreshInfo');
        } catch (e) {
            setMsg('#chartMsg', e.message, 'error');
        } finally {
            if (!firstChartLoaded) firstChartLoaded = true;
            trendsInFlight = false;
        }
    }

    function startPolling() {
        if (dashboardState.polling.timer) clearInterval(dashboardState.polling.timer);
        dashboardState.polling.timer = setInterval(() => {
            if (document.hidden) return;
            loadStatistics();
            loadActivities();
            loadChart();
        }, dashboardState.polling.interval);
    }

    function startSessionKeepAlive() {
        setInterval(() => {
            if (document.hidden) return;
            fetch('/api/v1/auth/keep_alive.php', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            }).catch(() => {});
        }, 60000);
    }

    function bindEvents() {
        qs('#qQualificationFilter').addEventListener('change', async (e) => {
            dashboardState.question.qualification_id = e.target.value;
            dashboardState.question.course_id = '';
            await loadFilters();
            await loadStatistics();
        });

        qs('#qCourseFilter').addEventListener('change', async (e) => {
            dashboardState.question.course_id = e.target.value;
            await loadStatistics();
        });

        initDateFilter('solved', dashboardState.solved, async () => {
            await loadStatistics();
        });

        qsa('#userTypeToggle button').forEach(btn => {
            btn.addEventListener('click', async () => {
                qsa('#userTypeToggle button').forEach(x => x.classList.remove('active'));
                btn.classList.add('active');
                dashboardState.users.user_type = btn.dataset.type;
                await loadStatistics();
            });
        });

        qsa('#activityTypes .chip').forEach(btn => {
            btn.addEventListener('click', async () => {
                btn.classList.toggle('active');
                const selected = getActiveTypes('#activityTypes');
                if (!selected.length) {
                    btn.classList.add('active');
                    return;
                }
                dashboardState.activity.types = selected;
                await loadActivities();
            });
        });

        qs('#activityLimit').addEventListener('change', async (e) => {
            dashboardState.activity.limit = Number(e.target.value);
            await loadActivities();
        });

        qs('#activityList').addEventListener('click', (e) => {
            const btn = e.target.closest('.activity-view-btn');
            if (!btn) return;
            const item = activityMap.get(btn.dataset.activityKey);
            if (item) openActivityModal(item);
        });

        initDateFilter('chart', dashboardState.chart, async () => {
            await loadChart();
        });

        qsa('#chartTypes .chip').forEach(btn => {
            btn.addEventListener('click', async () => {
                btn.classList.toggle('active');
                const selected = getActiveTypes('#chartTypes');
                if (!selected.length) {
                    btn.classList.add('active');
                    return;
                }
                dashboardState.chart.types = selected;
                await loadChart();
            });
        });
    }

    async function init() {
        bindEvents();
        await loadFilters();
        await Promise.all([
            loadStatistics(),
            loadActivities(),
            loadChart()
        ]);
        startPolling();
        startSessionKeepAlive();

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                loadStatistics();
                loadActivities();
                loadChart();
            }
        });
    }

    init();
})();
</script>

<?php include 'includes/footer.php'; ?>

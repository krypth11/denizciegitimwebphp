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
                            <select class="form-select form-select-sm" id="qCourseFilter">
                                <option value="">Tümü</option>
                            </select>
                        </div>
                    </div>
                    <div class="dashboard-metric-value" id="totalQuestionsValue">-</div>
                    <small class="text-muted" id="totalQuestionsHint">Filtreye göre toplam soru adedi</small>
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
                    <div class="row g-2">
                        <div class="col-12 col-md-4 col-lg-12 col-xl-4">
                            <label class="form-label mb-1">Hazır Aralık</label>
                            <select class="form-select form-select-sm" id="solvedRangeFilter">
                                <option value="1d">1 gün</option>
                                <option value="3d">3 gün</option>
                                <option value="7d" selected>7 gün</option>
                                <option value="15d">15 gün</option>
                                <option value="30d">30 gün</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-4 col-lg-6 col-xl-4">
                            <label class="form-label mb-1">Başlangıç</label>
                            <input type="date" class="form-control form-control-sm" id="solvedStartDate">
                        </div>
                        <div class="col-6 col-md-4 col-lg-6 col-xl-4">
                            <label class="form-label mb-1">Bitiş</label>
                            <input type="date" class="form-control form-control-sm" id="solvedEndDate">
                        </div>
                    </div>
                    <div class="dashboard-metric-value" id="solvedQuestionsValue">-</div>
                    <small class="text-muted" id="solvedQuestionsHint">Seçili zaman aralığındaki toplam attempt</small>
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
                    <div class="widget-message" id="totalUsersMsg"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card dashboard-widget mb-3">
        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
            <div>
                <h5 class="mb-0">Son Aktiviteler</h5>
                <small class="text-muted" id="activityRefreshInfo">Otomatik yenileme: 25sn</small>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="dashboard-chip-group" id="activityTypes">
                    <button type="button" class="chip active" data-type="registrations">Kaydolan Kullanıcılar</button>
                    <button type="button" class="chip active" data-type="daily_quiz">Daily Quiz</button>
                    <button type="button" class="chip active" data-type="solved_questions">Çözülen Sorular</button>
                    <button type="button" class="chip active" data-type="profile_updates">Profil İşlemleri</button>
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
                <small class="text-muted">Filtreler değişince otomatik güncellenir</small>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <select class="form-select form-select-sm w-auto" id="chartRangeFilter">
                    <option value="1d">1 gün</option>
                    <option value="3d">3 gün</option>
                    <option value="7d" selected>7 gün</option>
                    <option value="15d">15 gün</option>
                    <option value="30d">30 gün</option>
                </select>
                <input type="date" class="form-control form-control-sm" id="chartStartDate" style="max-width: 150px;">
                <input type="date" class="form-control form-control-sm" id="chartEndDate" style="max-width: 150px;">
            </div>
        </div>
        <div class="card-body">
            <div class="dashboard-chip-group mb-3" id="chartTypes">
                <button type="button" class="chip active" data-type="registrations">Kaydolan Kullanıcılar</button>
                <button type="button" class="chip active" data-type="solved_questions">Çözülen Sorular</button>
                <button type="button" class="chip active" data-type="daily_quiz_completed">Daily Quiz Tamamlananlar</button>
                <button type="button" class="chip active" data-type="profile_updates">Profil İşlemleri</button>
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
                <pre class="mb-0 small" id="activityDetailBody" style="white-space: pre-wrap;"></pre>
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
        activity: { types: ['registrations', 'daily_quiz', 'solved_questions', 'profile_updates'], limit: 25 },
        chart: { range: '7d', start_date: '', end_date: '', types: ['registrations', 'solved_questions', 'daily_quiz_completed', 'profile_updates'] },
        refs: { qualifications: [], courses: [] },
        polling: { timer: null, busy: false }
    };

    let activityChart = null;
    const activityMap = new Map();

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

    function getActiveTypes(containerId) {
        return qsa(`${containerId} .chip.active`).map(x => x.dataset.type);
    }

    async function loadFilters() {
        const data = await jsonGet('/api/v1/dashboard/details.php', { scope: 'admin', qualification_id: dashboardState.question.qualification_id || '' });
        dashboardState.refs.qualifications = data.details?.qualifications || [];
        dashboardState.refs.courses = data.details?.courses || [];

        const qSelect = qs('#qQualificationFilter');
        const cSelect = qs('#qCourseFilter');
        qSelect.innerHTML = '<option value="">Tümü</option>';
        cSelect.innerHTML = '<option value="">Tümü</option>';

        dashboardState.refs.qualifications.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.name;
            qSelect.appendChild(opt);
        });

        dashboardState.refs.courses.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.name;
            cSelect.appendChild(opt);
        });

        qSelect.value = dashboardState.question.qualification_id;
        cSelect.value = dashboardState.question.course_id;
    }

    async function loadStatsCard(mode) {
        const params = { scope: 'admin' };

        if (mode === 'questions') {
            setWidgetLoading('#cardTotalQuestions', true);
            setMsg('#totalQuestionsMsg');
            params.qualification_id = dashboardState.question.qualification_id;
            params.course_id = dashboardState.question.course_id;
        } else if (mode === 'solved') {
            setWidgetLoading('#cardSolvedQuestions', true);
            setMsg('#solvedQuestionsMsg');
            params.solved_range = dashboardState.solved.range;
            params.solved_start_date = dashboardState.solved.start_date;
            params.solved_end_date = dashboardState.solved.end_date;
        } else if (mode === 'users') {
            setWidgetLoading('#cardTotalUsers', true);
            setMsg('#totalUsersMsg');
            params.user_type = dashboardState.users.user_type;
        }

        try {
            const data = await jsonGet('/api/v1/dashboard/statistics.php', params);
            console.log('statistics:', data);
            const s = data.statistics || {};
            if (mode === 'questions') {
                qs('#totalQuestionsValue').textContent = formatNumber(s.total_questions || 0);
            } else if (mode === 'solved') {
                qs('#solvedQuestionsValue').textContent = formatNumber(s.solved_questions_count || 0);
            } else if (mode === 'users') {
                qs('#totalUsersValue').textContent = formatNumber(s.total_users || 0);
            }
        } catch (e) {
            if (mode === 'questions') setMsg('#totalQuestionsMsg', e.message, 'error');
            if (mode === 'solved') setMsg('#solvedQuestionsMsg', e.message, 'error');
            if (mode === 'users') setMsg('#totalUsersMsg', e.message, 'error');
        } finally {
            if (mode === 'questions') setWidgetLoading('#cardTotalQuestions', false);
            if (mode === 'solved') setWidgetLoading('#cardSolvedQuestions', false);
            if (mode === 'users') setWidgetLoading('#cardTotalUsers', false);
        }
    }

    function activityTypeLabel(type) {
        const map = {
            registrations: 'Kayıt',
            daily_quiz: 'Daily Quiz',
            solved_questions: 'Soru Çözümü',
            profile_updates: 'Profil'
        };
        return map[type] || type;
    }

    function openActivityModal(item) {
        qs('#activityDetailTitle').textContent = item.title || 'Aktivite Detayı';
        qs('#activityDetailBody').textContent = JSON.stringify(item, null, 2);
        const modal = bootstrap.Modal.getOrCreateInstance(qs('#activityDetailModal'));
        modal.show();
    }

    async function loadActivities() {
        if (dashboardState.polling.busy) return;
        dashboardState.polling.busy = true;

        const listEl = qs('#activityList');
        const errEl = qs('#activityError');
        const emptyEl = qs('#activityEmpty');
        errEl.classList.add('d-none');
        listEl.classList.add('is-loading');

        try {
            const params = {
                scope: 'admin',
                limit: dashboardState.activity.limit,
                types: dashboardState.activity.types.join(',')
            };
            const data = await jsonGet('/api/v1/dashboard/recent_activity.php', params);
            console.log('activities:', data);
            const rows = data.activities || [];

            activityMap.clear();
            listEl.innerHTML = '';
            if (!rows.length) {
                emptyEl.classList.remove('d-none');
                return;
            }
            emptyEl.classList.add('d-none');

            rows.forEach((item, idx) => {
                const id = `act-${idx}-${Date.now()}`;
                activityMap.set(id, item);
                const row = document.createElement('div');
                row.className = 'activity-row';
                row.innerHTML = `
                    <div class="activity-row-main">
                        <div class="activity-title-row">
                            <span class="activity-type-badge">${activityTypeLabel(item.type)}</span>
                            <strong class="text-truncate">${item.title || '-'}</strong>
                        </div>
                        <div class="small text-muted text-truncate">${item.subtitle || ''}</div>
                        <div class="small text-muted">${formatDateTime(item.created_at)} · ${(item.user?.full_name || item.user?.email || '-')}</div>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary activity-view-btn" data-activity-id="${id}" title="Detayı Gör">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>`;
                listEl.appendChild(row);
            });
        } catch (e) {
            errEl.textContent = e.message;
            errEl.classList.remove('d-none');
        } finally {
            listEl.classList.remove('is-loading');
            dashboardState.polling.busy = false;
        }
    }

    function renderChartTotals(totals = {}) {
        const box = qs('#chartTotals');
        const items = [
            ['Toplam Kayıt', totals.registrations || 0],
            ['Toplam Çözülen Soru', totals.solved_questions || 0],
            ['Toplam Daily Quiz', totals.daily_quiz_completed || 0],
            ['Toplam Profil İşlemi', totals.profile_updates || 0]
        ];
        box.innerHTML = items.map(([k, v]) => `<div class="col-6 col-lg-3"><div class="chart-total-box"><small>${k}</small><strong>${formatNumber(v)}</strong></div></div>`).join('');
    }

    async function loadChart() {
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
            renderChartTotals(trends.totals || {});

            const datasetsMeta = [
                { key: 'registrations', label: 'Kayıt', color: '#5B9BD5', dash: [] },
                { key: 'solved_questions', label: 'Çözülen Sorular', color: '#6AA786', dash: [] },
                { key: 'daily_quiz_completed', label: 'Daily Quiz', color: '#C89B54', dash: [6, 4] },
                { key: 'profile_updates', label: 'Profil İşlemleri', color: '#B86B7F', dash: [4, 4] },
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
            if (activityChart) activityChart.destroy();
            activityChart = new Chart(ctx, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        } catch (e) {
            setMsg('#chartMsg', e.message, 'error');
        }
    }

    function startPolling() {
        if (dashboardState.polling.timer) clearInterval(dashboardState.polling.timer);
        dashboardState.polling.timer = setInterval(loadActivities, 25000);
    }

    function bindEvents() {
        qs('#qQualificationFilter').addEventListener('change', async (e) => {
            dashboardState.question.qualification_id = e.target.value;
            dashboardState.question.course_id = '';
            await loadFilters();
            await loadStatsCard('questions');
        });

        qs('#qCourseFilter').addEventListener('change', async (e) => {
            dashboardState.question.course_id = e.target.value;
            await loadStatsCard('questions');
        });

        qs('#solvedRangeFilter').addEventListener('change', async (e) => {
            dashboardState.solved.range = e.target.value;
            if (!dashboardState.solved.start_date || !dashboardState.solved.end_date) {
                dashboardState.solved.start_date = '';
                dashboardState.solved.end_date = '';
            }
            await loadStatsCard('solved');
        });

        ['#solvedStartDate', '#solvedEndDate'].forEach(id => {
            qs(id).addEventListener('change', async () => {
                dashboardState.solved.start_date = qs('#solvedStartDate').value;
                dashboardState.solved.end_date = qs('#solvedEndDate').value;
                await loadStatsCard('solved');
            });
        });

        qsa('#userTypeToggle button').forEach(btn => {
            btn.addEventListener('click', async () => {
                qsa('#userTypeToggle button').forEach(x => x.classList.remove('active'));
                btn.classList.add('active');
                dashboardState.users.user_type = btn.dataset.type;
                await loadStatsCard('users');
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
            const item = activityMap.get(btn.dataset.activityId);
            if (item) openActivityModal(item);
        });

        qs('#chartRangeFilter').addEventListener('change', async (e) => {
            dashboardState.chart.range = e.target.value;
            if (!dashboardState.chart.start_date || !dashboardState.chart.end_date) {
                dashboardState.chart.start_date = '';
                dashboardState.chart.end_date = '';
            }
            await loadChart();
        });

        ['#chartStartDate', '#chartEndDate'].forEach(id => {
            qs(id).addEventListener('change', async () => {
                dashboardState.chart.start_date = qs('#chartStartDate').value;
                dashboardState.chart.end_date = qs('#chartEndDate').value;
                await loadChart();
            });
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
            loadStatsCard('questions'),
            loadStatsCard('solved'),
            loadStatsCard('users'),
            loadActivities(),
            loadChart()
        ]);
        startPolling();
    }

    init();
})();
</script>

<?php include 'includes/footer.php'; ?>

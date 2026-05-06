<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'rewarded-ad-stats';
$page_title = 'Reklam İstatistikleri';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid rewarded-stats-page">
    <div class="rewarded-hero mb-3">
        <div>
            <h2 class="mb-1">Reklam İstatistikleri</h2>
            <p class="text-muted mb-0">Ödüllü reklam performansı, kullanıcı kazanımları ve platform kırılımı.</p>
        </div>
        <span class="badge rounded-pill text-bg-light hero-badge">Rewarded Ads</span>
    </div>

    <div class="card soft-card rewarded-filter-card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-lg-2">
                    <label class="form-label">Tarih Aralığı</label>
                    <select id="range" class="form-select">
                        <option value="today">Bugün</option><option value="7d" selected>7 Gün</option><option value="30d">30 Gün</option><option value="90d">90 Gün</option><option value="custom">Özel</option>
                    </select>
                </div>
                <div class="col-6 col-lg-2"><label class="form-label">Başlangıç</label><input type="date" id="start_date" class="form-control" disabled></div>
                <div class="col-6 col-lg-2"><label class="form-label">Bitiş</label><input type="date" id="end_date" class="form-control" disabled></div>
                <div class="col-6 col-lg-2"><label class="form-label">Reklam Tipi</label><select id="reward_type" class="form-select"><option value="all">Tümü</option><option value="study">Çalışma Hakkı</option><option value="exam">Deneme Hakkı</option></select></div>
                <div class="col-6 col-lg-2"><label class="form-label">Platform</label><select id="platform" class="form-select"><option value="all">Tümü</option><option value="android">Android</option><option value="ios">iOS</option><option value="unknown">Bilinmeyen</option></select></div>
                <div class="col-12 col-lg-2"><label class="form-label">Kullanıcı Ara</label><input id="user_search" class="form-control" placeholder="Ad veya email"></div>
                <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary" id="refreshBtn"><i class="bi bi-arrow-repeat"></i> Yenile</button></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="kpiCards"></div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8"><div class="card soft-card rewarded-chart-card"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Günlük İzlenme Trendi</h6></div><div class="card-body"><div class="chart-wrap"><canvas id="dailyChart"></canvas></div></div></div></div>
        <div class="col-12 col-xl-4 d-flex flex-column gap-3">
            <div class="card soft-card rewarded-chart-card"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Çalışma vs Deneme</h6></div><div class="card-body"><div class="chart-wrap small"><canvas id="typeChart"></canvas></div></div></div>
            <div class="card soft-card rewarded-chart-card"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Platform Kırılımı</h6></div><div class="card-body"><div class="chart-wrap small"><canvas id="platformChart"></canvas></div></div></div>
        </div>
    </div>

    <div class="card soft-card rewarded-table-card mb-3"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Top Kullanıcılar</h6></div><div class="card-body table-responsive"><table class="table table-sm align-middle rewarded-dark-table"><thead><tr><th>Kullanıcı</th><th>Toplam</th><th>Çalışma</th><th>Deneme</th><th>Çalışma Kazanç</th><th>Deneme Kazanç</th><th>Son İzleme</th></tr></thead><tbody id="topUsersBody"><tr><td colspan="7" class="text-muted">Yükleniyor...</td></tr></tbody></table></div></div>

    <div class="card soft-card rewarded-table-card"><div class="card-header bg-transparent border-0 d-flex justify-content-between"><h6 class="mb-0">Son Reklam Olayları</h6><small id="paginationMeta" class="text-muted"></small></div><div class="card-body table-responsive"><table class="table table-sm align-middle rewarded-dark-table"><thead><tr><th>Tarih</th><th>Kullanıcı</th><th>Platform</th><th>Tip</th><th>Kazanım</th><th>IP</th></tr></thead><tbody id="eventsBody"><tr><td colspan="6" class="text-muted">Yükleniyor...</td></tr></tbody></table></div></div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function(){
 const endpoint='../ajax/rewarded-ad-stats.php';
 const esc=(t)=>$('<div>').text(t??'').html();
 const fmt=(v)=>window.formatDate?window.formatDate(v):v;
 let dailyChart=null,typeChart=null,platformChart=null;
 function colors(){return{grid:'rgba(148,163,184,.12)',text:'#cbd5e1'};}
 function params(){return{range:$('#range').val(),start_date:$('#start_date').val(),end_date:$('#end_date').val(),reward_type:$('#reward_type').val(),platform:$('#platform').val(),user_search:$('#user_search').val(),page:1,per_page:20};}
 function badge(v,t){return `<span class="badge ${t}">${esc(v)}</span>`;}
 function renderKpi(s){const items=[['Toplam İzlenme',s.total_watches],['Çalışma Reklamı',s.study_watches],['Deneme Reklamı',s.exam_watches],['Benzersiz Kullanıcı',s.unique_users],['Android / iOS',`${s.android_watches} / ${s.ios_watches}`],['Çalışma Hakkı Toplam',s.total_study_bonus],['Deneme Hakkı Toplam',s.total_exam_bonus]];$('#kpiCards').html(items.map(i=>`<div class="col-6 col-xl"><div class="card soft-card rewarded-kpi-card kpi"><div class="card-body"><div class="k-label">${esc(i[0])}</div><div class="k-value">${esc(i[1])}</div></div></div></div>`).join(''));}
 function renderCharts(ch){const c=colors();
  if(dailyChart)dailyChart.destroy(); if(typeChart)typeChart.destroy(); if(platformChart)platformChart.destroy();
  dailyChart=new Chart(document.getElementById('dailyChart'),{type:'bar',data:{labels:(ch.daily||[]).map(x=>x.date),datasets:[{label:'Toplam',data:(ch.daily||[]).map(x=>x.total),backgroundColor:'rgba(37,99,235,.45)',borderColor:'#2563eb'},{label:'Çalışma',data:(ch.daily||[]).map(x=>x.study),backgroundColor:'rgba(245,158,11,.35)',borderColor:'#f59e0b'},{label:'Deneme',data:(ch.daily||[]).map(x=>x.exam),backgroundColor:'rgba(16,185,129,.35)',borderColor:'#10b981'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:c.text}}},scales:{x:{ticks:{color:c.text},grid:{color:c.grid}},y:{ticks:{color:c.text,precision:0},grid:{color:c.grid}}}}});
  typeChart=new Chart(document.getElementById('typeChart'),{type:'doughnut',data:{labels:['Çalışma','Deneme'],datasets:[{data:[ch.type_distribution?.study||0,ch.type_distribution?.exam||0],backgroundColor:['#2563eb','#f59e0b']}]},options:{plugins:{legend:{labels:{color:c.text}}}}});
  platformChart=new Chart(document.getElementById('platformChart'),{type:'doughnut',data:{labels:['Android','iOS','Bilinmeyen'],datasets:[{data:[ch.platform_distribution?.android||0,ch.platform_distribution?.ios||0,ch.platform_distribution?.unknown||0],backgroundColor:['#10b981','#3b82f6','#94a3b8']}]},options:{plugins:{legend:{labels:{color:c.text}}}}});
 }
 function renderTop(users){if(!users.length){$('#topUsersBody').html('<tr><td colspan="7" class="text-center text-muted">Henüz reklam izleme kaydı yok.</td></tr>');return;}$('#topUsersBody').html(users.map(x=>`<tr><td><div class="fw-semibold">${esc(x.full_name||'-')}</div><small class="text-muted">${esc(x.email||'-')}</small></td><td>${x.total_watches}</td><td>${x.study_watches}</td><td>${x.exam_watches}</td><td>${x.total_study_bonus}</td><td>${x.total_exam_bonus}</td><td>${esc(fmt(x.last_watch_at))}</td></tr>`).join(''));}
 function renderEvents(events,p){if(!events.length){$('#eventsBody').html('<tr><td colspan="6" class="text-center text-muted">Henüz reklam izleme kaydı yok.</td></tr>');return;}$('#eventsBody').html(events.map(x=>`<tr><td>${esc(fmt(x.created_at))}</td><td><div class="fw-semibold">${esc(x.full_name)}</div><small class="text-muted">${esc(x.email)}</small></td><td>${badge(x.platform,x.platform==='android'?'text-bg-success':(x.platform==='ios'?'text-bg-primary':'text-bg-secondary'))}</td><td>${badge(x.reward_type,x.reward_type==='study'?'text-bg-warning':'text-bg-info')}</td><td>+${x.bonus_amount}</td><td><small>${esc(x.ip_address||'-')}</small></td></tr>`).join(''));$('#paginationMeta').text(`Sayfa ${p.page}/${p.total_pages} • Toplam ${p.total}`);}
 async function load(){ $('#eventsBody').html('<tr><td colspan="6" class="text-muted">Yükleniyor...</td></tr>'); const res=await window.appAjax({url:endpoint,method:'GET',data:params(),dataType:'json'}); if(!res.success){$('#eventsBody').html('<tr><td colspan="6" class="text-danger">Veri alınamadı.</td></tr>');return;} renderKpi(res.summary||{}); renderCharts(res.charts||{}); renderTop(res.top_users||[]); renderEvents(res.events||[],res.pagination||{}); }
 $('#range').on('change',function(){const custom=$(this).val()==='custom';$('#start_date,#end_date').prop('disabled',!custom);load();});
 $('#reward_type,#platform').on('change',load); $('#refreshBtn').on('click',load); let t=null; $('#user_search').on('input',function(){clearTimeout(t);t=setTimeout(load,400);});
 document.addEventListener('themechange',()=>setTimeout(load,80));
 load();
});
</script>
<style>
.rewarded-stats-page {
    max-width: 1480px;
    margin: 0 auto;
    color: #f8fafc;
}

.rewarded-stats-page .text-muted {
    color: #9fb0c7 !important;
}

.rewarded-stats-page .soft-card {
    background: linear-gradient(180deg, rgba(19, 35, 58, 0.98), rgba(10, 20, 35, 0.98));
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 16px;
    box-shadow: 0 14px 28px rgba(2, 8, 23, 0.35);
    overflow: hidden;
}

.rewarded-hero {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 22px 24px;
    border-radius: 18px;
    border: 1px solid rgba(212, 160, 23, 0.28);
    background: linear-gradient(135deg, #13233a 0%, #174ea6 55%, #d4a017 130%);
    box-shadow: 0 16px 32px rgba(5, 12, 28, 0.45);
    color: #f8fafc;
    overflow: hidden;
}

.rewarded-hero::before {
    content: "";
    position: absolute;
    inset: -35% auto auto -12%;
    width: 420px;
    height: 280px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.22), transparent 62%);
    pointer-events: none;
}

.rewarded-hero > * {
    position: relative;
    z-index: 1;
}

.rewarded-hero h2 {
    color: #f8fafc;
    font-weight: 700;
    letter-spacing: 0.2px;
}

.rewarded-hero p {
    color: #d3e2f5 !important;
}

.hero-badge {
    border: 1px solid rgba(212, 160, 23, 0.45);
    background: rgba(212, 160, 23, 0.16) !important;
    color: #fff7db !important;
    font-weight: 600;
    padding: 8px 12px;
}

.rewarded-filter-card .card-body {
    padding: 14px;
}

.rewarded-filter-card .form-label {
    color: #9fb0c7;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
}

.rewarded-filter-card .form-control,
.rewarded-filter-card .form-select {
    color: #f8fafc;
    background: #0b1626;
    border: 1px solid rgba(148, 163, 184, 0.24);
}

.rewarded-filter-card .form-control::placeholder {
    color: #7f93ad;
}

.rewarded-filter-card .form-control:focus,
.rewarded-filter-card .form-select:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.2);
}

.rewarded-filter-card .form-control:disabled,
.rewarded-filter-card .form-select:disabled {
    color: #cbd5e1;
    background: #07111f;
    border-color: rgba(148, 163, 184, 0.2);
    opacity: 1;
}

.rewarded-filter-card #refreshBtn {
    border: none;
    background: linear-gradient(135deg, #2563eb, #3b82f6);
    color: #eff6ff;
    font-weight: 600;
    padding: 10px 14px;
}

.rewarded-kpi-card {
    position: relative;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.rewarded-kpi-card::before {
    content: "";
    position: absolute;
    left: 14px;
    right: 14px;
    top: 0;
    height: 3px;
    border-radius: 0 0 12px 12px;
    background: linear-gradient(90deg, #3b82f6 0%, #7c3aed 55%, #d4a017 100%);
}

.rewarded-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 32px rgba(2, 8, 23, 0.42);
}

.kpi .card-body {
    padding-top: 16px;
}

.kpi .k-label {
    font-size: 12px;
    color: #9fb0c7;
    margin-bottom: 6px;
}

.kpi .k-value {
    font-size: 24px;
    font-weight: 700;
    line-height: 1.2;
    color: #f8fafc;
}

.rewarded-chart-card .card-header,
.rewarded-table-card .card-header {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.12) !important;
}

.rewarded-chart-card .card-header h6,
.rewarded-table-card .card-header h6 {
    color: #f8fafc;
    font-weight: 600;
}

.rewarded-chart-card .card-body,
.rewarded-table-card .card-body {
    padding: 14px 16px;
}

.chart-wrap {
    position: relative;
    height: 320px;
    min-height: 220px;
}

.chart-wrap.small {
    height: 220px;
    min-height: 200px;
}

.rewarded-dark-table {
    margin-bottom: 0;
    color: #d8e3f1;
}

.rewarded-dark-table thead th {
    background: rgba(23, 78, 166, 0.22);
    color: #e8f0fb;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    border-top: 0;
    font-weight: 600;
}

.rewarded-dark-table > :not(caption) > * > * {
    border-color: rgba(148, 163, 184, 0.12);
}

.rewarded-dark-table tbody td {
    color: #cbd5e1;
    background: transparent;
}

.rewarded-dark-table tbody tr:hover td {
    background: rgba(59, 130, 246, 0.08);
}

.rewarded-dark-table .fw-semibold {
    color: #f8fafc;
}

.rewarded-stats-page .badge.text-bg-success {
    background: rgba(16, 185, 129, 0.2) !important;
    color: #7fffd0 !important;
}

.rewarded-stats-page .badge.text-bg-primary {
    background: rgba(59, 130, 246, 0.2) !important;
    color: #bfdbfe !important;
}

.rewarded-stats-page .badge.text-bg-secondary {
    background: rgba(148, 163, 184, 0.22) !important;
    color: #dbe4f1 !important;
}

.rewarded-stats-page .badge.text-bg-warning {
    background: rgba(212, 160, 23, 0.22) !important;
    color: #ffe6a6 !important;
}

.rewarded-stats-page .badge.text-bg-info {
    background: rgba(124, 58, 237, 0.22) !important;
    color: #ddd6fe !important;
}

@media (max-width: 991.98px) {
    .rewarded-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .rewarded-filter-card .row > [class*="col-"] {
        margin-bottom: 2px;
    }

    .chart-wrap {
        height: 280px;
    }

    .chart-wrap.small {
        height: 220px;
    }
}

@media (max-width: 767.98px) {
    .rewarded-stats-page .kpi .k-value {
        font-size: 20px;
    }

    .rewarded-filter-card .card-body,
    .rewarded-chart-card .card-body,
    .rewarded-table-card .card-body {
        padding: 12px;
    }
}
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

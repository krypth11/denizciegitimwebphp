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

    <div class="card soft-card mb-3">
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
        <div class="col-12 col-xl-8"><div class="card soft-card"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Günlük İzlenme Trendi</h6></div><div class="card-body"><div class="chart-wrap"><canvas id="dailyChart"></canvas></div></div></div></div>
        <div class="col-12 col-xl-4 d-flex flex-column gap-3">
            <div class="card soft-card"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Çalışma vs Deneme</h6></div><div class="card-body"><div class="chart-wrap small"><canvas id="typeChart"></canvas></div></div></div>
            <div class="card soft-card"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Platform Kırılımı</h6></div><div class="card-body"><div class="chart-wrap small"><canvas id="platformChart"></canvas></div></div></div>
        </div>
    </div>

    <div class="card soft-card mb-3"><div class="card-header bg-transparent border-0"><h6 class="mb-0">Top Kullanıcılar</h6></div><div class="card-body table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Kullanıcı</th><th>Toplam</th><th>Çalışma</th><th>Deneme</th><th>Çalışma Kazanç</th><th>Deneme Kazanç</th><th>Son İzleme</th></tr></thead><tbody id="topUsersBody"><tr><td colspan="7" class="text-muted">Yükleniyor...</td></tr></tbody></table></div></div>

    <div class="card soft-card"><div class="card-header bg-transparent border-0 d-flex justify-content-between"><h6 class="mb-0">Son Reklam Olayları</h6><small id="paginationMeta" class="text-muted"></small></div><div class="card-body table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Tarih</th><th>Kullanıcı</th><th>Platform</th><th>Tip</th><th>Kazanım</th><th>IP</th></tr></thead><tbody id="eventsBody"><tr><td colspan="6" class="text-muted">Yükleniyor...</td></tr></tbody></table></div></div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function(){
 const endpoint='../ajax/rewarded-ad-stats.php';
 const esc=(t)=>$('<div>').text(t??'').html();
 const fmt=(v)=>window.formatDate?window.formatDate(v):v;
 let dailyChart=null,typeChart=null,platformChart=null;
 function colors(){const dark=document.documentElement.getAttribute('data-theme')==='dark';return{grid:dark?'rgba(255,255,255,.08)':'rgba(10,30,80,.08)',text:dark?'#dbeafe':'#1e3a8a'};}
 function params(){return{range:$('#range').val(),start_date:$('#start_date').val(),end_date:$('#end_date').val(),reward_type:$('#reward_type').val(),platform:$('#platform').val(),user_search:$('#user_search').val(),page:1,per_page:20};}
 function badge(v,t){return `<span class="badge ${t}">${esc(v)}</span>`;}
 function renderKpi(s){const items=[['Toplam İzlenme',s.total_watches],['Çalışma Reklamı',s.study_watches],['Deneme Reklamı',s.exam_watches],['Benzersiz Kullanıcı',s.unique_users],['Android / iOS',`${s.android_watches} / ${s.ios_watches}`],['Çalışma Hakkı Toplam',s.total_study_bonus],['Deneme Hakkı Toplam',s.total_exam_bonus]];$('#kpiCards').html(items.map(i=>`<div class="col-6 col-xl"><div class="card soft-card kpi"><div class="card-body"><div class="k-label">${esc(i[0])}</div><div class="k-value">${esc(i[1])}</div></div></div></div>`).join(''));}
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
.rewarded-stats-page .soft-card{border:none;box-shadow:var(--shadow-soft);background:linear-gradient(180deg,var(--card-bg,#fff),var(--bg-soft,#f8fafc));}
.rewarded-hero{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-radius:16px;background:linear-gradient(135deg,#0f274d,#1d4f91 60%,#d4a017);color:#fff;box-shadow:var(--shadow-soft)}
.rewarded-hero .text-muted{color:#dbeafe!important}.hero-badge{font-weight:600}
.kpi .k-label{font-size:12px;color:var(--text-muted)} .kpi .k-value{font-size:24px;font-weight:700;color:var(--text-main)}
.chart-wrap{position:relative;height:320px}.chart-wrap.small{height:220px}
</style>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

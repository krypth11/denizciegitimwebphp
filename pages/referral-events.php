<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'referral-events';
$page_title = 'Referans İşlemleri';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header"><div><h2>Referans İşlemleri</h2><p class="text-muted mb-0">Pending, approved, suspicious, rejected ve reversed ödül eventleri.</p></div><button id="processBtn" class="btn btn-primary">Pending Uygun Olanları İşle</button></div>
    <div class="card"><div class="card-body">
        <div class="row g-2 mb-3"><div class="col-md-3"><select id="statusFilter" class="form-select"><option value="">Tüm durumlar</option><option>pending</option><option>approved</option><option>suspicious</option><option>rejected</option><option>reversed</option></select></div><div class="col-md-4"><input id="search" class="form-control" placeholder="Kullanıcı/product ara"></div><div class="col-md-2"><button id="filterBtn" class="btn btn-outline-secondary w-100">Filtrele</button></div></div>
        <div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Veren</th><th>Gelen/Satın Alan</th><th>Tür</th><th>Product</th><th>Plan</th><th>Gün/%</th><th>Eligible</th><th>Status</th><th>Fraud</th><th class="text-end">İşlem</th></tr></thead><tbody id="eventsBody"></tbody></table></div>
    </div></div>
</div>

<?php $extra_js = <<<'JS'
<script>
$(function(){
 const ep='../ajax/referrals.php'; const esc=t=>$('<div>').text(t??'').html();
 async function post(action,data){ return window.appAjax({url:ep+'?action='+action,method:'POST',data,dataType:'json'}); }
 async function load(){ const r=await window.appAjax({url:ep+'?action=list_events',data:{status:$('#statusFilter').val(),search:$('#search').val()},dataType:'json'}); $('#eventsBody').html((r.data.items||[]).map(x=>{ const days=[x.referrer_reward_days,x.referred_reward_days,x.buyer_bonus_days].map(Number).reduce((a,b)=>a+b,0); return `<tr><td>${esc(x.referrer_name||x.referrer_user_id||'-')}</td><td>${esc(x.referred_name||x.purchase_user_name||x.referred_user_id||x.purchase_user_id||'-')}</td><td><span class="badge text-bg-light border">${esc(x.event_kind)}</span></td><td>${esc(x.product_id)}</td><td>${esc(x.plan_code)}</td><td>${days} gün / +${esc(x.referrer_bonus_percent_delta||0)}%</td><td><small>${esc(x.eligible_at)}</small></td><td>${esc(x.status)}</td><td>${Number(x.is_suspicious)?'⚠️ '+esc(x.fraud_flags_json):'-'}</td><td class="text-end"><button class="btn btn-sm btn-success act" data-a="approve_event" data-id="${esc(x.id)}">Onayla</button> <button class="btn btn-sm btn-warning act" data-a="reject_event" data-id="${esc(x.id)}">Reddet</button> <button class="btn btn-sm btn-danger act" data-a="reverse_event" data-id="${esc(x.id)}">Geri al</button> <button class="btn btn-sm btn-outline-secondary act" data-a="mark_suspicious" data-id="${esc(x.id)}">Şüpheli</button></td></tr>` }).join('')||'<tr><td colspan="10" class="text-muted">Kayıt yok.</td></tr>'); }
 $('#filterBtn,#statusFilter').on('click change',load); $('#processBtn').on('click',async()=>{await post('process_pending',{}); load();});
 $(document).on('click','.act',async function(){ await post($(this).data('a'),{id:$(this).data('id')}); load(); });
 load();
});
</script>
JS; include '../includes/footer.php'; ?>

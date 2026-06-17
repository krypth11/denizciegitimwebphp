<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'referral-settings';
$page_title = 'Referans Ayarları';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header"><div><h2>Referans Ayarları</h2><p class="text-muted mb-0">Kullanıcılar davet linki yerine referans kodlarını paylaşır. Hediye kodları premium ekstra süre bonusu verir.</p></div></div>
    <div class="row g-3">
        <div class="col-lg-4"><div class="card"><div class="card-body">
            <h5>Global Ayarlar</h5>
            <form id="globalForm" class="vstack gap-2">
                <label class="form-label">Maksimum Bonus %<input class="form-control" name="max_bonus_percent" type="number" min="0"></label>
                <label class="form-label">Varsayılan Bekleme Günü<input class="form-control" name="default_waiting_days" type="number" min="0"></label>
                <div class="alert alert-info py-2 mb-1">Davet linki üretilmez/gösterilmez. Kullanıcılar yalnızca referans kodu paylaşır.</div>
                <label><input type="checkbox" name="auto_approve_enabled" value="1"> Otomatik onay</label>
                <label><input type="checkbox" name="reverse_on_refund" value="1"> Refund'da geri al</label>
                <label><input type="checkbox" name="suspicious_same_ip_enabled" value="1"> Aynı IP şüpheli</label>
                <label><input type="checkbox" name="suspicious_same_device_enabled" value="1"> Aynı cihaz şüpheli</label>
                <label><input type="checkbox" name="manual_approval_for_suspicious" value="1"> Şüphelide manuel onay</label>
                <button class="btn btn-primary" type="submit">Kaydet</button>
            </form>
        </div></div></div>
        <div class="col-lg-8"><div class="card"><div class="card-body">
            <h5>Paket Kuralları</h5>
            <form id="ruleForm" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="id">
                <div class="col-md-2"><label class="form-label">Plan<input class="form-control" name="plan_code" value="monthly"></label></div>
                <div class="col-md-3"><label class="form-label">Product ID<input class="form-control" name="product_id"></label></div>
                <div class="col-md-1"><label class="form-label">Veren<input class="form-control" name="referrer_reward_days" type="number" value="7"></label></div>
                <div class="col-md-1"><label class="form-label">Gelen<input class="form-control" name="referred_reward_days" type="number" value="7"></label></div>
                <div class="col-md-1"><label class="form-label">%<input class="form-control" name="referrer_bonus_percent_delta" type="number" value="5"></label></div>
                <div class="col-md-1"><label class="form-label">Bekle<input class="form-control" name="hold_days" type="number" value="7"></label></div>
                <div class="col-md-1"><label><input type="checkbox" name="is_active" value="1" checked> Aktif</label></div>
                <div class="col-md-2"><button class="btn btn-success w-100">Kural Kaydet</button></div>
            </form>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Plan</th><th>Product</th><th>Veren</th><th>Gelen</th><th>%</th><th>Bekleme</th><th>Aktif</th><th></th></tr></thead><tbody id="rulesBody"></tbody></table></div>
        </div></div></div>
    </div>

    <div class="card mt-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3"><div><h5 class="mb-0">Hediye Kodları</h5><small class="text-muted">Admin tanımlı kodlar kullanıcıya kalıcı ekstra süre bonusu verir.</small></div><button class="btn btn-outline-secondary btn-sm" id="refreshPromo" type="button">Yenile</button></div>
        <form id="promoForm" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="id">
            <div class="col-md-2"><label class="form-label">Kod<input class="form-control" name="code" placeholder="DENIZCILERGUNU" required></label></div>
            <div class="col-md-2"><label class="form-label">Başlık<input class="form-control" name="title"></label></div>
            <div class="col-md-2"><label class="form-label">Açıklama<input class="form-control" name="description"></label></div>
            <div class="col-md-1"><label class="form-label">Gün<input class="form-control" name="reward_days" type="number" min="1" value="7" required></label></div>
            <div class="col-md-1"><label class="form-label">Stok<input class="form-control" name="stock_total" type="number" min="1" placeholder="∞"></label></div>
            <div class="col-md-2"><label class="form-label">Başlangıç<input class="form-control" name="starts_at" type="datetime-local"></label></div>
            <div class="col-md-2"><label class="form-label">Bitiş<input class="form-control" name="ends_at" type="datetime-local"></label></div>
            <div class="col-md-2"><label class="form-label">Admin Notu<input class="form-control" name="admin_note"></label></div>
            <div class="col-md-2"><label><input type="checkbox" name="is_active" value="1" checked> Aktif</label><br><label><input type="checkbox" name="once_per_user" value="1" checked> Kullanıcı başına tek</label></div>
            <div class="col-md-2"><button class="btn btn-success w-100">Hediye Kodu Kaydet</button></div>
            <div class="col-md-2"><button class="btn btn-light w-100" id="promoReset" type="button">Temizle</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Kod</th><th>Başlık</th><th>Gün</th><th>Stok</th><th>Kullanılan</th><th>Kalan</th><th>Tarih</th><th>Durum</th><th>İşlemler</th></tr></thead><tbody id="promoBody"></tbody></table></div>
    </div></div>
</div>

<div class="modal fade" id="redemptionsModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Hediye Kod Kullanımları</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Kullanıcı</th><th>Email</th><th>Gün</th><th>Grant</th><th>Durum</th><th>Tarih</th></tr></thead><tbody id="redemptionsBody"></tbody></table></div></div></div></div></div>

<?php $extra_js = <<<'JS'
<script>
$(function(){
 const ep='../ajax/referrals.php'; const esc=t=>$('<div>').text(t??'').html(); const dt=v=>v?String(v).replace(' ','T').slice(0,16):'';
 function fillForm(form,obj){ Object.entries(obj||{}).forEach(([k,v])=>{ const el=$(form).find(`[name="${k}"]`); if(el.attr('type')==='checkbox') el.prop('checked', Number(v)===1); else if(el.attr('type')==='datetime-local') el.val(dt(v)); else el.val(v??''); }); }
 function stockBadge(x){ const t=(x.stock_total==null||x.stock_total==='')?null:Number(x.stock_total), u=Number(x.stock_used||0), r=t===null?null:Math.max(0,t-u); return t===null?'<span class="badge bg-info">Sınırsız</span>':`<span class="badge ${r<=0?'bg-danger':'bg-success'}">${r}</span>`; }
 async function load(){ const r=await window.appAjax({url:ep+'?action=get_settings',dataType:'json'}); fillForm('#globalForm',r.data.settings); $('#rulesBody').html((r.data.rules||[]).map(x=>`<tr><td>${esc(x.plan_code)}</td><td>${esc(x.product_id)}</td><td>${x.referrer_reward_days}</td><td>${x.referred_reward_days}</td><td>${x.referrer_bonus_percent_delta}</td><td>${x.hold_days ?? x.waiting_days ?? 0}</td><td>${Number(x.is_active)?'Evet':'Hayır'}</td><td><button class="btn btn-sm btn-outline-primary edit" data-row='${esc(JSON.stringify({...x, hold_days: x.hold_days ?? x.waiting_days ?? 0}))}'>Düzenle</button></td></tr>`).join('')||'<tr><td colspan="8" class="text-muted">Kural yok.</td></tr>'); await loadPromos(); }
 async function loadPromos(){ const r=await window.appAjax({url:ep+'?action=list_promo_codes',dataType:'json'}); $('#promoBody').html((r.data.items||[]).map(x=>{ const row=esc(JSON.stringify(x)); const total=(x.stock_total==null||x.stock_total==='')?'Sınırsız':x.stock_total; return `<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.title)}</td><td>${x.reward_days}</td><td>${total}</td><td>${x.stock_used||0}</td><td>${stockBadge(x)}</td><td><small>${esc(x.starts_at||'-')}<br>${esc(x.ends_at||'-')}</small></td><td>${Number(x.is_active)?'<span class="badge bg-primary">Aktif</span>':'<span class="badge bg-secondary">Pasif</span>'}</td><td class="text-nowrap"><button class="btn btn-sm btn-outline-primary promo-edit" data-row='${row}'>Düzenle</button> <button class="btn btn-sm btn-outline-warning promo-toggle" data-id="${esc(x.id)}" data-active="${Number(x.is_active)?0:1}">${Number(x.is_active)?'Pasifleştir':'Aktifleştir'}</button> <button class="btn btn-sm btn-outline-info promo-redemptions" data-id="${esc(x.id)}">Geçmiş</button></td></tr>`; }).join('')||'<tr><td colspan="9" class="text-muted">Hediye kodu yok.</td></tr>'); }
 $('#globalForm').on('submit',async function(e){e.preventDefault(); await window.appAjax({url:ep+'?action=save_global_settings',method:'POST',data:$(this).serialize(),dataType:'json'}); load();});
 $('#ruleForm').on('submit',async function(e){e.preventDefault(); await window.appAjax({url:ep+'?action=save_rule',method:'POST',data:$(this).serialize(),dataType:'json'}); this.reset(); load();});
 $('#promoForm').on('submit',async function(e){e.preventDefault(); await window.appAjax({url:ep+'?action=save_promo_code',method:'POST',data:$(this).serialize(),dataType:'json'}); this.reset(); $(this).find('[name=is_active],[name=once_per_user]').prop('checked',true); loadPromos();});
 $('#promoReset').on('click',function(){ $('#promoForm')[0].reset(); $('#promoForm [name=id]').val(''); $('#promoForm [name=is_active],#promoForm [name=once_per_user]').prop('checked',true); });
 $('#refreshPromo').on('click',loadPromos);
 $(document).on('click','.edit',function(){ fillForm('#ruleForm', JSON.parse($(this).attr('data-row'))); });
 $(document).on('click','.promo-edit',function(){ fillForm('#promoForm', JSON.parse($(this).attr('data-row'))); });
 $(document).on('click','.promo-toggle',async function(){ await window.appAjax({url:ep+'?action=toggle_promo_code',method:'POST',data:{id:$(this).data('id'),is_active:$(this).data('active')},dataType:'json'}); loadPromos(); });
 $(document).on('click','.promo-redemptions',async function(){ const r=await window.appAjax({url:ep+'?action=get_promo_redemptions&id='+encodeURIComponent($(this).data('id')),dataType:'json'}); $('#redemptionsBody').html((r.data.items||[]).map(x=>`<tr><td>${esc(x.full_name||x.user_id)}</td><td>${esc(x.email)}</td><td>${x.reward_days}</td><td><small>${esc(x.premium_grant_id)}</small></td><td>${esc(x.status)}</td><td>${esc(x.redeemed_at)}</td></tr>`).join('')||'<tr><td colspan="6" class="text-muted">Kullanım yok.</td></tr>'); new bootstrap.Modal(document.getElementById('redemptionsModal')).show(); });
 load();
});
</script>
JS; include '../includes/footer.php'; ?>

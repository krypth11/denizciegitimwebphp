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
    <div class="page-header"><div><h2>Referans Ayarları</h2><p class="text-muted mb-0">Global referans davranışı ve paket bazlı ödül kuralları.</p></div></div>
    <div class="row g-3">
        <div class="col-lg-4"><div class="card"><div class="card-body">
            <h5>Global Ayarlar</h5>
            <form id="globalForm" class="vstack gap-2">
                <label class="form-label">Maksimum Bonus %<input class="form-control" name="max_bonus_percent" type="number" min="0"></label>
                <label class="form-label">Varsayılan Bekleme Günü<input class="form-control" name="default_waiting_days" type="number" min="0"></label>
                <label class="form-label">Davet Link Base URL<input class="form-control" name="invite_base_url" placeholder="https://site/app?ref={code}"></label>
                <label><input type="checkbox" name="auto_approve_enabled" value="1"> Otomatik onay</label>
                <label><input type="checkbox" name="reverse_on_refund_enabled" value="1"> Refund'da geri al</label>
                <label><input type="checkbox" name="same_ip_suspicious_enabled" value="1"> Aynı IP şüpheli</label>
                <label><input type="checkbox" name="same_device_suspicious_enabled" value="1"> Aynı cihaz şüpheli</label>
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
                <div class="col-md-1"><label class="form-label">Bekle<input class="form-control" name="waiting_days" type="number" value="14"></label></div>
                <div class="col-md-1"><label><input type="checkbox" name="is_active" value="1" checked> Aktif</label></div>
                <div class="col-md-2"><button class="btn btn-success w-100">Kural Kaydet</button></div>
            </form>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Plan</th><th>Product</th><th>Veren</th><th>Gelen</th><th>%</th><th>Bekleme</th><th>Aktif</th><th></th></tr></thead><tbody id="rulesBody"></tbody></table></div>
        </div></div></div>
    </div>
</div>

<?php $extra_js = <<<'JS'
<script>
$(function(){
 const ep='../ajax/referrals.php'; const esc=t=>$('<div>').text(t??'').html();
 function fillForm(form,obj){ Object.entries(obj||{}).forEach(([k,v])=>{ const el=$(form).find(`[name="${k}"]`); if(el.attr('type')==='checkbox') el.prop('checked', Number(v)===1); else el.val(v??''); }); }
 async function load(){ const r=await window.appAjax({url:ep+'?action=get_settings',dataType:'json'}); fillForm('#globalForm',r.data.settings); $('#rulesBody').html((r.data.rules||[]).map(x=>`<tr><td>${esc(x.plan_code)}</td><td>${esc(x.product_id)}</td><td>${x.referrer_reward_days}</td><td>${x.referred_reward_days}</td><td>${x.referrer_bonus_percent_delta}</td><td>${x.waiting_days}</td><td>${Number(x.is_active)?'Evet':'Hayır'}</td><td><button class="btn btn-sm btn-outline-primary edit" data-row='${esc(JSON.stringify(x))}'>Düzenle</button></td></tr>`).join('')||'<tr><td colspan="8" class="text-muted">Kural yok.</td></tr>'); }
 $('#globalForm').on('submit',async function(e){e.preventDefault(); await window.appAjax({url:ep+'?action=save_global_settings',method:'POST',data:$(this).serialize(),dataType:'json'}); load();});
 $('#ruleForm').on('submit',async function(e){e.preventDefault(); await window.appAjax({url:ep+'?action=save_rule',method:'POST',data:$(this).serialize(),dataType:'json'}); this.reset(); load();});
 $(document).on('click','.edit',function(){ fillForm('#ruleForm', JSON.parse($(this).attr('data-row'))); });
 load();
});
</script>
JS; include '../includes/footer.php'; ?>

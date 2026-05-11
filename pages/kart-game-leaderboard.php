<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'kart-game-leaderboard';
$page_title = 'Kart Oyunu - Leaderboard';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kart Oyunu - Leaderboard</h2>
            <p class="text-muted mb-0">Kategori bazlı leaderboard ve sezon ödülleri yönetimi.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#kgLbTabRank" type="button">Sıralama Kayıtları</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#kgLbTabRewards" type="button">Ödül Ayarları</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="kgLbTabRank">
            <div class="d-flex justify-content-end mb-3">
                <button class="btn btn-primary" id="kgLbAddBtn"><i class="bi bi-plus-lg"></i> + Kayıt Ekle</button>
            </div>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kategori</label>
                            <select class="form-select" id="kgLbCategory"></select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="kgLbTable">
                            <thead>
                            <tr>
                                <th>Kullanıcı</th><th>Kategori</th><th>Level</th><th>XP</th><th>Best Combo</th><th>Best Score</th><th>Total Games</th><th>İşlemler</th>
                            </tr>
                            </thead>
                            <tbody><tr><td colspan="8" class="text-muted">Kategori seçiniz.</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="kgLbTabRewards">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Kategori</label>
                            <select class="form-select" id="kgRewardCategory"></select>
                        </div>
                        <div class="col-md-8 d-flex gap-2 justify-content-md-end">
                            <button class="btn btn-primary" id="kgRewardAddBtn">+ Ödül Ekle</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Aktif Sezon</div>
                <div class="card-body">
                    <form id="kgSeasonForm" class="row g-3">
                        <input type="hidden" id="kgSeasonId" name="season_id">
                        <div class="col-md-5">
                            <label class="form-label">Sezon Adı</label>
                            <input type="text" class="form-control" id="kgSeasonTitle" name="title" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sıfırlanma Tarihi</label>
                            <input type="datetime-local" class="form-control" id="kgSeasonResetAt" name="reset_at" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Durum</label>
                            <select class="form-select" id="kgSeasonActive" name="is_active">
                                <option value="1">Aktif</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-primary" type="submit">Sezonu Kaydet/Güncelle</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Ödül Listesi</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="kgRewardTable">
                            <thead>
                            <tr>
                                <th>Sıra Aralığı</th><th>Başlık</th><th>Açıklama</th><th>Durum</th><th>İşlemler</th>
                            </tr>
                            </thead>
                            <tbody><tr><td colspan="5" class="text-muted">Sezon seçiniz.</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="kgLbModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="kgLbModalTitle">Leaderboard Kaydı</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="kgLbForm"><input type="hidden" name="id" id="kgLbId"><div class="modal-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Kullanıcı</label><select class="form-select" name="user_id" id="kgLbUser" required></select></div>
            <div class="col-md-6"><label class="form-label">Kategori</label><select class="form-select" name="category_id" id="kgLbCategoryModal" required></select></div>
            <div class="col-md-4"><label class="form-label">Total XP</label><input type="number" class="form-control" name="total_xp" id="kgLbTotalXp" min="0" required></div>
            <div class="col-md-4"><label class="form-label">Current Level (Otomatik)</label><input type="number" class="form-control" name="current_level" id="kgLbCurrentLevel" min="1" required></div>
            <div class="col-md-4"><label class="form-label">Total Correct</label><input type="number" class="form-control" name="total_correct" id="kgLbTotalCorrect" min="0" required></div>
            <div class="col-md-4"><label class="form-label">Total Wrong</label><input type="number" class="form-control" name="total_wrong" id="kgLbTotalWrong" min="0" required></div>
            <div class="col-md-4"><label class="form-label">Best Combo</label><input type="number" class="form-control" name="best_combo" id="kgLbBestCombo" min="0" required></div>
            <div class="col-md-4"><label class="form-label">Best Score</label><input type="number" class="form-control" name="best_score" id="kgLbBestScore" min="0" required></div>
            <div class="col-md-4"><label class="form-label">Total Games</label><input type="number" class="form-control" name="total_games" id="kgLbTotalGames" min="0" required></div>
        </div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div></form>
    </div></div>
</div>

<div class="modal fade" id="kgRewardModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="kgRewardModalTitle">Ödül Ekle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="kgRewardForm"><input type="hidden" id="kgRewardId" name="id"><input type="hidden" id="kgRewardSeasonId" name="season_id"><div class="modal-body"><div class="row g-3">
            <div class="col-6"><label class="form-label">Sıra Başlangıç</label><input type="number" class="form-control" name="rank_start" id="kgRewardRankStart" min="1" required></div>
            <div class="col-6"><label class="form-label">Sıra Bitiş</label><input type="number" class="form-control" name="rank_end" id="kgRewardRankEnd" min="1" required></div>
            <div class="col-12"><label class="form-label">Ödül Başlığı</label><input type="text" class="form-control" name="reward_title" id="kgRewardTitle" required></div>
            <div class="col-12"><label class="form-label">Açıklama</label><textarea class="form-control" name="reward_description" id="kgRewardDesc" rows="3"></textarea></div>
            <div class="col-6"><label class="form-label">Durum</label><select class="form-select" name="is_active" id="kgRewardActive"><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
            <div class="col-6"><label class="form-label">Sort Order</label><input type="number" class="form-control" name="sort_order" id="kgRewardSort" min="0" value="0" required></div>
        </div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div></form>
    </div></div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function(){
  const endpoint='../ajax/kart-game-leaderboard.php';
  const lbModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('kgLbModal'));
  const rwModal=bootstrap.Modal.getOrCreateInstance(document.getElementById('kgRewardModal'));
  const esc=(v)=>$('<div>').text(v??'').html();
  const appAlert=(title,message,type='info')=>window.showAppAlert({title,message,type});
  const appConfirm=(title,message,options={})=>window.showAppConfirm({title,message,...options});
  let categories=[], users=[], modalCategories=[], currentSeason=null, currentRewards=[];
  async function api(action,method='GET',data={}){return window.appAjax({url:endpoint+'?action='+encodeURIComponent(action),method,data,dataType:'json'});}  

  function renderCategories(){
    const s=$('#kgLbCategory,#kgRewardCategory'); s.empty();
    if(!categories.length){s.append('<option value="">Kategori yok</option>');return;}
    categories.forEach(c=>s.append(`<option value="${esc(c.id)}">${esc(c.title||c.name||'')}</option>`));
    const first=s.first().val()||'';
    $('#kgLbCategory').val(first); $('#kgRewardCategory').val(first);
  }
  function renderModalSelects(){
    const us=$('#kgLbUser').empty().append('<option value="">Kullanıcı seçiniz</option>');
    users.forEach(u=>{const label=((u.full_name||'').trim()?u.full_name+' - ':'')+(u.email||u.id||''); us.append(`<option value="${esc(u.id)}">${esc(label)}</option>`);});
    const cs=$('#kgLbCategoryModal').empty().append('<option value="">Kategori seçiniz</option>');
    modalCategories.forEach(c=>cs.append(`<option value="${esc(c.id)}">${esc(c.name||'')}</option>`));
  }

  function renderRows(items){
    const tb=$('#kgLbTable tbody').empty();
    if(!items.length){tb.html('<tr><td colspan="8" class="text-muted">Kayıt bulunamadı.</td></tr>');return;}
    items.forEach(i=>tb.append(`<tr><td>${esc(i.username)}</td><td>${esc($('#kgLbCategory option:selected').text())}</td><td>${esc(i.current_level)}</td><td>${esc(i.total_xp)}</td><td>${esc(i.best_combo)}</td><td>${esc(i.best_score)}</td><td>${esc(i.total_games)}</td><td><button class="btn btn-sm btn-outline-warning kg-lb-edit" data-id="${esc(i.id)}">Düzenle</button> <button class="btn btn-sm btn-danger kg-lb-del" data-id="${esc(i.id)}">Sil</button></td></tr>`));
  }
  function fmtLocalDateTime(v){ if(!v) return ''; const d=new Date(v.replace(' ','T')); if(isNaN(d)) return ''; const p=n=>String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; }
  function toMysqlDateTime(v){ if(!v) return ''; return v.replace('T',' ') + ':00'; }
  function renderRewards(){
    const tb=$('#kgRewardTable tbody').empty();
    if(!currentSeason){tb.html('<tr><td colspan="5" class="text-muted">Aktif sezon yok.</td></tr>'); return;}
    if(!currentRewards.length){tb.html('<tr><td colspan="5" class="text-muted">Ödül kaydı yok.</td></tr>'); return;}
    currentRewards.forEach(r=>tb.append(`<tr><td>${esc(r.rank_start)}-${esc(r.rank_end)}</td><td>${esc(r.reward_title)}</td><td>${esc(r.reward_description||'')}</td><td>${(String(r.is_active)==='1')?'<span class="badge bg-success">Aktif</span>':'<span class="badge bg-secondary">Pasif</span>'}</td><td><button class="btn btn-sm btn-outline-warning kg-rw-edit" data-id="${esc(r.id)}">Düzenle</button> <button class="btn btn-sm btn-danger kg-rw-del" data-id="${esc(r.id)}">Sil</button></td></tr>`));
  }

  async function load(categoryId){ const res=await api('list','GET',{category_id:categoryId}); if(!res.success){await appAlert('Hata',res.message||'Liste alınamadı.','error');return;} categories=res.data?.categories||categories; renderCategories(); renderRows(res.data?.items||[]); }
  async function loadOptions(){ const res=await api('options','GET'); if(!res.success){await appAlert('Hata',res.message||'Seçenekler alınamadı.','error');return false;} users=res.data?.users||[]; modalCategories=res.data?.categories||[]; renderModalSelects(); return true; }
  async function loadRewardsConfig(){
    const categoryId=($('#kgRewardCategory').val()||'').trim();
    if(!categoryId){currentSeason=null;currentRewards=[];renderRewards();return;}
    const res=await api('get_rewards_config','GET',{category_id:categoryId});
    if(!res.success){await appAlert('Hata',res.message||'Ödül ayarları alınamadı.','error'); return;}
    currentSeason=res.data?.season||null; currentRewards=res.data?.rewards||[];
    $('#kgSeasonId').val(currentSeason?.id||'');
    $('#kgSeasonTitle').val(currentSeason?.title||'');
    $('#kgSeasonResetAt').val(fmtLocalDateTime(currentSeason?.reset_at||''));
    $('#kgSeasonActive').val(String(currentSeason?.is_active ?? 1));
    renderRewards();
  }

  $('#kgLbCategory').on('change',function(){const id=$(this).val()||'';if(id) load(id);});
  $('#kgRewardCategory').on('change',loadRewardsConfig);

  $('#kgLbAddBtn').on('click', async function(){ if(!users.length || !modalCategories.length){const ok=await loadOptions(); if(!ok) return;} $('#kgLbForm')[0].reset(); $('#kgLbId').val(''); $('#kgLbUser,#kgLbCategoryModal').prop('disabled',false); $('#kgLbCategoryModal').val(($('#kgLbCategory').val()||'').trim()); $('#kgLbCurrentLevel').val('1'); $('#kgLbModalTitle').text('Leaderboard Kaydı Ekle'); lbModal.show(); });
  $(document).on('click','.kg-lb-edit', async function(){ const id=$(this).data('id'); if(!id) return; if(!users.length || !modalCategories.length){const ok=await loadOptions(); if(!ok) return;} const res=await api('get','GET',{id}); if(!res.success){await appAlert('Hata',res.message||'Kayıt alınamadı.','error');return;} const i=res.data?.item||{}; $('#kgLbModalTitle').text('Leaderboard Kaydı Düzenle'); $('#kgLbId').val(i.id||''); $('#kgLbUser').val(i.user_id||'').prop('disabled',true); $('#kgLbCategoryModal').val(i.category_id||'').prop('disabled',true); $('#kgLbTotalXp').val(i.total_xp||0); $('#kgLbCurrentLevel').val(i.current_level||1); $('#kgLbTotalCorrect').val(i.total_correct||0); $('#kgLbTotalWrong').val(i.total_wrong||0); $('#kgLbBestCombo').val(i.best_combo||0); $('#kgLbBestScore').val(i.best_score||0); $('#kgLbTotalGames').val(i.total_games||0); lbModal.show(); });
  $('#kgLbForm').on('submit', async function(e){ e.preventDefault(); const id=($('#kgLbId').val()||'').trim(); const action=id?'update':'create'; $('#kgLbUser,#kgLbCategoryModal').prop('disabled',false); const res=await api(action,'POST',$(this).serialize()); if(id){$('#kgLbUser,#kgLbCategoryModal').prop('disabled',true);} if(!res.success){const errs=res.data?.errors||{}; await appAlert('Hata',Object.values(errs).join('\n')||res.message||'İşlem başarısız.','error'); return;} lbModal.hide(); await appAlert('Başarılı',res.message||'Kaydedildi.','success'); const cid=($('#kgLbCategory').val()||'').trim(); if(cid) await load(cid); });
  $(document).on('click','.kg-lb-del', async function(){ const id=$(this).data('id'); if(!id) return; const ok=await appConfirm('Sil','Bu leaderboard kaydı silinsin mi?',{type:'warning',confirmText:'Sil',cancelText:'İptal'}); if(!ok) return; const res=await api('delete','POST',{id}); if(!res.success){await appAlert('Hata',res.message||'Silme başarısız.','error');return;} await appAlert('Başarılı',res.message||'Silindi.','success'); const cid=($('#kgLbCategory').val()||'').trim(); if(cid) await load(cid); });

  $('#kgSeasonForm').on('submit', async function(e){
    e.preventDefault();
    const categoryId=($('#kgRewardCategory').val()||'').trim();
    const payload={category_id:categoryId,season_id:$('#kgSeasonId').val()||'',title:$('#kgSeasonTitle').val()||'',reset_at:toMysqlDateTime($('#kgSeasonResetAt').val()||''),is_active:$('#kgSeasonActive').val()||'1'};
    const res=await api('save_season','POST',payload);
    if(!res.success){const errs=res.data?.errors||{}; await appAlert('Hata',Object.values(errs).join('\n')||res.message||'İşlem başarısız.','error'); return;}
    await appAlert('Başarılı',res.message||'Sezon kaydedildi.','success');
    await loadRewardsConfig();
  });

  $('#kgRewardAddBtn').on('click', async function(){
    if(!currentSeason){await appAlert('Uyarı','Önce sezon kaydediniz.','warning'); return;}
    $('#kgRewardForm')[0].reset(); $('#kgRewardId').val(''); $('#kgRewardSeasonId').val(currentSeason.id); $('#kgRewardActive').val('1'); $('#kgRewardSort').val('0'); $('#kgRewardModalTitle').text('Ödül Ekle'); rwModal.show();
  });
  $(document).on('click','.kg-rw-edit', function(){
    const id=$(this).data('id'); const r=currentRewards.find(x=>String(x.id)===String(id)); if(!r) return;
    $('#kgRewardId').val(r.id); $('#kgRewardSeasonId').val(r.season_id); $('#kgRewardRankStart').val(r.rank_start); $('#kgRewardRankEnd').val(r.rank_end); $('#kgRewardTitle').val(r.reward_title||''); $('#kgRewardDesc').val(r.reward_description||''); $('#kgRewardActive').val(String(r.is_active)); $('#kgRewardSort').val(r.sort_order||0); $('#kgRewardModalTitle').text('Ödül Düzenle'); rwModal.show();
  });
  $('#kgRewardForm').on('submit', async function(e){
    e.preventDefault();
    const id=($('#kgRewardId').val()||'').trim();
    const action=id?'update_reward':'create_reward';
    const payload=$(this).serialize();
    const res=await api(action,'POST',payload);
    if(!res.success){const errs=res.data?.errors||{}; await appAlert('Hata',Object.values(errs).join('\n')||res.message||'İşlem başarısız.','error'); return;}
    rwModal.hide(); await appAlert('Başarılı',res.message||'Kaydedildi.','success'); await loadRewardsConfig();
  });
  $(document).on('click','.kg-rw-del', async function(){
    const id=$(this).data('id'); if(!id) return;
    const ok=await appConfirm('Sil','Bu ödül kaydı silinsin mi?',{type:'warning',confirmText:'Sil',cancelText:'İptal'});
    if(!ok) return;
    const res=await api('delete_reward','POST',{id});
    if(!res.success){await appAlert('Hata',res.message||'Silme başarısız.','error'); return;}
    await appAlert('Başarılı',res.message||'Silindi.','success'); await loadRewardsConfig();
  });

  (async ()=>{
    const res=await api('list','GET',{category_id:''});
    categories=res.data?.categories||[];
    renderCategories();
    await loadOptions();
    const first=($('#kgLbCategory').val()||'').trim();
    if(first){ await load(first); await loadRewardsConfig(); }
  })();
});
</script>
JS;
include '../includes/footer.php';
?>

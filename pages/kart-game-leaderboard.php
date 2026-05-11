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
            <p class="text-muted mb-0">Kategori bazlı kullanıcı sıralamasını görüntüleyin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" id="kgLbAddBtn"><i class="bi bi-plus-lg"></i> + Kayıt Ekle</button>
        </div>
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

<div class="modal fade" id="kgLbModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kgLbModalTitle">Leaderboard Kaydı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="kgLbForm">
                <input type="hidden" name="id" id="kgLbId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kullanıcı</label>
                            <select class="form-select" name="user_id" id="kgLbUser" required></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kategori</label>
                            <select class="form-select" name="category_id" id="kgLbCategoryModal" required></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total XP</label>
                            <input type="number" class="form-control" name="total_xp" id="kgLbTotalXp" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Current Level (Otomatik Hesaplanır)</label>
                            <input type="number" class="form-control" name="current_level" id="kgLbCurrentLevel" min="1" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Correct</label>
                            <input type="number" class="form-control" name="total_correct" id="kgLbTotalCorrect" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Wrong</label>
                            <input type="number" class="form-control" name="total_wrong" id="kgLbTotalWrong" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Best Combo</label>
                            <input type="number" class="form-control" name="best_combo" id="kgLbBestCombo" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Best Score</label>
                            <input type="number" class="form-control" name="best_score" id="kgLbBestScore" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Total Games</label>
                            <input type="number" class="form-control" name="total_games" id="kgLbTotalGames" min="0" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function(){
  const endpoint='../ajax/kart-game-leaderboard.php';
  const modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('kgLbModal'));
  const esc=(v)=>$('<div>').text(v??'').html();
  const appAlert=(title,message,type='info')=>window.showAppAlert({title,message,type});
  const appConfirm=(title,message,options={})=>window.showAppConfirm({title,message,...options});
  let categories=[];
  let users=[];
  let modalCategories=[];
  async function api(action,method='GET',data={}){return window.appAjax({url:endpoint+'?action='+encodeURIComponent(action),method,data,dataType:'json'});}  
  function renderModalSelects(){
    const us=$('#kgLbUser');
    us.empty();
    us.append('<option value="">Kullanıcı seçiniz</option>');
    users.forEach(u=>{
      const label=((u.full_name||'').trim() ? (u.full_name+' - ') : '') + (u.email||u.id||'');
      us.append(`<option value="${esc(u.id)}">${esc(label)}</option>`);
    });

    const cs=$('#kgLbCategoryModal');
    cs.empty();
    cs.append('<option value="">Kategori seçiniz</option>');
    modalCategories.forEach(c=>cs.append(`<option value="${esc(c.id)}">${esc(c.name||'')}</option>`));
  }
  function renderCategories(){const s=$('#kgLbCategory');s.empty();if(!categories.length){s.append('<option value="">Kategori yok</option>');return;}categories.forEach(c=>s.append(`<option value="${esc(c.id)}">${esc(c.title)}</option>`));}
  function renderRows(items){
    const tb=$('#kgLbTable tbody');
    tb.empty();
    if(!items.length){tb.html('<tr><td colspan="8" class="text-muted">Kayıt bulunamadı.</td></tr>');return;}
    items.forEach(i=>tb.append(`<tr>
      <td>${esc(i.username)}</td>
      <td>${esc($('#kgLbCategory option:selected').text())}</td>
      <td>${esc(i.current_level)}</td>
      <td>${esc(i.total_xp)}</td>
      <td>${esc(i.best_combo)}</td>
      <td>${esc(i.best_score)}</td>
      <td>${esc(i.total_games)}</td>
      <td>
        <button class="btn btn-sm btn-outline-warning kg-lb-edit" data-id="${esc(i.id)}"><i class="bi bi-pencil"></i> Düzenle</button>
        <button class="btn btn-sm btn-danger kg-lb-del" data-id="${esc(i.id)}"><i class="bi bi-trash"></i> Sil</button>
      </td>
    </tr>`));
  }
  async function load(categoryId){const res=await api('list','GET',{category_id:categoryId});if(!res.success){await appAlert('Hata',res.message||'Liste alınamadı.','error');return;}categories=res.data?.categories||categories;renderCategories();renderRows(res.data?.items||[]);}  
  async function loadOptions(){const res=await api('options','GET');if(!res.success){await appAlert('Hata',res.message||'Seçenekler alınamadı.','error');return false;}users=res.data?.users||[];modalCategories=res.data?.categories||[];renderModalSelects();return true;}
  function resetForm(){
    $('#kgLbForm')[0].reset();
    $('#kgLbId').val('');
    $('#kgLbModalTitle').text('Leaderboard Kaydı Ekle');
    $('#kgLbUser, #kgLbCategoryModal').prop('disabled',false);
    const selectedCategory=($('#kgLbCategory').val()||'').trim();
    if(selectedCategory){$('#kgLbCategoryModal').val(selectedCategory);}
    $('#kgLbCurrentLevel').val('1');
  }
  $('#kgLbCategory').on('change',function(){const id=$(this).val()||'';if(id) load(id);});
  $('#kgLbAddBtn').on('click', async function(){
    if(!users.length || !modalCategories.length){
      const ok=await loadOptions();
      if(!ok) return;
    }
    resetForm();
    modal.show();
  });

  $(document).on('click','.kg-lb-edit', async function(){
    const id=$(this).data('id');
    if(!id) return;
    if(!users.length || !modalCategories.length){
      const ok=await loadOptions();
      if(!ok) return;
    }
    const res=await api('get','GET',{id});
    if(!res.success){await appAlert('Hata',res.message||'Kayıt alınamadı.','error');return;}
    const i=res.data?.item||{};
    $('#kgLbModalTitle').text('Leaderboard Kaydı Düzenle');
    $('#kgLbId').val(i.id||'');
    $('#kgLbUser').val(i.user_id||'').prop('disabled',true);
    $('#kgLbCategoryModal').val(i.category_id||'').prop('disabled',true);
    $('#kgLbTotalXp').val(i.total_xp||0);
    $('#kgLbCurrentLevel').val(i.current_level||1);
    $('#kgLbTotalCorrect').val(i.total_correct||0);
    $('#kgLbTotalWrong').val(i.total_wrong||0);
    $('#kgLbBestCombo').val(i.best_combo||0);
    $('#kgLbBestScore').val(i.best_score||0);
    $('#kgLbTotalGames').val(i.total_games||0);
    modal.show();
  });

  $('#kgLbForm').on('submit', async function(e){
    e.preventDefault();
    const id=($('#kgLbId').val()||'').trim();
    const action=id?'update':'create';
    $('#kgLbUser, #kgLbCategoryModal').prop('disabled',false);
    const res=await api(action,'POST',$(this).serialize());
    if(id){$('#kgLbUser, #kgLbCategoryModal').prop('disabled',true);} 
    if(!res.success){
      const errs=res.data?.errors||{};
      await appAlert('Hata',Object.values(errs).join('\n')||res.message||'İşlem başarısız.','error');
      return;
    }
    modal.hide();
    await appAlert('Başarılı',res.message||'Kaydedildi.','success');
    const currentCategory=($('#kgLbCategory').val()||'').trim();
    if(currentCategory){ await load(currentCategory); }
  });

  $(document).on('click','.kg-lb-del', async function(){
    const id=$(this).data('id');
    if(!id) return;
    const ok=await appConfirm('Sil', 'Bu leaderboard kaydı silinsin mi?', {type:'warning',confirmText:'Sil',cancelText:'İptal'});
    if(!ok) return;
    const res=await api('delete','POST',{id});
    if(!res.success){await appAlert('Hata',res.message||'Silme başarısız.','error');return;}
    await appAlert('Başarılı',res.message||'Silindi.','success');
    const currentCategory=($('#kgLbCategory').val()||'').trim();
    if(currentCategory){ await load(currentCategory); }
  });

  (async ()=>{
    const res=await api('list','GET',{category_id:''});
    categories=res.data?.categories||[];
    renderCategories();
    await loadOptions();
    const first=$('#kgLbCategory').val()||'';
    if(first) load(first);
  })();
});
</script>
JS;
include '../includes/footer.php';
?>

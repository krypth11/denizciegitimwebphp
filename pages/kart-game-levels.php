<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'kart-game-levels';
$page_title = 'Kart Oyunu - Level Sistemi';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Kart Oyunu - Level Sistemi</h2>
            <p class="text-muted mb-0">Level ve gerekli toplam XP değerlerini yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" id="addLevelBtn"><i class="bi bi-plus-lg"></i> Yeni Level</button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="kgLevelTable">
                    <thead><tr><th>Level</th><th>Required Total XP</th><th>İşlemler</th></tr></thead>
                    <tbody><tr><td colspan="3" class="text-muted">Yükleniyor...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="kgLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="kgLevelModalTitle">Level</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form id="kgLevelForm">
                <input type="hidden" id="lvl_id" name="id">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Level Number</label><input type="number" class="form-control" name="level_number" id="lvl_number" min="1" required></div>
                    <div class="mb-3"><label class="form-label">Required Total XP</label><input type="number" class="form-control" name="required_total_xp" id="lvl_required_xp" min="0" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
            </form>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function(){
  const endpoint='../ajax/kart-game-levels.php';
  const modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('kgLevelModal'));
  const esc=(v)=>$('<div>').text(v??'').html();
  const appAlert=(title,message,type='info')=>window.showAppAlert({title,message,type});
  const appConfirm=(title,message,options={})=>window.showAppConfirm({title,message,...options});
  let rows=[];
  async function api(action,method='GET',data={}){return window.appAjax({url:endpoint+'?action='+encodeURIComponent(action),method,data,dataType:'json'});}  
  function render(){const tb=$('#kgLevelTable tbody');tb.empty();if(!rows.length){tb.html('<tr><td colspan="3" class="text-muted">Kayıt bulunamadı.</td></tr>');return;}rows.forEach(r=>tb.append(`<tr><td>${esc(r.level_number)}</td><td>${esc(r.required_total_xp)}</td><td><button class="btn btn-sm btn-outline-primary edit" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button> <button class="btn btn-sm btn-outline-danger del" data-id="${esc(r.id)}"><i class="bi bi-trash"></i></button></td></tr>`));}
  async function load(){const res=await api('list');if(!res.success){await appAlert('Hata',res.message||'Liste alınamadı.','error');return;}rows=res.data?.items||[];render();}
  function reset(){ $('#kgLevelForm')[0].reset(); $('#lvl_id').val(''); $('#kgLevelModalTitle').text('Yeni Level'); }
  $('#addLevelBtn').on('click',()=>{reset();modal.show();});
  $(document).on('click','.edit',async function(){const id=$(this).data('id');const res=await api('get','GET',{id});if(!res.success){await appAlert('Hata',res.message||'Kayıt alınamadı.','error');return;}const i=res.data?.item||{};$('#kgLevelModalTitle').text('Level Düzenle');$('#lvl_id').val(i.id||'');$('#lvl_number').val(i.level_number||'');$('#lvl_required_xp').val(i.required_total_xp||'');modal.show();});
  $('#kgLevelForm').on('submit',async function(e){e.preventDefault();const id=($('#lvl_id').val()||'').trim();const action=id?'update':'create';const res=await api(action,'POST',$(this).serialize());if(!res.success){const errs=res.data?.errors||{};await appAlert('Hata',Object.values(errs).join('\n')||res.message||'İşlem başarısız.','error');return;}modal.hide();await appAlert('Başarılı',res.message||'Kaydedildi.','success');load();});
  $(document).on('click','.del',async function(){const id=$(this).data('id');const ok=await appConfirm('Sil', 'Bu level kaydı silinsin mi?', {type:'warning',confirmText:'Sil',cancelText:'İptal'});if(!ok)return;const res=await api('delete','POST',{id});if(!res.success){await appAlert('Hata',res.message||'Silme başarısız.','error');return;}await appAlert('Başarılı',res.message||'Silindi.','success');load();});
  load();
});
</script>
JS;
include '../includes/footer.php';
?>

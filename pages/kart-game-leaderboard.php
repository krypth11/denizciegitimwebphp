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
                        <th>Kullanıcı</th><th>Kategori</th><th>Level</th><th>XP</th><th>Best Combo</th><th>Best Score</th><th>Total Games</th>
                    </tr>
                    </thead>
                    <tbody><tr><td colspan="7" class="text-muted">Kategori seçiniz.</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function(){
  const endpoint='../ajax/kart-game-leaderboard.php';
  const esc=(v)=>$('<div>').text(v??'').html();
  const appAlert=(title,message,type='info')=>window.showAppAlert({title,message,type});
  let categories=[];
  async function api(data={}){return window.appAjax({url:endpoint+'?action=list',method:'GET',data,dataType:'json'});}  
  function renderCategories(){const s=$('#kgLbCategory');s.empty();if(!categories.length){s.append('<option value="">Kategori yok</option>');return;}categories.forEach(c=>s.append(`<option value="${esc(c.id)}">${esc(c.title)}</option>`));}
  function renderRows(items){const tb=$('#kgLbTable tbody');tb.empty();if(!items.length){tb.html('<tr><td colspan="7" class="text-muted">Kayıt bulunamadı.</td></tr>');return;}items.forEach(i=>tb.append(`<tr><td>${esc(i.username)}</td><td>${esc($('#kgLbCategory option:selected').text())}</td><td>${esc(i.current_level)}</td><td>${esc(i.total_xp)}</td><td>${esc(i.best_combo)}</td><td>${esc(i.best_score)}</td><td>${esc(i.total_games)}</td></tr>`));}
  async function load(categoryId){const res=await api({category_id:categoryId});if(!res.success){await appAlert('Hata',res.message||'Liste alınamadı.','error');return;}categories=res.data?.categories||categories;renderCategories();renderRows(res.data?.items||[]);}  
  $('#kgLbCategory').on('change',function(){const id=$(this).val()||'';if(id) load(id);});
  (async ()=>{const res=await api({category_id:''});categories=res.data?.categories||[];renderCategories();const first=$('#kgLbCategory').val()||'';if(first) load(first);})();
});
</script>
JS;
include '../includes/footer.php';
?>

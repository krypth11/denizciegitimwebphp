<?php
require_once '../includes/config.php'; require_once '../includes/auth.php'; require_once '../includes/functions.php';
$user=require_auth(); $current_page='word-game-mappings'; $page_title='Kelime Oyunu - Eşleştirmeler';
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<div class="container-fluid"><div class="page-header"><div><h2>Kelime Oyunu - Başlık / Yeterlilik Eşleştirme</h2></div></div><div id="list"></div></div>
<?php $extra_js=<<<'JS'
<script>$(function(){const ep='../ajax/word-game-mappings.php';const api=(a,method='GET',data={})=>window.appAjax({url:ep+'?action='+encodeURIComponent(a),method,data,dataType:'json'});const esc=v=>$('<div>').text(v??'').html();let st={categories:[],qualifications:[],mapping:{}};
function render(){const w=$('#list');w.empty();(st.categories||[]).forEach(c=>{const selected=new Set(st.mapping?.[c.id]||[]);const q=(st.qualifications||[]).map(x=>`<div class="form-check"><input class="form-check-input q" type="checkbox" value="${esc(x.id)}" id="m_${esc(c.id)}_${esc(x.id)}" ${selected.has(String(x.id))?'checked':''}><label class="form-check-label" for="m_${esc(c.id)}_${esc(x.id)}">${esc(x.name)}</label></div>`).join('');w.append(`<div class="card mb-3" data-c="${esc(c.id)}"><div class="card-body"><div class="d-flex justify-content-between"><div><div class="fw-semibold">${esc(c.name)}</div><div class="small text-muted"><code>${esc(c.slug)}</code></div></div><button class="btn btn-sm btn-primary s" data-c="${esc(c.id)}">Kaydet</button></div><div class="mt-2">${q}</div></div></div>`);});}
async function load(){const r=await api('list');if(!r.success)return;st={categories:r.data.categories||[],qualifications:r.data.qualifications||[],mapping:r.data.mapping||{}};render();}
$(document).on('click','.s',async function(){const cid=$(this).data('c');const ids=[];$(`[data-c="${cid}"] .q:checked`).each(function(){ids.push($(this).val());});await api('save','POST',{category_id:cid,qualification_ids:ids});});
load();});</script>
JS; include '../includes/footer.php'; ?>

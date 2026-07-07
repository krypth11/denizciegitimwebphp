<?php
require_once '../includes/config.php'; require_once '../includes/auth.php'; require_once '../includes/functions.php';
$user=require_auth(); $current_page='word-game-questions'; $page_title='Kelime Oyunu - Sorular';
$qualifications=$pdo->query('SELECT id,name FROM qualifications ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$categories=$pdo->query('SELECT id,name FROM word_game_categories ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<div class="container-fluid">
  <div class="page-header"><div><h2>Kelime Oyunu Soru Havuzu</h2></div><div class="page-actions"><button class="btn btn-primary" id="addBtn"><i class="bi bi-plus-lg"></i> Yeni Soru Ekle</button></div></div>
  <div class="card mb-3"><div class="card-body"><div class="row g-3"><div class="col-md-3"><label class="form-label">Başlık</label><select id="fCategory" class="form-select"><option value="">Tümü</option><?php foreach($categories as $c): ?><option value="<?=htmlspecialchars($c['id'])?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Yeterlilik</label><select id="fQualification" class="form-select"><option value="">Tümü</option><?php foreach($qualifications as $q): ?><option value="<?=htmlspecialchars($q['id'])?>"><?=htmlspecialchars($q['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Durum</label><select id="fActive" class="form-select"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div><div class="col-md-4"><label class="form-label">Arama</label><input id="fSearch" class="form-control" placeholder="Soru/Cevap ara..."></div></div></div></div>
  <div class="card mt-3"><div class="card-body"><h5>Toplu Pattern Giriş</h5><div class="row g-3"><div class="col-md-4"><label class="form-label">Başlık *</label><select id="bulkCategory" class="form-select"><option value="">Seçiniz...</option><?php foreach($categories as $c): ?><option value="<?=htmlspecialchars($c['id'])?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Yeterlilik *</label><select id="bulkQualification" class="form-select" disabled><option value="">Önce başlık seçiniz...</option></select><div id="bulkQualificationInfo" class="form-text text-warning d-none">Bu başlığa bağlı yeterlilik bulunamadı.</div></div><div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-outline-primary" id="parseBulk" disabled>Ayrıştır</button><button class="btn btn-success" id="saveBulk" disabled>Onayla ve Kaydet</button></div></div><textarea id="bulkPattern" class="form-control mt-3" rows="10" placeholder="### WORD&#10;TR_SORU: ...&#10;EN_QUESTION: ...&#10;TR_CEVAP: ...&#10;EN_ANSWER: ...&#10;NOTE: ..."></textarea><div id="bulkPreview" class="mt-3"></div></div></div>

  <div class="card mt-3"><div class="card-body">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3" id="paginationTop"></div>
    <div class="d-flex justify-content-end mb-2"><button id="bulkDeleteBtn" class="btn btn-sm btn-outline-danger" disabled>Seçilenleri Sil (0)</button></div>
    <div class="table-responsive"><table class="table" id="tbl"><thead><tr><th style="width:36px"><input type="checkbox" id="selectAllVisible" class="form-check-input"></th><th>Başlık</th><th>Yeterlilik</th><th>TR Soru</th><th>TR Cevap</th><th>EN Cevap</th><th>Uz.</th><th>Durum</th><th>İşlem</th></tr></thead><tbody><tr><td colspan="9" class="text-muted">Yükleniyor...</td></tr></tbody></table></div>
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-3" id="paginationBottom"></div>
  </div></div>
</div>

<div class="modal fade" id="m" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="mt">Soru</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form id="f"><input type="hidden" name="id" id="id"><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Başlık *</label><select class="form-select" name="category_id" id="category_id" required><option value="">Seçiniz...</option><?php foreach($categories as $c): ?><option value="<?=htmlspecialchars($c['id'])?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Yeterlilik *</label><select class="form-select" name="qualification_id" id="qualification_id" required disabled><option value="">Önce başlık seçiniz...</option></select><div id="qualificationMappingWarning" class="form-text text-danger d-none">Mevcut yeterlilik bu başlığa bağlı değil. Kaydetmeden önce geçerli bir yeterlilik seçiniz.</div></div><div class="col-12"><label class="form-label">🇹🇷 Soru Metni *</label><textarea class="form-control" name="question_text" id="question_text" required></textarea></div><div class="col-12"><label class="form-label">🇬🇧 Question Text</label><textarea class="form-control" name="question_text_en" id="question_text_en"></textarea></div><div class="col-md-6"><label class="form-label">🇹🇷 Doğru Cevap *</label><input class="form-control" name="answer_text" id="answer_text" required></div><div class="col-md-6"><label class="form-label">🇬🇧 Correct Answer</label><input class="form-control" name="answer_text_en" id="answer_text_en"></div><div class="col-md-4"><label class="form-label">Sıra</label><input type="number" class="form-control" name="order_index" id="order_index" value="0"></div><div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked><label class="form-check-label" for="is_active">Aktif</label></div></div><div class="col-12"><label class="form-label">Not</label><textarea class="form-control" name="notes" id="notes"></textarea></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary" type="submit">Kaydet</button></div></form></div></div></div>

<?php $extra_js=<<<'JS'
<script>
 $(function(){
 const ep='../ajax/word-game-questions.php',m=bootstrap.Modal.getOrCreateInstance(document.getElementById('m'));
 const esc=v=>$('<div>').text(v??'').html();
 const api=(a,method='GET',data={})=>window.appAjax({url:ep+'?action='+encodeURIComponent(a),method,data,dataType:'json'});
  const perPageOptions=[10,25,50,100,500];
  const state={
    pagination:{page:1,per_page:25,total_count:0,total_pages:1},
    selectedQuestionIds:new Set(),
    visibleQuestionIds:[],
    parsedItems:[],
    validCount:0,
    invalidCount:0,
    totalCount:0,
    invalidItems:[],
    isBulkSaving:false,
    bulkQualificationReady:false
  };
 let bulkItems=[];
  function resetPage(){state.pagination.page=1;}
  function clearSelection(){
    state.selectedQuestionIds.clear();
    state.visibleQuestionIds=[];
    renderBulkSelectionState();
  }
  function renderBulkSelectionState(){
    const selectedCount=state.selectedQuestionIds.size;
    $('#bulkDeleteBtn').prop('disabled',selectedCount===0).text(`Seçilenleri Sil (${selectedCount})`);
    const visible=state.visibleQuestionIds||[];
    const hasRows=visible.length>0;
    const allVisibleSelected=hasRows && visible.every(id=>state.selectedQuestionIds.has(String(id)));
    $('#selectAllVisible').prop('checked',allVisibleSelected).prop('indeterminate',hasRows && !allVisibleSelected && visible.some(id=>state.selectedQuestionIds.has(String(id)))).prop('disabled',!hasRows);
  }
  function shortText(v,max=80){
    const t=String(v??'').trim();
    if(!t) return '-';
    return t.length>max?t.slice(0,max)+'…':t;
  }
  function updateBulkButtons(){const canParse=!!$('#bulkCategory').val()&&!!$('#bulkQualification').val()&&state.bulkQualificationReady&&!state.isBulkSaving;$('#parseBulk').prop('disabled',!canParse);$('#saveBulk').prop('disabled',state.isBulkSaving||state.validCount===0);}
  function resetBulkPreview(){state.parsedItems=[];state.totalCount=0;state.invalidItems=[];state.invalidCount=0;state.validCount=0;bulkItems=[];$('#bulkPreview').html('');updateBulkButtons();}
  async function loadCategoryQualifications(categoryId,targetSelect,selectedId=''){
    const $sel=$(targetSelect);$sel.prop('disabled',true).html('<option value="">Yükleniyor...</option>');
    if(!categoryId){$sel.html('<option value="">Önce başlık seçiniz...</option>');return [];} const r=await api('get_category_qualifications','GET',{category_id:categoryId});
    if(!r.success){$sel.html('<option value="">Yeterlilik yüklenemedi</option>');return [];} const qs=r.data?.qualifications||[];
    if(!qs.length){$sel.html('<option value="">Bu başlığa bağlı yeterlilik yok</option>').prop('disabled',true);return [];} $sel.html('<option value="">Seçiniz...</option>'+qs.map(q=>`<option value="${esc(q.id)}">${esc(q.name)}</option>`).join('')).prop('disabled',false);
    if(selectedId&&qs.some(q=>String(q.id)===String(selectedId))) $sel.val(selectedId); else $sel.val(''); return qs;
  }
  function renderBulkPreview(){
    if(state.totalCount===0){
      $('#bulkPreview').html('');
      $('#saveBulk').prop('disabled',true);
      return;
    }
    const hasInvalid=state.invalidCount>0;
    const invalidHtml=hasInvalid?`
      <div class="mt-2">
        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="collapse" data-bs-target="#bulkInvalidCollapse" aria-expanded="false" aria-controls="bulkInvalidCollapse">Hataları göster</button>
      </div>
      <div class="collapse mt-2" id="bulkInvalidCollapse">
        <div class="card card-body p-2">
          <div class="small text-muted mb-2">Sadece hatalı kayıtlar listelenir.</div>
          <ul class="mb-0 ps-3">
            ${state.invalidItems.map(item=>`<li class="mb-2"><div><strong>#${Number(item.index)+1}</strong> - <span class="text-danger">${esc((item.errors||[]).join(', ')||'Hata')}</span></div><div class="small text-muted">TR Soru: ${esc(shortText(item.record?.tr_question||''))} | TR Cevap: ${esc(shortText(item.record?.tr_answer||''))}</div></li>`).join('')}
          </ul>
        </div>
      </div>`:'';
    $('#bulkPreview').html(`
      <div class="card border-0" style="background:rgba(255,255,255,.03)">
        <div class="card-body py-3">
          <div class="fw-semibold mb-2">Ayrıştırma ve Veritabanı Doğrulama Sonucu</div>
          <div class="d-flex flex-wrap gap-2">
            <span class="badge text-bg-primary">Toplam: ${state.totalCount}</span>
            <span class="badge text-bg-success">Geçerli: ${state.validCount}</span>
            <span class="badge text-bg-danger">Hatalı: ${state.invalidCount}</span>
          </div>
          ${invalidHtml}
        </div>
      </div>
    `);
    updateBulkButtons();
  }
  function setPaginationFromResponse(data){
    const p=data?.pagination||{};
    state.pagination.page=Math.max(1,Number(p.page)||1);
    state.pagination.per_page=perPageOptions.includes(Number(p.per_page))?Number(p.per_page):25;
    state.pagination.total_count=Math.max(0,Number(p.total_count)||0);
    state.pagination.total_pages=Math.max(1,Number(p.total_pages)||1);
  }
  function renderPageButtons(current,total){
    const pages=[];
    if(total<=7){for(let i=1;i<=total;i++) pages.push(i);}else{pages.push(1);let s=Math.max(2,current-1),e=Math.min(total-1,current+1);if(s>2) pages.push('...');for(let i=s;i<=e;i++) pages.push(i);if(e<total-1) pages.push('...');pages.push(total);} 
    return pages.map(p=>p==='...'?'<span class="px-1 text-muted">...</span>':`<button class="btn btn-sm ${p===current?'btn-primary':'btn-outline-secondary'} page-btn" data-page="${p}">${p}</button>`).join('');
  }
  function renderPagination(){
    const p=state.pagination;
    const html=`<div class="d-flex align-items-center gap-2"><label class="mb-0 small text-muted">Sayfa başına</label><select id="perPageSelect" class="form-select form-select-sm" style="width:auto">${perPageOptions.map(v=>`<option value="${v}" ${v===p.per_page?'selected':''}>${v}</option>`).join('')}</select></div><div class="d-flex align-items-center gap-2"><span class="small text-muted">Sayfa ${p.page} / ${p.total_pages}</span><span class="small text-muted">Toplam: ${p.total_count}</span><button class="btn btn-sm btn-outline-secondary page-nav" data-dir="prev" ${p.page<=1?'disabled':''}>Önceki</button><span class="d-inline-flex gap-1">${renderPageButtons(p.page,p.total_pages)}</span><button class="btn btn-sm btn-outline-secondary page-nav" data-dir="next" ${p.page>=p.total_pages?'disabled':''}>Sonraki</button></div>`;
    $('#paginationTop,#paginationBottom').html(html);
  }
  async function load(){
    const r=await api('list_questions','GET',{category_id:$('#fCategory').val()||'',qualification_id:$('#fQualification').val()||'',is_active:$('#fActive').val()||'',search:$('#fSearch').val()||'',page:state.pagination.page,per_page:state.pagination.per_page});
    if(!r.success)return;
    setPaginationFromResponse(r.data||{});
    const rows=r.data?.questions||[];
    state.visibleQuestionIds=rows.map(x=>String(x.id));
    if(!rows.length){$('#tbl tbody').html('<tr><td colspan="9" class="text-muted">Kayıt bulunamadı</td></tr>'); renderPagination(); renderBulkSelectionState(); return;}
    $('#tbl tbody').html(rows.map(x=>`<tr><td><input type="checkbox" class="form-check-input row-select" data-id="${esc(x.id)}" ${state.selectedQuestionIds.has(String(x.id))?'checked':''}></td><td>${esc(x.category_name||'-')}</td><td>${esc(x.qualification_name||'-')}</td><td>${esc(x.question_text||'')}</td><td>${esc(x.answer_text||'')}</td><td>${esc(x.answer_text_en||'-')}</td><td>${esc(x.answer_length||0)}</td><td>${Number(x.is_active)===1?'<span class="badge text-bg-success">Aktif</span>':'<span class="badge text-bg-secondary">Pasif</span>'}</td><td><button class="btn btn-sm btn-outline-primary e" data-id="${esc(x.id)}"><i class="bi bi-pencil"></i></button> <button class="btn btn-sm btn-outline-secondary t" data-id="${esc(x.id)}" data-a="${Number(x.is_active)===1?1:0}"><i class="bi bi-toggle-${Number(x.is_active)===1?'off':'on'}"></i></button> <button class="btn btn-sm btn-outline-danger d" data-id="${esc(x.id)}"><i class="bi bi-trash"></i></button></td></tr>`).join(''));
    renderPagination();
    renderBulkSelectionState();
  }
 $('#addBtn').on('click',async()=>{$('#f')[0].reset();$('#id').val('');$('#order_index').val('0');$('#is_active').prop('checked',true);$('#qualificationMappingWarning').addClass('d-none');await loadCategoryQualifications('', '#qualification_id');m.show();});
  $('#category_id').on('change',async function(){await loadCategoryQualifications($(this).val(),'#qualification_id');$('#qualificationMappingWarning').addClass('d-none');});
  $('#bulkCategory').on('change',async function(){resetBulkPreview();$('#bulkQualificationInfo').addClass('d-none');state.bulkQualificationReady=false;const qs=await loadCategoryQualifications($(this).val(),'#bulkQualification');state.bulkQualificationReady=qs.length>0;if(!state.bulkQualificationReady&&$(this).val())$('#bulkQualificationInfo').removeClass('d-none');updateBulkButtons();});
  $('#bulkQualification').on('change',()=>{resetBulkPreview();updateBulkButtons();});
  $('#fCategory,#fQualification,#fActive').on('change',()=>{clearSelection();resetPage();load();}); $('#fSearch').on('input',()=>{clearTimeout(window.__wgs);window.__wgs=setTimeout(()=>{clearSelection();resetPage();load();},200)});
 $(document).on('click','.e',async function(){const r=await api('get_question','GET',{id:$(this).data('id')}); if(!r.success)return; const q=r.data.question; $('#f')[0].reset(); Object.keys(q).forEach(k=>$('#'+k).val(q[k]??'')); $('#is_active').prop('checked',Number(q.is_active)===1); const qs=await loadCategoryQualifications(q.category_id,'#qualification_id',q.qualification_id); const mapped=qs.some(x=>String(x.id)===String(q.qualification_id)); $('#qualificationMappingWarning').toggleClass('d-none',mapped); if(!mapped) $('#qualification_id').val(''); m.show();});
  $('#f').on('submit',async function(e){e.preventDefault();const id=$('#id').val();const r=await api(id?'update_question':'create_question','POST',$(this).serialize()); if(!r.success){await window.showAppAlert({title:'Hata',message:r.message||'İşlem başarısız',type:'error'}); return;} m.hide(); resetPage(); load();});
  $(document).on('click','.t',async function(){await api('toggle_active','POST',{id:$(this).data('id'),is_active:String($(this).data('a'))==='1'?0:1}); load();});
  $(document).on('click','.d',async function(){const id=String($(this).data('id')||'');await api('delete_question','POST',{id}); if(id) state.selectedQuestionIds.delete(id); load();});

  $('#parseBulk').on('click',async function(){
    const cid=$('#bulkCategory').val(),qid=$('#bulkQualification').val(); if(!cid||!qid){await window.showAppAlert({title:'Hata',message:'Başlık ve yeterlilik seçiniz.',type:'error'});return;} state.isBulkSaving=true; updateBulkButtons();
    try{const r=await api('parse_bulk_pattern','POST',{category_id:cid,qualification_id:qid,pattern:$('#bulkPattern').val()});
    if(!r.success)return;
    const items=(r.data.items||[]);
    state.parsedItems=items;
    state.totalCount=items.length;
    state.invalidItems=items.filter(x=>!x.valid);
    state.invalidCount=Number(r.data.invalid_count??state.invalidItems.length);
    state.validCount=Number(r.data.valid_count??(state.totalCount-state.invalidCount));
    bulkItems=items.filter(x=>x.valid).map(x=>x.record);
    renderBulkPreview();
    }finally{state.isBulkSaving=false;updateBulkButtons();}
  });
  $('#saveBulk').on('click',async function(){if(state.isBulkSaving)return;const cid=$('#bulkCategory').val(),qid=$('#bulkQualification').val(); if(!cid||!qid){await window.showAppAlert({title:'Hata',message:'Başlık ve yeterlilik seçiniz',type:'error'});return;} if(state.validCount===0){await window.showAppAlert({title:'Uyarı',message:'Kaydedilecek geçerli kayıt bulunamadı.',type:'warning'});return;} state.isBulkSaving=true; updateBulkButtons(); try{const r=await api('create_bulk_questions','POST',{category_id:cid,qualification_id:qid,items:JSON.stringify(bulkItems)}); if(!r.success){if(r.data?.validation?.items){state.parsedItems=r.data.validation.items;state.totalCount=state.parsedItems.length;state.invalidItems=state.parsedItems.filter(x=>!x.valid);state.invalidCount=Number(r.data.validation.invalid_count||state.invalidItems.length);state.validCount=Number(r.data.validation.valid_count||0);renderBulkPreview();}await window.showAppAlert({title:'Hata',message:r.message||'Kaydedilemedi',type:'error'});return;} const requested=Number(r.data.requested_count||0),created=Number(r.data.created_count||0); await window.showAppAlert({title:'Başarılı',message:`${created} / ${requested} kayıt başarıyla eklendi.`,type:'success'}); $('#bulkPattern').val(''); resetBulkPreview(); resetPage(); load();}finally{state.isBulkSaving=false;updateBulkButtons();}});
  $(document).on('change','.row-select',function(){
    const id=String($(this).data('id')||'');
    if(!id) return;
    if($(this).is(':checked')) state.selectedQuestionIds.add(id); else state.selectedQuestionIds.delete(id);
    renderBulkSelectionState();
  });
  $(document).on('change','#selectAllVisible',function(){
    const checked=$(this).is(':checked');
    (state.visibleQuestionIds||[]).forEach(id=>{ if(checked) state.selectedQuestionIds.add(String(id)); else state.selectedQuestionIds.delete(String(id)); });
    $('.row-select').prop('checked',checked);
    renderBulkSelectionState();
  });
  $('#bulkDeleteBtn').on('click',async function(){
    const ids=Array.from(state.selectedQuestionIds);
    const count=ids.length;
    if(count===0) return;
    const ok=window.confirm(`Seçili ${count} kelime oyunu sorusu silinecek. Emin misiniz?`);
    if(!ok) return;
    const r=await api('bulk_delete','POST',{ids:JSON.stringify(ids)});
    if(!r.success){await window.showAppAlert({title:'Hata',message:r.message||'Toplu silme başarısız',type:'error'});return;}
    state.selectedQuestionIds.clear();
    await window.showAppAlert({title:'Başarılı',message:`${Number(r.data?.deleted_count||0)} kayıt silindi.`,type:'success'});
    load();
  });
  $(document).on('change','#perPageSelect',function(){const v=Number($(this).val()); state.pagination.per_page=perPageOptions.includes(v)?v:25; clearSelection(); resetPage(); load();});
  $(document).on('click','.page-btn',function(){const p=Number($(this).data('page')); if(!Number.isFinite(p)) return; clearSelection(); state.pagination.page=Math.min(Math.max(1,p),state.pagination.total_pages); load();});
  $(document).on('click','.page-nav',function(){const dir=$(this).data('dir'); clearSelection(); if(dir==='prev') state.pagination.page=Math.max(1,state.pagination.page-1); else if(dir==='next') state.pagination.page=Math.min(state.pagination.total_pages,state.pagination.page+1); load();});
 updateBulkButtons(); load();
});
</script>
JS; include '../includes/footer.php'; ?>

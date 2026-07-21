<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
$user = require_auth();
$current_page = 'maritime-english';
$page_title = 'Maritime English';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
  <div class="page-header"><div><h2>Maritime English İçerik Aktarımı</h2><p class="text-muted mb-0">Standart çıktı metnini analiz edin, önizleyin ve tek işlemde kaydedin.</p></div></div>
  <div class="row g-3">
    <div class="col-lg-7"><div class="card"><div class="card-body">
      <div class="mb-3"><label class="form-label">Kategori *</label><select id="meCategory" class="form-select"><option value="">Yükleniyor...</option></select></div>
      <div class="mb-3"><label class="form-label">Yeterlilik <span class="text-muted">(opsiyonel)</span></label><select id="meQualification" class="form-select"><option value="">Bütün yeterlilikler</option></select></div>
      <label class="form-label">Toplu içerik metni *</label>
      <textarea id="meContent" class="form-control font-monospace" rows="24" placeholder="Kelime: Deck&#10;Türkçe: Güverte&#10;&#10;Cümleler&#10;..."></textarea>
      <div class="d-flex gap-2 mt-3"><button id="meParse" class="btn btn-primary"><i class="bi bi-search"></i> Metni Analiz Et</button><button id="meSave" class="btn btn-success" disabled><i class="bi bi-database-check"></i> Onayla ve Kaydet</button></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card"><div class="card-body"><h5>Önizleme</h5><div id="meMessage" class="text-muted">Henüz bir metin analiz edilmedi.</div><div id="mePreview" class="mt-3"></div></div></div></div>
  </div>

  <div class="card mt-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div><h5 class="mb-0">Eklenmiş Sorular</h5><small class="text-muted" id="meListSummary">Sorular yükleniyor...</small></div>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge text-bg-secondary d-none" id="meSelectedCount">0 seçili</span>
        <button type="button" class="btn btn-sm btn-outline-danger" id="meBulkDelete" disabled><i class="bi bi-trash"></i> Seçilenleri Sil</button>
        <button type="button" class="btn btn-sm btn-outline-primary" id="meListRefresh"><i class="bi bi-arrow-clockwise"></i> Yenile</button>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-3">
        <div class="col-md-3"><label class="form-label">Kategori</label><select id="meFilterCategory" class="form-select"><option value="">Tüm kategoriler</option></select></div>
        <div class="col-md-3"><label class="form-label">Soru Tipi</label><select id="meFilterType" class="form-select"><option value="">Tüm soru tipleri</option><option value="context_meaning">Bağlamdan Anlam</option><option value="fill_blank">Cümleyi Tamamlama</option><option value="translation">Çeviri</option><option value="dialogue">Mesleki Diyalog</option><option value="word_order">Kelime Sıralama</option><option value="wrong_usage">Yanlış Kullanım</option><option value="matching">Eşleştirme</option></select></div>
        <div class="col-md-2"><label class="form-label">Durum</label><select id="meFilterActive" class="form-select"><option value="">Tümü</option><option value="1">Aktif</option><option value="0">Pasif</option></select></div>
        <div class="col-md-3"><label class="form-label">Arama</label><input type="search" id="meFilterSearch" class="form-control" placeholder="Kelime, soru veya seçenek ara"></div>
        <div class="col-md-1"><label class="form-label">Adet</label><select id="mePerPage" class="form-select"><option>10</option><option selected>20</option><option>50</option><option>100</option></select></div>
      </div>
      <div id="meListError" class="alert alert-danger d-none"></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th><input class="form-check-input" type="checkbox" id="meSelectPage" aria-label="Bu sayfadaki soruların tümünü seç"></th><th style="min-width:130px">Kelime</th><th style="min-width:140px">Kategori</th><th style="min-width:130px">Soru Tipi</th><th style="min-width:320px">Soru ve Seçenekler</th><th>Doğru</th><th>Durum</th><th>Tarih</th><th>İşlem</th></tr></thead>
          <tbody id="meQuestionRows"><tr><td colspan="9" class="text-center text-muted py-4">Yükleniyor...</td></tr></tbody>
        </table>
      </div>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
        <div class="small text-muted" id="mePageInfo">Sayfa 1 / 1</div>
        <nav aria-label="Maritime English soru sayfaları"><ul class="pagination pagination-sm mb-0" id="mePagination"></ul></nav>
      </div>
    </div>
  </div>
</div>
<script>
(() => {
 const endpoint='../ajax/maritime-english-import.php'; let parsed=null; let listPage=1; let listLoading=false; let searchTimer=null; let currentPageIds=[]; const selectedIds=new Set();
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const typeLabel=t=>({context_meaning:'Bağlamdan Anlam',fill_blank:'Cümleyi Tamamlama',translation:'Çeviri',dialogue:'Mesleki Diyalog',word_order:'Kelime Sıralama',wrong_usage:'Yanlış Kullanım',matching:'Eşleştirme'}[t]||t);
 const post=async data=>{const b=new URLSearchParams(data);const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:b});const j=await r.json();if(!r.ok)throw j;return j;};
 fetch(endpoint+'?action=categories').then(r=>r.json()).then(j=>{const categoryOptions=j.data.categories.map(x=>`<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');document.querySelector('#meCategory').innerHTML='<option value="">Kategori seçin</option>'+categoryOptions;document.querySelector('#meFilterCategory').innerHTML='<option value="">Tüm kategoriler</option>'+categoryOptions;document.querySelector('#meQualification').innerHTML='<option value="">Bütün yeterlilikler</option>'+j.data.qualifications.map(x=>`<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');}).catch(()=>document.querySelector('#meCategory').innerHTML='<option value="">Kategoriler yüklenemedi</option>');
 const pageNumbers=(page,total)=>{const nums=new Set([1,total]);for(let i=Math.max(1,page-2);i<=Math.min(total,page+2);i++)nums.add(i);return [...nums].sort((a,b)=>a-b);};
 const renderPages=(page,total)=>{const ul=document.querySelector('#mePagination');const parts=[];parts.push(`<li class="page-item ${page<=1?'disabled':''}"><button class="page-link" data-page="${page-1}">Önceki</button></li>`);let prev=0;for(const n of pageNumbers(page,total)){if(prev&&n-prev>1)parts.push('<li class="page-item disabled"><span class="page-link">…</span></li>');parts.push(`<li class="page-item ${n===page?'active':''}"><button class="page-link" data-page="${n}">${n}</button></li>`);prev=n;}parts.push(`<li class="page-item ${page>=total?'disabled':''}"><button class="page-link" data-page="${page+1}">Sonraki</button></li>`);ul.innerHTML=parts.join('');ul.querySelectorAll('button[data-page]').forEach(b=>b.onclick=()=>{const p=Number(b.dataset.page);if(p>=1&&p<=total&&p!==page)loadQuestions(p);});};
 const updateSelectionUi=()=>{const count=selectedIds.size;const badge=document.querySelector('#meSelectedCount');badge.textContent=`${count} seçili`;badge.classList.toggle('d-none',count===0);document.querySelector('#meBulkDelete').disabled=count===0;const pageBox=document.querySelector('#meSelectPage');const selectedOnPage=currentPageIds.filter(id=>selectedIds.has(id)).length;pageBox.checked=currentPageIds.length>0&&selectedOnPage===currentPageIds.length;pageBox.indeterminate=selectedOnPage>0&&selectedOnPage<currentPageIds.length;};
 const bindSelection=()=>{document.querySelectorAll('.me-row-check').forEach(box=>{box.addEventListener('change',()=>{if(box.checked)selectedIds.add(box.dataset.id);else selectedIds.delete(box.dataset.id);updateSelectionUi();});});document.querySelectorAll('.me-delete-one').forEach(btn=>btn.addEventListener('click',()=>deleteQuestions([btn.dataset.id])));updateSelectionUi();};
 const deleteQuestions=async ids=>{ids=[...new Set(ids.filter(Boolean))];if(!ids.length)return;if(!confirm(ids.length===1?'Bu soru silinsin mi? Kullanıcıların geçmiş oturum sonuçları korunacaktır.':`${ids.length} soru silinsin mi? Kullanıcıların geçmiş oturum sonuçları korunacaktır.`))return;const btn=document.querySelector('#meBulkDelete');btn.disabled=true;try{const j=await post({action:'bulk_delete',ids:JSON.stringify(ids)});ids.forEach(id=>selectedIds.delete(id));updateSelectionUi();alert(j.message||`${ids.length} soru silindi.`);await loadQuestions(listPage);}catch(e){alert(e?.message||'Sorular silinemedi.');updateSelectionUi();}};
 const loadQuestions=async(page=1)=>{if(listLoading)return;listLoading=true;const rows=document.querySelector('#meQuestionRows');const err=document.querySelector('#meListError');err.classList.add('d-none');rows.innerHTML='<tr><td colspan="9" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor...</td></tr>';const qs=new URLSearchParams({action:'list',page:String(page),per_page:document.querySelector('#mePerPage').value,category_id:document.querySelector('#meFilterCategory').value,question_type:document.querySelector('#meFilterType').value,is_active:document.querySelector('#meFilterActive').value,search:document.querySelector('#meFilterSearch').value.trim()});try{const r=await fetch(endpoint+'?'+qs);const j=await r.json();if(!r.ok||!j.success)throw j;const items=j.data.items||[];const p=j.data.pagination;listPage=p.page;currentPageIds=items.map(x=>String(x.id));document.querySelector('#meListSummary').textContent=`Toplam ${p.total} soru`;document.querySelector('#mePageInfo').textContent=`Sayfa ${p.page} / ${p.total_pages} • Bu sayfada ${items.length} kayıt`;rows.innerHTML=items.length?items.map(x=>{const id=String(x.id);const options=Object.entries(x.options||{}).map(([k,v])=>`<div class="small ${k===x.correct_option_key?'text-success fw-semibold':''}"><span class="badge ${k===x.correct_option_key?'bg-success':'bg-secondary'} me-1">${esc(k)}</span>${esc(v)}</div>`).join('');return `<tr><td><input class="form-check-input me-row-check" type="checkbox" data-id="${esc(id)}" ${selectedIds.has(id)?'checked':''} aria-label="Soruyu seç"></td><td><strong>${esc(x.term_en)}</strong><div class="small text-muted">${esc(x.term_tr)}</div></td><td>${esc(x.category_name)}${x.qualification_name?`<div class="small text-muted">${esc(x.qualification_name)}</div>`:''}</td><td><span class="badge text-bg-info">${esc(typeLabel(x.question_type))}</span></td><td><div class="fw-semibold mb-2" style="white-space:pre-wrap">${esc(x.prompt)}</div>${options}</td><td><span class="badge text-bg-success">${esc(x.correct_option_key)}</span></td><td><span class="badge ${Number(x.is_active)===1?'text-bg-success':'text-bg-secondary'}">${Number(x.is_active)===1?'Aktif':'Pasif'}</span></td><td class="small text-nowrap">${esc(x.created_at)}</td><td><button type="button" class="btn btn-sm btn-outline-danger me-delete-one" data-id="${esc(id)}" title="Soruyu sil"><i class="bi bi-trash"></i></button></td></tr>`;}).join(''):'<tr><td colspan="9" class="text-center text-muted py-5">Filtrelere uygun soru bulunamadı.</td></tr>';renderPages(p.page,p.total_pages);bindSelection();}catch(e){currentPageIds=[];rows.innerHTML='<tr><td colspan="9" class="text-center text-danger py-4">Sorular yüklenemedi.</td></tr>';err.textContent=e?.message||'Soru listesi alınamadı.';err.classList.remove('d-none');updateSelectionUi();}finally{listLoading=false;}};
 ['#meFilterCategory','#meFilterType','#meFilterActive','#mePerPage'].forEach(s=>document.querySelector(s).addEventListener('change',()=>loadQuestions(1)));document.querySelector('#meFilterSearch').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>loadQuestions(1),350);});document.querySelector('#meListRefresh').onclick=()=>loadQuestions(listPage);
 document.querySelector('#meSelectPage').addEventListener('change',e=>{currentPageIds.forEach(id=>{if(e.target.checked)selectedIds.add(id);else selectedIds.delete(id);});document.querySelectorAll('.me-row-check').forEach(box=>box.checked=e.target.checked);updateSelectionUi();});document.querySelector('#meBulkDelete').onclick=()=>deleteQuestions([...selectedIds]);
 document.querySelector('#meParse').onclick=async()=>{parsed=null;document.querySelector('#meSave').disabled=true;const msg=document.querySelector('#meMessage');msg.textContent='Analiz ediliyor...';document.querySelector('#mePreview').innerHTML='';try{const j=await post({action:'parse',content:document.querySelector('#meContent').value});parsed=j.data.data;msg.className='text-success';msg.textContent=j.message;const d=parsed;document.querySelector('#mePreview').innerHTML=`<dl><dt>Kelime</dt><dd>${esc(d.term_en)} — ${esc(d.term_tr)}</dd><dt>Cümleler</dt><dd>${d.sentences.length}</dd><dt>Sorular</dt><dd>${d.questions.length}</dd></dl><ol class="small">${d.questions.map(q=>`<li class="mb-2"><strong>${esc(q.type)}</strong><br>${esc(q.prompt)}<br><span class="text-success">Cevap: ${esc(q.correct_key)} — ${esc(q.options[q.correct_key])}</span></li>`).join('')}</ol>`;document.querySelector('#meSave').disabled=false;}catch(e){const errors=e?.data?.errors||e?.data?.data?.errors||[];msg.className='text-danger';msg.innerHTML='<strong>Metin doğrulanamadı.</strong><ul>'+errors.map(x=>`<li>${esc(x)}</li>`).join('')+'</ul>';}};
 document.querySelector('#meSave').onclick=async()=>{if(!parsed)return;const category=document.querySelector('#meCategory').value;if(!category){alert('Kategori seçmelisiniz.');return;}const btn=document.querySelector('#meSave');btn.disabled=true;try{const j=await post({action:'import',category_id:category,qualification_id:document.querySelector('#meQualification').value,parsed_json:JSON.stringify(parsed)});document.querySelector('#meMessage').className='text-success';document.querySelector('#meMessage').textContent=`${j.message} ${j.data.sentences_added} cümle, ${j.data.questions_added} soru eklendi.`;parsed=null;loadQuestions(1);}catch(e){document.querySelector('#meMessage').className='text-danger';document.querySelector('#meMessage').textContent=e?.message||'Kayıt başarısız.';btn.disabled=false;}};
 loadQuestions(1);
})();
</script>
<?php include '../includes/footer.php'; ?>

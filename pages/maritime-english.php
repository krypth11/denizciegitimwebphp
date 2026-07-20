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
</div>
<script>
(() => {
 const endpoint='../ajax/maritime-english-import.php'; let parsed=null;
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const post=async data=>{const b=new URLSearchParams(data);const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:b});const j=await r.json();if(!r.ok)throw j;return j;};
 fetch(endpoint+'?action=categories').then(r=>r.json()).then(j=>{document.querySelector('#meCategory').innerHTML='<option value="">Kategori seçin</option>'+j.data.categories.map(x=>`<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');document.querySelector('#meQualification').innerHTML='<option value="">Bütün yeterlilikler</option>'+j.data.qualifications.map(x=>`<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');}).catch(()=>document.querySelector('#meCategory').innerHTML='<option value="">Kategoriler yüklenemedi</option>');
 document.querySelector('#meParse').onclick=async()=>{parsed=null;document.querySelector('#meSave').disabled=true;const msg=document.querySelector('#meMessage');msg.textContent='Analiz ediliyor...';document.querySelector('#mePreview').innerHTML='';try{const j=await post({action:'parse',content:document.querySelector('#meContent').value});parsed=j.data.data;msg.className='text-success';msg.textContent=j.message;const d=parsed;document.querySelector('#mePreview').innerHTML=`<dl><dt>Kelime</dt><dd>${esc(d.term_en)} — ${esc(d.term_tr)}</dd><dt>Cümleler</dt><dd>${d.sentences.length}</dd><dt>Sorular</dt><dd>${d.questions.length}</dd></dl><ol class="small">${d.questions.map(q=>`<li class="mb-2"><strong>${esc(q.type)}</strong><br>${esc(q.prompt)}<br><span class="text-success">Cevap: ${esc(q.correct_key)} — ${esc(q.options[q.correct_key])}</span></li>`).join('')}</ol>`;document.querySelector('#meSave').disabled=false;}catch(e){const errors=e?.data?.errors||e?.data?.data?.errors||[];msg.className='text-danger';msg.innerHTML='<strong>Metin doğrulanamadı.</strong><ul>'+errors.map(x=>`<li>${esc(x)}</li>`).join('')+'</ul>';}};
 document.querySelector('#meSave').onclick=async()=>{if(!parsed)return;const category=document.querySelector('#meCategory').value;if(!category){alert('Kategori seçmelisiniz.');return;}const btn=document.querySelector('#meSave');btn.disabled=true;try{const j=await post({action:'import',category_id:category,qualification_id:document.querySelector('#meQualification').value,parsed_json:JSON.stringify(parsed)});document.querySelector('#meMessage').className='text-success';document.querySelector('#meMessage').textContent=`${j.message} ${j.data.sentences_added} cümle, ${j.data.questions_added} soru eklendi.`;parsed=null;}catch(e){document.querySelector('#meMessage').className='text-danger';document.querySelector('#meMessage').textContent=e?.message||'Kayıt başarısız.';btn.disabled=false;}};
})();
</script>
<?php include '../includes/footer.php'; ?>

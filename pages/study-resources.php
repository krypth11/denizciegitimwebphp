<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
$user = require_auth();
$current_page = 'study-resources';
$page_title = 'Çalışma Kaynakları';
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div><h2>Çalışma Kaynakları Yönetimi</h2><p class="text-muted mb-0">Kapsamları ve PDF kaynaklarını yönetin.</p></div>
    </div>
    <div class="card mb-3"><div class="card-body">
        <form id="uploadForm" enctype="multipart/form-data" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Yeterlilik</label><input class="form-control" name="qualification_id" required></div>
            <div class="col-md-3"><label class="form-label">Ders</label><input class="form-control" name="course_id" required></div>
            <div class="col-md-2"><label class="form-label">Konu</label><input class="form-control" name="topic_id"></div>
            <div class="col-md-2"><label class="form-label">PDF</label><input class="form-control" type="file" name="pdfs[]" accept="application/pdf" multiple required></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Yükle</button></div>
        </form>
    </div></div>
    <div class="card"><div class="card-body">
        <div class="table-responsive"><table class="table table-hover"><thead><tr><th>PDF</th><th>Kapsam</th><th>Boyut</th><th>Sayfa</th><th>Durum</th><th>Sayaç</th><th>İşlem</th></tr></thead><tbody id="pdfBody"></tbody></table></div>
    </div></div>
</div>
<?php $extra_js = <<<'JS'
<script>
$(function(){
    const api='/ajax/study-resources.php';
    async function load(){
        const r=await window.appAjax({url:api,method:'GET',data:{action:'list'},dataType:'json'});
        if(!r.success){ return window.showAppAlert({title:'Hata',message:r.message||'Yüklenemedi',type:'error'}); }
        const rows=r.data?.pdfs||[]; const $b=$('#pdfBody').empty();
        if(!rows.length){ $b.html('<tr><td colspan="7" class="text-muted text-center py-4">Kayıt yok</td></tr>'); return; }
        rows.forEach(x=>{ $b.append(`<tr><td>${x.title||'-'}</td><td>${x.qualification_name||'-'} / ${x.course_name||'-'} / ${x.topic_name||'-'}</td><td>${x.file_size_bytes||0}</td><td>${x.page_count||'-'}</td><td>${Number(x.is_premium)===1?'Premium':'Free'} / ${Number(x.is_active)===1?'Aktif':'Pasif'}</td><td>${x.open_count||0}/${x.download_count||0}</td><td><a class="btn btn-sm btn-outline-info" href="/api/v1/study-resources/download.php?pdf_id=${encodeURIComponent(x.id)}" target="_blank">İndir</a></td></tr>`); });
    }
    $('#uploadForm').on('submit', async function(e){
        e.preventDefault();
        const fd=new FormData(this); fd.append('action','upload_pdfs'); fd.append('is_active','1');
        const r=await $.ajax({url:api,method:'POST',data:fd,processData:false,contentType:false,dataType:'json'}).catch(x=>x.responseJSON||{success:false,message:'Hata'});
        if(!r.success) return window.showAppAlert({title:'Hata',message:r.message||'Yükleme başarısız',type:'error'});
        await window.showAppAlert({title:'Başarılı',message:r.message||'Yüklendi',type:'success'}); this.reset(); load();
    });
    load();
});
</script>
JS;
include '../includes/footer.php';

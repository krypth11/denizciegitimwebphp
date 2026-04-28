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
            <div class="col-md-3"><label class="form-label">Yeterlilik</label><select class="form-select" id="uploadQualification" name="qualification_id" required></select></div>
            <div class="col-md-3"><label class="form-label">Ders</label><select class="form-select" id="uploadCourse" name="course_id" required></select></div>
            <div class="col-md-2"><label class="form-label">Konu (Opsiyonel)</label><select class="form-select" id="uploadTopic" name="topic_id"></select></div>
            <div class="col-md-2"><label class="form-label">PDF</label><input class="form-control" type="file" name="pdfs[]" accept="application/pdf" multiple required></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Yükle</button></div>
        </form>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label">Yeterlilik</label><select class="form-select" id="filterQualification" name="qualification_id"></select></div>
            <div class="col-md-3"><label class="form-label">Ders</label><select class="form-select" id="filterCourse" name="course_id"></select></div>
            <div class="col-md-3"><label class="form-label">Konu</label><select class="form-select" id="filterTopic" name="topic_id"></select></div>
            <div class="col-md-3"><label class="form-label">Ara</label><input class="form-control" id="filterSearch" name="search" placeholder="PDF başlığı / dosya adı"></div>
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
    let scope={qualifications:[],courses:[],topics:[]};

    function renderSelect($el, items, placeholder, valueKey='id', labelKey='name', allowEmpty=true){
        const prev = $el.val();
        $el.empty();
        if(allowEmpty) $el.append(`<option value="">${placeholder}</option>`);
        (items||[]).forEach(i=> $el.append(`<option value="${String(i[valueKey]??'')}">${String(i[labelKey]??'-')}</option>`));
        if(prev && $el.find(`option[value="${prev}"]`).length){ $el.val(prev); }
    }

    function linkedCourses(qualificationId){
        return (scope.courses||[]).filter(c=>String(c.resource_qualification_id||'')===String(qualificationId||''));
    }
    function linkedTopics(courseId){
        return (scope.topics||[]).filter(t=>String(t.resource_course_id||'')===String(courseId||''));
    }

    function hydrateUploadScope(){
        renderSelect($('#uploadQualification'), scope.qualifications, 'Yeterlilik seçin', 'id', 'name', true);
        const qId = $('#uploadQualification').val();
        renderSelect($('#uploadCourse'), linkedCourses(qId), 'Ders seçin', 'id', 'name', true);
        const cId = $('#uploadCourse').val();
        renderSelect($('#uploadTopic'), linkedTopics(cId), 'Konu seçin (opsiyonel)', 'id', 'name', true);
    }

    function hydrateFilterScope(){
        renderSelect($('#filterQualification'), scope.qualifications, 'Tümü', 'id', 'name', true);
        const qId = $('#filterQualification').val();
        renderSelect($('#filterCourse'), linkedCourses(qId), 'Tümü', 'id', 'name', true);
        const cId = $('#filterCourse').val();
        renderSelect($('#filterTopic'), linkedTopics(cId), 'Tümü', 'id', 'name', true);
    }

    async function load(){
        const data={
            action:'list',
            qualification_id: $('#filterQualification').val()||'',
            course_id: $('#filterCourse').val()||'',
            topic_id: $('#filterTopic').val()||'',
            search: $('#filterSearch').val()||''
        };
        const r=await window.appAjax({url:api,method:'GET',data,dataType:'json'});
        if(!r.success){ return window.showAppAlert({title:'Hata',message:r.message||'Yüklenemedi',type:'error'}); }
        scope = r.data?.scope || {qualifications:[],courses:[],topics:[]};
        hydrateUploadScope();
        hydrateFilterScope();
        const rows=r.data?.pdfs||[]; const $b=$('#pdfBody').empty();
        if(!rows.length){ $b.html('<tr><td colspan="7" class="text-muted text-center py-4">Kayıt yok</td></tr>'); return; }
        rows.forEach(x=>{ $b.append(`<tr><td>${x.title||'-'}</td><td>${x.qualification_name||'-'} / ${x.course_name||'-'} / ${x.topic_name||'-'}</td><td>${x.file_size_bytes||0}</td><td>${x.page_count||'-'}</td><td>${Number(x.is_premium)===1?'Premium':'Free'} / ${Number(x.is_active)===1?'Aktif':'Pasif'}</td><td>${x.open_count||0}/${x.download_count||0}</td><td><a class="btn btn-sm btn-outline-info" href="/api/v1/study-resources/download.php?pdf_id=${encodeURIComponent(x.id)}" target="_blank">İndir</a></td></tr>`); });
    }

    $('#uploadQualification').on('change', function(){
        renderSelect($('#uploadCourse'), linkedCourses(this.value), 'Ders seçin', 'id', 'name', true);
        renderSelect($('#uploadTopic'), linkedTopics($('#uploadCourse').val()), 'Konu seçin (opsiyonel)', 'id', 'name', true);
    });
    $('#uploadCourse').on('change', function(){
        renderSelect($('#uploadTopic'), linkedTopics(this.value), 'Konu seçin (opsiyonel)', 'id', 'name', true);
    });

    $('#filterQualification').on('change', function(){
        renderSelect($('#filterCourse'), linkedCourses(this.value), 'Tümü', 'id', 'name', true);
        renderSelect($('#filterTopic'), linkedTopics($('#filterCourse').val()), 'Tümü', 'id', 'name', true);
        load();
    });
    $('#filterCourse').on('change', function(){
        renderSelect($('#filterTopic'), linkedTopics(this.value), 'Tümü', 'id', 'name', true);
        load();
    });
    $('#filterTopic').on('change', load);
    let searchTimer=null;
    $('#filterSearch').on('input', function(){
        clearTimeout(searchTimer);
        searchTimer = setTimeout(load, 250);
    });

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

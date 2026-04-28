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
            <div class="col-md-2"><label class="form-label">PDF</label><input id="uploadPdfsInput" class="form-control" type="file" name="pdfs[]" accept="application/pdf" multiple required></div>
            <div class="col-md-2"><button id="uploadSubmitBtn" class="btn btn-primary w-100" type="submit">Yükle</button></div>
            <div class="col-12"><small class="text-muted">PDF başına maksimum 250 MB. Tek seferde en fazla 20 PDF yükleyebilirsin.</small></div>
        </form>
        <div id="uploadProgressWrap" class="mt-3 d-none">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Dosya</th>
                            <th>Boyut</th>
                            <th>Durum</th>
                            <th style="min-width:220px;">İlerleme</th>
                        </tr>
                    </thead>
                    <tbody id="uploadProgressBody"></tbody>
                </table>
            </div>
        </div>
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

    function formatFileSize(bytes){
        const n = Number(bytes||0);
        if(!Number.isFinite(n) || n<=0) return '0 B';
        const units=['B','KB','MB','GB'];
        let i=0,val=n;
        while(val>=1024 && i<units.length-1){ val/=1024; i++; }
        return `${val.toFixed(i===0?0:2)} ${units[i]}`;
    }

    const MAX_FILES = 20;
    const MAX_FILE_BYTES = 250 * 1024 * 1024;
    const MAX_PARALLEL = 2;
    let uploadInProgress = false;

    function setUploadingState(isUploading){
        uploadInProgress = !!isUploading;
        $('#uploadSubmitBtn').prop('disabled', uploadInProgress);
    }

    window.addEventListener('beforeunload', function(e){
        if(!uploadInProgress) return;
        e.preventDefault();
        e.returnValue = '';
    });

    function renderProgressRows(items){
        const $body = $('#uploadProgressBody').empty();
        if(!items.length){
            $('#uploadProgressWrap').addClass('d-none');
            return;
        }
        $('#uploadProgressWrap').removeClass('d-none');
        items.forEach(item=>{
            $body.append(`
                <tr id="uploadRow_${item.id}">
                    <td>
                        <div class="fw-medium">${$('<div>').text(item.file.name||'-').html()}</div>
                        <small id="uploadError_${item.id}" class="text-danger d-none"></small>
                    </td>
                    <td>${formatFileSize(item.file.size||0)}</td>
                    <td><span id="uploadStatus_${item.id}" class="badge text-bg-secondary">Bekliyor</span></td>
                    <td>
                        <div class="progress" style="height:14px;">
                            <div id="uploadBar_${item.id}" class="progress-bar" role="progressbar" style="width:0%">0%</div>
                        </div>
                    </td>
                </tr>
            `);
        });
    }

    function updateProgressItem(item, patch){
        Object.assign(item, patch||{});
        const statusMap = {
            waiting:{label:'Bekliyor',cls:'text-bg-secondary'},
            uploading:{label:'Yükleniyor',cls:'text-bg-primary'},
            done:{label:'Yüklendi',cls:'text-bg-success'},
            error:{label:'Hata',cls:'text-bg-danger'}
        };
        const st = statusMap[item.status] || statusMap.waiting;
        const percent = Math.max(0, Math.min(100, Number(item.progress||0)));
        const $status = $(`#uploadStatus_${item.id}`);
        const $bar = $(`#uploadBar_${item.id}`);
        const $err = $(`#uploadError_${item.id}`);
        $status.attr('class', `badge ${st.cls}`).text(st.label);
        $bar.css('width', `${percent}%`).text(`${Math.round(percent)}%`);
        if(item.status==='error' && item.error){
            $err.text(item.error).removeClass('d-none');
        } else {
            $err.text('').addClass('d-none');
        }
    }

    function uploadSinglePdf(baseFields, item){
        return new Promise((resolve)=>{
            const fd = new FormData();
            fd.append('action','upload_pdfs');
            fd.append('qualification_id', baseFields.qualification_id);
            fd.append('course_id', baseFields.course_id);
            if(baseFields.topic_id) fd.append('topic_id', baseFields.topic_id);
            fd.append('is_premium', baseFields.is_premium || '0');
            fd.append('is_active', baseFields.is_active || '1');
            fd.append('pdfs[]', item.file, item.file.name);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', api, true);
            xhr.responseType = 'json';

            xhr.upload.onprogress = function(evt){
                if(evt.lengthComputable){
                    const p = Math.round((evt.loaded / evt.total) * 100);
                    updateProgressItem(item, {progress:p, status:'uploading'});
                }
            };

            xhr.onload = function(){
                const res = xhr.response || (()=>{ try{return JSON.parse(xhr.responseText||'{}');}catch(_){return{};} })();
                if(xhr.status >= 200 && xhr.status < 300 && res && res.success){
                    updateProgressItem(item, {status:'done', progress:100, error:''});
                    resolve({success:true, response:res, item});
                    return;
                }
                const serverFailed = Array.isArray(res?.data?.failed) ? res.data.failed : [];
                const serverErr = serverFailed[0]?.error || res?.message || 'Yükleme başarısız';
                updateProgressItem(item, {status:'error', error:serverErr});
                resolve({success:false, response:res, item, error:serverErr});
            };

            xhr.onerror = function(){
                const err = 'Ağ hatası oluştu. Sunucu upload limiti bu dosya için düşük olabilir.';
                updateProgressItem(item, {status:'error', error:err});
                resolve({success:false, response:null, item, error:err});
            };

            updateProgressItem(item, {status:'uploading', progress:0, error:''});
            xhr.send(fd);
        });
    }

    async function processUploadQueue(baseFields, items){
        let cursor = 0;
        const results = [];

        async function worker(){
            while(cursor < items.length){
                const idx = cursor++;
                const it = items[idx];
                const result = await uploadSinglePdf(baseFields, it);
                results.push(result);
            }
        }

        const workers = [];
        const workerCount = Math.min(MAX_PARALLEL, items.length);
        for(let i=0; i<workerCount; i++) workers.push(worker());
        await Promise.all(workers);
        return results;
    }

    $('#uploadForm').on('submit', async function(e){
        e.preventDefault();
        if(uploadInProgress) return;

        const fileInput = document.getElementById('uploadPdfsInput');
        const files = Array.from(fileInput?.files || []);
        if(!files.length){
            return window.showAppAlert({title:'Uyarı',message:'Lütfen en az bir PDF seçin.',type:'warning'});
        }
        if(files.length > MAX_FILES){
            return window.showAppAlert({title:'Uyarı',message:'Tek seferde en fazla 20 PDF yükleyebilirsin.',type:'warning'});
        }

        for(const f of files){
            const ext = String(f.name||'').toLowerCase().split('.').pop();
            if(f.type !== 'application/pdf' || ext !== 'pdf'){
                return window.showAppAlert({title:'Uyarı',message:`${f.name}: Sadece application/pdf ve .pdf uzantılı dosyalar yüklenebilir.`,type:'warning'});
            }
            if(Number(f.size||0) > MAX_FILE_BYTES){
                return window.showAppAlert({title:'Uyarı',message:`${f.name}: Dosya boyutu 250 MB limitini aşıyor.`,type:'warning'});
            }
        }

        const qualificationId = $('#uploadQualification').val() || '';
        const courseId = $('#uploadCourse').val() || '';
        if(!qualificationId || !courseId){
            return window.showAppAlert({title:'Uyarı',message:'Yeterlilik ve ders zorunludur.',type:'warning'});
        }

        const uploadItems = files.map((file, idx)=>({
            id:`${Date.now()}_${idx}`,
            file,
            status:'waiting',
            progress:0,
            error:''
        }));
        renderProgressRows(uploadItems);
        setUploadingState(true);

        const baseFields = {
            qualification_id: qualificationId,
            course_id: courseId,
            topic_id: $('#uploadTopic').val() || '',
            is_premium: '0',
            is_active: '1'
        };

        const results = await processUploadQueue(baseFields, uploadItems);
        setUploadingState(false);

        const successCount = results.filter(r=>r.success).length;
        const failedResults = results.filter(r=>!r.success);
        const failedCount = failedResults.length;

        await load();

        if(successCount > 0 && failedCount > 0){
            await window.showAppAlert({
                title:'Kısmi Başarı',
                message:`${successCount} PDF yüklendi, ${failedCount} PDF yüklenemedi.`,
                type:'warning'
            });
        } else if(successCount > 0){
            await window.showAppAlert({title:'Başarılı',message:`${successCount} PDF yüklendi.`,type:'success'});
            this.reset();
        } else {
            const firstErr = failedResults[0]?.error || 'Hiçbir PDF yüklenemedi. Sunucu upload limiti bu dosya için düşük olabilir.';
            await window.showAppAlert({title:'Hata',message:firstErr,type:'error'});
        }
    });
    load();
});
</script>
JS;
include '../includes/footer.php';

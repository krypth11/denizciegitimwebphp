<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();
$current_page = 'question-scope-bulk';
$page_title = 'Toplu Soru Kapsamı';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Toplu Soru Kapsamı</h2>
            <p class="text-muted mb-0">Kaynak filtreye uyan sorular için hedef kapsama toplu ekleme / kaldırma yapın.</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="mb-3">A) Kaynak Soru Filtresi</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kaynak yeterlilik *</label>
                            <select class="form-select" id="sourceQualification">
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kaynak ders *</label>
                            <select class="form-select" id="sourceCourse" disabled>
                                <option value="">Önce yeterlilik seçin...</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Kaynak konu <span class="text-muted">(opsiyonel)</span></label>
                            <select class="form-select" id="sourceTopic" disabled>
                                <option value="">Tüm konular</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Soru tipi <span class="text-muted">(opsiyonel)</span></label>
                            <select class="form-select" id="questionType">
                                <option value="">Tümü</option>
                                <option value="sayısal">Sayısal</option>
                                <option value="sözel">Sözel</option>
                                <option value="karışık">Karışık</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Arama <span class="text-muted">(opsiyonel)</span></label>
                            <input type="search" class="form-control" id="searchText" placeholder="question_text içinde ara...">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="mb-3">B) Hedef Kapsam</h5>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Hedef yeterlilik *</label>
                            <select class="form-select" id="targetQualification">
                                <option value="">Seçiniz...</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Hedef ders *</label>
                            <select class="form-select" id="targetCourse" disabled>
                                <option value="">Önce yeterlilik seçin...</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Hedef konu <span class="text-muted">(opsiyonel)</span></label>
                            <select class="form-select" id="targetTopic" disabled>
                                <option value="">Konu seçmeden devam et</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-outline-primary" id="previewBtn">
                    <i class="bi bi-search"></i> Önizleme / Sayı Getir
                </button>
                <button class="btn btn-success" id="bulkAddBtn">
                    <i class="bi bi-plus-circle"></i> Toplu Kapsam Ekle
                </button>
                <button class="btn btn-danger" id="bulkRemoveBtn">
                    <i class="bi bi-dash-circle"></i> Toplu Kapsam Kaldır
                </button>
            </div>

            <div id="previewBox" class="d-none">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 bg-light-subtle">
                            <div class="small text-muted">Toplam kaynak soru</div>
                            <div class="h5 mb-0" id="pvTotal">0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 bg-light-subtle">
                            <div class="small text-muted">Zaten bağlı</div>
                            <div class="h5 mb-0" id="pvAlready">0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 bg-light-subtle">
                            <div class="small text-muted">Eklenecek</div>
                            <div class="h5 mb-0" id="pvWillAdd">0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 bg-light-subtle">
                            <div class="small text-muted">Primary sayısı</div>
                            <div class="h5 mb-0" id="pvPrimary">0</div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <h6 class="mb-2">Örnek Sorular (İlk 10)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="sampleTable">
                            <thead>
                                <tr>
                                    <th style="width: 120px;">ID</th>
                                    <th>Soru Metni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="2" class="text-muted">Henüz önizleme alınmadı.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h5 class="mb-0">Kayıtlı Kapsam Eşleştirmeleri</h5>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-primary" id="refreshMappingsBtn">
                        <i class="bi bi-arrow-clockwise"></i> Listeyi Yenile
                    </button>
                    <button class="btn btn-primary" id="bulkSyncMappingsBtn">
                        <i class="bi bi-arrow-repeat"></i> Seçilenleri Eşzamanla
                    </button>
                    <button class="btn btn-outline-danger" id="bulkCancelMappingsBtn">
                        <i class="bi bi-x-circle"></i> Seçilenleri İptal Et
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle" id="mappingsTable">
                    <thead>
                        <tr>
                            <th style="width: 42px;">
                                <input class="form-check-input" type="checkbox" id="selectAllMappings">
                            </th>
                            <th>Kaynak</th>
                            <th>Hedef</th>
                            <th>Filtre</th>
                            <th class="text-end">Kaynaktaki soru</th>
                            <th class="text-end">Hedefte bağlı</th>
                            <th class="text-end">Eksik</th>
                            <th>Durum</th>
                            <th>Son eşzamanlama</th>
                            <th style="min-width: 180px;">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="10" class="text-muted">Yükleniyor...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JS'
<script>
$(function () {
    const esc = (v) => $('<div>').text(v ?? '').html();
    const numberTr = (v) => Number(v || 0).toLocaleString('tr-TR');

    const endpoint = '../ajax/question-scope-bulk.php';
    const lookupEndpoint = '../ajax/questions.php';
    let mappingsCache = [];

    function appAlert(title, message, type = 'info') {
        if (typeof window.showAppAlert === 'function') {
            return window.showAppAlert({ title, message, type });
        }
        return Promise.resolve();
    }

    function appConfirm(opts) {
        if (typeof window.showAppConfirm === 'function') {
            return window.showAppConfirm(opts);
        }
        return Promise.resolve(false);
    }

    function parseRespError(resp, fallback = 'İşlem başarısız oldu.') {
        return (resp && resp.message) ? resp.message : fallback;
    }

    function getPayload() {
        return {
            source_qualification_id: $('#sourceQualification').val() || '',
            source_course_id: $('#sourceCourse').val() || '',
            source_topic_id: $('#sourceTopic').val() || '',
            question_type: $('#questionType').val() || '',
            search: $('#searchText').val().trim(),
            target_qualification_id: $('#targetQualification').val() || '',
            target_course_id: $('#targetCourse').val() || '',
            target_topic_id: $('#targetTopic').val() || ''
        };
    }

    function formatScope(qualificationName, courseName, topicName, emptyTopicLabel = 'Tüm konular') {
        const q = qualificationName || '-';
        const c = courseName || '-';
        const t = topicName || emptyTopicLabel;
        return '<div><strong>' + esc(q) + '</strong> / ' + esc(c) + '<br><span class="text-muted small">' + esc(t) + '</span></div>';
    }

    function formatDateTime(value) {
        if (!value) {
            return '<span class="text-muted">-</span>';
        }
        const dt = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(dt.getTime())) {
            return esc(value);
        }
        return dt.toLocaleString('tr-TR');
    }

    function statusBadge(label) {
        const map = {
            'Güncel': 'bg-success-subtle text-success',
            'Güncelleme var': 'bg-warning-subtle text-warning-emphasis',
            'Kaynak boş': 'bg-secondary-subtle text-secondary'
        };
        const cls = map[label] || 'bg-light text-dark';
        return '<span class="badge rounded-pill ' + cls + '">' + esc(label || '-') + '</span>';
    }

    function renderFilter(mapping) {
        const parts = [];
        if (mapping.question_type) {
            parts.push('Tip: ' + mapping.question_type);
        }
        if (mapping.search_text) {
            parts.push('Arama: "' + mapping.search_text + '"');
        }
        if (!parts.length) {
            return '<span class="text-muted">Ek filtre yok</span>';
        }
        return '<span class="small">' + esc(parts.join(' • ')) + '</span>';
    }

    function getSelectedMappingIds() {
        return $('#mappingsTable tbody .mapping-check:checked').map(function () {
            return $(this).val();
        }).get();
    }

    function renderMappingsTable(rows) {
        const $tbody = $('#mappingsTable tbody');

        if (!rows.length) {
            $tbody.html('<tr><td colspan="10" class="text-muted">Kayıtlı eşleştirme bulunamadı.</td></tr>');
            $('#selectAllMappings').prop('checked', false);
            return;
        }

        const html = rows.map((m) => {
            return '<tr data-id="' + esc(m.id) + '">' +
                '<td><input class="form-check-input mapping-check" type="checkbox" value="' + esc(m.id) + '"></td>' +
                '<td>' + formatScope(m.source_qualification_name, m.source_course_name, m.source_topic_name, 'Tüm konular') + '</td>' +
                '<td>' + formatScope(m.target_qualification_name, m.target_course_name, m.target_topic_name, 'Konu seçilmeden') + '</td>' +
                '<td>' + renderFilter(m) + '</td>' +
                '<td class="text-end">' + numberTr(m.source_count) + '</td>' +
                '<td class="text-end">' + numberTr(m.target_linked_count) + '</td>' +
                '<td class="text-end">' + numberTr(m.missing_count) + '</td>' +
                '<td>' + statusBadge(m.status_label) + '</td>' +
                '<td class="small">' + formatDateTime(m.last_synced_at) + '</td>' +
                '<td>' +
                    '<div class="btn-group btn-group-sm" role="group">' +
                        '<button class="btn btn-outline-primary sync-mapping-btn" data-id="' + esc(m.id) + '">Eşzamanla</button>' +
                        '<button class="btn btn-outline-danger cancel-mapping-btn" data-id="' + esc(m.id) + '">İptal Et</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        });

        $tbody.html(html.join(''));
        $('#selectAllMappings').prop('checked', false);
    }

    async function loadMappings() {
        window.appSetButtonLoading('#refreshMappingsBtn', true, 'Yükleniyor...');
        const res = await window.appAjax({
            url: endpoint + '?action=mappings',
            method: 'GET',
            dataType: 'json'
        });
        window.appSetButtonLoading('#refreshMappingsBtn', false);

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Eşleştirme listesi alınamadı.'), 'error');
            return;
        }

        mappingsCache = res.data?.mappings || [];
        renderMappingsTable(mappingsCache);
    }

    async function syncSingleMapping(mappingId) {
        const confirmed = await appConfirm({
            title: 'Eşzamanla',
            message: 'Seçili kaynak-hedef eşleştirmesi için eksik bağlantılar eklenecek. Onaylıyor musunuz?',
            confirmText: 'Eşzamanla',
            cancelText: 'İptal',
            type: 'confirm'
        });

        if (!confirmed) {
            return;
        }

        const res = await window.appAjax({
            url: endpoint + '?action=sync_mapping',
            method: 'POST',
            data: { mapping_id: mappingId },
            dataType: 'json'
        });

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Eşzamanlama başarısız oldu.'), 'error');
            return;
        }

        const d = res.data || {};
        await appAlert('Başarılı',
            (res.message || 'Eşzamanlama tamamlandı.') +
            '\n\nKaynak: ' + numberTr(d.source_count) +
            '\nZaten bağlı: ' + numberTr(d.already_linked_count) +
            '\nYeni eklenen: ' + numberTr(d.inserted_count) +
            '\nKalan eksik: ' + numberTr(d.missing_count_after),
            'success'
        );
        await loadMappings();
    }

    async function cancelSingleMapping(mappingId) {
        const confirmed = await appConfirm({
            title: 'Eşleştirmeyi İptal Et',
            message: 'Bu eşleştirme için hedefteki bağlantılar kaldırılacak (primary linkler korunur). Onaylıyor musunuz?',
            confirmText: 'İptal Et',
            cancelText: 'Vazgeç',
            type: 'warning'
        });

        if (!confirmed) {
            return;
        }

        const res = await window.appAjax({
            url: endpoint + '?action=cancel_mapping',
            method: 'POST',
            data: { mapping_id: mappingId },
            dataType: 'json'
        });

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'İptal işlemi başarısız oldu.'), 'error');
            return;
        }

        const d = res.data || {};
        await appAlert('Başarılı',
            (res.message || 'İptal işlemi tamamlandı.') +
            '\n\nEşleşen link: ' + numberTr(d.matched_links) +
            '\nSilinen: ' + numberTr(d.deleted_count) +
            '\nAtlanan primary: ' + numberTr(d.skipped_primary_count),
            'success'
        );
        await loadMappings();
    }

    async function bulkSyncMappings(ids) {
        const confirmed = await appConfirm({
            title: 'Toplu Eşzamanla',
            message: 'Seçilen eşleştirmelerde eksik bağlantılar eklenecek. Onaylıyor musunuz?',
            confirmText: 'Toplu Eşzamanla',
            cancelText: 'İptal',
            type: 'confirm'
        });
        if (!confirmed) {
            return;
        }

        window.appSetButtonLoading('#bulkSyncMappingsBtn', true, 'İşleniyor...');
        const res = await window.appAjax({
            url: endpoint + '?action=bulk_sync_mappings',
            method: 'POST',
            data: { ids },
            dataType: 'json'
        });
        window.appSetButtonLoading('#bulkSyncMappingsBtn', false);

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Toplu eşzamanlama başarısız oldu.'), 'error');
            return;
        }

        const d = res.data || {};
        await appAlert('Başarılı',
            (res.message || 'Toplu eşzamanlama tamamlandı.') +
            '\n\nİşlenen: ' + numberTr(d.processed) +
            '\nToplam eklenen: ' + numberTr(d.total_inserted) +
            '\nHatalı: ' + numberTr(d.failed),
            'success'
        );
        await loadMappings();
    }

    async function bulkCancelMappings(ids) {
        const confirmed = await appConfirm({
            title: 'Toplu İptal',
            message: 'Seçilen eşleştirmelerin hedef bağlantıları kaldırılacak (primary korunur). Onaylıyor musunuz?',
            confirmText: 'Toplu İptal Et',
            cancelText: 'Vazgeç',
            type: 'warning'
        });
        if (!confirmed) {
            return;
        }

        window.appSetButtonLoading('#bulkCancelMappingsBtn', true, 'İşleniyor...');
        const res = await window.appAjax({
            url: endpoint + '?action=bulk_cancel_mappings',
            method: 'POST',
            data: { ids },
            dataType: 'json'
        });
        window.appSetButtonLoading('#bulkCancelMappingsBtn', false);

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Toplu iptal başarısız oldu.'), 'error');
            return;
        }

        const d = res.data || {};
        await appAlert('Başarılı',
            (res.message || 'Toplu iptal tamamlandı.') +
            '\n\nİşlenen: ' + numberTr(d.processed) +
            '\nToplam silinen: ' + numberTr(d.total_removed) +
            '\nHatalı: ' + numberTr(d.failed),
            'success'
        );
        await loadMappings();
    }

    function validatePayload(payload) {
        if (!payload.source_qualification_id || !payload.source_course_id) {
            return 'Kaynak yeterlilik ve kaynak ders seçilmelidir.';
        }
        if (!payload.target_qualification_id || !payload.target_course_id) {
            return 'Hedef yeterlilik ve hedef ders seçilmelidir.';
        }

        if (
            payload.source_qualification_id === payload.target_qualification_id &&
            payload.source_course_id === payload.target_course_id &&
            (payload.source_topic_id || '') === (payload.target_topic_id || '')
        ) {
            return 'Kaynak ve hedef kapsam aynı olamaz.';
        }

        return '';
    }

    function setTopicDisabled($select, disabled, emptyLabel = 'Tüm konular') {
        $select.prop('disabled', disabled);
        if (disabled) {
            $select.html('<option value="">' + esc(emptyLabel) + '</option>');
        }
    }

    async function loadQualifications() {
        const res = await window.appAjax({
            url: lookupEndpoint + '?action=list_qualifications',
            method: 'GET',
            dataType: 'json'
        });

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Yeterlilikler yüklenemedi.'), 'error');
            return;
        }

        const rows = res.data?.qualifications || [];
        const options = ['<option value="">Seçiniz...</option>'];
        rows.forEach((r) => options.push('<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>'));

        $('#sourceQualification').html(options.join(''));
        $('#targetQualification').html(options.join(''));
    }

    async function loadCourses(qualificationId, $courseSelect, $topicSelect, topicEmptyLabel) {
        $courseSelect.prop('disabled', true).html('<option value="">Yükleniyor...</option>');
        setTopicDisabled($topicSelect, true, topicEmptyLabel);

        if (!qualificationId) {
            $courseSelect.html('<option value="">Önce yeterlilik seçin...</option>');
            return;
        }

        const res = await window.appAjax({
            url: lookupEndpoint + '?action=list_courses',
            method: 'GET',
            data: { qualification_id: qualificationId },
            dataType: 'json'
        });

        if (!res.success) {
            $courseSelect.html('<option value="">Dersler yüklenemedi</option>');
            return;
        }

        const rows = res.data?.courses || [];
        const options = ['<option value="">Seçiniz...</option>'];
        rows.forEach((r) => options.push('<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>'));
        $courseSelect.html(options.join('')).prop('disabled', false);
    }

    async function loadTopics(courseId, $topicSelect, emptyLabel) {
        $topicSelect.prop('disabled', true).html('<option value="">Yükleniyor...</option>');

        if (!courseId) {
            $topicSelect.html('<option value="">' + esc(emptyLabel) + '</option>');
            return;
        }

        const res = await window.appAjax({
            url: lookupEndpoint + '?action=list_topics',
            method: 'GET',
            data: { course_id: courseId },
            dataType: 'json'
        });

        if (!res.success) {
            $topicSelect.html('<option value="">Konular yüklenemedi</option>');
            return;
        }

        const rows = res.data?.topics || [];
        const options = ['<option value="">' + esc(emptyLabel) + '</option>'];
        rows.forEach((r) => options.push('<option value="' + esc(r.id) + '">' + esc(r.name) + '</option>'));
        $topicSelect.html(options.join('')).prop('disabled', rows.length === 0);
    }

    function renderPreview(data) {
        const summary = data.summary || {};
        const samples = data.sample_questions || [];

        $('#pvTotal').text(numberTr(summary.total_source_questions));
        $('#pvAlready').text(numberTr(summary.already_linked_count));
        $('#pvWillAdd').text(numberTr(summary.will_add_count));
        $('#pvPrimary').text(numberTr(summary.primary_count));

        const $tbody = $('#sampleTable tbody');
        if (!samples.length) {
            $tbody.html('<tr><td colspan="2" class="text-muted">Örnek soru bulunamadı.</td></tr>');
        } else {
            const rows = samples.map((q) => {
                return '<tr>' +
                    '<td class="small text-muted">' + esc((q.id || '').slice(0, 12)) + '...</td>' +
                    '<td>' + esc(q.question_text || '').slice(0, 240) + '</td>' +
                '</tr>';
            });
            $tbody.html(rows.join(''));
        }

        $('#previewBox').removeClass('d-none');
    }

    async function doPreview() {
        const payload = getPayload();
        const validationError = validatePayload(payload);
        if (validationError) {
            await appAlert('Uyarı', validationError, 'warning');
            return null;
        }

        window.appSetButtonLoading('#previewBtn', true, 'Önizleniyor...');
        const res = await window.appAjax({
            url: endpoint + '?action=preview',
            method: 'POST',
            data: payload,
            dataType: 'json'
        });
        window.appSetButtonLoading('#previewBtn', false);

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Önizleme alınamadı.'), 'error');
            return null;
        }

        renderPreview(res.data || {});
        return res.data || null;
    }

    async function doBulkAction(action) {
        const payload = getPayload();
        const validationError = validatePayload(payload);
        if (validationError) {
            await appAlert('Uyarı', validationError, 'warning');
            return;
        }

        const isAdd = action === 'bulk_add';
        const confirmed = await appConfirm({
            title: isAdd ? 'Toplu Kapsam Ekle' : 'Toplu Kapsam Kaldır',
            message: isAdd
                ? 'Seçili filtreye uyan sorulara hedef kapsam eklenecek. Onaylıyor musunuz?'
                : 'Seçili filtredeki soruların hedef kapsam bağlantıları kaldırılacak (primary hariç). Onaylıyor musunuz?',
            confirmText: isAdd ? 'Ekle' : 'Kaldır',
            cancelText: 'İptal',
            type: isAdd ? 'confirm' : 'warning'
        });

        if (!confirmed) {
            return;
        }

        const btnId = isAdd ? '#bulkAddBtn' : '#bulkRemoveBtn';
        window.appSetButtonLoading(btnId, true, 'İşleniyor...');

        const res = await window.appAjax({
            url: endpoint + '?action=' + action,
            method: 'POST',
            data: payload,
            dataType: 'json'
        });

        window.appSetButtonLoading(btnId, false);

        if (!res.success) {
            await appAlert('Hata', parseRespError(res, 'Toplu işlem başarısız oldu.'), 'error');
            return;
        }

        const d = res.data || {};
        let detailMessage = '';
        if (isAdd) {
            detailMessage = 'Toplam kaynak: ' + numberTr(d.total_source_questions) +
                '\nYeni eklenen: ' + numberTr(d.inserted_count) +
                '\nAtlanan (zaten vardı): ' + numberTr(d.skipped_existing_count);
        } else {
            detailMessage = 'Eşleşen link: ' + numberTr(d.matched_links) +
                '\nSilinen: ' + numberTr(d.deleted_count) +
                '\nAtlanan primary: ' + numberTr(d.skipped_primary_count);
        }

        await appAlert('Başarılı', (res.message || 'İşlem tamamlandı.') + '\n\n' + detailMessage, 'success');
        await doPreview();
        if (isAdd) {
            await loadMappings();
        }
    }

    $('#sourceQualification').on('change', function () {
        loadCourses($(this).val(), $('#sourceCourse'), $('#sourceTopic'), 'Tüm konular');
    });

    $('#sourceCourse').on('change', function () {
        loadTopics($(this).val(), $('#sourceTopic'), 'Tüm konular');
    });

    $('#targetQualification').on('change', function () {
        loadCourses($(this).val(), $('#targetCourse'), $('#targetTopic'), 'Konu seçmeden devam et');
    });

    $('#targetCourse').on('change', function () {
        loadTopics($(this).val(), $('#targetTopic'), 'Konu seçmeden devam et');
    });

    $('#previewBtn').on('click', async function () {
        await doPreview();
    });

    $('#bulkAddBtn').on('click', async function () {
        await doBulkAction('bulk_add');
    });

    $('#bulkRemoveBtn').on('click', async function () {
        await doBulkAction('bulk_remove');
    });

    $('#refreshMappingsBtn').on('click', async function () {
        await loadMappings();
    });

    $('#selectAllMappings').on('change', function () {
        const checked = $(this).is(':checked');
        $('#mappingsTable tbody .mapping-check').prop('checked', checked);
    });

    $(document).on('change', '#mappingsTable tbody .mapping-check', function () {
        const total = $('#mappingsTable tbody .mapping-check').length;
        const checked = $('#mappingsTable tbody .mapping-check:checked').length;
        $('#selectAllMappings').prop('checked', total > 0 && total === checked);
    });

    $(document).on('click', '.sync-mapping-btn', async function () {
        const id = $(this).data('id') || '';
        if (id) {
            await syncSingleMapping(id);
        }
    });

    $(document).on('click', '.cancel-mapping-btn', async function () {
        const id = $(this).data('id') || '';
        if (id) {
            await cancelSingleMapping(id);
        }
    });

    $('#bulkSyncMappingsBtn').on('click', async function () {
        const ids = getSelectedMappingIds();
        if (!ids.length) {
            await appAlert('Uyarı', 'Lütfen eşzamanlamak için en az bir kayıt seçin.', 'warning');
            return;
        }
        await bulkSyncMappings(ids);
    });

    $('#bulkCancelMappingsBtn').on('click', async function () {
        const ids = getSelectedMappingIds();
        if (!ids.length) {
            await appAlert('Uyarı', 'Lütfen iptal etmek için en az bir kayıt seçin.', 'warning');
            return;
        }
        await bulkCancelMappings(ids);
    });

    loadQualifications();
    loadMappings();
});
</script>
JS;

include '../includes/footer.php';

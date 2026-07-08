<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'legal-documents';
$page_title = 'Yasal Metinler';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Yasal Metinler</h2>
            <p class="text-muted mb-0">Kullanım Koşulları, Gizlilik Politikası ve Çerez Politikası metinlerini yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" id="saveLegalBtn"><i class="bi bi-save"></i> Kaydet</button>
        </div>
    </div>

    <ul class="nav nav-tabs" id="legalTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="terms-tab" data-bs-toggle="tab" data-bs-target="#termsPane" type="button" role="tab">Kullanım Koşulları</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="privacy-tab" data-bs-toggle="tab" data-bs-target="#privacyPane" type="button" role="tab">Gizlilik Politikası</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="cookiePolicy-tab" data-bs-toggle="tab" data-bs-target="#cookiePolicyPane" type="button" role="tab">Çerez Politikası</button>
        </li>
    </ul>

    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="termsPane" role="tabpanel" aria-labelledby="terms-tab">
            <div class="row g-3">
                <div class="col-12 col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Başlık</label>
                                <input type="text" class="form-control" id="termsTitle" maxlength="255">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Durum</label>
                                <select class="form-select" id="termsStatus">
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">İçerik</label>
                                <textarea class="form-control" id="termsContent" rows="14" placeholder="Kullanım koşulları metnini girin..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    <div class="card mb-3">
                        <div class="card-header bg-white"><h6 class="mb-0">Meta Bilgiler</h6></div>
                        <div class="card-body small">
                            <div class="mb-2"><strong>Versiyon:</strong> <span id="termsVersion">1</span></div>
                            <div class="mb-2"><strong>Son güncelleme:</strong> <span id="termsUpdatedAt">-</span></div>
                            <div><strong>Son düzenleyen:</strong> <span id="termsUpdatedBy">-</span></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header bg-white"><h6 class="mb-0">Preview</h6></div>
                        <div class="card-body">
                            <h5 id="termsPreviewTitle" class="mb-3"></h5>
                            <div id="termsPreview" class="legal-preview-area"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="privacyPane" role="tabpanel" aria-labelledby="privacy-tab">
            <div class="row g-3">
                <div class="col-12 col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Başlık</label>
                                <input type="text" class="form-control" id="privacyTitle" maxlength="255">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Durum</label>
                                <select class="form-select" id="privacyStatus">
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">İçerik</label>
                                <textarea class="form-control" id="privacyContent" rows="14" placeholder="Gizlilik politikası metnini girin..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    <div class="card mb-3">
                        <div class="card-header bg-white"><h6 class="mb-0">Meta Bilgiler</h6></div>
                        <div class="card-body small">
                            <div class="mb-2"><strong>Versiyon:</strong> <span id="privacyVersion">1</span></div>
                            <div class="mb-2"><strong>Son güncelleme:</strong> <span id="privacyUpdatedAt">-</span></div>
                            <div><strong>Son düzenleyen:</strong> <span id="privacyUpdatedBy">-</span></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header bg-white"><h6 class="mb-0">Preview</h6></div>
                        <div class="card-body">
                            <h5 id="privacyPreviewTitle" class="mb-3"></h5>
                            <div id="privacyPreview" class="legal-preview-area"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="cookiePolicyPane" role="tabpanel" aria-labelledby="cookiePolicy-tab">
            <div class="row g-3">
                <div class="col-12 col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Başlık</label>
                                <input type="text" class="form-control" id="cookiePolicyTitle" maxlength="255">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Durum</label>
                                <select class="form-select" id="cookiePolicyStatus">
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                </select>
                            </div>
                            <div class="mb-0">
                                <label class="form-label">İçerik</label>
                                <textarea class="form-control" id="cookiePolicyContent" rows="14" placeholder="Çerez politikası metnini girin..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-5">
                    <div class="card mb-3">
                        <div class="card-header bg-white"><h6 class="mb-0">Meta Bilgiler</h6></div>
                        <div class="card-body small">
                            <div class="mb-2"><strong>Versiyon:</strong> <span id="cookiePolicyVersion">1</span></div>
                            <div class="mb-2"><strong>Son güncelleme:</strong> <span id="cookiePolicyUpdatedAt">-</span></div>
                            <div><strong>Son düzenleyen:</strong> <span id="cookiePolicyUpdatedBy">-</span></div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header bg-white"><h6 class="mb-0">Preview</h6></div>
                        <div class="card-body">
                            <h5 id="cookiePolicyPreviewTitle" class="mb-3"></h5>
                            <div id="cookiePolicyPreview" class="legal-preview-area"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/legal-documents.php';
    let docs = { terms: null, privacy: null, cookie_policy: null };
    const docUiPrefixMap = {
        terms: 'terms',
        privacy: 'privacy',
        cookie_policy: 'cookiePolicy'
    };
    const tabDocKeyMap = {
        'terms-tab': 'terms',
        'privacy-tab': 'privacy',
        'cookiePolicy-tab': 'cookie_policy'
    };

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const esc = (txt) => $('<div>').text(txt ?? '').html();

    function getUiPrefix(key) {
        return docUiPrefixMap[key] || 'terms';
    }

    const api = async (action, method = 'GET', data = {}) => {
        try {
            return await $.ajax({
                url: endpoint + '?action=' + encodeURIComponent(action),
                method,
                data,
                dataType: 'json'
            });
        } catch (xhr) {
            return { success: false, message: xhr?.responseJSON?.message || 'Sunucu hatası oluştu.' };
        }
    };

    function getDocKeyFromActiveTab() {
        const activeTabId = $('#legalTabs .nav-link.active').attr('id');
        return tabDocKeyMap[activeTabId] || 'terms';
    }

    function fillDoc(key, data) {
        const prefix = getUiPrefix(key);
        $('#' + prefix + 'Title').val(data?.title || '');
        $('#' + prefix + 'Content').val(data?.content || '');
        $('#' + prefix + 'Status').val(data?.status || 'draft');
        $('#' + prefix + 'Version').text(data?.version || 1);
        $('#' + prefix + 'UpdatedAt').text(data?.updated_at || '-');
        $('#' + prefix + 'UpdatedBy').text(data?.updated_by_label || '-');
        renderPreview(key);
    }

    function collectDoc(key) {
        const prefix = getUiPrefix(key);
        return {
            title: ($('#' + prefix + 'Title').val() || '').trim(),
            content: ($('#' + prefix + 'Content').val() || '').trim(),
            status: ($('#' + prefix + 'Status').val() || 'draft')
        };
    }

    function renderPreview(key) {
        const prefix = getUiPrefix(key);
        const title = $('#' + prefix + 'Title').val() || '';
        const content = $('#' + prefix + 'Content').val() || '';
        $('#' + prefix + 'PreviewTitle').text(title);
        $('#' + prefix + 'Preview').html(content || '<span class="text-muted">Preview için içerik girin.</span>');
    }

    async function loadList() {
        const res = await api('list', 'GET');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Yasal metinler alınamadı.', 'error');
            return;
        }

        docs.terms = res.data?.terms || {};
        docs.privacy = res.data?.privacy || {};
        docs.cookie_policy = res.data?.cookie_policy || {};
        fillDoc('terms', docs.terms);
        fillDoc('privacy', docs.privacy);
        fillDoc('cookie_policy', docs.cookie_policy);
    }

    async function saveActiveDoc() {
        const docKey = getDocKeyFromActiveTab();
        const payload = collectDoc(docKey);

        if (!payload.title) return appAlert('Validasyon', 'Başlık boş olamaz.', 'warning');
        if (!payload.content) return appAlert('Validasyon', 'İçerik boş olamaz.', 'warning');

        const res = await api('save', 'POST', {
            doc_key: docKey,
            title: payload.title,
            content: payload.content,
            status: payload.status
        });

        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Yasal metin kaydedildi.', 'success');
        await loadList();
    }

    $('#saveLegalBtn').on('click', saveActiveDoc);
    $('#termsTitle, #termsContent').on('input', function () { renderPreview('terms'); });
    $('#privacyTitle, #privacyContent').on('input', function () { renderPreview('privacy'); });
    $('#cookiePolicyTitle, #cookiePolicyContent').on('input', function () { renderPreview('cookie_policy'); });

    loadList();
});
</script>

<style>
.legal-preview-area {
    min-height: 260px;
    max-height: 520px;
    overflow: auto;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg-soft);
}

.legal-preview-area p:last-child {
    margin-bottom: 0;
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'pusula-ai-knowledge';
$page_title = 'Pusula Ai Bilgi Bankası';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid pusula-ai-knowledge-page">
    <div class="page-header">
        <div>
            <h2>Pusula Ai Bilgi Bankası</h2>
            <p class="text-muted mb-0">Pusula Ai için bilgi, davranış, örnek konuşmalar ve tool yetkilerini yönetin.</p>
        </div>
        <div class="page-actions">
            <button class="btn btn-primary" type="button" id="saveActiveSectionBtn"><i class="bi bi-save"></i> Aktif Bölümü Kaydet</button>
        </div>
    </div>

    <div class="card">
        <div class="card-body pb-2">
            <ul class="nav nav-tabs knowledge-tabs" id="knowledgeTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-general" type="button">Genel Bilgi</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-features" type="button">Özellikler</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rules" type="button">Davranış</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-prompts" type="button">Prompt Katmanları</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-examples" type="button">Örnek Konuşmalar</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-tools" type="button">Tool Yetkileri</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-actions" type="button">Action Ayarları</button></li>
            </ul>

            <div class="tab-content pt-3">
                <div class="tab-pane fade show active" id="tab-general">
                    <div class="card soft-card"><div class="card-body">
                        <h6 class="mb-3">A. Genel Bilgi</h6>
                        <form id="formGeneral" class="row g-3">
                            <div class="col-md-6"><label class="form-label">app_name</label><input class="form-control" name="app_name"></div>
                            <div class="col-md-6"><label class="form-label">assistant_name</label><input class="form-control" name="assistant_name"></div>
                            <div class="col-12"><label class="form-label">app_summary</label><textarea class="form-control" name="app_summary" rows="3"></textarea></div>
                            <div class="col-md-6"><label class="form-label">target_users</label><textarea class="form-control" name="target_users" rows="3"></textarea></div>
                            <div class="col-md-6"><label class="form-label">tone_of_voice</label><textarea class="form-control" name="tone_of_voice" rows="3"></textarea></div>
                        </form>
                    </div></div>
                </div>

                <div class="tab-pane fade" id="tab-features">
                    <div class="card soft-card"><div class="card-body">
                        <h6 class="mb-3">B. Uygulama Özellikleri</h6>
                        <form id="formFeatures" class="row g-3">
                            <div class="col-md-6"><label class="form-label">app_features_text</label><textarea class="form-control" name="app_features_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">premium_features_text</label><textarea class="form-control" name="premium_features_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">offline_features_text</label><textarea class="form-control" name="offline_features_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">community_features_text</label><textarea class="form-control" name="community_features_text" rows="4"></textarea></div>
                            <div class="col-12"><label class="form-label">exam_features_text</label><textarea class="form-control" name="exam_features_text" rows="4"></textarea></div>
                        </form>
                    </div></div>
                </div>

                <div class="tab-pane fade" id="tab-rules">
                    <div class="card soft-card"><div class="card-body">
                        <h6 class="mb-3">C. Davranış Kuralları</h6>
                        <form id="formRules" class="row g-3">
                            <div class="col-md-6"><label class="form-label">allowed_topics_text</label><textarea class="form-control" name="allowed_topics_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">blocked_topics_text</label><textarea class="form-control" name="blocked_topics_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">response_style_text</label><textarea class="form-control" name="response_style_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">emotional_style_text</label><textarea class="form-control" name="emotional_style_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">short_reply_rules_text</label><textarea class="form-control" name="short_reply_rules_text" rows="4"></textarea></div>
                            <div class="col-md-6"><label class="form-label">long_reply_rules_text</label><textarea class="form-control" name="long_reply_rules_text" rows="4"></textarea></div>
                        </form>
                    </div></div>
                </div>

                <div class="tab-pane fade" id="tab-prompts">
                    <div class="card soft-card"><div class="card-body">
                        <h6 class="mb-3">D. Sistem Prompt Katmanları</h6>
                        <form id="formPrompts" class="row g-3">
                            <div class="col-md-6"><label class="form-label">system_prompt_base</label><textarea class="form-control" name="system_prompt_base" rows="5"></textarea></div>
                            <div class="col-md-6"><label class="form-label">system_prompt_behavior</label><textarea class="form-control" name="system_prompt_behavior" rows="5"></textarea></div>
                            <div class="col-md-6"><label class="form-label">system_prompt_app_knowledge</label><textarea class="form-control" name="system_prompt_app_knowledge" rows="5"></textarea></div>
                            <div class="col-md-6"><label class="form-label">system_prompt_stats_behavior</label><textarea class="form-control" name="system_prompt_stats_behavior" rows="5"></textarea></div>
                            <div class="col-12"><label class="form-label">system_prompt_exam_behavior</label><textarea class="form-control" name="system_prompt_exam_behavior" rows="5"></textarea></div>
                        </form>
                    </div></div>
                </div>

                <div class="tab-pane fade" id="tab-examples">
                    <div class="card soft-card"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0">E. Örnek Konuşmalar</h6>
                            <button class="btn btn-sm btn-primary" id="addExampleBtn" type="button"><i class="bi bi-plus-lg"></i> Ekle</button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle" id="examplesTable">
                                <thead>
                                <tr>
                                    <th style="width:90px;">Sıra</th>
                                    <th>Tag</th>
                                    <th>Kullanıcı</th>
                                    <th>Asistan</th>
                                    <th>Durum</th>
                                    <th style="width:230px;">İşlem</th>
                                </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div></div>
                </div>

                <div class="tab-pane fade" id="tab-tools">
                    <div class="card soft-card"><div class="card-body">
                        <h6 class="mb-3">F. Tool Yetkileri</h6>
                        <form id="formTools" class="row g-2 pusula-ai-switch-grid">
                            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tool_stats_enabled" value="1"><label class="form-check-label">tool_stats_enabled</label></div></div>
                            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tool_exam_recommendation_enabled" value="1"><label class="form-check-label">tool_exam_recommendation_enabled</label></div></div>
                            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tool_app_info_enabled" value="1"><label class="form-check-label">tool_app_info_enabled</label></div></div>
                            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tool_action_payload_enabled" value="1"><label class="form-check-label">tool_action_payload_enabled</label></div></div>
                            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tool_weak_topics_enabled" value="1"><label class="form-check-label">tool_weak_topics_enabled</label></div></div>
                            <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="tool_last_exam_enabled" value="1"><label class="form-check-label">tool_last_exam_enabled</label></div></div>
                        </form>
                    </div></div>
                </div>

                <div class="tab-pane fade" id="tab-actions">
                    <div class="card soft-card"><div class="card-body">
                        <h6 class="mb-3">G. Action Buton Ayarları</h6>
                        <form id="formActions" class="row g-3">
                            <div class="col-12"><label class="form-label">action_button_intro_text</label><textarea class="form-control" name="action_button_intro_text" rows="3"></textarea></div>
                            <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="action_exam_enabled" value="1"><label class="form-check-label">action_exam_enabled</label></div></div>
                            <div class="col-md-4"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="action_plan_enabled" value="1"><label class="form-check-label">action_plan_enabled</label></div></div>
                            <div class="col-md-4"><label class="form-label">action_exam_default_question_count</label><input class="form-control" type="number" min="1" max="100" name="action_exam_default_question_count"></div>
                            <div class="col-md-6"><label class="form-label">action_exam_default_mode</label><input class="form-control" name="action_exam_default_mode"></div>
                        </form>
                    </div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="exampleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Örnek Konuşma</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="exampleForm" class="row g-3">
                    <input type="hidden" name="id">
                    <div class="col-md-6"><label class="form-label">conversation_tag</label><input class="form-control" name="conversation_tag" placeholder="greeting"></div>
                    <div class="col-md-3"><label class="form-label">order_index</label><input class="form-control" type="number" min="0" name="order_index" value="0"></div>
                    <div class="col-md-3"><div class="form-check form-switch mt-4"><input class="form-check-input" type="checkbox" name="is_active" value="1" checked><label class="form-check-label">Aktif</label></div></div>
                    <div class="col-12"><label class="form-label">user_message</label><textarea class="form-control" name="user_message" rows="3"></textarea></div>
                    <div class="col-12"><label class="form-label">assistant_reply</label><textarea class="form-control" name="assistant_reply" rows="5"></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Kapat</button>
                <button class="btn btn-primary" type="button" id="saveExampleBtn"><i class="bi bi-save"></i> Kaydet</button>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(function () {
    const endpoint = '../ajax/pusula-ai-knowledge.php';
    const state = { examples: [] };
    const exampleModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('exampleModal'));

    const appAlert = (title, message, type = 'info') => window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();
    const appConfirm = (title, message, opts = {}) => window.showAppConfirm ? window.showAppConfirm({ title, message, ...opts }) : Promise.resolve(false);
    const esc = (v) => $('<div>').text(v ?? '').html();
    const short = (v, len = 90) => {
        const t = String(v || '').trim();
        return t.length > len ? t.slice(0, len - 3) + '...' : t;
    };

    async function api(action, method = 'GET', data = {}) {
        return window.appAjax({ url: endpoint + '?action=' + encodeURIComponent(action), method, data });
    }

    function formDataObj($form) {
        const out = {};
        ($form.serializeArray() || []).forEach(i => out[i.name] = i.value);
        $form.find('input[type="checkbox"]').each(function () {
            out[this.name] = $(this).is(':checked') ? 1 : 0;
        });
        return out;
    }

    function fillForm($form, data) {
        Object.keys(data || {}).forEach(key => {
            const $el = $form.find('[name="' + key + '"]');
            if (!$el.length) return;
            if ($el.is(':checkbox')) {
                $el.prop('checked', String(data[key]) === '1' || data[key] === 1 || data[key] === true);
            } else {
                $el.val(data[key] ?? '');
            }
        });
    }

    function renderExamples() {
        const $tb = $('#examplesTable tbody');
        $tb.empty();

        if (!state.examples.length) {
            $tb.append('<tr><td colspan="6" class="text-muted">Henüz örnek konuşma eklenmedi.</td></tr>');
            return;
        }

        state.examples.forEach((x, idx) => {
            const badge = Number(x.is_active) === 1
                ? '<span class="badge bg-success">Aktif</span>'
                : '<span class="badge bg-secondary">Pasif</span>';

            $tb.append(`
                <tr>
                    <td>${Number(x.order_index || 0)}</td>
                    <td><code>${esc(x.conversation_tag || '-')}</code></td>
                    <td>${esc(short(x.user_message, 85))}</td>
                    <td>${esc(short(x.assistant_reply, 85))}</td>
                    <td>${badge}</td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-outline-secondary move-up" data-id="${esc(x.id)}"><i class="bi bi-arrow-up"></i></button>
                            <button class="btn btn-sm btn-outline-secondary move-down" data-id="${esc(x.id)}"><i class="bi bi-arrow-down"></i></button>
                            <button class="btn btn-sm btn-outline-primary edit-example" data-id="${esc(x.id)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-example" data-id="${esc(x.id)}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);
        });
    }

    async function loadAll() {
        const res = await api('get_knowledge');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Veriler yüklenemedi.', 'error');
            return;
        }

        const knowledge = res.data?.knowledge || {};
        const tools = res.data?.tools || {};
        fillForm($('#formGeneral'), knowledge);
        fillForm($('#formFeatures'), knowledge);
        fillForm($('#formRules'), knowledge);
        fillForm($('#formPrompts'), knowledge);
        fillForm($('#formActions'), knowledge);
        fillForm($('#formTools'), tools);

        state.examples = Array.isArray(res.data?.examples) ? res.data.examples : [];
        renderExamples();
    }

    async function saveSectionByTab(tabId) {
        const map = {
            '#tab-general': { action: 'save_general', form: '#formGeneral', ok: 'Genel bilgi kaydedildi.' },
            '#tab-features': { action: 'save_features', form: '#formFeatures', ok: 'Özellikler kaydedildi.' },
            '#tab-rules': { action: 'save_rules', form: '#formRules', ok: 'Davranış kuralları kaydedildi.' },
            '#tab-prompts': { action: 'save_prompts', form: '#formPrompts', ok: 'Prompt katmanları kaydedildi.' },
            '#tab-tools': { action: 'save_tools', form: '#formTools', ok: 'Tool yetkileri kaydedildi.' },
            '#tab-actions': { action: 'save_actions', form: '#formActions', ok: 'Action ayarları kaydedildi.' },
            '#tab-examples': null,
        };

        const target = map[tabId];
        if (target === null) {
            await appAlert('Bilgi', 'Örnek konuşmalar sekmesinde kayıtlar satır bazlı kaydedilir.', 'info');
            return;
        }
        if (!target) return;

        const payload = formDataObj($(target.form));
        const res = await api(target.action, 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', target.ok, 'success');
        await loadAll();
    }

    function openExampleModal(item = null) {
        const $f = $('#exampleForm');
        $f[0].reset();
        $f.find('[name="id"]').val('');
        $f.find('[name="is_active"]').prop('checked', true);

        if (item) {
            fillForm($f, item);
            $f.find('[name="is_active"]').prop('checked', Number(item.is_active) === 1);
        }

        exampleModal.show();
    }

    $('#addExampleBtn').on('click', function () { openExampleModal(); });

    $(document).on('click', '.edit-example', function () {
        const id = $(this).data('id');
        const item = state.examples.find(x => x.id === id);
        if (!item) return;
        openExampleModal(item);
    });

    $('#saveExampleBtn').on('click', async function () {
        const payload = formDataObj($('#exampleForm'));
        if (!String(payload.user_message || '').trim() || !String(payload.assistant_reply || '').trim()) {
            await appAlert('Uyarı', 'Kullanıcı mesajı ve asistan cevabı zorunludur.', 'warning');
            return;
        }

        const res = await api('save_example', 'POST', payload);
        if (!res.success) {
            await appAlert('Hata', res.message || 'Örnek konuşma kaydedilemedi.', 'error');
            return;
        }

        state.examples = Array.isArray(res.data?.examples) ? res.data.examples : state.examples;
        renderExamples();
        exampleModal.hide();
    });

    $(document).on('click', '.delete-example', async function () {
        const id = $(this).data('id');
        const ok = await appConfirm('Silme Onayı', 'Örnek konuşma silinsin mi?', { type: 'warning', confirmText: 'Sil', cancelText: 'İptal' });
        if (!ok) return;

        const res = await api('delete_example', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme işlemi başarısız.', 'error');
            return;
        }

        state.examples = Array.isArray(res.data?.examples) ? res.data.examples : [];
        renderExamples();
    });

    async function persistReorder() {
        const items = state.examples.map((x, idx) => ({ id: x.id, order_index: idx + 1 }));
        const res = await api('reorder_examples', 'POST', { items: JSON.stringify(items) });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Sıralama kaydedilemedi.', 'error');
            return;
        }

        state.examples = Array.isArray(res.data?.examples) ? res.data.examples : state.examples;
        renderExamples();
    }

    $(document).on('click', '.move-up, .move-down', async function () {
        const id = $(this).data('id');
        const idx = state.examples.findIndex(x => x.id === id);
        if (idx < 0) return;

        const to = $(this).hasClass('move-up') ? idx - 1 : idx + 1;
        if (to < 0 || to >= state.examples.length) return;

        const tmp = state.examples[idx];
        state.examples[idx] = state.examples[to];
        state.examples[to] = tmp;
        renderExamples();
        await persistReorder();
    });

    $('#saveActiveSectionBtn').on('click', async function () {
        const tabId = $('#knowledgeTabs .nav-link.active').data('bs-target');
        window.appSetButtonLoading('#saveActiveSectionBtn', true, 'Kaydediliyor...');
        await saveSectionByTab(tabId);
        window.appSetButtonLoading('#saveActiveSectionBtn', false);
    });

    loadAll();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

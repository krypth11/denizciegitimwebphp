<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'pusula-ai';
$page_title = 'Pusula Ai';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid pusula-ai-page">
    <div class="page-header">
        <div>
            <h2>Pusula Ai</h2>
            <p class="text-muted mb-0">Pusula Ai, mevcut AI soru üretim sisteminden bağımsız çalışır.</p>
        </div>
    </div>

    <div class="card pusula-ai-card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Pusula Ai Ayarları</h6>
        </div>
        <div class="card-body">
            <form id="pusulaAiForm" autocomplete="off">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="provider">Provider</label>
                        <select class="form-select" id="provider" name="provider"></select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="model">Model</label>
                        <select class="form-select" id="model" name="model"></select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="api_key">API Key</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="api_key" name="api_key" placeholder="API key giriniz...">
                            <button type="button" class="btn btn-outline-secondary" id="togglePusulaApiKeyBtn">Göster</button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="base_url">Base URL</label>
                        <input type="url" class="form-control" id="base_url" name="base_url" placeholder="https://...">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="timeout_seconds">Timeout saniye</label>
                        <input type="number" class="form-control" id="timeout_seconds" name="timeout_seconds" min="5" max="120" step="1">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="temperature">Temperature</label>
                        <input type="number" class="form-control" id="temperature" name="temperature" min="0" max="1" step="0.01">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="max_tokens">Max Tokens</label>
                        <input type="number" class="form-control" id="max_tokens" name="max_tokens" min="1" max="8192" step="1">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="daily_limit">Daily Limit</label>
                        <input type="number" class="form-control" id="daily_limit" name="daily_limit" min="0" max="1000000" step="1">
                    </div>

                    <div class="col-md-8">
                        <div class="row g-2 pusula-ai-switch-grid">
                            <div class="col-sm-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="premium_only" name="premium_only" value="1">
                                    <label class="form-check-label" for="premium_only">Premium Only</label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="internet_required" name="internet_required" value="1">
                                    <label class="form-check-label" for="internet_required">Internet Required</label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="moderation_enabled" name="moderation_enabled" value="1">
                                    <label class="form-check-label" for="moderation_enabled">Moderation Enabled</label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1">
                                    <label class="form-check-label" for="is_active">Is Active</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="card-footer bg-white d-flex gap-2 flex-wrap justify-content-end">
            <button class="btn btn-outline-primary" type="button" id="testConnectionBtn"><i class="bi bi-plug"></i> Bağlantıyı Test Et</button>
            <button class="btn btn-primary" type="button" id="savePusulaAiBtn"><i class="bi bi-save"></i> Kaydet</button>
        </div>
    </div>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/pusula-ai.php';
    const defaultSettings = {
        provider: 'openai',
        model: 'gpt-5.4-mini',
        api_key: '',
        base_url: 'https://api.openai.com/v1',
        timeout_seconds: 30,
        temperature: 0.30,
        max_tokens: 1200,
        premium_only: 1,
        internet_required: 1,
        moderation_enabled: 1,
        daily_limit: 30,
        is_active: 1
    };
    const fallbackProviderModels = {
        openai: ['gpt-5.4-mini', 'gpt-5.4', 'gpt-4.1-mini', 'gpt-4.1'],
        gemini: ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-1.5-flash'],
        claude: ['claude-3-5-haiku-latest', 'claude-3-5-sonnet-latest', 'claude-3-7-sonnet-latest'],
        groq: ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768'],
        cerebras: ['llama3.1-8b', 'qwen-3-235b-a22b-instruct-2507']
    };

    let providerModels = { ...fallbackProviderModels };

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    function api(action, method = 'GET', data = {}) {
        return window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data
        });
    }

    function asBool(value) {
        return String(value) === '1' || value === true;
    }

    function esc(txt) {
        return $('<div>').text(txt ?? '').html();
    }

    function renderProviderOptions(selected = 'openai') {
        const providers = Object.keys(providerModels);
        const safeSelected = providers.includes(selected) ? selected : (providers[0] || 'openai');

        $('#provider').empty();
        providers.forEach(p => {
            $('#provider').append('<option value="' + esc(p) + '">' + esc(p) + '</option>');
        });
        $('#provider').val(safeSelected);

        renderModelOptions(safeSelected);
    }

    function renderModelOptions(provider, selectedModel = '') {
        const models = providerModels[provider] || [];
        $('#model').empty();
        models.forEach(m => {
            $('#model').append('<option value="' + esc(m) + '">' + esc(m) + '</option>');
        });

        if (selectedModel && models.includes(selectedModel)) {
            $('#model').val(selectedModel);
        } else if (models.length) {
            $('#model').val(models[0]);
        }
    }

    function collectPayload() {
        return {
            provider: $('#provider').val() || '',
            model: $('#model').val() || '',
            api_key: $('#api_key').val() || '',
            base_url: $('#base_url').val() || '',
            timeout_seconds: $('#timeout_seconds').val() || '',
            temperature: $('#temperature').val() || '',
            max_tokens: $('#max_tokens').val() || '',
            premium_only: $('#premium_only').is(':checked') ? 1 : 0,
            internet_required: $('#internet_required').is(':checked') ? 1 : 0,
            moderation_enabled: $('#moderation_enabled').is(':checked') ? 1 : 0,
            daily_limit: $('#daily_limit').val() || '',
            is_active: $('#is_active').is(':checked') ? 1 : 0
        };
    }

    function fillForm(settings = {}) {
        const mergedSettings = { ...defaultSettings, ...(settings || {}) };
        renderProviderOptions(mergedSettings.provider || 'openai');
        renderModelOptions(mergedSettings.provider || 'openai', mergedSettings.model || '');

        $('#api_key').val(mergedSettings.api_key || '');
        $('#base_url').val(mergedSettings.base_url || '');
        $('#timeout_seconds').val(mergedSettings.timeout_seconds ?? 30);
        $('#temperature').val(mergedSettings.temperature ?? 0.30);
        $('#max_tokens').val(mergedSettings.max_tokens ?? 1200);
        $('#daily_limit').val(mergedSettings.daily_limit ?? 30);
        $('#premium_only').prop('checked', asBool(mergedSettings.premium_only));
        $('#internet_required').prop('checked', asBool(mergedSettings.internet_required));
        $('#moderation_enabled').prop('checked', asBool(mergedSettings.moderation_enabled));
        $('#is_active').prop('checked', asBool(mergedSettings.is_active));
    }

    async function loadSettings() {
        const res = await api('get_settings', 'GET');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Ayarlar yüklenemedi.', 'error');
            return;
        }

        providerModels = res.data?.provider_models || fallbackProviderModels;
        fillForm(res.data?.settings || {});
    }

    $('#provider').on('change', function () {
        renderModelOptions($(this).val() || 'openai', '');
    });

    $('#togglePusulaApiKeyBtn').on('click', function () {
        const $apiInput = $('#api_key');
        const isPassword = $apiInput.attr('type') === 'password';
        $apiInput.attr('type', isPassword ? 'text' : 'password');
        $(this).text(isPassword ? 'Gizle' : 'Göster');
    });

    $('#savePusulaAiBtn').on('click', async function () {
        window.appSetButtonLoading('#savePusulaAiBtn', true, 'Kaydediliyor...');
        const res = await api('save_settings', 'POST', collectPayload());
        window.appSetButtonLoading('#savePusulaAiBtn', false);

        if (!res.success) {
            await appAlert('Hata', res.message || 'Ayarlar kaydedilemedi.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Ayarlar kaydedildi.', 'success');
        fillForm(res.data?.settings || {});
    });

    $('#testConnectionBtn').on('click', async function () {
        window.appSetButtonLoading('#testConnectionBtn', true, 'Test ediliyor...');
        const res = await api('test_connection', 'POST', collectPayload());
        window.appSetButtonLoading('#testConnectionBtn', false);

        if (!res.success) {
            await appAlert('Bağlantı Hatası', res.message || 'Bağlantı testi başarısız.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Bağlantı başarılı.', 'success');
    });

    fillForm(defaultSettings);
    loadSettings();
});
</script>
JAVASCRIPT;
?>

<?php include '../includes/footer.php'; ?>

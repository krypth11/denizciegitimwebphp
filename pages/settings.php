<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'settings';
$page_title = 'Ayarlar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Ayarlar</h2>
            <p class="text-muted mb-0">AI sağlayıcı, model ve soru üretim varsayılanlarını yönetin.</p>
        </div>
        <div class="page-actions d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" id="saveSettingsBtn"><i class="bi bi-save"></i> Ayarları Kaydet</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <h6 class="mb-2">Bilgilendirme</h6>
            <p class="mb-0 text-muted">
                Claude ve OpenAI yüksek kalite üretim için güçlü seçeneklerdir.
                Gemini ve Groq tarafında ise ücretsiz kota / daha ekonomik kullanım seçenekleri bulunabilir.
                Kullanım hacminize göre en uygun sağlayıcıyı seçebilirsiniz.
            </p>
        </div>
    </div>

    <form id="settingsForm" autocomplete="off">
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0">Tema Ayarları</h6></div>
                    <div class="card-body">
                        <input type="hidden" name="theme_mode" id="theme_mode" value="system">
                        <div class="theme-toggle" role="group" aria-label="Tema Seçimi">
                            <button type="button" class="theme-toggle-btn" data-theme="light">
                                <i class="bi bi-sun-fill"></i>
                                <span>Açık</span>
                            </button>
                            <button type="button" class="theme-toggle-btn" data-theme="dark">
                                <i class="bi bi-moon-stars-fill"></i>
                                <span>Koyu</span>
                            </button>
                            <button type="button" class="theme-toggle-btn" data-theme="system">
                                <i class="bi bi-display"></i>
                                <span>Sistem</span>
                            </button>
                        </div>
                        <small class="text-muted d-block mt-2">Tema seçiminiz bu tarayıcıda saklanır ve tüm panelde uygulanır.</small>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0">AI Sağlayıcı</h6></div>
                    <div class="card-body">
                        <div class="provider-grid" id="providerGrid">
                            <label class="provider-card" data-provider="claude">
                                <input type="radio" name="ai_provider" value="claude">
                                <div class="provider-title">Claude <span class="badge bg-info-subtle text-info-emphasis">Premium</span></div>
                                <small class="text-muted">Yüksek kalite uzun metin ve reasoning çıktıları.</small>
                            </label>

                            <label class="provider-card" data-provider="openai">
                                <input type="radio" name="ai_provider" value="openai">
                                <div class="provider-title">OpenAI <span class="badge bg-info-subtle text-info-emphasis">Premium</span></div>
                                <small class="text-muted">Genel amaçlı, dengeli ve güçlü model seçenekleri.</small>
                            </label>

                            <label class="provider-card" data-provider="gemini">
                                <input type="radio" name="ai_provider" value="gemini">
                                <div class="provider-title">Gemini <span class="badge bg-success-subtle text-success-emphasis">Ücretsiz Kota</span></div>
                                <small class="text-muted">Google AI Studio ile hızlı başlangıç ve uygun maliyet.</small>
                            </label>

                            <label class="provider-card" data-provider="groq">
                                <input type="radio" name="ai_provider" value="groq">
                                <div class="provider-title">Groq <span class="badge bg-success-subtle text-success-emphasis">Hızlı & Ekonomik</span></div>
                                <small class="text-muted">Düşük gecikme ile hızlı üretim akışları.</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white"><h6 class="mb-0">Model ve API Erişimi</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Model</label>
                                <select class="form-select" name="ai_model" id="ai_model"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">API Key</label>
                                <div class="input-group api-key-group">
                                    <input type="password" class="form-control" name="api_key" id="api_key" placeholder="API key giriniz...">
                                    <button type="button" class="btn btn-outline-secondary" id="toggleApiKeyBtn">Göster</button>
                                </div>
                                <small class="text-muted d-block mt-1">API key güvenli saklanır ve üçüncü kişilerle paylaşılmamalıdır.</small>
                                <small class="d-block mt-1"><a href="#" id="providerHelpLink" target="_blank" rel="noopener">Sağlayıcı API key alma sayfası</a></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0">Model Parametreleri</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Max Tokens</label>
                                <input type="number" class="form-control" name="max_tokens" id="max_tokens" min="1" step="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Temperature</label>
                                <input type="number" class="form-control" name="temperature" id="temperature" min="0" max="1" step="0.1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header bg-white"><h6 class="mb-0">Soru Üretim Varsayılanları</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Varsayılan Soru Adedi</label>
                                <input type="number" class="form-control" name="default_question_count" id="default_question_count" min="1" max="100" step="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Varsayılan Soru Türü</label>
                                <select class="form-select" name="default_question_type" id="default_question_type">
                                    <option value="all">Hepsi</option>
                                    <option value="sayısal">Sayısal</option>
                                    <option value="sözel">Sözel</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php
$extra_js = <<<'JAVASCRIPT'
<script>
$(document).ready(function () {
    const endpoint = '../ajax/settings.php';

    const providerModels = {
        claude: [
            'claude-3-7-sonnet-latest',
            'claude-3-5-sonnet-latest',
            'claude-3-5-haiku-latest',
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'claude-3-opus-20240229',
            'claude-3-sonnet-20240229',
            'claude-3-haiku-20240307'
        ],
        openai: [
            'gpt-4.1',
            'gpt-4.1-mini',
            'gpt-4.1-nano',
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-4o-2024-11-20',
            'gpt-4-turbo',
            'gpt-4',
            'gpt-3.5-turbo',
            'o1',
            'o1-mini',
            'o3-mini'
        ],
        gemini: [
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.0-pro-exp',
            'gemini-2.0-flash-exp',
            'gemini-1.5-pro',
            'gemini-1.5-flash',
            'gemini-1.5-flash-8b',
            'gemini-exp-1206'
        ],
        groq: [
            'llama-3.3-70b-versatile',
            'llama-3.1-70b-versatile',
            'llama-3.1-8b-instant',
            'llama-guard-3-8b',
            'mixtral-8x7b-32768',
            'gemma2-9b-it',
            'qwen-2.5-32b',
            'qwen-2.5-coder-32b',
            'deepseek-r1-distill-llama-70b'
        ]
    };

    const providerHelp = {
        claude: { label: 'Anthropic Console', url: 'https://console.anthropic.com/' },
        openai: { label: 'OpenAI Platform', url: 'https://platform.openai.com/' },
        gemini: { label: 'Google AI Studio', url: 'https://aistudio.google.com/' },
        groq: { label: 'Groq Console', url: 'https://console.groq.com/' }
    };

    const defaultProviderModel = {
        claude: 'claude-sonnet-4-20250514',
        openai: 'gpt-4o',
        gemini: 'gemini-2.5-flash',
        groq: 'llama-3.3-70b-versatile'
    };

    const appAlert = (title, message, type = 'info') =>
        window.showAppAlert ? window.showAppAlert({ title, message, type }) : Promise.resolve();

    const appConfirm = (title, message, options = {}) =>
        window.showAppConfirm ? window.showAppConfirm({ title, message, ...options }) : Promise.resolve(false);

    const esc = (txt) => $('<div>').text(txt ?? '').html();

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

    function getSelectedProvider() {
        return $('input[name="ai_provider"]:checked').val() || 'openai';
    }

    function renderProviderSelection() {
        const selected = getSelectedProvider();
        $('.provider-card').removeClass('active');
        $('.provider-card[data-provider="' + selected + '"]').addClass('active');
    }

    function renderProviderHelp(provider) {
        const item = providerHelp[provider] || providerHelp.openai;
        $('#providerHelpLink').attr('href', item.url).text(item.label + ' üzerinden API key alın');
    }

    function renderModelOptions(provider, selectedModel = '') {
        const models = providerModels[provider] || providerModels.openai;
        const fallback = defaultProviderModel[provider] || models[0];
        const selected = models.includes(selectedModel) ? selectedModel : fallback;

        $('#ai_model').empty();
        models.forEach(m => $('#ai_model').append('<option value="' + esc(m) + '">' + esc(m) + '</option>'));
        $('#ai_model').val(selected);
    }

    let initialFormSnapshot = '';
    let isDirty = false;
    let isSaving = false;

    function normalizeThemePreference(mode) {
        return ['light', 'dark', 'system'].includes(mode) ? mode : 'system';
    }

    function setThemeToggle(mode) {
        const safe = normalizeThemePreference(mode);
        $('#theme_mode').val(safe);
        $('.theme-toggle-btn').removeClass('active');
        $('.theme-toggle-btn[data-theme="' + safe + '"]').addClass('active');
    }

    function applyTheme(mode, persist = true) {
        const safe = normalizeThemePreference(mode);
        if (typeof window.applyGlobalTheme === 'function') {
            window.applyGlobalTheme(safe, persist);
        } else {
            const resolved = safe === 'system'
                ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : safe;

            document.documentElement.setAttribute('data-theme-preference', safe);
            document.documentElement.setAttribute('data-theme', resolved);
            document.documentElement.setAttribute('data-bs-theme', resolved);
        }
        if (persist) localStorage.setItem('app_theme', safe);
        setThemeToggle(safe);
    }

    function initTheme() {
        const saved = localStorage.getItem('app_theme') || 'system';
        applyTheme(saved, false);
    }

    function collectFormSnapshot() {
        const formArray = $('#settingsForm').serializeArray();
        const normalized = formArray
            .filter(x => x.name !== 'api_key')
            .sort((a, b) => a.name.localeCompare(b.name))
            .map(x => `${x.name}=${x.value}`)
            .join('&') + `|api_key=${$('#api_key').val() || ''}`;
        return normalized;
    }

    function refreshDirtyState() {
        const now = collectFormSnapshot();
        isDirty = now !== initialFormSnapshot;
    }

    function markCleanSnapshot() {
        initialFormSnapshot = collectFormSnapshot();
        isDirty = false;
    }

    function validateForm() {
        const provider = getSelectedProvider();
        const model = $('#ai_model').val() || '';
        const maxTokens = parseInt($('#max_tokens').val(), 10);
        const temperature = parseFloat($('#temperature').val());
        const questionCount = parseInt($('#default_question_count').val(), 10);
        const questionType = $('#default_question_type').val();

        if (!providerModels[provider]) return 'Geçersiz AI sağlayıcı seçimi.';
        if (!model) return 'Lütfen model seçiniz.';
        if (Number.isNaN(maxTokens) || maxTokens < 1) return 'Max Tokens en az 1 olmalıdır.';
        if (Number.isNaN(temperature) || temperature < 0 || temperature > 1) return 'Temperature 0 ile 1 arasında olmalıdır.';
        if (Number.isNaN(questionCount) || questionCount < 1 || questionCount > 100) return 'Varsayılan soru adedi 1-100 arasında olmalıdır.';
        if (!['all', 'sayısal', 'sözel'].includes(questionType)) return 'Geçersiz varsayılan soru türü.';

        return null;
    }

    async function loadSettings() {
        const res = await api('get_settings');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Ayarlar yüklenemedi.', 'error');
            return;
        }

        const s = res.data?.settings || {};
        const provider = s.ai_provider || 'openai';

        $('input[name="ai_provider"][value="' + provider + '"]').prop('checked', true);
        renderProviderSelection();
        renderModelOptions(provider, s.ai_model || '');
        renderProviderHelp(provider);

        $('#api_key').val(s.api_key || '');
        $('#max_tokens').val(s.max_tokens ?? 2000);
        $('#temperature').val(s.temperature ?? 0.7);
        $('#default_question_count').val(s.default_question_count ?? 10);
        $('#default_question_type').val(s.default_question_type || 'all');

        applyTheme(localStorage.getItem('app_theme') || 'system', false);
        markCleanSnapshot();
    }

    $('input[name="ai_provider"]').on('change', function () {
        const provider = getSelectedProvider();
        renderProviderSelection();
        renderModelOptions(provider, '');
        renderProviderHelp(provider);
    });

    $(document).on('click', '.theme-toggle-btn', function () {
        applyTheme($(this).data('theme'));
        refreshDirtyState();
    });

    $('#settingsForm').on('input change', 'input, select, textarea', function () {
        refreshDirtyState();
    });

    $('#toggleApiKeyBtn').on('click', function () {
        const $input = $('#api_key');
        const isPassword = $input.attr('type') === 'password';
        $input.attr('type', isPassword ? 'text' : 'password');
        $(this).text(isPassword ? 'Gizle' : 'Göster');
    });

    $('#saveSettingsBtn').on('click', async function () {
        const error = validateForm();
        if (error) {
            await appAlert('Validasyon Hatası', error, 'warning');
            return;
        }

        const payload = {
            ai_provider: getSelectedProvider(),
            ai_model: $('#ai_model').val() || '',
            api_key: $('#api_key').val() || '',
            max_tokens: $('#max_tokens').val() || '',
            temperature: $('#temperature').val() || '',
            default_question_count: $('#default_question_count').val() || '',
            default_question_type: $('#default_question_type').val() || 'all'
        };

        localStorage.setItem('app_theme', $('#theme_mode').val() || 'system');

        isSaving = true;
        const res = await api('save_settings', 'POST', payload);
        isSaving = false;
        if (!res.success) {
            await appAlert('Hata', res.message || 'Ayarlar kaydedilemedi.', 'error');
            return;
        }

        await appAlert('Başarılı', res.message || 'Ayarlar kaydedildi.', 'success');
        await loadSettings();
        markCleanSnapshot();
    });

    $(window).on('beforeunload', function (e) {
        if (isDirty && !isSaving) {
            const msg = 'Kaydedilmemiş değişiklikler var. Kaydetmeden çıkmak istediğinize emin misiniz?';
            e.preventDefault();
            e.returnValue = msg;
            return msg;
        }
    });

    $(document).on('click', 'a[href]', async function (e) {
        if (!isDirty || isSaving) return;

        const href = $(this).attr('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if ($(this).attr('target') === '_blank') return;

        e.preventDefault();
        const ok = await appConfirm(
            'Kaydedilmemiş Değişiklikler',
            'Kaydedilmemiş değişiklikler var. Kaydetmeden çıkmak istediğinize emin misiniz?',
            { type: 'warning', confirmText: 'Çık', cancelText: 'Kal' }
        );

        if (ok) {
            isDirty = false;
            window.location.href = href;
        }
    });

    initTheme();
    loadSettings();
});
</script>

<style>
.provider-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.provider-card {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: 0.2s ease;
}

.provider-card input {
    display: none;
}

.provider-card.active {
    background: var(--primary-soft);
    border-color: var(--primary);
}

.provider-title {
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.theme-toggle {
    display: inline-flex;
    border: 1px solid var(--border);
    background: var(--bg-soft);
    border-radius: 12px;
    padding: 4px;
    gap: 4px;
    width: 100%;
}

.theme-toggle-btn {
    border: 0;
    background: transparent;
    color: var(--text-muted);
    min-height: 36px;
    min-width: 82px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 6px 12px;
}

.theme-toggle-btn.active {
    background: var(--primary-soft);
    color: var(--text-main);
    font-weight: 600;
}

.api-key-group .btn {
    min-width: 92px;
}

@media (max-width: 991.98px) {
    .provider-grid {
        grid-template-columns: 1fr;
    }

    .theme-toggle-btn {
        flex: 1;
    }
}

@media (max-width: 767.98px) {
    .api-key-group {
        flex-wrap: wrap;
    }

    .api-key-group .btn {
        width: 100%;
    }

    .page-actions {
        width: 100%;
    }

    .page-actions .btn {
        flex: 1;
    }
}
</style>
JAVASCRIPT;
?>
<?php include '../includes/footer.php'; ?>

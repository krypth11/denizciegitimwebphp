function resolveThemeMode(preference) {
    const pref = ['light', 'dark', 'system'].includes(preference) ? preference : 'system';
    if (pref === 'system') {
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    return pref;
}

function applyGlobalTheme(preference, persist = true) {
    const pref = ['light', 'dark', 'system'].includes(preference) ? preference : 'system';
    const resolved = resolveThemeMode(pref);

    document.documentElement.setAttribute('data-theme-preference', pref);
    document.documentElement.setAttribute('data-theme', resolved);
    document.documentElement.setAttribute('data-bs-theme', resolved);

    if (persist) {
        try {
            localStorage.setItem('app_theme', pref);
        } catch (e) {}
    }
}

window.applyGlobalTheme = applyGlobalTheme;

try {
    applyGlobalTheme(localStorage.getItem('app_theme') || 'system', false);

    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onSystemThemeChange = function () {
            const pref = localStorage.getItem('app_theme') || 'system';
            if (pref === 'system') applyGlobalTheme('system', false);
        };

        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onSystemThemeChange);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onSystemThemeChange);
        }
    }

    window.addEventListener('storage', function (e) {
        if (e.key === 'app_theme') {
            applyGlobalTheme(e.newValue || 'system', false);
        }
    });
} catch (e) {}

$(document).ajaxError(function (event, jqxhr, settings, thrownError) {
    console.error('AJAX Error:', thrownError);
    if (typeof window.showAppAlert === 'function') {
        window.showAppAlert({
            title: 'Hata',
            message: 'Bir hata oluştu: ' + thrownError,
            type: 'error',
        });
    }
});

function showToast(message, type = 'success') {
    const toast = `
        <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;

    const container = $('#toast-container');
    if (!container.length) {
        $('body').append('<div id="toast-container" class="position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
    }

    $('#toast-container').append(toast);
    $('.toast').toast({ delay: 3000 }).toast('show');
}

function confirmAction(message, callback) {
    if (typeof window.showAppConfirm === 'function') {
        window.showAppConfirm({
            title: 'Onay',
            message: message,
            onConfirm: callback,
        });
        return;
    }
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('tr-TR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

(function initAppDialogs() {
    function getEls() {
        return {
            modal: document.getElementById('appDialogModal'),
            title: document.getElementById('appDialogTitle'),
            body: document.getElementById('appDialogBody'),
            cancel: document.getElementById('appDialogCancelBtn'),
            confirm: document.getElementById('appDialogConfirmBtn'),
        };
    }

    function safeHtml(text) {
        return (text || '').toString().replace(/\n/g, '<br>');
    }

    function setType(modalEl, type) {
        const header = modalEl.querySelector('.modal-header');
        const confirmBtn = modalEl.querySelector('#appDialogConfirmBtn');
        header.classList.remove('bg-danger', 'bg-success', 'bg-warning', 'bg-info', 'text-white', 'text-dark');
        confirmBtn.classList.remove('btn-danger', 'btn-success', 'btn-warning', 'btn-primary');

        if (type === 'error') {
            header.classList.add('bg-danger', 'text-white');
            confirmBtn.classList.add('btn-danger');
        } else if (type === 'success') {
            header.classList.add('bg-success', 'text-white');
            confirmBtn.classList.add('btn-success');
        } else if (type === 'warning' || type === 'confirm') {
            header.classList.add('bg-warning', 'text-dark');
            confirmBtn.classList.add('btn-warning');
        } else {
            header.classList.add('bg-info', 'text-white');
            confirmBtn.classList.add('btn-primary');
        }
    }

    function normalizeAlertArgs(arg1, arg2, arg3) {
        if (typeof arg1 === 'object' && arg1 !== null) {
            return {
                title: arg1.title || 'Bilgi',
                message: arg1.message || '',
                type: arg1.type || 'info',
                buttonText: arg1.buttonText || 'Tamam',
            };
        }

        return {
            title: arg1 || 'Bilgi',
            message: arg2 || '',
            type: arg3 || 'info',
            buttonText: 'Tamam',
        };
    }

    function normalizeConfirmArgs(arg1, arg2, arg3, arg4) {
        if (typeof arg1 === 'object' && arg1 !== null) {
            return {
                title: arg1.title || 'Onay',
                message: arg1.message || '',
                onConfirm: typeof arg1.onConfirm === 'function' ? arg1.onConfirm : null,
                onCancel: typeof arg1.onCancel === 'function' ? arg1.onCancel : null,
                type: arg1.type || 'warning',
                confirmText: arg1.confirmText || 'Onayla',
                cancelText: arg1.cancelText || 'İptal',
            };
        }

        const options = (arg4 && typeof arg4 === 'object') ? arg4 : {};
        return {
            title: arg1 || 'Onay',
            message: arg2 || '',
            onConfirm: typeof arg3 === 'function' ? arg3 : null,
            onCancel: typeof options.onCancel === 'function' ? options.onCancel : null,
            type: options.type || 'warning',
            confirmText: options.confirmText || 'Onayla',
            cancelText: options.cancelText || 'İptal',
        };
    }

    window.showAppAlert = function (arg1, arg2, arg3) {
        const els = getEls();
        if (!els.modal || typeof bootstrap === 'undefined') return Promise.resolve();

        const config = normalizeAlertArgs(arg1, arg2, arg3);
        const modal = bootstrap.Modal.getOrCreateInstance(els.modal);

        els.title.textContent = config.title;
        els.body.innerHTML = safeHtml(config.message);
        els.cancel.classList.add('d-none');
        els.confirm.textContent = config.buttonText;
        setType(els.modal, config.type);

        return new Promise((resolve) => {
            const cleanup = () => {
                els.confirm.onclick = null;
                els.cancel.onclick = null;
            };

            const onHidden = () => {
                els.modal.removeEventListener('hidden.bs.modal', onHidden);
                cleanup();
                resolve();
            };

            els.modal.addEventListener('hidden.bs.modal', onHidden);
            els.confirm.onclick = () => modal.hide();
            els.cancel.onclick = null;
            modal.show();
        });
    };

    window.showAppConfirm = function (arg1, arg2, arg3, arg4) {
        const els = getEls();
        if (!els.modal || typeof bootstrap === 'undefined') return Promise.resolve(false);

        const config = normalizeConfirmArgs(arg1, arg2, arg3, arg4);
        const modal = bootstrap.Modal.getOrCreateInstance(els.modal);

        els.title.textContent = config.title;
        els.body.innerHTML = safeHtml(config.message);
        els.cancel.classList.remove('d-none');
        els.cancel.textContent = config.cancelText;
        els.confirm.textContent = config.confirmText;
        setType(els.modal, config.type || 'confirm');

        return new Promise((resolve) => {
            let decided = false;

            const cleanup = () => {
                els.confirm.onclick = null;
                els.cancel.onclick = null;
            };

            const finalize = (confirmed) => {
                if (decided) return;
                decided = true;

                if (confirmed && typeof config.onConfirm === 'function') config.onConfirm();
                if (!confirmed && typeof config.onCancel === 'function') config.onCancel();
                resolve(confirmed);
            };

            const onHidden = () => {
                els.modal.removeEventListener('hidden.bs.modal', onHidden);
                cleanup();
                if (!decided) finalize(false);
            };

            els.modal.addEventListener('hidden.bs.modal', onHidden);

            els.confirm.onclick = () => {
                finalize(true);
                modal.hide();
            };

            els.cancel.onclick = () => {
                finalize(false);
                modal.hide();
            };

            modal.show();
        });
    };
})();

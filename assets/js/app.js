$(document).ajaxError(function (event, jqxhr, settings, thrownError) {
    console.error('AJAX Error:', thrownError);
    if (typeof window.showAppAlert === 'function') {
        window.showAppAlert('Hata', 'Bir hata oluştu: ' + thrownError, 'error');
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
        window.showAppConfirm('Onay', message, callback);
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
    const state = {
        onConfirm: null,
        onCancel: null,
        currentMode: 'alert',
    };

    function getDialogElements() {
        return {
            modal: document.getElementById('appDialogModal'),
            title: document.getElementById('appDialogTitle'),
            body: document.getElementById('appDialogBody'),
            cancel: document.getElementById('appDialogCancelBtn'),
            confirm: document.getElementById('appDialogConfirmBtn')
        };
    }

    function setType(modalEl, type) {
        const header = modalEl.querySelector('.modal-header');
        const confirmBtn = modalEl.querySelector('#appDialogConfirmBtn');
        header.classList.remove('bg-danger', 'bg-success', 'bg-warning', 'bg-info', 'text-white', 'text-dark');
        confirmBtn.classList.remove('btn-danger', 'btn-success', 'btn-warning', 'btn-primary', 'btn-info');

        if (type === 'error') {
            header.classList.add('bg-danger', 'text-white');
            confirmBtn.classList.add('btn-danger');
        } else if (type === 'success') {
            header.classList.add('bg-success', 'text-white');
            confirmBtn.classList.add('btn-success');
        } else if (type === 'warning') {
            header.classList.add('bg-warning', 'text-dark');
            confirmBtn.classList.add('btn-warning');
        } else {
            header.classList.add('bg-info', 'text-white');
            confirmBtn.classList.add('btn-primary');
        }
    }

    function resetState() {
        state.onConfirm = null;
        state.onCancel = null;
        state.currentMode = 'alert';
    }

    window.showAppAlert = function (title, message, type = 'info') {
        const els = getDialogElements();
        if (!els.modal || typeof bootstrap === 'undefined') return;

        els.title.textContent = title || 'Bilgi';
        els.body.innerHTML = (message || '').toString().replace(/\n/g, '<br>');
        els.cancel.classList.add('d-none');
        els.confirm.textContent = 'Tamam';
        state.currentMode = 'alert';

        setType(els.modal, type);

        const modal = bootstrap.Modal.getOrCreateInstance(els.modal);
        els.confirm.onclick = () => {
            modal.hide();
        };
        els.cancel.onclick = null;
        modal.show();
    };

    window.showAppConfirm = function (title, message, onConfirm, options = {}) {
        const els = getDialogElements();
        if (!els.modal || typeof bootstrap === 'undefined') return;

        els.title.textContent = title || 'Onay';
        els.body.innerHTML = (message || '').toString().replace(/\n/g, '<br>');
        els.cancel.classList.remove('d-none');
        els.cancel.textContent = options.cancelText || 'İptal';
        els.confirm.textContent = options.confirmText || 'Onayla';
        state.currentMode = 'confirm';

        setType(els.modal, options.type || 'warning');

        const modal = bootstrap.Modal.getOrCreateInstance(els.modal);
        state.onConfirm = typeof onConfirm === 'function' ? onConfirm : null;
        state.onCancel = typeof options.onCancel === 'function' ? options.onCancel : null;

        els.confirm.onclick = () => {
            const callback = state.onConfirm;
            resetState();
            modal.hide();
            if (typeof callback === 'function') callback();
        };

        els.cancel.onclick = () => {
            const onCancelCb = state.onCancel;
            resetState();
            modal.hide();
            if (typeof onCancelCb === 'function') onCancelCb();
        };

        els.modal.addEventListener('hidden.bs.modal', function handleHidden() {
            els.modal.removeEventListener('hidden.bs.modal', handleHidden);
            resetState();
            els.confirm.onclick = null;
            els.cancel.onclick = null;
        });

        modal.show();
    };
})();

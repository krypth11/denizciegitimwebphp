$(document).ajaxError(function (event, jqxhr, settings, thrownError) {
    console.error('AJAX Error:', thrownError);
    alert('Bir hata oluştu: ' + thrownError);
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
    if (confirm(message)) {
        callback();
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

<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'support-tickets';
$page_title = 'Destek Talepleri';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2>Destek Talepleri</h2>
            <p class="text-muted mb-0">Modern support inbox görünümü ile talepleri yönetin.</p>
        </div>
    </div>

    <div class="card mb-3" style="border:1px solid rgba(20,40,80,.15)">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Durum</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">Tümü</option>
                        <option value="submitted">submitted</option>
                        <option value="in_review">in_review</option>
                        <option value="answered">answered</option>
                        <option value="completed">completed</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Arama</label>
                    <input type="text" id="qFilter" class="form-control" placeholder="Konu, kullanıcı adı veya email">
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button class="btn btn-primary" id="filterBtn"><i class="bi bi-search"></i> Filtrele</button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card" style="border:1px solid rgba(20,40,80,.15)">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="ticketsTable">
                            <thead>
                            <tr><th>Konu</th><th>Kullanıcı</th><th>Durum</th><th>Tarih</th></tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card" style="border:1px solid rgba(20,40,80,.15)">
                <div class="card-body" id="ticketDetailWrap">
                    <div class="text-muted">Detay görmek için soldan bir ticket seçin.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $extra_js = <<<'JS'
<script>
$(function () {
    const endpoint = '../ajax/support-tickets.php';
    const esc = (v) => $('<div>').text(v ?? '').html();
    let activeTicketId = '';

    const statusBadge = (status) => {
        const s = String(status || '').toLowerCase();
        if (s === 'submitted') return '<span class="badge" style="background:#f59e0b">submitted</span>';
        if (s === 'in_review') return '<span class="badge" style="background:#2563eb">in_review</span>';
        if (s === 'answered') return '<span class="badge" style="background:#16a34a">answered</span>';
        return '<span class="badge bg-secondary">completed</span>';
    };

    const api = async (action, method = 'GET', data = {}) => {
        return await window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    };

    async function loadList() {
        const res = await api('list', 'GET', {
            status: ($('#statusFilter').val() || '').trim(),
            q: ($('#qFilter').val() || '').trim()
        });
        const $tb = $('#ticketsTable tbody');
        if (!res.success) {
            $tb.html('<tr><td colspan="4" class="text-muted">Kayıtlar getirilemedi.</td></tr>');
            return;
        }
        const rows = res.data?.tickets || [];
        if (!rows.length) {
            $tb.html('<tr><td colspan="4" class="text-muted">Kayıt bulunamadı.</td></tr>');
            return;
        }
        $tb.html(rows.map(r => `
            <tr class="ticket-row" data-id="${esc(r.id)}" style="cursor:pointer">
                <td><strong>${esc(r.subject)}</strong></td>
                <td>${esc(r.user_display || '-')}</td>
                <td>${statusBadge(r.status)}</td>
                <td><small class="text-muted">${esc(r.created_at || '-')}</small></td>
            </tr>
        `).join(''));
    }

    async function loadDetail(ticketId) {
        const res = await api('detail', 'GET', { ticket_id: ticketId });
        if (!res.success) return;
        const t = res.data?.ticket || {};
        const msgs = res.data?.messages || [];
        $('#ticketDetailWrap').html(`
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-1">${esc(t.subject || '-')}</h5>
                    <div class="text-muted small">${esc(t.user_full_name || t.user_email || '-')} • ${esc(t.created_at || '-')}</div>
                </div>
                <div>${statusBadge(t.status)}</div>
            </div>
            <div class="mb-3" style="max-height:320px; overflow:auto; border:1px solid rgba(20,40,80,.12); border-radius:12px; padding:12px; background:#f8fbff;">
                ${(msgs.length ? msgs.map(m => `<div class="mb-2"><span class="badge ${m.sender_type==='admin'?'bg-primary':'bg-dark'}">${esc(m.sender_type)}</span> <small class="text-muted">${esc(m.created_at || '')}</small><div class="mt-1">${esc(m.message || '')}</div></div>`).join('') : '<div class="text-muted">Mesaj yok.</div>')}
            </div>
            <div class="mb-2">
                <textarea id="adminReplyText" class="form-control" rows="4" placeholder="Admin cevabını yazın..."></textarea>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary" id="sendReplyBtn">Cevap Gönder</button>
                <button class="btn btn-outline-secondary" id="completeBtn">Completed Yap</button>
            </div>
        `);
    }

    $(document).on('click', '.ticket-row', async function () {
        activeTicketId = $(this).data('id') || '';
        if (!activeTicketId) return;
        await loadDetail(activeTicketId);
        await loadList();
    });

    $(document).on('click', '#sendReplyBtn', async function () {
        if (!activeTicketId) return;
        const message = ($('#adminReplyText').val() || '').trim();
        if (!message) return;
        const res = await api('reply', 'POST', { ticket_id: activeTicketId, message });
        if (!res.success) return;
        await loadDetail(activeTicketId);
        await loadList();
    });

    $(document).on('click', '#completeBtn', async function () {
        if (!activeTicketId) return;
        const res = await api('complete', 'POST', { ticket_id: activeTicketId });
        if (!res.success) return;
        await loadDetail(activeTicketId);
        await loadList();
    });

    $('#filterBtn').on('click', loadList);
    loadList();
});
</script>
JS;
include '../includes/footer.php';
?>

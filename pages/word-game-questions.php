<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_auth();
$current_page = 'word-game-questions';
$page_title = 'Kelime Oyunu - Sorular';
$categories = $pdo->query('SELECT id,name FROM word_game_categories ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="container-fluid">
  <div class="page-header">
    <div>
      <h2>Kelime Oyunu Soru Havuzu</h2>
      <p class="text-muted mb-0">Sorular yalnızca başlığa eklenir. Başlığa bağlı tüm yeterlilikler bu soru havuzundan yararlanır.</p>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" id="addBtn"><i class="bi bi-plus-lg"></i> Yeni Soru Ekle</button>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Başlık</label>
          <select id="fCategory" class="form-select">
            <option value="">Tümü</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Durum</label>
          <select id="fActive" class="form-select">
            <option value="">Tümü</option>
            <option value="1">Aktif</option>
            <option value="0">Pasif</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Arama</label>
          <input id="fSearch" class="form-control" placeholder="Soru veya cevap ara...">
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
          <h5 class="mb-1">Toplu Pattern Giriş</h5>
          <div class="small text-muted">Bir başlık seçin. Eklenen sorular o başlığa bağlı tüm yeterliliklerin ortak havuzuna girer.</div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-primary" id="parseBulk" disabled>Ayrıştır</button>
          <button class="btn btn-success" id="saveBulk" disabled>Onayla ve Kaydet</button>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Başlık *</label>
          <select id="bulkCategory" class="form-select">
            <option value="">Seçiniz...</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <textarea id="bulkPattern" class="form-control mt-3" rows="10" placeholder="### WORD&#10;TR_SORU: ...&#10;EN_QUESTION: ...&#10;TR_CEVAP: ...&#10;EN_ANSWER: ...&#10;NOTE: ..."></textarea>
      <div id="bulkPreview" class="mt-3"></div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3" id="paginationTop"></div>
      <div class="d-flex justify-content-end mb-2">
        <button id="bulkDeleteBtn" class="btn btn-sm btn-outline-danger" disabled>Seçilenleri Sil (0)</button>
      </div>
      <div class="table-responsive">
        <table class="table" id="tbl">
          <thead>
            <tr>
              <th style="width:36px"><input type="checkbox" id="selectAllVisible" class="form-check-input"></th>
              <th>Başlık</th>
              <th>TR Soru</th>
              <th>TR Cevap</th>
              <th>EN Cevap</th>
              <th>Uz.</th>
              <th>Durum</th>
              <th>İşlem</th>
            </tr>
          </thead>
          <tbody><tr><td colspan="8" class="text-muted">Yükleniyor...</td></tr></tbody>
        </table>
      </div>
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mt-3" id="paginationBottom"></div>
    </div>
  </div>
</div>

<div class="modal fade" id="m" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="mt">Soru</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="f">
        <input type="hidden" name="id" id="id">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Başlık *</label>
              <select class="form-select" name="category_id" id="category_id" required>
                <option value="">Seçiniz...</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Bu soru, başlığa bağlı tüm yeterliliklerde kullanılabilir.</div>
            </div>
            <div class="col-12">
              <label class="form-label">🇹🇷 Soru Metni *</label>
              <textarea class="form-control" name="question_text" id="question_text" required></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">🇬🇧 Question Text</label>
              <textarea class="form-control" name="question_text_en" id="question_text_en"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">🇹🇷 Doğru Cevap *</label>
              <input class="form-control" name="answer_text" id="answer_text" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">🇬🇧 Correct Answer</label>
              <input class="form-control" name="answer_text_en" id="answer_text_en">
            </div>
            <div class="col-md-4">
              <label class="form-label">Sıra</label>
              <input type="number" class="form-control" name="order_index" id="order_index" value="0">
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                <label class="form-check-label" for="is_active">Aktif</label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Not</label>
              <textarea class="form-control" name="notes" id="notes"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
          <button class="btn btn-primary" type="submit">Kaydet</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extra_js = <<<'JS'
<script>
$(function () {
  const ep = '../ajax/word-game-questions.php';
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('m'));
  const esc = (value) => $('<div>').text(value ?? '').html();
  const api = (action, method = 'GET', data = {}) => window.appAjax({
    url: ep + '?action=' + encodeURIComponent(action),
    method,
    data,
    dataType: 'json'
  });
  const perPageOptions = [10, 25, 50, 100, 500];
  const state = {
    pagination: {page: 1, per_page: 25, total_count: 0, total_pages: 1},
    selectedQuestionIds: new Set(),
    visibleQuestionIds: [],
    parsedItems: [],
    validCount: 0,
    invalidCount: 0,
    totalCount: 0,
    isBulkSaving: false
  };
  let bulkItems = [];

  function resetPage() {
    state.pagination.page = 1;
  }

  function clearSelection() {
    state.selectedQuestionIds.clear();
    state.visibleQuestionIds = [];
    renderBulkSelectionState();
  }

  function renderBulkSelectionState() {
    const selectedCount = state.selectedQuestionIds.size;
    $('#bulkDeleteBtn').prop('disabled', selectedCount === 0).text(`Seçilenleri Sil (${selectedCount})`);
    const visible = state.visibleQuestionIds || [];
    const hasRows = visible.length > 0;
    const allVisibleSelected = hasRows && visible.every((id) => state.selectedQuestionIds.has(String(id)));
    const someVisibleSelected = visible.some((id) => state.selectedQuestionIds.has(String(id)));
    $('#selectAllVisible')
      .prop('checked', allVisibleSelected)
      .prop('indeterminate', hasRows && !allVisibleSelected && someVisibleSelected)
      .prop('disabled', !hasRows);
  }

  function updateBulkButtons() {
    const hasCategory = !!$('#bulkCategory').val();
    const hasPattern = String($('#bulkPattern').val() || '').trim() !== '';
    const allValid = state.totalCount > 0 && state.invalidCount === 0 && state.validCount === state.totalCount;
    $('#parseBulk').prop('disabled', !hasCategory || !hasPattern || state.isBulkSaving);
    $('#saveBulk').prop('disabled', !allValid || state.isBulkSaving);
  }

  function resetBulkPreview() {
    state.parsedItems = [];
    state.totalCount = 0;
    state.invalidCount = 0;
    state.validCount = 0;
    bulkItems = [];
    $('#bulkPreview').empty();
    updateBulkButtons();
  }

  function renderBulkPreview() {
    const items = state.parsedItems || [];
    if (!items.length) {
      $('#bulkPreview').html('<div class="alert alert-warning mb-0">Pattern içinde kayıt bulunamadı.</div>');
      updateBulkButtons();
      return;
    }

    const summaryClass = state.invalidCount === 0 ? 'alert-success' : 'alert-danger';
    const summary = `<div class="alert ${summaryClass} py-2">
      Toplam: <strong>${state.totalCount}</strong> · Geçerli: <strong>${state.validCount}</strong> · Hatalı: <strong>${state.invalidCount}</strong>
      ${state.invalidCount > 0 ? '<div class="small mt-1">Bir kayıt bile hatalıysa hiçbir soru kaydedilmez.</div>' : ''}
    </div>`;

    const rows = items.map((item) => {
      const record = item.record || {};
      const errors = Array.isArray(item.errors) ? item.errors : [];
      return `<tr class="${item.valid ? '' : 'table-danger'}">
        <td>${esc(item.line || '-')}</td>
        <td>${esc(record.tr_question || '-')}</td>
        <td>${esc(record.tr_answer || '-')}</td>
        <td>${item.valid ? '<span class="badge text-bg-success">Geçerli</span>' : `<span class="badge text-bg-danger">Hatalı</span><div class="small mt-1">${errors.map(esc).join('<br>')}</div>`}</td>
      </tr>`;
    }).join('');

    $('#bulkPreview').html(summary + `<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Satır</th><th>TR Soru</th><th>TR Cevap</th><th>Durum</th></tr></thead><tbody>${rows}</tbody></table></div>`);
    updateBulkButtons();
  }

  function setPaginationFromResponse(data) {
    const p = data.pagination || {};
    state.pagination.page = Number(p.page || 1);
    state.pagination.per_page = Number(p.per_page || 25);
    state.pagination.total_count = Number(p.total_count ?? data.total_count ?? 0);
    state.pagination.total_pages = Math.max(1, Number(p.total_pages || 1));
  }

  function renderPageButtons(current, total) {
    const pages = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);
    for (let page = start; page <= end; page++) pages.push(page);
    return pages.map((page) => `<button class="btn btn-sm ${page === current ? 'btn-primary' : 'btn-outline-secondary'} page-btn" data-page="${page}">${page}</button>`).join('');
  }

  function renderPagination() {
    const p = state.pagination;
    const html = `<div class="d-flex align-items-center gap-2">
      <label class="mb-0 small text-muted">Sayfa başına</label>
      <select id="perPageSelect" class="form-select form-select-sm" style="width:auto">
        ${perPageOptions.map((value) => `<option value="${value}" ${value === p.per_page ? 'selected' : ''}>${value}</option>`).join('')}
      </select>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span class="small text-muted">Sayfa ${p.page} / ${p.total_pages}</span>
      <span class="small text-muted">Toplam: ${p.total_count}</span>
      <button class="btn btn-sm btn-outline-secondary page-nav" data-dir="prev" ${p.page <= 1 ? 'disabled' : ''}>Önceki</button>
      <span class="d-inline-flex gap-1">${renderPageButtons(p.page, p.total_pages)}</span>
      <button class="btn btn-sm btn-outline-secondary page-nav" data-dir="next" ${p.page >= p.total_pages ? 'disabled' : ''}>Sonraki</button>
    </div>`;
    $('#paginationTop,#paginationBottom').html(html);
  }

  async function load() {
    const response = await api('list_questions', 'GET', {
      category_id: $('#fCategory').val() || '',
      is_active: $('#fActive').val() || '',
      search: $('#fSearch').val() || '',
      page: state.pagination.page,
      per_page: state.pagination.per_page
    });
    if (!response.success) return;

    setPaginationFromResponse(response.data || {});
    const rows = response.data?.questions || [];
    state.visibleQuestionIds = rows.map((row) => String(row.id));

    if (!rows.length) {
      $('#tbl tbody').html('<tr><td colspan="8" class="text-muted">Kayıt bulunamadı</td></tr>');
      renderPagination();
      renderBulkSelectionState();
      return;
    }

    $('#tbl tbody').html(rows.map((row) => `<tr>
      <td><input type="checkbox" class="form-check-input row-select" data-id="${esc(row.id)}" ${state.selectedQuestionIds.has(String(row.id)) ? 'checked' : ''}></td>
      <td>${esc(row.category_name || '-')}</td>
      <td>${esc(row.question_text || '')}</td>
      <td>${esc(row.answer_text || '')}</td>
      <td>${esc(row.answer_text_en || '-')}</td>
      <td>${esc(row.answer_length || 0)}</td>
      <td>${Number(row.is_active) === 1 ? '<span class="badge text-bg-success">Aktif</span>' : '<span class="badge text-bg-secondary">Pasif</span>'}</td>
      <td>
        <button class="btn btn-sm btn-outline-primary e" data-id="${esc(row.id)}"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-secondary t" data-id="${esc(row.id)}" data-a="${Number(row.is_active) === 1 ? 1 : 0}"><i class="bi bi-toggle-${Number(row.is_active) === 1 ? 'off' : 'on'}"></i></button>
        <button class="btn btn-sm btn-outline-danger d" data-id="${esc(row.id)}"><i class="bi bi-trash"></i></button>
      </td>
    </tr>`).join(''));

    renderPagination();
    renderBulkSelectionState();
  }

  $('#addBtn').on('click', function () {
    $('#f')[0].reset();
    $('#id').val('');
    $('#order_index').val('0');
    $('#is_active').prop('checked', true);
    $('#mt').text('Yeni Soru');
    modal.show();
  });

  $(document).on('click', '.e', async function () {
    const response = await api('get_question', 'GET', {id: $(this).data('id')});
    if (!response.success) return;
    const question = response.data.question || {};
    $('#f')[0].reset();
    ['id', 'category_id', 'question_text', 'question_text_en', 'answer_text', 'answer_text_en', 'order_index', 'notes'].forEach((key) => {
      $('#' + key).val(question[key] ?? '');
    });
    $('#is_active').prop('checked', Number(question.is_active) === 1);
    $('#mt').text('Soruyu Düzenle');
    modal.show();
  });

  $('#f').on('submit', async function (event) {
    event.preventDefault();
    const id = $('#id').val();
    const response = await api(id ? 'update_question' : 'create_question', 'POST', $(this).serialize());
    if (!response.success) {
      await window.showAppAlert({title: 'Hata', message: response.message || 'İşlem başarısız', type: 'error'});
      return;
    }
    modal.hide();
    resetPage();
    load();
  });

  $(document).on('click', '.t', async function () {
    await api('toggle_active', 'POST', {
      id: $(this).data('id'),
      is_active: String($(this).data('a')) === '1' ? 0 : 1
    });
    load();
  });

  $(document).on('click', '.d', async function () {
    const id = String($(this).data('id') || '');
    if (!window.confirm('Bu soru silinecek. Emin misiniz?')) return;
    const response = await api('delete_question', 'POST', {id});
    if (!response.success) {
      await window.showAppAlert({title: 'Hata', message: response.message || 'Silinemedi', type: 'error'});
      return;
    }
    state.selectedQuestionIds.delete(id);
    load();
  });

  $('#bulkCategory').on('change', resetBulkPreview);
  $('#bulkPattern').on('input', function () {
    if (state.parsedItems.length) resetBulkPreview();
    updateBulkButtons();
  });

  $('#parseBulk').on('click', async function () {
    const categoryId = $('#bulkCategory').val();
    if (!categoryId) {
      await window.showAppAlert({title: 'Hata', message: 'Başlık seçiniz.', type: 'error'});
      return;
    }

    state.isBulkSaving = true;
    updateBulkButtons();
    try {
      const response = await api('parse_bulk_pattern', 'POST', {
        category_id: categoryId,
        pattern: $('#bulkPattern').val()
      });
      if (!response.success) {
        await window.showAppAlert({title: 'Hata', message: response.message || 'Pattern ayrıştırılamadı.', type: 'error'});
        return;
      }
      const items = response.data?.items || [];
      state.parsedItems = items;
      state.totalCount = items.length;
      state.invalidCount = Number(response.data?.invalid_count ?? items.filter((item) => !item.valid).length);
      state.validCount = Number(response.data?.valid_count ?? (state.totalCount - state.invalidCount));
      bulkItems = items.map((item) => item.record || {});
      renderBulkPreview();
    } finally {
      state.isBulkSaving = false;
      updateBulkButtons();
    }
  });

  $('#saveBulk').on('click', async function () {
    if (state.isBulkSaving) return;
    const categoryId = $('#bulkCategory').val();
    if (!categoryId) {
      await window.showAppAlert({title: 'Hata', message: 'Başlık seçiniz.', type: 'error'});
      return;
    }
    if (state.totalCount === 0 || state.invalidCount > 0 || state.validCount !== state.totalCount) {
      await window.showAppAlert({title: 'Uyarı', message: 'Tüm kayıtlar geçerli olmadan toplu kayıt yapılamaz.', type: 'warning'});
      return;
    }

    state.isBulkSaving = true;
    updateBulkButtons();
    try {
      const response = await api('create_bulk_questions', 'POST', {
        category_id: categoryId,
        items: JSON.stringify(bulkItems)
      });
      if (!response.success) {
        if (response.data?.validation?.items) {
          state.parsedItems = response.data.validation.items;
          state.totalCount = state.parsedItems.length;
          state.invalidCount = Number(response.data.validation.invalid_count || state.parsedItems.filter((item) => !item.valid).length);
          state.validCount = Number(response.data.validation.valid_count || 0);
          bulkItems = state.parsedItems.map((item) => item.record || {});
          renderBulkPreview();
        }
        await window.showAppAlert({title: 'Hata', message: response.message || 'Kaydedilemedi', type: 'error'});
        return;
      }

      const requested = Number(response.data?.requested_count || 0);
      const created = Number(response.data?.created_count || 0);
      await window.showAppAlert({title: 'Başarılı', message: `${created} / ${requested} kayıt başarıyla eklendi.`, type: 'success'});
      $('#bulkPattern').val('');
      resetBulkPreview();
      resetPage();
      load();
    } finally {
      state.isBulkSaving = false;
      updateBulkButtons();
    }
  });

  $(document).on('change', '.row-select', function () {
    const id = String($(this).data('id') || '');
    if (!id) return;
    if ($(this).is(':checked')) state.selectedQuestionIds.add(id);
    else state.selectedQuestionIds.delete(id);
    renderBulkSelectionState();
  });

  $(document).on('change', '#selectAllVisible', function () {
    const checked = $(this).is(':checked');
    (state.visibleQuestionIds || []).forEach((id) => {
      if (checked) state.selectedQuestionIds.add(String(id));
      else state.selectedQuestionIds.delete(String(id));
    });
    $('.row-select').prop('checked', checked);
    renderBulkSelectionState();
  });

  $('#bulkDeleteBtn').on('click', async function () {
    const ids = Array.from(state.selectedQuestionIds);
    if (!ids.length) return;
    if (!window.confirm(`Seçili ${ids.length} kelime oyunu sorusu silinecek. Emin misiniz?`)) return;
    const response = await api('bulk_delete', 'POST', {ids: JSON.stringify(ids)});
    if (!response.success) {
      await window.showAppAlert({title: 'Hata', message: response.message || 'Toplu silme başarısız', type: 'error'});
      return;
    }
    state.selectedQuestionIds.clear();
    await window.showAppAlert({title: 'Başarılı', message: `${Number(response.data?.deleted_count || 0)} kayıt silindi.`, type: 'success'});
    load();
  });

  $('#fCategory,#fActive').on('change', function () {
    clearSelection();
    resetPage();
    load();
  });

  $('#fSearch').on('input', function () {
    clearTimeout(window.__wgs);
    window.__wgs = setTimeout(function () {
      clearSelection();
      resetPage();
      load();
    }, 200);
  });

  $(document).on('change', '#perPageSelect', function () {
    const value = Number($(this).val());
    state.pagination.per_page = perPageOptions.includes(value) ? value : 25;
    clearSelection();
    resetPage();
    load();
  });

  $(document).on('click', '.page-btn', function () {
    const page = Number($(this).data('page'));
    if (!Number.isFinite(page)) return;
    clearSelection();
    state.pagination.page = Math.min(Math.max(1, page), state.pagination.total_pages);
    load();
  });

  $(document).on('click', '.page-nav', function () {
    const direction = $(this).data('dir');
    clearSelection();
    if (direction === 'prev') state.pagination.page = Math.max(1, state.pagination.page - 1);
    if (direction === 'next') state.pagination.page = Math.min(state.pagination.total_pages, state.pagination.page + 1);
    load();
  });

  updateBulkButtons();
  load();
});
</script>
JS;
include '../includes/footer.php';
?>

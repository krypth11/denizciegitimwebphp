(function () {
    const cfg = window.KGQ_CONFIG || {};
    const endpoint = cfg.endpoint || '../ajax/kart-game-questions.php';
    const cropAspectRatio = Number(cfg.cropAspectRatio || (4 / 5));
    const minCropWidth = Number(cfg.minCropWidth || 320);
    const minCropHeight = Number(cfg.minCropHeight || 400);

    const esc = (v) => $('<div>').text(v ?? '').html();
    const appAlert = (title, message, type = 'info') => window.showAppAlert({ title, message, type });
    const appConfirm = (title, message, options = {}) => window.showAppConfirm({ title, message, ...options });

    const state = {
        categories: [],
        page: 1,
        perPage: 20,
        totalPages: 1,
        croppedBlob: null,
        previewBlobUrl: '',
        editMode: false,
        keepExistingImage: false,
    };

    let cropper = null;
    let cropSourceUrl = '';
    let pendingCropFile = null;

    async function api(action, method = 'GET', data = {}) {
        return await window.appAjax({
            url: endpoint + '?action=' + encodeURIComponent(action),
            method,
            data,
            dataType: 'json'
        });
    }

    async function apiForm(action, formData) {
        try {
            return await $.ajax({
                url: endpoint + '?action=' + encodeURIComponent(action),
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            });
        } catch (xhr) {
            return {
                success: false,
                message: xhr?.responseJSON?.message || 'İşlem sırasında bir hata oluştu.',
                data: xhr?.responseJSON?.data || {}
            };
        }
    }

    function clearPreviewBlobUrl() {
        if (state.previewBlobUrl) {
            URL.revokeObjectURL(state.previewBlobUrl);
            state.previewBlobUrl = '';
        }
    }

    function setPreview(src, hasImage = false, isTemporary = false) {
        const $img = $('#kgqImagePreview');
        const $hint = $('#kgqImagePreviewHint');
        const $wrap = $('#kgqImagePreviewWrap');

        if (!isTemporary) {
            clearPreviewBlobUrl();
        }

        $wrap.removeClass('is-empty is-existing is-new');

        if (src) {
            $img.attr('src', src).removeClass('d-none');
            $wrap.addClass(hasImage ? 'is-existing' : 'is-new');
            $hint.text(hasImage ? 'Kayıtlı görsel önizlemesi.' : 'Yeni kırpılmış görsel hazır. Kaydet ile yüklenir.');

            if (isTemporary) {
                state.previewBlobUrl = src;
            }
        } else {
            $img.attr('src', '').addClass('d-none');
            $wrap.addClass('is-empty');
            $hint.text('Henüz görsel seçilmedi.');
        }
    }

    function fillCategorySelects() {
        const options = ['<option value="">Seçiniz...</option>']
            .concat((state.categories || []).map((c) => `<option value="${esc(c.id)}">${esc(c.title)}</option>`));

        $('#kgq_category_id').html(options.join(''));
        $('#kgqFilterCategory').html('<option value="">Tümü</option>' + (state.categories || []).map((c) => `<option value="${esc(c.id)}">${esc(c.title)}</option>`).join(''));
    }

    function resetForm(createMode = true) {
        $('#kgqForm')[0].reset();
        $('#kgq_id').val('');
        $('#kgq_sort_order').val('0');
        $('#kgq_is_active').prop('checked', true);
        $('#kgqModalTitle').text(createMode ? 'Yeni Kart Oyunu Sorusu' : 'Kart Oyunu Sorusu Düzenle');

        state.croppedBlob = null;
        state.editMode = !createMode;
        state.keepExistingImage = !createMode;
        $('#kgq_cropped_ready').val('0');
        setPreview('');
    }

    function renderList(items) {
        const $tb = $('#kgqTable tbody');
        const $mobile = $('#kgqMobileList');
        $tb.empty();
        $mobile.empty();

        if (!items.length) {
            $tb.html('<tr><td colspan="8" class="text-muted p-3">Kayıt bulunamadı.</td></tr>');
            $mobile.html('<div class="text-muted p-2">Kayıt bulunamadı.</div>');
            return;
        }

        items.forEach((r) => {
            const status = Number(r.is_active) === 1
                ? '<span class="badge text-bg-success">Aktif</span>'
                : '<span class="badge text-bg-secondary">Pasif</span>';

            const thumb = r.image_url
                ? `<img src="${esc(r.image_url)}" alt="thumb" class="kgq-thumb">`
                : '<span class="text-muted small">Yok</span>';

            $tb.append(`
                <tr>
                    <td>${thumb}</td>
                    <td>${esc(r.question_text || '')}</td>
                    <td>${esc(r.correct_answer || '')}</td>
                    <td>${esc(r.category_title || '-')}</td>
                    <td>${status}</td>
                    <td>${esc(r.sort_order || 0)}</td>
                    <td><small>${esc(r.created_at || '-')}</small></td>
                    <td>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-outline-primary kgq-edit" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger kgq-delete" data-id="${esc(r.id)}"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `);

            $mobile.append(`
                <div class="card mb-2">
                    <div class="card-body">
                        <div class="d-flex gap-2">
                            ${thumb}
                            <div class="flex-grow-1">
                                <div class="fw-semibold">${esc(r.question_text || '')}</div>
                                <div class="small text-muted">${esc(r.category_title || '-')} • ${esc(r.correct_answer || '')}</div>
                                <div class="mt-1">${status}</div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-sm btn-outline-primary kgq-edit" data-id="${esc(r.id)}"><i class="bi bi-pencil"></i> Düzenle</button>
                            <button class="btn btn-sm btn-outline-danger kgq-delete" data-id="${esc(r.id)}"><i class="bi bi-trash"></i> Sil</button>
                        </div>
                    </div>
                </div>
            `);
        });
    }

    function updatePagination(meta) {
        state.page = Number(meta.page || 1);
        state.totalPages = Number(meta.total_pages || 1);

        $('#kgqPageInfo').text(`Sayfa ${state.page} / ${state.totalPages}`);
        $('#kgqPrevBtn').prop('disabled', state.page <= 1);
        $('#kgqNextBtn').prop('disabled', state.page >= state.totalPages);
    }

    async function loadCategories() {
        const res = await api('category_options', 'GET');
        if (!res.success) {
            await appAlert('Hata', res.message || 'Başlık listesi alınamadı.', 'error');
            return false;
        }
        state.categories = res.data?.categories || [];
        fillCategorySelects();
        return true;
    }

    async function loadList() {
        const res = await api('list', 'GET', {
            category_id: $('#kgqFilterCategory').val() || '',
            is_active: $('#kgqFilterActive').val() || '',
            search: $('#kgqFilterSearch').val() || '',
            page: state.page,
            per_page: state.perPage
        });

        if (!res.success) {
            await appAlert('Hata', res.message || 'Liste alınamadı.', 'error');
            return;
        }

        renderList(res.data?.items || []);
        updatePagination(res.data?.pagination || {});
    }

    function destroyCropper() {
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        if (cropSourceUrl) {
            URL.revokeObjectURL(cropSourceUrl);
            cropSourceUrl = '';
        }
        pendingCropFile = null;
        $('#kgqCropImage').attr('src', '');
    }

    function fitCropperInitialLayout() {
        if (!cropper) return;

        const containerData = cropper.getContainerData();
        const imageData = cropper.getImageData();

        if (!containerData?.width || !containerData?.height || !imageData?.naturalWidth || !imageData?.naturalHeight) {
            return;
        }

        cropper.reset();

        const fitScale = Math.min(
            containerData.width / imageData.naturalWidth,
            containerData.height / imageData.naturalHeight
        );
        if (Number.isFinite(fitScale) && fitScale > 0) {
            cropper.zoomTo(fitScale);
        }
        cropper.center();

        const maxCropW = containerData.width * 0.78;
        const maxCropH = containerData.height * 0.88;
        let cropW = maxCropW;
        let cropH = cropW / cropAspectRatio;

        if (cropH > maxCropH) {
            cropH = maxCropH;
            cropW = cropH * cropAspectRatio;
        }

        cropper.setCropBoxData({
            width: cropW,
            height: cropH,
            left: (containerData.width - cropW) / 2,
            top: (containerData.height - cropH) / 2,
        });
    }

    function openCropModal(file) {
        pendingCropFile = file;
        const cropModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('kgqCropModal'));
        cropModal.show();
    }

    function initCropperForPendingFile() {
        if (!pendingCropFile) return;

        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        if (cropSourceUrl) {
            URL.revokeObjectURL(cropSourceUrl);
            cropSourceUrl = '';
        }

        const image = document.getElementById('kgqCropImage');
        cropSourceUrl = URL.createObjectURL(pendingCropFile);

        image.onload = function () {
            image.onload = null;
            cropper = new Cropper(image, {
                aspectRatio: cropAspectRatio,
                viewMode: 1,
                autoCropArea: 0.82,
                dragMode: 'move',
                responsive: true,
                background: false,
                zoomable: true,
                zoomOnWheel: true,
                movable: true,
                guides: true,
                center: true,
                highlight: false,
                toggleDragModeOnDblclick: false,
                ready() {
                    requestAnimationFrame(fitCropperInitialLayout);
                }
            });
        };

        image.src = cropSourceUrl;
    }

    function formatErrors(res) {
        const errs = res?.data?.errors || {};
        const list = Object.values(errs).filter(Boolean);
        return list.join('\n') || res?.message || 'İşlem başarısız.';
    }

    $(document).on('click', '#kgqAddBtn', function () {
        resetForm(true);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('kgqModal')).show();
    });

    $('#kgq_image_input').on('change', function () {
        const file = this.files?.[0];
        if (!file) return;
        openCropModal(file);
        $(this).val('');
    });

    $('#kgqCropUseBtn').on('click', async function () {
        if (!cropper) return;

        const cropData = cropper.getData(true);
        const naturalCropW = Math.round(cropData?.width || 0);
        const naturalCropH = Math.round(cropData?.height || 0);

        if (naturalCropW < minCropWidth || naturalCropH < minCropHeight) {
            await appAlert('Uyarı', `Crop sonucu çok küçük. Minimum ${minCropWidth}x${minCropHeight} olmalı.`, 'warning');
            return;
        }

        let exportWidth = Math.max(minCropWidth, Math.min(1800, naturalCropW));
        let exportHeight = Math.round(exportWidth / cropAspectRatio);
        if (exportHeight < minCropHeight) {
            exportHeight = minCropHeight;
            exportWidth = Math.round(exportHeight * cropAspectRatio);
        }

        const canvas = cropper.getCroppedCanvas({
            width: exportWidth,
            height: exportHeight,
            imageSmoothingQuality: 'high',
            imageSmoothingEnabled: true,
            fillColor: '#fff'
        });
        if (!canvas) {
            await appAlert('Hata', 'Crop alanı alınamadı.', 'error');
            return;
        }

        const width = canvas.width;
        const height = canvas.height;
        if (width < minCropWidth || height < minCropHeight) {
            await appAlert('Uyarı', `Crop sonucu çok küçük. Minimum ${minCropWidth}x${minCropHeight} olmalı.`, 'warning');
            return;
        }

        canvas.toBlob(async (blob) => {
            if (!blob) {
                await appAlert('Hata', 'Kırpılmış dosya üretilemedi.', 'error');
                return;
            }

            state.croppedBlob = blob;
            state.keepExistingImage = false;
            $('#kgq_cropped_ready').val('1');

            const previewUrl = URL.createObjectURL(blob);
            setPreview(previewUrl, false, true);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('kgqCropModal')).hide();
        }, 'image/jpeg', 0.94);
    });

    $('#kgqCropModal').on('shown.bs.modal', function () {
        initCropperForPendingFile();
    });

    $('#kgqCropModal').on('hidden.bs.modal', function () {
        destroyCropper();
    });

    $('#kgqForm').on('submit', async function (e) {
        e.preventDefault();

        const id = ($('#kgq_id').val() || '').trim();
        const action = id ? 'update' : 'create';

        if (!id && !state.croppedBlob) {
            await appAlert('Uyarı', 'Görsel seçip 4:5 oranında kırpmanız zorunludur.', 'warning');
            return;
        }

        const fd = new FormData();
        fd.append('id', id);
        fd.append('category_id', $('#kgq_category_id').val() || '');
        fd.append('question_text', $('#kgq_question_text').val() || '');
        fd.append('correct_answer', $('#kgq_correct_answer').val() || '');
        fd.append('sort_order', $('#kgq_sort_order').val() || '0');
        fd.append('is_active', $('#kgq_is_active').is(':checked') ? '1' : '0');

        if (state.croppedBlob) {
            fd.append('image', state.croppedBlob, 'kart-game-crop.jpg');
        }

        const $btn = $('#kgqSaveBtn');
        window.appSetButtonLoading($btn, true, 'Kaydediliyor...');
        const res = await apiForm(action, fd);
        window.appSetButtonLoading($btn, false);

        if (!res.success) {
            await appAlert('Hata', formatErrors(res), 'error');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('kgqModal')).hide();
        await appAlert('Başarılı', res.message || 'Kaydedildi.', 'success');
        state.page = 1;
        loadList();
    });

    $(document).on('click', '.kgq-edit', async function () {
        const id = $(this).data('id');
        const res = await api('get', 'GET', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Kayıt alınamadı.', 'error');
            return;
        }

        const item = res.data?.item;
        if (!item) {
            await appAlert('Hata', 'Kayıt bulunamadı.', 'error');
            return;
        }

        resetForm(false);
        $('#kgq_id').val(item.id || '');
        $('#kgq_category_id').val(item.category_id || '');
        $('#kgq_question_text').val(item.question_text || '');
        $('#kgq_correct_answer').val(item.correct_answer || '');
        $('#kgq_sort_order').val(item.sort_order || 0);
        $('#kgq_is_active').prop('checked', Number(item.is_active) === 1);
        setPreview(item.image_url || '', true);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('kgqModal')).show();
    });

    $(document).on('click', '.kgq-delete', async function () {
        const id = $(this).data('id');
        const ok = await appConfirm('Soruyu Sil', 'Bu soru kalıcı olarak silinecek. Onaylıyor musunuz?', {
            type: 'warning',
            confirmText: 'Sil',
            cancelText: 'İptal'
        });
        if (!ok) return;

        const res = await api('delete', 'POST', { id });
        if (!res.success) {
            await appAlert('Hata', res.message || 'Silme başarısız.', 'error');
            return;
        }
        await appAlert('Başarılı', res.message || 'Silindi.', 'success');
        loadList();
    });

    $('#kgqFilterCategory, #kgqFilterActive').on('change', function () {
        state.page = 1;
        loadList();
    });

    $('#kgqFilterSearch').on('input', function () {
        clearTimeout(window.__kgqSearchTimer);
        window.__kgqSearchTimer = setTimeout(() => {
            state.page = 1;
            loadList();
        }, 250);
    });

    $('#kgqFilterClear').on('click', function () {
        $('#kgqFilterCategory').val('');
        $('#kgqFilterActive').val('');
        $('#kgqFilterSearch').val('');
        state.page = 1;
        loadList();
    });

    $('#kgqPrevBtn').on('click', function () {
        if (state.page > 1) {
            state.page -= 1;
            loadList();
        }
    });

    $('#kgqNextBtn').on('click', function () {
        if (state.page < state.totalPages) {
            state.page += 1;
            loadList();
        }
    });

    $('#kgqModal').on('hidden.bs.modal', function () {
        resetForm(true);
    });

    (async function init() {
        const ok = await loadCategories();
        if (!ok) return;
        loadList();
    })();
})();

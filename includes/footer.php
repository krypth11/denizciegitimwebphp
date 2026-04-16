    </main>
    </div>
    </div>

    <!-- jQuery - EN ÖNCE YÜKLENECEK! -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <!-- Bootstrap JS Bundle (Popper dahil) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Custom JS -->
    <script src="/assets/js/app.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/js/app.js') ?>"></script>

    <!-- Ortak Uyarı/Onay Modalı -->
    <div class="modal fade" id="appDialogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="appDialogTitle">Bilgi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="appDialogBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="appDialogCancelBtn" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" id="appDialogConfirmBtn">Tamam</button>
                </div>
            </div>
        </div>
    </div>

    <?php
    $sub_event_label_map_tr = function_exists('subscription_mgmt_event_type_label_map_tr')
        ? subscription_mgmt_event_type_label_map_tr()
        : [
            'INITIAL_PURCHASE' => 'İlk Satın Alma',
            'RENEWAL' => 'Yenileme',
            'EXPIRATION' => 'Süre Doldu',
            'CANCELLATION' => 'İptal',
            'BILLING_ISSUE' => 'Ödeme Sorunu',
            'REFUND' => 'İade',
            'PRODUCT_CHANGE' => 'Paket Değişikliği',
            'UNCANCELLATION' => 'İptal Geri Alındı',
            'TRANSFER' => 'Transfer',
            'SUBSCRIPTION_PAUSED' => 'Abonelik Duraklatıldı',
            'TEMPORARY_ENTITLEMENT_GRANT' => 'Geçici Erişim',
        ];
    $sub_status_label_map_tr = function_exists('subscription_mgmt_status_label_map_tr')
        ? subscription_mgmt_status_label_map_tr()
        : [
            'processed' => 'İşlendi',
            'duplicate' => 'Tekrar',
            'unmatched_user' => 'Eşleşmedi',
            'conflict' => 'Çakışma',
            'failed' => 'Hata',
        ];
    ?>

    <script>
        (function () {
            const eventTypeLabels = <?= json_encode($sub_event_label_map_tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const statusLabels = <?= json_encode($sub_status_label_map_tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            window.subscriptionAdminUi = {
                eventTypeLabels,
                statusLabels,
                eventTypeLabel(value) {
                    const key = String(value || '').trim().toUpperCase();
                    return eventTypeLabels[key] || (key || '-');
                },
                statusLabel(value) {
                    const key = String(value || '').trim().toLowerCase();
                    return statusLabels[key] || (key || '-');
                }
            };
        })();
    </script>

    <!-- Sayfa özel JavaScript -->
    <?php if (isset($extra_js)): ?>
        <?= $extra_js ?>
    <?php endif; ?>

</body>
</html>

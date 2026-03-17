    </div> <!-- End Main Content -->

    <!-- jQuery - EN ÖNCE YÜKLENECEK! -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <!-- Bootstrap JS Bundle (Popper dahil) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Custom JS dosyası (varsa) -->
    <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/assets/js/app.js')): ?>
        <script src="/assets/js/app.js"></script>
    <?php endif; ?>

    <!-- Sayfa özel JavaScript -->
    <?php if (isset($extra_js)): ?>
        <?= $extra_js ?>
    <?php endif; ?>

    <!-- jQuery Test Script -->
    <script>
    // jQuery yüklendiğini doğrula
    if (typeof jQuery === 'undefined') {
        console.error('HATA: jQuery yüklenemedi!');
        alert('HATA: jQuery yüklenemedi! Lütfen internet bağlantınızı kontrol edin.');
    } else {
        console.log('jQuery başarıyla yüklendi. Versiyon:', jQuery.fn.jquery);
    }
    </script>
</body>
</html>

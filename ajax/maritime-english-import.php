<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/maritime_english_import_helper.php';
try { require_admin(); } catch (Throwable $e) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Yetkisiz erişim.']); exit; }
function me_admin_json(bool $success, string $message = '', array $data = [], int $status = 200): void {
    http_response_code($status); echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
    if ($action === 'categories') {
        $rows = $pdo->query('SELECT id, name FROM maritime_english_categories WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $qualifications = $pdo->query('SELECT id, name FROM qualifications WHERE is_active = 1 ORDER BY order_index, name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        me_admin_json(true, '', ['categories' => $rows, 'qualifications' => $qualifications]);
    }
    if ($action === 'parse') {
        $result = me_import_parse((string)($_POST['content'] ?? ''));
        me_admin_json((bool)$result['success'], $result['success'] ? 'Metin başarıyla analiz edildi.' : 'Metin doğrulanamadı.', $result, $result['success'] ? 200 : 422);
    }
    if ($action === 'import') {
        $decoded = json_decode((string)($_POST['parsed_json'] ?? ''), true);
        if (!is_array($decoded)) me_admin_json(false, 'Geçersiz önizleme verisi.', [], 422);
        $pdo->beginTransaction();
        $saved = me_import_save($pdo, trim((string)($_POST['category_id'] ?? '')), trim((string)($_POST['qualification_id'] ?? '')), $decoded);
        $pdo->commit();
        me_admin_json(true, 'İçerik başarıyla kaydedildi.', $saved);
    }
    me_admin_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    me_admin_json(false, $e instanceof InvalidArgumentException ? $e->getMessage() : 'İçerik kaydedilemedi.', [], $e instanceof InvalidArgumentException ? 422 : 500);
}

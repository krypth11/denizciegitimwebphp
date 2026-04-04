<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/qualification_heading_helper.php';

$user = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function qh_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'list_headings':
            qh_json(true, '', [
                'headings' => qualification_heading_list($pdo),
            ]);
            break;

        case 'get_heading_detail':
            $headingId = trim((string)($_GET['heading_id'] ?? $_GET['id'] ?? ''));
            if ($headingId === '') {
                qh_json(false, 'Başlık ID alanı zorunludur.', [], 422);
            }

            qh_json(true, '', [
                'heading' => qualification_heading_detail($pdo, $headingId),
                'available_qualifications' => qualification_heading_active_qualifications($pdo, $headingId),
            ]);
            break;

        case 'create_heading':
            $created = qualification_heading_create($pdo, [
                'name' => sanitize_input($_POST['name'] ?? ''),
                'order_index' => (int)($_POST['order_index'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1,
            ]);

            qh_json(true, 'Başlık başarıyla oluşturuldu.', ['heading' => $created]);
            break;

        case 'update_heading':
            $id = trim((string)($_POST['id'] ?? ''));
            $updated = qualification_heading_update($pdo, $id, [
                'name' => sanitize_input($_POST['name'] ?? ''),
                'order_index' => (int)($_POST['order_index'] ?? 0),
            ]);

            qh_json(true, 'Başlık başarıyla güncellendi.', ['heading' => $updated]);
            break;

        case 'delete_heading':
            $id = trim((string)($_POST['id'] ?? ''));
            qualification_heading_delete($pdo, $id);
            qh_json(true, 'Başlık başarıyla silindi.');
            break;

        case 'toggle_heading_active':
            $id = trim((string)($_POST['id'] ?? ''));
            $isActive = (int)($_POST['is_active'] ?? 0) === 1;
            qualification_heading_toggle_active($pdo, $id, $isActive);
            qh_json(true, 'Başlık durumu güncellendi.');
            break;

        case 'attach_qualification':
            $headingId = trim((string)($_POST['heading_id'] ?? ''));
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            $item = qualification_heading_attach_item($pdo, $headingId, $qualificationId, $orderIndex);
            qh_json(true, 'Yeterlilik başlığa eklendi.', ['item' => $item]);
            break;

        case 'detach_qualification':
            $headingId = trim((string)($_POST['heading_id'] ?? ''));
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
            qualification_heading_detach_item($pdo, $headingId, $qualificationId);
            qh_json(true, 'Yeterlilik başlıktan çıkarıldı.');
            break;

        case 'reorder_heading':
            $id = trim((string)($_POST['id'] ?? ''));
            $orderIndex = (int)($_POST['order_index'] ?? 0);
            qualification_heading_reorder_heading($pdo, $id, $orderIndex);
            qh_json(true, 'Başlık sırası güncellendi.');
            break;

        case 'reorder_heading_item':
            $headingId = trim((string)($_POST['heading_id'] ?? ''));
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
            $orderIndex = (int)($_POST['order_index'] ?? 0);
            qualification_heading_reorder_item($pdo, $headingId, $qualificationId, $orderIndex);
            qh_json(true, 'Başlık içi yeterlilik sırası güncellendi.');
            break;

        default:
            qh_json(false, 'Geçersiz işlem.', [], 400);
            break;
    }
} catch (InvalidArgumentException $e) {
    qh_json(false, $e->getMessage(), [], 422);
} catch (RuntimeException $e) {
    qh_json(false, $e->getMessage(), [], 400);
} catch (Throwable $e) {
    qh_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

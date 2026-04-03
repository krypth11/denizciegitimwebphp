<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/story_helper.php';

$authUser = require_admin();

function stories_response(bool $success, string $message = '', array $data = [], int $status = 200, array $errors = []): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function stories_safe_error_payload(Throwable $e): array
{
    if (!story_is_debug_mode()) {
        return [];
    }

    return [
        'error' => $e->getMessage(),
        'type' => get_class($e),
    ];
}

try {
    story_ensure_schema($pdo);

    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
    $adminId = (string)($authUser['user_id'] ?? ($_SESSION['user_id'] ?? ''));

    switch ($action) {
        case 'list': {
            $items = story_admin_list($pdo);
            stories_response(true, '', ['stories' => $items]);
            break;
        }

        case 'create': {
            story_log('create request geldi', [
                'has_title' => isset($_POST['title']),
                'has_thumbnail' => isset($_FILES['thumbnail']),
                'has_image' => isset($_FILES['image']),
                'admin_id' => $adminId,
            ]);

            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                stories_response(false, 'Hikaye adı zorunludur.', [], 422, ['title' => 'required']);
            }
            if (mb_strlen($title) > 191) {
                stories_response(false, 'Hikaye adı en fazla 191 karakter olabilir.', [], 422, ['title' => 'max_191']);
            }

            if (!isset($_FILES['thumbnail'])) {
                stories_response(false, 'Thumbnail görseli zorunludur.', [], 422, ['thumbnail' => 'required']);
            }
            if (!isset($_FILES['image'])) {
                stories_response(false, 'Story görseli zorunludur.', [], 422, ['image' => 'required']);
            }

            $thumbPath = '';
            $imagePath = '';

            try {
                $thumbPath = story_store_uploaded_image($_FILES['thumbnail'], 'thumbnail');
                $imagePath = story_store_uploaded_image($_FILES['image'], 'image');
                $storyId = story_create($pdo, $title, $thumbPath, $imagePath, $adminId);
            } catch (Throwable $e) {
                story_delete_file_if_exists($thumbPath);
                story_delete_file_if_exists($imagePath);
                throw $e;
            }

            stories_response(true, 'Hikaye oluşturuldu.', ['story_id' => $storyId]);
            break;
        }

        case 'toggle_active': {
            $storyId = trim((string)($_POST['story_id'] ?? ''));
            $isActive = (int)($_POST['is_active'] ?? -1);

            if ($storyId === '') {
                stories_response(false, 'story_id zorunludur.', [], 422, ['story_id' => 'required']);
            }
            if (!in_array($isActive, [0, 1], true)) {
                stories_response(false, 'is_active değeri 0 veya 1 olmalıdır.', [], 422, ['is_active' => 'invalid']);
            }

            $updated = story_set_active($pdo, $storyId, $isActive, $adminId);
            if (!$updated) {
                stories_response(false, 'Hikaye bulunamadı veya güncellenemedi.', [], 404);
            }

            stories_response(true, $isActive === 1 ? 'Hikaye aktif edildi.' : 'Hikaye pasife alındı.');
            break;
        }

        case 'delete': {
            $storyId = trim((string)($_POST['story_id'] ?? ''));
            if ($storyId === '') {
                stories_response(false, 'story_id zorunludur.', [], 422, ['story_id' => 'required']);
            }

            $deleted = story_soft_delete($pdo, $storyId, $adminId);
            if (!$deleted) {
                stories_response(false, 'Hikaye bulunamadı.', [], 404);
            }

            stories_response(true, 'Hikaye silindi.');
            break;
        }

        default:
            stories_response(false, 'Geçersiz işlem.', [], 400);
    }
} catch (Throwable $e) {
    story_log('admin stories error', ['error' => $e->getMessage()]);
    stories_response(false, 'İşlem sırasında bir sunucu hatası oluştu.', stories_safe_error_payload($e), 500);
}

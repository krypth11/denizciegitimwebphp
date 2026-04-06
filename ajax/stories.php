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
        'file' => $e->getFile(),
        'line' => $e->getLine(),
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

        case 'get': {
            $storyId = trim((string)($_GET['story_id'] ?? $_POST['story_id'] ?? ''));
            story_log('story get requested', [
                'story_id' => $storyId,
                'admin_id' => $adminId,
            ]);

            if ($storyId === '') {
                stories_response(false, 'story_id zorunludur.', [], 422, ['story_id' => 'required']);
            }

            $story = story_find_by_id($pdo, $storyId);
            if (!$story) {
                stories_response(false, 'Hikaye bulunamadı.', [], 404);
            }

            $item = story_normalize_story_row_urls([
                'id' => (string)($story['id'] ?? ''),
                'title' => (string)($story['title'] ?? ''),
                'thumbnail_url' => (string)($story['thumbnail_url'] ?? ''),
                'image_url' => (string)($story['image_url'] ?? ''),
                'is_active' => ((int)($story['is_active'] ?? 0) === 1) ? 1 : 0,
                'created_at' => (string)($story['created_at'] ?? ''),
            ], 'admin_get');

            stories_response(true, '', ['story' => $item]);
            break;
        }

        case 'create': {
            story_log('create request geldi', [
                'has_title' => isset($_POST['title']),
                'title_length' => mb_strlen((string)($_POST['title'] ?? '')),
                'has_thumbnail' => isset($_FILES['thumbnail']),
                'has_image' => isset($_FILES['image']),
                'thumbnail_meta' => [
                    'name' => (string)($_FILES['thumbnail']['name'] ?? ''),
                    'size' => (int)($_FILES['thumbnail']['size'] ?? 0),
                    'error' => (int)($_FILES['thumbnail']['error'] ?? -1),
                    'tmp_name' => (string)($_FILES['thumbnail']['tmp_name'] ?? ''),
                ],
                'image_meta' => [
                    'name' => (string)($_FILES['image']['name'] ?? ''),
                    'size' => (int)($_FILES['image']['size'] ?? 0),
                    'error' => (int)($_FILES['image']['error'] ?? -1),
                    'tmp_name' => (string)($_FILES['image']['tmp_name'] ?? ''),
                ],
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
                story_log('create transaction rollback cleanup', [
                    'error' => $e->getMessage(),
                    'thumb_path' => $thumbPath,
                    'image_path' => $imagePath,
                ]);
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

        case 'update': {
            $storyId = trim((string)($_POST['story_id'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));

            story_log('story update started', [
                'story_id' => $storyId,
                'title_length' => mb_strlen($title),
                'has_thumbnail' => isset($_FILES['thumbnail']) && ((int)($_FILES['thumbnail']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE),
                'has_image' => isset($_FILES['image']) && ((int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE),
                'admin_id' => $adminId,
            ]);

            if ($storyId === '') {
                stories_response(false, 'story_id zorunludur.', [], 422, ['story_id' => 'required']);
            }
            if ($title === '') {
                stories_response(false, 'Hikaye adı zorunludur.', [], 422, ['title' => 'required']);
            }
            if (mb_strlen($title) > 191) {
                stories_response(false, 'Hikaye adı en fazla 191 karakter olabilir.', [], 422, ['title' => 'max_191']);
            }

            $current = story_find_by_id($pdo, $storyId);
            if (!$current) {
                stories_response(false, 'Hikaye bulunamadı.', [], 404);
            }

            $oldThumb = (string)($current['thumbnail_url'] ?? '');
            $oldImage = (string)($current['image_url'] ?? '');

            $newThumb = null;
            $newImage = null;

            try {
                if (isset($_FILES['thumbnail']) && (int)($_FILES['thumbnail']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newThumb = story_store_uploaded_image($_FILES['thumbnail'], 'thumbnail');
                    story_log('uploaded new thumbnail', ['story_id' => $storyId, 'new_thumbnail' => $newThumb]);
                }

                if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newImage = story_store_uploaded_image($_FILES['image'], 'image');
                    story_log('uploaded new image', ['story_id' => $storyId, 'new_image' => $newImage]);
                }

                $updated = story_update($pdo, $storyId, $title, $newThumb, $newImage, $adminId);
                if (!$updated) {
                    throw new RuntimeException('Hikaye güncellenemedi.');
                }

                story_log('story update db success', [
                    'story_id' => $storyId,
                    'title_updated' => true,
                    'thumbnail_updated' => $newThumb !== null,
                    'image_updated' => $newImage !== null,
                ]);

                if ($newThumb !== null && $oldThumb !== '' && $oldThumb !== $newThumb) {
                    story_delete_file_if_exists($oldThumb);
                    story_log('old thumbnail deleted', ['story_id' => $storyId, 'old_thumbnail' => $oldThumb]);
                }

                if ($newImage !== null && $oldImage !== '' && $oldImage !== $newImage) {
                    story_delete_file_if_exists($oldImage);
                    story_log('old image deleted', ['story_id' => $storyId, 'old_image' => $oldImage]);
                }
            } catch (Throwable $e) {
                if ($newThumb !== null) {
                    story_delete_file_if_exists($newThumb);
                    story_log('rollback deleted new upload', [
                        'story_id' => $storyId,
                        'type' => 'thumbnail',
                        'path' => $newThumb,
                    ]);
                }
                if ($newImage !== null) {
                    story_delete_file_if_exists($newImage);
                    story_log('rollback deleted new upload', [
                        'story_id' => $storyId,
                        'type' => 'image',
                        'path' => $newImage,
                    ]);
                }

                story_log('update error', [
                    'story_id' => $storyId,
                    'error' => $e->getMessage(),
                    'type' => get_class($e),
                ]);

                throw $e;
            }

            stories_response(true, 'Hikaye güncellendi.', ['story_id' => $storyId]);
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
    story_log('admin stories error', [
        'error' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    stories_response(false, 'İşlem sırasında bir sunucu hatası oluştu.', stories_safe_error_payload($e), 500);
}

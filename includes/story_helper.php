<?php

require_once __DIR__ . '/functions.php';

function story_log(string $message, array $context = []): void
{
    if (!empty($context)) {
        error_log('[STORY] ' . $message . ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        return;
    }

    error_log('[STORY] ' . $message);
}

function story_q(string $identifier): string
{
    return '`' . str_replace('`', '', $identifier) . '`';
}

function story_project_root(): string
{
    return dirname(__DIR__);
}

function story_ensure_schema(PDO $pdo): void
{
    $sqlStories = "CREATE TABLE IF NOT EXISTS `app_stories` (
        `id` CHAR(36) NOT NULL,
        `title` VARCHAR(191) NOT NULL,
        `thumbnail_path` VARCHAR(255) NOT NULL,
        `image_path` VARCHAR(255) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL DEFAULT NULL,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        `created_by` VARCHAR(36) NULL,
        `updated_by` VARCHAR(36) NULL,
        `deleted_by` VARCHAR(36) NULL,
        PRIMARY KEY (`id`),
        KEY `idx_stories_active_created` (`is_active`, `is_deleted`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqlViews = "CREATE TABLE IF NOT EXISTS `app_story_views` (
        `id` CHAR(36) NOT NULL,
        `story_id` CHAR(36) NOT NULL,
        `user_id` VARCHAR(36) NOT NULL,
        `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_story_user` (`story_id`, `user_id`),
        KEY `idx_story_views_user` (`user_id`, `viewed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sqlStories);
    $pdo->exec($sqlViews);
}

function story_upload_dirs(): array
{
    $baseRelative = 'uploads/stories';
    $baseDir = story_project_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stories';
    $thumbDir = $baseDir . DIRECTORY_SEPARATOR . 'thumbnails';
    $imageDir = $baseDir . DIRECTORY_SEPARATOR . 'images';

    foreach ([$baseDir, $thumbDir, $imageDir] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Upload dizini oluşturulamadı: ' . $dir);
        }
    }

    return [
        'base_relative' => $baseRelative,
        'base' => $baseDir,
        'thumbnails' => $thumbDir,
        'images' => $imageDir,
    ];
}

function story_validate_upload(array $file, string $label): array
{
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException($label . ' yükleme hatası oluştu.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException($label . ' geçerli bir upload değil.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > (5 * 1024 * 1024)) {
        throw new RuntimeException($label . ' en fazla 5MB olabilir.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException($label . ' için yalnızca JPG, PNG veya WEBP kabul edilir.');
    }

    return [
        'tmp' => $tmp,
        'mime' => $mime,
        'ext' => $allowed[$mime],
    ];
}

function story_store_uploaded_image(array $file, string $type): string
{
    $type = $type === 'thumbnail' ? 'thumbnail' : 'image';
    $validated = story_validate_upload($file, $type === 'thumbnail' ? 'Thumbnail' : 'Story görseli');
    $dirs = story_upload_dirs();

    $targetDir = $type === 'thumbnail' ? $dirs['thumbnails'] : $dirs['images'];
    $relativePrefix = $type === 'thumbnail' ? 'uploads/stories/thumbnails/' : 'uploads/stories/images/';

    $filename = generate_uuid() . '.' . $validated['ext'];
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
    $relativePath = $relativePrefix . $filename;

    if (!move_uploaded_file($validated['tmp'], $targetPath)) {
        throw new RuntimeException('Dosya sunucuya taşınamadı.');
    }

    return str_replace('\\', '/', $relativePath);
}

function story_relative_to_abs(string $relativePath): string
{
    $clean = ltrim(str_replace(['..', '\\'], ['', '/'], trim($relativePath)), '/');
    return story_project_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $clean);
}

function story_delete_file_if_exists(?string $relativePath): void
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') {
        return;
    }

    $absolutePath = story_relative_to_abs($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function story_public_url(?string $relativePath): string
{
    $relativePath = ltrim(trim((string)$relativePath), '/');
    if ($relativePath === '') {
        return '';
    }

    return rtrim((string)SITE_URL, '/') . '/' . $relativePath;
}

function story_find_by_id(PDO $pdo, string $storyId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM `app_stories` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$storyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function story_create(PDO $pdo, string $title, string $thumbnailPath, string $imagePath, ?string $adminId): string
{
    $id = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO `app_stories` (`id`, `title`, `thumbnail_path`, `image_path`, `is_active`, `is_deleted`, `created_at`, `created_by`) '
        . 'VALUES (?, ?, ?, ?, 1, 0, NOW(), ?)'
    );
    $stmt->execute([$id, $title, $thumbnailPath, $imagePath, $adminId]);

    story_log('story created', ['story_id' => $id, 'title' => $title, 'admin_id' => $adminId]);
    return $id;
}

function story_admin_list(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM `app_stories` WHERE `is_deleted` = 0 ORDER BY `created_at` DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'thumbnail_path' => (string)($row['thumbnail_path'] ?? ''),
            'image_path' => (string)($row['image_path'] ?? ''),
            'thumbnail_url' => story_public_url($row['thumbnail_path'] ?? ''),
            'image_url' => story_public_url($row['image_path'] ?? ''),
            'is_active' => ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0,
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }, $rows);
}

function story_set_active(PDO $pdo, string $storyId, int $isActive, ?string $adminId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE `app_stories` SET `is_active` = ?, `updated_at` = NOW(), `updated_by` = ? '
        . 'WHERE `id` = ? AND `is_deleted` = 0'
    );
    $stmt->execute([$isActive ? 1 : 0, $adminId, $storyId]);
    $updated = $stmt->rowCount() > 0;

    story_log('story active toggled', [
        'story_id' => $storyId,
        'is_active' => $isActive ? 1 : 0,
        'admin_id' => $adminId,
        'updated' => $updated,
    ]);

    return $updated;
}

function story_soft_delete(PDO $pdo, string $storyId, ?string $adminId): bool
{
    $story = story_find_by_id($pdo, $storyId);
    if (!$story || ((int)($story['is_deleted'] ?? 0) === 1)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE `app_stories` SET `is_deleted` = 1, `is_active` = 0, `deleted_at` = NOW(), `deleted_by` = ?, `updated_at` = NOW(), `updated_by` = ? '
        . 'WHERE `id` = ? LIMIT 1'
    );
    $stmt->execute([$adminId, $adminId, $storyId]);
    $updated = $stmt->rowCount() > 0;

    if ($updated) {
        story_delete_file_if_exists((string)($story['thumbnail_path'] ?? ''));
        story_delete_file_if_exists((string)($story['image_path'] ?? ''));
    }

    story_log('story deleted', ['story_id' => $storyId, 'admin_id' => $adminId, 'deleted' => $updated]);
    return $updated;
}

function story_mobile_list(PDO $pdo, string $userId): array
{
    $sql = 'SELECT s.`id`, s.`title`, s.`thumbnail_path`, s.`image_path`, s.`created_at`, '
        . 'CASE WHEN v.`id` IS NULL THEN 0 ELSE 1 END AS `is_viewed` '
        . 'FROM `app_stories` s '
        . 'LEFT JOIN `app_story_views` v ON v.`story_id` = s.`id` AND v.`user_id` = ? '
        . 'WHERE s.`is_deleted` = 0 AND s.`is_active` = 1 '
        . 'ORDER BY s.`created_at` DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'thumbnail_url' => story_public_url((string)($row['thumbnail_path'] ?? '')),
            'image_url' => story_public_url((string)($row['image_path'] ?? '')),
            'created_at' => (string)($row['created_at'] ?? ''),
            'is_viewed' => ((int)($row['is_viewed'] ?? 0) === 1),
        ];
    }, $rows);
}

function story_mark_viewed(PDO $pdo, string $storyId, string $userId): array
{
    $check = $pdo->prepare('SELECT `id` FROM `app_stories` WHERE `id` = ? AND `is_deleted` = 0 AND `is_active` = 1 LIMIT 1');
    $check->execute([$storyId]);
    if (!$check->fetchColumn()) {
        throw new RuntimeException('Story bulunamadı.');
    }

    $viewId = generate_uuid();
    $stmt = $pdo->prepare(
        'INSERT INTO `app_story_views` (`id`, `story_id`, `user_id`, `viewed_at`) VALUES (?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE `viewed_at` = `viewed_at`'
    );
    $stmt->execute([$viewId, $storyId, $userId]);
    $inserted = $stmt->rowCount() > 0;

    story_log('story viewed', [
        'story_id' => $storyId,
        'user_id' => $userId,
        'already_viewed' => !$inserted,
    ]);

    return [
        'story_id' => $storyId,
        'is_viewed' => true,
        'already_viewed' => !$inserted,
    ];
}

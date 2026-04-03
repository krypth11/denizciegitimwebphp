<?php

require_once __DIR__ . '/functions.php';

function story_log(string $message, array $context = []): void
{
    if (!empty($context)) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = '{"log_context_error":"json_encode_failed"}';
        }

        error_log('[STORY] ' . $message . ' | ' . $json);
        return;
    }

    error_log('[STORY] ' . $message);
}

function story_is_debug_mode(): bool
{
    $env = strtolower((string)getenv('APP_DEBUG'));
    if (in_array($env, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    return ini_get('display_errors') === '1';
}

function story_project_root(): string
{
    return dirname(__DIR__);
}

function story_upload_error_message(int $errorCode): string
{
    $map = [
        UPLOAD_ERR_OK => 'Upload başarılı.',
        UPLOAD_ERR_INI_SIZE => 'Dosya php.ini upload_max_filesize limitini aşıyor.',
        UPLOAD_ERR_FORM_SIZE => 'Dosya form MAX_FILE_SIZE limitini aşıyor.',
        UPLOAD_ERR_PARTIAL => 'Dosya kısmi yüklendi.',
        UPLOAD_ERR_NO_FILE => 'Dosya yüklenmedi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici upload dizini bulunamadı.',
        UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
        UPLOAD_ERR_EXTENSION => 'Dosya yükleme bir PHP uzantısı tarafından durduruldu.',
    ];

    return $map[$errorCode] ?? ('Bilinmeyen upload hatası (' . $errorCode . ')');
}

function story_upload_root_abs(): string
{
    $custom = trim((string)(getenv('STORY_UPLOAD_ROOT') ?: ''));
    if ($custom !== '') {
        $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $custom);
        return rtrim($clean, DIRECTORY_SEPARATOR);
    }

    return story_project_root() . DIRECTORY_SEPARATOR . 'uploads';
}

function story_ensure_directory_ready(string $dir): void
{
    clearstatcache(true, $dir);

    if (is_file($dir)) {
        throw new RuntimeException('Upload yolu bir dosyaya işaret ediyor: ' . $dir);
    }

    if (!is_dir($dir)) {
        $mkdirOk = @mkdir($dir, 0775, true);
        $mkdirErr = error_get_last();
        clearstatcache(true, $dir);

        story_log('mkdir denemesi', [
            'path' => $dir,
            'mkdir_ok' => $mkdirOk,
            'mkdir_error' => $mkdirErr['message'] ?? null,
            'exists_after' => is_dir($dir),
        ]);

        if (!$mkdirOk && !is_dir($dir)) {
            throw new RuntimeException('Upload dizini oluşturulamadı: ' . $dir . ' | ' . (string)($mkdirErr['message'] ?? 'unknown_error'));
        }
    }

    if (!is_writable($dir)) {
        @chmod($dir, 0775);
        clearstatcache(true, $dir);
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('Upload dizini yazılabilir değil: ' . $dir);
    }

    story_log('directory ready', [
        'path' => $dir,
        'is_dir' => is_dir($dir),
        'is_writable' => is_writable($dir),
    ]);
}

function story_upload_dirs(): array
{
    $uploadsRoot = story_upload_root_abs();
    $base = $uploadsRoot . DIRECTORY_SEPARATOR . 'stories';
    $thumb = $base . DIRECTORY_SEPARATOR . 'thumbnails';
    $image = $base . DIRECTORY_SEPARATOR . 'images';

    story_log('upload directory check başladı', [
        'project_root' => story_project_root(),
        'uploads_root' => $uploadsRoot,
        'stories_base' => $base,
    ]);

    foreach ([$base, $thumb, $image] as $dir) {
        story_ensure_directory_ready($dir);
    }

    return [
        'base' => $base,
        'thumbnails' => $thumb,
        'images' => $image,
    ];
}

function story_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([DB_NAME, $table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function story_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $map[$field] = $row;
        }
    }

    return $map;
}

function story_schema(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    if (!story_table_exists($pdo, 'app_stories')) {
        throw new RuntimeException('app_stories tablosu bulunamadı.');
    }

    if (!story_table_exists($pdo, 'app_story_views')) {
        throw new RuntimeException('app_story_views tablosu bulunamadı.');
    }

    $storyCols = story_columns($pdo, 'app_stories');
    $viewCols = story_columns($pdo, 'app_story_views');

    $requiredStory = ['id', 'title', 'thumbnail_url', 'image_url', 'is_active', 'sort_created_at', 'created_by', 'created_at', 'updated_at'];
    foreach ($requiredStory as $col) {
        if (!isset($storyCols[$col])) {
            throw new RuntimeException('app_stories kolon eksik: ' . $col);
        }
    }

    foreach (['story_id', 'user_id'] as $col) {
        if (!isset($viewCols[$col])) {
            throw new RuntimeException('app_story_views kolon eksik: ' . $col);
        }
    }

    $cache = [
        'stories_table' => 'app_stories',
        'views_table' => 'app_story_views',
        'story_columns' => $storyCols,
        'view_columns' => $viewCols,
        'view_has_id' => isset($viewCols['id']),
        'view_has_viewed_at' => isset($viewCols['viewed_at']),
    ];

    return $cache;
}

function story_ensure_schema(PDO $pdo): void
{
    $schema = story_schema($pdo);
    story_log('schema verified', [
        'stories_table' => $schema['stories_table'],
        'views_table' => $schema['views_table'],
    ]);
}

function story_validate_upload(array $file, string $label): array
{
    story_log('upload validate başladı', [
        'label' => $label,
        'name' => (string)($file['name'] ?? ''),
        'size' => (int)($file['size'] ?? 0),
        'error_code' => (int)($file['error'] ?? -1),
        'tmp_name' => (string)($file['tmp_name'] ?? ''),
    ]);

    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? -1);
        throw new RuntimeException($label . ' yükleme hatası: ' . story_upload_error_message($code));
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
        'ext' => $allowed[$mime],
        'mime' => $mime,
        'size' => $size,
    ];
}

function story_build_public_url(string $relativePath): string
{
    $clean = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    return rtrim((string)SITE_URL, '/') . '/' . $clean;
}

function story_public_url_to_abs(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || trim($path) === '') {
        return '';
    }

    $cleanPath = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');
    return story_project_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanPath);
}

function story_store_uploaded_image(array $file, string $type): string
{
    $type = ($type === 'thumbnail') ? 'thumbnail' : 'image';
    $label = $type === 'thumbnail' ? 'Thumbnail' : 'Story görseli';

    $validated = story_validate_upload($file, $label);
    $dirs = story_upload_dirs();
    $dir = $type === 'thumbnail' ? $dirs['thumbnails'] : $dirs['images'];
    $relativeDir = $type === 'thumbnail' ? 'uploads/stories/thumbnails' : 'uploads/stories/images';

    story_log('upload hazırlığı', [
        'type' => $type,
        'tmp_name' => $validated['tmp'],
        'dir' => $dir,
        'relative_dir' => $relativeDir,
        'dir_exists' => is_dir($dir),
        'dir_writable' => is_writable($dir),
        'tmp_exists' => is_file($validated['tmp']),
        'tmp_readable' => is_readable($validated['tmp']),
        'tmp_is_uploaded_file' => is_uploaded_file($validated['tmp']),
    ]);

    $filename = generate_uuid() . '.' . $validated['ext'];
    $target = $dir . DIRECTORY_SEPARATOR . $filename;

    story_log('move_uploaded_file denemesi', [
        'type' => $type,
        'tmp_name' => $validated['tmp'],
        'target' => $target,
        'target_dir' => dirname($target),
        'target_dir_exists' => is_dir(dirname($target)),
        'target_dir_writable' => is_writable(dirname($target)),
    ]);

    if (!move_uploaded_file($validated['tmp'], $target)) {
        $moveErr = error_get_last();
        story_log('move_uploaded_file başarısız', [
            'type' => $type,
            'tmp_name' => $validated['tmp'],
            'target' => $target,
            'target_dir_exists' => is_dir(dirname($target)),
            'target_dir_writable' => is_writable(dirname($target)),
            'tmp_exists' => is_file($validated['tmp']),
            'tmp_is_uploaded_file' => is_uploaded_file($validated['tmp']),
            'error' => $moveErr['message'] ?? null,
        ]);
        throw new RuntimeException($label . ' dosyası sunucuya taşınamadı. Hedef dizin izinlerini kontrol edin.');
    }

    clearstatcache(true, $target);
    if (!is_file($target) || filesize($target) <= 0) {
        throw new RuntimeException($label . ' dosyası taşındı ancak dosya doğrulanamadı.');
    }

    $url = story_build_public_url($relativeDir . '/' . $filename);

    story_log('upload success', [
        'type' => $type,
        'mime' => $validated['mime'],
        'size' => $validated['size'],
        'target' => $target,
        'url' => $url,
    ]);

    return $url;
}

function story_delete_file_if_exists(?string $url): void
{
    $abs = story_public_url_to_abs($url);
    if ($abs !== '' && is_file($abs)) {
        @unlink($abs);
    }
}

function story_find_by_id(PDO $pdo, string $storyId): ?array
{
    $schema = story_schema($pdo);
    $sql = 'SELECT * FROM `' . $schema['stories_table'] . '` WHERE `id` = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$storyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function story_create(PDO $pdo, string $title, string $thumbnailUrl, string $imageUrl, ?string $adminId): string
{
    $schema = story_schema($pdo);
    $id = generate_uuid();

    $payload = [
        'id' => $id,
        'title' => $title,
        'thumbnail_url' => $thumbnailUrl,
        'image_url' => $imageUrl,
        'is_active' => 1,
        'sort_created_at' => date('Y-m-d H:i:s'),
        'created_by' => $adminId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    story_log('insert payload', [
        'title' => $title,
        'thumbnail_url' => $thumbnailUrl,
        'image_url' => $imageUrl,
        'columns' => array_keys($payload),
        'story_id' => $id,
    ]);

    $sql = 'INSERT INTO `' . $schema['stories_table'] . '` (`id`,`title`,`thumbnail_url`,`image_url`,`is_active`,`sort_created_at`,`created_by`,`created_at`,`updated_at`) '
        . 'VALUES (?,?,?,?,?,?,?,?,?)';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $payload['id'],
        $payload['title'],
        $payload['thumbnail_url'],
        $payload['image_url'],
        $payload['is_active'],
        $payload['sort_created_at'],
        $payload['created_by'],
        $payload['created_at'],
        $payload['updated_at'],
    ]);

    story_log('story created', ['story_id' => $id, 'admin_id' => $adminId]);
    return $id;
}

function story_admin_list(PDO $pdo): array
{
    $schema = story_schema($pdo);
    $order = isset($schema['story_columns']['sort_created_at']) ? '`sort_created_at` DESC' : '`created_at` DESC';

    $sql = 'SELECT `id`,`title`,`thumbnail_url`,`image_url`,`is_active`,`created_at`,`sort_created_at` '
        . 'FROM `' . $schema['stories_table'] . '` ORDER BY ' . $order;

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    story_log('list query result', ['count' => count($rows)]);

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'thumbnail_url' => (string)($row['thumbnail_url'] ?? ''),
            'image_url' => (string)($row['image_url'] ?? ''),
            'is_active' => ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0,
            'created_at' => (string)($row['created_at'] ?? ''),
            'sort_created_at' => (string)($row['sort_created_at'] ?? ''),
        ];
    }, $rows);
}

function story_set_active(PDO $pdo, string $storyId, int $isActive, ?string $adminId = null): bool
{
    $schema = story_schema($pdo);
    $sql = 'UPDATE `' . $schema['stories_table'] . '` SET `is_active` = ?, `updated_at` = NOW() WHERE `id` = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$isActive ? 1 : 0, $storyId]);
    $updated = $stmt->rowCount() > 0;

    story_log('toggle result', [
        'story_id' => $storyId,
        'is_active' => $isActive ? 1 : 0,
        'updated' => $updated,
        'admin_id' => $adminId,
    ]);

    return $updated;
}

function story_soft_delete(PDO $pdo, string $storyId, ?string $adminId = null): bool
{
    $schema = story_schema($pdo);
    $story = story_find_by_id($pdo, $storyId);
    if (!$story) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM `' . $schema['stories_table'] . '` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$storyId]);
    $deleted = $stmt->rowCount() > 0;

    if ($deleted) {
        story_delete_file_if_exists((string)($story['thumbnail_url'] ?? ''));
        story_delete_file_if_exists((string)($story['image_url'] ?? ''));
    }

    story_log('delete result', [
        'story_id' => $storyId,
        'deleted' => $deleted,
        'admin_id' => $adminId,
    ]);

    return $deleted;
}

function story_mobile_list(PDO $pdo, string $userId): array
{
    $schema = story_schema($pdo);
    $order = isset($schema['story_columns']['sort_created_at']) ? 's.`sort_created_at` DESC' : 's.`created_at` DESC';
    $viewIdSelect = $schema['view_has_id'] ? 'v.`id`' : 'v.`story_id`';

    $sql = 'SELECT s.`id`, s.`title`, s.`thumbnail_url`, s.`image_url`, s.`created_at`, '
        . 'CASE WHEN ' . $viewIdSelect . ' IS NULL THEN 0 ELSE 1 END AS `is_viewed` '
        . 'FROM `' . $schema['stories_table'] . '` s '
        . 'LEFT JOIN `' . $schema['views_table'] . '` v ON v.`story_id` = s.`id` AND v.`user_id` = ? '
        . 'WHERE s.`is_active` = 1 '
        . 'ORDER BY ' . $order;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (string)($row['id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'thumbnail_url' => (string)($row['thumbnail_url'] ?? ''),
            'image_url' => (string)($row['image_url'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'is_viewed' => ((int)($row['is_viewed'] ?? 0) === 1),
        ];
    }, $rows);
}

function story_mark_viewed(PDO $pdo, string $storyId, string $userId): array
{
    $schema = story_schema($pdo);

    $check = $pdo->prepare('SELECT `id` FROM `' . $schema['stories_table'] . '` WHERE `id` = ? AND `is_active` = 1 LIMIT 1');
    $check->execute([$storyId]);
    if (!$check->fetchColumn()) {
        throw new RuntimeException('Story bulunamadı.');
    }

    $existsSql = 'SELECT COUNT(*) FROM `' . $schema['views_table'] . '` WHERE `story_id` = ? AND `user_id` = ?';
    $existsStmt = $pdo->prepare($existsSql);
    $existsStmt->execute([$storyId, $userId]);
    $exists = ((int)$existsStmt->fetchColumn()) > 0;

    if (!$exists) {
        if ($schema['view_has_id'] && $schema['view_has_viewed_at']) {
            $insertSql = 'INSERT INTO `' . $schema['views_table'] . '` (`id`, `story_id`, `user_id`, `viewed_at`) VALUES (?, ?, ?, NOW())';
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([generate_uuid(), $storyId, $userId]);
        } elseif ($schema['view_has_id']) {
            $insertSql = 'INSERT INTO `' . $schema['views_table'] . '` (`id`, `story_id`, `user_id`) VALUES (?, ?, ?)';
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([generate_uuid(), $storyId, $userId]);
        } elseif ($schema['view_has_viewed_at']) {
            $insertSql = 'INSERT INTO `' . $schema['views_table'] . '` (`story_id`, `user_id`, `viewed_at`) VALUES (?, ?, NOW())';
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([$storyId, $userId]);
        } else {
            $insertSql = 'INSERT INTO `' . $schema['views_table'] . '` (`story_id`, `user_id`) VALUES (?, ?)';
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([$storyId, $userId]);
        }
    }

    story_log('story viewed', [
        'story_id' => $storyId,
        'user_id' => $userId,
        'already_viewed' => $exists,
    ]);

    return [
        'story_id' => $storyId,
        'is_viewed' => true,
        'already_viewed' => $exists,
    ];
}

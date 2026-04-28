<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/study_resources_helper.php';

$user = require_admin();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

function sr_admin_json(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sr_upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Dosya sunucu upload limitini aşıyor. Sunucu upload limiti bu dosya için düşük olabilir.',
        UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi.',
        UPLOAD_ERR_NO_FILE => 'Dosya bulunamadı.',
        UPLOAD_ERR_NO_TMP_DIR => 'Geçici dizin bulunamadı.',
        UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı.',
        UPLOAD_ERR_EXTENSION => 'Yükleme bir PHP eklentisi tarafından durduruldu.',
        default => 'PDF yüklenemedi.',
    };
}

function sr_normalize_pdf_uploads(array $files): array
{
    $names = $files['name'] ?? [];
    $tmpNames = $files['tmp_name'] ?? [];
    $errors = $files['error'] ?? [];
    $sizes = $files['size'] ?? [];

    if (!is_array($names)) {
        return [[
            'name' => (string)$names,
            'tmp_name' => (string)($tmpNames ?? ''),
            'error' => (int)($errors ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes ?? 0),
        ]];
    }

    $normalized = [];
    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name' => (string)($names[$i] ?? ''),
            'tmp_name' => (string)($tmpNames[$i] ?? ''),
            'error' => (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes[$i] ?? 0),
        ];
    }
    return $normalized;
}

try {
    if ($action === 'list') {
        $filters = [
            'qualification_id' => trim((string)($_GET['qualification_id'] ?? '')),
            'course_id' => trim((string)($_GET['course_id'] ?? '')),
            'topic_id' => trim((string)($_GET['topic_id'] ?? '')),
            'premium' => (string)($_GET['premium'] ?? ''),
            'active' => (string)($_GET['active'] ?? ''),
            'search' => trim((string)($_GET['search'] ?? '')),
        ];
        $where = ['1=1'];
        $params = [];
        if ($filters['qualification_id'] !== '') { $where[] = 'p.resource_qualification_id=?'; $params[] = $filters['qualification_id']; }
        if ($filters['course_id'] !== '') { $where[] = 'p.resource_course_id=?'; $params[] = $filters['course_id']; }
        if ($filters['topic_id'] !== '') { $where[] = 'p.resource_topic_id=?'; $params[] = $filters['topic_id']; }
        if ($filters['premium'] !== '') { $where[] = 'p.is_premium=?'; $params[] = ((int)$filters['premium'] === 1 ? 1 : 0); }
        if ($filters['active'] !== '') { $where[] = 'p.is_active=?'; $params[] = ((int)$filters['active'] === 1 ? 1 : 0); }
        if ($filters['search'] !== '') { $where[] = '(p.title LIKE ? OR p.original_file_name LIKE ?)'; $like = '%' . $filters['search'] . '%'; $params[] = $like; $params[] = $like; }

        $sql = 'SELECT p.*, q.name AS qualification_name, c.name AS course_name, t.name AS topic_name
                FROM study_resource_pdfs p
                INNER JOIN study_resource_qualifications q ON q.id = p.resource_qualification_id
                INNER JOIN study_resource_courses c ON c.id = p.resource_course_id
                LEFT JOIN study_resource_topics t ON t.id = p.resource_topic_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY p.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pdfs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        sr_admin_json(true, '', [
            'scope' => [
                'qualifications' => $pdo->query('SELECT * FROM study_resource_qualifications ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'courses' => $pdo->query('SELECT * FROM study_resource_courses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [],
                'topics' => $pdo->query('SELECT * FROM study_resource_topics ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ],
            'pdfs' => $pdfs,
        ]);
    }

    if ($action === 'upload_pdfs') {
        $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
        $courseId = trim((string)($_POST['course_id'] ?? ''));
        $topicId = trim((string)($_POST['topic_id'] ?? ''));
        if ($qualificationId === '' || $courseId === '') sr_admin_json(false, 'Yeterlilik ve ders zorunludur.', [], 422);
        if (empty($_FILES['pdfs'])) sr_admin_json(false, 'PDF dosyaları zorunludur.', [], 422);

        $courseCheck = $pdo->prepare('SELECT COUNT(*) FROM study_resource_courses WHERE id=? AND resource_qualification_id=?');
        $courseCheck->execute([$courseId, $qualificationId]);
        if ((int)$courseCheck->fetchColumn() <= 0) {
            sr_admin_json(false, 'Seçilen ders, seçilen yeterliliğe ait değil.', [], 422);
        }

        if ($topicId !== '') {
            $topicCheck = $pdo->prepare('SELECT COUNT(*) FROM study_resource_topics WHERE id=? AND resource_course_id=?');
            $topicCheck->execute([$topicId, $courseId]);
            if ((int)$topicCheck->fetchColumn() <= 0) {
                sr_admin_json(false, 'Seçilen konu, seçilen derse ait değil.', [], 422);
            }
        }

        $isPremium = sr_bool($_POST['is_premium'] ?? 0);
        $isActive = sr_bool($_POST['is_active'] ?? 0);
        $uploadedBy = (string)($user['user_id'] ?? '');

        $entries = sr_normalize_pdf_uploads($_FILES['pdfs']);
        $totalCount = count($entries);
        if ($totalCount <= 0) {
            sr_admin_json(false, 'PDF dosyaları zorunludur.', [], 422);
        }
        if ($totalCount > 20) {
            sr_admin_json(false, 'Tek seferde en fazla 20 PDF yükleyebilirsin.', [
                'uploaded_count' => 0,
                'failed_count' => $totalCount,
                'uploaded' => [],
                'failed' => [],
            ], 422);
        }

        $uploaded = [];
        $failed = [];

        foreach ($entries as $entry) {
            $originalName = (string)($entry['name'] ?? 'document.pdf');
            try {
                if ((int)($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException(sr_upload_error_message((int)$entry['error']));
                }
                $meta = sr_store_pdf_upload($entry);
                $pdfId = sr_uuid();
                $title = sr_clean(pathinfo((string)$meta['original_file_name'], PATHINFO_FILENAME), 191);
                if ($title === '') $title = 'PDF Kaynak';
                $pdo->prepare('INSERT INTO study_resource_pdfs (id,resource_qualification_id,resource_course_id,resource_topic_id,title,original_file_name,stored_file_name,file_path,file_url,mime_type,file_size_bytes,page_count,is_premium,is_active,uploaded_by,open_count,download_count,created_at,updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,NOW(),NOW())')
                    ->execute([
                        $pdfId, $qualificationId, $courseId, ($topicId !== '' ? $topicId : null), $title,
                        $meta['original_file_name'], $meta['stored_file_name'], $meta['file_path'], '/api/v1/study-resources/download.php?pdf_id=' . rawurlencode($pdfId),
                        $meta['mime_type'], $meta['file_size_bytes'], $meta['page_count'], $isPremium, $isActive, $uploadedBy,
                    ]);

                $uploaded[] = [
                    'original_file_name' => (string)$meta['original_file_name'],
                    'pdf_id' => $pdfId,
                    'title' => $title,
                ];
            } catch (InvalidArgumentException $e) {
                $failed[] = [
                    'original_file_name' => $originalName,
                    'error' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                error_log('[study-resources.ajax] action=' . $action
                    . ' file_name=' . $originalName
                    . ' message=' . $e->getMessage()
                    . ' file=' . $e->getFile()
                    . ' line=' . $e->getLine());
                $failed[] = [
                    'original_file_name' => $originalName,
                    'error' => 'Sunucu hatası oluştu. Sunucu upload limiti bu dosya için düşük olabilir.',
                ];
            }
        }

        $uploadedCount = count($uploaded);
        $failedCount = count($failed);

        if ($uploadedCount <= 0) {
            sr_admin_json(false, 'Hiçbir PDF yüklenemedi.', [
                'uploaded_count' => 0,
                'failed_count' => $failedCount,
                'uploaded' => [],
                'failed' => $failed,
            ], 422);
        }

        $message = $failedCount > 0
            ? ($uploadedCount . ' PDF yüklendi, ' . $failedCount . ' PDF yüklenemedi.')
            : ($uploadedCount . ' PDF yüklendi.');

        sr_admin_json(true, $message, [
            'uploaded_count' => $uploadedCount,
            'failed_count' => $failedCount,
            'uploaded' => $uploaded,
            'failed' => $failed,
        ]);
    }

    if ($action === 'pdf_update') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') sr_admin_json(false, 'id zorunludur.', [], 422);
        $parts = [];
        $vals = [];
        if (isset($_POST['title'])) { $parts[] = 'title=?'; $vals[] = sr_clean($_POST['title'], 191); }
        if (isset($_POST['is_premium'])) { $parts[] = 'is_premium=?'; $vals[] = sr_bool($_POST['is_premium']); }
        if (isset($_POST['is_active'])) { $parts[] = 'is_active=?'; $vals[] = sr_bool($_POST['is_active']); }
        if (!$parts) sr_admin_json(false, 'Güncellenecek alan yok.', [], 422);
        $vals[] = $id;
        $pdo->prepare('UPDATE study_resource_pdfs SET ' . implode(',', $parts) . ', updated_at=NOW() WHERE id=?')->execute($vals);
        sr_admin_json(true, 'PDF güncellendi.');
    }

    if ($action === 'pdf_delete') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') sr_admin_json(false, 'id zorunludur.', [], 422);
        $s = $pdo->prepare('SELECT file_path FROM study_resource_pdfs WHERE id=? LIMIT 1');
        $s->execute([$id]);
        $path = (string)($s->fetchColumn() ?: '');
        $pdo->prepare('DELETE FROM study_resource_user_states WHERE pdf_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM study_resource_user_events WHERE pdf_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM study_resource_pdfs WHERE id=?')->execute([$id]);
        if ($path !== '') { $abs = sr_safe_abs_from_rel($path); if ($abs && is_file($abs)) @unlink($abs); }
        sr_admin_json(true, 'PDF silindi.');
    }

    sr_admin_json(false, 'Geçersiz işlem.', [], 400);
} catch (InvalidArgumentException $e) {
    sr_admin_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('[study-resources.ajax] action=' . $action
        . ' message=' . $e->getMessage()
        . ' file=' . $e->getFile()
        . ' line=' . $e->getLine());
    sr_admin_json(false, 'Sunucu hatası oluştu.', [], 500);
}

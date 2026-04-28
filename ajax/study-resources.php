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

        $isPremium = sr_bool($_POST['is_premium'] ?? 0);
        $isActive = sr_bool($_POST['is_active'] ?? 0);
        $uploadedBy = (string)($user['user_id'] ?? '');

        $names = $_FILES['pdfs']['name'] ?? [];
        $tmp = $_FILES['pdfs']['tmp_name'] ?? [];
        $errors = $_FILES['pdfs']['error'] ?? [];
        $sizes = $_FILES['pdfs']['size'] ?? [];
        $count = is_array($names) ? count($names) : 0;
        $saved = 0;

        for ($i = 0; $i < $count; $i++) {
            $meta = sr_store_pdf_upload([
                'name' => $names[$i] ?? '',
                'tmp_name' => $tmp[$i] ?? '',
                'error' => $errors[$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $sizes[$i] ?? 0,
            ]);
            $pdfId = sr_uuid();
            $title = sr_clean(pathinfo((string)$meta['original_file_name'], PATHINFO_FILENAME), 191);
            if ($title === '') $title = 'PDF Kaynak';
            $pdo->prepare('INSERT INTO study_resource_pdfs (id,resource_qualification_id,resource_course_id,resource_topic_id,title,original_file_name,stored_file_name,file_path,file_url,mime_type,file_size_bytes,page_count,is_premium,is_active,uploaded_by,open_count,download_count,created_at,updated_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,NOW(),NOW())')
                ->execute([
                    $pdfId, $qualificationId, $courseId, ($topicId !== '' ? $topicId : null), $title,
                    $meta['original_file_name'], $meta['stored_file_name'], $meta['file_path'], '/api/v1/study-resources/download.php?pdf_id=' . rawurlencode($pdfId),
                    $meta['mime_type'], $meta['file_size_bytes'], $meta['page_count'], $isPremium, $isActive, $uploadedBy,
                ]);
            $saved++;
        }
        sr_admin_json(true, $saved . ' PDF yüklendi.');
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
    error_log('[study-resources.ajax] ' . $e->getMessage());
    sr_admin_json(false, 'Sunucu hatası oluştu.', [], 500);
}

<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$questionCols = get_table_columns($pdo, 'questions');
$questionsHasTopicId = is_array($questionCols) && in_array('topic_id', $questionCols, true);

function topics_json($success, $message = '', $data = [], $status = 200)
{
    http_response_code($status);
    echo json_encode([
        'success' => (bool)$success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        case 'list_qualifications':
            $rows = $pdo->query('SELECT id, name FROM qualifications ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
            topics_json(true, '', ['qualifications' => $rows]);
            break;

        case 'list_courses':
            $qualificationId = trim((string)($_GET['qualification_id'] ?? ''));
            $sql = 'SELECT c.id, c.name, c.qualification_id, q.name AS qualification_name
                    FROM courses c
                    LEFT JOIN qualifications q ON q.id = c.qualification_id';
            $params = [];
            if ($qualificationId !== '') {
                $sql .= ' WHERE c.qualification_id = ?';
                $params[] = $qualificationId;
            }
            $sql .= ' ORDER BY q.order_index ASC, c.order_index ASC, c.name ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            topics_json(true, '', ['courses' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'list_topics':
            $qualificationId = trim((string)($_GET['qualification_id'] ?? ''));
            $courseId = trim((string)($_GET['course_id'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            $where = ['1=1'];
            $params = [];

            if ($qualificationId !== '') {
                $where[] = 'q.id = ?';
                $params[] = $qualificationId;
            }
            if ($courseId !== '') {
                $where[] = 'c.id = ?';
                $params[] = $courseId;
            }
            if ($search !== '') {
                $where[] = '(t.name LIKE ? OR t.content LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
            }

            $sql = 'SELECT t.id, t.name, t.content, t.order_index, t.course_id, t.created_at,
                           c.name AS course_name, c.qualification_id,
                           q.name AS qualification_name
                    FROM topics t
                    INNER JOIN courses c ON c.id = t.course_id
                    INNER JOIN qualifications q ON q.id = c.qualification_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY q.order_index ASC, q.name ASC, c.order_index ASC, c.name ASC, COALESCE(t.order_index, 0) ASC, t.name ASC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            topics_json(true, '', ['topics' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'get':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id === '') {
                topics_json(false, 'ID gerekli!', [], 422);
            }

            $stmt = $pdo->prepare('SELECT t.*, c.qualification_id FROM topics t INNER JOIN courses c ON c.id = t.course_id WHERE t.id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                topics_json(false, 'Konu bulunamadı!', [], 404);
            }

            topics_json(true, '', ['topic' => $row]);
            break;

        case 'add':
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
            $courseId = trim((string)($_POST['course_id'] ?? ''));
            $name = sanitize_input($_POST['name'] ?? '');
            $content = sanitize_input($_POST['content'] ?? '');
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($qualificationId === '' || $courseId === '' || $name === '') {
                topics_json(false, 'Yeterlilik, ders ve konu adı zorunludur!', [], 422);
            }

            $courseCheck = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE id = ? AND qualification_id = ?');
            $courseCheck->execute([$courseId, $qualificationId]);
            if ((int)$courseCheck->fetchColumn() < 1) {
                topics_json(false, 'Seçilen ders bu yeterliliğe ait değil!', [], 422);
            }

            $id = generate_uuid();
            $stmt = $pdo->prepare('INSERT INTO topics (id, course_id, name, content, order_index, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $ok = $stmt->execute([$id, $courseId, $name, ($content !== '' ? $content : null), $orderIndex]);

            if (!$ok) {
                topics_json(false, 'Konu eklenemedi!', [], 500);
            }

            topics_json(true, 'Konu başarıyla eklendi!', ['id' => $id]);
            break;

        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            $qualificationId = trim((string)($_POST['qualification_id'] ?? ''));
            $courseId = trim((string)($_POST['course_id'] ?? ''));
            $name = sanitize_input($_POST['name'] ?? '');
            $content = sanitize_input($_POST['content'] ?? '');
            $orderIndex = (int)($_POST['order_index'] ?? 0);

            if ($id === '' || $qualificationId === '' || $courseId === '' || $name === '') {
                topics_json(false, 'Tüm zorunlu alanları doldurun!', [], 422);
            }

            $courseCheck = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE id = ? AND qualification_id = ?');
            $courseCheck->execute([$courseId, $qualificationId]);
            if ((int)$courseCheck->fetchColumn() < 1) {
                topics_json(false, 'Seçilen ders bu yeterliliğe ait değil!', [], 422);
            }

            $stmt = $pdo->prepare('UPDATE topics SET course_id = ?, name = ?, content = ?, order_index = ? WHERE id = ?');
            $ok = $stmt->execute([$courseId, $name, ($content !== '' ? $content : null), $orderIndex, $id]);

            if (!$ok) {
                topics_json(false, 'Konu güncellenemedi!', [], 500);
            }

            topics_json(true, 'Konu güncellendi!');
            break;

        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                topics_json(false, 'ID gerekli!', [], 422);
            }

            if ($questionsHasTopicId) {
                $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE topic_id = ?');
                $usageStmt->execute([$id]);
                $usageCount = (int)$usageStmt->fetchColumn();
                if ($usageCount > 0) {
                    topics_json(false, 'Bu konuya bağlı ' . $usageCount . ' soru var. Önce sorulardaki konu seçimini kaldırın.', [], 422);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM topics WHERE id = ?');
            $ok = $stmt->execute([$id]);

            if (!$ok) {
                topics_json(false, 'Konu silinemedi!', [], 500);
            }

            topics_json(true, 'Konu silindi!');
            break;

        default:
            topics_json(false, 'Geçersiz işlem!', [], 400);
            break;
    }
} catch (Throwable $e) {
    topics_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

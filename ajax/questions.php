<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function questions_json($success, $message = '', $data = [], $status = 200)
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
    $questionCols = get_table_columns($pdo, 'questions');
    $hasOptionE = is_array($questionCols) && in_array('option_e', $questionCols, true);
    $hasTopicId = is_array($questionCols) && in_array('topic_id', $questionCols, true);
    $hasStatus = is_array($questionCols) && in_array('status', $questionCols, true);
    $hasIsActive = is_array($questionCols) && in_array('is_active', $questionCols, true);

    switch ($action) {
        case 'list_qualifications':
            $rows = $pdo->query('SELECT id, name FROM qualifications ORDER BY order_index ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
            questions_json(true, '', ['qualifications' => $rows]);
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
            questions_json(true, '', ['courses' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'list_topics':
            if (!$hasTopicId) {
                questions_json(true, '', ['topics' => [], 'meta' => ['has_topic_filter' => false]]);
            }

            $courseId = trim((string)($_GET['course_id'] ?? ''));
            $sql = 'SELECT t.id, t.course_id, t.name
                    FROM topics t';
            $params = [];
            if ($courseId !== '') {
                $sql .= ' WHERE t.course_id = ?';
                $params[] = $courseId;
            }
            $sql .= ' ORDER BY COALESCE(t.order_index, 0) ASC, t.name ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            questions_json(true, '', ['topics' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'meta' => ['has_topic_filter' => true]]);
            break;

        case 'list_questions':
            $qualificationId = trim((string)($_GET['qualification_id'] ?? ''));
            $courseId = trim((string)($_GET['course_id'] ?? ''));
            $topicId = trim((string)($_GET['topic_id'] ?? ''));
            $questionType = trim((string)($_GET['question_type'] ?? ''));
            $statusFilter = trim((string)($_GET['status'] ?? ''));
            $search = trim((string)($_GET['search'] ?? ''));

            $select = 'q.*, c.name AS course_name, qual.name AS qualification_name';
            $join = ' FROM questions q
                      LEFT JOIN courses c ON q.course_id = c.id
                      LEFT JOIN qualifications qual ON c.qualification_id = qual.id';
            if ($hasTopicId) {
                $select .= ', t.name AS topic_name';
                $join .= ' LEFT JOIN topics t ON q.topic_id = t.id';
            } else {
                $select .= ', NULL AS topic_name';
            }

            $where = ['1=1'];
            $params = [];

            if ($qualificationId !== '') {
                $where[] = 'c.qualification_id = ?';
                $params[] = $qualificationId;
            }
            if ($courseId !== '') {
                $where[] = 'q.course_id = ?';
                $params[] = $courseId;
            }
            if ($hasTopicId && $topicId !== '') {
                $where[] = 'q.topic_id = ?';
                $params[] = $topicId;
            }
            if ($questionType !== '') {
                $where[] = 'q.question_type = ?';
                $params[] = $questionType;
            }

            if ($hasStatus && $statusFilter !== '') {
                $where[] = 'q.status = ?';
                $params[] = $statusFilter;
            } elseif ($hasIsActive && $statusFilter !== '') {
                if ($statusFilter === 'active') {
                    $where[] = 'q.is_active = 1';
                } elseif ($statusFilter === 'inactive') {
                    $where[] = 'q.is_active = 0';
                }
            }

            if ($search !== '') {
                $where[] = '(q.question_text LIKE ? OR q.explanation LIKE ? OR q.option_a LIKE ? OR q.option_b LIKE ? OR q.option_c LIKE ? OR q.option_d LIKE ?' . ($hasOptionE ? ' OR q.option_e LIKE ?' : '') . ')';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                if ($hasOptionE) {
                    $params[] = $like;
                }
            }

            $countSql = 'SELECT COUNT(*)' . $join . ' WHERE ' . implode(' AND ', $where);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            $sql = 'SELECT ' . $select . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY q.created_at DESC, q.id DESC LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['formatted_explanation'] = format_explanation_text($row['explanation'] ?? '');
            }
            unset($row);

            $statusOptions = [];
            if ($hasStatus) {
                $statusRows = $pdo->query('SELECT DISTINCT status FROM questions WHERE status IS NOT NULL AND status <> "" ORDER BY status ASC')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($statusRows as $s) {
                    $statusOptions[] = ['value' => (string)$s, 'label' => (string)$s];
                }
            } elseif ($hasIsActive) {
                $statusOptions[] = ['value' => 'active', 'label' => 'Aktif'];
                $statusOptions[] = ['value' => 'inactive', 'label' => 'Pasif'];
            }

            questions_json(true, '', [
                'questions' => $rows,
                'total_count' => $totalCount,
                'meta' => [
                    'has_topic_filter' => $hasTopicId,
                    'has_status_filter' => ($hasStatus || $hasIsActive),
                    'status_options' => $statusOptions,
                ],
            ]);
            break;

        case 'add':
            $course_id = $_POST['course_id'] ?? '';
            $topic_id = normalize_optional_uuid($_POST['topic_id'] ?? null);
            $question_type = $_POST['question_type'] ?? '';
            $question_text = sanitize_input($_POST['question_text'] ?? '');
            $option_a = sanitize_input($_POST['option_a'] ?? '');
            $option_b = sanitize_input($_POST['option_b'] ?? '');
            $option_c = sanitize_input($_POST['option_c'] ?? '');
            $option_d = sanitize_input($_POST['option_d'] ?? '');
            $option_e = sanitize_input($_POST['option_e'] ?? '');
            $correct_answer = strtoupper(trim((string)($_POST['correct_answer'] ?? '')));
            $explanation = sanitize_input($_POST['explanation'] ?? '');

            if (empty($course_id) || empty($question_type) || empty($question_text) ||
                empty($option_a) || empty($option_b) || empty($option_c) ||
                empty($option_d) || empty($correct_answer)) {
                questions_json(false, 'Tüm zorunlu alanları doldurun!', [], 422);
            }

            if ($hasTopicId && !validate_topic_belongs_to_course($pdo, $topic_id, $course_id)) {
                questions_json(false, 'Seçilen konu bu derse ait değil!', [], 422);
            }

            if (!in_array($question_type, ['sayısal', 'sözel', 'karışık'], true)) {
                questions_json(false, 'Geçersiz soru tipi!', [], 422);
            }

            if (!in_array($correct_answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                questions_json(false, 'Geçersiz doğru cevap!', [], 422);
            }

            if ($correct_answer === 'E' && $option_e === '') {
                questions_json(false, 'E doğru cevap için Şık E doldurulmalıdır!', [], 422);
            }

            if (!$hasOptionE && $correct_answer === 'E') {
                questions_json(false, 'correct_answer E seçildi ancak option_e kolonu bulunamadı.', ['error_code' => 'correct_answer_e_but_option_e_not_supported'], 422);
            }

            $id = generate_uuid();

            if ($hasTopicId && $hasOptionE) {
                $stmt = $pdo->prepare(
                    'INSERT INTO questions (
                        id, course_id, topic_id, question_type, question_text,
                        option_a, option_b, option_c, option_d, option_e,
                        correct_answer, explanation, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $ok = $stmt->execute([
                    $id, $course_id, $topic_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d, ($option_e !== '' ? $option_e : null),
                    $correct_answer, $explanation,
                ]);
            } elseif ($hasTopicId) {
                $stmt = $pdo->prepare(
                    'INSERT INTO questions (
                        id, course_id, topic_id, question_type, question_text,
                        option_a, option_b, option_c, option_d,
                        correct_answer, explanation, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $ok = $stmt->execute([
                    $id, $course_id, $topic_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d,
                    $correct_answer, $explanation,
                ]);
            } elseif ($hasOptionE) {
                $stmt = $pdo->prepare(
                    'INSERT INTO questions (
                        id, course_id, question_type, question_text,
                        option_a, option_b, option_c, option_d, option_e,
                        correct_answer, explanation, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $ok = $stmt->execute([
                    $id, $course_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d, ($option_e !== '' ? $option_e : null),
                    $correct_answer, $explanation,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO questions (
                        id, course_id, question_type, question_text,
                        option_a, option_b, option_c, option_d,
                        correct_answer, explanation, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $ok = $stmt->execute([
                    $id, $course_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d,
                    $correct_answer, $explanation,
                ]);
            }

            if ($ok) {
                questions_json(true, 'Soru başarıyla eklendi!', ['id' => $id]);
            } else {
                questions_json(false, 'Veritabanı hatası!', [], 500);
            }
            break;

        case 'get':
            $id = $_GET['id'] ?? '';

            if (empty($id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID gerekli!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare('SELECT * FROM questions WHERE id = ?');
            $stmt->execute([$id]);
            $data = $stmt->fetch();

            if ($data) {
                echo json_encode([
                    'success' => true,
                    'data' => $data,
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Soru bulunamadı!',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'update':
            $id = $_POST['id'] ?? '';
            $course_id = $_POST['course_id'] ?? '';
            $topic_id = normalize_optional_uuid($_POST['topic_id'] ?? null);
            $question_type = $_POST['question_type'] ?? '';
            $question_text = sanitize_input($_POST['question_text'] ?? '');
            $option_a = sanitize_input($_POST['option_a'] ?? '');
            $option_b = sanitize_input($_POST['option_b'] ?? '');
            $option_c = sanitize_input($_POST['option_c'] ?? '');
            $option_d = sanitize_input($_POST['option_d'] ?? '');
            $option_e = sanitize_input($_POST['option_e'] ?? '');
            $correct_answer = strtoupper(trim((string)($_POST['correct_answer'] ?? '')));
            $explanation = sanitize_input($_POST['explanation'] ?? '');

            if (empty($id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID gerekli!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (empty($course_id) || empty($question_type) || empty($question_text) ||
                empty($option_a) || empty($option_b) || empty($option_c) ||
                empty($option_d) || empty($correct_answer)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tüm zorunlu alanları doldurun!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($hasTopicId && !validate_topic_belongs_to_course($pdo, $topic_id, $course_id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Seçilen konu bu derse ait değil!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($question_type, ['sayısal', 'sözel', 'karışık'], true)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Geçersiz soru tipi!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($correct_answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Geçersiz doğru cevap!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($correct_answer === 'E' && $option_e === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'E doğru cevap için Şık E doldurulmalıdır!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!$hasOptionE && $correct_answer === 'E') {
                echo json_encode([
                    'success' => false,
                    'message' => 'correct_answer E seçildi ancak option_e kolonu bulunamadı.',
                    'error_code' => 'correct_answer_e_but_option_e_not_supported',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($hasTopicId && $hasOptionE) {
                $stmt = $pdo->prepare(
                    'UPDATE questions SET
                        course_id = ?,
                        topic_id = ?,
                        question_type = ?,
                        question_text = ?,
                        option_a = ?,
                        option_b = ?,
                        option_c = ?,
                        option_d = ?,
                        option_e = ?,
                        correct_answer = ?,
                        explanation = ?
                    WHERE id = ?'
                );
                $ok = $stmt->execute([
                    $course_id, $topic_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d, ($option_e !== '' ? $option_e : null),
                    $correct_answer, $explanation, $id,
                ]);
            } elseif ($hasTopicId) {
                $stmt = $pdo->prepare(
                    'UPDATE questions SET
                        course_id = ?,
                        topic_id = ?,
                        question_type = ?,
                        question_text = ?,
                        option_a = ?,
                        option_b = ?,
                        option_c = ?,
                        option_d = ?,
                        correct_answer = ?,
                        explanation = ?
                    WHERE id = ?'
                );
                $ok = $stmt->execute([
                    $course_id, $topic_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d,
                    $correct_answer, $explanation, $id,
                ]);
            } elseif ($hasOptionE) {
                $stmt = $pdo->prepare(
                    'UPDATE questions SET
                        course_id = ?,
                        question_type = ?,
                        question_text = ?,
                        option_a = ?,
                        option_b = ?,
                        option_c = ?,
                        option_d = ?,
                        option_e = ?,
                        correct_answer = ?,
                        explanation = ?
                    WHERE id = ?'
                );
                $ok = $stmt->execute([
                    $course_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d, ($option_e !== '' ? $option_e : null),
                    $correct_answer, $explanation, $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE questions SET
                        course_id = ?,
                        question_type = ?,
                        question_text = ?,
                        option_a = ?,
                        option_b = ?,
                        option_c = ?,
                        option_d = ?,
                        correct_answer = ?,
                        explanation = ?
                    WHERE id = ?'
                );
                $ok = $stmt->execute([
                    $course_id, $question_type, $question_text,
                    $option_a, $option_b, $option_c, $option_d,
                    $correct_answer, $explanation, $id,
                ]);
            }

            if ($ok) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Soru güncellendi!',
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Güncelleme başarısız!',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'delete':
            $id = $_POST['id'] ?? '';

            if (empty($id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID gerekli!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare('DELETE FROM questions WHERE id = ?');

            if ($stmt->execute([$id])) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Soru silindi!',
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Silme başarısız!',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'bulk_delete':
            $ids = $_POST['ids'] ?? [];

            if (empty($ids) || !is_array($ids)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Silinecek soru seçilmedi!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $valid_ids = array_values(array_filter($ids, static function ($id) {
                return !empty($id) && strlen((string)$id) === 36;
            }));

            if (empty($valid_ids)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Geçersiz ID listesi!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($valid_ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ($placeholders)");

            if ($stmt->execute($valid_ids)) {
                $deleted = $stmt->rowCount();
                echo json_encode([
                    'success' => true,
                    'message' => $deleted . ' soru başarıyla silindi!',
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Silme işlemi başarısız!',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Geçersiz işlem!',
            ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'İşlem sırasında bir sunucu hatası oluştu.',
    ], JSON_UNESCAPED_UNICODE);
}

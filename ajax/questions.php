<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$user = require_admin();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add':
            $course_id = $_POST['course_id'] ?? '';
            $question_type = $_POST['question_type'] ?? '';
            $question_text = sanitize_input($_POST['question_text'] ?? '');
            $option_a = sanitize_input($_POST['option_a'] ?? '');
            $option_b = sanitize_input($_POST['option_b'] ?? '');
            $option_c = sanitize_input($_POST['option_c'] ?? '');
            $option_d = sanitize_input($_POST['option_d'] ?? '');
            $correct_answer = $_POST['correct_answer'] ?? '';
            $explanation = sanitize_input($_POST['explanation'] ?? '');

            if (empty($course_id) || empty($question_type) || empty($question_text) ||
                empty($option_a) || empty($option_b) || empty($option_c) ||
                empty($option_d) || empty($correct_answer)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tüm zorunlu alanları doldurun!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($question_type, ['sayısal', 'sözel'], true)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Geçersiz soru tipi!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (!in_array($correct_answer, ['A', 'B', 'C', 'D'], true)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Geçersiz doğru cevap!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $id = generate_uuid();

            $stmt = $pdo->prepare(
                'INSERT INTO questions (
                    id, course_id, question_type, question_text,
                    option_a, option_b, option_c, option_d,
                    correct_answer, explanation, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );

            if ($stmt->execute([
                $id, $course_id, $question_type, $question_text,
                $option_a, $option_b, $option_c, $option_d,
                $correct_answer, $explanation,
            ])) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Soru başarıyla eklendi!',
                    'data' => ['id' => $id],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Veritabanı hatası!',
                ], JSON_UNESCAPED_UNICODE);
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
            $question_type = $_POST['question_type'] ?? '';
            $question_text = sanitize_input($_POST['question_text'] ?? '');
            $option_a = sanitize_input($_POST['option_a'] ?? '');
            $option_b = sanitize_input($_POST['option_b'] ?? '');
            $option_c = sanitize_input($_POST['option_c'] ?? '');
            $option_d = sanitize_input($_POST['option_d'] ?? '');
            $correct_answer = $_POST['correct_answer'] ?? '';
            $explanation = sanitize_input($_POST['explanation'] ?? '');

            if (empty($id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID gerekli!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

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

            if ($stmt->execute([
                $course_id, $question_type, $question_text,
                $option_a, $option_b, $option_c, $option_d,
                $correct_answer, $explanation, $id,
            ])) {
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

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Geçersiz işlem!',
            ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Hata: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

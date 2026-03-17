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
            $qualification_id = $_POST['qualification_id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $order_index = (int)($_POST['order_index'] ?? 0);

            if (empty($qualification_id) || empty($name)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Yeterlilik ve ders adı zorunludur!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $id = generate_uuid();

            $stmt = $pdo->prepare(
                'INSERT INTO courses (id, qualification_id, name, description, order_index, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            );

            if ($stmt->execute([$id, $qualification_id, $name, $description, $order_index])) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Ders başarıyla eklendi!',
                    'data' => ['id' => $id],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Ekleme başarısız!',
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

            $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
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
                    'message' => 'Ders bulunamadı!',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'update':
            $id = $_POST['id'] ?? '';
            $qualification_id = $_POST['qualification_id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $order_index = (int)($_POST['order_index'] ?? 0);

            if (empty($id) || empty($qualification_id) || empty($name)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Tüm zorunlu alanlar doldurulmalı!',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare(
                'UPDATE courses
                 SET qualification_id = ?, name = ?, description = ?, order_index = ?
                 WHERE id = ?'
            );

            if ($stmt->execute([$qualification_id, $name, $description, $order_index, $id])) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Ders güncellendi!',
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

            $checkStmt = $pdo->prepare('SELECT COUNT(*) as count FROM questions WHERE course_id = ?');
            $checkStmt->execute([$id]);
            $count = (int)($checkStmt->fetch()['count'] ?? 0);

            if ($count > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Bu derse ait ' . $count . ' soru var! Önce soruları silin.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare('DELETE FROM courses WHERE id = ?');

            if ($stmt->execute([$id])) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Ders silindi!',
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

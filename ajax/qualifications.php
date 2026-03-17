<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

try {
    $user = require_admin();
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Yetki hatası: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add':
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $order_index = (int)($_POST['order_index'] ?? 0);

            if ($name === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'İsim alanı zorunludur!',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $id = generate_uuid();
            $stmt = $pdo->prepare('INSERT INTO qualifications (id, name, description, order_index, created_at) VALUES (?, ?, ?, ?, NOW())');
            $result = $stmt->execute([$id, $name, $description, $order_index]);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Yeterlilik başarıyla eklendi!',
                    'data' => ['id' => $id],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Veritabanı hatası: Ekleme başarısız',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'get':
            $id = $_GET['id'] ?? '';

            if ($id === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID parametresi gerekli!',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $stmt = $pdo->prepare('SELECT * FROM qualifications WHERE id = ? LIMIT 1');
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
                    'message' => 'Kayıt bulunamadı!',
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'update':
            $id = $_POST['id'] ?? '';
            $name = sanitize_input($_POST['name'] ?? '');
            $description = sanitize_input($_POST['description'] ?? '');
            $order_index = (int)($_POST['order_index'] ?? 0);

            if ($id === '' || $name === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID ve isim alanları zorunludur!',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $stmt = $pdo->prepare('UPDATE qualifications SET name = ?, description = ?, order_index = ? WHERE id = ?');
            $result = $stmt->execute([$name, $description, $order_index, $id]);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Yeterlilik başarıyla güncellendi!',
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

            if ($id === '') {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID parametresi gerekli!',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $checkStmt = $pdo->prepare('SELECT COUNT(*) as count FROM courses WHERE qualification_id = ?');
            $checkStmt->execute([$id]);
            $count = (int)($checkStmt->fetch()['count'] ?? 0);

            if ($count > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Bu yeterliğe ait ' . $count . ' ders var! Önce dersleri silin.',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $stmt = $pdo->prepare('DELETE FROM qualifications WHERE id = ?');
            $result = $stmt->execute([$id]);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Yeterlilik başarıyla silindi!',
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
                'message' => 'Geçersiz işlem! Action: ' . $action,
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Hata: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

ob_end_flush();

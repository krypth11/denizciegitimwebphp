<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_admin();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'add':
        $name = sanitize_input($_POST['name'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $order_index = (int)($_POST['order_index'] ?? 0);

        if ($name === '') {
            error_response('İsim gerekli!');
        }

        $id = generate_uuid();
        $stmt = $pdo->prepare('INSERT INTO qualifications (id, name, description, order_index, created_at) VALUES (?, ?, ?, ?, NOW())');

        if ($stmt->execute([$id, $name, $description, $order_index])) {
            success_response('Yeterlilik eklendi!', ['id' => $id]);
        }
        error_response('Ekleme hatası!');
        break;

    case 'get':
        $id = sanitize_input($_GET['id'] ?? '');
        if ($id === '') {
            error_response('ID gerekli!');
        }

        $stmt = $pdo->prepare('SELECT * FROM qualifications WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $data = $stmt->fetch();

        if ($data) {
            success_response('Kayıt bulundu', $data);
        }
        error_response('Bulunamadı!', 404);
        break;

    case 'update':
        $id = sanitize_input($_POST['id'] ?? '');
        $name = sanitize_input($_POST['name'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $order_index = (int)($_POST['order_index'] ?? 0);

        if ($id === '' || $name === '') {
            error_response('ID ve isim gerekli!');
        }

        $stmt = $pdo->prepare('UPDATE qualifications SET name = ?, description = ?, order_index = ? WHERE id = ?');
        if ($stmt->execute([$name, $description, $order_index, $id])) {
            success_response('Güncellendi!');
        }
        error_response('Güncelleme hatası!');
        break;

    case 'delete':
        $id = sanitize_input($_POST['id'] ?? '');
        if ($id === '') {
            error_response('ID gerekli!');
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE qualification_id = ?');
        $countStmt->execute([$id]);
        if ((int)$countStmt->fetchColumn() > 0) {
            error_response('Bu yeterliliğe ait dersler var! Önce dersleri silin.');
        }

        $stmt = $pdo->prepare('DELETE FROM qualifications WHERE id = ?');
        if ($stmt->execute([$id])) {
            success_response('Silindi!');
        }
        error_response('Silme hatası!');
        break;

    default:
        error_response('Geçersiz işlem!', 400);
}

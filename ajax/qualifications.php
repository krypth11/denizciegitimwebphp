<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/community_helper.php';
require_once '../api/v1/mock_exam_helper.php';

try {
    $user = require_admin();
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Bu işlem için yetkiniz bulunmuyor.',
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
                try {
                    mock_exam_ensure_qualification_exam_settings($pdo, $id);
                } catch (Throwable $ensureError) {
                    error_log('Qualification exam settings ensure add failed: ' . $ensureError->getMessage());
                }

                try {
                    community_sync_qualification_room($pdo, $id, $name, true);
                } catch (Throwable $syncError) {
                    error_log('Qualification room sync add failed: ' . $syncError->getMessage());
                }

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
                try {
                    mock_exam_ensure_qualification_exam_settings($pdo, $id);
                } catch (Throwable $ensureError) {
                    error_log('Qualification exam settings ensure update failed: ' . $ensureError->getMessage());
                }

                try {
                    community_sync_qualification_room($pdo, $id, $name, true);
                } catch (Throwable $syncError) {
                    error_log('Qualification room sync update failed: ' . $syncError->getMessage());
                }

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

            $existingNameStmt = $pdo->prepare('SELECT name FROM qualifications WHERE id = ? LIMIT 1');
            $existingNameStmt->execute([$id]);
            $existingQualificationName = (string)($existingNameStmt->fetchColumn() ?: 'Yeterlilik Odası');

            if ($count > 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Bu yeterliğe ait ' . $count . ' ders var! Önce dersleri silin.',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            try {
                $cleanupStmt = $pdo->prepare('DELETE FROM qualification_exam_settings WHERE qualification_id = ?');
                $cleanupStmt->execute([$id]);
            } catch (Throwable $cleanupError) {
                error_log('Qualification exam settings cleanup delete failed: ' . $cleanupError->getMessage());
            }

            $stmt = $pdo->prepare('DELETE FROM qualifications WHERE id = ?');
            $result = $stmt->execute([$id]);

            if ($result) {
                try {
                    community_sync_qualification_room($pdo, $id, $existingQualificationName, false);
                } catch (Throwable $syncError) {
                    error_log('Qualification room sync delete failed: ' . $syncError->getMessage());
                }

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
        'message' => 'İşlem sırasında bir sunucu hatası oluştu.',
    ], JSON_UNESCAPED_UNICODE);
}

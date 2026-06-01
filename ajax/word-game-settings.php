<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/word_game_question_helper.php';
try { require_admin(); } catch (Throwable $e) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE); exit; }
function wg_set_json(bool $success, string $message = '', array $data = [], int $status = 200): void { http_response_code($status); echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
try {
    if ($action === 'get') {
        $settings = word_game_get_settings($pdo);
        $counts = [];
        $stmt = $pdo->query('SELECT answer_length, COUNT(*) AS c FROM word_game_questions WHERE answer_length BETWEEN 1 AND 64 GROUP BY answer_length');
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) { $counts[(int)$r['answer_length']] = (int)$r['c']; }
        wg_set_json(true, '', ['settings'=>$settings,'length_counts'=>$counts]);
    }
    if ($action === 'save') {
        $res = word_game_settings_update($pdo, [
            'target_score' => $_POST['target_score'] ?? 0,
            'points_per_char' => $_POST['points_per_char'] ?? 0,
            'min_questions' => $_POST['min_questions'] ?? 0,
            'max_questions' => $_POST['max_questions'] ?? 0,
            'duration_seconds' => $_POST['duration_seconds'] ?? 0,
            'allowed_lengths' => $_POST['allowed_lengths'] ?? [],
        ]);
        if (!($res['success'] ?? false)) {
            wg_set_json(false, $res['message'] ?? 'Kayıt başarısız.', ['errors'=>$res['errors'] ?? []], 422);
        }
        wg_set_json(true, 'Ayarlar kaydedildi.', ['settings'=>$res['settings'] ?? word_game_get_settings($pdo)]);
    }
    wg_set_json(false, 'Geçersiz işlem.', [], 400);
} catch (Throwable $e) {
    wg_set_json(false, 'İşlem sırasında bir sunucu hatası oluştu.', [], 500);
}

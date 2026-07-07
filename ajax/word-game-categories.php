<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/word_game_question_helper.php';

try { require_admin(); } catch (Throwable $e) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Bu işlem için yetkiniz yok.'], JSON_UNESCAPED_UNICODE); exit; }
function wg_cat_json(bool $success, string $message = '', array $data = [], int $status = 200): void { http_response_code($status); echo json_encode(['success'=>$success,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE); exit; }

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
try {
    switch ($action) {
        case 'list':
            wg_cat_json(true, '', ['items'=>word_game_list_categories($pdo, ['search'=>$_GET['search'] ?? '', 'is_active'=>$_GET['is_active'] ?? ''])]);
            break;
        case 'get':
            $id = trim((string)($_GET['id'] ?? ''));
            if ($id==='') wg_cat_json(false,'ID zorunludur.',[],422);
            $item = word_game_get_category($pdo, $id);
            if(!$item) wg_cat_json(false,'Kayıt bulunamadı.',[],404);
            wg_cat_json(true,'',['item'=>$item]);
            break;
        case 'create':
            $res = word_game_create_category($pdo, $_POST);
            if(!($res['success']??false)) wg_cat_json(false,$res['message']??'Kayıt başarısız.',['errors'=>$res['errors']??[]],422);
            wg_cat_json(true,'Başlık eklendi.',['item'=>$res['item']??null]);
            break;
        case 'update':
            $id = trim((string)($_POST['id'] ?? ''));
            if($id==='') wg_cat_json(false,'ID zorunludur.',[],422);
            $res = word_game_update_category($pdo,$id,$_POST);
            if(!($res['success']??false)) wg_cat_json(false,$res['message']??'Güncelleme başarısız.',['errors'=>$res['errors']??[]],422);
            wg_cat_json(true,'Başlık güncellendi.',['item'=>$res['item']??null]);
            break;
        case 'delete':
            $id = trim((string)($_POST['id'] ?? ''));
            if($id==='') wg_cat_json(false,'ID zorunludur.',[],422);
            if(!word_game_get_category($pdo,$id)) wg_cat_json(false,'Kayıt bulunamadı.',[],404);
            $res = word_game_delete_category($pdo,$id);
            if(!($res['success']??false)) {
                if (($res['code'] ?? '') === 'category_has_questions') {
                    wg_cat_json(false,'Bu başlık silinemez. Önce bağlı soruları başka başlığa taşıyın veya başlığı pasife alın.',[
                        'code'=>'category_has_questions',
                        'question_count'=>(int)($res['question_count']??0),
                        'active_question_count'=>(int)($res['active_question_count']??0),
                        'session_snapshot_count'=>(int)($res['session_snapshot_count']??0),
                    ],409);
                }
                wg_cat_json(false,'Silme başarısız.',[],500);
            }
            wg_cat_json(true,'Başlık silindi.');
            break;
        case 'toggle_active':
            $id = trim((string)($_POST['id'] ?? ''));
            $active = $_POST['is_active'] ?? null;
            if($id===''||$active===null) wg_cat_json(false,'ID ve durum zorunludur.',[],422);
            $item = word_game_get_category($pdo,$id);
            if(!$item) wg_cat_json(false,'Kayıt bulunamadı.',[],404);
            $stmt=$pdo->prepare('UPDATE word_game_categories SET is_active=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([in_array((string)$active,['1','true','on'],true)?1:0,$id]);
            wg_cat_json(true,'Durum güncellendi.');
            break;
        default: wg_cat_json(false,'Geçersiz işlem.',[],400);
    }
} catch (Throwable $e) { wg_cat_json(false,'İşlem sırasında bir sunucu hatası oluştu.',[],500); }

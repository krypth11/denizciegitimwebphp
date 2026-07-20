<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
require_once __DIR__ . '/maritime_english_learning_helper.php';
api_require_method('POST');
try {
    $auth=api_require_auth($pdo); $userId=(string)($auth['user']['id']??''); $payload=api_get_request_data();
    $sessionId=trim((string)($payload['session_id']??'')); $reason=trim((string)($payload['reason']??'user_finish'));
    if($sessionId==='') api_error('session_id zorunludur.',422);
    $pdo->beginTransaction(); $session=me_load_session($pdo,$sessionId,$userId,true);
    if(!$session) throw new RuntimeException('Oturum bulunamadı.',404);
    if((string)$session['status']==='active'){
        $status=$reason==='completed'?'completed':'abandoned';
        $pdo->prepare('UPDATE maritime_english_sessions SET status=?, completed_at=NOW(), updated_at=NOW() WHERE id=? AND user_id=? AND status=?')->execute([$status,$sessionId,$userId,'active']);
    }
    $result=me_result_payload($pdo,$sessionId,$userId); $pdo->commit();
    api_send_json(['success'=>true,'data'=>['result'=>$result]]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$s=(int)$e->getCode();api_error($s>=400&&$s<500?$e->getMessage():'Oturum sonlandırılamadı.',$s>=400&&$s<500?$s:500);}

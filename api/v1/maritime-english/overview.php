<?php
require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/response_helper.php';
api_require_method('GET');
try {
    $auth = api_require_auth($pdo); $userId = (string)($auth['user']['id'] ?? '');
    $summaryStmt = $pdo->prepare("SELECT COUNT(*) AS session_count, COALESCE(SUM(answered_count),0) AS answered_count, COALESCE(SUM(correct_count),0) AS correct_count, COALESCE(SUM(wrong_count),0) AS wrong_count, COALESCE(SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, updated_at))),0) AS duration_seconds FROM maritime_english_sessions WHERE user_id = ? AND status IN ('completed','abandoned')");
    $summaryStmt->execute([$userId]); $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $termStmt = $pdo->prepare("SELECT COUNT(*) AS seen_count, SUM(learning_state='mastered') AS mastered_count, SUM(learning_state IN ('learning','review','relearning')) AS learning_count, SUM(next_review_at <= NOW()) AS due_count FROM maritime_english_user_terms WHERE user_id = ?");
    $termStmt->execute([$userId]); $terms = $termStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $categoryStmt = $pdo->prepare("SELECT c.id, c.name, COUNT(DISTINCT ut.term_id) AS seen_count, SUM(ut.correct_count) AS correct_count, SUM(ut.wrong_count) AS wrong_count, SUM(ut.learning_state='mastered') AS mastered_count FROM maritime_english_user_terms ut INNER JOIN maritime_english_terms t ON t.id=ut.term_id INNER JOIN maritime_english_categories c ON c.id=t.category_id WHERE ut.user_id=? GROUP BY c.id,c.name,c.sort_order ORDER BY c.sort_order");
    $categoryStmt->execute([$userId]);
    $recentStmt = $pdo->prepare("SELECT id,status,question_count,answered_count,correct_count,wrong_count,started_at,completed_at,TIMESTAMPDIFF(SECOND,started_at,COALESCE(completed_at,updated_at)) AS duration_seconds FROM maritime_english_sessions WHERE user_id=? AND status IN ('completed','abandoned') ORDER BY started_at DESC LIMIT 10");
    $recentStmt->execute([$userId]);
    $answered = (int)($summary['answered_count'] ?? 0); $correct = (int)($summary['correct_count'] ?? 0);
    api_send_json(['success'=>true,'data'=>[
        'summary'=>['session_count'=>(int)($summary['session_count']??0),'answered_count'=>$answered,'correct_count'=>$correct,'wrong_count'=>(int)($summary['wrong_count']??0),'accuracy'=>$answered>0?round($correct/$answered*100,1):0,'duration_seconds'=>(int)($summary['duration_seconds']??0),'seen_count'=>(int)($terms['seen_count']??0),'mastered_count'=>(int)($terms['mastered_count']??0),'learning_count'=>(int)($terms['learning_count']??0),'due_count'=>(int)($terms['due_count']??0)],
        'categories'=>$categoryStmt->fetchAll(PDO::FETCH_ASSOC)?:[], 'recent_sessions'=>$recentStmt->fetchAll(PDO::FETCH_ASSOC)?:[],
    ]]);
} catch(Throwable $e){ api_error('Maritime English istatistikleri alınamadı.',500); }

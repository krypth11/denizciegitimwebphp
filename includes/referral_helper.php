<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../api/v1/usage_limits_helper.php';

if (!defined('REFERRAL_CODE_ALPHABET')) define('REFERRAL_CODE_ALPHABET', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');

function referral_table_exists(PDO $pdo, string $table): bool { try { return !empty(get_table_columns($pdo, $table)); } catch (Throwable $e) { return false; } }
function referral_now(): string { return date('Y-m-d H:i:s'); }
function referral_add_days(string $start, int $days): string { return (new DateTimeImmutable($start ?: 'now'))->modify('+' . max(0, $days) . ' days')->format('Y-m-d H:i:s'); }
function referral_json($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'; }

function referral_generate_code(PDO $pdo, int $length = 8): string
{
    $length = max(7, min(8, $length)); $alphabet = REFERRAL_CODE_ALPHABET; $max = strlen($alphabet) - 1;
    for ($a = 0; $a < 50; $a++) {
        $code = ''; for ($i = 0; $i < $length; $i++) $code .= $alphabet[random_int(0, $max)];
        $st = $pdo->prepare('SELECT 1 FROM user_referral_codes WHERE referral_code = ? LIMIT 1'); $st->execute([$code]);
        if (!$st->fetchColumn()) return $code;
    }
    throw new RuntimeException('Unique referans kodu üretilemedi.');
}

function referral_get_or_create_code(PDO $pdo, string $userId): array
{
    $st = $pdo->prepare('SELECT * FROM user_referral_codes WHERE user_id = ? AND is_active = 1 LIMIT 1'); $st->execute([$userId]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $code = (string)($r['referral_code'] ?? $r['code'] ?? '');
        $r['referral_code'] = $code;
        $r['code'] = $code;
        return $r;
    }
    $code = referral_generate_code($pdo, random_int(7, 8));
    $pdo->prepare('INSERT INTO user_referral_codes (user_id, referral_code, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')->execute([$userId,$code]);
    return ['user_id'=>$userId,'referral_code'=>$code,'code'=>$code];
}

function referral_get_global_settings(PDO $pdo): array
{
    $d = ['id'=>null,'max_bonus_percent'=>50,'auto_approve_enabled'=>1,'reverse_on_refund'=>1,'suspicious_same_ip_enabled'=>1,'suspicious_same_device_enabled'=>1,'manual_approval_for_suspicious'=>1,'default_waiting_days'=>7,'invite_base_url'=>''];
    $d['reverse_on_refund_enabled'] = $d['reverse_on_refund'];
    $d['same_ip_suspicious_enabled'] = $d['suspicious_same_ip_enabled'];
    $d['same_device_suspicious_enabled'] = $d['suspicious_same_device_enabled'];
    if (!referral_table_exists($pdo,'referral_global_settings')) return $d;
    $r = $pdo->query('SELECT * FROM referral_global_settings ORDER BY created_at ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $m = array_merge($d,$r);
    $m['reverse_on_refund_enabled'] = (int)($m['reverse_on_refund'] ?? 1);
    $m['same_ip_suspicious_enabled'] = (int)($m['suspicious_same_ip_enabled'] ?? 1);
    $m['same_device_suspicious_enabled'] = (int)($m['suspicious_same_device_enabled'] ?? 1);
    return $m;
}

function referral_save_global_settings(PDO $pdo, array $p): array
{
    $s = referral_get_global_settings($pdo); $id = (string)($s['id'] ?? '');
    $vals = [max(0,(int)($p['max_bonus_percent'] ?? $s['max_bonus_percent'])), !empty($p['auto_approve_enabled'])?1:0, !empty($p['reverse_on_refund'] ?? $p['reverse_on_refund_enabled'] ?? null)?1:0, !empty($p['suspicious_same_ip_enabled'] ?? $p['same_ip_suspicious_enabled'] ?? null)?1:0, !empty($p['suspicious_same_device_enabled'] ?? $p['same_device_suspicious_enabled'] ?? null)?1:0, !empty($p['manual_approval_for_suspicious'] ?? $s['manual_approval_for_suspicious'] ?? 1)?1:0];
    if ($id==='') { $id=generate_uuid(); $pdo->prepare('INSERT INTO referral_global_settings (id,max_bonus_percent,auto_approve_enabled,reverse_on_refund,suspicious_same_ip_enabled,suspicious_same_device_enabled,manual_approval_for_suspicious,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())')->execute(array_merge([$id],$vals)); }
    else { $pdo->prepare('UPDATE referral_global_settings SET max_bonus_percent=?,auto_approve_enabled=?,reverse_on_refund=?,suspicious_same_ip_enabled=?,suspicious_same_device_enabled=?,manual_approval_for_suspicious=?,updated_at=NOW() WHERE id=?')->execute(array_merge($vals,[$id])); }
    return referral_get_global_settings($pdo);
}

function referral_detect_plan_code(?string $productId): string
{
    $p = strtolower(trim((string)$productId));
    if (preg_match('/annual|year|yearly|yillik|12\s*ay|12m|12month/', $p)) return 'annual';
    if (preg_match('/semi|6\s*ay|6m|6month/', $p)) return 'semiannual';
    if (preg_match('/quarter|3\s*ay|3m|3month/', $p)) return 'quarterly';
    return 'monthly';
}
function referral_plan_base_days(string $planCode): int { return ['annual'=>365,'semiannual'=>180,'quarterly'=>90][$planCode] ?? 30; }

function referral_get_reward_rule(PDO $pdo, ?string $planCode=null, ?string $productId=null): array
{
    $planCode = $planCode ?: referral_detect_plan_code($productId); $g = referral_get_global_settings($pdo);
    $d = ['id'=>null,'plan_code'=>$planCode,'product_id'=>$productId,'referrer_reward_days'=>7,'referred_reward_days'=>7,'referrer_bonus_percent_delta'=>5,'hold_days'=>(int)$g['default_waiting_days'],'waiting_days'=>(int)$g['default_waiting_days'],'is_active'=>1];
    if (!referral_table_exists($pdo,'referral_reward_rules')) return $d;
    $found = [];
    if ($productId) { $st=$pdo->prepare('SELECT * FROM referral_reward_rules WHERE is_active=1 AND product_id=? ORDER BY updated_at DESC LIMIT 1'); $st->execute([$productId]); $found=$st->fetch(PDO::FETCH_ASSOC) ?: []; }
    if (!$found) { $st=$pdo->prepare('SELECT * FROM referral_reward_rules WHERE is_active=1 AND plan_code=? ORDER BY updated_at DESC LIMIT 1'); $st->execute([$planCode]); $found=$st->fetch(PDO::FETCH_ASSOC) ?: []; }
    $row = array_merge($d,$found);
    $holdDays = (int)($row['hold_days'] ?? $row['waiting_days'] ?? 7);
    $row['hold_days'] = $holdDays;
    $row['waiting_days'] = $holdDays;
    return $row;
}

function referral_get_user_bonus_percent(PDO $pdo, string $userId): int
{
    $max = max(0,(int)(referral_get_global_settings($pdo)['max_bonus_percent'] ?? 50));
    $st=$pdo->prepare("SELECT COALESCE(SUM(percent_delta),0) FROM user_referral_bonus_ledger WHERE user_id=? AND status='active'"); $st->execute([$userId]);
    return min($max,max(0,(int)$st->fetchColumn()));
}

function referral_hash_ip(?string $ip): ?string { $ip=trim((string)$ip); return $ip===''?null:hash_hmac('sha256',$ip,defined('APP_KEY')?(string)APP_KEY:__DIR__); }
function referral_user_has_purchase(PDO $pdo,string $userId): bool { try { $s=usage_limits_get_user_subscription_status($pdo,$userId); return !empty($s['exists']) && (!empty($s['is_pro']) || !empty($s['expires_at']) || !empty($s['plan_code'])); } catch(Throwable $e){ return false; } }

function referral_apply_code_to_user(PDO $pdo, string $userId, string $referralCode, ?string $deviceHash=null, ?string $ipAddress=null): array
{
    $code=strtoupper(trim($referralCode)); if($code==='') throw new InvalidArgumentException('Referans kodu zorunludur.',422);
    $st=$pdo->prepare('SELECT * FROM user_referral_codes WHERE referral_code = ? AND is_active = 1 LIMIT 1'); $st->execute([$code]); $c=$st->fetch(PDO::FETCH_ASSOC);
    if(!$c) throw new InvalidArgumentException('Geçersiz referans kodu.',422); $ref=(string)$c['user_id'];
    if($ref===$userId) throw new InvalidArgumentException('Kendi referans kodunuzu kullanamazsınız.',422);
    $st=$pdo->prepare('SELECT 1 FROM user_referral_links WHERE referred_user_id=? LIMIT 1'); $st->execute([$userId]); if($st->fetchColumn()) throw new InvalidArgumentException('Bu hesaba daha önce referans kodu uygulanmış.',409);
    if(referral_user_has_purchase($pdo,$userId)) throw new InvalidArgumentException('Premium satın alımı olan kullanıcı referans kodu uygulayamaz.',422);
    $g=referral_get_global_settings($pdo); $ipHash=referral_hash_ip($ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null)); $deviceHash=trim((string)$deviceHash) ?: null; $flags=[];
    if(!empty($g['same_ip_suspicious_enabled']) && $ipHash){$q=$pdo->prepare('SELECT 1 FROM user_referral_links WHERE referrer_user_id=? AND ip_hash=? LIMIT 1');$q->execute([$ref,$ipHash]);if($q->fetchColumn())$flags[]='same_ip';}
    if(!empty($g['same_device_suspicious_enabled']) && $deviceHash){$q=$pdo->prepare('SELECT 1 FROM user_referral_links WHERE referrer_user_id=? AND device_hash=? LIMIT 1');$q->execute([$ref,$deviceHash]);if($q->fetchColumn())$flags[]='same_device';}
    $id=generate_uuid();
    $status = !empty($flags) ? 'suspicious' : 'active';
    $pdo->prepare('INSERT INTO user_referral_links (id, referrer_user_id, referred_user_id, referral_code, source, device_hash, ip_hash, status, fraud_flags_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([$id,$ref,$userId,$code,'api',$deviceHash,$ipHash,$status,referral_json($flags)]);
    return ['id'=>$id,'referrer_user_id'=>$ref,'referred_user_id'=>$userId,'referral_code'=>$code,'status'=>$status,'is_suspicious'=>!empty($flags),'fraud_flags'=>$flags];
}

function referral_create_reward_event(PDO $pdo,array $p): string
{
    $id=generate_uuid();
    $pdo->prepare('INSERT INTO referral_reward_events (id,event_kind,status,referrer_user_id,referred_user_id,purchase_user_id,referral_link_id,revenuecat_webhook_event_id,source_event_id,revenuecat_transaction_id,product_id,plan_code,store,event_type,referrer_reward_days,referred_reward_days,buyer_bonus_days,referrer_bonus_percent_delta,buyer_bonus_percent_snapshot,hold_days,purchased_at,eligible_at,fraud_flags_json,admin_note,raw_event_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())')->execute([$id,$p['event_kind'],$p['status']??'pending',$p['referrer_user_id']??null,$p['referred_user_id']??null,$p['purchase_user_id']??null,$p['referral_link_id']??null,$p['revenuecat_webhook_event_id']??null,$p['source_event_id']??$p['revenuecat_event_id']??null,$p['revenuecat_transaction_id']??null,$p['product_id']??null,$p['plan_code']??null,$p['store']??null,$p['event_type']??null,(int)($p['referrer_reward_days']??0),(int)($p['referred_reward_days']??0),(int)($p['buyer_bonus_days']??0),(int)($p['referrer_bonus_percent_delta']??0),(int)($p['buyer_bonus_percent_snapshot']??0),(int)($p['hold_days']??$p['waiting_days']??0),$p['purchased_at']??null,$p['eligible_at']??referral_now(),referral_json($p['fraud_flags']??[]),$p['admin_note']??null,referral_json($p['raw_event']??$p['meta']??[])]);
    return $id;
}

function referral_handle_revenuecat_event(PDO $pdo,string $matchedUserId,?string $eventRowId,string $eventId,array $event,string $eventType): array
{
    $eventType=strtoupper($eventType); if(!in_array($eventType,['INITIAL_PURCHASE','RENEWAL','PRODUCT_CHANGE','UNCANCELLATION','REFUND'],true)) return ['handled'=>false];
    $productId=trim((string)($event['product_id']??$event['product_identifier']??'')) ?: null; $plan=referral_detect_plan_code($productId); $rule=referral_get_reward_rule($pdo,$plan,$productId); $tx=trim((string)($event['transaction_id']??$event['original_transaction_id']??'')) ?: null;
    if($eventType==='REFUND'){ $n=!empty(referral_get_global_settings($pdo)['reverse_on_refund'])?referral_reverse_reward_event($pdo,'','RevenueCat REFUND: '.$eventId,['purchase_user_id'=>$matchedUserId,'source_event_id'=>$eventId,'revenuecat_transaction_id'=>$tx]):0; return ['handled'=>true,'reversed_count'=>$n]; }
    if($eventType==='INITIAL_PURCHASE'){
        $st=$pdo->prepare("SELECT * FROM user_referral_links WHERE referred_user_id=? AND status IN ('active','suspicious') LIMIT 1");$st->execute([$matchedUserId]);$l=$st->fetch(PDO::FETCH_ASSOC); if(!$l)return ['handled'=>true,'created'=>false];
        $st=$pdo->prepare("SELECT 1 FROM referral_reward_events WHERE event_kind='first_purchase_referral' AND referred_user_id=? LIMIT 1");$st->execute([$matchedUserId]); if($st->fetchColumn())return ['handled'=>true,'created'=>false,'reason'=>'already_exists'];
        $holdDays=(int)($rule['hold_days']??$rule['waiting_days']??7); $isSuspicious=(($l['status']??'')==='suspicious');
        $id=referral_create_reward_event($pdo,['event_kind'=>'first_purchase_referral','status'=>$isSuspicious?'suspicious':'pending','referrer_user_id'=>$l['referrer_user_id'],'referred_user_id'=>$matchedUserId,'purchase_user_id'=>$matchedUserId,'referral_link_id'=>$l['id']??null,'revenuecat_webhook_event_id'=>$eventRowId,'source_event_id'=>$eventId,'revenuecat_transaction_id'=>$tx,'product_id'=>$productId,'plan_code'=>$rule['plan_code']??$plan,'store'=>$event['store']??null,'event_type'=>$eventType,'referrer_reward_days'=>$rule['referrer_reward_days'],'referred_reward_days'=>$rule['referred_reward_days'],'referrer_bonus_percent_delta'=>$rule['referrer_bonus_percent_delta'],'hold_days'=>$holdDays,'eligible_at'=>referral_add_days(referral_now(),$holdDays),'fraud_flags'=>json_decode((string)($l['fraud_flags_json']??'[]'),true)?:[],'raw_event'=>$event]); return ['handled'=>true,'created'=>true,'event_id'=>$id];
    }
    $pct=referral_get_user_bonus_percent($pdo,$matchedUserId); if($pct<=0)return ['handled'=>true,'created'=>false,'reason'=>'no_bonus_percent'];
    $days=max(1,(int)floor(referral_plan_base_days($plan)*$pct/100));
    $holdDays=(int)($rule['hold_days']??$rule['waiting_days']??7);
    $id=referral_create_reward_event($pdo,['event_kind'=>'purchase_extra_time_bonus','purchase_user_id'=>$matchedUserId,'revenuecat_webhook_event_id'=>$eventRowId,'source_event_id'=>$eventId,'revenuecat_transaction_id'=>$tx,'product_id'=>$productId,'plan_code'=>$plan,'store'=>$event['store']??null,'event_type'=>$eventType,'buyer_bonus_days'=>$days,'buyer_bonus_percent_snapshot'=>$pct,'hold_days'=>$holdDays,'eligible_at'=>referral_add_days(referral_now(),$holdDays),'raw_event'=>$event]);
    return ['handled'=>true,'created'=>true,'event_id'=>$id,'buyer_bonus_days'=>$days];
}

function referral_effective_until(PDO $pdo,string $userId): ?string { $until=null; try{$s=usage_limits_get_user_subscription_status($pdo,$userId); if(usage_limits_is_subscription_active($s))$until=usage_limits_normalize_datetime_to_mysql($s['expires_at']??null);}catch(Throwable $e){} $b=function_exists('usage_limits_get_active_premium_bonus_until')?usage_limits_get_active_premium_bonus_until($pdo,$userId):null; return ($b && (!$until || strtotime($b)>strtotime($until)))?$b:$until; }
function referral_add_premium_grant(PDO $pdo,string $userId,int $days,string $grantKind,string $eventId): ?string { if($days<=0)return null; $map=['first_purchase_referrer'=>'referrer_reward','first_purchase_referred'=>'referred_reward','referrer_reward'=>'referrer_reward','referred_reward'=>'referred_reward','purchase_extra_time_bonus'=>'purchase_extra_time_bonus','manual'=>'manual']; $grantKind=$map[$grantKind]??'manual'; $start=referral_effective_until($pdo,$userId); $start=($start && strtotime($start)>time())?$start:referral_now(); $exp=referral_add_days($start,$days); $id=generate_uuid(); $pdo->prepare("INSERT INTO user_premium_bonus_grants (id,user_id,reward_event_id,grant_kind,source,days,starts_at,expires_at,status,created_at,admin_note) VALUES (?,?,?,?,?,?,?,?,'active',NOW(),?)")->execute([$id,$userId,$eventId,$grantKind,'referral',$days,$start,$exp,'Referral reward']); return $id; }

function referral_approve_reward_event(PDO $pdo,string $eventId,?string $adminUserId=null,string $note=''): array
{
    $st=$pdo->prepare('SELECT * FROM referral_reward_events WHERE id=? LIMIT 1');$st->execute([$eventId]);$e=$st->fetch(PDO::FETCH_ASSOC); if(!$e)throw new RuntimeException('Ödül eventi bulunamadı.'); if(($e['status']??'')==='approved')return ['approved'=>false];
    $gr=[];$ledger=null;
    if($e['event_kind']==='first_purchase_referral'){
        if($e['referrer_user_id']){$gr[]=referral_add_premium_grant($pdo,$e['referrer_user_id'],(int)$e['referrer_reward_days'],'referrer_reward',$eventId); $delta=(int)$e['referrer_bonus_percent_delta']; $cur=referral_get_user_bonus_percent($pdo,$e['referrer_user_id']); $max=(int)referral_get_global_settings($pdo)['max_bonus_percent']; $delta=max(0,min($delta,$max-$cur)); $totalAfter=$cur+$delta; if($delta>0){$ledger=generate_uuid();$pdo->prepare("INSERT INTO user_referral_bonus_ledger (id,user_id,reward_event_id,percent_delta,total_after,status,created_at,admin_note) VALUES (?,?,?,?,?,'active',NOW(),?)")->execute([$ledger,$e['referrer_user_id'],$eventId,$delta,$totalAfter,'Referans bonus yüzdesi']);}}
        if($e['referred_user_id'])$gr[]=referral_add_premium_grant($pdo,$e['referred_user_id'],(int)$e['referred_reward_days'],'first_purchase_referred',$eventId);
    } elseif($e['event_kind']==='purchase_extra_time_bonus' && $e['purchase_user_id']) $gr[]=referral_add_premium_grant($pdo,$e['purchase_user_id'],(int)$e['buyer_bonus_days'],'purchase_extra_time_bonus',$eventId);
    $pdo->prepare("UPDATE referral_reward_events SET status='approved',approved_at=NOW(),admin_note=?,updated_at=NOW() WHERE id=?")->execute([$note,$eventId]);
    return ['approved'=>true,'grants'=>array_values(array_filter($gr)),'ledger_id'=>$ledger];
}
function referral_reject_reward_event(PDO $pdo,string $eventId,?string $adminUserId=null,string $note=''): array { $st=$pdo->prepare("UPDATE referral_reward_events SET status='rejected',rejected_at=NOW(),admin_note=?,updated_at=NOW() WHERE id=? AND status IN ('pending','suspicious')");$st->execute([$note,$eventId]); return ['rejected'=>$st->rowCount()>0]; }
function referral_reverse_reward_event(PDO $pdo,string $eventId='',string $note='',array $criteria=[]): int { $rows=[]; if($eventId!==''){$st=$pdo->prepare('SELECT * FROM referral_reward_events WHERE id=?');$st->execute([$eventId]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];} else {$where=["status='approved'"]; $p=[]; if(!empty($criteria['purchase_user_id'])){$where[]='purchase_user_id=?';$p[]=$criteria['purchase_user_id'];} $match=[]; if(!empty($criteria['source_event_id'])){$match[]='source_event_id=?';$p[]=$criteria['source_event_id'];} if(!empty($criteria['revenuecat_transaction_id'])){$match[]='revenuecat_transaction_id=?';$p[]=$criteria['revenuecat_transaction_id'];} if($match){$where[]='('.implode(' OR ',$match).')';} $st=$pdo->prepare('SELECT * FROM referral_reward_events WHERE '.implode(' AND ',$where).' ORDER BY created_at DESC LIMIT 20');$st->execute($p);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];} foreach($rows as $r){$id=$r['id'];$pdo->prepare("UPDATE user_premium_bonus_grants SET status='reversed',reversed_at=NOW(),admin_note=? WHERE reward_event_id=? AND status='active'")->execute([$note,$id]);$pdo->prepare("UPDATE user_referral_bonus_ledger SET status='reversed',reversed_at=NOW(),admin_note=? WHERE reward_event_id=? AND status='active'")->execute([$note,$id]);$pdo->prepare("UPDATE referral_reward_events SET status='reversed',reversed_at=NOW(),admin_note=?,updated_at=NOW() WHERE id=?")->execute([$note,$id]);} return count($rows); }
function referral_process_pending_rewards(PDO $pdo,int $limit=200): array { if(empty(referral_get_global_settings($pdo)['auto_approve_enabled']))return ['processed'=>0,'approved'=>0,'auto_approve_enabled'=>false]; $st=$pdo->prepare("SELECT id FROM referral_reward_events WHERE status='pending' AND eligible_at<=NOW() ORDER BY eligible_at ASC LIMIT ".max(1,min(1000,$limit)));$st->execute();$ids=$st->fetchAll(PDO::FETCH_COLUMN)?:[]; foreach($ids as $id)referral_approve_reward_event($pdo,(string)$id,null,'auto_approved'); return ['processed'=>count($ids),'approved'=>count($ids),'auto_approve_enabled'=>true]; }

function referral_get_user_summary(PDO $pdo,string $userId): array
{
    $c=referral_get_or_create_code($pdo,$userId); $g=referral_get_global_settings($pdo); $codeValue=$c['referral_code'] ?? $c['code'] ?? ''; $base=trim((string)$g['invite_base_url']) ?: '/?ref='; $link=str_contains($base,'{code}')?str_replace('{code}',$codeValue,$base):(rtrim($base,'=').'='.rawurlencode($codeValue));
    $st=$pdo->prepare("SELECT status,COUNT(*) c,COALESCE(SUM(referrer_reward_days+referred_reward_days+buyer_bonus_days),0) days FROM referral_reward_events WHERE referrer_user_id=? OR referred_user_id=? OR purchase_user_id=? GROUP BY status");$st->execute([$userId,$userId,$userId]); $cnt=['pending_rewards_count'=>0,'approved_rewards_count'=>0,'pending_days'=>0,'approved_days'=>0]; foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){if($r['status']==='pending'){$cnt['pending_rewards_count']=(int)$r['c'];$cnt['pending_days']=(int)$r['days'];} if($r['status']==='approved'){$cnt['approved_rewards_count']=(int)$r['c'];$cnt['approved_days']=(int)$r['days'];}}
    $st=$pdo->prepare("SELECT * FROM referral_reward_events WHERE referrer_user_id=? OR referred_user_id=? OR purchase_user_id=? ORDER BY created_at DESC LIMIT 50");$st->execute([$userId,$userId,$userId]);
    return array_merge(['code'=>$codeValue,'referral_code'=>$codeValue,'invite_link'=>$link,'referral_link'=>$link,'bonus_percent'=>referral_get_user_bonus_percent($pdo,$userId),'max_bonus_percent'=>(int)$g['max_bonus_percent'],'history'=>$st->fetchAll(PDO::FETCH_ASSOC)?:[]],$cnt);
}
function referral_mask_name(PDO $pdo,string $userId): string { $st=$pdo->prepare('SELECT full_name,email FROM user_profiles WHERE id=? LIMIT 1');$st->execute([$userId]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[]; $n=trim((string)($r['full_name']?:$r['email']??'')); return $n===''?'Kullanıcı':mb_substr($n,0,2).'***'; }

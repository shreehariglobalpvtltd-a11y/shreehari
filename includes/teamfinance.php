<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }
final class TeamFinance {
 public static function term(int $aid,?string $at=null): ?array {
  try { return Database::fetch('SELECT * FROM team_terms WHERE admin_id=:a AND effective_at<=:t ORDER BY effective_at DESC,id DESC LIMIT 1',['a'=>$aid,'t'=>$at?:date('Y-m-d H:i:s')]); } catch(Throwable $e) { return null; }
 }
 public static function terms(int $aid,array $in,int $by): void {
  if (!Auth::can('staff.manage')) { throw new RuntimeException('Staff management permission required.'); }
  if (!Database::exists("SELECT 1 FROM admins WHERE id=:a AND role='agent'",['a'=>$aid])) { throw new RuntimeException('Choose the linked agent account. Staff payroll stays in Payroll.'); }
  $basis=(string)($in['basis']??'');$rate=$in['rate']??'';$salary=$in['salary']??'';$reason=Security::clean((string)($in['reason']??''),255);
  $date=(string)($in['effective_at']??date('Y-m-d'));$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
  if (!$dt || $dt->format('Y-m-d')!==$date || $date<date('Y-m-d')) { throw new RuntimeException('Effective date must be today or later. Posted earnings are never rewritten.'); }
  if (!in_array($basis,['percent','flat','booking','salary'],true)||!is_numeric($rate)||!is_numeric($salary)||!is_finite((float)$rate)||!is_finite((float)$salary)||(float)$rate<0||(float)$rate>($basis==='percent'?100:10000)||(float)$salary<0||(float)$salary>10000000||mb_strlen($reason)<5) { throw new RuntimeException('Check commission, salary and mandatory reason.'); }
  Database::transaction(static function()use($aid,$basis,$rate,$salary,$reason,$by,$date):void {
   Database::fetch('SELECT id FROM admins WHERE id=:a FOR UPDATE',['a'=>$aid]);
   // Snapshot the old scheme before the first scheduled change, including any legacy salary.
   if (!Database::exists('SELECT 1 FROM team_terms WHERE admin_id=:a',['a'=>$aid])) {
    $ov=AgentWallet::commissionOverrideFor($aid);$b=$ov['mode']??(AgentWallet::flatMode()?'flat':'percent');
    $r=$b==='percent'?AgentWallet::commissionPercentFor($aid):($ov['flat']??AgentWallet::flatRateFor($aid));
    Database::insert('team_terms',['admin_id'=>$aid,'basis'=>$b,'rate'=>$r,'salary'=>AgentWallet::salaryFor($aid),'effective_at'=>'2000-01-01 00:00:00','reason'=>'Preserved existing agreement before scheduled change','created_by'=>$by]);
   }
   if(Database::exists('SELECT 1 FROM team_terms WHERE admin_id=:a AND basis=:b AND rate=:r AND salary=:s AND reason=:why AND DATE(effective_at)=:d',['a'=>$aid,'b'=>$basis,'r'=>$basis==='salary'?0:round((float)$rate,2),'s'=>round((float)$salary,2),'why'=>$reason,'d'=>$date])){return;}
   $effective=$date===date('Y-m-d')?date('Y-m-d H:i:s'):$date.' 00:00:00';
   Database::insert('team_terms',['admin_id'=>$aid,'basis'=>$basis,'rate'=>$basis==='salary'?0:round((float)$rate,2),'salary'=>round((float)$salary,2),'effective_at'=>$effective,'reason'=>$reason,'created_by'=>$by]);
   Logger::audit('team.terms','admin',(string)$aid,null,['basis'=>$basis,'rate'=>$rate,'salary'=>$salary,'effective_at'=>$effective],$reason);
  });
 }
 public static function available(int $aid): array {
  $due=AgentWallet::balances($aid)['commission'];$reserved=(float)Database::scalar("SELECT COALESCE(SUM(amount),0) FROM agent_payout_requests WHERE agent_admin_id=:a AND status='open'",['a'=>$aid],0);
  return ['pending'=>$reserved,'available'=>max(0,round($due-$reserved,2)),'due'=>$due];
 }
 public static function approve(int $id,int $by): void {
  if (!Auth::can('commissions.pay')) { throw new RuntimeException('Finance payout permission required.'); }
  $r=Database::fetch("SELECT * FROM agent_payout_requests WHERE id=:i AND status='open'",['i'=>$id]);
  if (!$r) { throw new RuntimeException('Open request not found.'); }
  if ((int)$r['agent_admin_id']===$by) { throw new RuntimeException('Another administrator must approve your withdrawal.'); }
  $meta=Database::fetch('SELECT i.payout_verified FROM team_identity i JOIN agent_applications a ON i.person_key=CONCAT(\'agent:\',a.id) WHERE a.admin_id=:a LIMIT 1',['a'=>$r['agent_admin_id']]);
  if (empty($meta['payout_verified'])) { throw new RuntimeException('Verify the recipient payout details on their profile first.'); }
  Database::run('INSERT IGNORE INTO team_withdrawal_approvals(request_id,approved_by) VALUES(:i,:b)',['i'=>$id,'b'=>$by]);
  Logger::audit('team.withdrawal_approved','withdrawal',(string)$id,null,null,'Approved only; no payment recorded');
 }
 public static function pay(int $id,string $reference,int $by): int {
  if (!Auth::can('commissions.pay')) { throw new RuntimeException('Finance payout permission required.'); }
  $reference=Security::clean($reference,120);if(mb_strlen($reference)<5){throw new RuntimeException('Record the confirmed payment reference (at least five characters).');}
  return Database::transaction(static function()use($id,$reference,$by):int {
   $peek=Database::fetch('SELECT agent_admin_id FROM agent_payout_requests WHERE id=:i',['i'=>$id]);if(!$peek){throw new RuntimeException('Request not found.');}
   $aid=(int)$peek['agent_admin_id']; Database::fetch('SELECT id FROM admins WHERE id=:a FOR UPDATE',['a'=>$aid]);
   $r=Database::fetch('SELECT * FROM agent_payout_requests WHERE id=:i FOR UPDATE',['i'=>$id]);
   if($r['status']!=='open'){throw new RuntimeException('This request was already decided.');}
   if(!Database::exists('SELECT 1 FROM team_withdrawal_approvals WHERE request_id=:i',['i'=>$id])){throw new RuntimeException('Approve the withdrawal before recording payment.');}
   if(Database::exists("SELECT 1 FROM agent_ledger WHERE entry_type='payout' AND ref=:r",['r'=>$reference])){throw new RuntimeException('This payment reference is already recorded.');}
   $ledger=AgentWallet::record($aid,'payout',(float)$r['amount'],['by'=>$by,'ref'=>$reference,'note'=>'Confirmed withdrawal #'.$id,'request_id'=>$id]);
   AgentWallet::decidePayoutRequest($id,'paid',$by,$reference,$ledger);
   Database::update('team_withdrawal_approvals',['payment_reference'=>$reference],'request_id=:i',['i'=>$id]);return $ledger;
  });
 }
 public static function adjustment(int $aid,float $amount,string $type,string $reason,string $token,int $by): int {
  if(!Auth::can('commissions.pay')||!is_finite($amount)||$amount<=0||$amount>10000000||!in_array($type,['bonus','deduction'],true)||mb_strlen(trim($reason))<5||!preg_match('/^[a-f0-9]{32}$/',$token)){throw new RuntimeException('Valid account, amount, type and reason required.');}
  if(!Database::exists("SELECT 1 FROM admins WHERE id=:a AND role='agent'",['a'=>$aid])){throw new RuntimeException('Choose a linked agent account. Staff pay uses Payroll.');}
  return Database::transaction(static function()use($aid,$amount,$type,$reason,$token,$by):int {
   Database::fetch('SELECT id FROM admins WHERE id=:a FOR UPDATE',['a'=>$aid]);$ref='ADJ-'.$token;
   $old=(int)Database::scalar('SELECT id FROM agent_ledger WHERE ref=:r AND agent_admin_id=:a',['r'=>$ref,'a'=>$aid],0);if($old){return $old;}
   if($type==='deduction'&&$amount>self::available($aid)['available']){throw new RuntimeException('Deduction exceeds unreserved available earnings.');}
   return AgentWallet::record($aid,'adjustment',$amount,['by'=>$by,'ref'=>$ref,'note'=>$reason,'direction'=>$type==='deduction'?'debit':'credit']);
  });
 }
}

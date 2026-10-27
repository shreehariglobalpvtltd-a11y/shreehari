<?php
/** CLI-only, read-only schema inventory for this continuation. Does not migrate or print credentials/data. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
$required = [
    'agent_applications'=>['id','type','role_category','parent_agent_id','fields_json'],
    'trip_expenses'=>['id','route_id','expense_date','diesel','toll','driver_pay','misc','total','note','created_by','created_at'],
    'team_identity'=>['person_key','code','origin','route','consent_note','delivery_consent','payout_verified'],
    'team_reviews'=>['id','person_key','file_key','mime','suggestions','created_by','reviewed_at','created_at'],
    'team_card_jobs'=>['id','ref','recipient','file_key','dedupe','state','provider_ref'],
    'team_terms'=>['admin_id','basis','rate','salary','effective_at'],
    'team_withdrawal_approvals'=>['request_id','approved_by'],
    'team_ledger_journal'=>['id','ledger_id','event_type','new_row'],
    'agent_payout_requests'=>['id','agent_admin_id','amount','status'],
];
$missing=[];
foreach($required as $table=>$columns){
    $actual=Database::fetchAll('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t',['t'=>$table]);
    $diff=array_values(array_diff($columns,array_column($actual,'name')));
    if($diff){$missing[$table]=$diff;}
}
$triggers=Database::fetchAll("SELECT EVENT_MANIPULATION AS event, ACTION_STATEMENT AS statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='team_ledger_journal' AND ACTION_TIMING='BEFORE'");
foreach(['UPDATE','DELETE'] as $event){$found=false;foreach($triggers as $t){if($t['event']===$event && str_contains($t['statement'],'Ledger audit history is immutable')){$found=true;}}if(!$found){$missing['team_ledger_journal_triggers'][]=$event;}}
echo json_encode(['ok'=>$missing===[],'php'=>PHP_VERSION,'database_engine'=>Database::scalar('SELECT VERSION()'),'checked_tables'=>count($required),'missing'=>$missing],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($missing===[]?0:1);

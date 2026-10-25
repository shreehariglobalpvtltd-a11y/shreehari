<?php
declare(strict_types=1);require dirname(__DIR__).'/includes/bootstrap.php';require_once INCLUDE_PATH.'/teamregistry.php';require_once INCLUDE_PATH.'/teamfinance.php';if(!str_ends_with((string)Database::scalar('SELECT DATABASE()'),'_test')){exit('TEST ONLY');}
$aid=(int)Database::scalar("SELECT id FROM admins WHERE username='legacy-login-id'");if(!$aid){exit('Run unified-team-test first');}
$by=(int)Database::scalar("SELECT id FROM admins WHERE username='unified-owner'");
if(isset($argv[1])){usleep(150000);try{if($argv[1]==='withdraw'){echo AgentWallet::requestPayout($aid,100,'Synthetic race');}else{echo AgentWallet::postSalary($aid,date('Y-m'),$by);}}catch(Throwable $e){echo 'REJECTED';}exit;}
foreach(['withdraw','salary']as$mode){$workers=[];for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$workers[]=[$process,$pipes];}
$out=[];foreach($workers as[$proc,$pipes]){$out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);if($err){throw new RuntimeException($err);}}
if(count(array_filter($out,static fn($x)=>$x==='REJECTED'))!==1){throw new RuntimeException('Race was not serialized: '.json_encode($out));}
if($mode==='withdraw'){$count=(int)Database::scalar("SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id=:a AND status='open'",['a'=>$aid]);if($count!==1){throw new RuntimeException('Duplicate withdrawal');}foreach(AgentWallet::openPayoutRequests($aid)as$r){AgentWallet::decidePayoutRequest((int)$r['id'],'declined',$by,'Synthetic race completed');}}
else{$count=(int)Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id=:a AND ref=:r",['a'=>$aid,'r'=>'SALARY '.date('Y-m')]);if($count!==1){throw new RuntimeException('Duplicate salary');}}
echo 'PASS simultaneous '.$mode.' submissions create one financial record'.PHP_EOL;}

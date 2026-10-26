<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamregistry.php';
require_once INCLUDE_PATH . '/teamfinance.php';
require_once __DIR__ . '/payout-fixture.php';
if (!str_ends_with((string)Database::scalar('SELECT DATABASE()'), '_test')) { fwrite(STDERR, 'TEST DATABASE ONLY'); exit(2); }
if (($argv[1] ?? '') === '--worker') {
    usleep(150000);
    try {
        if ($argv[2] === 'withdraw') { AgentWallet::requestPayout((int)$argv[3], 100, 'Synthetic race'); }
        else { AgentWallet::postSalary((int)$argv[3], date('Y-m'), 0); }
        echo 'WIN';
    } catch (Throwable $e) { echo 'REJECTED'; }
    exit;
}
$tag = bin2hex(random_bytes(6));
$aid = Database::insert('admins', ['username'=>'race-'.$tag, 'full_name'=>'Synthetic Race', 'role'=>'agent', 'is_active'=>1, 'password_hash'=>Security::hashPassword('synthetic-password')]);
$walletBefore = Settings::getBool('agent_wallet_enabled', false);
try {
    Settings::set('agent_wallet_enabled', true, 'bool', 'agent', false);
    verifiedPayoutFixture($aid);
    Database::insert('team_terms', ['admin_id'=>$aid, 'basis'=>'salary', 'rate'=>0, 'salary'=>1000, 'effective_at'=>date('Y-m-d').' 00:00:00', 'reason'=>'Synthetic race salary', 'created_by'=>0]);
    AgentWallet::record($aid, 'commission', 400, ['ref'=>'SYNTHETIC-RACE-'.$tag, 'by'=>0]);
    foreach (['withdraw','salary'] as $mode) {
        $workers=[];
        for ($i=0; $i<2; $i++) {
            $pipes=[];
            $cmd=[PHP_BINARY]; if ($ini=php_ini_loaded_file()) { $cmd[]='-c'; $cmd[]=$ini; }
            array_push($cmd,__FILE__,'--worker',$mode,(string)$aid);
            $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($proc)) { throw new RuntimeException('Worker did not start'); }
            fclose($pipes[0]); $workers[]=[$proc,$pipes];
        }
        $out=[];
        foreach ($workers as [$proc,$pipes]) {
            $out[]=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($proc);
            if ($exit!==0 || $err!=='') { throw new RuntimeException('Worker failed: '.$err); }
        }
        sort($out); if ($out!==['REJECTED','WIN']) { throw new RuntimeException('Race not serialized: '.json_encode($out)); }
        if ($mode==='withdraw') {
            $rows=AgentWallet::openPayoutRequests($aid);
            if (count($rows)!==1 || TeamFinance::available($aid)['pending']!==100.0 || TeamFinance::available($aid)['available']!==300.0) { throw new RuntimeException('Withdrawal reservation is incorrect'); }
            AgentWallet::decidePayoutRequest((int)$rows[0]['id'],'declined',0,'Synthetic race complete');
        } else {
            if ((int)Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id=:a AND ref=:r',['a'=>$aid,'r'=>'SALARY '.date('Y-m')])!==1) { throw new RuntimeException('Duplicate salary'); }
        }
        echo 'PASS simultaneous '.$mode.' submissions create one financial record'.PHP_EOL;
    }
    echo "SUCCESS 2 independent financial races\n";
} finally {
    Settings::set('agent_wallet_enabled',$walletBefore,'bool','agent',false);
    Database::delete('agent_payout_requests','agent_admin_id=:a',['a'=>$aid]);
    Database::delete('agent_ledger','agent_admin_id=:a',['a'=>$aid]);
    Database::delete('team_terms','admin_id=:a',['a'=>$aid]);
    // Synthetic identities and immutable audit events remain in the disposable test DB.
    Database::update('admins',['is_active'=>0],'id=:a',['a'=>$aid]);
}

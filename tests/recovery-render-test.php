<?php
/** CLI-only controller smoke checks on an isolated synthetic staging database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!str_contains(DB_NAME, 'test')) { fwrite(STDERR, "Requires test database\n"); exit(2); }
if (($argv[1] ?? '') === '--child') {
    $_SESSION[ADMIN_SESSION_KEY] = ['id'=>1,'username'=>'recovery-test','full_name'=>'Recovery Test','role'=>'superadmin','permissions'=>[],'last_seen'=>time()];
    $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['REQUEST_URI']='/admin/' . $argv[2];
    $_GET = $_REQUEST = ['sid'=>(int)($argv[3] ?? 0)];
    chdir(dirname(__DIR__) . '/admin');
    require $argv[2];
    exit;
}
$sid=(int)Database::scalar('SELECT id FROM schedules ORDER BY id LIMIT 1',[],0);
$pages=['agents.php'=>'Agents & Organization Hub','agent-sales.php'=>'My Sales','seatmap.php'=>'seatmap-visual','trip-dashboard.php'=>'All Buses','company.php'=>'Company details','manifest.php'=>'Passenger manifest','analytics.php'=>'Analytics','messages-log.php'=>'Message Log'];
$fail=0;
foreach ($pages as $page=>$marker) {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --child '.escapeshellarg($page).' '.$sid;
    $lines=[]; $code=0; exec($cmd.' 2>&1',$lines,$code); $html=implode("\n",$lines);
    $ok=$code===0 && str_contains(html_entity_decode($html, ENT_QUOTES, 'UTF-8'),$marker) && str_contains($html,'</body></html>') && !str_contains($html,'Application error') && !str_contains($html,'Something went wrong');
    echo ($ok?'PASS ':'FAIL ').$page."\n";
    if(!$ok){$fail++; echo substr(strip_tags($html),-1500)."\n";}
}
exit($fail?1:0);

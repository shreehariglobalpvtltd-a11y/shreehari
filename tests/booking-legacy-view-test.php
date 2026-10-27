<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
if (!str_ends_with((string) DB_NAME, '_test')) { throw new RuntimeException('Test database required'); }
$id = Database::insert('bookings', ['pnr'=>'LEGACY-QA-'.bin2hex(random_bytes(5)),
    'contact_phone'=>'9100000099', 'total_amount'=>1000, 'status'=>'confirmed']);
try {
    $pnr=(string)Database::scalar('SELECT pnr FROM bookings WHERE id=:i',['i'=>$id]);
    $cmd=[PHP_BINARY];if($ini=php_ini_loaded_file()){$cmd[]='-c';$cmd[]=$ini;}
    $cmd=array_merge($cmd,[__DIR__.'/render-admin.php','booking-view.php','pnr='.$pnr]);
    $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc)){throw new RuntimeException('Cannot render historical booking');}
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
    if($code!==0 || !str_contains($out,'</html>') || !str_contains($out,'no active departure or seat allocation')
        || str_contains($out,'Application error') || str_contains($out,'Fatal error')) {
        throw new RuntimeException('Historical booking must render fully without unsafe seat controls: '.$err);
    }
    echo "PASS historical booking without a departure renders with seat-edit explanation\n";
} finally { Database::delete('bookings','id=:i',['i'=>$id]); }

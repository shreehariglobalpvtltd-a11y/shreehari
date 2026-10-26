<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$file = dirname(__DIR__) . '/includes/gpssimulator.php';
foreach ([null, 'production', 'Production', 'unknown', 'development', 'staging', 'test'] as $env) {
    $allowed = in_array($env, ['development','staging','test'], true);
    $code = "define('SHG_APP',true);" . ($env === null ? '' : 'define("APP_ENV",'.var_export($env,true).');')
        . 'require '.var_export($file,true).';'
        . 'if(GpsSimulator::available()!=='.($allowed?'true':'false').'){exit(1);}';
    if (!$allowed) {
        // No database/config mocks: a guard must run before any read, write or frame.
        $code .= '$calls=[fn()=>GpsSimulator::state(),fn()=>GpsSimulator::save("start",[],1),fn()=>GpsSimulator::route(1),fn()=>GpsSimulator::frame([],[],1)];'
            . 'foreach($calls as $call){try{$call();exit(2);}catch(RuntimeException $e){}}';
    }
    $proc = proc_open([PHP_BINARY,'-r',$code], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($proc)) { throw new RuntimeException('Cannot start environment check'); }
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($proc)!==0) { throw new RuntimeException('Simulation environment '.($env??'undefined').' failed: '.$out.$err); }
}
echo "GPS simulation environment guards passed (production/undefined fail closed)\n";

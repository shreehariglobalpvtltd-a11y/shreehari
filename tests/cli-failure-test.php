<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$file=tempnam(sys_get_temp_dir(),'shg-cli-failure-');
try {
    file_put_contents($file,'<?php require '.var_export(dirname(__DIR__).'/includes/bootstrap.php',true).'; throw new RuntimeException("Synthetic CLI failure");');
    $pipes=[];$cmd=[PHP_BINARY];if($ini=php_ini_loaded_file()){$cmd[]='-c';$cmd[]=$ini;}$cmd[]=$file;
    $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
    if($exit!==1 || str_contains($out,'<h1>') || !str_contains($err,'Application')){fwrite(STDERR,'CLI failures must return nonzero without a success-shaped HTML page');exit(1);}
    echo "PASS uncaught CLI exceptions fail the process and emit a diagnostic\n";
} finally {@unlink($file);}

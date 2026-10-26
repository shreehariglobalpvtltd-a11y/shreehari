<?php
declare(strict_types=1);require __DIR__.'/_guard.php';require_once INCLUDE_PATH.'/teamregistry.php';$admin=admin_boot('staff.manage');if(!TeamManager::allowed($admin)){http_response_code(403);exit;}
$r=Database::fetch('SELECT * FROM team_reviews WHERE id=:i',['i'=>(int)($_GET['id']??0)]);if(!$r){http_response_code(404);exit;}$bytes=TeamRegistry::open($r['file_key']);Logger::audit('team.original_view','team',$r['person_key'],null,['import_id'=>$r['id']],'Private original viewed');
header('Content-Type: '.$r['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Security-Policy: sandbox');header('Content-Disposition: inline; filename="original-'.(int)$r['id'].($r['mime']==='application/pdf'?'.pdf':'.jpg').'"');echo $bytes;

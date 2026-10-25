<?php
declare(strict_types=1);require __DIR__.'/_init.php';require_once INCLUDE_PATH.'/teamregistry.php';
try{$a=TeamRegistry::actor();$post=($_SERVER['REQUEST_METHOD']??'GET')==='POST';if($post){Security::requireCsrf();} $key=Security::clean((string)($post?Response::field('person',''):($_GET['person']??'')),40);
if(!TeamManager::find($key)){Response::error('Application not found.',404);}
$allowed=['full_name','name','email','address_perm','address_current','district','province','area','p_addr','p_idno','p_route','import_id'];
if($post){$input=Response::field('fields',[]);$fields=[];foreach($allowed as $k){if(isset($input[$k])&&is_scalar($input[$k])){$fields[$k]=Security::clean((string)$input[$k],500);}}
require_once INCLUDE_PATH.'/companydocs.php';$json=base64_encode(CompanyDocs::seal(json_encode($fields,JSON_UNESCAPED_UNICODE)));
Database::run('INSERT INTO team_drafts(person_key,admin_id,fields) VALUES(:k,:a,:f) ON DUPLICATE KEY UPDATE fields=:f2',['k'=>$key,'a'=>$a['id'],'f'=>$json,'f2'=>$json]);Response::json(['ok'=>true]);}
$r=Database::fetch('SELECT fields FROM team_drafts WHERE person_key=:k AND admin_id=:a',['k'=>$key,'a'=>$a['id']]);require_once INCLUDE_PATH.'/companydocs.php';Response::json(['ok'=>true,'fields'=>$r?(json_decode(CompanyDocs::open(base64_decode($r['fields'],true)),true)?:[]):[]]);
}catch(Throwable $e){Response::serverError($e);}

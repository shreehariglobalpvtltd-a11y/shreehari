<?php
declare(strict_types=1);
require __DIR__.'/_init.php';require_once INCLUDE_PATH.'/teamregistry.php';require_once INCLUDE_PATH.'/applicationimport.php';
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){Response::error('POST required.',405);} Security::requireCsrf();$actor=TeamRegistry::actor();
 Response::$maxInputBytes=8200000;Security::requireRateLimit('team_ocr_admin',(string)$actor['id'],20,3600);
 $key=Security::clean((string)Response::field('person',''),40);$row=TeamManager::find($key);if(!$row||$row['kind']==='account'){Response::error('Choose an application.',404);}
 $file=ApplicationImport::decode(Response::field('doc',null));if(!$file){Response::error('Choose a valid JPEG, PNG, WEBP or PDF, at most 6 MB.',422);}
 $original=TeamRegistry::stash($file['bytes']);
 $id=Database::insert('team_reviews',['person_key'=>$key,'file_key'=>$original,'mime'=>$file['mime'],'suggestions'=>'{"status":"pending","fields":{}}','created_by'=>$actor['id']]);
 try{$r=ApplicationImport::read($file,'form');}catch(Throwable $e){Logger::warning('Team OCR unavailable');$r=['status'=>'unavailable','fields'=>[]];}
 $fields=$r['fields']??[];Database::update('team_reviews',['suggestions'=>json_encode($r,JSON_UNESCAPED_UNICODE)],'id=:i',['i'=>$id]);
 Logger::audit('team.ocr','team',$key,null,['import_id'=>$id,'status'=>$r['status']],'Private original retained; suggestions require manual review');
 Response::json(['ok'=>true,'status'=>$r['status'],'fields'=>$fields,'uncertain_fields'=>array_keys(array_filter($fields,static fn($v)=>$v!=='')),'import_id'=>$id]);
}catch(Throwable $e){Response::serverError($e);}

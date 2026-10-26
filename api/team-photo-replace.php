<?php
declare(strict_types=1);require __DIR__.'/_init.php';require_once INCLUDE_PATH.'/teamregistry.php';require_once INCLUDE_PATH.'/idcard.php';
try{if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){Response::error('POST required.',405);}Security::requireCsrf();$admin=TeamRegistry::actor();Response::$maxInputBytes=2500000;
$ref=strtoupper(trim((string)Response::field('ref','')));$key=TeamRegistry::key($ref);$r=TeamManager::find($key);if(!$r||$r['archived']){Response::error('Current application not found.',404);}
$clean=AgentApplications::cleanPhoto(Response::field('photo',null));if(empty($clean['photo'])){Response::error('Invalid portrait photo.',422);}
$bytes=base64_decode(explode(',',$clean['photo'],2)[1],true);$size=getimagesizefromstring($bytes);if(!$size||$size[0]<240||$size[1]<300){Response::error('Use a clear portrait at least 240 × 300 pixels.',422);}
$old=IdCard::person($ref);if($old&&!empty($old['photo'])){$file=TeamRegistry::stash($old['photo']);Database::insert('team_reviews',['person_key'=>$key,'file_key'=>$file,'mime'=>(new finfo(FILEINFO_MIME_TYPE))->buffer($old['photo']),'suggestions'=>'{"type":"previous-approved-photo"}','created_by'=>$admin['id']]);}
if($r['kind']==='staff'){$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$path=StaffApps::saveFile((int)$r['id'],'photo',$bytes,$mime);Database::update('staff_applications',['photo_path'=>$path],'id=:i',['i'=>$r['id']]);}
else{AgentApplications::saveCard((int)$r['id'],['photo'=>$clean['photo']],(int)$admin['id'],true);}
Logger::audit('photo.replaced','team',$key,null,['sha256'=>hash('sha256',$bytes)],'Portrait replaced by authorized office reviewer; prior photo preserved');
Response::json(['ok'=>true,'message'=>'Photo saved. Card previews now use the reviewed photo.']);
}catch(Throwable $e){Response::serverError($e);}

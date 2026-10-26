<?php
declare(strict_types=1);if(PHP_SAPI!=='cli'){exit;}define('SHG_APP',true);require dirname(__DIR__).'/includes/bootstrap.php';require_once INCLUDE_PATH.'/teamregistry.php';
$by=(int)Database::scalar("SELECT id FROM admins WHERE role='superadmin' AND is_active=1 ORDER BY id LIMIT 1",[],0);if(!$by){throw new RuntimeException('Active superadmin required.');}$actor=['id'=>$by,'role'=>'superadmin'];$made=0;$archived=0;
foreach(TeamManager::rows()as$r){if($r['archived']||$r['kind']==='account'){continue;}if(TeamRegistry::digital($r)){TeamRegistry::ensure($r['key'],$by);$made++;continue;}
 if($r['kind']==='agent'){$a=AgentApplications::find((int)$r['id']);if(($a['fields']['p_occ']??'')==='Added by the office'||($a['note']??'')==='Brought in from the agent accounts list'){TeamManager::archive($r['key'],false,$actor);$archived++;}}
}
echo json_encode(['digital_records'=>$made,'recoverably_archived_imports'=>$archived,'deleted_records'=>0]).PHP_EOL;

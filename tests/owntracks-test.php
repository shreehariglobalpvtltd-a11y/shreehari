<?php
declare(strict_types=1);
define('SHG_APP',true);define('APP_KEY','owntracks-test-key');
class Database{
 public static array $row=[];
 public static function fetch(string $sql,array $params=[]):?array{return ($params['u']??'')===(self::$row['device_uid']??'')?self::$row:null;}
}
require_once dirname(__DIR__).'/includes/owntracks.php';
function check(bool $ok,string $msg):void{if(!$ok){throw new RuntimeException($msg);}}
$uid=GpsTrackers::generateUid();$tracker=['id'=>3,'bus_id'=>7,'device_uid'=>$uid,'kind'=>'phone','is_active'=>1,'revoked_at'=>null,'bus_number'=>'GJ-02-T-6011'];Database::$row=$tracker;
$header=['HTTP_AUTHORIZATION'=>'Basic '.base64_encode('bus-7:'.$uid)];
check(OwnTracksGateway::authenticatedTracker($header)['id']===3,'Basic header auth');
check(OwnTracksGateway::authenticatedTracker(['PHP_AUTH_USER'=>'bus-7','PHP_AUTH_PW'=>$uid])['id']===3,'PHP auth variables');
check(OwnTracksGateway::authenticatedTracker([])===null,'missing credentials refused');
check(OwnTracksGateway::authenticatedTracker(['HTTP_AUTHORIZATION'=>'Basic !!'])===null,'bad base64 refused');
check(OwnTracksGateway::authenticatedTracker(['PHP_AUTH_USER'=>'bus-8','PHP_AUTH_PW'=>$uid])===null,'wrong bus refused');
Database::$row['is_active']=0;check(OwnTracksGateway::authenticatedTracker($header)===null,'inactive refused');
Database::$row=$tracker;Database::$row['revoked_at']='2026-01-01';check(OwnTracksGateway::authenticatedTracker($header)===null,'revoked refused');
Database::$row=$tracker;Database::$row['kind']='hardware';check(OwnTracksGateway::authenticatedTracker($header)===null,'hardware never uses phone auth');
Database::$row=$tracker;
$own=['_type'=>'location','lat'=>23.1,'lon'=>72.6,'tst'=>1792900000,'vel'=>54,'acc'=>9,'cog'=>180,'batt'=>70,'tid'=>'FORGED-OTHER-BUS'];
$q=OwnTracksGateway::location($own,$uid);$p=GpsTrackers::parseOsmAnd($q);
check($p['uid']===$uid,'topic cannot change authenticated identity');
check($p['fixes'][0]['kmh']===54.0,'km/h speed preserved');
check($p['fixes'][0]['ts']===1792900000,'GPS time preserved');
check($p['fixes'][0]['acc']===9.0&&$p['fixes'][0]['brg']===180.0,'accuracy and direction preserved');
$bad=$own;unset($bad['tst']);try{OwnTracksGateway::location($bad,$uid);throw new RuntimeException('missing fix time accepted');}catch(InvalidArgumentException $e){}
$profile=json_decode(OwnTracksGateway::profile($tracker),true,512,JSON_THROW_ON_ERROR);
check($profile['mode']===3&&$profile['auth']===true&&$profile['monitoring']===2,'HTTP Move profile');
check($profile['url']==='https://www.shreehariglobal.in/api/owntracks.php','canonical HTTPS receiver');
check($profile['username']==='bus-7'&&$profile['password']===$uid,'correct bus account');
check($profile['locatorInterval']===3&&$profile['locatorPriority']===0,'3-second high accuracy');
check($profile['autostartOnBoot']===true,'dedicated bus phone restarts tracking after boot');
check(!isset($profile['allowConfigurationByURIAndConfigFile']),'external import switch stays under user control');
echo "19 OwnTracks auth, protocol and profile checks passed\n";

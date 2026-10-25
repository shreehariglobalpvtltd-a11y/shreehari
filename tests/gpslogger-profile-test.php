<?php
declare(strict_types=1);
define('SHG_APP',true);
define('APP_KEY','gpslogger-unit-test-key-no-production-secret');
require_once dirname(__DIR__).'/includes/gpsloggerprofile.php';
function ok(bool $v,string $label):void{if(!$v){throw new RuntimeException($label);}}
$now=1792900000;$id=3;$e=$now+86400;$sig=GpsLoggerProfile::signature($id,$e);
ok(GpsLoggerProfile::valid($id,$e,$sig,$now),'valid signature');
ok(!GpsLoggerProfile::valid($id+1,$e,$sig,$now),'other tracker rejected');
ok(!GpsLoggerProfile::valid($id,$e+1,$sig,$now),'changed expiry rejected');
ok(!GpsLoggerProfile::valid($id,$e,$sig,$e),'expired rejected');
ok(!GpsLoggerProfile::valid($id,$e+1,GpsLoggerProfile::signature($id,$e+1),$now),'excess TTL rejected');
$uid=GpsTrackers::generateUid();$body=GpsLoggerProfile::body($uid);$props=[];
foreach(explode("\n",$body) as $line){if($line===''||str_starts_with($line,'#')){continue;}[$k,$v]=explode('=',$line,2);$props[$k]=$v;}
ok($props['log_customurl_method']==='POST','POST uses a private body');
ok($props['log_customurl_url']==='https://www.shreehariglobal.in/api/gps.php','canonical HTTPS');
ok($props['time_before_logging']==='10','10 second interval');
ok($props['log_satellite_locations']==='true'&&$props['log_network_locations']==='false','GNSS without cached network provider');
ok($props['autosend_enabled']==='false'&&$props['autocustomurl_enabled']==='false','no duplicate file auto uploads');
$replace=['%LAT'=>'23.1','%LON'=>'72.6','%TIMESTAMP'=>(string)$now,'%SPD'=>'10','%DIR'=>'90','%ACC'=>'8','%BATT'=>'70'];
parse_str(strtr($props['log_customurl_body'],$replace),$q);$p=GpsTrackers::parseOsmAnd($q);
ok($p['bad']===''&&$p['uid']===$uid,'profile imports with matching tracker');
ok($p['fixes'][0]['kmh']===36.0,'GPSLogger 10 m/s = 36 km/h');
ok($p['fixes'][0]['ts']===$now,'real fix timestamp preserved');
unset($q['source']);$p=GpsTrackers::parseOsmAnd($q);ok($p['fixes'][0]['kmh']===18.52,'Traccar knots preserved');
try{GpsLoggerProfile::body('GJ-02-6011');throw new RuntimeException('bus plate accepted');}catch(InvalidArgumentException $e){}
echo "15 GPSLogger profile, token and protocol checks passed\n";

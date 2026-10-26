<?php
declare(strict_types=1);define('SHG_APP',true);define('APP_ENV','test');
class Settings {public static array $data=[];public static function getArray($k,$d=[]){return self::$data[$k]??$d;}public static function set($k,$v,...$rest){self::$data[$k]=$v;}public static function flush(){} }
class Logger {public static array $audit=[];public static function audit(...$args){self::$audit[]=$args;} }
class Database {public static array $queries=[];public static function fetch($sql,$p=[]){self::$queries[]=$sql;if(str_contains($sql,'FROM routes'))return ['id'=>1,'from_city'=>'QA A','to_city'=>'QA B'];throw new RuntimeException('Road geometry not present in isolated test');}public static function fetchAll($sql,$p=[]){self::$queries[]=$sql;if(str_contains($sql,'FROM route_stops'))return [['latitude'=>23.0,'longitude'=>72.0],['latitude'=>23.01,'longitude'=>72.01]];throw new RuntimeException('Unexpected query');} }
require_once dirname(__DIR__).'/includes/gpscenter.php';require_once dirname(__DIR__).'/includes/gpssimulator.php';
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function reject(callable $f,string $m):void{try{$f();}catch(RuntimeException $e){return;}throw new RuntimeException($m);}
$p=GpsCenter::plan(['bus_id'=>7,'kind'=>'phone','public'=>'1'],false,true);ok($p['busId']===7&&$p['uid']===''&&$p['publish'],'existing phone plan');
reject(fn()=>GpsCenter::plan(['bus_id'=>7,'public'=>'1'],false,false),'unauthorized publishing');
reject(fn()=>GpsCenter::plan(['bus_number'=>'NEW-01'],false,true),'unauthorized fleet creation');
reject(fn()=>GpsCenter::plan(['bus_id'=>7,'kind'=>'hardware'],false,true),'missing hardware ID');
$p=GpsCenter::plan(['bus_number'=>' gj-02-qa ','bus_name'=>'QA','kind'=>'hardware','uid'=>'123456789012345'],true,true);ok($p['plate']==='GJ-02-QA'&&$p['kind']==='hardware','new bus and hardware plan');
reject(fn()=>GpsSimulator::options(['route_id'=>1,'count'=>4,'speed'=>60]),'too many demos');reject(fn()=>GpsSimulator::options(['route_id'=>1,'count'=>3,'speed'=>0]),'invalid speed');
$route=GpsSimulator::route(1);$state=['routeId'=>1,'count'=>3,'speed'=>60,'running'=>true,'elapsed'=>0,'changedAt'=>1000];
$a=GpsSimulator::frame($state,$route,1000);$b=GpsSimulator::frame($state,$route,1001);
ok(count($a['buses'])===3&&count(array_unique(array_column($a['buses'],'id')))===3,'three independent demo identities');
ok($a['buses'][0]['lat']!==$b['buses'][0]['lat'],'bus moves each second');ok(abs(($b['buses'][0]['km']-$a['buses'][0]['km'])-60/3600)<.002,'physical speed-distance relation');
foreach($a['buses'] as $bus){ok($bus['simulation']===true&&str_starts_with($bus['id'],'DEMO-'),'explicit simulation labels');ok($bus['bearing']>=0&&$bus['bearing']<360,'valid heading');}
Settings::$data['gps_simulator']=$state;GpsSimulator::save('pause',[],0,1010);$paused=GpsSimulator::state();$c=GpsSimulator::frame($paused,$route,1020);$d=GpsSimulator::frame($paused,$route,1050);ok($c['buses'][0]['lat']===$d['buses'][0]['lat']&&$c['buses'][0]['kmh']===0,'pause freezes all positions');
$old=GpsSimulator::frame($paused,$route,1020);$resumed=GpsSimulator::save('start',['route_id'=>1,'count'=>3,'speed'=>120],0,1020);$new=GpsSimulator::frame($resumed,$route,1020);ok(abs($old['buses'][0]['lat']-$new['buses'][0]['lat'])<1e-7,'speed change preserves position');
GpsSimulator::save('reset',[],0,1030);$reset=GpsSimulator::state();ok($reset['elapsed']===0&&$reset['running']===false,'reset starts paused at origin');
$total=end($route['cum']);$out=GpsSimulator::point($route,$total+0.1);ok($out['lat']<23.01&&$out['lat']>23.0,'demo turns at route end');
foreach(Database::$queries as $sql){ok(!preg_match('/INSERT|UPDATE|DELETE|gps_trackers|live_trips|schedules|bookings/i',$sql),'demo never writes or uses real GPS/booking rows');}
echo "GPS setup permissions, 3-bus movement, speed, pause/reset, route loop and isolation checks passed\n";

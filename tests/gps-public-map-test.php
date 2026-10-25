<?php
declare(strict_types=1);define('SHG_APP',true);
require_once dirname(__DIR__).'/includes/gpsfleet.php';
function ck(bool $v,string $label):void{if(!$v){throw new RuntimeException($label);}}
$now=1792900000;
$d=['id'=>3,'busId'=>7,'bus'=>'GJ-02-T-6011','name'=>'Bus','state'=>'live','public'=>true,'lat'=>23.1,'lng'=>72.6,'fixAt'=>($now-5)*1000,'seenAt'=>($now-1)*1000,'kmh'=>0,'device_uid'=>'MUST-NOT-LEAK','battery'=>80,'diagnostic'=>['reportedAt'=>($now-5)*1000,'accuracyM'=>5]];
$p=GpsFleet::publicPoints([$d],$now);ck(count($p)===1,'opted-in fresh tracker appears');ck($p[0]['id']===-7&&$p[0]['trackingOnly']===true,'stable independent GPS identity');ck($p[0]['kmh']===0,'zero speed preserved');ck($p[0]['acc']===5,'matching fix accuracy');
foreach(['device_uid','battery','diagnostic','label','busId'] as $k){ck(!array_key_exists($k,$p[0]),'private metadata absent');}
foreach([['public'=>false],['state'=>'offline'],['fixAt'=>($now-121)*1000],['seenAt'=>($now-121)*1000],['fixAt'=>($now+121)*1000],['lat'=>0],['lng'=>null]] as $change){ck(GpsFleet::publicPoints([array_replace($d,$change)],$now)===[],'invalid/non-shared/stale GPS excluded');}
$new=array_replace($d,['id'=>4,'fixAt'=>($now-1)*1000,'kmh'=>32]);$p=GpsFleet::publicPoints([$d,$new],$now);ck(count($p)===1&&$p[0]['kmh']===32,'newest tracker wins for one bus');ck($p[0]['acc']===null,'older diagnostic accuracy not applied to newer fix');
echo "Public GPS opt-in, freshness, deduplication and privacy checks passed\n";

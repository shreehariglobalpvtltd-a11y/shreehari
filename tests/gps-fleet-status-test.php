<?php
// Pure health-state checks; no configuration, database or real devices.
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/gpsfleet.php';
date_default_timezone_set('Asia/Kolkata');
$now = 1792900000;
$base = ['is_active'=>1, 'last_seen'=>date('Y-m-d H:i:s',$now-5), 'last_fix_at'=>date('Y-m-d H:i:s',$now-8), 'last_lat'=>23.1, 'last_lng'=>72.6];
$cases = [
 ['fresh', [], true, 'live'],
 ['never connected', ['last_seen'=>null], true, 'waiting'],
 ['disabled globally', [], false, 'disabled'],
 ['device disabled', ['is_active'=>0], true, 'disabled'],
 ['offline device', ['last_seen'=>date('Y-m-d H:i:s',$now-121)], true, 'offline'],
 ['future device clock', ['last_seen'=>date('Y-m-d H:i:s',$now+121)], true, 'offline'],
 ['connected without fix', ['last_fix_at'=>null], true, 'no_fix'],
 ['missing coordinates', ['last_lng'=>null], true, 'no_fix'],
 ['old fix still receiving', ['last_fix_at'=>date('Y-m-d H:i:s',$now-121)], true, 'stale'],
 ['future fix', ['last_fix_at'=>date('Y-m-d H:i:s',$now+121)], true, 'stale'],
];
foreach ($cases as [$name,$changes,$enabled,$expected]) {
 $got=GpsFleet::state(array_replace($base,$changes),$now,$enabled);
 if ($got!==$expected) { throw new RuntimeException($name.': '.$got); }
}
$p=GpsTrackers::parseOsmAnd(['device'=>['uniqueId'=>'QA-BUS-TEST'],'position'=>['latitude'=>23,'longitude'=>72,'valid'=>'0']]);
if ($p['fixes']!==[]) { throw new RuntimeException('string zero is not a valid fix'); }
echo "11 GPS health/invalid-fix checks passed\n";
$p=['t'=>$now*1000,'lat'=>23.1,'lng'=>72.6,'acc'=>8];
foreach ([['accepted',$p,$now-1],['poor_accuracy',array_replace($p,['acc'=>1200]),$now-1],['old_fix',$p,$now],['stale_report',array_replace($p,['t'=>($now-300)*1000]),$now-400],['invalid_fix',array_replace($p,['lat'=>0]),0]] as [$expected,$point,$last]) {
 $got=GpsTrackers::fixHealth([$point],$last,$now);
 if($got['reason']!==$expected){throw new RuntimeException('health '.$expected.': '.$got['reason']);}
 foreach(['lat','lng','device_uid','key'] as $secret){if(array_key_exists($secret,$got)){throw new RuntimeException('Health metadata leaked '.$secret);}}
}
echo "5 payload-free GPS quality checks passed\n";

<?php
declare(strict_types=1);require __DIR__.'/_init.php';require_once INCLUDE_PATH.'/staffapplications.php';$custom=Settings::getArray('team_staff_positions',[]);$out=[];foreach(StaffApps::roles() as $key){$out[]=['key'=>$key,'label'=>StaffApps::roleLabel($key),'required'=>$custom[$key]['required']??[]];}Response::json(['ok'=>true,'positions'=>$out]);

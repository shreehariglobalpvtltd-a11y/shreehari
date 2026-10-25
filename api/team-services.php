<?php
declare(strict_types=1);require __DIR__.'/_init.php';require_once INCLUDE_PATH.'/teamregistry.php';require_once INCLUDE_PATH.'/teamservices.php';
try{TeamRegistry::actor();if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){Response::json(['ok'=>true,'readiness'=>TeamServices::readiness()]);}Security::requireCsrf();TeamServices::submit((array)Response::input());}catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}catch(RuntimeException $e){Response::error($e->getMessage(),503);}

<?php
declare(strict_types=1);
if(!defined('SHG_APP')){http_response_code(403);exit;}
/** A provider adapter must implement these operations and verified webhook reconciliation before activation. */
interface TeamServiceProvider {
 public function quoteRecharge(string $country,string $operator,string $mobile,int $minorUnits): array;
 public function submitRecharge(string $quoteId,string $idempotencyKey): array;
 public function preparePartnerTransfer(array $verifiedParties,int $minorUnits,string $idempotencyKey): array;
 public function lookup(string $providerReference): array;
 public function verifyWebhook(string $raw,string $signature): array;
}
final class TeamServices {
 public static function validate(array $in): array {
  $country=(string)($in['country']??'');$service=(string)($in['service']??'');$amount=$in['amount_minor']??null;
  if(!in_array($country,['IN','NP'],true)||!in_array($service,['recharge','partner_transfer'],true)||!is_numeric($amount)||(int)$amount<=0||(string)(int)$amount!==(string)$amount){throw new InvalidArgumentException('Country, service and a positive integer amount in minor units are required.');}
  if($service==='partner_transfer'&&$country!=='NP'){throw new InvalidArgumentException('Partner transfer preparation is limited to Nepal.');}
  return ['country'=>$country,'service'=>$service,'amount_minor'=>(int)$amount,'enabled'=>false];
 }
 public static function readiness(): array { return ['recharge_IN'=>false,'recharge_NP'=>false,'partner_transfer_NP'=>false,'reason'=>'No production provider adapter, verified credentials, approved partner contract or reconciliation callback is configured.']; }
 public static function submit(array $input): never { self::validate($input); throw new RuntimeException('Service transactions are disabled pending verified provider and partner setup. No wallet funds were debited.'); }
}

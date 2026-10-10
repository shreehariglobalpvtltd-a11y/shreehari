<?php
/** WhatsApp evidence and click-to-chat contract. No DB and no real sends. */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/wadelivery.php';
$failed = 0;
$checks = [
  'No log never means delivered' => WaDelivery::status(null) === 'NOT SENT / NO LOG',
  'Meta accepted is not delivered' => WaDelivery::status(['status'=>'sent','provider'=>'cloud_api']) === 'ACCEPTED (UNVERIFIED)',
  'Only a delivered callback means delivered' => WaDelivery::status(['status'=>'sent','delivery_state'=>'delivered']) === 'DELIVERED',
  'Read callback is distinct' => WaDelivery::status(['status'=>'sent','delivery_state'=>'read']) === 'READ',
  'Failed callback is distinct' => WaDelivery::status(['status'=>'failed']) === 'FAILED',
  'Manual handover is not provider proof' => WaDelivery::status(['status'=>'skipped','provider'=>'manual']) === 'MANUAL (UNVERIFIED)',
  'Explicit Indian country' => WaDelivery::mobile('9876543210','91') === '919876543210',
  'Explicit Nepali country' => WaDelivery::mobile('9812345678','977') === '9779812345678',
  'Ambiguous local number blocked' => WaDelivery::mobile('9876543210','') === '',
  'Invalid number blocked' => WaDelivery::mobile('12345','91') === '',
];
foreach ($checks as $name => $ok) {
  echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
  if (!$ok) { $failed++; }
}
exit($failed ? 1 : 0);

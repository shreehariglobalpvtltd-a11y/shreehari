<?php
/**
 * Smart saver regression guard: code-only, no database and no provider calls.
 * Run: php tests/whatsapp-saver-static-test.php
 */
declare(strict_types=1);
$src = file_get_contents(dirname(__DIR__) . '/cron/whatsapp-retry.php');
if (!is_string($src)) { fwrite(STDERR, "Unable to read retry job\n"); exit(1); }
$checks = [
  'retry disabled unless explicitly enabled' => "Settings::getBool('wa_auto_retry_enabled', false)",
  'cutover date required' => "wa_auto_retry_cutover_at",
  'original booking after cutover' => "b.created_at >= :cutover",
  'failure row after cutover' => "m.created_at >= :cutover2",
  'recent messages only' => "NOW() - INTERVAL 60 MINUTE",
  'callback grace window' => "NOW() - INTERVAL 10 MINUTE",
  'one retry maximum' => "const MAX_TRIES  = 2;",
  'small batch' => "const BATCH_SIZE = 5;",
  'worker lock' => "GET_LOCK('shg_wa_retry_saver'",
  'transient throttling only' => "(code 130429)",
  'transient service issue only' => "(code 131016)",
  'guard against stale billable failures' => "wa_meta.billing_ok_at",
];
$failed = 0;
foreach ($checks as $label => $needle) {
  $ok = str_contains($src, $needle);
  echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
  if (!$ok) { $failed++; }
}
exit($failed ? 1 : 0);

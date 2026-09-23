<?php
/**
 * =====================================================================
 *  complaints-test.php — the Help bot's complaint desk (23 Sep 2026).
 *
 *      php -c .claude/php-dev.ini tests/complaints-test.php
 *
 *  Files a complaint through Complaints::file() with the click-to-chat
 *  driver (no network), checks the ticket shape, the office text, the
 *  wa.me fallback, the status ladder and the switch; deletes its rows.
 *  Needs database/upgrade-2026-09-23-complaints.sql applied.
 * ===================================================================== */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/complaints.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok   {$l}\n"; }
    else     { $FAIL++; echo "  FAIL {$l}" . ($extra !== '' ? "  ({$extra})" : '') . "\n"; }
}

echo "\n=== Complaint desk ===\n\n";
try { Database::query('SELECT 1 FROM complaints LIMIT 1'); }
catch (Throwable $e) { echo "  complaints table missing - apply database/upgrade-2026-09-23-complaints.sql\n"; exit(1); }

/* run with the click-to-chat driver so nothing leaves the machine */
$driverBefore = Settings::getString('whatsapp_driver', 'click_to_chat');
$onBefore     = Settings::getString('complaints_on', '0');
$numBefore    = Settings::getString('complaint_whatsapp', '');
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'whatsapp', false);
Settings::set('complaint_whatsapp', '918735881507', 'string', 'support', true);
Settings::flush();

$phone = '98' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$ids = [];
try {
    echo "A. filing\n";
    $out = Complaints::file(['name' => 'Test Yatri', 'phone' => $phone, 'pnr' => 'NOT-A-PNR', 'category' => 'luggage', 'message' => 'Bag came late at Rupaidiha', 'lang' => 'ne'], '127.0.0.1', 'test');
    $ids[] = (int) $out['id'];
    check('ticket looks like SHG-C-yymmdd-XXXX', (bool) preg_match('/^SHG-C-\d{6}-[A-Z0-9]{4,5}$/', $out['ticketId']), $out['ticketId']);
    check('click-to-chat driver -> not sent, wa.me link offered', $out['waSent'] === false && is_string($out['waLink']) && str_contains($out['waLink'], 'wa.me/918735881507'));
    check('office text carries ticket, name, category, message', str_contains($out['message'], $out['ticketId']) && str_contains($out['message'], 'Test Yatri') && str_contains($out['message'], 'Luggage') && str_contains($out['message'], 'Bag came late'));
    $row = Database::fetch('SELECT * FROM complaints WHERE id = :id', ['id' => $ids[0]]);
    check('row stored open, invalid PNR dropped, lang kept', $row !== null && $row['status'] === 'open' && $row['pnr'] === null && $row['lang'] === 'ne');

    echo "B. validation\n";
    $bad = '';
    try { Complaints::file(['phone' => '123', 'message' => 'x']); } catch (InvalidArgumentException $e) { $bad = $e->getMessage(); }
    check('a bad phone is refused by name', $bad === 'phone');
    $bad = '';
    try { Complaints::file(['phone' => $phone, 'message' => '   ']); } catch (InvalidArgumentException $e) { $bad = $e->getMessage(); }
    check('an empty message is refused by name', $bad === 'message');
    $out2 = Complaints::file(['phone' => $phone, 'category' => 'not-a-category', 'message' => 'AC off', 'lang' => 'xx']);
    $ids[] = (int) $out2['id'];
    $row2 = Database::fetch('SELECT * FROM complaints WHERE id = :id', ['id' => $ids[1]]);
    check('unknown category -> other, unknown lang -> en, blank name -> Passenger', $row2['category'] === 'other' && $row2['lang'] === 'en' && $row2['name'] === 'Passenger');
    check('two tickets never collide', $out['ticketId'] !== $out2['ticketId']);

    echo "C. status ladder\n";
    check('open -> in_progress with a note', Complaints::setStatus($ids[0], 'in_progress', 'called, waiting for bag tag', 1)
        && Database::scalar('SELECT status FROM complaints WHERE id = :id', ['id' => $ids[0]]) === 'in_progress');
    check('in_progress -> resolved stamps resolved_at', Complaints::setStatus($ids[0], 'resolved', null, 1)
        && Database::scalar('SELECT resolved_at FROM complaints WHERE id = :id', ['id' => $ids[0]]) !== null);
    check('reopen clears resolved_at', Complaints::setStatus($ids[0], 'open', null, 1)
        && Database::scalar('SELECT resolved_at FROM complaints WHERE id = :id', ['id' => $ids[0]]) === null);
    check('an unknown status is refused', Complaints::setStatus($ids[0], 'closed', null, 1) === false);

    echo "D. the switch\n";
    Settings::set('complaints_on', '0', 'bool', 'support', true); Settings::flush();
    check('off by default -> enabled() false', Complaints::enabled() === false);
    Settings::set('complaints_on', '1', 'bool', 'support', true); Settings::flush();
    check('on -> enabled() true', Complaints::enabled() === true);
} finally {
    foreach ($ids as $id) { Database::delete('complaints', 'id = :id', ['id' => $id]); }
    Settings::set('whatsapp_driver', $driverBefore, 'string', 'whatsapp', false);
    Settings::set('complaints_on', $onBefore, 'bool', 'support', true);
    Settings::set('complaint_whatsapp', $numBefore, 'string', 'support', true);
    Settings::flush();
}

echo "\n----------------------------------------\nPASSED: {$PASS}   FAILED: {$FAIL}\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);

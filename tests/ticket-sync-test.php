<?php
/**
 * TicketSync — ONE ticket-regeneration hook for every booking change
 * (owner requirement R3f / R3e, 1 Oct 2026). CLI only, DB-backed.
 *
 *   php tests/ticket-sync-test.php
 *
 * Locks in:
 *   • inside a transaction the job is DEFERRED to Database::afterCommit():
 *     a rollback leaves reissue_count and the rendered PNG untouched; a
 *     deadlock retry runs it once, not twice; the commit path bumps REV by
 *     exactly one and redraws the PNG at once;
 *   • a silent refresh (cash collected, agent credited at sale) keeps REV but
 *     still gives the picture a NEW link;
 *   • the imageUrl() memo is dropped when the same request re-mints;
 *   • void → tickets.is_void = 1, files gone; reconfirm lifts it;
 *   • cancel() / reject() void the ticket; confirm() after reject re-renders;
 *   • the "your ticket changed" notice is written with purpose ticket_update
 *     even when an earlier purpose=ticket row is 'sent' (the guard that used
 *     to swallow it), the manual resend with ticket_resend, and the guard
 *     still protects the FIRST ticket;
 *   • a counter sale carries boarding / drop / ID on the rows BEFORE the
 *     ticket is issued;
 *   • no file outside includes/ticketsync.php calls Ticket::reissue().
 *
 * Throwaway rows (SHG-TEST-) on a far-future date; cleans up after itself.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/ticketsync.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const TD = '2099-12-05';

function revOf(int $bid): int { return (int) Database::scalar('SELECT reissue_count FROM tickets WHERE booking_id = :b', ['b' => $bid], -1); }
function tk(int $bid): array { return Database::fetch('SELECT * FROM tickets WHERE booking_id = :b', ['b' => $bid]) ?? []; }
function pngOf(string $pnr): string { return TICKET_PATH . '/ticket_' . $pnr . '.png'; }
function pdfOf(string $pnr): string { return TICKET_PATH . '/ticket_' . $pnr . '.pdf'; }
function lastAudit(string $action, string $pnr): ?array {
    return Database::fetch("SELECT * FROM audit_logs WHERE action = :a AND entity_id = :p ORDER BY id DESC LIMIT 1", ['a' => $action, 'p' => $pnr]);
}

function cleanupAll(): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE pnr LIKE 'SHG-TEST-%' OR contact_phone LIKE '91900000009%'") as $bk) {
        Database::delete('message_logs', 'booking_id = :b', ['b' => (int) $bk['id']]);
        Database::delete('audit_logs', "entity_type = 'booking' AND entity_id = :p", ['p' => (string) $bk['pnr']]);
        @unlink(pngOf((string) $bk['pnr'])); @unlink(pdfOf((string) $bk['pnr']));
        if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $bk['pnr'] . '.pdf'); }
        Database::delete('bookings', 'id = :i', ['i' => (int) $bk['id']]);   // cascade: legs/seats/pax/payments/tickets
    }
    foreach (pluck(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => TD]), 'id') as $sid) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $sid]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
}

/** Confirmed booking on $sid with $seat + an issued ticket. */
function mkBooking(int $sid, string $coach, string $seat, string $gender, string $phone): array {
    return Database::transaction(function () use ($sid, $coach, $seat, $gender, $phone): array {
        Seats::assertAvailable($sid, [$seat], 't' . bin2hex(random_bytes(3)));
        Seats::assertGenderAllowed($sid, $coach, 'sharing', [$seat], [$gender]);
        $pnr = 'SHG-TEST-' . strtoupper(bin2hex(random_bytes(4)));
        $bid = Database::insert('bookings', [
            'pnr' => $pnr, 'trip_type' => 'oneway', 'booking_mode' => 'sharing',
            'contact_phone' => $phone, 'fare_per_seat' => 1800, 'base_total' => 1800,
            'total_amount' => 1800, 'currency' => 'INR', 'status' => 'confirmed',
            'confirmed_at' => date('Y-m-d H:i:s'), 'source' => 'counter',
        ]);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => TD, 'fare_per_seat' => 1800, 'seat_count' => 1, 'leg_total' => 1800]);
        Seats::claim($sid, [$seat], $bid, $lid);
        Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid, 'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat, 'full_name' => 'Sync ' . $seat, 'gender' => $gender, 'is_primary' => 1]);
        Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'PAY' . strtoupper(bin2hex(random_bytes(5))), 'method' => 'cash', 'mode' => 'offline', 'amount' => 1800, 'currency' => 'INR', 'status' => 'verified']);
        Ticket::issue($bid);
        return ['id' => $bid, 'pnr' => $pnr];
    });
}

echo "\n=== TicketSync — one hook for every booking change ===\n\n";

// Pin the pre-L5/L6 staff layout (fixture uses cabin L-2 back) and a sender
// that cannot reach anyone (click-to-chat), so every notice is LOGGED, never sent.
Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);
$prev = [];
foreach (['whatsapp_driver' => 'click_to_chat', 'notify_ticket_edit' => '1', 'whatsapp_notify_customer' => '1',
          'twilio_content_sid' => '', 'whatsapp_template_name' => '', 'whatsapp_template_name_gupshup' => ''] as $k => $v) {
    $prev[$k] = Settings::getString($k, '');
    Settings::set($k, $v, in_array($k, ['notify_ticket_edit', 'whatsapp_notify_customer'], true) ? 'bool' : 'string', 'notify', false);
}
Settings::flush();

try {
    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $rid = (int) $route['id']; $coach = (string) $route['coach_type'];
    cleanupAll();
    $sid = (int) Seats::schedule($rid, TD)['id'];

    /* ------------------------------------------------------------------
     * 1. Deferred inside a transaction; rollback leaves the ticket alone
     * ---------------------------------------------------------------- */
    echo "-- deferred in a transaction, discarded on rollback\n";
    $A = mkBooking($sid, $coach, 'L3', 'Female', '919000000091');
    $bidA = (int) $A['id']; $pnrA = (string) $A['pnr'];
    Ticket::pngPath($bidA, true);
    check('setup: ticket issued, REV 0, PNG rendered', revOf($bidA) === 0 && is_file(pngOf($pnrA)));
    $payloadBefore = (string) tk($bidA)['qr_payload'];
    $mtimeBefore   = filemtime(pngOf($pnrA));
    $deferred = null; $pendingInside = -1;
    try {
        Database::transaction(function () use ($bidA, &$deferred, &$pendingInside): void {
            $deferred      = TicketSync::afterBookingChange($bidA, 'passenger', ['name' => 'X'], ['notify' => false]);
            $pendingInside = Database::pendingAfterCommit();
            throw new RuntimeException('abort the edit');
        });
    } catch (RuntimeException $e) { /* expected */ }
    check('inside a transaction the call returns deferred=true', ($deferred['deferred'] ?? false) === true);
    check('the job was queued on afterCommit', $pendingInside === 1, (string) $pendingInside);
    check('rollback discarded the queue', Database::pendingAfterCommit() === 0);
    clearstatcache();
    check('rollback: reissue_count unchanged', revOf($bidA) === 0);
    check('rollback: QR payload unchanged', (string) tk($bidA)['qr_payload'] === $payloadBefore);
    check('rollback: rendered PNG still present and untouched', is_file(pngOf($pnrA)) && filemtime(pngOf($pnrA)) === $mtimeBefore);
    check('rollback: no ticket.regenerated audit row', lastAudit('ticket.regenerated', $pnrA) === null);

    /* ------------------------------------------------------------------
     * 2. Commit path: exactly +1, PNG redrawn, one audit row, new link
     * ---------------------------------------------------------------- */
    echo "\n-- commit path\n";
    $urlBefore = Ticket::imageUrl($pnrA);
    Database::transaction(function () use ($bidA): void {
        TicketSync::afterBookingChange($bidA, 'passenger', ['name' => 'Y'], ['notify' => false, 'reason' => 'spelling']);
        Database::update('booking_passengers', ['full_name' => 'Sync Y'], 'booking_id = :b', ['b' => $bidA]);
    });
    check('commit: reissue_count is exactly 1', revOf($bidA) === 1, (string) revOf($bidA));
    // A name is not part of the signed payload, so the payload stays equal here;
    // the re-mint shows in reissued_at (the link's version tag reads it).
    check('commit: re-minted (reissued_at stamped)', !empty(tk($bidA)['reissued_at']));
    check('commit: PNG redrawn at once (png_path set, file exists)', (string) (tk($bidA)['png_path'] ?? '') !== '' && is_file(pngOf($pnrA)));
    check('commit: PDF stays lazy (no file until opened)', !is_file(pdfOf($pnrA)));
    $au = lastAudit('ticket.regenerated', $pnrA);
    check('commit: ONE ticket.regenerated audit row with kind + REV 0→1 + reason',
        $au !== null && str_contains((string) $au['new_value'], '"kind":"passenger"') && str_contains((string) $au['new_value'], '"rev":1')
        && str_contains((string) $au['old_value'], '"rev":0') && (string) ($au['reason'] ?? '') === 'spelling'
        && (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'ticket.regenerated' AND entity_id = :p", ['p' => $pnrA], 0) === 1);
    check('commit: no separate ticket.reissue audit row any more', lastAudit('ticket.reissue', $pnrA) === null);
    check('commit: the image link changed (revision + version tag)', Ticket::imageUrl($pnrA) !== $urlBefore && str_contains(Ticket::imageUrl($pnrA), '&r=1&v='));
    $last = TicketSync::last($bidA);
    check('journal: last() returns the completed run for this booking', $last !== null && ($last['rev'] ?? null) === 1 && ($last['oldRev'] ?? null) === 0 && empty($last['notifyAttempted']));

    /* ------------------------------------------------------------------
     * 3. Deadlock retry: queued work from the failed attempt never runs
     * ---------------------------------------------------------------- */
    echo "\n-- deadlock retry runs the hook once\n";
    $attempt = 0;
    Database::transaction(function () use ($bidA, &$attempt): void {
        $attempt++;
        TicketSync::afterBookingChange($bidA, 'contact', [], ['notify' => false]);
        if ($attempt === 1) {
            $e = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
            $e->errorInfo = ['40001', 1213, 'Deadlock'];
            throw $e;
        }
    }, 3);
    check('the closure ran twice (one retry)', $attempt === 2, (string) $attempt);
    check('REV bumped once, not twice (1 → 2)', revOf($bidA) === 2, (string) revOf($bidA));

    /* ------------------------------------------------------------------
     * 4. Silent refresh: REV unchanged, link still changes, memo busted
     * ---------------------------------------------------------------- */
    echo "\n-- silent refresh (cash collected / agent credited at sale)\n";
    $url2 = Ticket::imageUrl($pnrA);
    $hashBefore = (string) tk($bidA)['qr_hash'];
    sleep(1);   // reissued_at has second resolution; the tag is built from it
    TicketSync::afterBookingChange($bidA, 'paid', [], ['silent' => true, 'notify' => false]);
    check('silent: reissue_count unchanged (2)', revOf($bidA) === 2);
    check('silent: files redrawn (PNG present)', is_file(pngOf($pnrA)));
    check('silent: the link still changed (memo busted in the same request)', Ticket::imageUrl($pnrA) !== $url2);
    check('silent: a ticket.regenerated row says silent refresh', str_contains((string) (lastAudit('ticket.regenerated', $pnrA)['new_value'] ?? ''), '"silent":true'));

    /* ------------------------------------------------------------------
     * 5. Notify purposes and the first-ticket guard
     * ---------------------------------------------------------------- */
    echo "\n-- change notice is written with its own purpose; the first-ticket guard stays\n";
    Database::insert('message_logs', ['booking_id' => $bidA, 'channel' => 'whatsapp', 'provider' => 'twilio', 'to_number' => '919000000091',
        'body' => 'earlier ticket', 'status' => 'sent', 'provider_ref' => 'SM-PRIOR', 'purpose' => 'ticket']);
    $r = TicketSync::afterBookingChange($bidA, 'seat', ['old' => 'LA2', 'new' => 'LA4'], ['reason' => 'reseat']);
    check('seat kind attempts the passenger notice by default', !empty($r['notifyAttempted']));
    check('its outcome is honest: not sent (click-to-chat has no API), never "suppressed"', empty($r['notified'])
        && !str_contains((string) ($r['notifyDetail'] ?? ''), 'suppressed') && (string) ($r['link'] ?? '') !== '', (string) ($r['notifyDetail'] ?? ''));
    $upd = Database::fetch("SELECT status, purpose FROM message_logs WHERE booking_id = :b AND purpose = 'ticket_update' ORDER BY id DESC LIMIT 1", ['b' => $bidA]);
    check('a message_logs row with purpose=ticket_update was written despite the prior sent purpose=ticket row', $upd !== null, json_encode($upd));
    check('the audit row records notify_attempted + notified=false', str_contains((string) (lastAudit('ticket.regenerated', $pnrA)['new_value'] ?? ''), '"notified":false'));
    $detail = BookingService::detail($pnrA);
    $rs = Notify::resendTicketWhatsApp($detail);
    check('manual resend is not swallowed either (ok=false for a missing API, with a link)', ($rs['ok'] ?? null) === false && !str_contains((string) $rs['detail'], 'suppressed') && !empty($rs['link']), (string) $rs['detail']);
    check('…and logs purpose=ticket_resend', Database::exists("SELECT 1 FROM message_logs WHERE booking_id = :b AND purpose = 'ticket_resend'", ['b' => $bidA]));
    $nBefore = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b", ['b' => $bidA], 0);
    $g = Notify::whatsapp('+919000000091', 'duplicate first ticket', null, 'IN', [], $bidA, ['purpose' => 'ticket']);
    check('the FIRST-ticket guard still short-circuits a duplicate confirmation', $g === true
        && (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b", ['b' => $bidA], 0) === $nBefore);
    $cnt = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' AND purpose = 'ticket_update'", ['b' => $bidA], 0);
    TicketSync::afterBookingChange($bidA, 'payment', [], []);
    check('payment kind does not message the passenger by default', (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' AND purpose = 'ticket_update'", ['b' => $bidA], 0) === $cnt);
    TicketSync::afterBookingChange($bidA, 'payment', [], ['notify' => true]);
    check('…unless notify=true is passed', (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b AND channel = 'whatsapp' AND purpose = 'ticket_update'", ['b' => $bidA], 0) === $cnt + 1);
    $revNow = revOf($bidA);
    Settings::set('notify_ticket_edit', '0', 'bool', 'notify', false); Settings::flush();
    $r0 = TicketSync::afterBookingChange($bidA, 'seat', ['old' => 'LA4', 'new' => 'LA2']);
    check('notify_ticket_edit=0 regenerates but sends nothing', revOf($bidA) === $revNow + 1 && empty($r0['notifyAttempted']));
    Settings::set('notify_ticket_edit', '1', 'bool', 'notify', false); Settings::flush();

    /* ------------------------------------------------------------------
     * 6. void / reconfirm
     * ---------------------------------------------------------------- */
    echo "\n-- void and reconfirm\n";
    Ticket::pdfPath($bidA, true);
    check('setup: PNG + PDF on disk', is_file(pngOf($pnrA)) && is_file(pdfOf($pnrA)));
    $rv = TicketSync::afterBookingChange($bidA, 'void', [], ['reason' => 'test void']);
    $t = tk($bidA);
    check('void: tickets.is_void = 1', (int) ($t['is_void'] ?? 0) === 1);
    check('void: PNG and PDF removed, path columns null', !is_file(pngOf($pnrA)) && !is_file(pdfOf($pnrA)) && $t['png_path'] === null && $t['pdf_path'] === null);
    check('void: REV not bumped, no regeneration', (int) $t['reissue_count'] === $revNow + 1 && ($rv['voided'] ?? false) === true);
    check('void: audited as ticket.voided with the reason', (string) (lastAudit('ticket.voided', $pnrA)['reason'] ?? '') === 'test void');
    $revV = revOf($bidA);
    TicketSync::afterBookingChange($bidA, 'reconfirm', [], ['silent' => true, 'notify' => false]);
    $t = tk($bidA);
    check('reconfirm: is_void back to 0, PNG re-rendered, REV unchanged', (int) $t['is_void'] === 0 && is_file(pngOf($pnrA)) && (int) $t['reissue_count'] === $revV);

    /* ------------------------------------------------------------------
     * 7. The engine paths: cancel() voids; reject() voids; confirm() re-renders
     * ---------------------------------------------------------------- */
    echo "\n-- cancel / reject / confirm through BookingService\n";
    $B = mkBooking($sid, $coach, 'L7', 'Male', '919000000092');
    $bidB = (int) $B['id']; $pnrB = (string) $B['pnr'];
    Ticket::pngPath($bidB, true);
    $adminId = (int) Database::scalar('SELECT id FROM admins ORDER BY id LIMIT 1', [], 0);
    BookingService::reject($bidB, $adminId, 'proof unreadable');
    $t = tk($bidB);
    check('reject(): ticket voided + file gone', (int) $t['is_void'] === 1 && !is_file(pngOf($pnrB)));
    BookingService::confirm($bidB, $adminId, 're-checked');
    $t = tk($bidB);
    check('confirm() after reject: void lifted, PNG re-rendered from live rows, REV not counted', (int) $t['is_void'] === 0 && is_file(pngOf($pnrB)) && (int) $t['reissue_count'] === 0, json_encode(['void' => $t['is_void'], 'rev' => $t['reissue_count'], 'png' => is_file(pngOf($pnrB))]));
    $res = BookingService::cancel($pnrB, 'plans changed', false);
    $t = tk($bidB);
    check('cancel(): ticket voided, files removed', (int) $t['is_void'] === 1 && !is_file(pngOf($pnrB)) && $t['png_path'] === null);
    check('cancel(): booking.cancel audit carries the reason in the reason column', (string) (lastAudit('booking.cancel', $pnrB)['reason'] ?? '') === 'plans changed');

    /* ------------------------------------------------------------------
     * 8. Counter sale: boarding / drop / ID on the rows before the ticket
     * ---------------------------------------------------------------- */
    echo "\n-- counter sale carries stops + ID before Ticket::issue()\n";
    $open = Boarding::openStops($rid, TD);
    $town = $open !== [] ? trim((string) preg_split('/[—–\-·|,(@]/u', (string) $open[0]['name'], 2)[0]) : '';
    $cs = BookingService::counterSale($route, $sid, TD, ['U7'], [
        'name' => 'Counter Sync', 'phone' => '919000000093', 'gender' => 'Male', 'paymentMethod' => 'cash',
        'boarding' => $town, 'drop' => 'Rupaidiha Border', 'idType' => 'Aadhaar', 'idNum' => '1234 5678 9012',
    ], $adminId, 'counter');
    $leg = Database::fetch("SELECT boarding_stop, drop_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $cs['id']]);
    check('counterSale: drop_stop stored with the sale', (string) ($leg['drop_stop'] ?? '') === 'Rupaidiha Border', json_encode($leg));
    check('counterSale: boarding_stop resolved to the configured stop', $town === '' || str_contains(Boarding::townKey((string) $leg['boarding_stop']), Boarding::townKey($town)), (string) ($leg['boarding_stop'] ?? ''));
    check('counterSale: booking carries the contact ID', (string) $cs['id_type'] === 'Aadhaar' && (string) $cs['id_number'] === '1234 5678 9012');
    check('counterSale: the primary passenger inherits the ID', (string) Database::scalar('SELECT id_type FROM booking_passengers WHERE booking_id = :b AND is_primary = 1', ['b' => (int) $cs['id']], '') === 'Aadhaar');
    $tc = tk((int) $cs['id']);
    check('counterSale: ticket issued in the same sale (no post-sale amendment needed)', $tc !== [] && (int) $tc['reissue_count'] === 0);
    check('counterSale: the sale audit names the drop + ID', str_contains((string) (lastAudit('booking.counter', (string) $cs['pnr'])['new_value'] ?? ''), 'Rupaidiha Border'));
    check('counterSale: no booking.counter_details amendment row', lastAudit('booking.counter_details', (string) $cs['pnr']) === null);

    /* ------------------------------------------------------------------
     * 9. Source guard: the engine is the only caller of Ticket::reissue()
     * ---------------------------------------------------------------- */
    echo "\n-- source guard\n";
    $root = dirname(__DIR__);
    $offenders = [];
    foreach (['includes', 'admin', 'api', 'cron', 'whatsapp'] as $dir) {
        if (!is_dir($root . '/' . $dir)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $f) {
            if ($f->getExtension() !== 'php' || str_ends_with($f->getFilename(), 'ticketsync.php')) { continue; }
            $src = (string) file_get_contents((string) $f);
            if (preg_match('/^(?!\s*(\*|\/\/|#)).*Ticket::reissue\(/m', $src)) { $offenders[] = substr((string) $f, strlen($root) + 1); }
        }
    }
    check('no file outside ticketsync.php calls Ticket::reissue()', $offenders === [], implode(', ', $offenders));
    $bv = (string) file_get_contents($root . '/admin/booking-view.php');
    check('booking-view.php no longer hand-rolls the cache drop or a second reissue', !str_contains($bv, 'function bv_invalidate_pdfs') && !str_contains($bv, 'function bv_regen_ticket') && !str_contains($bv, 'function bv_notify_edit'));
    check('sw.js drops the saved copy on 403/410', str_contains((string) file_get_contents($root . '/sw.js'), 'res.status === 403 || res.status === 410'));
    check('verify-ticket.php honours is_void', str_contains((string) file_get_contents($root . '/verify-ticket.php'), "['ticket']['is_void']"));
    check('download-ticket.php answers 410 for a voided ticket', str_contains((string) file_get_contents($root . '/download-ticket.php'), 'http_response_code(410)'));
    check('whatsapp-retry.php knows the new purposes', str_contains((string) file_get_contents($root . '/cron/whatsapp-retry.php'), "'ticket','ticket_update','ticket_resend'"));
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanupAll();
    Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
    foreach ($prev as $k => $v) {
        Settings::set($k, $v, in_array($k, ['notify_ticket_edit', 'whatsapp_notify_customer'], true) ? 'bool' : 'string', 'notify', false);
    }
    Settings::flush();
}

echo "\n----------------------------------------\n  $PASS passed, $FAIL failed\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);

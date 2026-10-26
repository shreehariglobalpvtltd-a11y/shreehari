<?php
/**
 * Passenger photo / ID documents — PassengerDocs (17 Sep 2026).
 *
 * Proves the document store's rules without a browser:
 *  1. the passenger_documents table exists (the migration is applied here
 *     when it is missing, so a fresh test database self-heals);
 *  2. record() writes one row + one audit line per file, keyed on the
 *     passenger, with the size and sha256 of the stored bytes;
 *  3. listFor() groups by passenger; get() carries the booking's pnr and
 *     sold_by_admin_id (the reader page's scope key); absolutePath()
 *     refuses anything outside uploads/passengers/;
 *  4. a counter agent is refused on a booking they did not sell — on
 *     attach() AND remove() — and allowed on their own;
 *  5. remove() deletes the file and the row and writes an audit line;
 *  6. deleting the booking cascades the document rows away;
 *  7. enabled() follows the office switch settings.passenger_docs_on.
 *
 * CLI LIMITATION — attach() cannot complete here. It runs
 * Security::validateUpload(), which calls is_uploaded_file(), and that is
 * false for any file PHP did not receive over HTTP. So attach() is driven
 * only up to that gate (kind check, passenger check, agent scope — all of
 * which sit BEFORE the upload check, so a refused upload never touches the
 * disk) and its "could not be verified" refusal is asserted. The store step
 * is exercised through PassengerDocs::record(), which attach() calls after
 * move_uploaded_file() and which is the single writer of the row + audit
 * line. The browser path (multipart form on the ticket page → attach()) is
 * checked by hand — see the workstream report.
 *
 *   php -c .claude/php-dev.ini tests/passenger-docs-test.php
 * Throwaway rows (SHG-PDOC-*, testpdoc-agent-*) on a far-future date;
 * cleans up after itself, including the staged files. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/passengerdocs.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; } }
function expectThrow(string $l, callable $fn): void { try { $fn(); check($l . ' (expected rejection)', false); } catch (Throwable $e) { check($l . ' → "' . $e->getMessage() . '"', true); } }
/** Run $fn and hand back the exception message ('' when it did not throw). */
function thrown(callable $fn): string { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } }

const TD      = '2099-12-05';
const AGENT_A = 'testpdoc-agent-a';
const AGENT_B = 'testpdoc-agent-b';
const PHONE   = '919800000011';

/** Stand up an admin session the way agent-isolation-test.php does (Auth reads $_SESSION). */
function asAdmin(int $id, string $role, string $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $id, 'username' => $user, 'full_name' => $user,
        'role' => $role, 'permissions' => [], 'must_change_pw' => false,
        'logged_in_at' => time(), 'last_seen' => time(),
    ];
}

/** A small PNG on disk — GD when present, else a byte-exact 1x1 PNG. */
function makePng(string $path): void
{
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        $im = imagecreatetruecolor(24, 24);
        imagefilledrectangle($im, 0, 0, 23, 23, imagecolorallocate($im, 30, 90, 160));
        imagepng($im, $path);
        imagedestroy($im);
        return;
    }
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

/** Copy the PNG under uploads/passengers/YYYY/MM/ the way attach() lays files out; returns the relative path. */
function stageFile(string $png): string
{
    $ym  = date('Y') . '/' . date('m');
    $dir = UPLOAD_PATH . '/passengers/' . $ym;
    if (!ensureDir($dir)) { throw new RuntimeException('cannot create ' . $dir); }
    $rel = 'passengers/' . $ym . '/' . Security::safeFilename('png');
    if (!copy($png, UPLOAD_PATH . '/' . $rel)) { throw new RuntimeException('cannot stage ' . $rel); }
    return $rel;
}

/** Build a confirmed booking on $sid with $seats (one passenger each), sold by $seller (null = office). */
function mkBooking(int $sid, array $seats, ?int $seller): int
{
    return Database::transaction(function () use ($sid, $seats, $seller): int {
        Seats::assertAvailable($sid, $seats, 't' . bin2hex(random_bytes(3)));
        $n   = count($seats);
        $bid = Database::insert('bookings', [
            'pnr' => 'SHG-PDOC-' . strtoupper(bin2hex(random_bytes(4))),
            'trip_type' => 'oneway', 'booking_mode' => 'sharing',
            'contact_phone' => PHONE, 'contact_country_code' => '91',
            'sold_by_admin_id' => $seller,
            'fare_per_seat' => 1500, 'base_total' => 1500 * $n, 'total_amount' => 1500 * $n,
            'currency' => 'INR', 'status' => 'confirmed', 'confirmed_at' => date('Y-m-d H:i:s'),
            'source' => $seller !== null ? 'agent' : 'counter',
        ]);
        $lid = Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => TD, 'fare_per_seat' => 1500, 'seat_count' => $n, 'leg_total' => 1500 * $n]);
        Seats::claim($sid, $seats, $bid, $lid);
        foreach (array_values($seats) as $i => $seat) {
            Database::insert('booking_passengers', ['booking_id' => $bid, 'leg_id' => $lid, 'passenger_ref' => 'PAX' . strtoupper(bin2hex(random_bytes(5))), 'seat_no' => $seat, 'full_name' => 'Pdoc ' . $seat, 'gender' => 'Male', 'age' => 30, 'is_primary' => $i === 0 ? 1 : 0]);
        }
        return $bid;
    });
}

function cleanupBookings(): void
{
    foreach (pluck(Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-PDOC-%'"), 'id') as $id) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $id]);   // FK cascade drops legs/seats/passengers/documents
    }
    Database::delete('audit_logs', "entity_type = 'booking' AND entity_id LIKE 'SHG-PDOC-%'");
}

echo "\n=== Passenger photo / ID documents (PassengerDocs) ===\n\n";

$aId = 0; $bId = 0; $sid = 0; $schedCreated = false; $tmpBase = ''; $tmpPng = ''; $staged = [];
$prevSwitch = Settings::getBool('passenger_docs_on', true);
try {
    /* ---- 1. The table (self-applying the migration on a fresh test DB) ---- */
    if (!PassengerDocs::available(true)) {
        echo "  passenger_documents missing — applying database/upgrade-2026-09-passenger-documents.sql\n";
        $sql = (string) file_get_contents(dirname(__DIR__) . '/database/upgrade-2026-09-passenger-documents.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '') as $stmt) {
            Database::pdo()->exec($stmt);
        }
    }
    check('passenger_documents table available', PassengerDocs::available(true));
    if (!PassengerDocs::available()) { throw new RuntimeException('table still missing — migration failed'); }

    /* ---- Fixtures: two agents, a far-future schedule, two bookings ---- */
    foreach ([[AGENT_A, 'aId'], [AGENT_B, 'bId']] as [$u, $var]) {
        Database::delete('admins', 'username = :u', ['u' => $u]);
        $$var = Database::insert('admins', [
            'username' => $u, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
            'full_name' => 'Pdoc Test ' . strtoupper(substr($u, -1)), 'role' => 'agent', 'is_active' => 1,
        ]);
    }
    $route = Database::fetch("SELECT id, coach_type FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route\n"; exit(1); }
    $rid = (int) $route['id'];
    cleanupBookings();
    // Only tear down a schedule this run brought into being.
    $schedCreated = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $rid, 'd' => TD]) === null;
    $sid  = (int) Seats::schedule($rid, TD)['id'];
    $free = Seats::availability($rid, TD, 'sharing')['available'] ?? [];
    if (count($free) < 3) { echo "need 3 free seats on " . TD . "\n"; exit(1); }

    $bkA = mkBooking($sid, [$free[0], $free[1]], $aId);   // agent A's sale, two passengers
    $bkO = mkBooking($sid, [$free[2]], null);              // office sale, same phone
    $pnrA = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $bkA], '');
    $paxA = Database::fetchAll('SELECT id, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bkA]);
    $paxO = Database::fetchAll('SELECT id, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bkO]);
    check('fixtures: agent booking has 2 passengers, office booking has 1', count($paxA) === 2 && count($paxO) === 1);
    $pA0 = (int) $paxA[0]['id']; $pA1 = (int) $paxA[1]['id']; $pO0 = (int) $paxO[0]['id'];

    /* ---- Static answers ---- */
    check('kinds(): photo / id_front / id_back / other, in that order', array_keys(PassengerDocs::kinds()) === ['photo', 'id_front', 'id_back', 'other']);
    check('label(): id_front → "ID front"; unknown kinds humanised', PassengerDocs::label('id_front') === 'ID front' && PassengerDocs::label('x_y') === 'X y');
    check('maxMb() derives from MAX_UPLOAD_BYTES', PassengerDocs::maxMb() === max(1, (int) round(MAX_UPLOAD_BYTES / 1048576)));

    /* ---- The file + a browser-shaped $_FILES entry ---- */
    $tmpBase = tempnam(sys_get_temp_dir(), 'pdoc');
    $tmpPng  = $tmpBase . '.png';
    makePng($tmpPng);
    check('temp PNG written', is_file($tmpPng) && filesize($tmpPng) > 0);
    $fake = ['name' => 'photo.png', 'type' => 'image/png', 'tmp_name' => $tmpPng, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmpPng)];

    /* ---- 4a. attach() gates that run BEFORE the upload check ---- */
    asAdmin(1, 'superadmin', 'superadmin');
    expectThrow('attach(): unknown kind refused', fn() => PassengerDocs::attach($pA0, $fake, 'selfie', 1));
    expectThrow('attach(): unknown passenger refused', fn() => PassengerDocs::attach(999999999, $fake, 'photo', 1));
    $m = thrown(fn() => PassengerDocs::attach($pA0, $fake, 'photo', 1));
    check('attach(): a file no browser sent stops at the upload gate (CLI limitation — "' . $m . '")', $m !== '' && stripos($m, 'upload') !== false);
    check('…and nothing was written for it', (int) Database::scalar('SELECT COUNT(*) FROM passenger_documents WHERE booking_id = :b', ['b' => $bkA], 0) === 0);

    asAdmin($bId, 'agent', AGENT_B);
    $m = thrown(fn() => PassengerDocs::attach($pA0, $fake, 'photo', $bId));
    check('agent B cannot attach to agent A\'s booking → "' . $m . '"', stripos($m, 'sold') !== false);
    asAdmin($aId, 'agent', AGENT_A);
    $m = thrown(fn() => PassengerDocs::attach($pO0, $fake, 'photo', $aId));
    check('agent A cannot attach to the office booking → "' . $m . '"', stripos($m, 'sold') !== false);
    $m = thrown(fn() => PassengerDocs::attach($pA0, $fake, 'id_front', $aId));
    check('agent A passes the scope gate on their own sale (stops only at the upload gate)', stripos($m, 'sold') === false && stripos($m, 'upload') !== false);
    check('agent-scope refusals never touch the disk', (int) Database::scalar('SELECT COUNT(*) FROM passenger_documents WHERE booking_id IN (:a, :o)', ['a' => $bkA, 'o' => $bkO], 0) === 0);

    /* ---- 2. record(): the store step attach() ends in ---- */
    asAdmin(1, 'superadmin', 'superadmin');
    $rel1 = stageFile($tmpPng); $staged[] = $rel1;
    $rec1 = PassengerDocs::record($pA0, $rel1, 'image/png', 0, 'photo', 1);
    check('record() returns id + kind + pnr + seat', ($rec1['id'] ?? 0) > 0 && $rec1['kind'] === 'photo' && $rec1['pnr'] === $pnrA && $rec1['seat'] === (string) $paxA[0]['seat_no']);
    $rel2 = stageFile($tmpPng); $staged[] = $rel2;
    $rec2 = PassengerDocs::record($pA1, $rel2, 'image/png', 0, 'id_front', 1);
    $rel3 = stageFile($tmpPng); $staged[] = $rel3;
    $rec3 = PassengerDocs::record($pA1, $rel3, 'image/png', 0, 'ID_BACK', 1);   // kind is normalised
    check('record() normalises the kind (ID_BACK → id_back)', $rec3['kind'] === 'id_back');

    $row = Database::fetch('SELECT * FROM passenger_documents WHERE id = :i', ['i' => (int) $rec1['id']]);
    check('row: booking_id + passenger_id + kind + file_path', $row !== null && (int) $row['booking_id'] === $bkA && (int) $row['passenger_id'] === $pA0 && $row['kind'] === 'photo' && $row['file_path'] === $rel1);
    check('row: file_size + sha256 are those of the stored bytes',
        $row !== null && (int) $row['file_size'] === (int) filesize(UPLOAD_PATH . '/' . $rel1) && $row['sha256'] === hash_file('sha256', UPLOAD_PATH . '/' . $rel1));
    check('row: uploaded_by_admin_id + mime kept', $row !== null && (int) $row['uploaded_by_admin_id'] === 1 && $row['mime_type'] === 'image/png');
    check('audit: one passenger.document line per file (3)',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'passenger.document' AND entity_id = :p", ['p' => $pnrA], 0) === 3);
    expectThrow('record(): a path outside uploads/passengers/ is refused', fn() => PassengerDocs::record($pA0, '../config/config.php', 'image/png', 1, 'photo', 1));
    expectThrow('record(): a file that is not on disk is refused', fn() => PassengerDocs::record($pA0, 'passengers/2099/01/nothere.png', 'image/png', 1, 'photo', 1));
    check('refused record() calls wrote no rows', (int) Database::scalar('SELECT COUNT(*) FROM passenger_documents WHERE booking_id = :b', ['b' => $bkA], 0) === 3);

    /* ---- 3. Reads ---- */
    $list = PassengerDocs::listFor($bkA);
    check('listFor(): grouped by passenger — pax0 has 1, pax1 has 2', count($list[$pA0] ?? []) === 1 && count($list[$pA1] ?? []) === 2);
    check('listFor(): kinds in insertion order for pax1', array_map(static fn($d) => $d['kind'], $list[$pA1] ?? []) === ['id_front', 'id_back']);
    check('listFor(): the office booking has none; an unknown id → []', PassengerDocs::listFor($bkO) === [] && PassengerDocs::listFor(0) === []);

    $g = PassengerDocs::get((int) $rec1['id']);
    check('get(): joined pnr + sold_by_admin_id + passenger name / seat',
        $g !== null && $g['pnr'] === $pnrA && (int) $g['sold_by_admin_id'] === $aId && $g['seat_no'] === (string) $paxA[0]['seat_no'] && $g['full_name'] === 'Pdoc ' . $paxA[0]['seat_no']);
    check('get(): unknown id → null', PassengerDocs::get(999999999) === null && PassengerDocs::get(0) === null);
    $abs1 = $g !== null ? PassengerDocs::absolutePath($g) : '';
    check('absolutePath() resolves under UPLOAD_PATH/passengers and the file exists', $abs1 !== '' && str_starts_with($abs1, UPLOAD_PATH . '/passengers/') && is_file($abs1));
    expectThrow('absolutePath() refuses traversal', fn() => PassengerDocs::absolutePath(['file_path' => '../../config/config.php']));
    expectThrow('absolutePath() refuses a path outside passengers/', fn() => PassengerDocs::absolutePath(['file_path' => '2026/09/x.png']));
    check('isImage(): image/png yes, application/pdf no', $g !== null && PassengerDocs::isImage($g) && !PassengerDocs::isImage(['mime_type' => 'application/pdf']));

    check('countForPhone(): 3 documents on this number', PassengerDocs::countForPhone(PHONE) === 3);
    check('countForPhone(): scoped — agent A sees 3, agent B sees 0', PassengerDocs::countForPhone(PHONE, $aId) === 3 && PassengerDocs::countForPhone(PHONE, $bId) === 0);
    check('countForPhone(): unknown number → 0', PassengerDocs::countForPhone('910000000000') === 0);

    /* ---- 4b + 5. remove(): scope, file, row, audit ---- */
    asAdmin($bId, 'agent', AGENT_B);
    $m = thrown(fn() => PassengerDocs::remove((int) $rec1['id'], $bId));
    check('agent B cannot remove agent A\'s document (reads as not found) → "' . $m . '"', stripos($m, 'not found') !== false);
    check('…and the row + file survived', PassengerDocs::get((int) $rec1['id']) !== null && is_file($abs1));

    asAdmin($aId, 'agent', AGENT_A);
    PassengerDocs::remove((int) $rec1['id'], $aId);
    check('agent A removes their own: row gone', PassengerDocs::get((int) $rec1['id']) === null);
    check('…file unlinked', !is_file($abs1));
    check('…audited as passenger.document_remove',
        (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'passenger.document_remove' AND entity_id = :p", ['p' => $pnrA], 0) === 1);
    expectThrow('remove() twice → not found', fn() => PassengerDocs::remove((int) $rec1['id'], $aId));
    check('the other two documents are untouched', count(PassengerDocs::listFor($bkA)[$pA1] ?? []) === 2);

    /* ---- 6. FK cascade ---- */
    asAdmin(1, 'superadmin', 'superadmin');
    Database::delete('bookings', 'id = :i', ['i' => $bkA]);
    check('deleting the booking cascades its document rows away',
        (int) Database::scalar('SELECT COUNT(*) FROM passenger_documents WHERE booking_id = :b', ['b' => $bkA], 0) === 0);
    check('get() on a cascaded row → null', PassengerDocs::get((int) $rec2['id']) === null);

    /* ---- 7. The office switch ---- */
    Settings::set('passenger_docs_on', '0', 'bool', 'booking');
    Settings::flush();
    check('enabled() follows settings.passenger_docs_on (off)', PassengerDocs::enabled() === false);
    Settings::set('passenger_docs_on', '1', 'bool', 'booking');
    Settings::flush();
    check('enabled() follows settings.passenger_docs_on (on)', PassengerDocs::enabled() === true);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    try {
        Settings::set('passenger_docs_on', $prevSwitch ? '1' : '0', 'bool', 'booking');
        Settings::flush();
    } catch (Throwable $e) { /* leave the switch as it is */ }
    cleanupBookings();
    foreach ($staged as $rel) { @unlink(UPLOAD_PATH . '/' . $rel); }
    if ($tmpPng !== '') { @unlink($tmpPng); }
    if ($tmpBase !== '') { @unlink($tmpBase); }
    foreach ([$aId, $bId] as $id) {
        if ($id > 0) {
            Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
            Database::delete('admins', 'id = :a', ['a' => $id]);
        }
    }
    if ($schedCreated && $sid > 0) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('booking_legs', 'schedule_id = :s', ['s' => $sid]);
        Database::delete('schedules', 'id = :s', ['s' => $sid]);
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);

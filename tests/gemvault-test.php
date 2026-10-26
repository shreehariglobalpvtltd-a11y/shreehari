<?php
/**
 * =====================================================================
 *  gemvault-test.php — the customer vault keeps gems, and keeps them safe.
 *
 *  Every name and phone that reaches this company is a permanent asset, so
 *  the vault has to be three things at once, and this suite proves each:
 *
 *    A. COMPLETE   — a contact from any door (app sign-in, counter sale,
 *                    agent sale) lands in the vault, deduplicated by the
 *                    normalised phone, whatever format it was typed in.
 *    B. ONE-WAY    — enrichment may add or sharpen, never blank. A later
 *                    sale with no name does not erase the name we hold.
 *    C. SEALED     — a scoped counter agent can NEVER read the vault view
 *                    of a passenger who is not in their own book, and no
 *                    gender is stored or returned anywhere.
 *
 *  Plus the guards that stop the vault becoming a liability: placeholder
 *  numbers are refused, a country is only ever recorded when it was
 *  CAPTURED, and a vault failure can never break a sale.
 *
 *    php -c .claude/php-dev.ini tests/gemvault-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/gemvault.php';

/* Numbers nothing real uses, so cleanup is exact. */
const GV_A = '9812370001';
const GV_B = '9812370002';
const GV_C = '9812370003';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$cleanup = static function (): void {
    foreach ([GV_A, GV_B, GV_C] as $p) {
        try { Database::delete('user_profiles', 'phone = :p', ['p' => $p]); } catch (Throwable $e) {}
    }
};
$cleanup();

echo "\n=== The customer vault ===\n\n";

/* -----------------------------------------------------------------
 *  A. Placeholders are not customers.
 * --------------------------------------------------------------- */
echo "-- A. what is not a gem --\n";
check('0000000000 is a placeholder',        GemVault::isPlaceholder('0000000000'));
check('1111111111 is a placeholder',        GemVault::isPlaceholder('1111111111'));
check('9999999999 is a placeholder',        GemVault::isPlaceholder('9999999999'));
check('an empty string is a placeholder',   GemVault::isPlaceholder(''));
check('a short number is a placeholder',    GemVault::isPlaceholder('12345'));
check('a real number is NOT a placeholder', !GemVault::isPlaceholder(GV_A));
check('upsert refuses a placeholder',       GemVault::upsert('0000000000', 'Walk In', 'IN', 'counter') === '');
check('…and stored no row for it',
      (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE phone = :p', ['p' => '0000000000'], 0) === 0);

/* -----------------------------------------------------------------
 *  B. One identity per human, whatever they typed.
 * --------------------------------------------------------------- */
echo "\n-- B. one gem per human --\n";
$stored = GemVault::upsert(GV_A, 'Ram Bahadur', 'NP', 'counter');
check('a counter contact is stored',        $stored === GV_A, "stored={$stored}");

// The SAME human, typed three different ways at three different doors.
GemVault::upsert('+977 ' . GV_A, '', '', 'whatsapp');
GemVault::upsert('0091' . GV_A, '', '', 'app');
$rows = (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE phone = :p', ['p' => GV_A], 0);
check('+977…, 0091… and the bare number are ONE row', $rows === 1, "rows={$rows}");

$row = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_A]);
$channels = array_values(array_filter(explode(',', (string) ($row['channels'] ?? ''))));
check('every door that saw them is recorded',
      in_array('counter', $channels, true) && in_array('whatsapp', $channels, true) && in_array('app', $channels, true),
      implode('+', $channels));

/* -----------------------------------------------------------------
 *  C. Enrichment is ONE-WAY.
 * --------------------------------------------------------------- */
echo "\n-- C. a later blank never erases what we know --\n";
check('the name survived two nameless later contacts',
      (string) ($row['full_name'] ?? '') === 'Ram Bahadur', (string) ($row['full_name'] ?? '(none)'));
check('the captured country survived them too',
      (string) ($row['country_code'] ?? '') === '977', (string) ($row['country_code'] ?? '(none)'));

// A corrected spelling DOES win — that is the name that prints on the ticket.
GemVault::upsert(GV_A, 'Ram Bahadur Thapa', '', 'counter');
$row = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_A]);
check('a corrected spelling replaces the old one',
      (string) $row['full_name'] === 'Ram Bahadur Thapa', (string) $row['full_name']);

// A one-character "name" is noise, not a correction.
GemVault::upsert(GV_A, 'R', '', 'counter');
$row = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_A]);
check('a one-letter name is ignored',
      (string) $row['full_name'] === 'Ram Bahadur Thapa', (string) $row['full_name']);

/* -----------------------------------------------------------------
 *  D. A country is CAPTURED, never guessed.
 * --------------------------------------------------------------- */
echo "\n-- D. country is captured, never guessed --\n";
GemVault::upsert(GV_B, 'Sita', '', 'counter');           // no country given
$rowB = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_B]);
check('an unknown country stays NULL, not defaulted to India',
      $rowB['country_code'] === null, var_export($rowB['country_code'], true));

GemVault::upsert(GV_B, '', '977', 'app');                 // dialing-code form
$rowB = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_B]);
check("a dialing code ('977') is accepted", (string) $rowB['country_code'] === '977');

GemVault::upsert(GV_B, '', 'ZZ', 'app');                  // nonsense
$rowB = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_B]);
check('a nonsense country is ignored, not stored',
      (string) $rowB['country_code'] === '977', (string) $rowB['country_code']);

/* -----------------------------------------------------------------
 *  E. The vault holds NO gender. Ever.
 * --------------------------------------------------------------- */
echo "\n-- E. no gender lives here --\n";
$cols = Database::fetchAll("SHOW COLUMNS FROM user_profiles");
$names = array_map(static fn(array $c): string => strtolower((string) $c['Field']), $cols);
check('user_profiles has no gender column',
      !in_array('gender', $names, true) && !in_array('sex', $names, true), implode(',', $names));

$recall = GemVault::recall(GV_A, 'system') ?? [];
check('recall() returns no gender key', !array_key_exists('gender', $recall));
check('recall() returns no ID number',  !array_key_exists('id_number', $recall));

/* -----------------------------------------------------------------
 *  F. recall() shape.
 * --------------------------------------------------------------- */
echo "\n-- F. what a gem tells you --\n";
check('recall finds the stored gem',    ($recall['phone'] ?? '') === GV_A);
check('…with the name',                 ($recall['name'] ?? '') === 'Ram Bahadur Thapa');
check('…with the captured country',     ($recall['country'] ?? '') === '977');
check('…and marks a never-travelled contact as not returning',
      ($recall['returning'] ?? true) === false);
check('recall of an unknown number is null', GemVault::recall('9812379999', 'system') === null);
check('recall of a placeholder is null',     GemVault::recall('0000000000', 'system') === null);

/* -----------------------------------------------------------------
 *  G. SEALED — a scoped counter agent never reads the vault.
 *
 *  This is the one that matters most. TicketBot::profile() was scoped on
 *  8 Sep 2026 precisely because an agent could POST any number and read a
 *  stranger's travel history out of another agent's book. A vault that
 *  answers the same question unscoped would re-open that hole through a
 *  new door, so recall() must refuse to serve the vault row to a scoped
 *  viewer — it may only hand back what TicketBot::profile() allows.
 * --------------------------------------------------------------- */
echo "\n-- G. an agent cannot read another agent's customers --\n";

/* Auth::admin() reads the session directly, so standing one up is enough —
   no admins row needed, and nothing to clean up in the database.
   The scoped role is 'agent' (Auth::isCounterAgent), not 'counter'. */
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$agentId = 987654;
$_SESSION[ADMIN_SESSION_KEY] = [
    'id' => $agentId, 'username' => 'gv_scope_probe', 'full_name' => 'Gem Vault Scope Probe',
    'role' => 'agent', 'permissions' => [], 'must_change_pw' => false,
    'logged_in_at' => time(), 'last_seen' => time(),
];

check('the probe really is a scoped viewer', Auth::bookingScopeAdminId() === $agentId,
      var_export(Auth::bookingScopeAdminId(), true));

GemVault::forget();
$scoped = GemVault::recall(GV_A, 'staff');
check('a scoped agent gets NOTHING for a number outside their book',
      $scoped === null, $scoped === null ? '' : 'source=' . (string) ($scoped['source'] ?? '?'));

// …and the vault is not reachable by asking as 'self' either, unless the
// caller genuinely verified ownership. 'self' is a claim the CALLER makes;
// what this asserts is that the scoped branch is chosen by the viewer
// argument and not by whatever session happens to be open.
$asSystem = GemVault::recall(GV_A, 'system');
check('the same call as the system DOES see the gem (so the block above was the scoping, not an empty vault)',
      $asSystem !== null && ($asSystem['source'] ?? '') === 'vault');

unset($_SESSION[ADMIN_SESSION_KEY]);
GemVault::forget();

/* -----------------------------------------------------------------
 *  H. A vault problem can never break a sale.
 * --------------------------------------------------------------- */
echo "\n-- H. the vault fails quietly or not at all --\n";
$threw = false;
try {
    // A wildly over-long name and a junk country: bad input must be absorbed.
    GemVault::upsert(GV_C, str_repeat('अ', 400), '<script>', 'counter');
} catch (Throwable $e) {
    $threw = true;
}
check('upsert never throws on hostile input', !$threw);
$rowC = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => GV_C]);
check('…and stored a truncated, cleaned name',
      $rowC !== null && mb_strlen((string) $rowC['full_name']) <= 120,
      $rowC ? ('len=' . mb_strlen((string) $rowC['full_name'])) : 'no row');

$threw = false;
try { GemVault::enrich('not-a-number'); } catch (Throwable $e) { $threw = true; }
check('enrich never throws on a junk phone', !$threw);
check('enrich of a phone with no bookings returns null', GemVault::enrich(GV_C) === null);

/* -----------------------------------------------------------------
 *  I. stats() answers "how many customers do we have?"
 * --------------------------------------------------------------- */
echo "\n-- I. the vault can be counted --\n";
$stats = GemVault::stats();
check('stats returns every expected counter',
      array_diff(['gems','named','withCountry','active90','returning','placeholder','merged'], array_keys($stats)) === []);
check('gems counts at least the three we just made', (int) $stats['gems'] >= 3, 'gems=' . $stats['gems']);
check('named counts at least our two named gems',    (int) $stats['named'] >= 2, 'named=' . $stats['named']);

/* -----------------------------------------------------------------
 *  J. Enrichment actually learns the habit.
 *
 *  Three confirmed sales on a far-future date, on a schedule of this
 *  test's own so no other booking can collide with the seats. The vault
 *  should come out knowing where this passenger boards, which deck they
 *  sleep on, how many they travel with and what they have spent — the
 *  four things that make the NEXT booking one tap instead of a
 *  conversation.
 * --------------------------------------------------------------- */
echo "\n-- J. the vault learns a travel habit --\n";

$routes = QuickTicket::routes();
$route  = $routes[0] ?? null;

if ($route === null) {
    check('a route exists to learn from', false, 'no routes configured');
} else {
    $td  = date('Y-m-d', strtotime('+300 days'));
    $sch = Seats::schedule((int) $route['id'], $td);
    $sid = (int) $sch['id'];

    /* Two sales from MEHSANA, one from AHMEDABAD: the modal stop must win,
       not the most recent one. Lower-deck berths outnumber upper 3:1. */
    $sales = [
        ['stop' => 'Mehsana',   'seats' => ['L21', 'L22'], 'total' => 4000.0],
        ['stop' => 'Ahmedabad', 'seats' => ['L23'],        'total' => 2000.0],
        ['stop' => 'Mehsana',   'seats' => ['U21'],        'total' => 2000.0],
    ];

    $madeIds = [];
    foreach ($sales as $i => $s) {
        $bid = (int) Database::insert('bookings', [
            'pnr'           => 'SHG-GVTEST-' . $i . '-' . substr((string) time(), -5),
            'contact_phone' => GV_C,
            'contact_country_code' => '977',
            'status'        => 'confirmed',
            'total_amount'  => $s['total'],
            'source'        => 'counter',
            'is_cod'        => 1,
            'confirmed_at'  => date('Y-m-d H:i:s'),
        ]);
        $lid = (int) Database::insert('booking_legs', [
            'booking_id'    => $bid,
            'schedule_id'   => $sid,
            'leg_type'      => 'outbound',
            'travel_date'   => $td,
            'boarding_stop' => $s['stop'],
            'seat_count'    => count($s['seats']),
            'leg_total'     => $s['total'],
        ]);
        foreach ($s['seats'] as $seat) {
            Database::insert('booking_seats', [
                'schedule_id' => $sid, 'seat_no' => $seat, 'booking_id' => $bid, 'leg_id' => $lid,
            ]);
        }
        $madeIds[] = $bid;
    }

    GemVault::forget();
    $learned = GemVault::enrich(GV_C);
    $gem     = GemVault::recall(GV_C, 'system') ?? [];

    check('enrich returned something', $learned !== null);
    check('it counted all three confirmed sales', ($gem['trips'] ?? 0) === 3, 'trips=' . ($gem['trips'] ?? '?'));
    check('lifetime value adds up',              abs(((float) ($gem['lifetimeValue'] ?? 0)) - 8000.0) < 0.01,
          'ltv=' . ($gem['lifetimeValue'] ?? '?'));
    check('average party size is the mean, not the last',
          abs(((float) ($gem['partySize'] ?? 0)) - 1.33) < 0.02, 'party=' . ($gem['partySize'] ?? '?'));
    check('the MODAL boarding stop wins, not the most recent',
          stripos((string) ($gem['boarding'] ?? ''), 'mehsana') !== false, (string) ($gem['boarding'] ?? '(none)'));
    check('the dominant deck is learned',        ($gem['deck'] ?? '') === 'lower', (string) ($gem['deck'] ?? '(none)'));
    check('COD is remembered as the payment habit', ($gem['payment'] ?? '') === 'cod', (string) ($gem['payment'] ?? '(none)'));
    check('the passenger now reads as returning', ($gem['returning'] ?? false) === true);
    check('the captured country came off the booking, not the digits',
          ($gem['country'] ?? '') === '977', (string) ($gem['country'] ?? '(none)'));

    /* A tie is not a preference. The three sales above give 3 lower berths
       (L21,L22,L23) against 1 upper (U21); add two more upper berths to make
       it 3-3, and the vault must stop claiming to know which deck they like
       rather than picking one at random. */
    $bidT = (int) Database::insert('bookings', [
        'pnr' => 'SHG-GVTEST-T-' . substr((string) time(), -5), 'contact_phone' => GV_C,
        'status' => 'confirmed', 'total_amount' => 0.0, 'source' => 'counter', 'is_cod' => 1,
    ]);
    $lidT = (int) Database::insert('booking_legs', [
        'booking_id' => $bidT, 'schedule_id' => $sid, 'leg_type' => 'outbound',
        'travel_date' => $td, 'boarding_stop' => 'Mehsana', 'seat_count' => 2,
    ]);
    foreach (['U22', 'U23'] as $seat) {
        Database::insert('booking_seats', ['schedule_id' => $sid, 'seat_no' => $seat, 'booking_id' => $bidT, 'leg_id' => $lidT]);
    }
    $madeIds[] = $bidT;

    GemVault::forget();
    GemVault::enrich(GV_C);
    $tied = GemVault::recall(GV_C, 'system') ?? [];
    check('a 3-3 deck split is NOT reported as a preference',
          ($tied['deck'] ?? 'x') === '', 'deck=' . ((string) ($tied['deck'] ?? '') ?: '(none)'));

    /* Clean up: seats and legs cascade off the booking. */
    foreach ($madeIds as $bid) {
        Database::delete('booking_seats', 'booking_id = :b', ['b' => $bid]);
        Database::delete('booking_legs', 'booking_id = :b', ['b' => $bid]);
        Database::delete('bookings', 'id = :b', ['b' => $bid]);
    }
}

$cleanup();

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);

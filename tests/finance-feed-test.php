<?php
/**
 * =====================================================================
 *  finance-feed-test.php — the website feed SHG Finance Master pulls
 *  (admin/api/finance-feed.php + includes/financefeed.php, Oct 2026).
 *
 *  The finance app posts real journals from this feed, so a wrong money
 *  state books revenue that never arrived, a paging gap silently drops a
 *  sale, and a leaked column hands a customer's phone to anyone holding the
 *  CEO's laptop. This suite pins:
 *
 *    - the envelope and the exact row keys of every part (no extra columns)
 *    - money as decimal strings, signed where the column is
 *    - moneyState for collected / COD due / proof awaiting / cancelled
 *      after collection / cancelled uncollected / rejected / expired / other
 *    - bookings keyset paging by (wm, id): no gap, no duplicate, even when
 *      dozens of rows share one watermark, at any page size
 *    - ledger kinds (salary, loan, advance, paper ticket, write-off …),
 *      the note rule (booking-linked rows only) and orphaned sellers
 *    - NO PII: fixture phones / emails / names / IDs / notes never appear in
 *      any part's JSON
 *    - the day-book matches the Accounting page's basis
 *    - parameter validation (422) and limit clamping
 *    - the gates at source level (reports.view, counter agents refused,
 *      GET only, rate limit, read-only SQL)
 *    - an HTTP round trip on 127.0.0.1:8891 when a dev server is there
 *      (401 signed out, 200 signed in, 403 / 405 / 422 / 429)
 *
 *  Fixtures carry the PNR prefix FFT-<run tag> and are removed in a finally
 *  block. It refuses to run against anything but a local test database.
 *
 *    php tests/finance-feed-test.php
 *    php -S 127.0.0.1:8891 -t . &   (optional, for the HTTP section)
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/financefeed.php';

define('FFT_BASE', rtrim((string) (getenv('SHG_FEED_BASE') ?: 'http://127.0.0.1:8891'), '/'));
const FFT_PW = 'FeedTest@12345';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* ---------------------------------------------------------------------
 *  Never against production (same three checks as tests/run-all.php).
 * ------------------------------------------------------------------- */
(static function (): void {
    $name = defined('DB_NAME') ? (string) DB_NAME : '';
    $host = strtolower(trim(explode(';', defined('DB_HOST') ? (string) DB_HOST : '')[0]));
    $env  = defined('APP_ENV') ? strtolower((string) APP_ENV) : '';
    $bad  = [];
    if (stripos($name, 'test') === false) { $bad[] = "database name '{$name}' does not contain 'test'"; }
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1', ''], true)) { $bad[] = "database host '{$host}' is not local"; }
    if ($env === 'production') { $bad[] = "APP_ENV is 'production'"; }
    if ($bad !== []) {
        fwrite(STDERR, "\n  REFUSING TO RUN — this does not look like a test database.\n");
        foreach ($bad as $b) { fwrite(STDERR, "    - {$b}\n"); }
        fwrite(STDERR, "  This suite inserts and deletes bookings, ledger rows and staff accounts.\n\n");
        exit(2);
    }
})();

/* ---------------------------------------------------------------------
 *  Helpers
 * ------------------------------------------------------------------- */
function feed(array $q, array $admin = []): array {
    return FinanceFeed::run(FinanceFeed::params($q), $admin);
}
function enc(array $v): string {
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
}
/** @return array<string,mixed> keyed by $key */
function indexBy(array $rows, string $key): array {
    $o = [];
    foreach ($rows as $r) { $o[(string) $r[$key]] = $r; }
    return $o;
}
function invalid(array $q): bool {
    try { FinanceFeed::params($q); return false; } catch (InvalidArgumentException $e) { return true; }
}
/** Strip comments so source gates look at code only. */
function codeOnly(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}
/** Only the string literals of a PHP file (where SQL lives). */
function stringsOnly(string $src): string {
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $out .= $t[1] . "\n"; }
    }
    return $out;
}

const ENVELOPE_KEYS = ['ok', 'part', 'feedVersion', 'generatedAt', 'tz', 'dbTimeZone', 'dbSystemTimeZone', 'dbUtcOffset'];
const KEYS = [
    'agents'      => ['sellerId', 'code', 'name', 'username', 'role', 'active', 'kind', 'tier', 'overrideMode', 'overrideFlat',
                      'overridePercent', 'salary', 'depositRequired', 'depositPaid'],
    'schedules'   => ['scheduleId', 'travelDate', 'slot', 'depTime', 'status', 'routeId', 'routeCode', 'fromCity', 'toCity',
                      'direction', 'busId', 'busNumber', 'busName', 'seats', 'liveSeats', 'liveBookings'],
    'bookings'    => ['bookingId', 'pnr', 'ticketNo', 'status', 'isCod', 'source', 'currency', 'totalAmount', 'discount',
                      'bookingFee', 'taxAmount', 'refundAmount', 'refundStatus', 'refundRef', 'refundApprovedAt', 'sellerId',
                      'referralCode', 'createdAt', 'confirmedAt', 'cancelledAt', 'paymentId', 'paymentRef', 'paymentMethod',
                      'paymentMode', 'paymentAmount', 'paymentStatus', 'utr', 'verifiedBy', 'verifiedAt', 'paymentRows',
                      'scheduleId', 'travelDate', 'seats', 'routeCode', 'moneyState', 'wm'],
    'ledger'      => ['ledgerId', 'sellerId', 'code', 'account', 'entryType', 'kind', 'amount', 'bookingId', 'pnr',
                      'bookingMissing', 'bookingStatus', 'ref', 'loanId', 'note', 'writeOff', 'createdBy', 'createdAt'],
    'paper'       => ['paperId', 'sellerId', 'paperTicketNo', 'bookingId', 'pnr', 'shape', 'routeCode', 'travelDate', 'pax',
                      'amount', 'commission', 'paymentMode', 'status', 'createdAt'],
    'deposits'    => ['depositId', 'sellerId', 'amount', 'ref', 'createdAt'],
    'loans'       => ['loanId', 'sellerId', 'kind', 'principal', 'recovered', 'status', 'issuedOn', 'settledAt', 'createdAt'],
    'shifts'      => ['shiftId', 'staffId', 'staffName', 'openedAt', 'closedAt', 'openingCash', 'cashSales', 'upiSales',
                      'tickets', 'seats', 'cashPaidOut', 'expectedCash', 'countedCash', 'variance', 'wm'],
    'daybook'     => ['day', 'bucket', 'bookings', 'amount'],
];
const MONEY_RE = '/^-?\d+\.\d{2}$/';
const TS_RE    = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

function envelopeOk(string $part, array $r): bool {
    foreach (ENVELOPE_KEYS as $k) { if (!array_key_exists($k, $r)) { return false; } }
    return $r['ok'] === true && $r['part'] === $part && $r['feedVersion'] === 1
        && preg_match(TS_RE, (string) $r['generatedAt']) === 1 && $r['tz'] === 'Asia/Kolkata'
        && is_string($r['dbTimeZone']) && $r['dbTimeZone'] !== ''
        && preg_match('/^[+-]\d{2}:\d{2}$/', (string) $r['dbUtcOffset']) === 1
        && is_array($r['rows'] ?? null) && array_is_list($r['rows']) && array_key_exists('next', $r)
        && ($r['count'] ?? -1) === count($r['rows']);
}
function keysExact(array $rows, array $keys): bool {
    foreach ($rows as $row) { if (array_keys($row) !== $keys) { return false; } }
    return true;
}

/* ---------------------------------------------------------------------
 *  Fixtures
 * ------------------------------------------------------------------- */
$tag  = substr(bin2hex(random_bytes(4)), 0, 7);
$PFX  = 'FFT-' . strtoupper($tag) . '-';
$rnd  = static fn(int $len): string => substr(str_pad((string) random_int(0, (int) str_repeat('9', $len)), $len, '0', STR_PAD_LEFT), 0, $len);
$PII = [
    'phone'      => '98' . $rnd(8),
    'phone2'     => '97' . $rnd(8),
    'email'      => 'fft.pii.' . $tag . '@example.invalid',
    'name'       => 'Piipassenger Zq' . $tag,
    'payer'      => 'Piipayer Zq' . $tag,
    'idno'       => 'PIIID' . strtoupper($tag),
    'ua'         => 'PiiAgentUA/' . $tag,
    'ip'         => '203.0.113.' . random_int(10, 250),
    'agentPhone' => '96' . $rnd(8),
    'agentEmail' => 'fft.agent.' . $tag . '@example.invalid',
    'paperName'  => 'Piipaper Zq' . $tag,
    'paperPhone' => '95' . $rnd(8),
    'note'       => 'Piinote Zq' . $tag,
    'address'    => 'Pii Street ' . $tag,
    'seatText'   => 'Piiseat Zq' . $tag,
    'shot'       => 'pii/' . $tag . '.png',
];

$ids = ['admins' => [], 'bookings' => [], 'routes' => [], 'buses' => [], 'schedules' => [], 'ledger' => [], 'paper' => [],
        'deposits' => [], 'loans' => [], 'shifts' => [], 'audit' => []];
$ORPHAN = 0;

$cleanup = static function () use (&$ids, &$ORPHAN, $PFX): void {
    $in = static function (array $list, string $p): array {
        $ph = []; $pp = [];
        foreach (array_values($list) as $i => $v) { $ph[] = ':' . $p . $i; $pp[$p . $i] = $v; }
        return [$ph === [] ? 'NULL' : implode(',', $ph), $pp];
    };
    $try = static function (callable $fn): void { try { $fn(); } catch (Throwable $e) { echo "  (cleanup) " . $e->getMessage() . "\n"; } };
    $sellers = array_values(array_filter(array_merge($ids['admins'], [$ORPHAN])));

    $try(function () use ($PFX) {
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id LIKE :p", ['p' => $PFX . '%']);
    });
    if ($ids['admins'] !== []) {
        [$ph, $pp] = $in($ids['admins'], 'a');
        $try(fn() => Database::run("DELETE FROM audit_logs WHERE actor_type = 'admin' AND actor_id IN ($ph)", $pp));
        $try(fn() => Database::run("DELETE FROM admin_login_events WHERE admin_id IN ($ph)", $pp));
    }
    if ($sellers !== []) {
        [$ph, $pp] = $in($sellers, 's');
        foreach (['agent_ledger', 'offline_tickets', 'agent_deposit_txns', 'agent_loans'] as $t) {
            $try(fn() => Database::run("DELETE FROM {$t} WHERE agent_admin_id IN ($ph)", $pp));
        }
        $try(fn() => Database::run("DELETE FROM counter_shifts WHERE admin_id IN ($ph)", $pp));
    }
    if ($ids['ledger'] !== []) {
        [$ph, $pp] = $in($ids['ledger'], 'l');
        $try(fn() => Database::run("DELETE FROM agent_ledger WHERE id IN ($ph)", $pp));
    }
    $try(fn() => Database::run('DELETE FROM bookings WHERE pnr LIKE :p', ['p' => $PFX . '%']));   // payments, legs, passengers, tickets, screenshots cascade
    if ($ids['routes'] !== []) {
        [$ph, $pp] = $in($ids['routes'], 'r');
        $try(fn() => Database::run("DELETE FROM schedules WHERE route_id IN ($ph)", $pp));
        $try(fn() => Database::run("DELETE FROM routes WHERE id IN ($ph)", $pp));
    }
    if ($ids['buses'] !== []) {
        [$ph, $pp] = $in($ids['buses'], 'u');
        $try(fn() => Database::run("DELETE FROM buses WHERE id IN ($ph)", $pp));
    }
    if ($ids['admins'] !== []) {
        [$ph, $pp] = $in($ids['admins'], 'a');
        $try(fn() => Database::run("DELETE FROM admin_profiles WHERE admin_id IN ($ph)", $pp));
        $try(fn() => Database::run("DELETE FROM admins WHERE id IN ($ph)", $pp));
        $idents = array_map(static fn($i) => 'admin:' . $i, $ids['admins']);
        [$ph2, $pp2] = $in($idents, 'i');
        $try(fn() => Database::run("DELETE FROM rate_limits WHERE bucket = 'finance_feed' AND identifier IN ($ph2)", $pp2));
    }
    $try(fn() => Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"));
};

/* Leftovers of an interrupted earlier run (older than an hour, so a run in
   another terminal is never pulled out from under itself). */
(static function (): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'fftfeed-%' AND created_at < NOW() - INTERVAL 1 HOUR") as $a) {
        $id = (int) $a['id'];
        foreach (['agent_ledger', 'offline_tickets', 'agent_deposit_txns', 'agent_loans'] as $t) {
            try { Database::run("DELETE FROM {$t} WHERE agent_admin_id = :a", ['a' => $id]); } catch (Throwable $e) {}
        }
        try { Database::run('DELETE FROM counter_shifts WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
    }
    try { Database::run("DELETE FROM bookings WHERE pnr LIKE 'FFT-%' AND created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM routes WHERE route_code LIKE 'FFT%' AND created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM buses WHERE bus_number LIKE 'FFT-%' AND created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM admin_profiles WHERE admin_id IN (SELECT id FROM admins WHERE username LIKE 'fftfeed-%' AND created_at < NOW() - INTERVAL 1 HOUR)"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM admins WHERE username LIKE 'fftfeed-%' AND created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
})();

echo "\n=== finance feed — fixtures {$PFX}* ===\n";

$mkAdmin = static function (string $role, string $label, array $extra = []) use (&$ids, $tag): int {
    $id = Database::insert('admins', $extra + [
        'username'       => 'fftfeed-' . $label . '-' . $tag,
        'password_hash'  => password_hash(FFT_PW, PASSWORD_BCRYPT),
        'full_name'      => 'FFT ' . ucfirst($label) . ' ' . $tag,
        'role'           => $role,
        'is_active'      => 1,
        'must_change_pw' => 0,
        'failed_logins'  => 0,
    ]);
    $ids['admins'][] = $id;
    return $id;
};

$n = 0;
/**
 * One booking. $b overrides bookings columns; $pays is a list of payment
 * column overrides (inserted in order, so the last is the "latest"); $o:
 * leg (schedule id), legSeats, pax (passenger rows), ticket, shot.
 */
$mkBooking = static function (array $b, array $pays = [], array $o = []) use (&$ids, &$n, $PFX, $PII): int {
    $n++;
    $pnr = $PFX . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $wm  = $b['updated_at'] ?? '2037-05-05 05:05:05';
    $id  = Database::insert('bookings', $b + [
        'pnr'           => $pnr,
        'contact_phone' => $PII['phone'],
        'contact_email' => $PII['email'],
        'id_type'       => 'Aadhaar',
        'id_number'     => $PII['idno'],
        'ip_address'    => $PII['ip'],
        'user_agent'    => $PII['ua'],
        'total_amount'  => 1000,
        'status'        => 'pending',
        'source'        => 'web',
        'currency'      => 'INR',
        'updated_at'    => $wm,
    ]);
    $ids['bookings'][$n] = $id;
    $legId = null;
    if (isset($o['leg'])) {
        $legId = Database::insert('booking_legs', [
            'booking_id' => $id, 'schedule_id' => (int) $o['leg'], 'leg_type' => 'outbound',
            'travel_date' => (string) $o['legDate'], 'seat_count' => (int) ($o['legSeats'] ?? 1),
            'leg_total' => (float) ($b['total_amount'] ?? 1000),
        ]);
    }
    for ($i = 1; $i <= (int) ($o['pax'] ?? 1); $i++) {
        Database::insert('booking_passengers', [
            'booking_id' => $id, 'leg_id' => $legId, 'passenger_ref' => 'PAX-' . $pnr . '-' . $i,
            'seat_no' => 'L' . $i, 'full_name' => $PII['name'], 'id_number' => $PII['idno'], 'is_primary' => $i === 1 ? 1 : 0,
        ]);
    }
    $k = 0;
    $lastPay = null;
    foreach ($pays as $p) {
        $k++;
        $lastPay = Database::insert('payments', $p + [
            'booking_id'  => $id,
            'payment_ref' => 'PAY-' . $pnr . '-' . $k,
            'method'      => 'upi',
            'mode'        => 'utr',
            'amount'      => (float) ($b['total_amount'] ?? 1000),
            'payer_name'  => $PII['payer'],
            'payer_phone' => $PII['phone2'],
            'status'      => 'pending',
            'updated_at'  => '2037-05-05 05:00:00',
        ]);
    }
    if (!empty($o['ticket'])) {
        Database::insert('tickets', [
            'booking_id' => $id, 'ticket_number' => 'TKT-' . $pnr, 'qr_payload' => 'SHG-TICKET|' . $pnr,
            'qr_hash' => hash('sha256', $pnr), 'issued_at' => '2031-07-10 10:00:00',
        ]);
    }
    if (!empty($o['shot']) && $lastPay !== null) {
        Database::insert('payment_screenshots', [
            'payment_id' => $lastPay, 'booking_id' => $id, 'file_path' => $PII['shot'], 'mime_type' => 'image/png',
            'original_name' => $PII['name'] . '.png', 'uploaded_ip' => $PII['ip'],
        ]);
    }
    return $id;
};

$exitCode = 0;
try {
    /* ---- staff -------------------------------------------------------- */
    $A   = $mkAdmin('agent', 'agent', ['phone' => $PII['agentPhone'], 'email' => $PII['agentEmail'],
                                       'permissions' => '["reports.view"]']);
    $ACC = $mkAdmin('accountant', 'acct');
    $SUP = $mkAdmin('support', 'support');
    $ORPHAN = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM admins', [], 0) + 700000 + random_int(1, 9999);
    Database::insert('admin_profiles', [
        'admin_id' => $A, 'display_phone' => $PII['agentPhone'], 'whatsapp' => $PII['agentPhone'],
        'display_email' => $PII['agentEmail'], 'address' => $PII['address'], 'id_number' => $PII['idno'],
        'payout_account' => $PII['idno'] . '@upi', 'agent_kind' => 'person', 'commission_percent' => 7.5,
        'counter_name' => 'FFT counter',
    ]);

    /* ---- routes / bus / schedules --------------------------------------- */
    $BUS = Database::insert('buses', ['bus_number' => 'FFT-' . $tag, 'bus_name' => 'FFT Coach ' . $tag, 'is_active' => 0]);
    $ids['buses'][] = $BUS;
    $R1 = Database::insert('routes', ['route_code' => 'FFT' . $tag . 'U', 'from_city' => 'Mehsana', 'to_city' => 'Rupaidiha (Nepal border)',
        'dep_time' => '13:00:00', 'arr_time' => '09:00:00', 'is_active' => 0, 'bus_id' => $BUS]);
    $R2 = Database::insert('routes', ['route_code' => 'FFT' . $tag . 'D', 'from_city' => 'Rupaidiha', 'to_city' => 'Ahmedabad',
        'dep_time' => '17:30:00', 'arr_time' => '12:00:00', 'is_active' => 0]);
    $ids['routes'] = [$R1, $R2];
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $plus  = static fn(int $d): string => (new DateTimeImmutable($today))->modify(($d < 0 ? '-' : '+') . abs($d) . ' days')->format('Y-m-d');
    $S1 = Database::insert('schedules', ['route_id' => $R1, 'travel_date' => '2099-03-01', 'slot' => 1, 'dep_time_override' => '21:15:00', 'total_seats' => 36]);
    $S2 = Database::insert('schedules', ['route_id' => $R1, 'travel_date' => '2099-03-02', 'slot' => 1, 'total_seats' => 36]);
    $S3 = Database::insert('schedules', ['route_id' => $R2, 'travel_date' => $plus(3), 'slot' => 1, 'total_seats' => 40]);
    $S4 = Database::insert('schedules', ['route_id' => $R1, 'travel_date' => $plus(-100), 'slot' => 1, 'total_seats' => 40]);
    $ids['schedules'] = [$S1, $S2, $S3, $S4];

    /* ---- bookings: one per money state --------------------------------- */
    $legS1 = ['leg' => $S1, 'legDate' => '2099-03-01'];
    $B = [];
    $B['collected'] = $mkBooking(['status' => 'confirmed', 'confirmed_at' => '2031-07-10 10:00:00', 'total_amount' => 2500,
        'group_discount' => 10, 'tier_discount' => 20, 'coupon_discount' => 30, 'points_value' => 40, 'booking_fee' => 15.5,
        'sold_by_admin_id' => $A, 'source' => 'agent', 'referral_code' => 'REFFFT'],
        [['method' => 'upi', 'status' => 'verified', 'utr_number' => 'UTRFFT' . $tag, 'verified_by' => $ACC, 'verified_at' => '2031-07-10 10:01:00']],
        $legS1 + ['legSeats' => 3, 'pax' => 3, 'ticket' => true]);
    $B['cod_due'] = $mkBooking(['status' => 'confirmed', 'is_cod' => 1, 'confirmed_at' => '2031-07-10 11:00:00', 'total_amount' => 1800,
        'sold_by_admin_id' => $A, 'source' => 'agent'], [['method' => 'cod', 'mode' => 'offline', 'status' => 'cod_pending']]);
    $B['proof_utr'] = $mkBooking(['status' => 'pending'], [['method' => 'upi', 'status' => 'pending', 'utr_number' => 'UTRPEND' . $tag]]);
    $B['proof_shot'] = $mkBooking(['status' => 'pending'], [['method' => 'upi', 'mode' => 'screenshot', 'status' => 'pending']], ['shot' => true]);
    $B['awaiting'] = $mkBooking(['status' => 'pending'], [['method' => 'esewa', 'status' => 'pending', 'utr_number' => '   ']]);
    $B['cancel_coll'] = $mkBooking(['status' => 'cancelled', 'confirmed_at' => '2031-07-10 12:00:00', 'cancelled_at' => '2031-07-13 09:00:00',
        'total_amount' => 2000, 'refund_amount' => 750, 'refund_status' => 'processed', 'refund_ref' => 'RFFFT' . $tag,
        'cancel_reason' => $PII['note'], 'sold_by_admin_id' => $A],
        [['method' => 'cash', 'mode' => 'offline', 'status' => 'verified', 'verified_by' => $A, 'verified_at' => '2031-07-10 12:00:00']],
        $legS1 + ['legSeats' => 2, 'pax' => 2]);
    $B['cancel_unc'] = $mkBooking(['status' => 'cancelled', 'cancelled_at' => '2031-07-13 10:00:00'], [['status' => 'pending']]);
    $B['cancel_cod'] = $mkBooking(['status' => 'cancelled', 'is_cod' => 1, 'confirmed_at' => '2031-07-10 13:00:00', 'cancelled_at' => '2031-07-14 10:00:00'],
        [['method' => 'cod', 'mode' => 'offline', 'status' => 'cod_pending']]);
    $B['rejected'] = $mkBooking(['status' => 'rejected'], [['status' => 'rejected', 'utr_number' => 'UTRREJ' . $tag]]);
    $B['expired'] = $mkBooking(['status' => 'expired'], [['status' => 'pending']]);
    $B['multi'] = $mkBooking(['status' => 'confirmed', 'confirmed_at' => '2031-07-11 09:00:00', 'total_amount' => 1200],
        [['method' => 'upi', 'status' => 'rejected', 'utr_number' => 'UTROLD' . $tag],
         ['method' => 'bank', 'status' => 'verified', 'verified_by' => $ACC, 'verified_at' => '2031-07-11 09:30:00']]);
    $B['nopay'] = $mkBooking(['status' => 'confirmed', 'confirmed_at' => '2031-07-12 08:00:00', 'total_amount' => 900], [], ['pax' => 2]);
    $B['npr'] = $mkBooking(['status' => 'confirmed', 'confirmed_at' => '2031-07-11 10:00:00', 'total_amount' => 1600, 'currency' => 'NPR'],
        [['method' => 'esewa', 'status' => 'verified', 'currency' => 'NPR', 'verified_by' => $ACC, 'verified_at' => '2031-07-11 10:00:00']]);
    $B['cod_settled'] = $mkBooking(['status' => 'confirmed', 'is_cod' => 1, 'confirmed_at' => '2031-07-12 09:00:00', 'total_amount' => 700],
        [['method' => 'cod', 'mode' => 'offline', 'status' => 'verified', 'verified_by' => $A, 'verified_at' => '2031-07-15 09:00:00']]);
    $B['completed'] = $mkBooking(['status' => 'completed', 'confirmed_at' => '2031-07-12 10:00:00', 'total_amount' => 300, 'sold_by_admin_id' => $A],
        [['method' => 'cash', 'mode' => 'offline', 'status' => 'verified', 'verified_by' => $A, 'verified_at' => '2031-07-12 10:00:00']],
        $legS1 + ['legSeats' => 1]);
    $B['refund_pend'] = $mkBooking(['status' => 'cancelled', 'confirmed_at' => '2031-07-12 11:00:00', 'cancelled_at' => '2031-07-14 11:00:00',
        'total_amount' => 800, 'refund_amount' => 400, 'refund_status' => 'pending', 'refund_ref' => $PII['phone'] . '@ybl'],
        [['method' => 'upi', 'status' => 'verified', 'verified_by' => $ACC, 'verified_at' => '2031-07-12 11:00:00']]);
    $B['refund_denied'] = $mkBooking(['status' => 'cancelled', 'confirmed_at' => '2031-07-12 12:00:00', 'cancelled_at' => '2031-07-14 12:00:00',
        'total_amount' => 500, 'refund_amount' => 100, 'refund_status' => 'denied'],
        [['method' => 'upi', 'status' => 'verified', 'verified_by' => $ACC, 'verified_at' => '2031-07-12 12:00:00',
          'utr_number' => $PII['phone2'] . '@okaxis']]);
    // The payment row moved later than the booking row: wm must follow it.
    $B['wm_pay'] = $mkBooking(['status' => 'confirmed', 'confirmed_at' => '2031-07-20 10:00:00', 'updated_at' => '2037-05-05 05:00:00'],
        [['method' => 'upi', 'status' => 'verified', 'updated_at' => '2037-05-05 06:00:00']]);

    // 23 bookings that share ONE watermark — the keyset's hardest case.
    $SAME = [];
    for ($i = 0; $i < 23; $i++) {
        $SAME[] = $mkBooking(['status' => 'expired', 'updated_at' => '2037-05-06 00:00:00']);
    }

    // Refund approvals live only in audit_logs (with the approver's phone in actor_name).
    $pnrOf = static fn(int $bid): string => (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $bid]);
    foreach ([['refund.approve', '2037-01-01 10:00:00'], ['refund.approve', '2037-01-02 11:00:00'], ['refund.deny', '2037-01-03 12:00:00']] as [$act, $at]) {
        Database::insert('audit_logs', [
            'actor_type' => 'admin', 'actor_id' => $ACC, 'actor_name' => '+91 ' . $PII['phone2'], 'actor_role' => 'accountant',
            'action' => $act, 'entity_type' => 'booking', 'entity_id' => $pnrOf($B['cancel_coll']),
            'old_value' => json_encode(['phone' => $PII['phone']]), 'new_value' => json_encode(['email' => $PII['email']]),
            'detail' => 'ref', 'ip_address' => $PII['ip'], 'created_at' => $at,
        ]);
    }

    /* ---- agent ledger ---------------------------------------------------- */
    $loanId = Database::insert('agent_loans', ['agent_admin_id' => $A, 'kind' => 'advance', 'principal' => 5000, 'issued_on' => '2031-07-01',
        'recovered' => 1000, 'status' => 'written_off', 'note' => $PII['note'], 'created_by' => $ACC, 'settled_at' => '2031-07-20 10:00:00']);
    $ids['loans'][] = $loanId;
    $gone = 1900000000 + random_int(1, 99999999);
    $L = [];
    $led = static function (string $key, array $row) use (&$L, &$ids): void {
        $id = Database::insert('agent_ledger', $row + ['created_at' => '2031-07-10 10:00:00']);
        $L[$key] = $id;
        $ids['ledger'][] = $id;
    };
    $pc = $pnrOf($B['collected']);
    $pk = $pnrOf($B['cancel_coll']);
    $led('commission', ['agent_admin_id' => $A, 'booking_id' => $B['collected'], 'account' => 'commission', 'entry_type' => 'commission',
        'amount' => 600, 'note' => 'Commission on ' . $pc . ' (3 x ₹200 (direct agent))', 'ref' => $pc]);
    $led('cash_due', ['agent_admin_id' => $A, 'booking_id' => $B['completed'], 'account' => 'cash', 'entry_type' => 'cash_due',
        'amount' => 300, 'note' => 'Cash collected', 'ref' => $pnrOf($B['completed'])]);
    $led('comm6', ['agent_admin_id' => $A, 'booking_id' => $B['cancel_coll'], 'account' => 'commission', 'entry_type' => 'commission',
        'amount' => 200, 'note' => 'Commission on ' . $pk, 'ref' => $pk]);
    $led('void', ['agent_admin_id' => $A, 'booking_id' => $B['cancel_coll'], 'account' => 'commission', 'entry_type' => 'commission_void',
        'amount' => -200, 'note' => 'Reversed — customer +91 ' . $PII['phone'] . ' / ' . $PII['email'] . ' asked (' . $pk . ')',
        'ref' => $pk, 'created_at' => '2031-07-13 09:00:00']);
    $led('payout', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'payout', 'amount' => -300,
        'note' => 'Payout ' . $PII['note'], 'ref' => 'SV-310715-1', 'created_by' => $ACC]);
    $led('handover', ['agent_admin_id' => $A, 'account' => 'cash', 'entry_type' => 'cash_handover', 'amount' => -300, 'ref' => 'SV-310715-2']);
    $led('salary', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'adjustment', 'amount' => 15000,
        'note' => 'Monthly salary 2031-07', 'ref' => 'SALARY 2031-07']);
    $led('loan_given', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'adjustment', 'amount' => -5000,
        'note' => 'Advance #' . $loanId . ' given — ' . $PII['note'], 'ref' => 'ADVANCE L' . $loanId]);
    $led('loan_repaid', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'adjustment', 'amount' => 1000,
        'note' => 'Repayment on Advance #' . $loanId, 'ref' => 'ADVANCE L' . $loanId]);
    $led('loan_writeoff', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'adjustment', 'amount' => 4000,
        'note' => 'Advance #' . $loanId . ' written off', 'ref' => 'ADVANCE L' . $loanId]);
    $led('advance', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'adjustment', 'amount' => -500,
        'note' => $PII['note'], 'ref' => 'ADVANCE']);
    $paperNo = 'PAPER-FFT-' . $tag . '-1';
    $led('paper_comm', ['agent_admin_id' => $A, 'account' => 'commission', 'entry_type' => 'commission', 'amount' => 150,
        'note' => 'Paper ticket ' . $paperNo . ' · ' . $PII['paperName'] . ' (1 x ₹150)', 'ref' => $paperNo, 'created_at' => '2031-07-11 10:00:00']);
    $led('paper_cash', ['agent_admin_id' => $A, 'account' => 'cash', 'entry_type' => 'cash_due', 'amount' => 900,
        'note' => 'Cash for paper ticket ' . $paperNo, 'ref' => $paperNo]);
    $led('adjust', ['agent_admin_id' => $A, 'account' => 'cash', 'entry_type' => 'adjustment', 'amount' => 50, 'note' => $PII['note'], 'ref' => 'ADJ']);
    $led('missing', ['agent_admin_id' => $A, 'booking_id' => $gone, 'account' => 'commission', 'entry_type' => 'commission',
        'amount' => 200, 'note' => 'Commission on SHG-FFT-GONE', 'ref' => 'SHG-FFT-GONE-' . $tag]);
    $led('orphan', ['agent_admin_id' => $ORPHAN, 'account' => 'cash', 'entry_type' => 'cash_handover', 'amount' => -10, 'ref' => 'SV-ORPHAN']);

    /* ---- paper tickets, deposits, shifts --------------------------------- */
    $P1 = Database::insert('offline_tickets', ['agent_admin_id' => $A, 'paper_ticket_no' => $paperNo, 'route_id' => $R1,
        'travel_date' => '2099-03-01', 'passenger_name' => $PII['paperName'], 'passenger_phone' => $PII['paperPhone'],
        'seat_text' => $PII['seatText'], 'pax_count' => 1, 'amount' => 900, 'commission' => 150, 'payment_mode' => 'cash',
        'status' => 'recorded', 'note' => $PII['note'], 'created_by' => $A]);
    $P2 = Database::insert('offline_tickets', ['agent_admin_id' => $A, 'paper_ticket_no' => 'PAPER-FFT-' . $tag . '-2',
        'booking_id' => $B['completed'], 'route_id' => $R1, 'travel_date' => '2099-03-01', 'passenger_name' => $PII['paperName'],
        'pax_count' => 1, 'amount' => 300, 'commission' => 0, 'payment_mode' => 'upi', 'status' => 'void']);
    $ids['paper'] = [$P1, $P2];
    $D1 = Database::insert('agent_deposit_txns', ['agent_admin_id' => $A, 'amount' => 5000, 'ref' => 'RCPT-FFT-' . $tag, 'note' => $PII['note'], 'created_by' => $ACC]);
    $D2 = Database::insert('agent_deposit_txns', ['agent_admin_id' => $A, 'amount' => -1000, 'ref' => null, 'note' => $PII['note']]);
    $D3 = Database::insert('agent_deposit_txns', ['agent_admin_id' => $A, 'amount' => 250, 'ref' => $PII['agentPhone'] . '@upi']);
    $ids['deposits'] = [$D1, $D2, $D3];
    $SH1 = Database::insert('counter_shifts', ['admin_id' => $A, 'is_open' => null, 'opened_at' => '2031-07-10 08:00:00',
        'closed_at' => '2031-07-10 20:00:00', 'opening_cash' => 500, 'cash_sales' => 1000.5, 'upi_sales' => 200, 'tickets' => 3,
        'seats' => 4, 'cash_paid_out' => 100, 'expected_cash' => 1400.5, 'counted_cash' => 1390.5, 'variance' => -10,
        'denominations' => '{"500":2}', 'note' => $PII['note'], 'closed_by' => $ACC, 'updated_at' => '2037-02-02 02:02:02']);
    $SH2 = Database::insert('counter_shifts', ['admin_id' => $A, 'is_open' => 1, 'opened_at' => '2031-07-11 08:00:00', 'updated_at' => '2037-02-02 02:02:03']);
    $SH3 = Database::insert('counter_shifts', ['admin_id' => $ACC, 'is_open' => null, 'opened_at' => '2031-07-10 08:00:00',
        'closed_at' => '2031-07-10 21:00:00', 'updated_at' => '2037-02-02 02:02:02']);
    $ids['shifts'] = [$SH1, $SH2, $SH3];

    $ADMIN = ['id' => $ACC, 'username' => 'fftfeed-acct-' . $tag, 'full_name' => 'FFT Acct ' . $tag, 'role' => 'accountant'];
    $ALL = [];   // part => JSON, for the PII scan

    /* ================================================================ */
    echo "\n-- envelope + meta --\n";
    $meta = feed(['part' => 'meta'], $ADMIN);
    $ALL['meta'] = enc($meta);
    check('meta: envelope', envelopeOk('meta', $meta) && $meta['rows'] === [] && $meta['next'] === null);
    $companyKeys = ['name', 'legal', 'legalNe', 'address', 'addressNe', 'phone', 'email', 'web', 'cin', 'gstin', 'ceo', 'tagline',
        'mantra', 'operator', 'operatorNe', 'counters', 'whatsapp', 'nepalPhone', 'nepalOffice', 'upiId', 'upiName', 'esewaId', 'esewaName'];
    check('meta.company carries every letterhead key (strings)',
        array_diff($companyKeys, array_keys($meta['company'])) === [] && count(array_filter($meta['company'], 'is_string')) === count($meta['company']));
    check('meta.company name / CIN from Settings::company()',
        $meta['company']['name'] === Settings::company()['name'] && $meta['company']['cin'] === Settings::company()['cin']);
    check('meta.company Nepali text is real Devanagari (no latin1 mojibake)',
        preg_match('/\p{Devanagari}/u', $meta['company']['legalNe']) === 1 && !str_contains($meta['company']['legalNe'], 'à¤'));
    check('meta.logo.url is root-relative', preg_match('#^/[A-Za-z0-9_\-./]+\.(png|jpe?g|webp)$#i', (string) $meta['logo']['url']) === 1,
        (string) $meta['logo']['url']);
    check('meta.peg: constant + setting are numbers',
        is_float($meta['peg']['constant']) && is_float($meta['peg']['setting']) && $meta['peg']['constant'] > 0);
    check('meta.user is the caller', $meta['user'] === ['id' => $ACC, 'name' => 'FFT Acct ' . $tag, 'role' => 'accountant']);
    check('meta.counts has every table, as ints',
        array_keys($meta['counts']) === ['bookings', 'ledger', 'paper', 'deposits', 'loans', 'schedules']
        && count(array_filter($meta['counts'], 'is_int')) === 6 && $meta['counts']['bookings'] >= count($ids['bookings']));
    check('meta.serverDate is today in Asia/Kolkata', $meta['serverDate'] === $today);
    check('meta.walletEnabled is a bool', is_bool($meta['walletEnabled']));
    check('meta.commission: mode + decimal strings',
        in_array($meta['commission']['mode'], ['flat_per_seat', 'percent'], true)
        && preg_match(MONEY_RE, $meta['commission']['flatDirect']) === 1 && preg_match(MONEY_RE, $meta['commission']['flatJoint']) === 1
        && preg_match(MONEY_RE, $meta['commission']['percent']) === 1);
    $zone = Database::fetch('SELECT @@session.time_zone AS tz');
    check('dbTimeZone is the session time zone', $meta['dbTimeZone'] === (string) $zone['tz'], $meta['dbTimeZone'] . ' / ' . $meta['dbUtcOffset']);

    /* ================================================================ */
    echo "\n-- agents --\n";
    $ag = feed(['part' => 'agents']);
    $ALL['agents'] = enc($ag);
    check('agents: envelope, full snapshot (next null)', envelopeOk('agents', $ag) && $ag['next'] === null);
    check('agents: exact row keys', keysExact($ag['rows'], KEYS['agents']));
    $agi = indexBy($ag['rows'], 'sellerId');
    $a = $agi[(string) $A] ?? null;
    check('the fixture agent is a seller', $a !== null);
    check('agent row: name/username/role/active', $a !== null && $a['name'] === 'FFT Agent ' . $tag && $a['username'] === 'fftfeed-agent-' . $tag
        && $a['role'] === 'agent' && $a['active'] === true);
    check('agent row: kind person (admin_profiles.agent_kind)', ($a['kind'] ?? '') === 'person');
    check('agent row: override percent from admin_profiles', ($a['overridePercent'] ?? null) === '7.50');
    check('agent row: no code issued → null, tier direct, deposits 0.00', $a !== null && $a['code'] === null && $a['tier'] === 'direct'
        && $a['depositRequired'] === '0.00' && $a['depositPaid'] === '0.00' && $a['salary'] === null);
    check('accountant who only verifies payments is not a seller', !isset($agi[(string) $SUP]));
    check('a seller who only appears in the ledger is still listed (deleted account)',
        isset($agi[(string) $ORPHAN]) && $agi[(string) $ORPHAN]['role'] === 'deleted' && $agi[(string) $ORPHAN]['active'] === false);
    $codesOk = true;
    foreach ($ag['rows'] as $r) {
        if ($r['code'] !== null && preg_match('/^SHG-\d{4}$/', $r['code']) !== 1) { $codesOk = false; }
        if (!in_array($r['tier'], ['direct', 'joint'], true) || !in_array($r['kind'], ['org', 'person'], true)) { $codesOk = false; }
    }
    check('every agent code is SHG-#### or null; tier/kind in vocabulary', $codesOk);
    $raw = Database::scalar("SELECT svalue FROM settings WHERE skey = 'agent_codes'");
    if (is_string($raw) && ($map = json_decode($raw, true)) && is_array($map) && $map !== []) {
        $k = (int) array_key_first($map);
        if (isset($agi[(string) $k])) {
            check('agent_codes map drives the code label', $agi[(string) $k]['code'] === 'SHG-' . str_pad((string) (int) $map[$k], 4, '0', STR_PAD_LEFT));
        }
    }
    check('decodeMap peels a double-encoded settings row',
        FinanceFeed::decodeMap(json_encode(json_encode(['7' => 3]))) === ['7' => 3]
        && FinanceFeed::decodeMap('[]') === [] && FinanceFeed::decodeMap('not json') === [] && FinanceFeed::decodeMap(null) === []);

    /* ================================================================ */
    echo "\n-- schedules --\n";
    $sc = feed(['part' => 'schedules', 'from' => '2099-01-01', 'to' => '2099-12-31']);
    $ALL['schedules'] = enc($sc);
    check('schedules: envelope + exact keys', envelopeOk('schedules', $sc) && keysExact($sc['rows'], KEYS['schedules']));
    $sci = indexBy($sc['rows'], 'scheduleId');
    $s1 = $sci[(string) $S1] ?? null;
    check('a far schedule with booking legs is listed', $s1 !== null);
    check('a far schedule with no legs is not', !isset($sci[(string) $S2]));
    check('schedule row: departure override, bus from the route, up direction', $s1 !== null && $s1['depTime'] === '21:15:00'
        && $s1['busId'] === $BUS && $s1['busNumber'] === 'FFT-' . $tag && $s1['direction'] === 'up' && $s1['seats'] === 36
        && $s1['routeCode'] === 'FFT' . $tag . 'U' && $s1['travelDate'] === '2099-03-01' && $s1['slot'] === 1);
    check('liveSeats / liveBookings count confirmed + completed only (3 + 1, cancelled 2 left out)',
        $s1 !== null && $s1['liveSeats'] === 4 && $s1['liveBookings'] === 2, json_encode([$s1['liveSeats'] ?? null, $s1['liveBookings'] ?? null]));
    // default window: today −400 … +60, paged
    $seen = []; $after = 0; $pages = 0;
    do {
        $pg = feed(['part' => 'schedules', 'afterId' => (string) $after, 'limit' => '2000']);
        foreach ($pg['rows'] as $r) { $seen[$r['scheduleId']] = $r; }
        $after = $pg['next']['afterId'] ?? 0;
        $pages++;
    } while ($pg['next'] !== null && $pages < 50);
    check('default window: a no-leg departure within ±7 days is listed, direction down',
        isset($seen[$S3]) && $seen[$S3]['direction'] === 'down' && $seen[$S3]['depTime'] === '17:30:00' && $seen[$S3]['busId'] === null);
    check('default window: a no-leg departure 100 days ago is not', !isset($seen[$S4]));
    $p1 = feed(['part' => 'schedules', 'from' => '2099-01-01', 'to' => '2099-12-31', 'limit' => '1', 'afterId' => (string) ($S1 - 1)]);
    check('schedules paging: a full page returns next.afterId', $p1['count'] === 1 && $p1['next'] === ['afterId' => $p1['rows'][0]['scheduleId']]);
    check('direction(): Nepal-bound names are up', FinanceFeed::direction('Kathmandu') === 'up' && FinanceFeed::direction('NEPALGUNJ') === 'up'
        && FinanceFeed::direction('Pokhara') === 'up' && FinanceFeed::direction('Surat') === 'down');

    /* ================================================================ */
    echo "\n-- bookings: rows and money states --\n";
    $bk = feed(['part' => 'bookings', 'since' => '2037-01-01 00:00:00', 'limit' => '1000']);
    $ALL['bookings'] = enc($bk);
    check('bookings: envelope + exact keys', envelopeOk('bookings', $bk) && keysExact($bk['rows'], KEYS['bookings']));
    $bi = indexBy($bk['rows'], 'bookingId');
    $state = static fn(string $k): ?string => $bi[(string) $B[$k]]['moneyState'] ?? null;
    foreach ([
        'collected' => 'collected', 'cod_due' => 'cod_due', 'proof_utr' => 'proof_awaiting_verification',
        'proof_shot' => 'proof_awaiting_verification', 'awaiting' => 'awaiting_payment',
        'cancel_coll' => 'cancelled_after_collection', 'cancel_unc' => 'cancelled_uncollected', 'cancel_cod' => 'cancelled_uncollected',
        'rejected' => 'rejected', 'expired' => 'expired', 'multi' => 'collected', 'nopay' => 'other', 'npr' => 'collected',
        'cod_settled' => 'collected', 'completed' => 'collected', 'refund_pend' => 'cancelled_after_collection',
    ] as $k => $want) {
        check("moneyState {$k} → {$want}", $state($k) === $want, (string) $state($k));
    }
    $c = $bi[(string) $B['collected']] ?? [];
    check('collected row: amounts are decimal strings', ($c['totalAmount'] ?? '') === '2500.00' && $c['discount'] === '100.00'
        && $c['bookingFee'] === '15.50' && $c['taxAmount'] === '0.00' && $c['paymentAmount'] === '2500.00' && $c['refundAmount'] === '0.00');
    check('collected row: payment fields', ($c['paymentMethod'] ?? '') === 'upi' && $c['paymentMode'] === 'utr' && $c['paymentStatus'] === 'verified'
        && $c['utr'] === 'UTRFFT' . $tag && $c['verifiedBy'] === $ACC && $c['verifiedAt'] === '2031-07-10 10:01:00' && $c['paymentRows'] === 1
        && is_int($c['paymentId']) && str_starts_with((string) $c['paymentRef'], 'PAY-'));
    check('collected row: seller, source, ticket, referral', ($c['sellerId'] ?? 0) === $A && $c['source'] === 'agent' && $c['isCod'] === false
        && $c['ticketNo'] === 'TKT-' . $pnrOf($B['collected']) && $c['referralCode'] === 'REFFFT' && $c['currency'] === 'INR');
    check('collected row: trip (outbound leg) + seats from the leg', ($c['scheduleId'] ?? 0) === $S1 && $c['travelDate'] === '2099-03-01'
        && $c['seats'] === 3 && $c['routeCode'] === 'FFT' . $tag . 'U');
    check('collected row: timestamps as stored', ($c['confirmedAt'] ?? '') === '2031-07-10 10:00:00' && preg_match(TS_RE, (string) $c['createdAt']) === 1
        && $c['cancelledAt'] === null && $c['wm'] === '2037-05-05 05:05:05');
    $np = $bi[(string) $B['nopay']] ?? [];
    check('no payment: payment fields null, seats from passengers', ($np !== [] && $np['paymentId'] === null) && $np['paymentAmount'] === null
        && $np['paymentStatus'] === null && $np['paymentRows'] === 0 && $np['seats'] === 2 && $np['scheduleId'] === null);
    $m = $bi[(string) $B['multi']] ?? [];
    check('two payments: the LATEST (MAX id) decides, paymentRows = 2', ($m['paymentMethod'] ?? '') === 'bank' && $m['paymentStatus'] === 'verified'
        && $m['utr'] === null && $m['paymentRows'] === 2);
    $cc = $bi[(string) $B['cancel_coll']] ?? [];
    check('refund: amount/status/ref, approval time = latest refund.approve (deny ignored)', ($cc['refundAmount'] ?? '') === '750.00'
        && $cc['refundStatus'] === 'processed' && $cc['refundRef'] === 'RFFFT' . $tag && $cc['refundApprovedAt'] === '2037-01-02 11:00:00'
        && $cc['cancelledAt'] === '2031-07-13 09:00:00', (string) ($cc['refundApprovedAt'] ?? ''));
    check('a UPI address in refund_ref / UTR is masked to its last four', ($bi[(string) $B['refund_pend']]['refundRef'] ?? '') === '******' . substr($PII['phone'], -4) . '@ybl'
        && ($bi[(string) $B['refund_denied']]['utr'] ?? '') === '******' . substr($PII['phone2'], -4) . '@okaxis');
    check('no approval → refundApprovedAt null', array_key_exists('refundApprovedAt', $bi[(string) $B['refund_pend']] ?? [])
        && $bi[(string) $B['refund_pend']]['refundApprovedAt'] === null);
    check('COD due row: isCod true, method cod', ($bi[(string) $B['cod_due']]['isCod'] ?? false) === true
        && $bi[(string) $B['cod_due']]['paymentMethod'] === 'cod');
    check('NPR row keeps its currency (conversion is the app\'s job)', ($bi[(string) $B['npr']]['currency'] ?? '') === 'NPR'
        && $bi[(string) $B['npr']]['totalAmount'] === '1600.00');
    check('wm follows a later payment update', ($bi[(string) $B['wm_pay']]['wm'] ?? '') === '2037-05-05 06:00:00');
    $vocab = true;
    foreach ($bk['rows'] as $r) {
        if (!in_array($r['moneyState'], FinanceFeed::MONEY_STATES, true)) { $vocab = false; }
        foreach (['totalAmount', 'discount', 'bookingFee', 'taxAmount', 'refundAmount'] as $mk) {
            if (preg_match(MONEY_RE, (string) $r[$mk]) !== 1) { $vocab = false; }
        }
        if ($r['paymentAmount'] !== null && preg_match(MONEY_RE, $r['paymentAmount']) !== 1) { $vocab = false; }
    }
    check('every row: moneyState in vocabulary, money fields are decimal strings', $vocab);

    /* ================================================================ */
    echo "\n-- bookings: keyset paging --\n";
    $mine = array_flip(array_map('intval', $ids['bookings']));
    foreach ([1, 5, 7, 23, 1000] as $lim) {
        $got = []; $dups = 0; $order = true; $prev = ['', 0]; $since = '2037-01-01 00:00:00'; $after = 0; $calls = 0; $fullNext = true;
        while (true) {
            $pg = feed(['part' => 'bookings', 'since' => $since, 'afterId' => (string) $after, 'limit' => (string) $lim]);
            $calls++;
            foreach ($pg['rows'] as $r) {
                $key = [$r['wm'], $r['bookingId']];
                if (strcmp($key[0], $prev[0]) < 0 || ($key[0] === $prev[0] && $key[1] <= $prev[1])) { $order = false; }
                $prev = $key;
                if (isset($got[$r['bookingId']])) { $dups++; }
                $got[$r['bookingId']] = true;
            }
            if ($pg['count'] === $lim && $pg['next'] === null) { $fullNext = false; }
            if ($pg['count'] < $lim && $pg['next'] !== null) { $fullNext = false; }
            if ($pg['next'] === null || $calls > 400) { break; }
            if ($pg['next'] !== ['since' => $pg['rows'][$pg['count'] - 1]['wm'], 'afterId' => $pg['rows'][$pg['count'] - 1]['bookingId']]) { $fullNext = false; }
            $since = $pg['next']['since'];
            $after = $pg['next']['afterId'];
        }
        $missing = array_diff_key($mine, $got);
        check("limit {$lim}: every fixture booking exactly once, ordered by (wm, id)",
            $missing === [] && $dups === 0 && $order, count($got) . ' rows in ' . $calls . ' calls, ' . count($missing) . ' missing, ' . $dups . ' dups');
        check("limit {$lim}: next only on a full page, = {since: last wm, afterId: last id}", $fullNext);
    }
    // The 23 rows sharing one watermark come back in id order.
    $grp = feed(['part' => 'bookings', 'since' => '2037-05-06 00:00:00', 'afterId' => '0', 'limit' => '1000']);
    $grpIds = array_values(array_filter(array_column($grp['rows'], 'bookingId'), static fn($i) => in_array($i, $SAME, true)));
    $sorted = $SAME; sort($sorted);
    check('same-watermark group: all 23, ascending id', $grpIds === $sorted);
    $mid = $sorted[10];
    $rest = feed(['part' => 'bookings', 'since' => '2037-05-06 00:00:00', 'afterId' => (string) $mid, 'limit' => '1000']);
    $restIds = array_values(array_filter(array_column($rest['rows'], 'bookingId'), static fn($i) => in_array($i, $SAME, true)));
    check('resuming inside the group continues after afterId, no repeat', $restIds === array_slice($sorted, 11));
    $none = feed(['part' => 'bookings', 'since' => '2037-12-31 00:00:00']);
    check('a watermark past every row returns nothing, next null', $none['count'] === 0 && $none['next'] === null);
    $def = FinanceFeed::params(['part' => 'bookings']);
    check('defaults: since 2000-01-01 00:00:00, afterId 0, limit 500', $def['since'] === '2000-01-01 00:00:00' && $def['afterId'] === 0 && $def['limit'] === 500);

    /* ================================================================ */
    echo "\n-- booking_ids --\n";
    $minB = min($ids['bookings']);
    $got = []; $after = $minB - 1; $dups = 0; $calls = 0;
    do {
        $pg = feed(['part' => 'booking_ids', 'afterId' => (string) $after, 'limit' => '6']);
        foreach ($pg['rows'] as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !is_int($pair[0]) || !is_string($pair[1])) { $dups += 1000; }
            if (isset($got[$pair[0]])) { $dups++; }
            $got[$pair[0]] = $pair[1];
        }
        $after = $pg['next']['afterId'] ?? 0;
        $calls++;
    } while ($pg['next'] !== null && $calls < 1000);
    $ALL['booking_ids'] = enc($pg);
    check('booking_ids: [[id, pnr]] pairs, paged without duplicates', envelopeOk('booking_ids', $pg) && $dups === 0);
    $allThere = true;
    foreach ($ids['bookings'] as $bid) { if (($got[$bid] ?? '') !== $pnrOf($bid)) { $allThere = false; } }
    check('booking_ids: every fixture id with its PNR', $allThere);
    check('booking_ids: default limit 20000', FinanceFeed::params(['part' => 'booking_ids'])['limit'] === 20000);

    /* ================================================================ */
    echo "\n-- ledger --\n";
    $minL = min($ids['ledger']);
    $lg = feed(['part' => 'ledger', 'afterId' => (string) ($minL - 1), 'limit' => '5000']);
    $ALL['ledger'] = enc($lg);
    check('ledger: envelope + exact keys', envelopeOk('ledger', $lg) && keysExact($lg['rows'], KEYS['ledger']));
    $li = indexBy($lg['rows'], 'ledgerId');
    foreach ([
        'commission' => 'commission', 'cash_due' => 'cash_due', 'void' => 'commission_void', 'payout' => 'payout',
        'handover' => 'cash_handover', 'salary' => 'salary', 'loan_given' => 'loan', 'loan_repaid' => 'loan',
        'loan_writeoff' => 'loan', 'advance' => 'advance', 'paper_comm' => 'paper_ticket_commission',
        'paper_cash' => 'paper_ticket_cash', 'adjust' => 'adjustment',
    ] as $key => $want) {
        check("ledger kind {$key} → {$want}", ($li[(string) $L[$key]]['kind'] ?? '') === $want, (string) ($li[(string) $L[$key]]['kind'] ?? 'missing'));
    }
    $lc = $li[(string) $L['commission']] ?? [];
    check('commission row: signed amount, booking, pnr, status, note kept', ($lc['amount'] ?? '') === '600.00' && $lc['bookingId'] === $B['collected']
        && $lc['pnr'] === $pc && $lc['bookingStatus'] === 'confirmed' && $lc['bookingMissing'] === false
        && str_starts_with((string) $lc['note'], 'Commission on ') && $lc['account'] === 'commission' && $lc['entryType'] === 'commission'
        && $lc['sellerId'] === $A && $lc['createdAt'] === '2031-07-10 10:00:00');
    check('void / payout / handover amounts are negative strings', ($li[(string) $L['void']]['amount'] ?? '') === '-200.00'
        && $li[(string) $L['payout']]['amount'] === '-300.00' && $li[(string) $L['handover']]['amount'] === '-300.00');
    check('a booking-linked note keeps its words but loses a typed phone / e-mail',
        ($li[(string) $L['void']]['note'] ?? '') === 'Reversed — customer [phone] / [email] asked (' . $pk . ')', (string) ($li[(string) $L['void']]['note'] ?? ''));
    check('note is dropped on every booking-less row', array_key_exists('note', $li[(string) $L['payout']] ?? []) && $li[(string) $L['payout']]['note'] === null
        && $li[(string) $L['paper_comm']]['note'] === null && $li[(string) $L['loan_given']]['note'] === null && $li[(string) $L['adjust']]['note'] === null);
    check('loan rows carry loanId from "ADVANCE L<id>"', ($li[(string) $L['loan_given']]['loanId'] ?? 0) === $loanId
        && $li[(string) $L['loan_writeoff']]['loanId'] === $loanId && $li[(string) $L['advance']]['loanId'] === null);
    check('writeOff flags only the write-off credit', ($li[(string) $L['loan_writeoff']]['writeOff'] ?? false) === true
        && $li[(string) $L['loan_repaid']]['writeOff'] === false && $li[(string) $L['loan_given']]['writeOff'] === false
        && $li[(string) $L['commission']]['writeOff'] === false);
    $lm = $li[(string) $L['missing']] ?? [];
    check('a hard-deleted booking: bookingMissing, PNR falls back to ref', ($lm['bookingMissing'] ?? false) === true
        && $lm['pnr'] === 'SHG-FFT-GONE-' . $tag && $lm['bookingStatus'] === null && $lm['bookingId'] === $gone);
    check('paper-ticket rows: no booking, no pnr', array_key_exists('bookingId', $li[(string) $L['paper_comm']] ?? []) && $li[(string) $L['paper_comm']]['bookingId'] === null
        && $li[(string) $L['paper_comm']]['pnr'] === null && $li[(string) $L['paper_comm']]['ref'] === $paperNo);
    check('createdBy is an id or null', ($li[(string) $L['payout']]['createdBy'] ?? 0) === $ACC && $li[(string) $L['commission']]['createdBy'] === null);
    $got = []; $after = $minL - 1; $dups = 0; $calls = 0; $asc = true; $last = 0;
    do {
        $pg = feed(['part' => 'ledger', 'afterId' => (string) $after, 'limit' => '4']);
        foreach ($pg['rows'] as $r) {
            if (isset($got[$r['ledgerId']])) { $dups++; }
            if ($r['ledgerId'] <= $last) { $asc = false; }
            $last = $r['ledgerId'];
            $got[$r['ledgerId']] = true;
        }
        $after = $pg['next']['afterId'] ?? 0;
        $calls++;
    } while ($pg['next'] !== null && $calls < 1000);
    check('ledger paging by afterId: all fixture rows, ascending, no duplicates',
        array_diff($ids['ledger'], array_keys($got)) === [] && $dups === 0 && $asc);
    check('ledgerKind(): case-insensitive like the SQL LIKE', FinanceFeed::ledgerKind('adjustment', 'salary 2031-01', null) === 'salary'
        && FinanceFeed::ledgerKind('adjustment', 'Advance L9', null) === 'loan' && FinanceFeed::ledgerKind('commission', 'X', 5) === 'commission'
        && FinanceFeed::ledgerKind('cash_due', 'P-1', null) === 'paper_ticket_cash');

    /* ================================================================ */
    echo "\n-- paper / deposits / loans / shifts --\n";
    $pp = feed(['part' => 'paper', 'afterId' => (string) ($P1 - 1)]);
    $ALL['paper'] = enc($pp);
    check('paper: envelope + exact keys', envelopeOk('paper', $pp) && keysExact($pp['rows'], KEYS['paper']));
    $ppi = indexBy($pp['rows'], 'paperId');
    check('register-only paper ticket', ($ppi[(string) $P1]['shape'] ?? '') === 'register_only' && $ppi[(string) $P1]['bookingId'] === null
        && $ppi[(string) $P1]['amount'] === '900.00' && $ppi[(string) $P1]['commission'] === '150.00' && $ppi[(string) $P1]['paymentMode'] === 'cash'
        && $ppi[(string) $P1]['routeCode'] === 'FFT' . $tag . 'U' && $ppi[(string) $P1]['pax'] === 1 && $ppi[(string) $P1]['paperTicketNo'] === $paperNo);
    check('seated paper ticket links its booking', ($ppi[(string) $P2]['shape'] ?? '') === 'seated_booking'
        && $ppi[(string) $P2]['bookingId'] === $B['completed'] && $ppi[(string) $P2]['pnr'] === $pnrOf($B['completed']) && $ppi[(string) $P2]['status'] === 'void');

    $dp = feed(['part' => 'deposits', 'afterId' => (string) ($D1 - 1)]);
    $ALL['deposits'] = enc($dp);
    $dpi = indexBy($dp['rows'], 'depositId');
    check('deposits: envelope + exact keys + signed amounts', envelopeOk('deposits', $dp) && keysExact($dp['rows'], KEYS['deposits'])
        && ($dpi[(string) $D1]['amount'] ?? '') === '5000.00' && $dpi[(string) $D2]['amount'] === '-1000.00'
        && $dpi[(string) $D1]['ref'] === 'RCPT-FFT-' . $tag && $dpi[(string) $D2]['ref'] === null && $dpi[(string) $D1]['sellerId'] === $A);
    check('a UPI address typed as a deposit ref keeps only its last four', ($dpi[(string) $D3]['ref'] ?? '') === '******' . substr($PII['agentPhone'], -4) . '@upi',
        (string) ($dpi[(string) $D3]['ref'] ?? ''));

    $ln = feed(['part' => 'loans', 'afterId' => (string) ($loanId - 1)]);
    $ALL['loans'] = enc($ln);
    $lni = indexBy($ln['rows'], 'loanId');
    check('loans: envelope + exact keys + register figures', envelopeOk('loans', $ln) && keysExact($ln['rows'], KEYS['loans'])
        && ($lni[(string) $loanId]['principal'] ?? '') === '5000.00' && $lni[(string) $loanId]['recovered'] === '1000.00'
        && $lni[(string) $loanId]['status'] === 'written_off' && $lni[(string) $loanId]['kind'] === 'advance'
        && $lni[(string) $loanId]['issuedOn'] === '2031-07-01' && $lni[(string) $loanId]['settledAt'] === '2031-07-20 10:00:00');

    $sh = feed(['part' => 'shifts', 'since' => '2037-01-01 00:00:00']);
    $ALL['shifts'] = enc($sh);
    $shi = indexBy($sh['rows'], 'shiftId');
    check('shifts: envelope + exact keys', envelopeOk('shifts', $sh) && keysExact($sh['rows'], KEYS['shifts']));
    check('closed shift: frozen figures as decimal strings', ($shi[(string) $SH1]['cashSales'] ?? '') === '1000.50'
        && $shi[(string) $SH1]['variance'] === '-10.00' && $shi[(string) $SH1]['expectedCash'] === '1400.50'
        && $shi[(string) $SH1]['staffName'] === 'FFT Agent ' . $tag && $shi[(string) $SH1]['tickets'] === 3 && $shi[(string) $SH1]['seats'] === 4
        && $shi[(string) $SH1]['closedAt'] === '2031-07-10 20:00:00');
    check('an open shift is not sent', !isset($shi[(string) $SH2]));
    $s1p = feed(['part' => 'shifts', 'since' => '2037-01-01 00:00:00', 'limit' => '1']);
    $s2p = feed(['part' => 'shifts', 'since' => $s1p['next']['since'] ?? '', 'afterId' => (string) ($s1p['next']['afterId'] ?? 0), 'limit' => '1']);
    check('shifts keyset: two shifts sharing updated_at come one per page, by id',
        ($s1p['rows'][0]['shiftId'] ?? 0) === min($SH1, $SH3) && ($s2p['rows'][0]['shiftId'] ?? 0) === max($SH1, $SH3)
        && $s1p['next'] === ['since' => '2037-02-02 02:02:02', 'afterId' => min($SH1, $SH3)]);

    /* ================================================================ */
    echo "\n-- daybook (Accounting basis) --\n";
    $db = feed(['part' => 'daybook', 'from' => '2031-07-01', 'to' => '2031-07-31']);
    $ALL['daybook'] = enc($db);
    check('daybook: envelope + exact keys', envelopeOk('daybook', $db) && keysExact($db['rows'], KEYS['daybook']) && $db['next'] === null);
    $dbi = [];
    foreach ($db['rows'] as $r) { $dbi[$r['day'] . '|' . $r['bucket']] = $r; }
    $want = [
        '2031-07-10|upi' => [1, '2500.00'], '2031-07-10|cod_pending' => [1, '1800.00'],
        '2031-07-11|bank' => [1, '1200.00'], '2031-07-11|esewa' => [1, '1000.00'],
        '2031-07-12|unknown' => [1, '900.00'], '2031-07-12|cash' => [2, '1000.00'],
    ];
    foreach ($want as $k => [$cnt, $amt]) {
        check("daybook {$k}: {$cnt} booking(s), {$amt}", ($dbi[$k]['bookings'] ?? -1) === $cnt && ($dbi[$k]['amount'] ?? '') === $amt,
            json_encode($dbi[$k] ?? null));
    }
    check('daybook: cancelled bookings are not collections; buckets in vocabulary',
        !isset($dbi['2031-07-12|upi']) && count(array_filter($db['rows'], static fn($r) => !in_array($r['bucket'],
            ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod_pending', 'unknown'], true))) === 0);
    // 600 + 200 − 200 (void) + 150 (paper ticket) + 200 (row whose booking was deleted); payout/salary/advance excluded
    check('daybook totals: commission accrued = commission + void only', ($db['totals']['commissionAccrued'] ?? '') === '950.00',
        (string) ($db['totals']['commissionAccrued'] ?? ''));
    check('daybook totals: refunds processed / pending / all statuses', ($db['totals']['refundsProcessed'] ?? '') === '750.00'
        && $db['totals']['refundsPending'] === '400.00' && $db['totals']['refundsAll'] === '1250.00', json_encode($db['totals'] ?? null));
    $one = feed(['part' => 'daybook', 'from' => '2031-07-11', 'to' => '2031-07-11']);
    check('daybook: to is inclusive, from/to filter by confirmed_at', count($one['rows']) === 2 && $one['from'] === '2031-07-11');

    /* ================================================================ */
    echo "\n-- NO PII in any part --\n";
    $ALL['schedules_default'] = enc(feed(['part' => 'schedules']));
    $ALL['bookings_default'] = enc(feed(['part' => 'bookings', 'since' => '2037-05-05 00:00:00', 'limit' => '1000']));
    foreach ($PII as $what => $needle) {
        $hits = [];
        foreach ($ALL as $part => $json) {
            if (stripos($json, $needle) !== false) { $hits[] = $part; }
        }
        check("no {$what} anywhere", $hits === [], $hits === [] ? '' : 'found in ' . implode(', ', $hits));
    }
    $leakKeys = [];
    foreach ($ALL as $part => $json) {
        if (preg_match('/"(contact_?phone|contactEmail|phone|email|payer\w*|passenger\w*|idNumber|id_number|ip\w*|userAgent|actor\w*|old_?value|new_?value|address|seatText|denominations|password\w*)"\s*:/i', $json, $mm) === 1
            && $part !== 'meta') {
            $leakKeys[] = $part . ':' . $mm[1];
        }
    }
    check('no personal-data keys outside meta.company', $leakKeys === [], implode(', ', $leakKeys));

    /* ================================================================ */
    echo "\n-- parameters --\n";
    check('unknown part is refused', invalid(['part' => 'users']) && invalid(['part' => '']) && invalid([]) && invalid(['part' => ['meta']]));
    check('afterId must be a whole number', invalid(['part' => 'ledger', 'afterId' => 'abc']) && invalid(['part' => 'ledger', 'afterId' => '-1'])
        && invalid(['part' => 'ledger', 'afterId' => '1.5']) && invalid(['part' => 'ledger', 'afterId' => ['1']]));
    check('limit must be a whole number', invalid(['part' => 'bookings', 'limit' => 'all']) && invalid(['part' => 'bookings', 'limit' => '-5']));
    check('since must be a real timestamp', invalid(['part' => 'bookings', 'since' => '2026-13-01 00:00:00'])
        && invalid(['part' => 'bookings', 'since' => 'yesterday']) && invalid(['part' => 'bookings', 'since' => '2026-02-30 10:00:00'])
        && invalid(['part' => 'bookings', 'since' => '2026-01-01 25:00:00']) && invalid(['part' => 'shifts', 'since' => "2026-01-01' OR 1=1"]));
    check('since accepts fractional seconds (TIMESTAMP(n)), refuses 7 digits',
        FinanceFeed::params(['part' => 'bookings', 'since' => '2026-01-02 03:04:05.250'])['since'] === '2026-01-02 03:04:05.250'
        && invalid(['part' => 'bookings', 'since' => '2026-01-02 03:04:05.1234567']));
    check('schema probes: columns found, missing table/column false, bad identifier refused',
        FinanceFeed::hasColumn('bookings', 'pnr') && FinanceFeed::hasTable('agent_ledger') && !FinanceFeed::hasTable('no_such_table_fft')
        && !FinanceFeed::hasColumn('bookings', 'no_such_column') && FinanceFeed::columns('bookings; DROP TABLE x') === []);
    check('since accepts a bare date or a T separator', FinanceFeed::params(['part' => 'bookings', 'since' => '2026-01-02'])['since'] === '2026-01-02 00:00:00'
        && FinanceFeed::params(['part' => 'bookings', 'since' => '2026-01-02T03:04:05'])['since'] === '2026-01-02 03:04:05');
    check('dates must be real Y-m-d', invalid(['part' => 'daybook', 'from' => '2026-02-30']) && invalid(['part' => 'schedules', 'to' => '09/10/2026'])
        && invalid(['part' => 'daybook', 'from' => '2026-10-09', 'to' => '2026-10-01']));
    check('the day-book window is at most 400 days', invalid(['part' => 'daybook', 'from' => '2025-01-01', 'to' => '2026-03-01'])
        && !invalid(['part' => 'daybook', 'from' => '2025-01-01', 'to' => '2026-02-05']));
    check('limits are clamped', FinanceFeed::params(['part' => 'bookings', 'limit' => '999999'])['limit'] === 1000
        && FinanceFeed::params(['part' => 'bookings', 'limit' => '0'])['limit'] === 1
        && FinanceFeed::params(['part' => 'ledger', 'limit' => '99999'])['limit'] === 5000
        && FinanceFeed::params(['part' => 'schedules', 'limit' => '99999'])['limit'] === 2000
        && FinanceFeed::params(['part' => 'booking_ids', 'limit' => '99999'])['limit'] === 20000);
    check('unknown parameters are ignored', FinanceFeed::params(['part' => 'agents', '_' => '123'])['part'] === 'agents');
    check('money(): decimal strings, signed, no float noise', FinanceFeed::money('1234.5') === '1234.50' && FinanceFeed::money('-0.00') === '0.00'
        && FinanceFeed::money('-200') === '-200.00' && FinanceFeed::money(0.1 + 0.2) === '0.30' && FinanceFeed::money('625.000000') === '625.00'
        && FinanceFeed::money(null) === null && FinanceFeed::money('00012.30') === '12.30' && FinanceFeed::money(1e7) === '10000000.00');
    check('fixMojibake(): repairs latin1 double-encoding only', FinanceFeed::fixMojibake(mb_convert_encoding('एस हरि', 'UTF-8', 'ISO-8859-1')) === 'एस हरि'
        && FinanceFeed::fixMojibake('एस हरि ग्लोबल') === 'एस हरि ग्लोबल' && FinanceFeed::fixMojibake('₹200 · Café — ok') === '₹200 · Café — ok');

    /* ================================================================ */
    echo "\n-- gates (source level) --\n";
    $root = dirname(__DIR__);
    $ep   = (string) @file_get_contents($root . '/admin/api/finance-feed.php');
    $epc  = codeOnly($ep);
    $cls  = (string) @file_get_contents($root . '/includes/financefeed.php');
    $clsc = codeOnly($cls);
    $sql  = stringsOnly($cls);
    check('endpoint exists and boots through _guard.php', $ep !== '' && str_contains($epc, "require dirname(__DIR__) . '/_guard.php';"));
    check("endpoint: admin_boot('reports.view') before anything is read",
        ($pb = strpos($epc, "admin_boot('reports.view')")) !== false && $pb < (int) strpos($epc, 'FinanceFeed::'));
    check('endpoint: refuses a counter agent with 403 JSON',
        preg_match('/if \(Auth::isCounterAgent\(\)\) \{\s*Response::forbidden\(/', $epc) === 1);
    check('endpoint: GET only (405 + Allow)', preg_match("/REQUEST_METHOD'\] \?\? 'GET'\) !== 'GET'\) \{\s*header\('Allow: GET'\);\s*Response::error\([^;]+, 405\)/", $epc) === 1);
    check("endpoint: rate limit bucket finance_feed 240/min per admin → 429",
        str_contains($epc, "Security::rateLimit('finance_feed', 'admin:' . (int) (\$admin['id'] ?? 0), 240, 60)") && str_contains($epc, ', 429)'));
    check('endpoint: bad parameter → 422', preg_match('/catch \(InvalidArgumentException \$e\) \{\s*Response::error\(\$e->getMessage\(\), 422\)/', $epc) === 1);
    check('endpoint: no-store + noindex, JSON via Response::json', str_contains($epc, "header('X-Robots-Tag: noindex, nofollow')")
        && str_contains($epc, 'no-store') && str_contains($epc, 'Response::json(FinanceFeed::run('));
    check('endpoint: lives under /api/ (JSON 401/403, no HTML CSP)', is_file($root . '/admin/api/finance-feed.php'));
    check('endpoint: runs no SQL itself', !str_contains($epc, 'Database::'));
    check('class: guarded include (SHG_APP)', str_contains($clsc, "if (!defined('SHG_APP'))"));
    check('class: read-only — no write helpers', preg_match('/Database::(insert|insertIgnore|update|delete|run|query|begin|commit|transaction|pdo)\b/', $clsc) !== 1);
    check('class: read-only — no write statements in its SQL', preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE|GRANT|LOCK)\b/', $sql) !== 1);
    check('class: never SELECT *', preg_match('/SELECT\s+(\w+\.)?\*/i', $sql) !== 1);
    $banned = ['contact_phone', 'contact_email', 'id_number', 'id_type', 'ip_address', 'user_agent', 'payer_name', 'payer_phone',
        'gateway_payload', 'passenger_name', 'passenger_phone', 'seat_text', 'actor_name', 'old_value', 'new_value', 'password_hash',
        'file_path', 'display_phone', 'display_email', 'payout_account', 'kyc_', 'denominations', 'full_name AS passenger', 'a.phone', 'a.email',
        'bp.full_name', 'o.note', 'd.note', 'admin_note', 'cancel_reason'];
    $found = array_values(array_filter($banned, static fn(string $b): bool => stripos($sql, $b) !== false));
    check('class: no personal-data column is ever selected', $found === [], implode(', ', $found));
    check('class: settings are read by whitelisted key only', !preg_match('/FROM\s+settings/i', $sql) && !str_contains($clsc, 'Settings::all'));
    $ra = (string) @file_get_contents($root . '/tests/run-all.php');
    check('suite is registered in run-all.php CORE_SUITES',
        preg_match("/const CORE_SUITES = \[.*'finance-feed-test\.php'\s*=>.*?\];/s", $ra) === 1
        && strpos($ra, "'finance-feed-test.php'") < strpos($ra, 'const KNOWN_STALE'));

    /* ================================================================ */
    echo "\n-- HTTP round trip (" . FFT_BASE . ") --\n";
    $http = static function (string $method, string $path, ?string $jar = null, array $form = []): array {
        $ch = curl_init(FFT_BASE . $path);
        $o  = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
               CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 60];
        if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
        if ($form !== []) { $o[CURLOPT_POSTFIELDS] = http_build_query($form); }
        curl_setopt_array($ch, $o);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if (!is_string($raw)) { return ['code' => 0, 'headers' => '', 'body' => '', 'json' => null]; }
        $body = substr($raw, $hsz);
        $j    = json_decode($body, true);
        return ['code' => $code, 'headers' => substr($raw, 0, $hsz), 'body' => $body, 'json' => is_array($j) ? $j : null];
    };
    $probe = function_exists('curl_init') ? $http('GET', '/admin/login.php') : ['code' => 0];
    if ($probe['code'] !== 200) {
        echo "  \033[33mSKIP\033[0m  no dev server at " . FFT_BASE . " (start: php -S 127.0.0.1:8891 -t " . $root . ")\n";
    } else {
        $u = $http('GET', '/admin/api/finance-feed.php?part=meta');
        check('signed out → 401 JSON', $u['code'] === 401 && ($u['json']['ok'] ?? true) === false && ($u['json']['code'] ?? 0) === 401, 'HTTP ' . $u['code']);
        $up = $http('POST', '/admin/api/finance-feed.php?part=meta');
        check('signed out POST → 401 too (auth first)', $up['code'] === 401);

        Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'");
        $login = static function (string $user) use ($http, $tag): string {
            $jar = sys_get_temp_dir() . '/fft_' . $user . '_' . $tag . '_' . getmypid() . '.cookies';
            @unlink($jar);
            $lp = $http('GET', '/admin/login.php', $jar);
            preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $mm);
            $http('POST', '/admin/login.php', $jar, ['shg_csrf' => $mm[1] ?? '', 'username' => $user, 'password' => FFT_PW]);
            return $jar;
        };
        $jAcc = $login('fftfeed-acct-' . $tag);
        $ok = $http('GET', '/admin/api/finance-feed.php?part=meta', $jAcc);
        check('accountant (reports.view) → 200 meta envelope', $ok['code'] === 200 && ($ok['json']['ok'] ?? false) === true
            && ($ok['json']['part'] ?? '') === 'meta' && isset($ok['json']['company']['name']) && ($ok['json']['user']['id'] ?? 0) === $ACC,
            'HTTP ' . $ok['code'] . ' ' . substr($ok['body'], 0, 120));
        check('headers: JSON, no-store, noindex', stripos($ok['headers'], 'Content-Type: application/json') !== false
            && preg_match('/^Cache-Control:.*no-store/mi', $ok['headers']) === 1 && preg_match('/^X-Robots-Tag: noindex/mi', $ok['headers']) === 1);
        $hb = $http('GET', '/admin/api/finance-feed.php?part=bookings&since=' . rawurlencode('2037-01-01 00:00:00') . '&limit=5', $jAcc);
        check('bookings over HTTP: 5 rows + next keyset', $hb['code'] === 200 && ($hb['json']['count'] ?? 0) === 5
            && isset($hb['json']['next']['since'], $hb['json']['next']['afterId']));
        $hl = $http('GET', '/admin/api/finance-feed.php?part=ledger&afterId=' . ($minL - 1), $jAcc);
        $hp = $http('GET', '/admin/api/finance-feed.php?part=paper&afterId=' . ($P1 - 1), $jAcc);
        $leak = [];
        foreach ([$ok, $hb, $hl, $hp] as $resp) {
            foreach ($PII as $what => $needle) { if (stripos($resp['body'], $needle) !== false) { $leak[] = $what; } }
        }
        check('no PII over the wire', $leak === [], implode(', ', array_unique($leak)));
        $p = $http('POST', '/admin/api/finance-feed.php?part=meta', $jAcc, ['x' => '1']);
        check('POST → 405 with Allow: GET', $p['code'] === 405 && ($p['json']['code'] ?? 0) === 405 && preg_match('/^Allow: GET/mi', $p['headers']) === 1, 'HTTP ' . $p['code']);
        $bad = $http('GET', '/admin/api/finance-feed.php?part=users', $jAcc);
        check('unknown part → 422 JSON', $bad['code'] === 422 && ($bad['json']['ok'] ?? true) === false);
        $bad2 = $http('GET', '/admin/api/finance-feed.php?part=ledger&afterId=abc', $jAcc);
        $bad3 = $http('GET', '/admin/api/finance-feed.php?part=bookings&since=2026-13-45', $jAcc);
        $bad4 = $http('GET', '/admin/api/finance-feed.php?part=daybook&from=2024-01-01&to=2026-01-01', $jAcc);
        check('bad afterId / since / day-book window → 422', $bad2['code'] === 422 && $bad3['code'] === 422 && $bad4['code'] === 422);
        Database::run("UPDATE rate_limits SET hits = 1000 WHERE bucket = 'finance_feed' AND identifier = :i", ['i' => 'admin:' . $ACC]);
        $rl = $http('GET', '/admin/api/finance-feed.php?part=agents', $jAcc);
        check('over 240 a minute → 429 JSON', $rl['code'] === 429 && ($rl['json']['code'] ?? 0) === 429, 'HTTP ' . $rl['code']);
        Database::run("DELETE FROM rate_limits WHERE bucket = 'finance_feed' AND identifier = :i", ['i' => 'admin:' . $ACC]);

        Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'");
        $jAg = $login('fftfeed-agent-' . $tag);
        $ag403 = $http('GET', '/admin/api/finance-feed.php?part=meta', $jAg);
        check('counter agent even WITH a reports.view grant → 403 JSON', $ag403['code'] === 403
            && str_contains((string) ($ag403['json']['error'] ?? ''), 'counter agents'), 'HTTP ' . $ag403['code'] . ' ' . substr($ag403['body'], 0, 100));
        $jSup = $login('fftfeed-support-' . $tag);
        $s403 = $http('GET', '/admin/api/finance-feed.php?part=meta', $jSup);
        check('support (no reports.view) → 403 JSON', $s403['code'] === 403 && ($s403['json']['ok'] ?? true) === false, 'HTTP ' . $s403['code']);
        foreach ([$jAcc, $jAg, $jSup] as $j) { @unlink($j); }
    }
} catch (Throwable $e) {
    check('suite ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $left = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p', ['p' => $PFX . '%'], 0)
          + (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE :u", ['u' => 'fftfeed-%-' . $tag], 0);
    check('fixtures removed', $left === 0, $left . ' left');
}

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);

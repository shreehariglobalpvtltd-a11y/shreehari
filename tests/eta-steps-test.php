<?php
/**
 * =====================================================================
 *  eta-steps-test.php - "bus about 15 min / 5 min from your stop" as web
 *  push, each step once, and silence whenever it cannot be sure
 *  (15 Nov 2026, P8: EtaAlerts::runSteps, cron/eta-alerts.php).
 *
 *  On a far-future trip with a synthetic clock and a driver-app coach
 *  (live_trips row, the same fix source as the 30-minute alert) this
 *  suite pins:
 *
 *    - switch off (eta_push_steps_on = 0, the default) = nothing at all
 *    - a stale driver fix (15 min old) = nothing, and no fall-back to the
 *      shared `livebus` key for a coach that has its own app
 *    - at ~12 min the 15 step goes to every confirmed passenger of the
 *      NEXT stop whose ticket (or verified mobile) has a push phone; not
 *      the stop after, not the stop passed, not a cancelled ticket, not a
 *      ticket with no phone (and that one is not claimed)
 *    - told ONCE: a later run at ~10 min sends nothing
 *    - at ~4 min the 5 step goes once - also to a passenger who turned
 *      alerts on between the steps
 *    - a GPS wobble back to ~12 min never sends "15" after "5"
 *    - a step the 30-minute alert already covered is not repeated
 *    - the claim is UNIQUE(booking, trip, step)
 *    - the banner is Nepali first with English kept, names the stop,
 *      expires before it turns false; the sender is a stub (no real push,
 *      no WhatsApp)
 *
 *    php -c .claude/php-dev.ini tests/eta-steps-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/routegeo.php';
require_once INCLUDE_PATH . '/webpush.php';

if (DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  refusing to write: DB_NAME is not shari_test\n";
    exit(0);
}

/* The scenario is measured on the line of stops (like live-trips-test). */
RouteGeo::$enabled = false;

const ES_DATE = '2099-04-14';
const ES_PREV = '2099-04-13';
const ES_TAG  = 'SHG-EST-';
const ES_UA   = 'eta-steps-test';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . ES_TAG . "%'") as $b) {
        Database::delete('eta_alert_steps', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('trip_eta_alerts', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('booking_legs', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date IN (:a, :b)', ['a' => ES_DATE, 'b' => ES_PREV]) as $s) {
        Database::delete('live_trips', 'schedule_id = :s', ['s' => (int) $s['id']]);
        Database::delete('schedules', 'id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('push_subscriptions', 'ua = :u', ['u' => ES_UA]);
};

$n = 0;
function rider(int $sid, string $boarding, string $status = 'confirmed'): array {
    global $n; $n++;
    $phone = '90000047' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    $bid = Database::insert('bookings', [
        'pnr' => ES_TAG . $n . '-' . substr(md5((string) microtime(true)), 0, 6), 'contact_phone' => $phone,
        'total_amount' => 2000, 'status' => $status, 'source' => 'admin',
    ]);
    Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => ES_DATE, 'seat_count' => 1, 'boarding_stop' => $boarding]);
    return ['id' => $bid, 'phone' => $phone];
}
/** A push phone for a ticket (api/push.php with the PNR) or for a verified mobile. */
function phoneFor(?int $bookingId, string $mobile = ''): int {
    $ep = 'https://push.invalid/' . ES_UA . '/' . bin2hex(random_bytes(8));
    return Database::insert('push_subscriptions', [
        'endpoint' => $ep, 'endpoint_hash' => sha1($ep), 'p256dh' => 'test', 'auth' => 'test',
        'phone' => $mobile !== '' ? normalisePhone($mobile) : '', 'booking_id' => $bookingId, 'ua' => ES_UA,
    ]);
}

echo "-- steps and the banner --\n";
check('a 12- or 10-minute ETA belongs to the 15 step; 5, 4 and 3 to the 5 step',
    EtaAlerts::stepFor(12, [15, 5]) === 15 && EtaAlerts::stepFor(10, [15, 5]) === 15
    && EtaAlerts::stepFor(5, [15, 5]) === 5 && EtaAlerts::stepFor(4, [15, 5]) === 5 && EtaAlerts::stepFor(3, [15, 5]) === 5);
check('farther than every step = no step', EtaAlerts::stepFor(16, [15, 5]) === null);
check('between the windows (ETA 6-9) no step: "about 15 minutes" is never said with the coach 6 minutes away',
    EtaAlerts::stepFor(9, [15, 5]) === null && EtaAlerts::stepFor(6, [15, 5]) === null
    && EtaAlerts::stepFor(6, [30, 5]) === null && EtaAlerts::stepFor(12, [20, 10, 5]) === null && EtaAlerts::stepFor(14, [20, 10, 5]) === 20);
check('the banner expires before its number turns false (15 told at 15 -> 6 min, at 12 -> 3 min, at 10 -> 1 min; 5 told at 5 -> 3 min, at 3 -> 1 min)',
    EtaAlerts::stepTtl(15) === 360 && EtaAlerts::stepTtl(15, 12) === 180 && EtaAlerts::stepTtl(15, 10) === 60
    && EtaAlerts::stepTtl(5) === 180 && EtaAlerts::stepTtl(5, 3) === 60,
    EtaAlerts::stepTtl(15) . '/' . EtaAlerts::stepTtl(15, 12) . '/' . EtaAlerts::stepTtl(5) . '/' . EtaAlerts::stepTtl(5, 3));
$pl = EtaAlerts::stepPayload(15, 'Mehsana Highway', 'SHG123');
check('the 15 banner: "बस १५ मिनेटमा <stop> पुग्दैछ · Bus about 15 min from <stop>"',
    $pl['title'] === '🚌 बस १५ मिनेटमा Mehsana Highway पुग्दैछ · Bus about 15 min from Mehsana Highway', $pl['title']);
$pl5 = EtaAlerts::stepPayload(5, 'Mehsana Highway', 'SHG123');
check('the 5 banner says ५ / 5 and asks the passenger to be at the stop now',
    str_contains($pl5['title'], 'बस ५ मिनेटमा') && str_contains($pl5['title'], 'Bus about 5 min from') && str_contains($pl5['body'], 'now'));
check('one tap opens the ticket; the tag is the 30-minute alert\'s own (each banner replaces the last)',
    str_ends_with($pl['url'], '/#/ticket/SHG123') && $pl['tag'] === 'shg-bus_near-shg123' && mb_strlen($pl['title']) <= 120);

$orig = [];
foreach (['eta_push_steps_on', 'eta_push_steps'] as $k) {
    $orig[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($orig): void {
    foreach ($orig as $k => $row) {
        if ($row === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Settings::set($k, (string) $row['svalue'], (string) $row['stype'], (string) $row['sgroup']);
        }
    }
    Settings::flush();
};
register_shutdown_function($restore);   // a fatal error must not leave the switch on

try {
    Settings::set('eta_push_steps', '5, 15 ,99,abc', 'string', 'automation');
    check('steps: unreadable and out-of-range values are dropped, farthest first', EtaAlerts::steps() === [15, 5], json_encode(EtaAlerts::steps()));
    Settings::set('eta_push_steps', '', 'string', 'automation');
    check('steps: an empty setting falls back to 15 and 5', EtaAlerts::steps() === [15, 5]);
    Settings::set('eta_push_steps', '20,10,5', 'string', 'automation');
    check('steps: the office may choose others (20,10,5)', EtaAlerts::steps() === [20, 10, 5]);
    Settings::set('eta_push_steps', '15,5', 'string', 'automation');
} catch (Throwable $e) {
    check('steps setting: ' . $e->getMessage(), false);
}

$route = null;
foreach (Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY id') as $r) {
    $geoStops = array_values(array_filter(EtaAlerts::routeLine((int) $r['id'])['line'], static fn($s) => $s['type'] === 'boarding'));
    if (count($geoStops) >= 3) { $route = $r; break; }
}

if (!EtaAlerts::stepsAvailable()) {
    check('eta_alert_steps exists (apply database/upgrade-2026-11-15-eta-steps.sql)', false);
} elseif (!LiveTrips::available()) {
    check('live tracking tables exist (apply database/upgrade-2026-10-live-tracking.sql)', false);
} elseif ($route === null) {
    echo "  \033[33mSKIP\033[0m  needs a route with three boarding stops that have coordinates\n";
} else {
    $cleanup();
    try {
        $routeId = (int) $route['id'];
        $sid     = (int) Seats::schedule($routeId, ES_DATE)['id'];
        $stops   = array_values(array_filter(EtaAlerts::routeLine($routeId)['line'], static fn($s) => $s['type'] === 'boarding'));
        [$first, $mid, $last] = [$stops[0], $stops[1], $stops[2]];
        $seg = $mid['km'] - $first['km'];
        if ($seg < 10) {
            throw new RuntimeException('test geometry needs 10 km between the first two stops, this route has ' . round($seg, 1));
        }
        /* The coach $ahead km before the middle stop, at 45 km/h. */
        $at = static function (float $ahead) use ($first, $mid, $seg): array {
            $f = 1 - $ahead / $seg;
            return ['lat' => $first['lat'] + $f * ($mid['lat'] - $first['lat']), 'lng' => $first['lng'] + $f * ($mid['lng'] - $first['lng'])];
        };
        $now = strtotime(ES_DATE . ' ' . ($mid['time'] ?? '17:00:00')) - 15 * 60;
        $eta12 = EtaAlerts::etaMinutes(8.9, 45.0); $eta10 = EtaAlerts::etaMinutes(7.4, 45.0); $eta4 = EtaAlerts::etaMinutes(2.9, 45.0);
        $eta7  = EtaAlerts::etaMinutes(5.2, 45.0);
        echo "   (scenario: \"" . $mid['name'] . "\", ETAs $eta12 / $eta10 / $eta7 / $eta4 min)\n";
        if ($eta12 !== 12 || $eta10 !== 10 || $eta7 !== 7 || $eta4 !== 4) {
            throw new RuntimeException('ETA model changed: ' . "$eta12/$eta10/$eta7/$eta4");
        }
        $etaLast = EtaAlerts::etaMinutes($last['km'] - ($mid['km'] - 2.9), 45.0);   // the stop after, from the CLOSEST position used
        if ($etaLast <= 15) {
            throw new RuntimeException('test geometry: the stop after "' . $mid['name'] . '" is only ' . $etaLast . ' min further on this route');
        }

        $tid = Database::insert('live_trips', [
            'trip_ref' => LiveTrips::tripRef($sid), 'schedule_id' => $sid, 'route_id' => $routeId,
            'departure_at' => date('Y-m-d H:i:s', $now - 3600), 'started_at' => date('Y-m-d H:i:s', $now - 3600),
            'status' => 'running', 'last_speed' => 45, 'last_acc_m' => 12,
        ]);
        $put = static function (float $ahead, int $pingTs) use ($tid, $at): void {
            $p = $at($ahead);
            Database::update('live_trips', ['last_lat' => round($p['lat'], 7), 'last_lng' => round($p['lng'], 7),
                'last_ping_at' => date('Y-m-d H:i:s', $pingTs)], 'id = :i', ['i' => $tid]);
        };

        $bMid     = rider($sid, $mid['name'] . ' @ ' . substr((string) $mid['time'], 0, 5));
        $bPhone   = rider($sid, $mid['name']);
        $bNoSub   = rider($sid, $mid['name']);
        $bCovered = rider($sid, $mid['name']);
        $bLast    = rider($sid, $last['name']);
        $bPast    = rider($sid, $first['name']);
        $bDead    = rider($sid, $mid['name'], 'cancelled');
        phoneFor($bMid['id']);                     // subscribed from the ticket page with the PNR
        phoneFor(null, $bPhone['phone']);          // a verified mobile, no ticket link
        phoneFor($bCovered['id']);
        phoneFor($bLast['id']);
        phoneFor($bPast['id']);
        phoneFor($bDead['id']);
        Database::insert('trip_eta_alerts', ['booking_id' => $bCovered['id'], 'schedule_id' => $sid, 'stop_name' => $mid['name'],
            'eta_min' => 12, 'dist_km' => 8.9, 'channels' => 'test', 'ok' => 1, 'created_at' => date('Y-m-d H:i:s')]);

        $told = [];
        $send = static function (array $b, array $payload, int $step) use (&$told): array {
            $told[] = ['id' => (int) $b['id'], 'step' => $step, 'payload' => $payload];
            return ['ok' => true, 'pushed' => 1, 'detail' => ''];
        };
        $ids = static function () use (&$told): array { $i = array_column($told, 'id'); sort($i); return $i; };

        echo "-- silence --\n";
        Settings::set('eta_push_steps_on', '0', 'bool', 'automation');
        $put(8.9, $now - 10);
        $r = EtaAlerts::runSteps(null, $now, $send);
        check('switch off (the default): nobody is told, nothing is claimed', $told === [] && ($r['idle'] ?? '') === 'eta_push_steps_on = 0'
            && !Database::exists('SELECT 1 FROM eta_alert_steps WHERE schedule_id = :s', ['s' => $sid]), json_encode($r));

        Settings::set('eta_push_steps_on', '1', 'bool', 'automation');
        $put(8.9, $now - 900);
        $r = EtaAlerts::runSteps(null, $now, $send);
        check('a 15-minute-old driver fix: nobody is told, and the shared key is not used instead', $told === [] && $r['trips'] === 0 && $r['claimed'] === 0, json_encode($r));

        echo "-- the 15 step --\n";
        $put(8.9, $now - 10);
        $r = EtaAlerts::runSteps(null, $now, $send);
        $want = [$bMid['id'], $bPhone['id']]; sort($want);
        check('at ~12 min the passengers of the NEXT stop with a push phone get the 15 step (ticket link and verified mobile)', $ids() === $want, json_encode($r));
        check('...each with the 15 banner naming their own stop',
            $told !== [] && $told[0]['step'] === 15 && str_contains($told[0]['payload']['title'], 'बस १५ मिनेटमा')
            && str_contains($told[0]['payload']['title'], Boarding::stopDisplay($mid['name'], $mid['name'])['name']),
            (string) ($told[0]['payload']['title'] ?? ''));
        $notTold = array_diff([$bPast['id'], $bDead['id'], $bNoSub['id'], $bCovered['id']], $ids());
        check('...not the stop already passed, not a cancelled ticket, not a ticket with no phone, not one the 30-minute alert already told "12 min"',
            count($notTold) === 4);
        check('...not the stop after it', !in_array($bLast['id'], $ids(), true));
        check('a ticket with no phone is counted, and NOT claimed (so a later step can still reach it)',
            $r['noPhone'] >= 1 && !Database::exists('SELECT 1 FROM eta_alert_steps WHERE booking_id = :b', ['b' => $bNoSub['id']]));
        $row = Database::fetch('SELECT * FROM eta_alert_steps WHERE booking_id = :b AND schedule_id = :s', ['b' => $bMid['id'], 's' => $sid]);
        check('the step is recorded with what went', $row !== null && (int) $row['step_min'] === 15 && (int) $row['ok'] === 1
            && (int) $row['pushed'] === 1 && (int) $row['eta_min'] === 12, json_encode($row));

        $told = [];
        $put(7.4, $now + 170);
        $r = EtaAlerts::runSteps(null, $now + 180, $send);
        check('three minutes later (~10 min) NOBODY is told again', $told === [] && $r['claimed'] === 0, json_encode($r));

        echo "-- the 5 step --\n";
        phoneFor($bNoSub['id']);   // turned alerts on between the steps
        $put(5.2, $now + 290);
        $r = EtaAlerts::runSteps(null, $now + 300, $send);
        check('at ~7 min (between the two windows) nobody is told - not even the late subscriber: "about 15" would be wrong',
            $told === [] && $r['claimed'] === 0 && $r['trips'] >= 1, json_encode($r));

        $put(2.9, $now + 470);
        $r = EtaAlerts::runSteps(null, $now + 480, $send);
        $want = [$bMid['id'], $bPhone['id'], $bNoSub['id'], $bCovered['id']]; sort($want);
        check('at ~4 min the 5 step goes to them all - also the late subscriber and the one the 30-minute alert told',
            $ids() === $want && array_unique(array_column($told, 'step')) === [5], json_encode($r));
        check('...with the 5 banner', $told !== [] && str_contains($told[0]['payload']['title'], 'बस ५ मिनेटमा'));

        $told = [];
        $put(2.9, $now + 530);
        EtaAlerts::runSteps(null, $now + 540, $send);
        check('a second run at ~4 min tells nobody', $told === []);

        $put(8.9, $now + 590);
        EtaAlerts::runSteps(null, $now + 600, $send);
        check('a GPS wobble back to ~12 min never sends "15" after "5"', $told === []);
        check('the late subscriber has the 5 step only',
            array_map('intval', array_column(Database::fetchAll('SELECT step_min FROM eta_alert_steps WHERE booking_id = :b', ['b' => $bNoSub['id']]), 'step_min')) === [5]);

        echo "-- the claim --\n";
        $dup = Database::insertIgnore('eta_alert_steps', ['booking_id' => $bMid['id'], 'schedule_id' => $sid, 'step_min' => 15,
            'stop_name' => 'dup', 'sent_at' => date('Y-m-d H:i:s')]);
        check('the ledger refuses a second row for the same booking, trip and step', $dup === 0);
        check('each step went exactly once per passenger',
            (int) Database::scalar('SELECT COUNT(*) FROM eta_alert_steps WHERE schedule_id = :s', ['s' => $sid]) === 6
            && (int) Database::scalar('SELECT COUNT(*) FROM eta_alert_steps WHERE booking_id = :b', ['b' => $bMid['id']]) === 2);

        echo "-- wiring --\n";
        $src  = (string) file_get_contents(INCLUDE_PATH . '/etaalerts.php');
        $tail = substr($src, (int) strpos($src, 'public static function stepsEnabled'));
        check('the steps never use WhatsApp / Notify (web push only)', $tail !== '' && !str_contains($tail, 'Notify::') && !str_contains($tail, 'WhatsApp::'));
        $cron = (string) file_get_contents(ROOT_PATH . '/cron/eta-alerts.php');
        check('the cron runs the steps after the 30-minute run, behind their own switch',
            str_contains($cron, 'EtaAlerts::stepsEnabled()') && strpos($cron, 'EtaAlerts::runSteps(') > strpos($cron, 'EtaAlerts::run()'));
        check('the switch defaults OFF in code', str_contains($src, "Settings::getBool('eta_push_steps_on', false)"));
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
    }
}
$restore();
check('the switch is back to what it was', Settings::getBool('eta_push_steps_on', false) === ((string) ($orig['eta_push_steps_on']['svalue'] ?? '0') === '1'));

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
